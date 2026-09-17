<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use Doctrine\Persistence\ObjectManager;
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
        $max = 5;
        if (array_key_exists('queue', $options)) {
            if (!is_array($options['queue'])) {
                throw new \TypeError('queue options must be an array');
            }
            if (array_key_exists('runner', $options['queue'])) {
                if (!is_array($options['queue']['runner'])) {
                    throw new \TypeError('queue.runner options must be an array');
                }
                if (array_key_exists('max_per_run', $options['queue']['runner'])) {
                    $rawMax = $options['queue']['runner']['max_per_run'];
                    if (is_int($rawMax) && $rawMax > 0) {
                        $max = $rawMax;
                    } elseif (is_string($rawMax) && preg_match('/^[1-9][0-9]*$/D', $rawMax) === 1
                        && filter_var($rawMax, FILTER_VALIDATE_INT) !== false) {
                        $max = (int) $rawMax;
                    } else {
                        throw new \TypeError('max_per_run must be a positive integer');
                    }
                }
            }
        }

        $entityManager = $container->entityManager();
        if (!$entityManager instanceof ObjectManager) {
            throw new \LogicException('Queue command requires a Doctrine object manager.');
        }

        $runner = new \ViMbAdmin_Service_QueueRunner($entityManager, $options);

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
