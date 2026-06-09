<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\View\SmartyView;

/**
 * `maintenance.cli-precompile-templates` — compile every Smarty template ahead
 * of time so the first web request never pays the per-template compile.
 *
 * The compiled output lives in the persistent `var/templates_c`, shared between
 * this CLI run and the FPM workers (it is a plain filesystem dir, unlike the
 * per-SAPI opcache/APCu which a CLI run cannot warm). Run from the container
 * bootstrap after the schema check. Idempotent and safe to repeat.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class PrecompileTemplatesCommand implements CliCommand
{
    public function name(): string
    {
        return 'maintenance.cli-precompile-templates';
    }

    public function run(Container $container, array $args): int
    {
        $verbose = array_key_exists('v', $args) || array_key_exists('verbose', $args);

        try {
            $n = SmartyView::fromOptions($container->options())->compileAll();
        } catch (\Throwable $e) {
            echo 'ERROR: template precompile failed: ' . $e->getMessage() . "\n";
            return 1;
        }

        if ($verbose) {
            echo "Precompiled {$n} template(s).\n";
        }

        return 0;
    }
}
