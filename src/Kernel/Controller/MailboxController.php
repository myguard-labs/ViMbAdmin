<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\Form\FormRenderer;
use ViMbAdmin\Kernel\Form\Validators;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Plugin\FormPluginHost;
use ViMbAdmin\Kernel\Plugin\MailboxContext;
use ViMbAdmin\Kernel\Plugin\PluginHost;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native port of `MailboxController::list` + `ajaxToggleActive`
 * (docs/ZF1-REMOVAL.md).
 *
 * Reproduces the legacy `preDispatch` (for the list actions) + `listAction`: it
 * resolves the remembered/`did` domain scope into the session (so the mailbox
 * table stays filtered to one domain across requests, `unset` clears it), loads
 * the scoped mailboxes via the existing `Repositories\Mailbox::loadForMailboxList()`
 * (empty initial set when server-side pagination is configured), and exposes the
 * size-column multiplier. Like the ZF1 action it does NOT set a `domain` view
 * variable — the data is scoped, but the list header stays generic — so the
 * output matches byte-for-byte.
 *
 * `ajaxToggleActive` flips a mailbox's active flag through the framework-free
 * `ViMbAdmin_Service_Mailbox` (#30) while firing the same plugin hooks the ZF1
 * action did — via the native {@see PluginHost} + {@see MailboxContext} (Phase
 * 4c), so the MailboxAutomaticAliases pre/post-toggle logic runs natively and a
 * pre-toggle veto still aborts the change.
 *
 * `purge` reproduces the ZF1 CSRF-guarded confirm-then-purge flow natively,
 * running the mutation through the extracted `ViMbAdmin_Service_Mailbox::purge`
 * with the same plugin hooks over the PluginHost.
 *
 * The remaining form actions (add/edit) stay on ZF1 via the dispatcher
 * fallback. The legacy controller is untouched.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 * @method \Doctrine\ORM\EntityManager em()
 * @method \Entities\Admin|null admin()
 */
final class MailboxController extends AbstractController
{
    private static function requiredString(mixed $value, string $name): string
    {
        return \ViMbAdmin\Kernel\Input\Reader::requiredString($value, $name);
    }

    private static function optionalString(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredString($value, $name);
    }

    private static function stringOrDefault(mixed $value, string $default, string $name): string
    {
        return $value === null ? $default : self::requiredString($value, $name);
    }

    private static function renderStringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    private static function controlSafeString(string $value, string $name): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \LogicException("{$name} contains control characters");
        }

        return $value;
    }

    private static function displaySettingString(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        throw new \LogicException("{$name} must be a scalar display value");
    }

    private static function optionalServerPort(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $port = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            $port = is_int($parsed) ? $parsed : 0;
        } else {
            $port = 0;
        }
        if ($port < 1 || $port > 65535) {
            throw new \LogicException('Server port must be an integer from 1 through 65535');
        }

        return $port;
    }

    private static function positiveIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return is_int($integer) ? $integer : null;
        }

        return null;
    }

    private function positiveIdParam(string $key): ?int
    {
        return self::positiveIntegerOrNull($this->param($key));
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function stringKeyedArray(mixed $value, string $name): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::stringKeyedArray($value, $name);
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private static function requestArray(array $value): array
    {
        $scalarKeys = [
            'draw',
            'start',
            'length',
        ];
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                if (in_array($key, $scalarKeys, true) && !is_string($item)) {
                    throw new \LogicException("DataTables parameter {$key} must be a string");
                }
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{bool,mixed}
     */
    private static function option(array $options, string ...$path): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::option($options, ...$path);
    }

    /** @param array<string,mixed> $options */
    private static function optionString(array $options, string $default, string ...$path): string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /** @param array<string,mixed> $options */
    private static function optionNullableString(array $options, string ...$path): ?string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : null;
    }

    /** @param array<string,mixed> $options */
    private static function optionInt(array $options, int $default, string ...$path): int
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new \LogicException('Configuration ' . implode('.', $path) . ' must be a non-negative integer');
    }

    /** @param array<string,mixed> $options */
    /**
     * Column index -> sortable DB field for the mailbox list.
     *
     * `mailbox/list.phtml` and its view JS render the Domain column only when
     * `defaults.list_domain.disabled` is false, so a static index map shifts
     * once that column is turned off. Build the map from the same condition the
     * view uses. Computed columns -- used quota, last login, active, controls --
     * are rendered but not sortable and map to the default.
     *
     * @param int  $index        The DataTables `order[0][column]` index.
     * @param bool $domainColumn Whether the Domain column is rendered.
     */
    private static function listSortField(int $index, bool $domainColumn): string
    {
        $columns = array_values(array_filter([
            'username',                        // Email
            'name',                            // Name
            '',                                // Used / Quota (not sortable)
            '',                                // Last login (not sortable)
            $domainColumn ? 'domain' : null,   // Domain
            '',                                // Active (not sortable)
            '',                                // controls (not sortable)
        ], static fn(?string $c): bool => $c !== null));

        return ($columns[$index] ?? '') ?: 'username';
    }

    /** @param array<string,mixed> $options */
    private static function optionBool(array $options, bool $default, string ...$path): bool
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        throw new \LogicException('Configuration ' . implode('.', $path) . ' must be boolean');
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private static function optionArray(array $options, array $default, string ...$path): array
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::stringKeyedArray($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /** @param array<string,mixed> $options */
    private static function quotaMultiplier(array $options): string
    {
        $multiplier = strtoupper(self::optionString(
            $options,
            \OSS_Filter_FileSize::SIZE_KILOBYTES,
            'defaults',
            'quota',
            'multiplier',
        ));
        if (!in_array($multiplier, [
            \OSS_Filter_FileSize::SIZE_BYTES,
            \OSS_Filter_FileSize::SIZE_KILOBYTES,
            \OSS_Filter_FileSize::SIZE_MEGABYTES,
            \OSS_Filter_FileSize::SIZE_GIGABYTES,
        ], true)) {
            throw new \LogicException('Configuration defaults.quota.multiplier must be B, KB, MB, or GB');
        }

        return $multiplier;
    }

    private static function sizeMultiplier(string $multiplier): float
    {
        return match ($multiplier) {
            \OSS_Filter_FileSize::SIZE_BYTES => 1.0,
            \OSS_Filter_FileSize::SIZE_KILOBYTES => 1024.0,
            \OSS_Filter_FileSize::SIZE_MEGABYTES => 1048576.0,
            \OSS_Filter_FileSize::SIZE_GIGABYTES => 1073741824.0,
            default => throw new \LogicException('Unsupported file-size multiplier'),
        };
    }

    private static function quotaBytes(string $value, string $multiplier): int
    {
        $filtered = (new \OSS_Filter_FileSize($multiplier))->filter($value);
        if ((!is_int($filtered) && !is_float($filtered))
            || !is_finite((float) $filtered)
            || $filtered < 0
            || $filtered > PHP_INT_MAX) {
            throw new \LogicException('Mailbox quota produced an invalid byte value');
        }

        return (int) $filtered;
    }

    /** @param array<string,mixed> $options */
    private static function queueRunnerKey(array $options): string
    {
        return self::controlSafeString(
            self::optionString($options, '', 'queue', 'runner', 'key'),
            'Configuration queue.runner.key',
        );
    }

    /** @return array{https:bool,host:string,sni:string,port:int} */
    private static function queueEndpoint(mixed $httpsValue, mixed $hostValue, mixed $portValue): array
    {
        $httpsString = self::stringOrDefault($httpsValue, '', 'Server HTTPS value');
        $reportedPort = self::optionalServerPort($portValue);
        $https = ($httpsString !== '' && $httpsString !== '0' && strtolower($httpsString) !== 'off')
            || $reportedPort === 443;
        $host = self::controlSafeString(
            self::stringOrDefault($hostValue, '127.0.0.1', 'Server HTTP_HOST'),
            'Server HTTP_HOST',
        );
        if ($host === '' || strpbrk($host, " \t\r\n/\\") !== false) {
            throw new \LogicException('Server HTTP_HOST has an invalid shape');
        }

        return [
            'https' => $https,
            'host' => $host,
            'sni' => preg_replace('/:\d+$/', '', $host) ?: '127.0.0.1',
            'port' => $reportedPort ?? ($https ? 443 : 80),
        ];
    }

    /**
     * GET /mailbox/list-data — DataTables server-side processing source.
     *
     * One page of the scoped mailbox list as the DataTables JSON envelope (draw
     * counter + total / filtered counts + page rows), letting the browser page
     * through the full list without ever shipping every row. Active only when
     * server-side pagination is enabled; the in-image angie + edge CRS allow the
     * `list-data` route and the DataTables query args.
     */
    public function listDataAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return new Response('ko');
        }

        $domain = $this->session()->domain ?? null;
        if ($domain !== null && !$domain instanceof \Entities\Domain) {
            return new Response('ko');
        }
        try {
            $minimum = $this->dataTableMinimumSearchLength();
            try {
                $q = DataTableQuery::fromArray(self::requestArray($_GET), $minimum);
            } catch (\TypeError) {
                return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
            }
        } catch (\LengthException $e) {
            return new Response($e->getMessage(), 400, 'text/plain; charset=utf-8');
        } catch (\LogicException) {
            return new Response('ko');
        }

        $domainColumn = !self::optionBool($this->container->options(), false, 'defaults', 'list_domain', 'disabled');
        $sortField    = self::listSortField($q->sortColumn, $domainColumn);

        $r = $this->mailboxRepository()
            ->pagedForMailboxList($admin, $domain, $q->searchTerm, $q->contains, $sortField, $q->sortDir, $q->start, $q->length);

        return new Response(
            DataTableResult::json($q, $r['total'], $r['filtered'], $r['rows']),
            200,
            'application/json; charset=utf-8'
        );
    }


    /**
     * GET /mailbox and /mailbox/index — the auth-gated landing forwards to the list
     * (the native equivalent of the ZF1 indexAction `_forward('list')`).
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('mailbox/list')
            : $this->redirect('auth/login');
    }
    /**
     * GET /mailbox/list[/did/<id>][/unset/1] — the mailboxes overview.
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // preDispatch domain juggling (list/index/list-search): the selected
        // domain is remembered in the session and reused until `unset`.
        $session = new MagicPropertyStorage($this->session());
        $domain  = null;

        if ($this->param('unset', false)) {
            $session->remove('domain');
        } elseif ($session->has('domain') && $session->get('domain')) {
            $remembered = $session->get('domain');
            if (!$remembered instanceof \Entities\Domain) {
                $session->remove('domain');
                return $this->redirect('auth/login');
            }
            $domain = $remembered;
        } elseif (($didValue = $this->param('did')) !== null) {
            $did = self::positiveIntegerOrNull($didValue);
            if ($did === null) {
                return $this->redirect('auth/login');
            }
            $domain = $this->em()->getRepository('\\Entities\\Domain')->find($did);
            if (!$domain instanceof \Entities\Domain) {
                return $this->redirect('auth/login');
            }
            // loadDomain() authorises a non-super admin against the domain.
            if (!$admin->isSuper() && !$admin->canManageDomain($domain)) {
                return $this->redirect('auth/login');
            }
            $session->set('domain', $domain);
        }

        $opts = $this->container->options();

        $paginate = self::optionBool($opts, true, 'defaults', 'server_side', 'pagination', 'enable');

        $vars = [
            'mailboxes' => $paginate
                ? []
                : $this->mailboxRepository()->loadForMailboxList($admin, $domain),
        ];

        if (!self::optionBool($opts, false, 'defaults', 'list_size', 'disabled')) {
            $key = strtoupper(self::optionString(
                $opts,
                \OSS_Filter_FileSize::SIZE_KILOBYTES,
                'defaults',
                'list_size',
                'multiplier',
            ));
            if (!in_array($key, [
                \OSS_Filter_FileSize::SIZE_BYTES,
                \OSS_Filter_FileSize::SIZE_KILOBYTES,
                \OSS_Filter_FileSize::SIZE_MEGABYTES,
                \OSS_Filter_FileSize::SIZE_GIGABYTES,
            ], true)) {
                throw new \LogicException('Configuration defaults.list_size.multiplier must be B, KB, MB, or GB');
            }

            $vars['size_multiplier'] = $key;
            $vars['multiplier']      = self::sizeMultiplier($key);
        }

        return $this->view('mailbox/list.phtml', $vars);
    }

    /**
     * POST /mailbox/ajax-toggle-active — flip a mailbox's active flag.
     *
     * Mirrors the ZF1 action: resolve the mailbox from `mid`, refuse a missing
     * one or a domain the admin cannot manage (`loadMailbox` authorisation),
     * then toggle via `ViMbAdmin_Service_Mailbox` with the plugin pre/pre-flush/
     * post-flush hooks threaded in as callables over the native PluginHost. A
     * pre-toggle veto (any observer returning false) leaves the mailbox
     * unchanged and yields "ko". Prints the bare ok/ko body the JS reads.
     */
    public function ajaxToggleActiveAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            return new Response('ko');
        }

        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox instanceof \Entities\Mailbox) {
            return new Response('ko');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return new Response('ko');
        }

        $context = new MailboxContext(
            $this->em(),
            $admin,
            $domain,
            $mailbox,
            $this->container->options(),
            new FlashMessages(new MagicPropertyStorage($this->session())),
        );
        $host = new PluginHost($context);

        $result = (new \ViMbAdmin_Service_Mailbox($this->em()))->toggleActive(
            $mailbox,
            $admin,
            fn() => $host->notify('mailbox', 'toggleActive', 'preToggle', $context, ['active' => $mailbox->getActive()]) === true,
            function () use ($host, $context, $mailbox): void {
                $host->notify('mailbox', 'toggleActive', 'preflush', $context, ['active' => $mailbox->getActive()]);
            },
            function () use ($host, $context, $mailbox): void {
                $host->notify('mailbox', 'toggleActive', 'postflush', $context, ['active' => $mailbox->getActive()]);
            },
        );

        return new Response($result === null ? 'ko' : 'ok');
    }

    /**
     * GET|POST /mailbox/purge/mid/<id>/csrf/<token> — purge a mailbox.
     *
     * Faithful port of the ZF1 `purgeAction`, hardened so only the POST mutates.
     * The read-only GET confirmation still accepts the link token, but the
     * purge itself requires a POST whose body carries the token (the
     * confirmation form mints it as a hidden field) — an invalid/missing token
     * flashes + bounces to the list. A missing mailbox (or a domain the admin cannot manage)
     * redirects to the list. The GET renders the existing `mailbox/purge.phtml`
     * confirmation byte-for-byte (the mailbox plus its dependent + containing
     * aliases). The POST (`purge=purge`) runs the mutation through the extracted
     * `ViMbAdmin_Service_Mailbox::purge` with the plugin pre-remove/pre-flush/
     * post-flush hooks threaded over the native PluginHost; a pre-remove veto
     * leaves the mailbox untouched and suppresses the success flash.
     */
    public function purgeAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // _assertCsrf(): the read-only confirmation page accepts the link token;
        // the mutating POST branch below additionally requires a body token.
        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox instanceof \Entities\Mailbox) {
            return $this->redirect('mailbox/list');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return $this->redirect('mailbox/list');
        }

        $aliasRepo = $this->aliasRepository();

        if ($this->isPost() && (($this->postData()['purge'] ?? null) === 'purge')) {
            // The mutation itself only ever runs on a POST carrying the token in
            // its body, so a purge cannot be triggered by a URL alone.
            if (!$this->postBodyCsrfValid()) {
                $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
                return $this->redirect('mailbox/list');
            }

            // On-disk file deletion removed: ViMbAdmin has no shared maildir
            // filesystem (mail lives in the Dovecot container, reached over the
            // doveadm HTTP API). Real mail removal goes through the doveadm
            // queue TYPE_DELETE task. Purge only ever drops the DB rows here.
            $deleteFiles = false;

            $context = new MailboxContext(
                $this->em(),
                $admin,
                $domain,
                $mailbox,
                $this->container->options(),
                new FlashMessages(new MagicPropertyStorage($this->session())),
            );
            $host = new PluginHost($context);

            $purged = (new \ViMbAdmin_Service_Mailbox($this->em()))->purge(
                $mailbox,
                $admin,
                $deleteFiles,
                fn() => $host->notify('mailbox', 'purge', 'preRemove', $context) !== false,
                function () use ($host, $context): void {
                    $host->notify('mailbox', 'purge', 'preFlush', $context);
                },
                function () use ($host, $context): void {
                    $host->notify('mailbox', 'purge', 'postFlush', $context);
                },
            );

            if ($purged) {
                $this->flash('You have successfully purged the mailbox.');
            }

            return $this->redirect('mailbox/list');
        }

        return $this->view('mailbox/purge.phtml', [
            'mailbox'   => $mailbox,
            'aliases'   => $aliasRepo->loadForMailbox($mailbox, $admin),
            'inAliases' => $aliasRepo->loadWithMailbox($mailbox, $admin),
        ]);
    }

    /**
     * GET|POST /mailbox/add[/did/<id>] — create a mailbox (the heaviest form).
     *
     * Native port of the create path of the ZF1 `MailboxController::addAction`.
     * Edit (`/mailbox/edit/mid/<id>`) is a different action and stays on ZF1 via
     * the dispatcher fallback (the `mid` guard keeps any `add` URL carrying a
     * `mid` on ZF1 too).
     *
     * The form is the framework-free {@see Form}: the base mailbox fields plus
     * any native plugin sections appended by the {@see FormPluginHost} (today the
     * AccessPermissions access-restriction checkboxes; AdditionalInfo /
     * DirectoryEntry are not yet adapted to the native contract, so their ZF1
     * subforms are dropped here — a known, documented gap). The `welcome_email` /
     * `cc_welcome_email` fields are dropped because the native kernel has no
     * mailer (consistent with the native login dropping remember-me).
     *
     * On POST it validates the base form, then the plugin sections
     * ({@see FormPluginHost::validate}), resolves + authorises the chosen domain,
     * enforces the per-admin mailbox allowance, the address validity (a field
     * rule) and uniqueness, clamps the quota to the domain maximum, applies the
     * plugin writebacks ({@see FormPluginHost::apply}) and persists through the
     * extracted {@see \ViMbAdmin_Service_Mailbox::create} — threading the
     * `mailbox_add_addPostflush` plugin hook over the native {@see PluginHost} so
     * MailboxAutomaticAliases still creates its RFC2142 default aliases. A
     * cross-field failure flashes the reason and re-renders the repopulated form,
     * mirroring the ZF1 `addMessage(...) + return`.
     */
    public function addAction(): Response
    {
        // Edit is served natively by editAction; redirect the legacy
        // add-with-mid alias there rather than punting to ZF1.
        $midValue = $this->param('mid');
        $mid = self::positiveIntegerOrNull($midValue);
        if ($midValue !== null && $mid === null) {
            return $this->redirect('mailbox/list');
        }
        if ($mid !== null) {
            return $this->redirect('mailbox/edit/mid/' . $mid);
        }

        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $options = $this->container->options();
        $mult    = self::quotaMultiplier($options);
        $minPw   = self::optionInt($options, 8, 'defaults', 'mailbox', 'min_password_length');

        // The domains this admin may add a mailbox to (id => name); super sees all.
        $choices = $this->domainRepository()->loadForAdminAsArray($admin, true);
        if ($choices === []) {
            $this->flash('There are no domains to which you can add a mailbox.', FlashMessages::INFO);
            return $this->redirect('domain/list');
        }

        // A preferred domain from `did` preselects the dropdown and seeds the quota.
        $preferred = null;
        if (($did = $this->positiveIdParam('did')) !== null) {
            $preferred = $this->resolveAuthorizedTargetDomain($admin, $did, $this->domainRepository());
        }

        $formHost = new FormPluginHost($options);
        $form     = $this->buildMailboxAddForm($choices, $preferred, $minPw, $formHost, $options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $pErr   = $formHost->validate($v, $options);
            $domainId = self::positiveIntegerOrNull($v['domain'] ?? null);
            $domain = $this->resolveAuthorizedTargetDomain($admin, $domainId, $this->domainRepository());

            // The inArray rule already rejected a domain not offered; re-check
            // management server-side so a non-super admin cannot widen scope.
            if ($domain === null) {
                $this->flash('Please select a valid domain.', FlashMessages::ERROR);
            } elseif ($pErr !== null) {
                $this->flash($pErr, FlashMessages::ERROR);
            } elseif (!$admin->isSuper() && $domain->getMaxMailboxes() != 0
                && $domain->getMailboxCount() >= $domain->getMaxMailboxes()) {
                $this->flash('You have used all of your allocated mailboxes.', FlashMessages::ERROR);
            } else {
                $localPart = \ViMbAdmin_Identity::canonical(
                    self::requiredString($v['local_part'] ?? null, 'Mailbox local part'),
                );
                $username  = sprintf('%s@%s', $localPart, $domain->requiredDomainName());

                if (!$this->mailboxRepository()->isUnique($username)) {
                    $this->flash("Mailbox already exists for {$username}", FlashMessages::ERROR);
                } else {
                    $mailbox = new \Entities\Mailbox();
                    $mailbox->setLocalPart($localPart);
                    $mailbox->setUsername($username);
                    $mailbox->setName(self::stringOrDefault($v['name'] ?? null, '', 'Mailbox name'));
                    $mailbox->setAltEmail(self::optionalString($v['alt_email'] ?? null, 'Alternative email'));
                    $mailbox->setPassword(self::requiredString($v['password'] ?? null, 'Mailbox password')); // plaintext; the service hashes it
                    $mailbox->setQuota(self::quotaBytes(
                        self::stringOrDefault($v['quota'] ?? null, '', 'Mailbox quota'),
                        $mult,
                    ));

                    // Clamp the quota to the domain's per-mailbox maximum.
                    if ($domain->getMaxQuota() != 0
                        && ($mailbox->getQuota() <= 0 || $mailbox->getQuota() > $domain->getMaxQuota())) {
                        $domainQuota = $domain->requiredQuota();
                        $mailbox->setQuota($domainQuota);
                        $this->flash('Mailbox quota set to ' . $domainQuota, FlashMessages::INFO);
                    }

                    // Plugin form sections write back onto the entity (e.g. the
                    // AccessPermissions access restriction).
                    $formHost->apply($mailbox, $v, $options, $em);

                    // Fire the post-flush plugin hook natively so
                    // MailboxAutomaticAliases creates its RFC2142 default aliases.
                    $context = new MailboxContext(
                        $em,
                        $admin,
                        $domain,
                        $mailbox,
                        $options,
                        new FlashMessages(new MagicPropertyStorage($this->session())),
                    );
                    $host = new PluginHost($context);

                    (new \ViMbAdmin_Service_Mailbox($em))->create(
                        $mailbox,
                        $domain,
                        $admin,
                        $options,
                        null,
                        function () use ($host, $context, $options): void {
                            $host->notify('mailbox', 'add', 'addPostflush', $context, ['options' => $options]);
                        },
                    );

                    if ($this->param('did')) {
                        (new MagicPropertyStorage($this->session()))->set('domain', $domain);
                    }

                    $this->flash('You have successfully added the mailbox record.');
                    return $this->redirect('mailbox/list');
                }
            }
        }

        return $this->view('mailbox/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/mailbox/add', 'Add Mailbox'),
        ]);
    }

    /**
     * GET|POST /mailbox/edit/mid/<id> — edit an existing mailbox.
     *
     * Native port of the edit path of the ZF1 `MailboxController::addAction`
     * (`editAction` is a `forward('add')`). Only the editable fields are shown —
     * name, quota, alt_email, plus the plugin sections ({@see FormPluginHost},
     * AccessPermissions today) prefilled from the entity. The ZF1 edit form drops
     * local_part / domain / password, so the native form does too (the address,
     * its domain and the password are not editable here).
     *
     * GET prepopulates from the entity. POST validates the base form + the plugin
     * sections, writes the editable fields back, clamps the quota to the domain
     * maximum, applies the plugin writebacks and persists through the extracted
     * {@see \ViMbAdmin_Service_Mailbox::update} — threading the
     * `mailbox_add_addPostflush` plugin hook over the native {@see PluginHost} so
     * MailboxAutomaticAliases re-asserts its RFC2142 aliases (idempotent). A
     * missing / unmanaged mailbox flashes and bounces to the list (the ZF1
     * `loadMailbox` redirect).
     */
    public function editAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $em->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox instanceof \Entities\Mailbox) {
            $this->flash('Mailbox not found.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }
        $mailboxDomain = $mailbox->getDomain();
        if (!$mailboxDomain instanceof \Entities\Domain
            || (!$admin->isSuper() && !$admin->canManageDomain($mailboxDomain))) {
            $this->flash('Mailbox not found.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $options  = $this->container->options();
        $mult     = self::quotaMultiplier($options);
        $formHost = new FormPluginHost($options);
        $form     = $this->buildMailboxEditForm($mailbox, $formHost, $options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v    = $form->values();
            $pErr = $formHost->validate($v, $options);

            if ($pErr !== null) {
                $this->flash($pErr, FlashMessages::ERROR);
            } else {
                $domain = $mailbox->getDomain();
                if (!$domain instanceof \Entities\Domain) {
                    throw new \LogicException('Mailbox domain cannot be null.');
                }

                $mailbox->setName(self::stringOrDefault($v['name'] ?? null, '', 'Mailbox name'));
                $mailbox->setAltEmail(self::optionalString($v['alt_email'] ?? null, 'Alternative email'));
                $mailbox->setQuota(self::quotaBytes(
                    self::stringOrDefault($v['quota'] ?? null, '', 'Mailbox quota'),
                    $mult,
                ));

                // Clamp the quota to the domain's per-mailbox maximum.
                if ($domain->getMaxQuota() != 0
                    && ($mailbox->getQuota() <= 0 || $mailbox->getQuota() > $domain->getMaxQuota())) {
                    $domainQuota = $domain->requiredQuota();
                    $mailbox->setQuota($domainQuota);
                    $this->flash('Mailbox quota set to ' . $domainQuota, FlashMessages::INFO);
                }

                $formHost->apply($mailbox, $v, $options, $em);

                $context = new MailboxContext(
                    $em,
                    $admin,
                    $domain,
                    $mailbox,
                    $options,
                    new FlashMessages(new MagicPropertyStorage($this->session())),
                );
                $host = new PluginHost($context);

                (new \ViMbAdmin_Service_Mailbox($em))->update(
                    $mailbox,
                    $admin,
                    null,
                    function () use ($host, $context, $options): void {
                        $host->notify('mailbox', 'add', 'addPostflush', $context, ['options' => $options]);
                    },
                );

                $this->flash('You have successfully added/edited the mailbox record.');
                return $this->redirect('mailbox/list');
            }
        }

        return $this->view('mailbox/native-add.phtml', [
            'formHtml'  => (new FormRenderer())->render($form, '/mailbox/edit/mid/' . $mailbox->getId(), 'Save'),
            'pageTitle' => $this->mailboxPageTitle($mailbox),
        ]);
    }

    /** Preserve the legitimate pre-hydration add form without inventing an identity. */
    private function mailboxPageTitle(\Entities\Mailbox $mailbox): string
    {
        $username = $mailbox->getUsername();
        if ($username === null && $mailbox->getId() === null) {
            return 'Add Mailbox';
        }
        return 'Edit Mailbox: ' . $mailbox->requiredUsername();
    }

    /**
     * GET /mailbox/aliases/mid/<id>[/ima/<0|1>] — list a mailbox's aliases.
     *
     * Native port of `MailboxController::aliasesAction`: resolves + authorises the
     * mailbox and renders `mailbox/aliases.phtml` with the aliases that point at it
     * (`loadForMailbox`) merged with the ones that contain it among several gotos
     * (`loadWithMailbox`); the `ima` flag toggles the mailbox-aliases view.
     */
    public function aliasesAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        if (!$mailbox instanceof \Entities\Mailbox) {
            return $this->redirect('mailbox/list');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return $this->redirect('mailbox/list');
        }

        $imaValue = $this->param('ima', 0);
        $ima = $imaValue === 1 || $imaValue === '1';
        $aliasRepo = $this->aliasRepository();

        return $this->view('mailbox/aliases.phtml', [
            'mailbox' => $mailbox,
            'ima'     => $ima,
            'aliases' => array_merge(
                $aliasRepo->loadForMailbox($mailbox, $admin, $ima),
                $aliasRepo->loadWithMailbox($mailbox, $admin)
            ),
        ]);
    }

    /**
     * POST /mailbox/delete-alias (mid, alid, csrf in the body) — remove a mailbox
     * from one of its aliases (or delete the alias if this mailbox was its only
     * destination).
     *
     * Faithful port of `MailboxController::deleteAliasAction`: CSRF-gated. If the
     * alias's only goto is this mailbox, the alias is removed and the domain alias
     * count decremented; otherwise this mailbox's address is trimmed out of the
     * comma-separated goto list (leaving the alias for the others). Each path logs
     * an ALIAS_DELETE. Redirects back to the mailbox's alias list.
     *
     * NOTE: also fixes a latent deployment bug — the aliases-page delete link
     * carried no `csrf` segment, so `_assertCsrf()` always failed; the control is
     * now a POST form carrying the token as a hidden field.
     *
     * Both `mid` and `alid` are authorised independently: the admin must manage
     * the mailbox's domain AND the alias's domain. The two domains need not
     * match — detaching a mailbox from a cross-domain alias is supported. Every
     * rejection is the same redirect to `mailbox/list`, so probing `mid` reveals
     * nothing.
     */
    public function deleteAliasAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $em->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;
        $alias = ($alid = $this->positiveIdParam('alid')) !== null
            ? $em->getRepository('\\Entities\\Alias')->find($alid)
            : null;

        if (!$mailbox instanceof \Entities\Mailbox || !$alias instanceof \Entities\Alias) {
            return $this->redirect('mailbox/list');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return $this->redirect('mailbox/list');
        }
        // Authorise the alias independently of the mailbox. The two need not
        // share a domain: `Repositories\Alias::loadForMailbox()` and
        // `loadWithMailbox()` deliberately list cross-domain aliases (they scope
        // on `a.Domain.Admins`, not on the mailbox's domain), and the aliases
        // page renders a delete link for every row it returns. Requiring the
        // same domain here would silently no-op a link the app itself rendered.
        $aliasDomain = $alias->getDomain();
        if (!$aliasDomain instanceof \Entities\Domain
            || (!$admin->isSuper() && !$admin->canManageDomain($aliasDomain))) {
            return $this->redirect('mailbox/list');
        }

        $identity = $this->requiredAliasIdentity($alias);
        $user     = $mailbox->requiredUsername();

        if ($user === $identity['goto']) {
            $em->remove($alias);
            $this->logAlias($admin, "removed alias {$identity['address']}");
            // The counter belongs to the ALIAS's domain, which is not
            // necessarily the mailbox's — see the cross-domain note above.
            $aliasDomain->setAliasCount($aliasDomain->getAliasCount() - 1);
            $this->flash('You have successfully removed the alias.');
        } else {
            $gotos = explode(',', $identity['goto']);
            foreach ($gotos as $key => $item) {
                $gotos[$key] = $item = trim($item);
                if ($item === $user || $item === '') {
                    unset($gotos[$key]);
                }
            }
            $alias->setGoto(implode(',', $gotos));
            $this->logAlias($admin, "removed destination {$user} from alias {$identity['address']}");
            $this->flash("You have successfully removed {$user} from the alias {$identity['address']}.");
        }

        $em->flush();
        return $this->redirect('mailbox/aliases/mid/' . $mailbox->getId());
    }

    /** @return array{address:string,goto:string} */
    private function requiredAliasIdentity(\Entities\Alias $alias): array
    {
        $address = $alias->getAddress();
        if ($address === null) {
            throw new \LogicException('Alias address cannot be null.');
        }

        $goto = $alias->getGoto();
        if ($goto === null) {
            throw new \LogicException('Alias goto cannot be null.');
        }

        return ['address' => $address, 'goto' => $goto];
    }

    /** Write an ALIAS_DELETE audit row. */
    private function logAlias(\Entities\Admin $admin, string $message): void
    {
        $log = new \Entities\Log();
        $log->setAction(\Entities\Log::ACTION_ALIAS_DELETE)
            ->setData("{$admin->getFormattedName()} {$message}")
            ->setAdmin($admin)
            ->setTimestamp(new \DateTime());
        $this->em()->persist($log);
    }

    /**
     * GET|POST /mailbox/password/mid/<id> — an admin sets a mailbox's password.
     *
     * Native port of `MailboxController::passwordAction`: resolve + authorise the
     * mailbox, then on a valid POST hash the new password with the configured
     * mailbox scheme (framework-free {@see \OSS_Auth_Password}) and store it,
     * logging MAILBOX_PW_CHANGE. The ZF1 `mailbox_password_*` notify hooks have no
     * listeners (verified) so they are no-ops and omitted; the opt-in "email the
     * new password" is dropped (no mailer in the native kernel, like native login).
     * Password rendered as a visible text field, matching the ZF1 form.
     */
    public function passwordAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $em->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        if (!$mailbox instanceof \Entities\Mailbox) {
            $this->flash('No mailbox id passed.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            $this->flash('No mailbox id passed.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $options = $this->container->options();
        $minPw   = self::optionInt($options, 8, 'defaults', 'mailbox', 'min_password_length');

        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('password', 'Password', 'text', [
            Validators::string(),
            Validators::required(),
            Validators::minLength($minPw),
        ]));

        if ($this->isPost() && $form->isValid($this->postData())) {
            $username = $mailbox->requiredUsername();
            $pwOpts = [
                'pwhash'   => self::optionNullableString($options, 'defaults', 'mailbox', 'password_scheme'),
                'username' => $username,
            ];
            $mailbox->setPassword(\OSS_Auth_Password::hash(
                self::requiredString($form->values()['password'] ?? null, 'Mailbox password'),
                $pwOpts,
            ));

            $log = new \Entities\Log();
            $log->setAction(\Entities\Log::ACTION_MAILBOX_PW_CHANGE)
                ->setData("{$admin->getFormattedName()} changed password for mailbox {$username}")
                ->setAdmin($admin)
                ->setTimestamp(new \DateTime());
            $em->persist($log);
            $em->flush();

            $this->flash('Password has been successfully changed.');
            return $this->redirect('mailbox/list');
        }

        return $this->view('mailbox/native-password.phtml', [
            'mailbox'  => $mailbox,
            'formHtml' => (new FormRenderer())->render($form, '/mailbox/password/mid/' . $mailbox->getId(), 'Change Password'),
        ]);
    }

    /**
     * POST /mailbox/queue-repair (mid, csrf in the body) — enqueue a REPAIR task.
     */
    public function queueRepairAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_REPAIR, 'Repair/optimize');
    }

    /**
     * POST /mailbox/queue-archive (mid, csrf in the body) — enqueue an ARCHIVE task.
     */
    public function queueArchiveAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_ARCHIVE, 'Archive');
    }

    /**
     * POST /mailbox/queue-delete (mid, csrf in the body) — enqueue a DELETE task.
     */
    public function queueDeleteAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_DELETE, 'Delete');
    }

    /**
     * Enqueue a background mailbox-maintenance task (the native port of the ZF1
     * `_queueMailboxTask`). CSRF-gated POST (the token travels in the request
     * body, never the URL); resolve + authorise the mailbox,
     * enqueue via `ViMbAdmin_MailboxQueue` (deduped — a second identical open task
     * is refused with an info notice), audit-log the request, flash and bounce to
     * the list. The cron / native run-now then drains it through the shared
     * {@see \ViMbAdmin_Service_QueueRunner}.
     */
    private function queueMailboxTask(string $type, string $label): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        // Validate a configured bearer key before enqueue/flush. An absent or
        // empty key intentionally leaves the cron runner as the fallback.
        try {
            $queueRunnerKey = self::queueRunnerKey($this->container->options());
        } catch (\LogicException $e) {
            error_log('queue trigger configuration rejected: ' . $e->getMessage());
            return $this->redirect('mailbox/list');
        }

        $mailbox = ($mid = $this->positiveIdParam('mid')) !== null
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        if (!$mailbox instanceof \Entities\Mailbox) {
            return $this->redirect('mailbox/list');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return $this->redirect('mailbox/list');
        }

        $username = $mailbox->requiredUsername();
        $task     = \ViMbAdmin_MailboxQueue::enqueue($this->em(), $mailbox, $type, $admin);
        $this->em()->flush();

        if ($task) {
            $log = new \Entities\Log();
            $log->setAction(\Entities\Log::ACTION_MAILBOX_EDIT)
                ->setData("{$admin->getFormattedName()} queued {$type} for {$username}")
                ->setAdmin($admin)
                ->setTimestamp(new \DateTime());
            $this->em()->persist($log);
            $this->em()->flush();

            $this->flash(sprintf('%s queued for %s.', $label, $username));
        } else {
            $this->flash(sprintf('A %s task is already queued for %s.', strtolower($label), $username), FlashMessages::INFO);
        }

        // Kick the queue immediately instead of waiting for the */2-min cron,
        // WITHOUT draining in this request. We fire a fire-and-forget HTTP POST
        // at the local /queue/trigger endpoint and return at once; the drain
        // (slow doveadm backup/delete) then runs in the TRIGGER's FPM worker via
        // its own afterSend — not here. This keeps the delete/archive request
        // snappy and, critically, releases this request's session lock promptly
        // (an in-request afterSend drain holds the file-session flock for the
        // whole backup, so the browser's follow-up GET /mailbox/list blocks on
        // session_start() until it finishes -> 504). No shell-out (Snuffleupagus
        // blocks exec), no fork. If the trigger is disabled (no queue.runner.key)
        // the call is a silent no-op and the */2-min cron drains the backlog.
        $this->kickQueueAsync($queueRunnerKey);

        return $this->redirect('mailbox/list');
    }

    /**
     * Fire-and-forget nudge of the local POST /queue/trigger endpoint so a
     * just-enqueued task is drained now instead of at the next cron tick — in
     * the trigger's own worker, never in the caller's request.
     *
     * Connects to loopback (127.0.0.1) on the same port/scheme this request
     * arrived on, carrying the real vhost in the Host header (and as TLS SNI) so
     * the web server routes to the right server block and sub-path mount. Writes
     * the bearer-authenticated request and closes immediately without reading the
     * response — the trigger accepts, returns {"triggered":true} and drains after
     * its own connection closes. Best-effort: any failure (endpoint disabled,
     * connect error) is swallowed; the cron runner remains the guaranteed path.
     *
     */
    private function kickQueueAsync(string $key): void
    {
        if ($key === '') {
            return; // remote trigger disabled — the */2-min cron will drain
        }

        try {
            $endpoint = self::queueEndpoint(
                $_SERVER['HTTPS'] ?? null,
                $_SERVER['HTTP_HOST'] ?? null,
                $_SERVER['SERVER_PORT'] ?? null,
            );
        } catch (\LogicException $e) {
            error_log('kickQueueAsync: invalid runtime endpoint (' . $e->getMessage() . ') — cron will drain');
            return;
        }
        $https = $endpoint['https'];
        $host = $endpoint['host'];
        $sni = $endpoint['sni'];
        $port = $endpoint['port'];

        $path = rtrim((string) \OSS_Runtime::baseUrl(), '/') . '/queue/trigger';

        $ctx = stream_context_create($https ? ['ssl' => [
            // Loopback self-call; the bearer key is the real authentication, so
            // peer verification only gets in the way (the cert is for the public
            // name, we connect to 127.0.0.1). SNI/peer_name still set so the
            // TLS handshake + vhost routing pick the right server block.
            'peer_name'         => $sni,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
        ]] : []);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            ($https ? 'ssl' : 'tcp') . '://127.0.0.1:' . $port,
            $errno,
            $errstr,
            1.0,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            error_log("kickQueueAsync: connect 127.0.0.1:{$port} failed ({$errno} {$errstr}) — cron will drain");
            return;
        }

        $req = "POST {$path} HTTP/1.1\r\n"
             . "Host: {$host}\r\n"
             . "Authorization: Bearer {$key}\r\n"
             . "Content-Length: 0\r\n"
             . "Connection: close\r\n\r\n";

        @stream_set_timeout($fp, 1);
        self::writeRequest($fp, $req);
        // Fire-and-forget: do not read the body — the trigger drains on its own.
        @fclose($fp);
    }

    /** @param resource $stream */
    private static function writeRequest($stream, string $request): bool
    {
        $offset = 0;
        $length = strlen($request);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($request, $offset));
            if ($written === false || $written === 0) {
                error_log('kickQueueAsync: short write — cron will drain');
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /**
     * Build the native mailbox EDIT form: only the editable fields (name, quota,
     * alt_email) + the plugin sections, prefilled from the entity.
     *
     * @param array<string,mixed> $options the merged application options
     */
    private function buildMailboxEditForm(
        \Entities\Mailbox $mailbox,
        FormPluginHost $formHost,
        array $options
    ): Form {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $name = new Field('name', 'Name', 'text', [Validators::string(), Validators::noControlChars()]);
        $name->setValue((string) $mailbox->getName());
        $form->add($name);

        $quota = new Field('quota', 'Quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]);
        $quota->setValue((string) \OSS_Filter_FileSize::unfilter((int) $mailbox->getQuota()));
        $form->add($quota);

        $altEmail = new Field('alt_email', 'Alternative Email', 'text', [
            Validators::string(),
            static function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null; // optional
                }
                $error = Validators::email()($value);
                if ($error !== null && !is_string($error)) {
                    throw new \LogicException('Email validator returned an invalid result');
                }
                return $error;
            },
        ]);
        $altEmail->setValue((string) $mailbox->getAltEmail());
        $form->add($altEmail);

        // Native plugin form sections, prefilled from the entity (AccessPermissions
        // reads the mailbox's access restriction).
        foreach ($formHost->fields($mailbox, $options) as $field) {
            $form->add($field);
        }

        return $form;
    }

    /**
     * Build the native mailbox add form: the base fields + the plugin sections.
     *
     * @param array<int|string,string> $choices  domain id → name for the dropdown
     * @param array<string,mixed>      $options  the merged application options
     */
    private function buildMailboxAddForm(
        array $choices,
        ?\Entities\Domain $preferred,
        int $minPw,
        FormPluginHost $formHost,
        array $options
    ): Form {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $form->add(new Field('local_part', 'Local Part', 'text', [
            Validators::string(),
            Validators::required(),
            Validators::localPart(),
        ]));

        $domainKeys = array_map('strval', array_keys($choices));
        $domainField = new Field('domain', 'Domain', 'select', [
            Validators::string(),
            Validators::required(),
            Validators::inArray($domainKeys),
        ]);
        $domainField->setOptions(['' => ''] + $choices);
        if ($preferred !== null) {
            $domainField->setValue((string) $preferred->requiredId());
        }
        $form->add($domainField);

        $form->add(new Field('name', 'Name', 'text', [Validators::string(), Validators::noControlChars()]));

        // ZF1 renders the password as a visible text field; keep that (type=text)
        // so the generated password stays readable on screen.
        $form->add(new Field('password', 'Password', 'text', [
            Validators::string(),
            Validators::required(),
            Validators::minLength($minPw),
        ]));

        $quota = new Field('quota', 'Quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]);
        $quota->setValue($preferred !== null
            ? (string) \OSS_Filter_FileSize::unfilter($preferred->requiredQuota())
            : '0');
        $form->add($quota);

        $form->add(new Field('alt_email', 'Alternative Email', 'text', [
            Validators::string(),
            static function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null; // optional
                }
                $error = Validators::email()($value);
                if ($error !== null && !is_string($error)) {
                    throw new \LogicException('Email validator returned an invalid result');
                }
                return $error;
            },
        ]));

        // Native plugin form sections (AccessPermissions today).
        foreach ($formHost->fields(null, $options) as $field) {
            $form->add($field);
        }

        return $form;
    }

    /**
     * GET|POST /mailbox/email-settings/mid/<id> — the "send settings" modal.
     *
     * Native port of the ZF1 `emailSettingsAction` + `_sendSettingsEmail`, the
     * last mailer-dependent UI action (WALL #2 slice 6b). It is ajax-loaded into a
     * modal: a GET returns the chrome-less modal HTML (a `type` select of
     * username / alt_email / other + an "other" free-text box) and a POST with
     * `send=1` resolves the recipient(s) and mails the per-user settings,
     * returning the literal `ok` / `error` the modal JS expects (or re-rendering
     * the modal with an inline error, which the JS detects by its
     * `<div class="modal-header">` prefix and swaps back in).
     *
     * The mail is sent through the native {@see \ViMbAdmin\Kernel\Mail\Mailer}
     * (slice 6b-1). The ZF1 `mailbox/sendSettingsEmail/preSetBody` notify hook has
     * no listeners, so it is not replicated (the optional `vimbadminPlugins`
     * template var simply stays unset). The welcome-email variant + its `cc` are
     * dropped, consistent with the other native mailbox actions.
     */
    public function emailSettingsAction(): Response
    {
        $admin   = $this->admin();
        $mid     = $this->positiveIdParam('mid');
        $mailbox = $mid !== null
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        // loadMailbox() authorisation: a non-super admin must manage the domain.
        if ($admin === null || !$mailbox instanceof \Entities\Mailbox) {
            return new Response('error');
        }
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return new Response('error');
        }
        $username = $mailbox->requiredUsername();

        // The recipient choices: the mailbox itself, its alternative email (if
        // set), and a free-text "other".
        $typeOptions = ['username' => $username];
        if ($mailbox->getAltEmail()) {
            $typeOptions['alt_email'] = $mailbox->getAltEmail();
        }
        $typeOptions['other'] = 'Other';

        if ($this->isPost() && $this->param('send')) {
            $post = $this->postData();

            $form = new Form(new Csrf(new MagicPropertyStorage($this->session())));
            $form->add(new Field('type', 'Email', 'select', [
                Validators::string(),
                Validators::required(),
                Validators::inArray(array_keys($typeOptions)),
            ]));
            $form->add(new Field('email', 'Other Email(s)', 'text', [Validators::string()]));
            $form->field('type')?->setOptions($typeOptions);

            $error      = null;
            $recipients = [];

            if (!$form->isValid($post)) {
                $error = $form->errors()['_form'] ?? $form->errors()['type'] ?? 'Invalid submission.';
            } else {
                $type = self::requiredString($form->values()['type'] ?? null, 'Email recipient type');

                if ($type === 'other') {
                    $raw = trim(self::stringOrDefault($post['email'] ?? null, '', 'Other email recipients'));
                    foreach (explode(',', $raw) as $em) {
                        $em = trim($em);
                        if ($em === '') {
                            continue;
                        }
                        if (Validators::email()($em) !== null) {
                            $error = 'Not valid email address(es)';
                            break;
                        }
                        $recipients[] = $em;
                    }
                    if ($error === null && $recipients === []) {
                        $error = 'Other Email(s) is required.';
                    }
                } elseif ($type === 'alt_email') {
                    $recipients[] = (string) $mailbox->getAltEmail();
                } else {
                    $recipients[] = $username;
                }
            }

            if ($error === null && $recipients !== []) {
                return new Response($this->sendSettingsEmail($mailbox, $recipients) ? 'ok' : 'error');
            }

            // Re-render the modal with the error: the JS swaps it back in.
            return new Response($this->renderEmailSettingsModal(
                $mailbox,
                $typeOptions,
                self::renderStringOrDefault($post['type'] ?? null, 'username'),
                self::renderStringOrDefault($post['email'] ?? null, ''),
                $error
            ));
        }

        return new Response($this->renderEmailSettingsModal($mailbox, $typeOptions, 'username', '', null));
    }

    /**
     * Render the chrome-less email-settings modal (header + form + footer + JS).
     *
     * @param array<string,string> $typeOptions value => label
     */
    private function renderEmailSettingsModal(
        \Entities\Mailbox $mailbox,
        array $typeOptions,
        string $selectedType,
        string $emailValue,
        ?string $error
    ): string {
        return $this->renderPartial('mailbox/native-email-settings.phtml', [
            'mailbox'      => $mailbox,
            'typeOptions'  => $typeOptions,
            'selectedType' => $selectedType,
            'emailValue'   => $emailValue,
            'esError'      => $error,
            'csrfToken'    => (new Csrf(new MagicPropertyStorage($this->session())))->token(),
        ]);
    }

    /**
     * Build + send the settings email (the native `_sendSettingsEmail`). From is
     * `server.email.*`; the body is `mailbox/email/settings.phtml` rendered with
     * the per-user-substituted `server.*` display settings
     * (`\Entities\Mailbox::substitute`). Returns false on a transport failure
     * (the action maps that to the `error` the modal JS shows).
     *
     * @param list<string> $recipients
     */
    private function sendSettingsEmail(\Entities\Mailbox $mailbox, array $recipients): bool
    {
        $options = $this->container->options();
        $domain = $mailbox->getDomain();
        if (!$domain instanceof \Entities\Domain) {
            throw new \LogicException('Mailbox domain cannot be null.');
        }
        $domainName = $domain->requiredDomainName();
        $username = $mailbox->requiredUsername();

        $senderAddress = self::controlSafeString(
            self::optionString($options, 'support@localhost', 'server', 'email', 'address'),
            'Configuration server.email.address',
        );
        $senderName = self::controlSafeString(
            self::optionString($options, '', 'server', 'email', 'name'),
            'Configuration server.email.name',
        );

        $email = (new Email())
            ->from(new Address(
                $senderAddress,
                $senderName,
            ))
            ->subject(sprintf('Settings for your mailbox on %s', $domainName));

        foreach ($recipients as $rcpt) {
            $email->addTo($rcpt);
        }

        // Substitute %m/%d/%u in each server.* display value for this mailbox.
        $serverSettings = self::optionArray($options, [], 'server');
        $settings = [];
        foreach ($serverSettings as $tech => $params) {
            if (!is_array($params)) {
                continue;
            }
            $typedParams = self::stringKeyedArray($params, "Configuration server.{$tech}");
            $settings[$tech] = [];
            foreach ($typedParams as $k => $v) {
                $settings[$tech][$k] = \Entities\Mailbox::substitute(
                    $username,
                    self::displaySettingString($v, "Configuration server.{$tech}.{$k}"),
                );
            }
        }

        $email->text($this->renderPartial('mailbox/email/settings.phtml', [
            'mailbox'  => $mailbox,
            'welcome'  => false,
            'password' => '',
            'settings' => $settings,
        ]));

        try {
            $this->mailer()->send($email);
            return true;
        } catch (\Throwable $e) {
            error_log('MailboxController::emailSettings send: ' . $e->getMessage());
            return false;
        }
    }

    protected function em(): EntityManagerInterface
    {
        $em = parent::em();
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Doctrine entity manager resource has an invalid type');
        }
        return $em;
    }

    private function domainRepository(): \Repositories\Domain
    {
        $repo = $this->em()->getRepository('\\Entities\\Domain');
        if (!$repo instanceof \Repositories\Domain) {
            throw new \LogicException('Domain repository has an invalid type');
        }
        return $repo;
    }

    private function mailboxRepository(): \Repositories\Mailbox
    {
        $repo = $this->em()->getRepository('\\Entities\\Mailbox');
        if (!$repo instanceof \Repositories\Mailbox) {
            throw new \LogicException('Mailbox repository has an invalid type');
        }
        return $repo;
    }

    private function aliasRepository(): \Repositories\Alias
    {
        $repo = $this->em()->getRepository('\\Entities\\Alias');
        if (!$repo instanceof \Repositories\Alias) {
            throw new \LogicException('Alias repository has an invalid type');
        }
        return $repo;
    }
}
