<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Plugin\MailboxContext;
use ViMbAdmin\Kernel\Plugin\PluginHost;
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
}
