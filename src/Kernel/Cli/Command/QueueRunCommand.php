<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `queue.cli-run` — drain the mailbox-task queue (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Native port of `QueueController::cliRunAction`: claims up to
 * `queue.runner.max_per_run` PENDING tasks and runs them, through the SAME
 * framework-free {@see \ViMbAdmin_Service_QueueRunner} engine the ZF1 cron runner
 * (and the native runNow/runTask + the trigger endpoint) use. This is the
 * every-2-minutes cron entrypoint (`vimbtool.php -a queue.cli-run`).
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

        $options = $container->options();
        $max     = (int) ($options['queue']['runner']['max_per_run'] ?? 5);

        $n = (new \ViMbAdmin_Service_QueueRunner($container->entityManager(), $options))->drain($max, $verbose);

        if ($verbose && $n >= 0) {
            echo "Processed {$n} task(s).\n";
        }

        return 0;
    }
}
