<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `AdminController::list` (docs/ZF1-REMOVAL.md) — a super-admin
 * read-only list page whose template carries CSRF-guarded action links.
 *
 * `listAction` is the super-only administrator overview: it reproduces the ZF1
 * `preDispatch` super-admin gate (`authorise(true)`) and renders all admins
 * through `admin/list.phtml`. The template's state-changing links (purge, …)
 * carry the per-session CSRF token, which {@see AbstractController::view()} now
 * seeds over the same session key the ZF1 `_assertCsrf()` reads — so those links
 * keep validating against the legacy actions that still serve them.
 *
 * Only `listAction` is migrated; every other action (add/edit/purge/password/
 * two-factor/domains/toggle/cli-*) stays on ZF1 via the dispatcher fallback. The
 * legacy controller is untouched — with VIMBADMIN_NATIVE_KERNEL off ZF1 serves
 * the whole controller unchanged.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AdminController extends AbstractController
{
    /**
     * GET /admin/list — the administrator overview (super admins only).
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            // ZF1 preDispatch authorise(true) redirects a non-super to login.
            return $this->redirect('auth/login');
        }

        $admins = $this->em()->getRepository('\\Entities\\Admin')->findAll();

        return $this->view('admin/list.phtml', ['admins' => $admins]);
    }
}
