<?php

require __DIR__ . '/../library/ViMbAdmin/Mcp/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/RateLimit.php';

final class McpRateLimitAssertions
{
    public static int $failures = 0;
}

function mcpRateLimitCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        McpRateLimitAssertions::$failures++;
    }
}

/** @return list<int> */
function mcpRateLimitState(string $file): array
{
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }
    $state = [];
    foreach ($decoded as $value) {
        if (!is_int($value)) {
            return [];
        }
        $state[] = $value;
    }
    return $state;
}

function mcpRateLimitCleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}

/** @param callable():void $call */
function mcpRateLimitDenied(string $label, callable $call): void
{
    try {
        $call();
        mcpRateLimitCheck($label, false);
    } catch (ViMbAdmin_Mcp_Exception $e) {
        mcpRateLimitCheck($label, $e->getCode() === 503);
    }
}

echo "== MCP destructive rate limit ==\n";

$defaultLimiter = new ViMbAdmin_Mcp_RateLimit();
$dirProperty = new ReflectionProperty(ViMbAdmin_Mcp_RateLimit::class, '_dir');
mcpRateLimitCheck(
    'default state is kept under the project var directory',
    $dirProperty->getValue($defaultLimiter) === dirname(__DIR__) . '/var/mcp-ratelimit'
);

$stateDir = sys_get_temp_dir() . '/vimbadmin-rate-limit-' . bin2hex(random_bytes(8));
$limiter = new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 2, 'window' => 3600]);
$limiter->hit(42, 'archive/restore');
$limiter->hit(42, 'archive/restore');
$stateFile = $stateDir . '/42-archiverestore.json';
mcpRateLimitCheck('bucket name is sanitized in the per-token filename', is_file($stateFile));
mcpRateLimitCheck('hits are recorded as a JSON timestamp list', count(mcpRateLimitState($stateFile)) === 2);

try {
    $limiter->hit(42, 'archive/restore');
    mcpRateLimitCheck('third in-window hit is denied', false);
} catch (ViMbAdmin_Mcp_Exception $e) {
    mcpRateLimitCheck('third in-window hit is denied with 429', $e->getCode() === 429);
}
mcpRateLimitCheck('denied hit does not alter state', count(mcpRateLimitState($stateFile)) === 2);

$oldFile = $stateDir . '/7-destructive.json';
file_put_contents($oldFile, json_encode([time() - 10]));
$pruning = new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 1, 'window' => 2]);
$pruning->hit(7);
mcpRateLimitCheck('timestamps outside the window are pruned before counting', count(mcpRateLimitState($oldFile)) === 1);

$malformedFile = $stateDir . '/8-destructive.json';
file_put_contents($malformedFile, '{not-json');
mcpRateLimitDenied(
    'malformed JSON denies destructive work instead of resetting the limiter',
    static function () use ($stateDir): void {
        (new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 1, 'window' => 60]))->hit(8);
    }
);
mcpRateLimitCheck('denied malformed state remains unchanged', file_get_contents($malformedFile) === '{not-json');

$stringTimestampFile = $stateDir . '/11-destructive.json';
file_put_contents($stringTimestampFile, json_encode([(string) time()]));
(new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 2, 'window' => 60]))->hit(11);
$stringTimestampState = json_decode((string) file_get_contents($stringTimestampFile), true);
mcpRateLimitCheck(
    'canonical legacy numeric-string timestamps retain their limiting effect',
    is_array($stringTimestampState) && count($stringTimestampState) === 2
);

$wrongShapeFile = $stateDir . '/12-destructive.json';
file_put_contents($wrongShapeFile, json_encode([true, [], '1e2']));
mcpRateLimitDenied(
    'container and coercive timestamp shapes deny destructive work',
    static function () use ($stateDir): void {
        (new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 2, 'window' => 60]))->hit(12);
    }
);

$disabledDir = $stateDir . '/disabled';
(new ViMbAdmin_Mcp_RateLimit(['statedir' => $disabledDir, 'max' => 0]))->hit(9);
mcpRateLimitCheck('zero maximum disables the limiter without creating state', !file_exists($disabledDir));
mcpRateLimitDenied(
    'zero window cannot silently disable an enabled destructive limiter',
    static function () use ($stateDir): void {
        new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 1, 'window' => 0]);
    }
);
mcpRateLimitDenied(
    'negative maximum cannot silently disable the destructive limiter',
    static function () use ($stateDir): void {
        new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => -1, 'window' => 60]);
    }
);
mcpRateLimitDenied(
    'non-integer maximum is rejected instead of coerced',
    static function () use ($stateDir): void {
        new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 'off', 'window' => 60]);
    }
);
mcpRateLimitDenied(
    'invalid window is rejected even when maximum explicitly disables limiting',
    static function () use ($stateDir): void {
        new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 0, 'window' => 0]);
    }
);

$unsafeDir = $stateDir . '/unsafe';
mkdir($unsafeDir, 0777);
chmod($unsafeDir, 0770);
mcpRateLimitDenied(
    'group/world-writable state directory denies destructive work',
    static function () use ($unsafeDir): void {
        (new ViMbAdmin_Mcp_RateLimit(['statedir' => $unsafeDir, 'max' => 1]))->hit(13);
    }
);
chmod($unsafeDir, 0750);
rmdir($unsafeDir);

$unsafeParent = $stateDir . '/unsafe-parent';
$safeChild = $unsafeParent . '/state';
mkdir($safeChild, 0750, true);
chmod($unsafeParent, 0775);
$parentAccepted = true;
try {
    (new ViMbAdmin_Mcp_RateLimit(['statedir' => $safeChild, 'max' => 1]))->hit(14);
} catch (ViMbAdmin_Mcp_Exception) {
    $parentAccepted = false;
}
mcpRateLimitCheck(
    'representative group-writable project parent permits its safe state child',
    $parentAccepted && is_file($safeChild . '/14-destructive.json')
);
foreach (glob($safeChild . '/*') ?: [] as $file) {
    unlink($file);
}
chmod($unsafeParent, 0750);
rmdir($safeChild);
rmdir($unsafeParent);

$symlinkTarget = $stateDir . '/symlink-target';
$symlinkState = $stateDir . '/symlink-state';
mkdir($symlinkTarget, 0750);
symlink($symlinkTarget, $symlinkState);
mcpRateLimitDenied(
    'symlink-substituted state directory denies destructive work',
    static function () use ($symlinkState): void {
        (new ViMbAdmin_Mcp_RateLimit(['statedir' => $symlinkState, 'max' => 1]))->hit(15);
    }
);
unlink($symlinkState);
rmdir($symlinkTarget);

$notDirectory = $stateDir . '/not-a-directory';
file_put_contents($notDirectory, 'occupied');
$errorLog = $stateDir . '/error.log';
$previousLog = ini_set('error_log', $errorLog);
mcpRateLimitDenied(
    'state open failure denies destructive work',
    static function () use ($notDirectory): void {
        (new ViMbAdmin_Mcp_RateLimit(['statedir' => $notDirectory, 'max' => 1]))->hit(10);
    }
);
if ($previousLog !== false) {
    ini_set('error_log', $previousLog);
}
$logged = (string) file_get_contents($errorLog);
mcpRateLimitCheck('state open denial is logged', str_contains($logged, 'destructive operation denied for token 10'));

mcpRateLimitCleanup($stateDir);
$failureCount = McpRateLimitAssertions::$failures;
echo $failureCount === 0 ? "\nALL PASSED\n" : "\n{$failureCount} FAILED\n";
exit(min(1, $failureCount));
