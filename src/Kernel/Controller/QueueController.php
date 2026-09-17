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
 * {@see \ViMbAdmin_Service_QueueRunner} (the same engine the cron runner uses).
 * `trigger` is the unauthenticated remote endpoint (bearer key + IP allowlist)
 * that drains the queue SYNCHRONOUSLY in-request. The other path is the
 * out-of-band `queue.cli-run` CLI (container cron / s6 service). ViMbAdmin
 * never forks a background runner from a web request.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class QueueController extends AbstractController
{
    private static function requiredString(mixed $value, string $name): string
    {
        return \ViMbAdmin\Kernel\Input\Reader::requiredString($value, $name);
    }

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
    private static function optionString(array $options, string $default, string ...$path): string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /** @param array<string,mixed> $options */
    private static function optionInt(array $options, int $default, string ...$path): int
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new \LogicException('Configuration ' . implode('.', $path) . ' must be a non-negative integer');
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function optionArray(array $options, string ...$path): array
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::stringKeyedArray($value, 'Configuration ' . implode('.', $path)) : [];
    }

    /** @return array<int|string,mixed> */
    private static function proxyList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            throw new \LogicException('Configuration trustedproxy.proxies must be a list');
        }
        foreach ($value as $proxy) {
            if (!is_string($proxy)) {
                throw new \LogicException('Configuration trustedproxy.proxies must contain strings');
            }
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function serverParams(): array
    {
        return self::stringKeyedArray($_SERVER, 'Server parameters');
    }

    private static function serverString(string $key): string
    {
        $value = self::serverParams()[$key] ?? '';
        return $value === '' ? '' : self::requiredString($value, "Server parameter {$key}");
    }

    private static function postStringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * GET /queue — the mailbox-task queue overview (super admins only).
     */
    public function indexAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $repo = $this->mailboxTaskRepository();

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

        if ($task && $this->mailboxTaskRepository()->cancelIfPending($task, $admin->getFormattedName())) {
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

        if ($task && $task->getStatus() === \Entities\MailboxTask::STATUS_FAILED && !$task->isAbandoned()) {
            $task->setStatus(\Entities\MailboxTask::STATUS_PENDING)
                 ->setFinishedAt(null)
                 ->appendLog('retry queued by ' . $admin->getFormattedName());
            $this->em()->flush();
            $this->flash('Task re-queued.');
        } else {
            $this->flash('Task not found or not safely retryable.', FlashMessages::ERROR);
        }

        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/delete — delete a task unless it is actively RUNNING (super only).
     *
     * Terminal and pending tasks are removable. RUNNING tasks are removable only
     * after their owning runner lease is gone. The repository performs the owner
     * check and delete atomically so active work is never deleted after a stale
     * application-side read.
     */
    public function deleteAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $task = $this->taskFromPost();

        if ($task && $this->mailboxTaskRepository()->deleteUnlessActive($task)) {
            $this->flash('Task deleted.');
        } else {
            $this->flash('Task not found, or it is still actively running.', FlashMessages::ERROR);
        }

        return $this->redirect('queue/index');
    }

    /**
     * POST /queue/clear — delete all safely finished tasks.
     *
     * Faithful port of the ZF1 `clearAction`: super-gated POST+CSRF, a bulk DQL
     * delete of terminal-state rows, then flashes how many were cleared. Failed
     * tasks reaped from an abandoned runner retain their deduplication fence
     * until an operator explicitly deletes that individual task.
     */
    public function clearAction(): Response
    {
        $admin = $this->guardSuperPost();
        if ($admin instanceof Response) {
            return $admin;
        }

        $n = $this->em()->createQuery(
            'DELETE FROM \\Entities\\MailboxTask t WHERE t.status IN (:done) AND t.abandoned = false'
        )
            ->setParameter('done', [
                \Entities\MailboxTask::STATUS_DONE,
                \Entities\MailboxTask::STATUS_FAILED,
                \Entities\MailboxTask::STATUS_CANCELLED,
            ])
            ->execute();
        if (!is_int($n)) {
            throw new \LogicException('Queue clear returned an invalid affected-row count');
        }

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
        $max = self::optionInt($options, 5, 'queue', 'runner', 'max_per_run');
        if ($max < 1) {
            throw new \LogicException('Configuration queue.runner.max_per_run must be greater than zero');
        }

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

        $task = $this->taskFromPost();

        if (!$task || $task->getStatus() !== \Entities\MailboxTask::STATUS_PENDING) {
            $this->flash('Task not found or not pending.', FlashMessages::ERROR);
            return $this->redirect('queue/index');
        }

        $runner = new \ViMbAdmin_Service_QueueRunner($this->em(), $this->container->options());
        $result = $runner->runOne($task, function (?\Throwable $error) use ($task, $admin): void {
            if ($error === null) {
                $task->setStatus(\Entities\MailboxTask::STATUS_DONE);
                $task->setRunner(null);
                $task->appendLog('done (run-now by ' . $admin->getFormattedName() . ')');
                $this->flash(sprintf('Task #%d completed.', $task->getId()));
            } else {
                $task->setStatus(\Entities\MailboxTask::STATUS_FAILED);
                $task->setRunner(null);
                $task->appendLog('FAILED: ' . $error->getMessage());
                $this->flash(sprintf('Task #%d failed: %s', $task->getId(), $error->getMessage()), FlashMessages::ERROR);
            }

            $task->setFinishedAt(new \DateTime());
        });

        if ($result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_BUSY) {
            $this->flash('A queue runner is already active (max_concurrent reached) — it will pick up the task.', FlashMessages::INFO);
        } elseif ($result === \ViMbAdmin_Service_QueueRunner::RUN_ONE_NOT_CLAIMED) {
            $this->flash('Task is already being processed.', FlashMessages::INFO);
        }

        return $this->redirect('queue/index');
    }

    /**
     * GET|POST /queue/trigger — the unauthenticated remote-cron endpoint that
     * kicks the queue. NOT session-authenticated: gated by a Bearer key
     * (compared by SHA-256, constant-time) plus a source-IP allowlist.
     *
     * It is a pure TRIGGER: on a valid request it returns `{"triggered":true}`
     * immediately, then — once the response is flushed and the caller has
     * disconnected (`fastcgi_finish_request()`, run from public/index.php) —
     * drains the queue autonomously in the same FPM worker. No blocking of the
     * caller, no forked process, no shell-out. The lease cap
     * (`queue.runner.max_concurrent`) still serialises concurrent triggers, so
     * a flood of triggers cannot pile up runners. With no `queue.runner.key`
     * configured the endpoint is disabled (404).
     */
    public function triggerAction(): Response
    {
        $options = $this->container->options();

        $key = self::optionString($options, '', 'queue', 'runner', 'key');
        if ($key === '') {
            return $this->json(['error' => 'queue trigger disabled'], 404);
        }

        $auth = self::serverString('HTTP_AUTHORIZATION');
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return $this->json(['error' => 'missing bearer'], 401);
        }
        if (!hash_equals(hash('sha256', $key), hash('sha256', trim($m[1])))) {
            return $this->json(['error' => 'bad key'], 403);
        }

        // Proxy-aware client IP + the CIDR allowlist.
        $proxy = self::optionArray($options, 'trustedproxy');
        [$proxyListFound, $proxyList] = self::option($proxy, 'proxies');
        $ip    = \ViMbAdmin_Net::clientIp(
            self::serverParams(),
            self::optionString($proxy, 'auto', 'mode'),
            self::proxyList($proxyListFound ? $proxyList : null)
        );

        if (!\ViMbAdmin_Net::ipInList($ip, self::optionString($options, '', 'queue', 'runner', 'allowed_ips'))) {
            return $this->json(['error' => "source IP {$ip} not allowed"], 403);
        }

        // Accept now; drain after the caller disconnects (see Response::afterSend
        // + public/index.php). Drain in batches until the backlog is clear (or a
        // batch is lease-throttled), so one trigger autonomously empties the
        // queue. The EntityManager + options are captured for the detached run.
        $em      = $this->em();
        $max = self::optionInt($options, 5, 'queue', 'runner', 'max_per_run');
        if ($max < 1) {
            throw new \LogicException('Configuration queue.runner.max_per_run must be greater than zero');
        }
        $afterSend = static function () use ($em, $options, $max): void {
            $runner = new \ViMbAdmin_Service_QueueRunner($em, $options);
            $deadline = microtime(true) + 60.0;
            $batches = 0;
            // Bound detached work so an HTTP worker cannot be retained by an
            // unbounded producer. Drop the identity map and validate the DB
            // connection between batches after potentially long doveadm calls.
            do {
                // Validate an existing connection. DBAL retains a dead driver
                // after a server-side idle close, so discard it once and let
                // the retry establish a fresh connection.
                $connection = $em->getConnection();
                try {
                    $connection->fetchOne('SELECT 1');
                } catch (\Throwable $e) {
                    $connection->close();
                    $connection->fetchOne('SELECT 1');
                }
                $n = $runner->drain($max);
                $em->clear();
                $batches++;
            } while ($n > 0 && $batches < 100 && microtime(true) < $deadline);
        };

        return new Response(
            (string) json_encode(['triggered' => true]),
            200,
            'application/json; charset=utf-8',
            [],
            $afterSend,
        );
    }

    /**
     * Shared guard for the POST task actions: require a super admin, a POST method
     * and a valid CSRF token (carried in the form's hidden `csrf` field, so it is
     * read from the POST body — not the URL like the GET-link actions). Returns the
     * admin on success, or the {@see Response} to return on any failure.
     */
    private function guardSuperPost(): \Entities\Admin|Response
    {
        $admin = $this->admin();
        if (!$admin instanceof \Entities\Admin || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->isPost()) {
            return $this->redirect('queue/index');
        }

        $csrf = new Csrf(new MagicPropertyStorage($this->container->session()));
        if (!$csrf->isValid(self::postStringOrEmpty($this->postData()['csrf'] ?? null))) {
            $this->flash('Invalid or missing security token. Please retry from the queue page.', FlashMessages::ERROR);
            return $this->redirect('queue/index');
        }

        return $admin;
    }

    /**
     * Resolve the MailboxTask from the POST `id` field, or null when absent/unknown.
     */
    private function taskFromPost(): ?\Entities\MailboxTask
    {
        $id = self::positiveIntegerOrNull($this->postData()['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $task = $this->mailboxTaskRepository()->find($id);
        return $task instanceof \Entities\MailboxTask ? $task : null;
    }

    protected function em(): \Doctrine\ORM\EntityManager
    {
        $em = parent::em();
        if (!$em instanceof \Doctrine\ORM\EntityManager) {
            throw new \LogicException('Doctrine entity manager resource has an invalid type');
        }
        return $em;
    }

    protected function admin(): ?\Entities\Admin
    {
        $admin = parent::admin();
        if ($admin !== null && !$admin instanceof \Entities\Admin) {
            throw new \LogicException('Authenticated admin has an invalid type');
        }
        return $admin;
    }

    private function mailboxTaskRepository(): \Repositories\MailboxTask
    {
        $repo = $this->em()->getRepository('\\Entities\\MailboxTask');
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new \LogicException('MailboxTask repository has an invalid type');
        }
        return $repo;
    }
}
