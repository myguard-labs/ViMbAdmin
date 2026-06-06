<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native port of `QueueController::index` + the per-task actions
 * (cancel/retry/delete) — the super-admin mailbox-task queue (docs/ZF1-REMOVAL.md).
 *
 * `indexAction` reproduces the legacy overview: the per-status counts, the most
 * recent 200 tasks (newest first) and the status value that marks a task
 * cancellable (from the framework-free `Entities\MailboxTask` constants), rendered
 * into `queue/index.phtml`. `cancel`/`retry`/`delete` reproduce the POST+CSRF task
 * actions (pure DB status changes / removal — no plugin hooks, no Log rows). The
 * legacy `preDispatch` super gate is reproduced inline on every action.
 *
 * The runner actions (`run-now`/`run-task`) and the unauthenticated `trigger` /
 * `cli-run` actions stay on ZF1 — they invoke the task runner / CLI, which the
 * native kernel does not yet wrap.
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

    /**
     * POST /queue/cancel — cancel a PENDING task (super admins only).
     *
     * Faithful port of the ZF1 `cancelAction`: super-gated, POST-only (a GET
     * bounces to the overview), CSRF-checked against the form's hidden `csrf`
     * field. A PENDING task is moved to CANCELLED with `finishedAt` stamped and a
     * log line appended; anything else flashes "not cancellable". Redirects to the
     * queue overview.
     */
    public function cancelAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $task = $this->taskFromPost();

        if ($task && $task->getStatus() === \Entities\MailboxTask::STATUS_PENDING) {
            $task->setStatus(\Entities\MailboxTask::STATUS_CANCELLED)
                 ->setFinishedAt(new \DateTime())
                 ->appendLog('cancelled by ' . $admin->getFormattedName());
            $this->em()->flush();
            $this->flash('Task cancelled.');
        } else {
            $this->flash('Task not found or not cancellable.', FlashMessages::ERROR);
        }

        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/retry — re-queue a FAILED task (super admins only).
     *
     * Faithful port of the ZF1 `retryAction`: a FAILED task is moved back to
     * PENDING with `finishedAt` cleared and a log line appended; anything else
     * flashes "not in a failed state".
     */
    public function retryAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $task = $this->taskFromPost();

        if ($task && $task->getStatus() === \Entities\MailboxTask::STATUS_FAILED) {
            $task->setStatus(\Entities\MailboxTask::STATUS_PENDING)
                 ->setFinishedAt(null)
                 ->appendLog('retry queued by ' . $admin->getFormattedName());
            $this->em()->flush();
            $this->flash('Task re-queued.');
        } else {
            $this->flash('Task not found or not in a failed state.', FlashMessages::ERROR);
        }

        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/delete — delete a task that is not currently RUNNING (super only).
     *
     * Faithful port of the ZF1 `deleteAction`: any task not in the RUNNING state is
     * removed; a running task (or a missing one) flashes the refusal.
     */
    public function deleteAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $task = $this->taskFromPost();

        if ($task && $task->getStatus() !== \Entities\MailboxTask::STATUS_RUNNING) {
            $this->em()->remove($task);
            $this->em()->flush();
            $this->flash('Task deleted.');
        } else {
            $this->flash('Task not found, or it is currently running.', FlashMessages::ERROR);
        }

        return $this->redirect('queue/index');
    }

    /**
     * Shared guard for the POST task actions: require a super admin, a POST method
     * and a valid CSRF token (carried in the form's hidden `csrf` field, so it is
     * read from the POST body — not the URL like the GET-link actions). Returns the
     * admin on success, or the {@see Response} to return on any failure.
     */
    private function guardSuperPost(): object
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->isPost()) {
            return $this->redirect('queue/index');
        }

        $csrf = new Csrf(new MagicPropertyStorage($this->container->session()));
        if (!$csrf->isValid((string) ($this->postData()['csrf'] ?? ''))) {
            $this->flash('Invalid or missing security token. Please retry from the queue page.', FlashMessages::ERROR);
            return $this->redirect('queue/index');
        }

        return $admin;
    }

    /**
     * Resolve the MailboxTask from the POST `id` field, or null when absent/unknown.
     */
    private function taskFromPost(): ?object
    {
        $id = (int) ($this->postData()['id'] ?? 0);

        return $id > 0
            ? $this->em()->getRepository('\\Entities\\MailboxTask')->find($id)
            : null;
    }
}
