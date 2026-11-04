<?php

declare(strict_types=1);

/** Extract the actual protocol functions without executing the database suite. */
function maintenanceBootstrapFunction(string $source, string $name): string
{
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $next = $index + 1;
        while (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            ++$next;
        }
        if (!is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING || $tokens[$next][1] !== $name) {
            continue;
        }
        $definition = '';
        $depth = 0;
        $body = false;
        for (; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            $definition .= is_array($token) ? $token[1] : $token;
            if ($token === '{') {
                $body = true;
                ++$depth;
            } elseif ($token === '}' && --$depth === 0 && $body) {
                return $definition;
            }
        }
    }
    throw new RuntimeException('Protocol function missing: ' . $name);
}

$sourcePath = __DIR__ . '/test-maintenance-resource-claims.php';
$source = (string) file_get_contents($sourcePath);
$directory = sys_get_temp_dir() . '/maintenance-child-bootstrap-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700) || !mkdir($directory . '/ci', 0700) || !mkdir($directory . '/ci/support', 0700)) {
    throw new RuntimeException('Cannot create private bootstrap fixture');
}
$checks = 0;
$check = static function (string $label, bool $valid) use (&$checks): void {
    if (!$valid) {
        throw new RuntimeException('FAIL ' . $label);
    }
    ++$checks;
    echo 'PASS ' . $label . "\n";
};

try {
    // Only the database boundary is stubbed. The entry script, both session
    // files, dispatch and barrier parser are the actual maintained sources.
    // No callback is invoked: no database, backend process or signal is used.
    file_put_contents($directory . '/ci/test-maintenance-task-ownership.php', <<<'PHP'
<?php
function maintenanceTaskManager(): object {
    return new class { public function getConnection(): object { return new stdClass(); } };
}
final class ViMbAdmin_ResourceClaim {
    public static function acquire(object $connection, string $username): self { return new self(); }
    public function remote(callable $callback): void { fwrite(STDOUT, "REMOTE 123\n"); fflush(STDOUT); }
}
PHP);
    foreach (['test-maintenance-session-identity.php', 'support/maintenance-session-identity.php'] as $file) {
        copy(__DIR__ . '/' . $file, $directory . '/ci/' . $file);
    }
    $entry = $directory . '/ci/test-maintenance-resource-claims.php';
    file_put_contents($entry, $source);
    if (preg_match('/^const MAINTENANCE_RESOURCE_LOCAL_TIMEOUT_NS = [0-9_]+;$/m', $source, $constant) !== 1) {
        throw new RuntimeException('Protocol deadline constant missing');
    }
    file_put_contents($directory . '/barrier.php', "<?php\n" . $constant[0] . "\n"
        . maintenanceBootstrapFunction($source, 'maintenanceResourceRemotePid') . "\n"
        . maintenanceBootstrapFunction($source, 'maintenanceResourceRemoteBarrier') . "\n");
    require $directory . '/barrier.php';

    /** @return array{pid:?int,error:?string,status:int} */
    $run = static function (string $mode, string $argument, int $timeout = 2_000_000_000) use ($entry): array {
        $process = proc_open(
            [PHP_BINARY, $entry, $mode, $argument],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start owned protocol child');
        }
        $pid = null;
        $error = null;
        try {
            $pid = maintenanceResourceRemoteBarrier($pipes, 'Bootstrap child barrier', $timeout);
        } catch (RuntimeException $failure) {
            $error = $failure->getMessage();
        } finally {
            // Every fixture exits on stdin EOF; reap its handle without signals.
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($process);
        }
        return ['pid' => $pid, 'error' => $error, 'status' => $status];
    };

    foreach (['claim-wait-timeout-child', 'claim-crash-child'] as $mode) {
        $result = $run($mode, $directory);
        $check(
            $mode . ' actual entry bootstrap starts with REMOTE only'
            . ($result['error'] === null ? '' : '; ' . $result['error']),
            $result === ['pid' => 123, 'error' => null, 'status' => 1]
        );
        $check($mode . ' database-boundary stub never starts its backend callback', !file_exists($directory . '/backend-spawning'));
    }
    foreach (['normal', 'stderr-burst'] as $mode) {
        $check(
            $mode . ' actual probe barrier is accepted and reaped',
            $run('barrier-probe-child', $mode) === ['pid' => 123, 'error' => null, 'status' => 0]
        );
    }
    foreach (['unexpected' => 'invalid first line', 'stderr-error' => 'invalid first line',
              'empty' => 'stdout closed before REMOTE', 'early-error' => 'stdout closed before REMOTE',
              'silent' => 'timed out before REMOTE'] as $mode => $diagnostic) {
        $result = $run('barrier-probe-child', $mode, $mode === 'silent' ? 20_000_000 : 2_000_000_000);
        $check($mode . ' probe fails closed with its original diagnostic', $result['pid'] === null
            && str_contains($result['error'] ?? '', $diagnostic)
            && !str_contains($result['error'] ?? '', 'sensitive fixture detail')
            && $result['status'] === ($mode === 'early-error' ? 7 : 0));
    }

    $quiet = "require_once __DIR__ . '/support/maintenance-session-identity.php';";
    $noisy = "require __DIR__ . '/test-maintenance-session-identity.php';";
    $check('quiet shared-helper import has exactly one bootstrap owner', substr_count($source, $quiet) === 1);
    file_put_contents($entry, str_replace($quiet, $noisy, $source));
    $legacy = $run('claim-wait-timeout-child', $directory);
    $check('old executable-test import reproduces the actual unexpected first line', $legacy['pid'] === null
        && str_contains($legacy['error'] ?? '', 'invalid first line "PASS stable observer and purge connection"'));
    file_put_contents($entry, $source);
    $check(
        'restored quiet bootstrap retains the strict REMOTE barrier',
        $run('claim-wait-timeout-child', $directory) === ['pid' => 123, 'error' => null, 'status' => 1]
    );

    foreach (['REMOTE bad', 'PREFIX REMOTE 123', 'REMOTE 123 EXTRA', ''] as $line) {
        try {
            maintenanceResourceRemotePid($line, 'Malformed barrier', 0);
        } catch (RuntimeException $error) {
            $check('malformed first line is rejected: ' . json_encode($line), str_contains($error->getMessage(), 'invalid first line'));
            continue;
        }
        throw new RuntimeException('Malformed first line accepted');
    }
} finally {
    foreach (['ci/support/maintenance-session-identity.php', 'ci/test-maintenance-session-identity.php',
              'ci/test-maintenance-task-ownership.php', 'ci/test-maintenance-resource-claims.php', 'barrier.php'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory . '/ci/support');
    rmdir($directory . '/ci');
    rmdir($directory);
}
echo 'ALL PASSED (' . $checks . " checks)\n";
