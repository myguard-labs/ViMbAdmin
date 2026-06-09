<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `queue.cli-run` — drain the mailbox-task queue (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Drains through the SAME framework-free {@see \ViMbAdmin_Service_QueueRunner}
 * engine the native runNow/runTask + the remote trigger endpoint use. This is
 * the cron / s6 entrypoint (`vimbtool.php -a queue.cli-run`).
 *
 * By default it autonomously clears the whole backlog: it drains batches of
 * `queue.runner.max_per_run` until the queue is empty (or a batch is
 * lease-throttled). Pass `--once` to drain a single batch and exit (the lease
 * cap serialises overlapping runs either way, so a long run is safe — the next
 * cron tick simply finds the slot busy and returns).
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class QueueRunCommand implements CliCommand
{
    public function name(): string
    {
        return 'queue.cli-run';
    }

    public function run(Container $container, array $args): int
    {
        $verbose = array_key_exists('v', $args) || array_key_exists('verbose', $args);
        $once    = array_key_exists('once', $args);

        $options = $container->options();
        $max     = (int) ($options['queue']['runner']['max_per_run'] ?? 5);

        $runner = new \ViMbAdmin_Service_QueueRunner($container->entityManager(), $options);

        $total = 0;
        do {
            $n = $runner->drain($max, $verbose);
            if ($n > 0) {
                $total += $n;
            }
        } while (!$once && $n > 0);

        if ($verbose) {
            echo "Processed {$total} task(s).\n";
        }

        return 0;
    }
}
