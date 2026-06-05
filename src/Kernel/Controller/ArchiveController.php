<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `ArchiveController::list` (docs/ZF1-REMOVAL.md).
 *
 * Reproduces the legacy `preDispatch` domain-scope juggling + `listAction`: loads
 * the scoped archives via the existing `Repositories\Archive::loadForArchiveList()`
 * and exposes the status map plus the status sets that allow the delete/restore
 * row actions (from the framework-free `Entities\Archive` constants). Only
 * `listAction` is migrated; the form/CRUD actions stay on ZF1.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class ArchiveController extends AbstractController
{
    /**
     * GET /archive/list[/did/<id>][/unset/1] — the archives overview.
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

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

        return $this->view('archive/list.phtml', [
            'archives'     => $this->em()->getRepository('\\Entities\\Archive')->loadForArchiveList($admin, $domain),
            'statuses'     => \Entities\Archive::$ARCHIVE_STATUS,
            'allowDelete'  => [\Entities\Archive::STATUS_ARCHIVED],
            'allowRestore' => [\Entities\Archive::STATUS_ARCHIVED],
        ]);
    }
}
