<?php
/**
 * Unit test: the native CLI dispatcher — ViMbAdmin\Kernel\Cli\CliKernel +
 * commands (WALL #2, docs/ZF1-REMOVAL.md). Pure registry/routing checks; no
 * resource is booted (canHandle/commands/name are side-effect-free).
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Cli/CliCommand.php';
require __DIR__ . '/../src/Kernel/Cli/Command/QueueRunCommand.php';
require __DIR__ . '/../src/Kernel/Cli/CliKernel.php';

// CliKernel pulls in Bootstrap via a `use` import only for run(); canHandle()
// touches none of it. Provide the class only if not already autoloaded.
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;
use ViMbAdmin\Kernel\Cli\CliKernel;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native CLI dispatcher ==\n";

$kernel = new CliKernel('/nonexistent', 'testing');

check('canHandle(queue.cli-run) true',  $kernel->canHandle('queue.cli-run'));
check('canHandle(unknown) false',        !$kernel->canHandle('foo.bar'));
check('canHandle(empty) false',          !$kernel->canHandle(''));
check('commands() lists queue.cli-run',  in_array('queue.cli-run', $kernel->commands(), true));

$cmd = new QueueRunCommand();
check('QueueRunCommand name',            $cmd->name() === 'queue.cli-run');
check('CliCommand contract',             $cmd instanceof \ViMbAdmin\Kernel\Cli\CliCommand);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
