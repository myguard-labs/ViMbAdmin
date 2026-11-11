<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/library/ViMbAdmin/Mcp/Exception.php';
require_once getenv('VI_NONSTYLE_RATE_LIMIT') ?: dirname(__DIR__) . '/library/ViMbAdmin/Mcp/RateLimit.php';
require_once dirname(__DIR__) . '/library/ViMbAdmin/BruteForce.php';

$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $checks++;
};
$expectCode = static function (callable $operation, int $code, string $name) use ($check): void {
    try {
        $operation();
    } catch (ViMbAdmin_Mcp_Exception $exception) {
        $check($exception->getCode() === $code, $name);
        return;
    }
    throw new RuntimeException('FAIL: ' . $name . ' did not throw');
};
$root = tempnam(sys_get_temp_dir(), 'vi-nonstyle-cleanup-');
if ($root === false || !unlink($root) || !mkdir($root, 0700)) {
    throw new RuntimeException('cannot create private fixture');
}

try {
    // Production construction establishes a string; direct private calls also
    // honour the nullable initializer without passing null to filesystem APIs.
    $unconfigured = (new ReflectionClass(ViMbAdmin_BruteForce::class))->newInstanceWithoutConstructor();
    $reap = new ReflectionMethod(ViMbAdmin_BruteForce::class, '_reapStale');
    $nullResult = null;
    $nullError = null;
    set_error_handler(static function (int $severity, string $message): bool {
        if ($severity === E_DEPRECATED) {
            throw new ErrorException($message, 0, $severity);
        }
        return false;
    });
    try {
        $nullResult = $reap->invoke($unconfigured, time());
    } catch (Throwable $exception) {
        $nullError = $exception;
    } finally {
        restore_error_handler();
    }
    $check($nullResult === false && $nullError === null, 'null reaper directory skips without filesystem error');
    $unconfiguredRejected = false;
    try {
        (new ReflectionMethod($unconfigured, '_maybeReapStale'))->invoke($unconfigured);
    } catch (LogicException $exception) {
        $unconfiguredRejected = $exception->getMessage() === 'bruteforce state directory is not configured';
    }
    $check($unconfiguredRejected, 'normal reaper entry still rejects unconfigured state');
    foreach ([null, '', $root . '/configured'] as $configured) {
        $instance = new ViMbAdmin_BruteForce(null, ['statedir' => $configured]);
        $check(is_string((new ReflectionProperty($instance, '_statedir'))->getValue($instance)), 'constructor establishes string state directory');
    }
    $invalidRejected = false;
    try {
        new ViMbAdmin_BruteForce(null, ['statedir' => 17]);
    } catch (LogicException $exception) {
        $invalidRejected = $exception->getMessage() === 'bruteforce.statedir must be a string';
    }
    $check($invalidRejected, 'non-string state directory configuration rejected');
    mkdir($root . '/reaper-valid', 0700);
    file_put_contents($root . '/reaper-file', 'preserved');
    symlink($root . '/reaper-valid', $root . '/reaper-link');
    try {
        foreach (['valid', 'missing', 'file', 'link'] as $kind) {
            $instance = new ViMbAdmin_BruteForce(null, ['statedir' => $root . '/reaper-' . $kind]);
            $check($reap->invoke($instance, time()) === false, $kind . ' reaper directory boundary');
        }
        $check(is_file($root . '/reaper-valid/.reap-cursor'), 'valid empty directory retains cursor effect');
        $check(file_get_contents($root . '/reaper-file') === 'preserved', 'regular file preserved');
        $check(!file_exists($root . '/reaper-missing'), 'missing directory not created');
        $check(is_link($root . '/reaper-link'), 'symlink preserved');
    } finally {
        unlink($root . '/reaper-link');
    }

    $disabled = new ViMbAdmin_BruteForce(null, ['enabled' => false, 'statedir' => $root . '/disabled']);
    $disabled->assertNotLocked(null);
    $check(!file_exists($root . '/disabled'), 'disabled brute-force returns without creating state');

    $limiter = new ViMbAdmin_Mcp_RateLimit(['statedir' => $root, 'max' => 1, 'window' => 60]);
    $limiter->hit(1);
    $state = $root . '/1-destructive.json';
    $hits = json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    $check(is_array($hits) && count($hits) === 1 && is_int($hits[0]), 'successful hit commits one timestamp');
    $check(glob($state . '.tmp-*') === [], 'successful commit leaves no temporary file');
    $before = file_get_contents($state);
    $expectCode(static fn () => $limiter->hit(1), 429, 'limit boundary remains denied');
    $check(file_get_contents($state) === $before, 'denied hit preserves committed state');

    file_put_contents($root . '/2-destructive.json', '{broken');
    $expectCode(static fn () => $limiter->hit(2), 503, 'malformed state remains fail-closed');
    $check(file_get_contents($root . '/2-destructive.json') === '{broken', 'malformed state is not overwritten');

    $failedState = $root . '/3-destructive.json';
    mkdir($failedState, 0700);
    set_error_handler(static fn (): bool => true);
    try {
        $expectCode(static fn () => $limiter->hit(3), 503, 'failed rename remains unavailable');
    } finally {
        restore_error_handler();
    }
    $check(glob($failedState . '.tmp-*') === [], 'failed rename removes its temporary file');
    $check(is_dir($failedState), 'failed rename preserves destination');
    $lock = fopen($failedState . '.lock', 'c+');
    if ($lock === false) {
        throw new RuntimeException('cannot open fixture lock');
    }
    try {
        $check(flock($lock, LOCK_EX | LOCK_NB), 'failed rename releases the sidecar lock');
        flock($lock, LOCK_UN);
    } finally {
        fclose($lock);
    }
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected cleanup iterator entry');
        }
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}

echo "ALL PASSED ({$checks} checks)\n";
