<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli;

use ViMbAdmin\Kernel\Container;

/**
 * A native CLI command (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * The framework-free replacement for a ZF1 `cli*Action` dispatched by
 * `bin/vimbtool.php` through the ZF1 application + `OSS_Controller_Router_Cli`.
 * Each command is keyed by the same `controller.action` name the ZF1 CLI used
 * (e.g. `queue.cli-run`), runs against the native {@see Container} (built once by
 * the {@see CliKernel}), reads its options from the parsed argv array, writes to
 * stdout, and returns a process exit code.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
interface CliCommand
{
    /**
     * The `controller.action` name this command answers to (the same string the
     * ZF1 CLI accepted as `-a`, e.g. `queue.cli-run`).
     */
    public function name(): string;

    /**
     * Run the command. Echo any output; return the process exit code (0 = ok).
     *
     * @param array<string,mixed> $args the parsed argv (long+short option names →
     *                            value, or `false` for a present value-less flag),
     *                            as produced by {@see \getopt()}
     */
    public function run(Container $container, array $args): int;
}
