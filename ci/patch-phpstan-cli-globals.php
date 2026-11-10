<?php

declare(strict_types=1);

/**
 * Prepare the CI-pinned PHPStan with the PHP CLI argument-globals contract.
 * The original PHAR remains untouched. Outputs are fresh private copies;
 * --verify checks a previously prepared copy without changing it.
 */
const PHPSTAN_CLI_PHAR_SHA256 = '46e0eb600188e5f6945b846e427614ad531ca575dac2659d307c3f2b7e1d6c3e';
const PHPSTAN_CLI_PATCH_SHA256 = '71c24d497aed11f27559be1bd765db0f553363fbb47ebc7c70ddf698bbfa56d0';
const PHPSTAN_CLI_MEMBER = 'src/DependencyInjection/ContainerFactory.php';
const PHPSTAN_CLI_MEMBER_BEFORE_SHA256 = '30fde28fd79a5472f174f24a9343618abd743338316e80911e0f39090cfab3c8';
const PHPSTAN_CLI_MEMBER_AFTER_SHA256 = '8e475770f46443522cbadab1739cb5702f34e8663ab255418321fdc46229b967';
const PHPSTAN_CLI_BEFORE = "'cliArgumentsVariablesRegistered' => ini_get('register_argc_argv') === '1'";
const PHPSTAN_CLI_AFTER = "'cliArgumentsVariablesRegistered' => (PHP_SAPI === 'cli' || ini_get('register_argc_argv') === '1')";
const PHPSTAN_CLI_LAUNCHER = "#!/usr/bin/env php\n<?php\nrequire dirname(__DIR__) . '/engine/bin/phpstan';\n";

function phpstanCliRequire(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function phpstanCliRead(string $path): string
{
    phpstanCliRequire(is_file($path) && !is_link($path), 'Expected a regular PHPStan input: ' . $path);
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('Cannot read PHPStan input: ' . $path);
    }
    return $bytes;
}

function phpstanCliWrite(string $path, string $bytes, int $mode): void
{
    $stream = fopen($path, 'xb');
    if ($stream === false) {
        throw new RuntimeException('Cannot create fresh PHPStan output: ' . $path);
    }
    try {
        phpstanCliRequire(fwrite($stream, $bytes) === strlen($bytes), 'Incomplete PHPStan output');
    } finally {
        fclose($stream);
    }
    phpstanCliRequire(chmod($path, $mode), 'Cannot protect PHPStan output');
}

/** @return array<string, string> */
function phpstanCliInventory(Phar $archive, string $archivePath): array
{
    $inventory = [];
    $prefix = 'phar://' . $archivePath . '/';
    foreach (new RecursiveIteratorIterator($archive) as $file) {
        if (!$file instanceof PharFileInfo || !$file->isFile() || $file->isLink()) {
            throw new RuntimeException('Unsupported PHPStan archive member');
        }
        $path = $file->getPathname();
        phpstanCliRequire(str_starts_with($path, $prefix), 'Unbound PHPStan archive member');
        $member = substr($path, strlen($prefix));
        phpstanCliRequire($member !== '' && !str_contains($member, '\\') && !in_array('..', explode('/', $member), true), 'Unsafe PHPStan archive path');
        $inventory[$member] = hash('sha256', $file->getContent());
    }
    ksort($inventory);
    phpstanCliRequire(count($inventory) === 7127, 'Unexpected PHPStan member inventory');
    phpstanCliRequire(($inventory[PHPSTAN_CLI_MEMBER] ?? null) === PHPSTAN_CLI_MEMBER_BEFORE_SHA256, 'Unknown PHPStan model member');
    return $inventory;
}

/** @return array<string, bool|int|string> */
function phpstanCliManifest(): array
{
    return [
        'upstream_version' => '2.2.17',
        'source_phar_sha256' => PHPSTAN_CLI_PHAR_SHA256,
        'patch_sha256' => PHPSTAN_CLI_PATCH_SHA256,
        'member' => PHPSTAN_CLI_MEMBER,
        'member_original_sha256' => PHPSTAN_CLI_MEMBER_BEFORE_SHA256,
        'member_patched_sha256' => PHPSTAN_CLI_MEMBER_AFTER_SHA256,
        'member_count' => 7127,
        'inverse_byte_conservation' => true,
        'entrypoint' => 'engine/bin/phpstan',
        'launcher_sha256' => hash('sha256', PHPSTAN_CLI_LAUNCHER),
    ];
}

/** @param array<string, string> $inventory */
function phpstanCliVerify(string $output, array $inventory): void
{
    phpstanCliRequire(is_dir($output) && !is_link($output), 'Missing private PHPStan output');
    phpstanCliRequire(json_decode(phpstanCliRead($output . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR) === phpstanCliManifest(), 'PHPStan manifest mismatch');
    phpstanCliRequire(hash('sha256', phpstanCliRead($output . '/original/phpstan-2.2.17.phar')) === PHPSTAN_CLI_PHAR_SHA256, 'Immutable PHPStan PHAR changed');
    foreach (['original/ContainerFactory.php', 'inverse-check/ContainerFactory.php'] as $member) {
        phpstanCliRequire(hash('sha256', phpstanCliRead($output . '/' . $member)) === PHPSTAN_CLI_MEMBER_BEFORE_SHA256, 'PHPStan original/inverse changed');
    }
    phpstanCliRequire(phpstanCliRead($output . '/bin/phpstan') === PHPSTAN_CLI_LAUNCHER && is_executable($output . '/bin/phpstan'), 'PHPStan launcher changed');
    $expected = $inventory;
    $expected[PHPSTAN_CLI_MEMBER] = PHPSTAN_CLI_MEMBER_AFTER_SHA256;
    $actual = [];
    $engine = $output . '/engine';
    phpstanCliRequire(is_dir($engine) && !is_link($engine), 'Missing PHPStan engine');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($engine, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            throw new RuntimeException('Unsupported PHPStan engine member');
        }
        $member = substr($file->getPathname(), strlen($engine) + 1);
        $actual[$member] = hash('sha256', phpstanCliRead($file->getPathname()));
        phpstanCliRequire(($file->getPerms() & 0222) === 0, 'Writable PHPStan engine member');
    }
    ksort($actual);
    ksort($expected);
    phpstanCliRequire($actual === $expected, 'PHPStan engine inventory/hash mismatch');
    foreach (['original/phpstan-2.2.17.phar', 'original/ContainerFactory.php', 'inverse-check/ContainerFactory.php'] as $member) {
        $mode = fileperms($output . '/' . $member);
        phpstanCliRequire($mode !== false && ($mode & 0222) === 0, 'Writable immutable PHPStan input');
    }
}

try {
    $verify = ($argv[1] ?? null) === '--verify';
    phpstanCliRequire($argc === ($verify ? 4 : 3), 'Usage: php ci/patch-phpstan-cli-globals.php [--verify] PINNED_PHAR OUTPUT');
    $source = realpath($argv[$verify ? 2 : 1]);
    if ($source === false) {
        throw new RuntimeException('Missing PHPStan PHAR');
    }
    $sourceBytes = phpstanCliRead($source);
    phpstanCliRequire(hash('sha256', $sourceBytes) === PHPSTAN_CLI_PHAR_SHA256, 'Unknown or modified PHPStan PHAR; expected CI-pinned 2.2.17');
    phpstanCliRequire(hash('sha256', phpstanCliRead(__DIR__ . '/phpstan-cli-globals.patch')) === PHPSTAN_CLI_PATCH_SHA256, 'PHPStan patch bytes changed');
    $requested = $argv[$verify ? 3 : 2];
    $parent = realpath(dirname($requested));
    $name = basename($requested);
    if ($parent === false) {
        throw new RuntimeException('Output parent must exist');
    }
    phpstanCliRequire($name !== '.' && $name !== '..' && $name !== '', 'Invalid output name');
    $output = $parent . '/' . $name;
    if ($verify) {
        $archivePath = $output . '/original/phpstan-2.2.17.phar';
        phpstanCliRequire(hash('sha256', phpstanCliRead($archivePath)) === PHPSTAN_CLI_PHAR_SHA256, 'Immutable PHPStan PHAR changed');
        phpstanCliVerify($output, phpstanCliInventory(new Phar($archivePath), $archivePath));
    } else {
        phpstanCliRequire(!file_exists($output) && !is_link($output), 'Refusing an existing PHPStan output');
        phpstanCliRequire(mkdir($output, 0700), 'Cannot create private PHPStan output');
        foreach (['original', 'engine', 'inverse-check', 'bin'] as $directory) {
            phpstanCliRequire(mkdir($output . '/' . $directory, 0700), 'Cannot create PHPStan directory');
        }
        $archivePath = $output . '/original/phpstan-2.2.17.phar';
        phpstanCliWrite($archivePath, $sourceBytes, 0400);
        $archive = new Phar($archivePath);
        $inventory = phpstanCliInventory($archive, $archivePath);
        $before = $archive[PHPSTAN_CLI_MEMBER]->getContent();
        $after = str_replace(PHPSTAN_CLI_BEFORE, PHPSTAN_CLI_AFTER, $before, $count);
        phpstanCliRequire($count === 1 && hash('sha256', $after) === PHPSTAN_CLI_MEMBER_AFTER_SHA256, 'PHPStan model replacement mismatch');
        $inverse = str_replace(PHPSTAN_CLI_AFTER, PHPSTAN_CLI_BEFORE, $after, $count);
        phpstanCliRequire($count === 1 && $inverse === $before, 'PHPStan inverse failed byte conservation');
        phpstanCliRequire($archive->extractTo($output . '/engine'), 'Cannot extract pinned PHPStan');
        $target = $output . '/engine/' . PHPSTAN_CLI_MEMBER;
        phpstanCliRequire(file_put_contents($target, $after) === strlen($after), 'Cannot write corrected PHPStan model');
        phpstanCliWrite($output . '/original/ContainerFactory.php', $before, 0400);
        phpstanCliWrite($output . '/inverse-check/ContainerFactory.php', $inverse, 0400);
        foreach (array_keys($inventory) as $member) {
            phpstanCliRequire(chmod($output . '/engine/' . $member, 0400), 'Cannot protect PHPStan engine member');
        }
        phpstanCliWrite($output . '/bin/phpstan', PHPSTAN_CLI_LAUNCHER, 0700);
        phpstanCliWrite($output . '/manifest.json', json_encode(phpstanCliManifest(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", 0400);
        phpstanCliVerify($output, $inventory);
    }
    phpstanCliRequire(hash('sha256', phpstanCliRead($source)) === PHPSTAN_CLI_PHAR_SHA256, 'Input PHPStan PHAR changed');
    echo $verify ? "Verified isolated PHPStan CLI model\n" : "Built isolated PHPStan CLI model\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
