<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

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
            $deleteFiles = (bool) $this->param('delete_files', false);

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
        // Edit (mid in URL) stays on ZF1 via the dispatcher fallback.
        if ($this->param('mid')) {
            return null;
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

        return $this->redirect('mailbox/list');
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

        $name = new Field('name', 'Name', 'text');
        $name->setValue((string) $mailbox->getName());
        $form->add($name);

        $quota = new Field('quota', 'Quota', 'text');
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
            Validators::regex('/^[a-zA-Z0-9._%+\-]+$/', 'Please enter a valid local part (the bit before the @).'),
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

        $form->add(new Field('name', 'Name', 'text'));

        // ZF1 renders the password as a visible text field; keep that (type=text)
        // so the generated password stays readable on screen.
        $form->add(new Field('password', 'Password', 'text', [
            Validators::required(),
            Validators::minLength($minPw),
        ]));

        $quota = new Field('quota', 'Quota', 'text');
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
}
