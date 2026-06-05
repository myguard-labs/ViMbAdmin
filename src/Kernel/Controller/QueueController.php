<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of `QueueController::index` (docs/ZF1-REMOVAL.md) — the super-admin
 * mailbox-task queue overview.
 *
 * Reproduces the legacy `indexAction`: the per-status counts, the most recent 200
 * tasks (newest first) and the status value that marks a task cancellable (from
 * the framework-free `Entities\MailboxTask` constants), rendered into
 * `queue/index.phtml`. The legacy `preDispatch` super gate is reproduced inline.
 *
 * Only `indexAction` (the read-only overview) is migrated; the unauthenticated
 * `trigger` / `cli-run` actions and the action handlers stay on ZF1 via the
 * dispatcher fallback.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class QueueController extends AbstractController
{
    /**
     * GET /queue — the mailbox-task queue overview (super admins only).
     */
    public function indexAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $repo = $this->em()->getRepository('\\Entities\\MailboxTask');

        $tasks = $this->em()->createQueryBuilder()
            ->select('t')
            ->from('\\Entities\\MailboxTask', 't')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()->getResult();

        return $this->view('queue/index.phtml', [
            'counts'      => $repo->statusCounts(),
            'tasks'       => $tasks,
            'cancellable' => \Entities\MailboxTask::STATUS_PENDING,
        ]);
    }
}
