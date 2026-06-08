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
 * `aboutAction` renders the static `index/about.phtml`; `indexAction` is the
 * auth-gated landing — an authenticated admin is sent to the domain list, anyone
 * else to the login page (the native equivalent of the ZF1 `forward('list',
 * 'domain')` / `redirect('auth/login')`; a redirect replaces the internal forward,
 * so `/` lands on `/domain/list`).
 *
 * Preserves the historical landing-page routes and templates.
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

    /**
     * GET / and /index/index — the auth-gated landing page.
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('domain/list')
            : $this->redirect('auth/login');
    }
}
