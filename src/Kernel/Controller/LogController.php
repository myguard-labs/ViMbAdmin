<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Doctrine\ORM\EntityManager;
use Entities\Admin;
use Entities\Domain;
use LogicException;
use Repositories\Admin as AdminRepository;
use Repositories\Domain as DomainRepository;
use Repositories\Log as LogRepository;
use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

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
        $scope = $this->resolveScope();
        if ($scope instanceof Response) {
            return $scope;
        }
        [$targetAdmin, $domain] = $scope;

        // When server-side pagination is on the table is filled by /log/list-data;
        // ship the page without inlining every (unbounded) log row.
        $options = $this->container->options();
        $defaults = array_key_exists('defaults', $options) ? self::stringMap($options['defaults'], 'defaults') : [];
        $serverSide = array_key_exists('server_side', $defaults)
            ? self::stringMap($defaults['server_side'], 'defaults.server_side') : [];
        $pagination = array_key_exists('pagination', $serverSide)
            ? self::stringMap($serverSide['pagination'], 'defaults.server_side.pagination') : [];
        $cfg = array_key_exists('log', $pagination)
            ? self::stringMap($pagination['log'], 'defaults.server_side.pagination.log') : [];
        $enabled = array_key_exists('enable', $cfg) ? self::booleanValue($cfg['enable'], 'log pagination enable') : true;
        $logs    = !$enabled
            ? $this->logRepository()->loadForLogList($targetAdmin, $domain)
            : [];

        return $this->view('log/list.phtml', ['logs' => $logs]);
    }

    /**
     * GET /log/list-data — DataTables server-side processing source for the log.
     *
     * Same scope as {@see listAction} (target admin + remembered domain) but one
     * page only, so the unbounded log table never ships whole. Active when
     * server-side pagination is enabled for the log.
     */
    public function listDataAction(): Response
    {
        $scope = $this->resolveScope();
        if ($scope instanceof Response) {
            return new Response('ko');
        }
        [$targetAdmin, $domain] = $scope;

        $minimum = $this->dataTableMinimumSearchLength('log');
        try {
            $q = DataTableQuery::fromArray(
                self::stringMap($_GET, 'GET data'),
                $minimum,
            );
        } catch (\LengthException $e) {
            return new Response($e->getMessage(), 400, 'text/plain; charset=utf-8');
        } catch (\TypeError) {
            return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
        }
        $sortField = self::listSortField($q->sortColumn, $domain !== null);

        $r = $this->logRepository()
            ->pagedForLogList($targetAdmin, $domain, $q->searchTerm, $q->contains, $sortField, $q->sortDir, $q->start, $q->length);

        // Array-hydrated datetime columns come back as DateTime objects; format
        // to the same string the inline template used before JSON-encoding.
        foreach ($r['rows'] as &$row) {
            if (($row['timestamp'] ?? null) instanceof \DateTimeInterface) {
                $row['timestamp'] = $row['timestamp']->format('Y-m-d H:i:s');
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
     * Resolve the log scope shared by {@see listAction} and {@see listDataAction}:
     * authenticate, pick the target admin (a non-super sees only their own
     * actions; super sees all, or one admin's via `aid`) and the session-remembered
     * domain filter (`did` sets it, `unset` clears it). Returns `[targetAdmin,
     * domain]` or a redirect {@see Response} when authentication / authorisation
     * fails — preserving the ZF1 `loadAdmin()` / `loadDomain()` side effects.
     *
     * @return array{0: \Entities\Admin|null, 1: \Entities\Domain|null}|Response
     */
    private function resolveScope(): array|Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $targetAdmin = null;
        $aid = $this->param('aid');
        if ($aid !== null && $aid !== '') {
            $targetAdmin = $this->adminRepository()->find(self::positiveId($aid, 'aid'));
            if (!$targetAdmin) {
                return $this->redirect('admin/list');
            }
            if ($targetAdmin->getId() != $admin->getId() && !$admin->isSuper()) {
                return $this->redirect('auth/login');
            }
        }
        if (!$targetAdmin && !$admin->isSuper()) {
            $targetAdmin = $admin;
        }

        $session = new MagicPropertyStorage($this->session());
        $domain  = null;

        if ($this->param('unset', false)) {
            $session->remove('domain');
        } elseif ($session->has('domain')) {
            $storedDomain = $session->get('domain');
            if ($storedDomain !== null && !$storedDomain instanceof Domain) {
                throw new LogicException('Stored domain has an invalid type');
            }
            $domain = $storedDomain;
        } elseif (($did = $this->param('did')) !== null && $did !== '') {
            $domain = $this->domainRepository()->find(self::positiveId($did, 'did'));
            if ($domain && !$admin->isSuper() && !$admin->canManageDomain($domain)) {
                return $this->redirect('auth/login');
            }
            if ($domain) {
                $session->set('domain', $domain);
            }
        }

        return [$targetAdmin, $domain];
    }

    protected function em(): EntityManager
    {
        $em = parent::em();
        if (!$em instanceof EntityManager) {
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

    private function logRepository(): LogRepository
    {
        $repository = $this->em()->getRepository('\\Entities\\Log');
        if (!$repository instanceof LogRepository) {
            throw new LogicException('Log repository has an invalid type');
        }
        return $repository;
    }

    private function adminRepository(): AdminRepository
    {
        $repository = $this->em()->getRepository('\\Entities\\Admin');
        if (!$repository instanceof AdminRepository) {
            throw new LogicException('Admin repository has an invalid type');
        }
        return $repository;
    }

    /**
     * Column index -> sortable field for the log list.
     *
     * `log/list.phtml` and its view JS render the Domain column ONLY when no
     * domain filter is remembered, and set the initial order to index 3 in that
     * case -- so a static map that always puts `domain` at index 3 sorts every
     * domain-scoped log page (including its very first load) by domain instead
     * of by the "Occurred At" column the user actually clicked. Build the map
     * from the same condition the view uses. The "Log"/data column is rendered
     * but not usefully sortable and maps to the default.
     *
     * @param int  $index         The DataTables `order[0][column]` index.
     * @param bool $domainScoped  Whether a domain filter is active (Domain column hidden).
     */
    private static function listSortField(int $index, bool $domainScoped): string
    {
        $columns = array_values(array_filter([
            'action',                          // Action
            '',                                // Log / data (not sortable)
            'admin',                           // Admin
            $domainScoped ? null : 'domain',   // Domain (hidden while domain-scoped)
            'timestamp',                       // Occurred At
        ], static fn(?string $c): bool => $c !== null));

        return ($columns[$index] ?? '') ?: 'timestamp';
    }

    private function domainRepository(): DomainRepository
    {
        $repository = $this->em()->getRepository('\\Entities\\Domain');
        if (!$repository instanceof DomainRepository) {
            throw new LogicException('Domain repository has an invalid type');
        }
        return $repository;
    }

    /** @return array<string,mixed> */
    private static function stringMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \TypeError($name . ' must be an array');
        }
        foreach ($value as $key => $_value) {
            if (!is_string($key)) {
                throw new \TypeError($name . ' must use string keys');
            }
        }
        return $value;
    }

    private static function booleanValue(mixed $value, string $name): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) && ($value === 0 || $value === 1)) return $value === 1;
        if (is_string($value) && ($value === '0' || $value === '1')) return $value === '1';
        throw new \TypeError($name . ' must be boolean');
    }

    private static function positiveId(mixed $value, string $name): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false && $id > 0) return $id;
        }
        throw new \TypeError($name . ' must be a positive integer');
    }
}
