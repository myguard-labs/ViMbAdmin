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
 * `run-now`/`run-task` drive the queue through the shared framework-free
 * {@see \ViMbAdmin_Service_QueueRunner} (the same engine the ZF1 cron runner uses).
 * The unauthenticated `trigger` / `cli-run` endpoints (remote-key / cron CLI, not
 * browser UI) stay on ZF1.
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
     * POST /queue/clear — delete all finished (DONE/FAILED/CANCELLED) tasks.
     *
     * Faithful port of the ZF1 `clearAction`: super-gated POST+CSRF, a bulk DQL
     * delete of the terminal-state rows, then flashes how many were cleared.
     */
    public function clearAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $n = (int) $this->em()->createQuery(
            'DELETE FROM \\Entities\\MailboxTask t WHERE t.status IN (:done)')
            ->setParameter('done', [
                \Entities\MailboxTask::STATUS_DONE,
                \Entities\MailboxTask::STATUS_FAILED,
                \Entities\MailboxTask::STATUS_CANCELLED,
            ])
            ->execute();

        $this->flash(sprintf('Cleared %d finished task(s).', $n));
        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/run-now — drain the queue now (super admins only).
     *
     * Faithful port of the ZF1 `runNowAction`: lease-gated batch run of up to
     * `queue.runner.max_per_run` PENDING tasks through the shared framework-free
     * {@see \ViMbAdmin_Service_QueueRunner} (the same engine the ZF1 cron runner
     * uses). A throttled run (every slot busy) flashes an info notice; otherwise it
     * reports how many tasks were processed.
     */
    public function runNowAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $options = $this->container->options();
        $max     = (int) ($options['queue']['runner']['max_per_run'] ?? 5);

        $n = (new \ViMbAdmin_Service_QueueRunner($this->em(), $options))->drain($max);

        if ($n < 0) {
            $this->flash('A queue runner is already active (max_concurrent reached) — it will pick up the work.', FlashMessages::INFO);
        } else {
            $this->flash(
                sprintf('Queue run complete — %d task(s) processed.', $n),
                $n > 0 ? FlashMessages::SUCCESS : FlashMessages::INFO
            );
        }

        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/run-task — run one PENDING task now (super admins only).
     *
     * Faithful port of the ZF1 `runTaskAction`: atomically claim the PENDING task
     * (bail if a background runner grabbed it), execute it through the shared
     * {@see \ViMbAdmin_Service_QueueRunner::runOne}, then record DONE/FAILED +
     * finishedAt. The runner does the doveadm work for the task's type.
     */
    public function runTaskAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $repo = $this->em()->getRepository('\\Entities\\MailboxTask');
        $task = $this->taskFromPost();

        if (!$task || $task->getStatus() !== \Entities\MailboxTask::STATUS_PENDING) {
            $this->flash('Task not found or not pending.', FlashMessages::ERROR);
            return $this->redirect('queue/index');
        }

        // Atomic PENDING -> RUNNING; bail if a background runner won the row.
        if (!$repo->claim($task)) {
            $this->flash('Task is already being processed.', FlashMessages::INFO);
            return $this->redirect('queue/index');
        }

        try {
            (new \ViMbAdmin_Service_QueueRunner($this->em(), $this->container->options()))->runOne($task);
            $task->setStatus(\Entities\MailboxTask::STATUS_DONE);
            $task->appendLog('done (run-now by ' . $admin->getFormattedName() . ')');
            $this->flash(sprintf('Task #%d completed.', $task->getId()));
        } catch (\Throwable $e) {
            $task->setStatus(\Entities\MailboxTask::STATUS_FAILED);
            $task->appendLog('FAILED: ' . $e->getMessage());
            $this->flash(sprintf('Task #%d failed: %s', $task->getId(), $e->getMessage()), FlashMessages::ERROR);
        }

        $task->setFinishedAt(new \DateTime());
        $this->em()->flush();

        return $this->redirect('queue/index');
    }

    /**
     * GET|POST /queue/trigger — the unauthenticated remote-cron endpoint that
     * kicks the queue runner. Native port of the ZF1 `triggerAction`: NOT
     * session-authenticated — it is gated by a Bearer key (compared by SHA-256,
     * constant-time) and a source-IP allowlist, then spawns a background runner
     * (non-blocking, via {@see \ViMbAdmin_QueueRunner::triggerCheck}) and returns
     * JSON immediately. With no `queue.runner.key` configured the endpoint is
     * disabled (404).
     */
    public function triggerAction(): Response
    {
        $options = $this->container->options();

        $key = (string) ($options['queue']['runner']['key'] ?? '');
        if ($key === '') {
            return $this->json(['error' => 'queue trigger disabled'], 404);
        }

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return $this->json(['error' => 'missing bearer'], 401);
        }
        if (!hash_equals(hash('sha256', $key), hash('sha256', trim($m[1])))) {
            return $this->json(['error' => 'bad key'], 403);
        }

        // Proxy-aware client IP + the CIDR allowlist (same resolver/check as ZF1).
        $proxy = $options['trustedproxy'] ?? [];
        $ip    = \ViMbAdmin_Net::clientIp(
            $_SERVER,
            $proxy['mode'] ?? 'auto',
            isset($proxy['proxies']) ? (array) $proxy['proxies'] : []
        );

        if (!\ViMbAdmin_Net::ipInList($ip, (string) ($options['queue']['runner']['allowed_ips'] ?? ''))) {
            return $this->json(['error' => "source IP {$ip} not allowed"], 403);
        }

        $spawned = \ViMbAdmin_QueueRunner::triggerCheck($this->em(), $options);
        return $this->json(['triggered' => $spawned], 200);
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
