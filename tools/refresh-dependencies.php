<?php

declare(strict_types=1);

/** @return array{name:string,version:string,dist:array{type:'zip',url:string,shasum:string}} */
function dependencyPackage(mixed $package, bool $trusted): array
{
    if (!is_array($package) || !isset($package['name'], $package['version'], $package['dist'])
        || !is_string($package['name']) || preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $package['name']) !== 1
        || !is_string($package['version']) || $package['version'] === '' || !is_array($package['dist'])) {
        throw new RuntimeException('Lock package identity/distribution is malformed or duplicated');
    }
    $dist = $package['dist'];
    if (($dist['type'] ?? null) !== 'zip' || !is_string($dist['url'] ?? null) || !is_string($dist['shasum'] ?? null)) {
        throw new RuntimeException('Lock package identity/distribution is malformed or duplicated');
    }
    dependencyArchiveUrl($dist['url']);
    $pin = $dist['shasum'];
    if (($trusted && $pin === '') || ($pin !== '' && preg_match('/^[a-f0-9]{40}$/D', $pin) !== 1)) {
        throw new RuntimeException('Trusted pin missing or malformed distribution checksum');
    }
    $package['dist'] = $dist;
    return $package;
}

/** @return array{packages:list<array{name:string,version:string,dist:array{type:'zip',url:string,shasum:string}}>,packages-dev:list<array{name:string,version:string,dist:array{type:'zip',url:string,shasum:string}}>} */
function dependencyLock(string $contents, bool $trusted): array
{
    $structure = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
    if (!$structure instanceof stdClass || !is_array($structure->packages ?? null) || !is_array($structure->{'packages-dev'} ?? null)) {
        throw new RuntimeException('Lock package lists are missing or malformed');
    }
    $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lock)) {
        throw new RuntimeException('Lock root is malformed');
    }
    $names = [];
    $production = [];
    $development = [];
    foreach ([['packages', $structure->packages], ['packages-dev', $structure->{'packages-dev'}]] as [$section, $packages]) {
        foreach ($packages as $value) {
            $package = dependencyPackage(json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $trusted);
            if (isset($names[$package['name']])) {
                throw new RuntimeException('Lock package identity/distribution is malformed or duplicated');
            }
            $names[$package['name']] = true;
            if ($section === 'packages') {
                $production[] = $package;
            } else {
                $development[] = $package;
            }
        }
    }
    $lock['packages'] = $production;
    $lock['packages-dev'] = $development;
    return $lock;
}

function dependencyArchiveUrl(string $url): void
{
    // This repository currently locks only immutable GitHub zipball archives.
    // New origins need an explicit reviewed policy, never arbitrary PR URLs.
    if (preg_match('~^https://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zipball/[a-f0-9]{40}$~D', $url) !== 1) {
        throw new RuntimeException('Unsupported distribution archive URL');
    }
}

function dependencyRead(string $path): string
{
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Required dependency-refresh input cannot be read');
    }
    return $contents;
}

function dependencyArchive(string $url): string
{
    dependencyArchiveUrl($url);
    $temporary = tempnam(sys_get_temp_dir(), 'vimbadmin-dependency-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create archive download file');
    }
    try {
        $process = proc_open(['curl', '--disable', '--fail', '--silent', '--show-error', '--location',
            '--proto', '=https', '--proto-redir', '=https', '--max-redirs', '3',
            '--connect-timeout', '10', '--max-time', '120', '--max-filesize', '52428800',
            '--output', $temporary, $url], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Distribution archive fetch failed');
        }
        $bytes = dependencyRead($temporary);
        if ($bytes === '' || strlen($bytes) > 52428800) {
            throw new RuntimeException('Distribution archive is empty or exceeds size bound');
        }
        return $bytes;
    } finally {
        unlink($temporary);
    }
}

/**
 * @param null|Closure(string):string $fetch Test-only transport; hashing stays owned here.
 */
function refreshDependencies(string $root, string $baseLock, bool $check = false, ?Closure $fetch = null): void
{
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        throw new RuntimeException('Dependency checkout root is missing');
    }
    $root = $resolvedRoot;
    $base = dependencyLock(dependencyRead($baseLock), true);
    $paths = ['composer.lock', 'docs/ORM3-UPGRADE.md', 'CHANGELOG'];
    $original = [];
    foreach ($paths as $path) {
        $resolved = realpath($root . '/' . $path);
        if ($resolved === false || !is_file($resolved) || is_link($root . '/' . $path) || !str_starts_with($resolved, $root . '/')) {
            throw new RuntimeException('Required dependency-refresh input is missing or escapes checkout');
        }
        $original[$path] = dependencyRead($resolved);
    }
    $guards = ['.github/scripts/assert-composer-lock.php', 'tests/test-documentation-contract.php'];
    foreach ($guards as $guard) {
        $resolved = realpath($root . '/' . $guard);
        if ($resolved === false || !is_file($resolved) || is_link($root . '/' . $guard) || !str_starts_with($resolved, $root . '/')) {
            throw new RuntimeException('Required dependency guard is missing');
        }
    }
    $lock = dependencyLock($original['composer.lock'], false);
    $pins = [];
    foreach (array_merge($base['packages'], $base['packages-dev']) as $package) {
        $pins[$package['name']] = $package['dist'];
    }
    $fetch ??= dependencyArchive(...);
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] as &$package) {
            $name = $package['name'];
            $dist = $package['dist'];
            $previous = $pins[$name] ?? null;
            if ($previous !== null && $previous['url'] === $dist['url']) {
                if ($dist['shasum'] !== '' && !hash_equals($previous['shasum'], $dist['shasum'])) {
                    throw new RuntimeException('Checksum mismatch for unchanged archive URL');
                }
                $package['dist']['shasum'] = $previous['shasum'];
                continue;
            }
            $bytes = $fetch($dist['url']);
            if ($bytes === '' || strlen($bytes) > 52428800) {
                throw new RuntimeException('Distribution archive is empty or exceeds size bound');
            }
            $actual = sha1($bytes);
            if ($dist['shasum'] !== '' && !hash_equals($dist['shasum'], $actual)) {
                throw new RuntimeException('Downloaded archive checksum mismatch');
            }
            $package['dist']['shasum'] = $actual;
        }
        unset($package);
    }
    $versions = [];
    foreach ($lock['packages'] as $package) {
        $versions[$package['name']] = ltrim($package['version'], 'v');
    }
    $orm = $versions['doctrine/orm'] ?? '';
    $dbal = $versions['doctrine/dbal'] ?? '';
    if (preg_match('/^3\.[0-9]+\.[0-9]+$/D', $orm) !== 1 || preg_match('/^4\.[0-9]+\.[0-9]+$/D', $dbal) !== 1) {
        throw new RuntimeException('Existing ORM3/DBAL4 versions are required');
    }
    $updated = $original;
    // Replace checksum fields only: preserve every other byte of Composer output.
    $pendingPins = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
        $pendingPins[] = $package['dist']['shasum'];
    }
    $index = 0;
    $updated['composer.lock'] = preg_replace_callback(
        '/("shasum"\s*:\s*")[a-f0-9]*(")/',
        static function (array $match) use (&$index, $pendingPins): string {
            if (!isset($pendingPins[$index])) {
                throw new RuntimeException('Unexpected checksum field in lock');
            }
            return $match[1] . $pendingPins[$index++] . $match[2];
        },
        $original['composer.lock']
    );
    if ($index !== count($pendingPins) || !is_string($updated['composer.lock'])
        || dependencyLock($updated['composer.lock'], true) !== $lock) {
        throw new RuntimeException('Lock checksum field layout is unsupported');
    }
    $patterns = [
        'docs/ORM3-UPGRADE.md' => '/(\bORM\s+)[0-9]+(?:\.[0-9]+)*(\s+and\s+DBAL\s+)[0-9]+(?:\.[0-9]+)*/',
        'CHANGELOG' => '/(\bDoctrine ORM\s+2\.8\s+->\s+)[0-9]+(?:\.[0-9]+)*(\s+\(\+ DBAL\s+)[0-9]+(?:\.[0-9]+)*(\))/',
    ];
    foreach ($patterns as $path => $pattern) {
        $updated[$path] = preg_replace_callback($pattern, static fn (array $m): string => $m[1] . $orm . $m[2] . $dbal . ($m[3] ?? ''), $original[$path], -1, $count);
        if ($count !== 1 || !is_string($updated[$path])) {
            throw new RuntimeException('Existing documentation version pair is missing or ambiguous');
        }
    }
    $completed = false;
    try {
        foreach ($updated as $path => $bytes) {
            if (@file_put_contents($root . '/' . $path, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Could not write dependency-refresh candidate');
            }
        }
        foreach ($guards as $guard) {
            $process = proc_open([PHP_BINARY, $root . '/' . $guard], [0 => ['file', '/dev/null', 'r'],
                1 => STDOUT, 2 => STDERR], $pipes, $root);
            if (!is_resource($process) || proc_close($process) !== 0) {
                throw new RuntimeException('Dependency guard failed: ' . $guard);
            }
        }
        if ($check && $updated !== $original) {
            throw new RuntimeException('Dependency candidate needs refresh; run without --check and commit the lock/docs repair');
        }
        $completed = true;
    } finally {
        if ($check || !$completed) {
            foreach ($original as $path => $bytes) {
                if (@file_put_contents($root . '/' . $path, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new RuntimeException('Could not restore dependency-refresh input');
                }
            }
        }
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $arguments = array_slice($argv, 1);
        $check = in_array('--check', $arguments, true);
        $arguments = array_values(array_filter($arguments, static fn (string $arg): bool => $arg !== '--check'));
        if (count($arguments) !== 2 || $arguments[0] !== '--base-lock') {
            throw new RuntimeException('Usage: php tools/refresh-dependencies.php --base-lock FILE [--check]');
        }
        refreshDependencies(dirname(__DIR__), $arguments[1], $check);
        echo "Dependency refresh and both guards passed.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
