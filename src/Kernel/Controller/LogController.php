<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `LogController::list` (Phase 3c, docs/ZF1-REMOVAL.md) — the
 * first native controller that renders DB-driven data into its Smarty view.
 *
 * `listAction` reproduces the legacy controller's `preDispatch` + `listAction`:
 * it resolves the target admin (a non-super admin only ever sees their own
 * actions; a super admin sees everything, or one admin's actions via `aid`) and
 * the domain filter (the `did` URL param, remembered in the session namespace so
 * the list stays scoped across requests; `unset` clears it), then loads the log
 * rows through the existing `Repositories\Log::loadForLogList()` and renders
 * `log/list.phtml`. The view reads the remembered domain from the `session`
 * variable, which {@see AbstractController::view()} seeds.
 *
 * The legacy controller's authorisation side effects are preserved: an `aid`
 * pointing at another admin, or a `did` for a domain the caller cannot manage,
 * redirects exactly as the ZF1 `loadAdmin()` / `loadDomain()` did.
 *
 * `indexAction` and `listAction` both serve the native log listing.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class LogController extends AbstractController
{

    /**
     * GET /log and /log/index — the auth-gated landing forwards to the list
     * (the native equivalent of the ZF1 indexAction `_forward('list')`).
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('log/list')
            : $this->redirect('auth/login');
    }
    /**
     * GET /log/list[/did/<id>][/aid/<id>][/unset/1] — the action log table.
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // --- target admin (whose actions to show) ------------------------- //
        $targetAdmin = false;
        if ($aid = $this->param('aid')) {
            $targetAdmin = $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid);
            if (!$targetAdmin) {
                return $this->redirect('admin/list');
            }
            // loadAdmin() authorises super when acting on another admin.
            if ($targetAdmin->getId() != $admin->getId() && !$admin->isSuper()) {
                return $this->redirect('auth/login');
            }
        }
        if (!$targetAdmin && !$admin->isSuper()) {
            $targetAdmin = $admin;
        }

        // --- domain filter (remembered in the session) -------------------- //
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

        $logs = $this->em()
            ->getRepository('\\Entities\\Log')
            ->loadForLogList($targetAdmin, $domain);

        return $this->view('log/list.phtml', ['logs' => $logs]);
    }
}
