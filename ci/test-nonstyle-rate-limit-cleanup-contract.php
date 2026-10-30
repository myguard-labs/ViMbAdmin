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
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}

echo "ALL PASSED ({$checks} checks)\n";
