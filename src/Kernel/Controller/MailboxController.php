<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

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
 */
final class MailboxController extends AbstractController
{
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
        $q      = DataTableQuery::fromArray($_GET);

        // Column index -> sortable DB field (must match the JS column order;
        // computed columns — used quota, last login, controls — fall back).
        $sortField = [0 => 'username', 1 => 'name', 4 => 'domain', 5 => 'active'][$q->sortColumn] ?? 'username';

        $r = $this->em()->getRepository('\\Entities\\Mailbox')
            ->pagedForMailboxList($admin, $domain, $q->search, $sortField, $q->sortDir, $q->start, $q->length);

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
        $session = $this->session();
        $domain  = null;

        if ($this->param('unset', false)) {
            unset($session->domain);
        } elseif (isset($session->domain) && $session->domain) {
            $domain = $session->domain;
        } elseif ($did = $this->param('did')) {
            $domain = $this->em()->getRepository('\\Entities\\Domain')->find((int) $did);
            // loadDomain() authorises a non-super admin against the domain.
            if ($domain && !$admin->isSuper() && !$admin->canManageDomain($domain)) {
                return $this->redirect('auth/login');
            }
            if ($domain) {
                $session->domain = $domain;
            }
        }

        $opts = $this->container->options();

        $paginate = isset($opts['defaults']['server_side']['pagination']['enable'])
            && $opts['defaults']['server_side']['pagination']['enable'];

        $vars = [
            'mailboxes' => $paginate
                ? []
                : $this->em()->getRepository('\\Entities\\Mailbox')->loadForMailboxList($admin, $domain),
        ];

        if (empty($opts['defaults']['list_size']['disabled'])) {
            $key = (isset($opts['defaults']['list_size']['multiplier'])
                && isset(\OSS_Filter_FileSize::$SIZE_MULTIPLIERS[$opts['defaults']['list_size']['multiplier']]))
                ? $opts['defaults']['list_size']['multiplier']
                : \OSS_Filter_FileSize::SIZE_KILOBYTES;

            $vars['size_multiplier'] = $key;
            $vars['multiplier']      = \OSS_Filter_FileSize::$SIZE_MULTIPLIERS[$key];
        }

        return $this->view('mailbox/list.phtml', $vars);
    }

    /**
     * GET /mailbox/ajax-toggle-active/mid/<id> — flip a mailbox's active flag.
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

        $mailbox = ($mid = $this->param('mid'))
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            return new Response('ko');
        }

        $context = new MailboxContext(
            $this->em(),
            $admin,
            $mailbox->getDomain(),
            $mailbox,
            $this->container->options(),
            new FlashMessages(new MagicPropertyStorage($this->session())),
        );
        $host = new PluginHost($context);

        $result = (new \ViMbAdmin_Service_Mailbox($this->em()))->toggleActive(
            $mailbox,
            $admin,
            fn() => $host->notify('mailbox', 'toggleActive', 'preToggle', $context, ['active' => $mailbox->getActive()]) === true,
            fn() => $host->notify('mailbox', 'toggleActive', 'preflush', $context, ['active' => $mailbox->getActive()]),
            fn() => $host->notify('mailbox', 'toggleActive', 'postflush', $context, ['active' => $mailbox->getActive()]),
        );

        return new Response($result === null ? 'ko' : 'ok');
    }

    /**
     * GET|POST /mailbox/purge/mid/<id>/csrf/<token> — purge a mailbox.
     *
     * Faithful port of the ZF1 `purgeAction`: the CSRF token (carried in the URL,
     * the same one the list's purge link mints) is asserted FIRST on both the
     * GET confirmation and the POST — an invalid/missing token flashes + bounces
     * to the list. A missing mailbox (or a domain the admin cannot manage)
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

        // _assertCsrf(): the token is in the URL on both the GET and the POST.
        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $mailbox = ($mid = $this->param('mid'))
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            return $this->redirect('mailbox/list');
        }

        $aliasRepo = $this->em()->getRepository('\\Entities\\Alias');

        if ($this->isPost() && (($this->postData()['purge'] ?? null) === 'purge')) {
            // On-disk file deletion removed: ViMbAdmin has no shared maildir
            // filesystem (mail lives in the Dovecot container, reached over the
            // doveadm HTTP API). Real mail removal goes through the doveadm
            // queue TYPE_DELETE task. Purge only ever drops the DB rows here.
            $deleteFiles = false;

            $context = new MailboxContext(
                $this->em(),
                $admin,
                $mailbox->getDomain(),
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
                fn() => $host->notify('mailbox', 'purge', 'preFlush', $context),
                fn() => $host->notify('mailbox', 'purge', 'postFlush', $context),
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
    public function addAction(): ?Response
    {
        // Edit is served natively by editAction; redirect the legacy
        // add-with-mid alias there rather than punting to ZF1.
        if ($mid = $this->param('mid')) {
            return $this->redirect('mailbox/edit/mid/' . (int) $mid);
        }

        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $options = $this->container->options();
        $mult    = $options['defaults']['quota']['multiplier'] ?? \OSS_Filter_FileSize::SIZE_KILOBYTES;
        $minPw   = (int) ($options['defaults']['mailbox']['min_password_length'] ?? 8);

        // The domains this admin may add a mailbox to (id => name); super sees all.
        $choices = $em->getRepository('\\Entities\\Domain')->loadForAdminAsArray($admin, true);
        if ($choices === []) {
            $this->flash('There are no domains to which you can add a mailbox.', FlashMessages::INFO);
            return $this->redirect('domain/list');
        }

        // A preferred domain from `did` preselects the dropdown and seeds the quota.
        $preferred = null;
        if ($did = $this->param('did')) {
            $d = $em->getRepository('\\Entities\\Domain')->find((int) $did);
            if ($d !== null && ($admin->isSuper() || $admin->canManageDomain($d))) {
                $preferred = $d;
            }
        }

        $formHost = new FormPluginHost($options);
        $form     = $this->buildMailboxAddForm($choices, $preferred, $mult, $minPw, $formHost, $options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $pErr   = $formHost->validate($v, $options);
            $domain = $em->getRepository('\\Entities\\Domain')->find((int) $v['domain']);

            // The inArray rule already rejected a domain not offered; re-check
            // management server-side so a non-super admin cannot widen scope.
            if ($domain === null || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
                $this->flash('Please select a valid domain.', FlashMessages::ERROR);
            } elseif ($pErr !== null) {
                $this->flash($pErr, FlashMessages::ERROR);
            } elseif (!$admin->isSuper() && $domain->getMaxMailboxes() != 0
                && $domain->getMailboxCount() >= $domain->getMaxMailboxes()) {
                $this->flash('You have used all of your allocated mailboxes.', FlashMessages::ERROR);
            } else {
                $localPart = strtolower(trim((string) $v['local_part']));
                $username  = sprintf('%s@%s', $localPart, $domain->getDomain());

                if (!$em->getRepository('\\Entities\\Mailbox')->isUnique($username)) {
                    $this->flash("Mailbox already exists for {$username}", FlashMessages::ERROR);
                } else {
                    $mailbox = new \Entities\Mailbox();
                    $mailbox->setLocalPart($localPart);
                    $mailbox->setUsername($username);
                    $mailbox->setName((string) $v['name']);
                    $mailbox->setAltEmail(($v['alt_email'] ?? '') !== '' ? (string) $v['alt_email'] : null);
                    $mailbox->setPassword((string) $v['password']); // plaintext; the service hashes it
                    $mailbox->setQuota((int) (new \OSS_Filter_FileSize($mult))->filter((string) $v['quota']));

                    // Clamp the quota to the domain's per-mailbox maximum.
                    if ($domain->getMaxQuota() != 0
                        && ($mailbox->getQuota() <= 0 || $mailbox->getQuota() > $domain->getMaxQuota())) {
                        $mailbox->setQuota($domain->getQuota());
                        $this->flash('Mailbox quota set to ' . $domain->getQuota(), FlashMessages::INFO);
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
                        fn() => $host->notify('mailbox', 'add', 'addPostflush', $context, ['options' => $options]),
                    );

                    if ($this->param('did')) {
                        $this->session()->domain = $domain;
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
    public function editAction(): ?Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->param('mid'))
            ? $em->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        // loadMailbox() authorises a non-super admin against the mailbox's domain.
        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            $this->flash('Mailbox not found.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $options  = $this->container->options();
        $mult     = $options['defaults']['quota']['multiplier'] ?? \OSS_Filter_FileSize::SIZE_KILOBYTES;
        $formHost = new FormPluginHost($options);
        $form     = $this->buildMailboxEditForm($mailbox, $mult, $formHost, $options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v    = $form->values();
            $pErr = $formHost->validate($v, $options);

            if ($pErr !== null) {
                $this->flash($pErr, FlashMessages::ERROR);
            } else {
                $domain = $mailbox->getDomain();

                $mailbox->setName((string) $v['name']);
                $mailbox->setAltEmail(($v['alt_email'] ?? '') !== '' ? (string) $v['alt_email'] : null);
                $mailbox->setQuota((int) (new \OSS_Filter_FileSize($mult))->filter((string) $v['quota']));

                // Clamp the quota to the domain's per-mailbox maximum.
                if ($domain->getMaxQuota() != 0
                    && ($mailbox->getQuota() <= 0 || $mailbox->getQuota() > $domain->getMaxQuota())) {
                    $mailbox->setQuota($domain->getQuota());
                    $this->flash('Mailbox quota set to ' . $domain->getQuota(), FlashMessages::INFO);
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
                    fn() => $host->notify('mailbox', 'add', 'addPostflush', $context, ['options' => $options]),
                );

                $this->flash('You have successfully added/edited the mailbox record.');
                return $this->redirect('mailbox/list');
            }
        }

        return $this->view('mailbox/native-add.phtml', [
            'formHtml'  => (new FormRenderer())->render($form, '/mailbox/edit/mid/' . $mailbox->getId(), 'Save'),
            'pageTitle' => 'Edit Mailbox: ' . $mailbox->getUsername(),
        ]);
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

        $mailbox = ($mid = $this->param('mid'))
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            return $this->redirect('mailbox/list');
        }

        $ima       = (int) $this->param('ima', 0);
        $aliasRepo = $this->em()->getRepository('\\Entities\\Alias');

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
     * GET /mailbox/delete-alias/mid/<id>/alid/<id>/csrf/<token> — remove a mailbox
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
     * carried no `csrf` segment, so `_assertCsrf()` always failed; it now carries
     * `$csrfToken`.
     */
    public function deleteAliasAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->param('mid'))
            ? $em->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;
        $alias = ($alid = $this->param('alid'))
            ? $em->getRepository('\\Entities\\Alias')->find((int) $alid)
            : null;

        if (!$mailbox || !$alias || (!$admin->isSuper() && !$admin->canManageDomain($alias->getDomain()))) {
            return $this->redirect('mailbox/list');
        }

        $user = $mailbox->getUsername();

        if ($user === $alias->getGoto()) {
            $em->remove($alias);
            $this->logAlias($admin, "removed alias {$alias->getAddress()}");
            $alias->getDomain()->setAliasCount($alias->getDomain()->getAliasCount() - 1);
            $this->flash('You have successfully removed the alias.');
        } else {
            $gotos = explode(',', (string) $alias->getGoto());
            foreach ($gotos as $key => $item) {
                $gotos[$key] = $item = trim($item);
                if ($item === $user || $item === '') {
                    unset($gotos[$key]);
                }
            }
            $alias->setGoto(implode(',', $gotos));
            $this->logAlias($admin, "removed destination {$user} from alias {$alias->getAddress()}");
            $this->flash("You have successfully removed {$user} from the alias {$alias->getAddress()}.");
        }

        $em->flush();
        return $this->redirect('mailbox/aliases/mid/' . $mailbox->getId());
    }

    /** Write an ALIAS_DELETE audit row. */
    private function logAlias(object $admin, string $message): void
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
    public function passwordAction(): ?Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $mailbox = ($mid = $this->param('mid'))
            ? $em->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            $this->flash('No mailbox id passed.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $options = $this->container->options();
        $minPw   = (int) ($options['defaults']['mailbox']['min_password_length'] ?? 8);

        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('password', 'Password', 'text', [Validators::required(), Validators::minLength($minPw)]));

        if ($this->isPost() && $form->isValid($this->postData())) {
            $pwOpts = [
                'pwhash'   => $options['defaults']['mailbox']['password_scheme'] ?? null,
                'username' => $mailbox->getUsername(),
            ];
            $mailbox->setPassword(\OSS_Auth_Password::hash((string) $form->values()['password'], $pwOpts));

            $log = new \Entities\Log();
            $log->setAction(\Entities\Log::ACTION_MAILBOX_PW_CHANGE)
                ->setData("{$admin->getFormattedName()} changed password for mailbox {$mailbox->getUsername()}")
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
     * GET /mailbox/queue-repair/mid/<id>/csrf/<token> — enqueue a REPAIR task.
     */
    public function queueRepairAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_REPAIR, 'Repair/optimize');
    }

    /**
     * GET /mailbox/queue-archive/mid/<id>/csrf/<token> — enqueue an ARCHIVE task.
     */
    public function queueArchiveAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_ARCHIVE, 'Archive');
    }

    /**
     * GET /mailbox/queue-delete/mid/<id>/csrf/<token> — enqueue a DELETE task.
     */
    public function queueDeleteAction(): Response
    {
        return $this->queueMailboxTask(\Entities\MailboxTask::TYPE_DELETE, 'Delete');
    }

    /**
     * Enqueue a background mailbox-maintenance task (the native port of the ZF1
     * `_queueMailboxTask`). CSRF-gated GET link; resolve + authorise the mailbox,
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

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('mailbox/list');
        }

        $mailbox = ($mid = $this->param('mid'))
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find((int) $mid)
            : null;

        if (!$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            return $this->redirect('mailbox/list');
        }

        $username = $mailbox->getUsername();
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
        $this->kickQueueAsync($this->container->options());

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
     * @param array<string,mixed> $options the merged application options
     */
    private function kickQueueAsync(array $options): void
    {
        $key = (string) ($options['queue']['runner']['key'] ?? '');
        if ($key === '') {
            return; // remote trigger disabled — the */2-min cron will drain
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $sni   = preg_replace('/:\d+$/', '', $host) ?: '127.0.0.1';
        $port  = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));

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
        @fwrite($fp, $req);
        // Fire-and-forget: do not read the body — the trigger drains on its own.
        @fclose($fp);
    }

    /**
     * Build the native mailbox EDIT form: only the editable fields (name, quota,
     * alt_email) + the plugin sections, prefilled from the entity.
     *
     * @param array<string,mixed> $options the merged application options
     */
    private function buildMailboxEditForm(
        object $mailbox,
        string $mult,
        FormPluginHost $formHost,
        array $options
    ): Form {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $name = new Field('name', 'Name', 'text', [Validators::noControlChars()]);
        $name->setValue((string) $mailbox->getName());
        $form->add($name);

        $quota = new Field('quota', 'Quota', 'text', [Validators::nonNegativeNumber()]);
        $quota->setValue((string) \OSS_Filter_FileSize::unfilter((int) $mailbox->getQuota()));
        $form->add($quota);

        $altEmail = new Field('alt_email', 'Alternative Email', 'text', [
            static function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null; // optional
                }
                return Validators::email()($value);
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
        ?object $preferred,
        string $mult,
        int $minPw,
        FormPluginHost $formHost,
        array $options
    ): Form {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $form->add(new Field('local_part', 'Local Part', 'text', [
            Validators::required(),
            Validators::localPart(),
        ]));

        $domainKeys = array_map('strval', array_keys($choices));
        $domainField = new Field('domain', 'Domain', 'select', [
            Validators::required(),
            Validators::inArray($domainKeys),
        ]);
        $domainField->setOptions(['' => ''] + $choices);
        if ($preferred !== null) {
            $domainField->setValue((string) $preferred->getId());
        }
        $form->add($domainField);

        $form->add(new Field('name', 'Name', 'text', [Validators::noControlChars()]));

        // ZF1 renders the password as a visible text field; keep that (type=text)
        // so the generated password stays readable on screen.
        $form->add(new Field('password', 'Password', 'text', [
            Validators::required(),
            Validators::minLength($minPw),
        ]));

        $quota = new Field('quota', 'Quota', 'text', [Validators::nonNegativeNumber()]);
        $quota->setValue($preferred !== null
            ? (string) \OSS_Filter_FileSize::unfilter((int) $preferred->getQuota())
            : '0');
        $form->add($quota);

        $form->add(new Field('alt_email', 'Alternative Email', 'text', [
            static function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null; // optional
                }
                return Validators::email()($value);
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
        $mid     = (int) $this->param('mid', 0);
        $mailbox = $mid > 0
            ? $this->em()->getRepository('\\Entities\\Mailbox')->find($mid)
            : null;

        // loadMailbox() authorisation: a non-super admin must manage the domain.
        if ($admin === null || !$mailbox || (!$admin->isSuper() && !$admin->canManageDomain($mailbox->getDomain()))) {
            return new Response('error');
        }

        // The recipient choices: the mailbox itself, its alternative email (if
        // set), and a free-text "other".
        $typeOptions = ['username' => $mailbox->getUsername()];
        if ($mailbox->getAltEmail()) {
            $typeOptions['alt_email'] = $mailbox->getAltEmail();
        }
        $typeOptions['other'] = 'Other';

        if ($this->isPost() && $this->param('send')) {
            $post = $this->postData();

            $form = new Form(new Csrf(new MagicPropertyStorage($this->session())));
            $form->add(new Field('type', 'Email', 'select', [
                Validators::required(),
                Validators::inArray(array_keys($typeOptions)),
            ]));
            $form->add(new Field('email', 'Other Email(s)', 'text'));
            $form->field('type')?->setOptions($typeOptions);

            $error      = null;
            $recipients = [];

            if (!$form->isValid($post)) {
                $error = $form->errors()['_form'] ?? $form->errors()['type'] ?? 'Invalid submission.';
            } else {
                $type = (string) $form->values()['type'];

                if ($type === 'other') {
                    $raw = trim((string) ($post['email'] ?? ''));
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
                    $recipients[] = (string) $mailbox->getUsername();
                }
            }

            if ($error === null && $recipients !== []) {
                return new Response($this->sendSettingsEmail($mailbox, $recipients) ? 'ok' : 'error');
            }

            // Re-render the modal with the error: the JS swaps it back in.
            return new Response($this->renderEmailSettingsModal(
                $mailbox,
                $typeOptions,
                (string) ($post['type'] ?? 'username'),
                (string) ($post['email'] ?? ''),
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
        object $mailbox,
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
    private function sendSettingsEmail(object $mailbox, array $recipients): bool
    {
        $options = $this->container->options();

        $email = (new Email())
            ->from(new Address(
                (string) ($options['server']['email']['address'] ?? 'support@localhost'),
                (string) ($options['server']['email']['name'] ?? '')
            ))
            ->subject(sprintf('Settings for your mailbox on %s', $mailbox->getDomain()->getDomain()));

        foreach ($recipients as $rcpt) {
            $email->addTo($rcpt);
        }

        // Substitute %m/%d/%u in each server.* display value for this mailbox.
        $settings = $options['server'] ?? [];
        foreach ($settings as $tech => $params) {
            if (!is_array($params)) {
                continue;
            }
            foreach ($params as $k => $v) {
                $settings[$tech][$k] = \Entities\Mailbox::substitute($mailbox->getUsername(), (string) $v);
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
}
