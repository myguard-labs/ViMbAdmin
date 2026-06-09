<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
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
 * `ViMbAdmin_Service_Archive` (no plugin hooks, so no callback threading).
 * `delete` removes the backup files via the doveadm HTTP API
 * ({@see \ViMbAdmin_Doveadm}) and then the archive row. `restore` stays on ZF1 —
 * it recreates the mailbox + doveadm-syncs the backup + enqueues a repair, which
 * the native kernel does not yet wrap.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class ArchiveController extends AbstractController
{

    /**
     * GET /archive and /archive/index — the auth-gated landing forwards to the list
     * (the native equivalent of the ZF1 indexAction `_forward('list')`).
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('archive/list')
            : $this->redirect('auth/login');
    }
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

        $cfg      = $this->container->options()['defaults']['server_side']['pagination']['archive'] ?? [];
        $archives = empty($cfg['enable'])
            ? $this->em()->getRepository('\\Entities\\Archive')->loadForArchiveList($admin, $domain)
            : [];

        return $this->view('archive/list.phtml', [
            'archives'     => $archives,
            'statuses'     => \Entities\Archive::$ARCHIVE_STATUS,
            'allowDelete'  => [\Entities\Archive::STATUS_ARCHIVED],
            'allowRestore' => [\Entities\Archive::STATUS_ARCHIVED],
        ]);
    }

    /**
     * GET /archive/list-data — DataTables server-side processing source.
     *
     * One page of the scoped archive list (honouring the remembered domain) as
     * the DataTables JSON envelope. Active when archive server-side pagination is
     * enabled.
     */
    public function listDataAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return new Response('ko');
        }

        $session = $this->session();
        $domain  = (isset($session->domain) && $session->domain) ? $session->domain : null;

        $q = DataTableQuery::fromArray($_GET);
        // Column index -> sortable field (matches JS column order; size / user-exists
        // / autoprune / controls fall back to archived date).
        $sortField = [0 => 'username', 1 => 'status', 2 => 'domain', 4 => 'archived_at'][$q->sortColumn] ?? 'archived_at';

        $r = $this->em()->getRepository('\\Entities\\Archive')
            ->pagedForArchiveList($admin, $domain, $q->search, $sortField, $q->sortDir, $q->start, $q->length);

        foreach ($r['rows'] as &$row) {
            if (($row['archived_at'] ?? null) instanceof \DateTimeInterface) {
                $row['archived_at'] = $row['archived_at']->format('Y-m-d H:i');
            }
        }
        unset($row);

        return new Response(
            DataTableResult::json($q, $r['total'], $r['filtered'], $r['rows']),
            200,
            'application/json; charset=utf-8'
        );
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

    /**
     * GET /archive/delete/arid/<id>/csrf/<token> — delete a backup permanently.
     *
     * Faithful port of the ZF1 `deleteAction`: CSRF-gated, resolve + authorise the
     * archive, remove the backup files via the doveadm HTTP API FIRST (a failure
     * aborts with an error flash, before the DB row is touched — matching ZF1), then
     * drop the archive row + log via `ViMbAdmin_Service_Archive::delete`. Redirects
     * to the archive list.
     */
    public function deleteAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $archive = ($arid = $this->param('arid'))
            ? $this->em()->getRepository('\\Entities\\Archive')->find((int) $arid)
            : null;

        if (!$archive || (!$admin->isSuper() && !$admin->canManageDomain($archive->getDomain()))) {
            return $this->redirect('archive/list');
        }

        $user = $archive->getUsername();
        $dest = $archive->getMaildirFile();

        // Remove the backup files first; abort (keeping the row) if doveadm fails.
        try {
            if ($dest) {
                \ViMbAdmin_Doveadm::fromOptions($this->container->options())->fsDelete($dest);
            }
        } catch (\Throwable $e) {
            $this->flash(sprintf('Could not remove the backup files for %s: %s', $user, $e->getMessage()), FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        (new \ViMbAdmin_Service_Archive($this->em()))->delete($archive, $admin);

        $this->flash(sprintf('Archive backup for %s deleted.', $user));
        return $this->redirect('archive/list');
    }

    /**
     * GET /archive/restore/arid/<id>/csrf/<token> — restore a backup into the
     * live mailbox.
     *
     * Faithful port of the ZF1 `restoreAction`: CSRF-gated; only an ARCHIVED
     * backup can be restored. (1) If the mailbox was DELETEd it is recreated from
     * the JSON snapshot stored on the archive (original password hash preserved).
     * (2) The backup is synced back into the live store via the doveadm HTTP API
     * (`ViMbAdmin_Doveadm::restoreFrom`); a sync failure leaves the recreated
     * mailbox but aborts with an error (the archive is kept). (3) The backup files
     * are removed (`fsDelete`; a leftover is non-fatal). (4) The archive row is
     * dropped and a background REPAIR is enqueued so indexes/quota are rebuilt.
     * The doveadm client + the queue helper are framework-free, so src/ stays
     * free of any ZF1 reference.
     */
    public function restoreAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $em      = $this->em();
        $archive = ($arid = $this->param('arid'))
            ? $em->getRepository('\\Entities\\Archive')->find((int) $arid)
            : null;

        if (!$archive || (!$admin->isSuper() && !$admin->canManageDomain($archive->getDomain()))) {
            return $this->redirect('archive/list');
        }

        if ($archive->getStatus() !== \Entities\Archive::STATUS_ARCHIVED) {
            $this->flash('Restore can only be performed on an archived backup.', FlashMessages::INFO);
            return $this->redirect('archive/list');
        }

        $user    = $archive->getUsername();
        $dest    = $archive->getMaildirFile();
        $options = $this->container->options();

        // 1) Recreate the mailbox if it's gone (a DELETE'd account).
        $mailbox = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $user]);
        if (!$mailbox) {
            $snap = json_decode((string) $archive->getData(), true);
            $m    = (is_array($snap) && isset($snap['mailbox'])) ? $snap['mailbox'] : null;
            if (!$m) {
                $this->flash(sprintf('Cannot restore %s: no mailbox snapshot stored with the archive.', $user), FlashMessages::ERROR);
                return $this->redirect('archive/list');
            }

            $mailbox = new \Entities\Mailbox();
            $mailbox->setUsername($m['username'])
                    ->setLocalPart($m['local_part'])
                    ->setName($m['name'])
                    ->setPassword($m['password'])   // original hash — password preserved
                    ->setQuota($m['quota'])
                    ->setActive($m['active'])
                    ->setDomain($archive->getDomain())
                    ->setCreated(new \DateTime());
            $archive->getDomain()->increaseMailboxCount();
            $em->persist($mailbox);
            $em->flush();   // userdb must see the account before doveadm sync
        }

        // 2) Sync the backup back into the live store.
        try {
            if ($dest) {
                \ViMbAdmin_Doveadm::fromOptions($options)->restoreFrom($user, $dest);
            }
        } catch (\Throwable $e) {
            error_log("ArchiveController::restoreAction sync {$user}: " . $e->getMessage());
            $this->flash(sprintf('Mailbox %s was recreated, but restoring its mail failed: %s', $user, $e->getMessage()), FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        // 3) Remove the backup files (a leftover backup dir is non-fatal).
        try {
            if ($dest) {
                \ViMbAdmin_Doveadm::fromOptions($options)->fsDelete($dest);
            }
        } catch (\Throwable $e) {
            error_log("ArchiveController::restoreAction fsDelete {$user}: " . $e->getMessage());
        }

        $em->remove($archive);
        $em->flush();

        // 4) Queue a background REPAIR (force-resync + index + quota recalc) so the
        //    restored account is fully consistent. Non-blocking.
        $repairQueued = false;
        if ($mailbox) {
            try {
                if (\ViMbAdmin_MailboxQueue::enqueue($em, $mailbox, \Entities\MailboxTask::TYPE_REPAIR, $admin)) {
                    $em->flush();
                    $repairQueued = true;
                }
            } catch (\Throwable $e) {
                error_log("ArchiveController::restoreAction enqueue repair {$user}: " . $e->getMessage());
            }
        }

        (new \ViMbAdmin_Service_Archive($em))->logRestore($admin, $user, $repairQueued);

        $this->flash(sprintf(
            'Archive for %s restored into the live mailbox.%s',
            $user,
            $repairQueued ? ' A repair/optimize was queued and will run in the background.' : ''
        ));
        return $this->redirect('archive/list');
    }
}
