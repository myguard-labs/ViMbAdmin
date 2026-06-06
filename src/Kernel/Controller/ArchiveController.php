<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `ArchiveController::list` + `toggleAutoprune`
 * (docs/ZF1-REMOVAL.md).
 *
 * Reproduces the legacy `preDispatch` domain-scope juggling + `listAction`: loads
 * the scoped archives via the existing `Repositories\Archive::loadForArchiveList()`
 * and exposes the status map plus the status sets that allow the delete/restore
 * row actions (from the framework-free `Entities\Archive` constants).
 *
 * `toggleAutoprune` flips an archive's autoprune flag through the framework-free
 * `ViMbAdmin_Service_Archive` (no plugin hooks, so no callback threading). The
 * `delete`/`restore` actions stay on ZF1 — they drive doveadm / the filesystem
 * (fsDelete / restoreFrom) and the mailbox-repair queue, which the native kernel
 * does not yet wrap.
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

    /**
     * GET /archive/toggle-autoprune/arid/<id>/csrf/<token> — flip autoprune.
     *
     * Faithful port of the ZF1 `toggleAutopruneAction`: the CSRF token (carried in
     * the URL, the same one the archive-list link mints) is asserted first — an
     * invalid/missing token flashes + bounces to the list. The archive is resolved
     * from `arid` and a non-super admin is authorised against its domain (the ZF1
     * `loadArchive` check). The flip / timestamp bookkeeping / log / flush live in
     * the framework-free `ViMbAdmin_Service_Archive::toggleAutoprune`, which
     * returns the new state so the matching success flash is shown. Redirects to
     * the archive list.
     */
    public function toggleAutopruneAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // _assertCsrf(): the token is carried in the URL on the toggle link.
        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $archive = ($arid = $this->param('arid'))
            ? $this->em()->getRepository('\\Entities\\Archive')->find((int) $arid)
            : null;

        // loadArchive() authorises a non-super admin against the archive's domain.
        if (!$archive || (!$admin->isSuper() && !$admin->canManageDomain($archive->getDomain()))) {
            return $this->redirect('archive/list');
        }

        $enabled = (new \ViMbAdmin_Service_Archive($this->em()))->toggleAutoprune($archive, $admin);

        $this->flash($enabled
            ? sprintf('Autoprune enabled for %s; the prune window restarts from now.', $archive->getUsername())
            : sprintf('Autoprune disabled for %s.', $archive->getUsername()));

        return $this->redirect('archive/list');
    }
}
