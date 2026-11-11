<?php

declare(strict_types=1);

/** Add callable-return edges only to the checked, named-patched Psalm 6.16.1 build. */
const CALLABLE_PHAR_SHA256 = '7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00';
const CALLABLE_PATCH_SHA256 = '76b6f93d83735482ac10fe572ce0b55fd26427e4973980fdf98e8e6b5d32980e';
const NAMED_PATCH_SHA256 = '66d1bab5fa68913eeca421d29890a6037f03df3ecd0dd52664c0990bda0ea3fc';
const NAMED_ARGUMENTS_SHA256 = '1c0ba8ba626104f8c983f4c34451503887c822652b11fd917b504cd46c09ec8f';
const CALLABLE_MEMBERS = [
    'src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php' => [
        'original' => '7226b6354f4373426d6b6b25b88d45726ac42b64cbcb6d8f83331a536dcaa08a',
        'patched' => 'ed870ddfc6402c3f06995cd9ffbf0a61a8db8bba94c32dd4ff829cd2582f356e',
    ],
    'src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php' => [
        'original' => '12e2cf9e7564b2a6866d10794a03e9f83fddd93026b6f4de06a0e3a3c3c4474c',
        'patched' => 'e54be128641ce2bb8bed11f53897e6f8e61a45d5368e8b0e984816825749483c',
    ],
];

function callableEnsure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function callableRun(array $command, string $cwd): void
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    callableEnsure(is_resource($process), 'Cannot start callable patch command');
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start callable patch command');
    }
    callableEnsure(proc_close($process) === 0, 'Callable patch command failed');
}

try {
    callableEnsure($argc === 2, 'Usage: php ci/patch-psalm-callable-taint.php NAMED_PATCHED_TOOL_DIRECTORY');
    $tool = realpath($argv[1]);
    callableEnsure(is_string($tool) && is_dir($tool), 'Missing named-patched tool');
    if (!is_string($tool)) {
        throw new RuntimeException('Missing named-patched tool');
    }
    $manifestPath = $tool . '/manifest.json';
    $manifestBytes = file_get_contents($manifestPath);
    if (!is_string($manifestBytes)) {
        throw new RuntimeException('Cannot read named patch manifest');
    }
    $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new RuntimeException('Invalid named patch manifest');
    }
    callableEnsure(
        ($manifest['upstream_version'] ?? null) === '6.16.1'
        && ($manifest['source_phar_sha256'] ?? null) === CALLABLE_PHAR_SHA256,
        'Unknown Psalm version or PHAR identity',
    );
    $archive = $tool . '/original/psalm-6.16.1.phar';
    callableEnsure(hash_file('sha256', $archive) === CALLABLE_PHAR_SHA256, 'Unknown actual Psalm PHAR bytes');
    callableEnsure(
        ($manifest['patch_sha256'] ?? null) === NAMED_PATCH_SHA256
        && ($manifest['inverse_byte_conservation'] ?? false) === true,
        'Unchecked named-argument prerequisite',
    );
    callableEnsure(!isset($manifest['callable_patch_sha256']), 'Refusing an already callable-patched tool');
    $engine = $tool . '/engine';
    $namedArguments = 'src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentsAnalyzer.php';
    callableEnsure(hash_file('sha256', $engine . '/' . $namedArguments) === NAMED_ARGUMENTS_SHA256, 'Named prerequisite member mismatch');
    $patch = __DIR__ . '/psalm-callable-return-taint.patch';
    callableEnsure(hash_file('sha256', $patch) === CALLABLE_PATCH_SHA256, 'Unknown callable patch bytes');
    foreach (CALLABLE_MEMBERS as $member => $pins) {
        callableEnsure(is_file($engine . '/' . $member), 'Missing callable member: ' . $member);
        callableEnsure(hash_file('sha256', $engine . '/' . $member) === $pins['original'], 'Unknown callable member: ' . $member);
    }
    foreach (['callable-original', 'callable-inverse'] as $directory) {
        callableEnsure(!file_exists($tool . '/' . $directory) && !is_link($tool . '/' . $directory), 'Refusing existing callable output');
    }

    $phar = new Phar($archive);
    $functionLike = 'src/Psalm/Internal/Analyzer/FunctionLikeAnalyzer.php';
    callableEnsure(hash('sha256', $phar[$functionLike]->getContent()) === CALLABLE_MEMBERS[$functionLike]['original'], 'Unknown official callable member');
    $originalFunctionLike = $tool . '/original/FunctionLikeAnalyzer.php';
    callableEnsure(!file_exists($originalFunctionLike), 'Refusing existing original callable member');
    callableEnsure(copy($engine . '/' . $functionLike, $originalFunctionLike) && chmod($originalFunctionLike, 0400), 'Cannot preserve official callable member');
    callableEnsure(mkdir($tool . '/callable-original', 0700), 'Cannot preserve callable preimages');
    foreach (CALLABLE_MEMBERS as $member => $pins) {
        $original = $tool . '/callable-original/' . basename($member);
        callableEnsure(copy($engine . '/' . $member, $original) && chmod($original, 0400), 'Cannot preserve callable preimage');
    }
    $arguments = ['patch', '--batch', '--fuzz=0', '-p1', '--input=' . $patch];
    callableRun([...$arguments, '--forward', '--dry-run'], $engine);
    callableRun([...$arguments, '--forward'], $engine);
    foreach (CALLABLE_MEMBERS as $member => $pins) {
        callableEnsure(hash_file('sha256', $engine . '/' . $member) === $pins['patched'], 'Callable patched member mismatch');
        callableRun([PHP_BINARY, '-l', $engine . '/' . $member], $engine);
    }
    $inverse = $tool . '/callable-inverse';
    callableEnsure(mkdir($inverse, 0700), 'Cannot create callable inverse');
    foreach (CALLABLE_MEMBERS as $member => $pins) {
        $target = $inverse . '/' . $member;
        if (!is_dir(dirname($target))) {
            callableEnsure(mkdir(dirname($target), 0700, true), 'Cannot create callable inverse directory');
        }
        callableEnsure(copy($engine . '/' . $member, $target), 'Cannot copy callable inverse');
    }
    callableRun([...$arguments, '--reverse'], $inverse);
    foreach (CALLABLE_MEMBERS as $member => $pins) {
        callableEnsure(hash_file('sha256', $inverse . '/' . $member) === $pins['original'], 'Callable inverse byte mismatch');
        callableEnsure(hash_file('sha256', $tool . '/callable-original/' . basename($member)) === $pins['original'], 'Callable immutable preimage changed');
    }
    callableEnsure(hash_file('sha256', $archive) === CALLABLE_PHAR_SHA256, 'Immutable Psalm PHAR changed');
    $manifest['callable_patch_sha256'] = CALLABLE_PATCH_SHA256;
    $manifest['callable_inverse_byte_conservation'] = true;
    $manifest['callable_members'] = CALLABLE_MEMBERS;
    $manifest['members'] = [
        $namedArguments => ['original' => '68ff6086f19a691712b97c2c499f12385e1640789a658c00a00cf02e4afcfafc', 'patched' => NAMED_ARGUMENTS_SHA256],
        'src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php' => ['original' => '0e07017e894b3163f7069c7f1beb1e1bf6773f5dc9986a19b58b172da37a6fe7', 'patched' => CALLABLE_MEMBERS['src/Psalm/Internal/Analyzer/Statements/Expression/Call/ArgumentAnalyzer.php']['patched']],
        $functionLike => CALLABLE_MEMBERS[$functionLike],
    ];
    $manifest['scope'] = 'isolated named-argument and callable-return build; consumers are not changed';
    callableEnsure(file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") !== false, 'Cannot write composed manifest');
    echo "Applied guarded callable-return correction with inverse byte conservation\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
