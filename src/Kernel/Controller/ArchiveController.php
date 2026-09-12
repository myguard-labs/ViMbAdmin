<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Admin;
use LogicException;
use Repositories\Archive as ArchiveRepository;
use Repositories\Domain as DomainRepository;
use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

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
    private static function positiveIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return is_int($integer) ? $integer : null;
        }

        return null;
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function requestArray(array $value): array
    {
        $scalarKeys = ['draw', 'start', 'length'];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $scalarKeys, true) && !is_string($item)) {
                throw new LogicException("DataTables parameter {$key} must be a string");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private static function stringKeyedArray(mixed $value, string $name): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::stringKeyedArray($value, $name);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{bool,mixed}
     */
    private static function option(array $options, string ...$path): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::option($options, ...$path);
    }

    /** @param array<string,mixed> $options */
    private static function optionBoolean(array $options, bool $default, string ...$path): bool
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        throw new LogicException('Configuration ' . implode('.', $path) . ' must be boolean');
    }

    private static function nonNegativeInteger(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new LogicException("{$name} must be a non-negative integer");
    }

    /** @return array{username:string,local_part:string,name:?string,password:string,quota:int,active:bool} */
    private static function mailboxSnapshot(mixed $value): array
    {
        $snapshot = self::stringKeyedArray($value, 'Archive mailbox snapshot');
        foreach (['username', 'local_part', 'name', 'password', 'quota', 'active'] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new LogicException("Archive mailbox snapshot missing {$key}");
            }
        }
        if (!is_string($snapshot['username']) || $snapshot['username'] === '') {
            throw new LogicException('Archive mailbox snapshot username must be a non-empty string');
        }
        if (!is_string($snapshot['local_part']) || $snapshot['local_part'] === '') {
            throw new LogicException('Archive mailbox snapshot local_part must be a non-empty string');
        }
        if ($snapshot['name'] !== null && !is_string($snapshot['name'])) {
            throw new LogicException('Archive mailbox snapshot name must be a string or null');
        }
        if (!is_string($snapshot['password']) || $snapshot['password'] === '') {
            throw new LogicException('Archive mailbox snapshot password must be a non-empty string');
        }
        if (!is_bool($snapshot['active'])) {
            throw new LogicException('Archive mailbox snapshot active must be boolean');
        }

        return [
            'username' => $snapshot['username'],
            'local_part' => $snapshot['local_part'],
            'name' => $snapshot['name'],
            'password' => $snapshot['password'],
            'quota' => self::nonNegativeInteger($snapshot['quota'], 'Archive mailbox snapshot quota'),
            'active' => $snapshot['active'],
        ];
    }

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

        $session = new MagicPropertyStorage($this->session());
        $domain  = null;
        $unset   = $this->param('unset', false);

        if ($unset) {
            $session->remove('domain');
        }

        if (!$unset) {
            $storedDomain = $session->get('domain');
            if ($storedDomain instanceof \Entities\Domain) {
                $domain = $storedDomain;
            }

            if (!($storedDomain instanceof \Entities\Domain)) {
                $did = self::positiveIntegerOrNull($this->param('did'));
                if ($did !== null) {
                    $domain = $this->domainRepository()->find($did);
                    if ($domain && !$admin->isSuper() && !$admin->canManageDomain($domain)) {
                        return $this->redirect('auth/login');
                    }
                    if ($domain) {
                        $session->set('domain', $domain);
                    }
                }
            }
        }

        $paginate = self::optionBoolean($this->container->options(), true, 'defaults', 'server_side', 'pagination', 'archive', 'enable');
        $archives = !$paginate
            ? $this->archiveRepository()->loadForArchiveList($admin, $domain)
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

        $session = new MagicPropertyStorage($this->session());
        $storedDomain = $session->get('domain');
        $domain = $storedDomain instanceof \Entities\Domain ? $storedDomain : null;

        $minimum = $this->dataTableMinimumSearchLength('archive');
        try {
            $request = self::requestArray($_GET);
        } catch (LogicException $e) {
            foreach (['draw', 'start', 'length'] as $key) {
                if (array_key_exists($key, $_GET) && is_array($_GET[$key])) {
                    return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
                }
            }
            throw $e;
        }
        try {
            $q = DataTableQuery::fromArray(
                $request,
                $minimum,
            );
        } catch (\LengthException $e) {
            return new Response($e->getMessage(), 400, 'text/plain; charset=utf-8');
        } catch (\TypeError) {
            return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
        }
        // Column index -> sortable field (matches JS column order; size / user-exists
        // / autoprune / controls fall back to archived date).
        $sortField = [0 => 'username', 1 => 'status', 2 => 'domain', 4 => 'archived_at'][$q->sortColumn] ?? 'archived_at';

        $r = $this->archiveRepository()
            ->pagedForArchiveList($admin, $domain, $q->searchTerm, $q->contains, $sortField, $q->sortDir, $q->start, $q->length);

        foreach ($r['rows'] as &$row) {
            if (($row['archived_at'] ?? null) instanceof \DateTimeInterface) {
                $row['archived_at'] = $row['archived_at']->format('Y-m-d H:i');
            }
        }
        unset($row);

        return new Response(
            DataTableResult::json($q, $r['total'], $r['filtered'], array_values($r['rows'])),
            200,
            'application/json; charset=utf-8'
        );
    }

    /**
     * POST /archive/toggle-autoprune — flip autoprune.
     *
     * Faithful port of the ZF1 `toggleAutopruneAction`, hardened to a POST: the
     * CSRF token must arrive in the POST body (the archive-list form mints it as
     * a hidden field) — an invalid/missing token, or a non-POST, flashes +
     * bounces to the list. The archive is resolved
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

        // _assertCsrf(): the token must travel in the POST body, never the URL.
        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $archive = $this->archiveFromParameter('arid');

        // loadArchive() authorises a non-super admin against the archive's domain.
        if (!$archive) {
            return $this->redirect('archive/list');
        }
        if (!$admin->isSuper() && !$admin->canManageDomain($archive->requiredDomain())) {
            return $this->redirect('archive/list');
        }

        $username = $archive->requiredUsername();
        $enabled = (new \ViMbAdmin_Service_Archive($this->em()))->toggleAutoprune($archive, $admin);

        $this->flash($enabled
            ? sprintf('Autoprune enabled for %s; the prune window restarts from now.', $username)
            : sprintf('Autoprune disabled for %s.', $username));

        return $this->redirect('archive/list');
    }

    /**
     * POST /archive/delete — delete a backup permanently.
     *
     * Faithful port of the ZF1 `deleteAction`, hardened to a POST whose body
     * carries the CSRF token: resolve + authorise the
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

        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $archive = $this->archiveFromParameter('arid');

        if (!$archive) {
            return $this->redirect('archive/list');
        }
        if (!$admin->isSuper() && !$admin->canManageDomain($archive->requiredDomain())) {
            return $this->redirect('archive/list');
        }

        $user = $archive->requiredUsername();
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
     * POST /archive/restore — restore a backup into the
     * live mailbox.
     *
     * Faithful port of the ZF1 `restoreAction`, hardened to a POST whose body
     * carries the CSRF token; only an ARCHIVED
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

        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('archive/list');
        }

        $em      = $this->em();
        $archive = $this->archiveFromParameter('arid');

        if (!$archive) {
            return $this->redirect('archive/list');
        }
        if (!$admin->isSuper() && !$admin->canManageDomain($archive->requiredDomain())) {
            return $this->redirect('archive/list');
        }

        if ($archive->getStatus() !== \Entities\Archive::STATUS_ARCHIVED) {
            $this->flash('Restore can only be performed on an archived backup.', FlashMessages::INFO);
            return $this->redirect('archive/list');
        }

        $user    = $archive->requiredUsername();
        $dest    = $archive->getMaildirFile();
        $options = $this->container->options();

        // 1) Recreate the mailbox if it's gone (a DELETE'd account).
        $mailbox = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $user]);
        if (!$mailbox) {
            $domain = $archive->requiredDomain();
            $data = $archive->getData();
            $snap = is_string($data) ? json_decode($data, true) : null;
            try {
                $m = is_array($snap) && array_key_exists('mailbox', $snap)
                    ? self::mailboxSnapshot($snap['mailbox']) : null;
            } catch (\LogicException $e) {
                $m = null;
            }
            if ($m === null) {
                $this->flash(sprintf('Cannot restore %s: no mailbox snapshot stored with the archive.', $user), FlashMessages::ERROR);
                return $this->redirect('archive/list');
            }
            $expectedSnapshotUsername = $m['local_part'] . '@' . $domain->requiredDomainName();
            if ($m['username'] !== $user || $m['username'] !== $expectedSnapshotUsername) {
                $this->flash(sprintf('Cannot restore %s: mailbox snapshot identity does not match the archive.', $user), FlashMessages::ERROR);
                return $this->redirect('archive/list');
            }

            $mailbox = new \Entities\Mailbox();
            $mailbox->setUsername($m['username'])->setLocalPart($m['local_part']);
            if ($m['name'] !== null) {
                $mailbox->setName($m['name']);
            }
            $mailbox->setPassword($m['password'])   // original hash — password preserved
                    ->setQuota($m['quota'])
                    ->setActive($m['active'])
                    ->setDomain($domain)
                    ->setCreated(new \DateTime());
            $domain->increaseMailboxCount();
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
        try {
            if (\ViMbAdmin_MailboxQueue::enqueue($em, $mailbox, \Entities\MailboxTask::TYPE_REPAIR, $admin)) {
                $em->flush();
                $repairQueued = true;
            }
        } catch (\Throwable $e) {
            error_log("ArchiveController::restoreAction enqueue repair {$user}: " . $e->getMessage());
        }

        (new \ViMbAdmin_Service_Archive($em))->logRestore($admin, $user, $repairQueued);

        $this->flash(sprintf(
            'Archive for %s restored into the live mailbox.%s',
            $user,
            $repairQueued ? ' A repair/optimize was queued and will run in the background.' : ''
        ));
        return $this->redirect('archive/list');
    }

    protected function em(): EntityManagerInterface
    {
        $em = parent::em();
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('Doctrine entity manager resource has an invalid type');
        }

        return $em;
    }

    protected function admin(): ?Admin
    {
        $admin = parent::admin();
        if ($admin !== null && !$admin instanceof Admin) {
            throw new LogicException('Authenticated admin has an invalid type');
        }

        return $admin;
    }

    private function archiveRepository(): ArchiveRepository
    {
        $repo = $this->em()->getRepository('\\Entities\\Archive');
        if (!$repo instanceof ArchiveRepository) {
            throw new LogicException('Archive repository has an invalid type');
        }

        return $repo;
    }

    private function archiveFromParameter(string $parameter): ?\Entities\Archive
    {
        $id = self::positiveIntegerOrNull($this->param($parameter));
        if ($id === null) {
            return null;
        }

        $archive = $this->archiveRepository()->find($id);
        return $archive instanceof \Entities\Archive ? $archive : null;
    }

    private function domainRepository(): DomainRepository
    {
        $repo = $this->em()->getRepository('\\Entities\\Domain');
        if (!$repo instanceof DomainRepository) {
            throw new LogicException('Domain repository has an invalid type');
        }

        return $repo;
    }
}
