<?php

declare(strict_types=1);

/**
 * Build an isolated Psalm 6.16.1 from the exact PHAR installed by CI.
 *
 * Requires PHP's Phar extension and the patch executable. Never edits the
 * supplied PHAR, an existing output directory, Composer tools or PATH.
 *
 * Consumption after setup-php-container.sh (integration is a separate change):
 *   php ci/patch-psalm-named-taint.php /usr/local/bin/psalm "$PWD/vendor/patched-psalm"
 *   export PATH="$PWD/vendor/patched-psalm/bin:$PATH"
 *   export PSALM_BIN="$PWD/vendor/patched-psalm/bin/psalm"
 *   psalm --no-progress --taint-analysis
 * The job must export these before every baseline/probe/analysis consumer;
 * building this copy alone does not adopt it for native or CI checks.
 */

const UPSTREAM_PHAR_SHA256 = '7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00';
const PATCH_SHA256 = '66d1bab5fa68913eeca421d29890a6037f03df3ecd0dd52664c0990bda0ea3fc';
const MEMBERS = [
    'src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentsAnalyzer.php' => [
        'original' => '68ff6086f19a691712b97c2c499f12385e1640789a658c00a00cf02e4afcfafc',
        'patched' => '1c0ba8ba626104f8c983f4c34451503887c822652b11fd917b504cd46c09ec8f',
    ],
    'src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php' => [
        'original' => '0e07017e894b3163f7069c7f1beb1e1bf6773f5dc9986a19b58b172da37a6fe7',
        'patched' => '12e2cf9e7564b2a6866d10794a03e9f83fddd93026b6f4de06a0e3a3c3c4474c',
    ],
];

/** @param list<string> $command */
function run(array $command, string $cwd): void
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start patch validation command');
    }
    fclose($pipes[0]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Patch validation command failed: ' . $status);
    }
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    ensure($argc === 3, 'Usage: php ci/patch-psalm-named-taint.php PINNED_PHAR NEW_OUTPUT_DIRECTORY');
    $source = realpath($argv[1]);
    if ($source === false || !is_file($source)) {
        throw new RuntimeException('Expected a regular upstream PHAR');
    }
    ensure(hash_file('sha256', $source) === UPSTREAM_PHAR_SHA256, 'Unknown or modified Psalm PHAR; expected CI-pinned 6.16.1');
    $patch = __DIR__ . '/psalm-named-argument-taint.patch';
    ensure(hash_file('sha256', $patch) === PATCH_SHA256, 'Patch bytes do not match the checked patch');
    $parent = realpath(dirname($argv[2]));
    $name = basename($argv[2]);
    ensure($parent !== false && $name !== '.' && $name !== '..' && $name !== '', 'Output parent must exist');
    $output = $parent . '/' . $name;
    ensure(!file_exists($output) && !is_link($output), 'Refusing an existing/already patched output');

    // CI installs an extensionless `psalm`; open its preserved .phar copy.
    ensure(mkdir($output, 0700), 'Cannot create isolated output');
    ensure(mkdir($output . '/original', 0700), 'Cannot preserve immutable originals');
    $originalPhar = $output . '/original/psalm-6.16.1.phar';
    ensure(copy($source, $originalPhar) && chmod($originalPhar, 0400), 'Cannot preserve immutable upstream PHAR');
    ensure(hash_file('sha256', $originalPhar) === UPSTREAM_PHAR_SHA256, 'Immutable upstream PHAR changed');
    $phar = new Phar($originalPhar);
    $metadata = json_decode($phar['composer.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($metadata) || ($metadata['name'] ?? null) !== 'vimeo/psalm' || ($metadata['license'] ?? null) !== 'MIT') {
        throw new RuntimeException('Unexpected upstream package metadata');
    }
    foreach (MEMBERS as $member => $pins) {
        $bytes = $phar[$member]->getContent();
        ensure(hash('sha256', $bytes) === $pins['original'], 'Unknown upstream member: ' . $member);
    }

    $engine = $output . '/engine';
    ensure(mkdir($engine, 0700), 'Cannot create isolated engine');
    ensure($phar->extractTo($engine), 'Cannot extract verified upstream PHAR');
    foreach (MEMBERS as $member => $pins) {
        $original = $output . '/original/' . basename($member);
        ensure(copy($engine . '/' . $member, $original) && chmod($original, 0400), 'Cannot preserve original member');
    }
    $arguments = ['patch', '--batch', '--fuzz=0', '-p1', '--input=' . $patch];
    run([...$arguments, '--forward', '--dry-run'], $engine);
    run([...$arguments, '--forward'], $engine);
    foreach (MEMBERS as $member => $pins) {
        ensure(hash_file('sha256', $engine . '/' . $member) === $pins['patched'], 'Unexpected patched member: ' . $member);
        run([PHP_BINARY, '-l', $engine . '/' . $member], $engine);
    }

    // Actually reverse a separate copy; a dry run alone does not prove byte conservation.
    $inverse = $output . '/inverse-check';
    ensure(mkdir($inverse, 0700), 'Cannot create inverse check');
    foreach (MEMBERS as $member => $pins) {
        $target = $inverse . '/' . $member;
        if (!is_dir(dirname($target))) {
            ensure(mkdir(dirname($target), 0700, true), 'Cannot create inverse member directory');
        }
        ensure(copy($engine . '/' . $member, $target), 'Cannot copy inverse member');
    }
    run([...$arguments, '--reverse'], $inverse);
    foreach (MEMBERS as $member => $pins) {
        ensure(hash_file('sha256', $inverse . '/' . $member) === $pins['original'], 'Inverse failed byte conservation: ' . $member);
        ensure(hash_file('sha256', $output . '/original/' . basename($member)) === $pins['original'], 'Immutable original changed');
    }
    ensure(hash_file('sha256', $source) === UPSTREAM_PHAR_SHA256, 'Input PHAR changed');
    ensure(hash_file('sha256', $originalPhar) === UPSTREAM_PHAR_SHA256, 'Immutable upstream PHAR changed');
    ensure(mkdir($output . '/bin', 0700), 'Cannot create isolated CLI directory');
    $launcher = $output . '/bin/psalm';
    ensure(file_put_contents($launcher, "#!/usr/bin/env php\n<?php\nrequire dirname(__DIR__) . '/engine/psalm';\n") !== false && chmod($launcher, 0700), 'Cannot create isolated CLI launcher');
    run([PHP_BINARY, $launcher, '--version'], $engine);
    $manifest = [
        'upstream_version' => '6.16.1', 'source_phar_sha256' => UPSTREAM_PHAR_SHA256,
        'immutable_upstream_phar' => $originalPhar,
        'patch_sha256' => PATCH_SHA256, 'members' => MEMBERS,
        'inverse_byte_conservation' => true, 'entrypoint' => $engine . '/psalm',
        'executable' => $launcher, 'launcher_sha256' => hash_file('sha256', $launcher),
        'scope' => 'isolated build only; consumers are not changed',
    ];
    ensure(file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") !== false, 'Cannot write build manifest');
    run([PHP_BINARY, __DIR__ . '/patch-psalm-callable-taint.php', $output], $engine);
    echo 'Built isolated Psalm: ', $engine, "/psalm\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
