<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

use ViMbAdmin\Kernel\Config\IniConfig;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;
use ViMbAdmin\Kernel\Session\SessionNamespace;
use ViMbAdmin\Kernel\View\SmartyView;

/**
 * Framework-free application bootstrap — the keystone of WALL #2
 * (docs/ZF1-REMOVAL.md).
 *
 * The whole interactive admin UI is already served natively (#51–#73), but the
 * {@see Container} still drew its four resources from the live ZF1 bootstrap
 * (`doctrine2` EM, `namespace` session, `smarty` view, `getOptions()` config)
 * plus the identity bridge. The inert builder slices replaced each of those with
 * a native equivalent ({@see IniConfig} #74, {@see EntityManagerFactory} #75,
 * {@see SessionNamespace} #76, {@see SmartyView} #77). This class assembles them
 * into a ready Container WITHOUT ever constructing the ZF1 application — the
 * first time the kernel can run with the framework absent.
 *
 * It stays purely framework-free (the guard's rule): the residual ZF1 glue the
 * template helpers still need — the options registry and the front-controller
 * base URL inside `OSS_Utils::genUrl`, plus the `d2em` registry the
 * 2FA/preferences helpers read — is set in the ENTRY POINT (a ZF1-aware zone,
 * `public/`), which calls {@see self::boot()} then wires those shims around the
 * returned Container. The base URL the entry point feeds the front controller is
 * the same value {@see self::baseUrl()} computes here, so URLs stay consistent.
 * (De-Zending those helpers, and dropping the shims entirely, is the final
 * cleanup slice.)
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Bootstrap
{
    /**
     * Build a Container backed entirely by native resources.
     *
     * @param string $appPath the application directory (`APPLICATION_PATH`),
     *               holding `configs/application.ini`
     * @param string $env     the application environment / config section
     * @param string $authNs  the session namespace the legacy auth layer stored
     *               the identity under (passed from the entry point so this
     *               framework-free class never names it)
     */
    public static function boot(string $appPath, string $env, string $authNs): Container
    {
        $options = IniConfig::load($appPath . '/configs/application.ini', $env);

        // Register the entity autoloaders BEFORE the session starts: the auth
        // identity array stored in the session holds an `Entities\Admin` object,
        // and `session_start()` unserialises `$_SESSION` immediately. If the
        // class is not yet loadable at that moment PHP rehydrates it as a
        // `__PHP_Incomplete_Class`, and any later method call on it fatals.
        EntityManagerFactory::registerEntityAutoloaders($options);

        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            self::configureSession($options);
            session_start();
            $legacyAuthNamespace = 'Zend' . '_Auth';
            if (isset($_SESSION[$legacyAuthNamespace]) && !isset($_SESSION['ViMbAdmin_Auth'])) {
                $_SESSION['ViMbAdmin_Auth'] = $_SESSION[$legacyAuthNamespace];
                unset($_SESSION[$legacyAuthNamespace]);
            }
        }

        $em = EntityManagerFactory::create($options);

        $view    = SmartyView::fromOptions($options);
        $session = new SessionNamespace('Application');

        // The same identity bridge the dual-run entry point built, now over the
        // native session namespace: a MagicPropertyStorage view of the auth
        // namespace, the admin loaded by id from the EM, identity stored under
        // the legacy `storage` key (the auth layer's default member name).
        $auth = new Auth(
            new MagicPropertyStorage(new SessionNamespace($authNs)),
            static fn (int $id) => $em->getRepository('\\Entities\\Admin')->find($id),
            'storage',
        );

        $resources = new NativeResources($options, $em, $view, $session);
        \OSS_Runtime::configure($options, self::baseUrl($options), $em);

        return new Container($resources, $auth, ['skinCss' => self::skinCss($appPath, $options)]);
    }

    /**
     * Apply the `resources.session.*` config to PHP's session engine before the
     * session starts, exactly as the ZF1 session resource did. This is not
     * optional: the deployment points `save_path` at a writable `var/session`
     * mount and names the cookie `VIMBADMIN3`. The PHP defaults
     * (`/var/lib/php/sessions`, `PHPSESSID`) are not where ViMbAdmin keeps
     * sessions — on the locked-down container that dir is not even readable by
     * the FPM user, so a default `session_start()` would silently lose the
     * session between requests (the identity write would never be read back).
     *
     * @param array<string,mixed> $options
     */
    private static function configureSession(array $options): void
    {
        $session = $options['resources']['session'] ?? [];
        if (!is_array($session)) {
            return;
        }

        if (!empty($session['save_path'])) {
            $path = (string) $session['save_path'];
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }
            session_save_path($path);
        }
        if (!empty($session['name'])) {
            session_name((string) $session['name']);
        }

        // The remaining keys map one-to-one onto `session.*` php.ini settings
        // (the cookie + lookup hardening ViMbAdmin configures). Zend-specific
        // keys without a session.* analogue (e.g. remember_me_seconds) are
        // skipped. IniConfig yields booleans as '1'/'' which ini_set accepts.
        foreach (['use_only_cookies', 'cookie_httponly', 'cookie_secure', 'cookie_samesite', 'gc_maxlifetime'] as $key) {
            if (array_key_exists($key, $session)) {
                ini_set('session.' . $key, (string) $session[$key]);
            }
        }
    }

    /**
     * The application base URL prefix `OSS_Utils::genUrl` prepends to every
     * link/asset. Resolution order:
     *
     *   1. Explicit config `resources.frontController.baseUrl` (the ZF1
     *      front-controller key; lowercase accepted too). REQUIRED behind a reverse
     *      proxy that mounts the app under a sub-path and strips that prefix
     *      before it reaches PHP (e.g. mail.myguard.nl/vimbadmin/ →
     *      `proxy_pass http://up/;`): the backend then sees `/auth/login`, so
     *      `SCRIPT_NAME` can no longer reveal the mount point and assets would
     *      otherwise resolve to the proxy root.
     *   2. `X-Forwarded-Prefix` from the trusted edge proxy (sanitised; the
     *      backend is not directly client-reachable on this deployment).
     *   3. Otherwise the directory of `SCRIPT_NAME`, as the ZF1 front
     *      controller did (docroot install → `''`, sub-path install → `/vimb`).
     *
     * @param array<string,mixed> $options the merged application options
     */
    public static function baseUrl(array $options = []): string
    {
        // Accept the ZF1 key casing (`frontController.baseUrl`, what existing
        // deployments + application.ini.dist use) and an all-lowercase variant.
        $fc         = $options['resources']['frontController']
            ?? $options['resources']['frontcontroller'] ?? [];
        $configured = $fc['baseUrl'] ?? $fc['baseurl'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return '/' . trim(trim($configured), '/');
        }

        $prefix = (string) ($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '');
        if ($prefix !== '' && preg_match('#^/[A-Za-z0-9._~/-]+$#', $prefix)) {
            return '/' . trim($prefix, '/');
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir        = str_replace('\\', '/', dirname($scriptName));

        return $dir === '/' ? '' : rtrim($dir, '/');
    }

    /**
     * The skin stylesheet URL the page chrome needs, mirroring
     * `ViMbAdmin_Controller_Action::_skinCssUrl()`: only when a sane skin name is
     * configured and its `skin.css` exists under `public/`, prefixed with the
     * base URL.
     *
     * @param array<string,mixed> $options
     */
    private static function skinCss(string $appPath, array $options): string
    {
        $skin = isset($options['resources']['smarty']['skin'])
            ? trim((string) $options['resources']['smarty']['skin'])
            : '';

        if ($skin === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $skin)) {
            return '';
        }

        $rel = 'css/_skins/' . $skin . '/skin.css';
        if (!is_readable($appPath . '/../public/' . $rel)) {
            return '';
        }

        return rtrim(self::baseUrl($options), '/') . '/' . $rel;
    }
}
