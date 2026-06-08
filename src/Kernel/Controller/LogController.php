<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
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
        $scope = $this->resolveScope();
        if ($scope instanceof Response) {
            return $scope;
        }
        [$targetAdmin, $domain] = $scope;

        // When server-side pagination is on the table is filled by /log/list-data;
        // ship the page without inlining every (unbounded) log row.
        $cfg     = $this->container->options()['defaults']['server_side']['pagination']['log'] ?? [];
        $logs    = empty($cfg['enable'])
            ? $this->em()->getRepository('\\Entities\\Log')->loadForLogList($targetAdmin, $domain)
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

        $q = DataTableQuery::fromArray($_GET);
        // Column index -> sortable field (matches the JS column order; "Log"/data
        // column is not usefully sortable -> falls back to timestamp).
        $sortField = [0 => 'action', 2 => 'admin', 3 => 'domain', 4 => 'timestamp'][$q->sortColumn] ?? 'timestamp';

        $r = $this->em()->getRepository('\\Entities\\Log')
            ->pagedForLogList($targetAdmin, $domain, $q->search, $sortField, $q->sortDir, $q->start, $q->length);

        // Array-hydrated datetime columns come back as DateTime objects; format
        // to the same string the inline template used before JSON-encoding.
        foreach ($r['rows'] as &$row) {
            if (($row['timestamp'] ?? null) instanceof \DateTimeInterface) {
                $row['timestamp'] = $row['timestamp']->format('Y-m-d H:i:s');
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
     * Resolve the log scope shared by {@see listAction} and {@see listDataAction}:
     * authenticate, pick the target admin (a non-super sees only their own
     * actions; super sees all, or one admin's via `aid`) and the session-remembered
     * domain filter (`did` sets it, `unset` clears it). Returns `[targetAdmin,
     * domain]` or a redirect {@see Response} when authentication / authorisation
     * fails — preserving the ZF1 `loadAdmin()` / `loadDomain()` side effects.
     *
     * @return array{0: \Entities\Admin|false, 1: \Entities\Domain|null}|Response
     */
    private function resolveScope(): array|Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $targetAdmin = false;
        if ($aid = $this->param('aid')) {
            $targetAdmin = $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid);
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

        return [$targetAdmin, $domain];
    }
}
