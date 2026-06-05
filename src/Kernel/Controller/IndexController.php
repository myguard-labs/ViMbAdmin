<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `IndexController::about` (Phase 3b, docs/ZF1-REMOVAL.md) — the
 * first controller served by the native kernel that renders a Smarty page (the
 * additionalinfo port emitted JSON).
 *
 * Only `aboutAction` is migrated: it renders the static `index/about.phtml`
 * (chrome + copy, no per-request data), which is the cleanest first proof of the
 * native view pipeline. The controller's other action, `index` (the auth-gated
 * redirect / forward to domain list), is NOT implemented here, so the dispatcher
 * returns null for it and ZF1 still serves `/` and `/index` unchanged — native
 * migration is per-action, not all-or-nothing per controller.
 *
 * The legacy `application/controllers/IndexController.php` stays in place; with
 * VIMBADMIN_NATIVE_KERNEL off, ZF1 serves the whole controller byte-for-byte.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class IndexController extends AbstractController
{
    /**
     * GET /index/about — the static "About ViMbAdmin" page.
     */
    public function aboutAction(): Response
    {
        return $this->view('index/about.phtml');
    }
}
