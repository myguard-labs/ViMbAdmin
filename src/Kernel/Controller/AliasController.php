<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `AliasController::list` (docs/ZF1-REMOVAL.md).
 *
 * Reproduces the legacy `preDispatch` (for the list actions) + `listAction`: the
 * remembered/`did` domain scope is resolved into the session (`unset` clears
 * it), the `ima` flag ("include mailbox aliases") is read from the request, and
 * the scoped aliases are loaded via the existing
 * `Repositories\Alias::loadForAliasList()` (empty initial set when server-side
 * pagination is configured). Unlike the mailbox list, the alias `listAction`
 * DOES expose the current `domain` view variable, so this port sets it too.
 *
 * Only `listAction` is migrated; the form/CRUD actions stay on ZF1 via the
 * dispatcher fallback. The legacy controller is untouched.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AliasController extends AbstractController
{
    /**
     * GET /alias/list[/did/<id>][/ima/<0|1>][/unset/1] — the aliases overview.
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // preDispatch domain juggling: the selected domain is remembered in the
        // session and reused until `unset`.
        $session = $this->session();
        $domain  = null;

        if ($this->param('unset', false)) {
            unset($session->domain);
        } elseif (isset($session->domain) && $session->domain) {
            $domain = $session->domain;
        } elseif ($did = $this->param('did')) {
            $domain = $this->em()->getRepository('\\Entities\\Domain')->find((int) $did);
            if ($domain && !$admin->isSuper() && !$admin->canManageDomain($domain)) {
                return $this->redirect('auth/login');
            }
            if ($domain) {
                $session->domain = $domain;
            }
        }

        $ima = (int) $this->param('ima', 0);

        $opts = $this->container->options();

        $paginate = isset($opts['defaults']['server_side']['pagination']['enable'])
            && $opts['defaults']['server_side']['pagination']['enable'];

        $aliases = $paginate
            ? []
            : $this->em()->getRepository('\\Entities\\Alias')->loadForAliasList($admin, $domain, $ima);

        return $this->view('alias/list.phtml', [
            'ima'     => $ima,
            'domain'  => $domain,
            'aliases' => $aliases,
        ]);
    }
}
