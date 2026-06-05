<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
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

    /**
     * GET /admin/purge/aid/<id>/csrf/<token> — permanently delete an admin.
     *
     * The full state-changing path natively: CSRF-guarded (the link the native
     * admin/list mints carries the session token), super-only, refuses a missing
     * target or self-purge with a flashed error, otherwise purges via the
     * framework-free ViMbAdmin_Service_Admin and flashes success — each followed
     * by a redirect to admin/list, where the {OSS_Message} renderer shows the
     * flash. Mirrors the legacy AdminController::purgeAction.
     */
    public function purgeAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if (!$target) {
            $this->flash('Invalid or non-existent admin.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        if ($admin->getId() == $target->getId()) {
            $this->flash('You cannot purge yourself.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->purge($target, $admin);

        $this->flash('You have successfully purged the admin record.', FlashMessages::SUCCESS);
        return $this->redirect('admin/list');
    }

    /**
     * GET /admin/ajax-toggle-active/aid/<id> — flip an admin's active flag.
     * Mirrors the ZF1 action: prints "ko" when the target is missing or is the
     * caller themselves, otherwise toggles via the framework-free
     * ViMbAdmin_Service_Admin and prints "ok". Like the ZF1 ajax toggles it
     * carries no CSRF token (it is super-gated and self-toggle is refused); the
     * JS reads the bare ok/ko body.
     */
    public function ajaxToggleActiveAction(): Response
    {
        return $this->toggle('toggleActive');
    }

    /**
     * GET /admin/ajax-toggle-super/aid/<id> — flip an admin's super flag.
     */
    public function ajaxToggleSuperAction(): Response
    {
        return $this->toggle('toggleSuper');
    }

    /**
     * Shared body of the two ajax toggles: super gate, resolve the target admin
     * from `aid`, refuse a missing target or self-toggle, then call the named
     * ViMbAdmin_Service_Admin mutator (which owns its log write + flush).
     */
    private function toggle(string $method): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if (!$target || $admin->getId() == $target->getId()) {
            return new Response('ko');
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->{$method}($target, $admin);

        return new Response('ok');
    }
}
