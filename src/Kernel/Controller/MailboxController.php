<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `MailboxController::list` (docs/ZF1-REMOVAL.md).
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
 * Only `listAction` is migrated; the form/CRUD actions stay on ZF1 via the
 * dispatcher fallback. The legacy controller is untouched.
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
}
