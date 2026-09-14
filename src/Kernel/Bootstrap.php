<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

use Closure;
use LogicException;
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
 * Builds every resource used by the native {@see Container}: {@see IniConfig},
 * {@see EntityManagerFactory}, {@see SessionNamespace}, {@see SmartyView}, and
 * the native identity bridge. The public entry point calls {@see self::boot()}
 * directly and never constructs a ZF1 application.
 *
 * Compatibility registries used by older library helpers are configured inside
 * the native bootstrap. The base URL comes from {@see self::baseUrl()}, keeping
 * generated URLs consistent with native routing.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Bootstrap
{
    /** @return array<string,mixed> */
    private static function stringMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new LogicException("{$name} must be an array");
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new LogicException("{$name} must use string keys");
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $map */
    private static function optionalString(array $map, string $key, string $name, string $default = ''): string
    {
        if (!array_key_exists($key, $map)) {
            return $default;
        }
        $value = $map[$key];
        if (!is_string($value)) {
            throw new LogicException("{$name} must be a string");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new LogicException("{$name} contains control characters");
        }

        return $value;
    }

    private static function iniValue(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new LogicException("{$name} must be a scalar session setting");
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function resourceMap(array $options, string $key): array
    {
        $resources = self::stringMap($options['resources'] ?? [], 'resources');
        return self::stringMap($resources[$key] ?? [], "resources.{$key}");
    }

    /**
     * Build a Container backed entirely by native resources.
     *
     * @param string $appPath the application directory (`APPLICATION_PATH`),
     *               holding `configs/application.ini`
     * @param string $env     the application environment / config section
     * @param string $authNs  the current session namespace holding the auth
     *               identity
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
            self::startSession();
            $_SESSION = self::migrateAuthNamespace($_SESSION, true);
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
            self::adminLoader($em),
            'storage',
        );

        $resources = new NativeResources($options, $em, $view, $session);
        \OSS_Runtime::configure($options, self::baseUrl($options), $em);

        return new Container($resources, $auth, ['skinCss' => self::skinCss($appPath, $options)]);
    }

    /**
     * @param array<array-key,mixed> $session
     * @return array<array-key,mixed>
     */
    private static function migrateAuthNamespace(array $session, bool $activeWebSession): array
    {
        if (!$activeWebSession) {
            return $session;
        }

        $legacyAuthNamespace = 'Zend' . '_Auth';
        if (isset($session[$legacyAuthNamespace]) && !isset($session['ViMbAdmin_Auth'])) {
            $session['ViMbAdmin_Auth'] = $session[$legacyAuthNamespace];
            unset($session[$legacyAuthNamespace]);
        }

        return $session;
    }

    private static function startSession(): void
    {
        if (!session_start()) {
            throw new \RuntimeException('Unable to start session');
        }
    }

    /**
     * Validate the persistence boundary once, where the native entity manager
     * is wired into the framework-free auth service.
     *
     * @return Closure(int):?object
     */
    private static function adminLoader(object $entityManager): Closure
    {
        if (!method_exists($entityManager, 'getRepository')) {
            throw new LogicException('Native bootstrap requires a Doctrine object manager.');
        }

        $repository = $entityManager->getRepository('\\Entities\\Admin');
        if (!is_object($repository) || !method_exists($repository, 'find')) {
            throw new LogicException('Native bootstrap requires an admin repository.');
        }

        return static function (int $id) use ($repository): ?object {
            $admin = $repository->find($id);
            if ($admin !== null && !is_object($admin)) {
                throw new LogicException('Admin repository returned an invalid value.');
            }

            return $admin;
        };
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
        $session = self::resourceMap($options, 'session');

        $savePath = self::optionalString($session, 'save_path', 'resources.session.save_path');
        if ($savePath !== '') {
            $path = $savePath;
            if (!is_dir($path)) {
                @mkdir($path, 0770, true);
            }
            if (!is_dir($path) || !is_writable($path)) {
                throw new \RuntimeException('Session save path is not a writable directory');
            }
            session_save_path($path);
        }
        $name = self::optionalString($session, 'name', 'resources.session.name');
        if ($name !== '') {
            session_name($name);
        }

        // session.use_strict_mode rejects attacker-seeded session IDs (a core
        // session-fixation defence). The hardened FPM pool sets it too, but a
        // from-source / bare-metal install has no such pool — so enforce a safe
        // default HERE rather than relying on the deployment. A config value, if
        // present, still wins (cast '1'/'' from IniConfig booleans).
        ini_set('session.use_strict_mode', array_key_exists('use_strict_mode', $session)
            ? self::iniValue($session['use_strict_mode'], 'resources.session.use_strict_mode')
            : '1');

        // Enforce safe cookie defaults for sparse/from-source configuration;
        // explicit operator values still win. Zend-specific keys without a
        // session.* analogue (e.g. remember_me_seconds) are skipped.
        $defaults = [
            'use_only_cookies' => '1',
            'cookie_httponly' => '1',
            'cookie_secure' => '1',
            'cookie_samesite' => 'Lax',
        ];
        foreach ($defaults as $key => $default) {
            ini_set('session.' . $key, array_key_exists($key, $session)
                ? self::iniValue($session[$key], 'resources.session.' . $key)
                : $default);
        }
        if (array_key_exists('gc_maxlifetime', $session)) {
            ini_set('session.gc_maxlifetime', self::iniValue(
                $session['gc_maxlifetime'],
                'resources.session.gc_maxlifetime',
            ));
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
     *   2. `X-Forwarded-Prefix` from a peer accepted by `trustedproxy` policy,
     *      with unsafe and dot-segment paths rejected.
     *   3. Otherwise the directory of `SCRIPT_NAME`, as the ZF1 front
     *      controller did (docroot install → `''`, sub-path install → `/vimb`).
     *
     * @param array<string,mixed> $options the merged application options
     */
    public static function baseUrl(array $options = []): string
    {
        // Accept the ZF1 key casing (`frontController.baseUrl`, what existing
        // deployments + application.ini.dist use) and an all-lowercase variant.
        $resources = self::stringMap($options['resources'] ?? [], 'resources');
        $fc = array_key_exists('frontController', $resources)
            ? self::stringMap($resources['frontController'], 'resources.frontController')
            : self::stringMap($resources['frontcontroller'] ?? [], 'resources.frontcontroller');
        $configured = array_key_exists('baseUrl', $fc)
            ? self::optionalString($fc, 'baseUrl', 'resources.frontController.baseUrl')
            : self::optionalString($fc, 'baseurl', 'resources.frontController.baseurl');
        if (trim($configured) !== '') {
            if (preg_match('#^/?[A-Za-z0-9._~/-]+$#', $configured) !== 1) {
                throw new LogicException('Configured base URL contains invalid path characters');
            }
            return '/' . trim(trim($configured), '/');
        }

        $prefix = self::forwardedPrefix($options);
        if ($prefix !== '' && preg_match('#^/[A-Za-z0-9._~/-]+$#', $prefix)
            && preg_match('#(?:^|/)\.\.?($|/)#', $prefix) !== 1) {
            return '/' . trim($prefix, '/');
        }

        $scriptName = array_key_exists('SCRIPT_NAME', $_SERVER)
            ? self::serverString('SCRIPT_NAME') : '';
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
        $smarty = self::resourceMap($options, 'smarty');
        $skin = trim(self::optionalString($smarty, 'skin', 'resources.smarty.skin'));

        if ($skin === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $skin)) {
            return '';
        }

        $rel = 'css/_skins/' . $skin . '/skin.css';
        if (!is_readable($appPath . '/../public/' . $rel)) {
            return '';
        }

        return rtrim(self::baseUrl($options), '/') . '/' . $rel;
    }

    private static function serverString(string $key): string
    {
        $value = $_SERVER[$key] ?? '';
        if (!is_string($value)) {
            throw new LogicException("Server parameter {$key} must be a string");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new LogicException("Server parameter {$key} contains control characters");
        }

        return $value;
    }

    /** @param array<string,mixed> $options */
    private static function forwardedPrefix(array $options): string
    {
        if (!array_key_exists('HTTP_X_FORWARDED_PREFIX', $_SERVER)) {
            return '';
        }
        $value = $_SERVER['HTTP_X_FORWARDED_PREFIX'];
        if (!is_string($value)) {
            throw new LogicException('Server parameter HTTP_X_FORWARDED_PREFIX must be a string');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return '';
        }

        $proxy = self::stringMap($options['trustedproxy'] ?? [], 'trustedproxy');
        $mode = self::optionalString($proxy, 'mode', 'trustedproxy.mode', 'auto');
        $proxies = $proxy['proxies'] ?? [];
        if (is_string($proxies)) {
            $proxies = [$proxies];
        }
        if (!is_array($proxies) || !array_is_list($proxies)) {
            throw new LogicException('trustedproxy.proxies must be a string or list of strings');
        }
        foreach ($proxies as $configuredProxy) {
            if (!is_string($configuredProxy)) {
                throw new LogicException('trustedproxy.proxies must contain strings');
            }
        }
        $remote = self::serverString('REMOTE_ADDR');
        if (!\ViMbAdmin_Net::isTrustedForwardedHeaderPeer($remote, $mode, $proxies)) {
            return '';
        }

        return $value;
    }
}
