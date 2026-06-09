<?php
/**
 * Unit test: the native CLI dispatcher — ViMbAdmin\Kernel\Cli\CliKernel +
 * commands (WALL #2, docs/ZF1-REMOVAL.md). Pure registry/routing checks; no
 * resource is booted (canHandle/commands/name are side-effect-free).
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Cli/CliCommand.php';
foreach (glob(__DIR__ . '/../src/Kernel/Cli/Command/*.php') as $cmd) {
    require $cmd;
}
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

$expected = [
    'queue.cli-run',
    'admin.cli-reset-totp',
    'mailbox.cli-delete-pending',
    'maintenance.cli-schema-update',
    'maintenance.cli-precompile-templates',
    'mcp.cli-token-generate',
    'mcp.cli-token-list',
    'mcp.cli-token-revoke',
];
foreach ($expected as $name) {
    check("canHandle({$name}) true", $kernel->canHandle($name));
}
check('canHandle(unknown) false',        !$kernel->canHandle('foo.bar'));
check('canHandle(empty) false',          !$kernel->canHandle(''));

$got = $kernel->commands();
sort($got);
$want = $expected;
sort($want);
check('commands() == registered set',    $got === $want);

$cmd = new QueueRunCommand();
check('QueueRunCommand name',            $cmd->name() === 'queue.cli-run');
check('CliCommand contract',             $cmd instanceof \ViMbAdmin\Kernel\Cli\CliCommand);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
