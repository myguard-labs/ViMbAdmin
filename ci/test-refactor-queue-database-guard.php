<?php

declare(strict_types=1);

final class QueueDatabaseGuardContractState
{
    public static int $checks = 0;
}

/** @phpstan-assert true $passed */
function queueDatabaseGuardCheck(string $label, bool $passed): void
{
    ++QueueDatabaseGuardContractState::$checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo '  ok   ' . $label . "\n";
}

function queueDatabaseGuardRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $entries = scandir($path);
    if (!is_array($entries)) {
        throw new RuntimeException('Could not enumerate guard fixture directory.');
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            queueDatabaseGuardRemoveTree($child);
        } elseif (!unlink($child)) {
            throw new RuntimeException('Could not remove guard fixture file.');
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove guard fixture directory.');
    }
}

/**
 * @param list<string> $environment
 * @return array{status:int,stdout:string,stderr:string,autoloaded:bool}
 */
function queueDatabaseGuardRun(string $script, string $marker, array $environment): array
{
    if (is_file($marker) && !unlink($marker)) {
        throw new RuntimeException('Could not reset autoload marker.');
    }
    $command = [
        '/usr/bin/env',
        '-i',
        ...$environment,
        'QUEUE_LIFECYCLE_GUARD_AUTOLOAD_MARKER=' . $marker,
        PHP_BINARY,
        $script,
    ];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start queue lifecycle guard subprocess.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if (!is_string($stdout) || !is_string($stderr)) {
        throw new RuntimeException('Could not read queue lifecycle guard subprocess output.');
    }
    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'autoloaded' => is_file($marker),
    ];
}

$root = sys_get_temp_dir() . '/vimbadmin-queue-database-guard-' . bin2hex(random_bytes(8));
$fixtureDirectory = $root . '/ci';
$vendorDirectory = $root . '/vendor';
$fixture = $fixtureDirectory . '/test-refactor-queue-lifecycle.php';
$marker = $root . '/autoloaded';

if (!mkdir($fixtureDirectory, 0700, true) || !mkdir($vendorDirectory, 0700, true)) {
    throw new RuntimeException('Could not create queue lifecycle guard fixture directory.');
}

try {
    if (!copy(__DIR__ . '/test-refactor-queue-lifecycle.php', $fixture)) {
        throw new RuntimeException('Could not copy queue lifecycle fixture.');
    }
    $autoload = <<<'PHP'
<?php
file_put_contents((string) getenv('QUEUE_LIFECYCLE_GUARD_AUTOLOAD_MARKER'), "autoloaded\n");
exit(23);
PHP;
    if (file_put_contents($vendorDirectory . '/autoload.php', $autoload) === false) {
        throw new RuntimeException('Could not write queue lifecycle autoload sentinel.');
    }

    $flagMessage = "Queue lifecycle fixture requires VIMBADMIN_DESTRUCTIVE_DB_TESTS=1.\n";
    $targetMessage = "Queue lifecycle fixture requires DB_NAME=vimbadmin_queue_lifecycle_test.\n";
    $negativeCases = [
        'unset destructive flag' => [
            ['DB_NAME=vimbadmin_queue_lifecycle_test'],
            $flagMessage,
        ],
        'missing database target' => [
            ['VIMBADMIN_DESTRUCTIVE_DB_TESTS=1'],
            $targetMessage,
        ],
        'empty database target' => [
            ['VIMBADMIN_DESTRUCTIVE_DB_TESTS=1', 'DB_NAME='],
            $targetMessage,
        ],
        'application database target' => [
            ['VIMBADMIN_DESTRUCTIVE_DB_TESTS=1', 'DB_NAME=vimbadmin'],
            $targetMessage,
        ],
        'malformed dedicated database boundary' => [
            ['VIMBADMIN_DESTRUCTIVE_DB_TESTS=1', 'DB_NAME=vimbadmin_queue_lifecycle_test-1'],
            $targetMessage,
        ],
    ];
    foreach ($negativeCases as $label => [$environment, $message]) {
        $result = queueDatabaseGuardRun($fixture, $marker, $environment);
        queueDatabaseGuardCheck($label . ' exits with the guard status', $result['status'] === 2);
        queueDatabaseGuardCheck($label . ' reports the exact guard', $result['stderr'] === $message);
        queueDatabaseGuardCheck($label . ' emits no success output', $result['stdout'] === '');
        queueDatabaseGuardCheck($label . ' stops before autoload or connection setup', !$result['autoloaded']);
    }

    $positive = queueDatabaseGuardRun($fixture, $marker, [
        'VIMBADMIN_DESTRUCTIVE_DB_TESTS=1',
        'DB_NAME=vimbadmin_queue_lifecycle_test',
    ]);
    queueDatabaseGuardCheck('exact dedicated database target passes both guards', $positive['status'] === 23);
    queueDatabaseGuardCheck('exact dedicated database target reaches the autoload sentinel', $positive['autoloaded']);
    queueDatabaseGuardCheck('exact dedicated database target has no guard error', $positive['stderr'] === '');
    queueDatabaseGuardCheck('exact dedicated database target emits no fixture output before autoload', $positive['stdout'] === '');

    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/regression.yml');
    if (!is_string($workflow)) {
        throw new RuntimeException('Could not read regression workflow.');
    }
    queueDatabaseGuardCheck(
        'schema workflow provisions only the fixed dedicated database identity',
        str_contains(
            $workflow,
            "mysql -hmariadb -P3306 -uroot -pci -e\n"
            . "          \"CREATE DATABASE \\`vimbadmin_queue_lifecycle_test\\`\n"
            . "          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\""
        )
    );
    queueDatabaseGuardCheck(
        'schema workflow runs the lifecycle fixture against the dedicated database',
        preg_match(
            "/      - name: Actual queue execution retains ownership\\n"
            . "        env:\\n"
            . "          DB_HOST: mariadb\\n"
            . "          DB_PORT: '3306'\\n"
            . "          DB_NAME: vimbadmin_queue_lifecycle_test\\n"
            . "          DB_USER: root\\n"
            . "          DB_PASS: ci\\n"
            . "          VIMBADMIN_DESTRUCTIVE_DB_TESTS: '1'\\n"
            . "        run: php ci\\/test-refactor-queue-lifecycle\\.php/D",
            $workflow
        ) === 1
    );
} finally {
    queueDatabaseGuardRemoveTree($root);
}

if (QueueDatabaseGuardContractState::$checks !== 26) {
    throw new RuntimeException(
        'FAIL: fixed assertion count (' . QueueDatabaseGuardContractState::$checks . ' of 26)'
    );
}

echo 'ALL PASSED (' . QueueDatabaseGuardContractState::$checks . " checks)\n";
