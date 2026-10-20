<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tools/refresh-dependencies.php';

function dependencyTest(bool $passed, string $label): void
{
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . "\n";
}

/** @param list<string> $copies */
function dependencyFixtureTree(string $source, string $target, array $copies): void
{
    mkdir($target, 0700);
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected fixture directory entry');
        }
        $name = $entry->getFilename();
        if (in_array($name, ['.git', 'vendor', 'node_modules'], true)) {
            continue;
        }
        $under = array_values(array_filter($copies, static fn (string $path): bool => str_starts_with($path, $name . '/')));
        if ($under !== []) {
            dependencyFixtureTree(
                $entry->getPathname(),
                $target . '/' . $name,
                array_map(static fn (string $path): string => substr($path, strlen($name) + 1), $under)
            );
        } elseif (in_array($name, $copies, true)) {
            copy($entry->getPathname(), $target . '/' . $name);
        } else {
            symlink($entry->getPathname(), $target . '/' . $name);
        }
    }
}

function dependencyRemoveFixture(string $path): void
{
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected cleanup directory entry');
        }
        if ($entry->isDir() && !$entry->isLink()) {
            dependencyRemoveFixture($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

/** @return array<string,string> */
function dependencyInputs(string $root): array
{
    $result = [];
    foreach (['composer.lock', 'docs/ORM3-UPGRADE.md', 'CHANGELOG', 'README.md'] as $path) {
        $result[$path] = dependencyRead($root . '/' . $path);
    }
    return $result;
}

function dependencyReject(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        dependencyTest(str_contains($error->getMessage(), $message), 'rejected: ' . $message);
        return;
    }
    throw new RuntimeException('FAIL: accepted ' . $message);
}

$source = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/vimbadmin-dependency-refresh-' . bin2hex(random_bytes(8));
$copies = ['composer.lock', 'docs/ORM3-UPGRADE.md', 'CHANGELOG', 'README.md', '.github/scripts/assert-composer-lock.php',
    'tests/test-documentation-contract.php', '.github/workflows/ci.yml', '.github/scripts/check-dependency-refresh.sh', 'tools/refresh-dependencies.php'];
dependencyFixtureTree($source, $directory, $copies);
try {
    $original = dependencyInputs($directory);
    $basePath = $directory . '/base.lock';
    file_put_contents($basePath, $original['composer.lock']);
    $base = dependencyLock($original['composer.lock'], true);
    $baseVersions = [];
    foreach ($base['packages'] as $package) {
        $baseVersions[$package['name']] = ltrim($package['version'], 'v');
    }
    foreach (['.github/scripts/assert-composer-lock.php' => 'integrity', 'tests/test-documentation-contract.php' => 'documentation'] as $guard => $name) {
        $path = $directory . '/' . $guard;
        $text = dependencyRead($path);
        $marker = 'declare(strict_types=1);';
        file_put_contents($path, str_replace($marker, $marker . "\nfile_put_contents(" . var_export($directory . '/guard-calls', true) . ', ' . var_export($name . "\n", true) . ', FILE_APPEND);', $text));
    }
    $fetches = [];
    $fetch = static function (string $url) use (&$fetches): string {
        $fetches[] = $url;
        return 'independent fixture archive bytes: ' . $url;
    };
    $reset = static function () use ($directory, $original): void {
        foreach ($original as $path => $bytes) {
            file_put_contents($directory . '/' . $path, $bytes);
        }
        if (is_file($directory . '/guard-calls')) {
            unlink($directory . '/guard-calls');
        }
    };
    refreshDependencies($directory, $basePath, true, $fetch);
    dependencyTest($fetches === [] && dependencyInputs($directory) === $original, 'unchanged candidate is byte-identical without fetch');
    dependencyTest(is_file($directory . '/guard-calls') && dependencyRead($directory . '/guard-calls') === "integrity\ndocumentation\n", 'both existing guards actually execute');

    $candidate = $base;
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($candidate[$section] as &$package) {
            $package['dist']['shasum'] = '';
        }
        unset($package);
    }
    $blankPins = json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($directory . '/composer.lock', $blankPins);
    $before = dependencyInputs($directory);
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, true, $fetch), 'needs refresh');
    dependencyTest(dependencyInputs($directory) === $before, 'check mode restores unrefreshed candidate');
    refreshDependencies($directory, $basePath, false, $fetch);
    $restored = dependencyLock(dependencyRead($directory . '/composer.lock'), true);
    dependencyTest($restored === $base && $fetches === [], 'all unchanged-URL pins survive upstream removal');

    $reset();
    $candidate = $base;
    $changed = [];
    foreach ($candidate['packages'] as &$package) {
        if ($package['name'] === 'doctrine/orm' || $package['name'] === 'doctrine/dbal') {
            $package['version'] = $package['name'] === 'doctrine/orm' ? '3.99.1' : '4.99.2';
            $package['dist']['url'] = substr($package['dist']['url'], 0, -40) . str_repeat($package['name'] === 'doctrine/orm' ? 'a' : 'b', 40);
            $package['dist']['shasum'] = '';
            $changed[$package['name']] = $package['dist']['url'];
        }
    }
    unset($package);
    $changedLock = json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($directory . '/composer.lock', $changedLock);
    $before = dependencyInputs($directory);
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, true, $fetch), 'needs refresh');
    dependencyTest(dependencyInputs($directory) === $before, 'cross-minor check mode restores lock, upgrade guide, changelog and README byte-for-byte');
    dependencyTest(is_file($directory . '/guard-calls') && dependencyRead($directory . '/guard-calls') === "integrity\ndocumentation\n", 'cross-minor check mode executes both real guards before rollback');
    unlink($directory . '/guard-calls');
    refreshDependencies($directory, $basePath, false, $fetch);
    $updated = dependencyInputs($directory);
    $verified = dependencyLock($updated['composer.lock'], true);
    foreach ($verified['packages'] as $package) {
        if (isset($changed[$package['name']])) {
            dependencyTest($package['dist']['shasum'] === sha1('independent fixture archive bytes: ' . $changed[$package['name']]), 'changed archive independently hashed: ' . $package['name']);
        }
    }
    dependencyTest($updated['docs/ORM3-UPGRADE.md'] === str_replace(['ORM ' . $baseVersions['doctrine/orm'] . ' and DBAL ' . $baseVersions['doctrine/dbal']], ['ORM 3.99.1 and DBAL 4.99.2'], $original['docs/ORM3-UPGRADE.md'])
        && $updated['CHANGELOG'] === str_replace(['Doctrine ORM 2.8 -> ' . $baseVersions['doctrine/orm'] . ' (+ DBAL ' . $baseVersions['doctrine/dbal'] . ')'], ['Doctrine ORM 2.8 -> 3.99.1 (+ DBAL 4.99.2)'], $original['CHANGELOG']), 'only exact existing documentation pairs change');
    $baseOrmLine = implode('.', array_slice(explode('.', $baseVersions['doctrine/orm']), 0, 2));
    dependencyTest(
        $updated['README.md'] === str_replace(
            'Current release lines (doctrine/orm ' . $baseOrmLine . ', DBAL 4,',
            'Current release lines (doctrine/orm 3.99, DBAL 4,',
            $original['README.md']
        ) && $updated['README.md'] !== $original['README.md'],
        'cross-minor refresh changes only the exact README release-line pair'
    );
    dependencyTest(is_file($directory . '/guard-calls') && dependencyRead($directory . '/guard-calls') === "integrity\ndocumentation\n", 'changed candidate reaches both real guards');
    $fetches = [];
    refreshDependencies($directory, $basePath, true, $fetch);
    dependencyTest(count($fetches) === 2 && dependencyInputs($directory) === $updated, 'changed URLs are fetched again and verified against populated pins');
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, static fn (): string => 'wrong archive bytes'), 'checksum mismatch');
    dependencyTest(dependencyInputs($directory) === $updated, 'checksum mismatch preserves all inputs');
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, static fn (): never => throw new RuntimeException('fixture fetch failed')), 'fetch failed');


    $reset();
    $candidate = $base;
    $candidate['packages'][0]['dist']['shasum'] = str_repeat('0', 40);
    file_put_contents($directory . '/composer.lock', json_encode($candidate, JSON_THROW_ON_ERROR));
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'unchanged archive URL');
    $reset();
    foreach (['not-json', '{}', '{"packages":{},"packages-dev":[]}', '{"packages":[],"packages-dev":[]}'] as $bad) {
        file_put_contents($directory . '/composer.lock', $bad);
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), $bad === 'not-json' ? 'Syntax error' : ($bad === '{"packages":[],"packages-dev":[]}' ? 'ORM3/DBAL4' : 'package'));
    }
    $reset();
    $duplicate = $base;
    $duplicate['packages'][] = $base['packages'][0];
    $mutations = [
        'unsupported URL' => str_replace($base['packages'][0]['dist']['url'], 'http://127.0.0.1/archive.zip', $original['composer.lock']),
        'bad checksum' => str_replace($base['packages'][0]['dist']['shasum'], 'bad', $original['composer.lock']),
        'duplicate identity' => json_encode($duplicate, JSON_THROW_ON_ERROR),
        'missing dist' => str_replace('"dist":', '"missing-dist":', $original['composer.lock']),
        'unsupported major' => str_replace('"version": "' . $baseVersions['doctrine/orm'] . '"', '"version": "4.0.0"', $original['composer.lock']),
    ];
    foreach ($mutations as $name => $contents) {
        file_put_contents($directory . '/composer.lock', $contents);
        $before = dependencyInputs($directory);
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), match ($name) {
            'unsupported URL' => 'Unsupported distribution', 'bad checksum' => 'checksum', 'unsupported major' => 'ORM3/DBAL4', default => 'malformed or duplicated',
        });
        dependencyTest(dependencyInputs($directory) === $before, $name . ' fails before writes');
    }
    $reset();
    $untrustedBase = $base;
    $untrustedBase['packages'][0]['dist']['shasum'] = '';
    file_put_contents($basePath, json_encode($untrustedBase, JSON_THROW_ON_ERROR));
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'Trusted pin missing');
    file_put_contents($basePath, $original['composer.lock']);
    dependencyReject(static fn () => refreshDependencies($directory, $basePath . '.missing', false, $fetch), 'cannot be read');
    foreach (['docs/ORM3-UPGRADE.md', 'CHANGELOG', 'README.md'] as $surface) {
        foreach (['missing' => 'missing version pair', 'ambiguous' => $original[$surface] . "\n" . $original[$surface]] as $case => $bytes) {
            $reset();
            file_put_contents($directory . '/' . $surface, $bytes);
            $before = dependencyInputs($directory);
            dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'pair is missing or ambiguous');
            dependencyTest(dependencyInputs($directory) === $before, $surface . ' ' . $case . ' version pair preserves every input byte');
        }
    }
    $reset();
    foreach (['.github/scripts/assert-composer-lock.php', 'tests/test-documentation-contract.php'] as $guard) {
        $path = $directory . '/' . $guard;
        $saved = dependencyRead($path);
        file_put_contents($directory . '/composer.lock', $changedLock);
        $before = dependencyInputs($directory);
        file_put_contents($path, str_replace('declare(strict_types=1);', 'declare(strict_types=1); exit(37);', $saved));
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'Dependency guard failed: ' . $guard);
        dependencyTest(dependencyInputs($directory) === $before, $guard . ' failure rolls back lock, upgrade guide, changelog and README byte-for-byte');
        unlink($path);
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'guard is missing');
        file_put_contents($path, $saved);
        $reset();
    }
    $reset();
    $workflow = dependencyRead($source . '/.github/workflows/ci.yml');
    dependencyTest(preg_match('~^        run: bash \.github/scripts/check-dependency-refresh\.sh$~m', $workflow) === 1
        && str_contains($workflow, "if: >-\n          github.event_name == 'pull_request' &&\n          github.event.pull_request.user.login == 'dependabot[bot]'")
        && str_contains($workflow, 'DEPENDENCY_BASE_SHA: ${{ github.event.pull_request.base.sha }}'), 'real Dependabot workflow invokes owned refresh check at immutable base');
    mkdir($directory . '/stub-bin');
    $stub = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  fetch) exit "${DEPENDENCY_TEST_FETCH_EXIT:-0}" ;;
  show) cat "$DEPENDENCY_TEST_BASE_LOCK" ;;
  *) exit 98 ;;
esac
SH;
    file_put_contents($directory . '/stub-bin/git', $stub . "\n");
    chmod($directory . '/stub-bin/git', 0700);
    $runAutomation = static function (int $fetchExit = 0) use ($directory, $basePath): int {
        $environment = getenv();
        $environment['PATH'] = $directory . '/stub-bin:' . ($environment['PATH'] ?? '');
        $environment['DEPENDENCY_BASE_SHA'] = str_repeat('c', 40);
        $environment['DEPENDENCY_TEST_BASE_LOCK'] = $basePath;
        $environment['DEPENDENCY_TEST_FETCH_EXIT'] = (string) $fetchExit;
        $process = proc_open(
            ['bash', $directory . '/.github/scripts/check-dependency-refresh.sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/automation.stdout', 'w'],
                2 => ['file', $directory . '/automation.stderr', 'w']],
            $pipes,
            $directory,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not invoke actual dependency workflow helper');
        }
        return proc_close($process);
    };
    dependencyTest($runAutomation() === 0, 'actual automation helper runs maintained command');
    dependencyTest(is_file($directory . '/guard-calls') && dependencyRead($directory . '/guard-calls') === "integrity\ndocumentation\n", 'automation executes both actual guards');
    file_put_contents($directory . '/composer.lock', $blankPins);
    $before = dependencyInputs($directory);
    dependencyTest($runAutomation() !== 0 && str_contains(dependencyRead($directory . '/automation.stderr'), 'needs refresh'), 'automation propagates missing-pin repair failure');
    dependencyTest(dependencyInputs($directory) === $before, 'automation check restores candidate bytes');
    $reset();
    dependencyTest($runAutomation(29) === 29, 'automation propagates trusted-base fetch failure');
    $guardPath = $directory . '/tests/test-documentation-contract.php';
    $guardBytes = dependencyRead($guardPath);
    file_put_contents($guardPath, str_replace('declare(strict_types=1);', 'declare(strict_types=1); exit(37);', $guardBytes));
    dependencyTest($runAutomation() !== 0 && str_contains(dependencyRead($directory . '/automation.stderr'), 'Dependency guard failed'), 'automation propagates real documentation guard failure');
    file_put_contents($guardPath, $guardBytes);
    $reset();
    file_put_contents($directory . '/composer.lock', $changedLock);
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, static fn (): string => ''), 'empty or exceeds size bound');
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, static fn (): string => str_repeat('x', 52428801)), 'empty or exceeds size bound');
    $curlStub = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" >"${DEPENDENCY_TEST_CURL_ARGS:?missing argv evidence}"
if [[ ${DEPENDENCY_TEST_CURL_EXIT:-0} -ne 0 ]]; then exit "$DEPENDENCY_TEST_CURL_EXIT"; fi
output=
while [[ $# -gt 0 ]]; do
  if [[ $1 == --output ]]; then output=$2; shift; fi
  shift
done
if [[ ${DEPENDENCY_TEST_ARCHIVE_BIG:-0} == 1 ]]; then
  head -c 52428801 /dev/zero >"${output:?missing output}"
else
  printf '%s' "${DEPENDENCY_TEST_ARCHIVE-download fixture}" >"${output:?missing output}"
fi
SH;
    file_put_contents($directory . '/stub-bin/curl', $curlStub . "\n");
    chmod($directory . '/stub-bin/curl', 0700);
    $pathBefore = getenv('PATH');
    if (!is_string($pathBefore)) {
        throw new RuntimeException('Fixture PATH is unavailable');
    }
    putenv('PATH=' . $directory . '/stub-bin:' . $pathBefore);
    putenv('DEPENDENCY_TEST_CURL_ARGS=' . $directory . '/curl-argv');
    $downloadsBefore = glob(sys_get_temp_dir() . '/vimbadmin-dependency-*');
    try {
        dependencyTest(dependencyArchive($base['packages'][0]['dist']['url']) === 'download fixture', 'actual curl argv downloader reads archive bytes');
        $argvBytes = dependencyRead($directory . '/curl-argv');
        dependencyTest(str_starts_with($argvBytes, "--disable\n--fail\n") && str_contains($argvBytes, "--proto\n=https\n--proto-redir\n=https\n--max-redirs\n3\n")
            && str_contains($argvBytes, "--max-filesize\n52428800\n"), 'curl enforces HTTPS redirects and50MiB argv bounds');
        dependencyReject(static fn () => dependencyArchive('http://127.0.0.1/archive.zip'), 'Unsupported distribution');
        dependencyTest(dependencyRead($directory . '/curl-argv') === $argvBytes, 'unsupported URL never reaches transport');
        putenv('DEPENDENCY_TEST_CURL_EXIT=22');
        dependencyReject(static fn () => dependencyArchive($base['packages'][0]['dist']['url']), 'fetch failed');
        putenv('DEPENDENCY_TEST_CURL_EXIT=0');
        putenv('DEPENDENCY_TEST_ARCHIVE=');
        dependencyReject(static fn () => dependencyArchive($base['packages'][0]['dist']['url']), 'empty or exceeds size bound');
        putenv('DEPENDENCY_TEST_ARCHIVE_BIG=1');
        dependencyReject(static fn () => dependencyArchive($base['packages'][0]['dist']['url']), 'empty or exceeds size bound');
        dependencyTest(glob(sys_get_temp_dir() . '/vimbadmin-dependency-*') === $downloadsBefore, 'success/failure/oversize transport files are cleaned');
    } finally {
        putenv('PATH=' . $pathBefore);
        putenv('DEPENDENCY_TEST_CURL_EXIT');
        putenv('DEPENDENCY_TEST_ARCHIVE');
        putenv('DEPENDENCY_TEST_ARCHIVE_BIG');
        putenv('DEPENDENCY_TEST_CURL_ARGS');
    }
    $reset();
    foreach (['CHANGELOG', 'README.md'] as $surface) {
        unlink($directory . '/' . $surface);
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'input is missing or escapes checkout');
        symlink($source . '/' . $surface, $directory . '/' . $surface);
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'input is missing or escapes checkout');
        unlink($directory . '/' . $surface);
        file_put_contents($directory . '/' . $surface, $original[$surface]);
    }
    $reset();
    dependencyReject(static fn () => refreshDependencies($directory . '.missing', $basePath, false, $fetch), 'checkout root is missing');
    $development = $base;
    $developmentIndex = array_search('dasprid/enum', array_column($development['packages'], 'name'), true);
    if (!is_int($developmentIndex)) {
        throw new RuntimeException('Production fixture needs dasprid/enum');
    }
    $developmentPackage = array_splice($development['packages'], $developmentIndex, 1)[0];
    $development['packages-dev'][] = $developmentPackage;
    file_put_contents($directory . '/composer.lock', json_encode($development, JSON_THROW_ON_ERROR));
    refreshDependencies($directory, $basePath, true, $fetch);
    dependencyTest(dependencyLock(dependencyRead($directory . '/composer.lock'), true) === $development, 'development distributions retain named-base pins');
    $reset();
    file_put_contents($directory . '/composer.lock', str_replace('"type": "zip"', '"type": "tar"', $original['composer.lock']));
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'malformed or duplicated');
    $extraField = $base;
    $extraField['unexpected'] = ['shasum' => ''];
    file_put_contents($directory . '/composer.lock', json_encode($extraField, JSON_THROW_ON_ERROR));
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'Unexpected checksum field');
    file_put_contents($directory . '/composer.lock', str_replace('"shasum":', '"\u0073hasum":', $original['composer.lock']));
    dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $fetch), 'field layout is unsupported');
    $reset();
    file_put_contents($directory . '/composer.lock', $changedLock);
    $injectedWriteFailure = false;
    $breakWrite = static function (string $url) use ($directory, &$injectedWriteFailure): string {
        if (!$injectedWriteFailure) {
            rename($directory . '/CHANGELOG', $directory . '/CHANGELOG.saved');
            mkdir($directory . '/CHANGELOG');
            $injectedWriteFailure = true;
        }
        return 'independent fixture archive bytes: ' . $url;
    };
    try {
        dependencyReject(static fn () => refreshDependencies($directory, $basePath, false, $breakWrite), 'Could not restore dependency-refresh input');
    } finally {
        if ($injectedWriteFailure) {
            rmdir($directory . '/CHANGELOG');
            rename($directory . '/CHANGELOG.saved', $directory . '/CHANGELOG');
        }
        $reset();
    }

    file_put_contents($directory . '/composer.lock', $changedLock);
    $beforeRestoreFailure = dependencyInputs($directory);
    $guard = $directory . '/.github/scripts/assert-composer-lock.php';
    $originalGuard = dependencyRead($guard);
    $locked = $directory . '/composer.lock';
    $savedLock = $directory . '/composer.lock.saved';
    $injection = "declare(strict_types=1);\nrename(" . var_export($locked, true) . ', ' . var_export($savedLock, true)
        . ");\nmkdir(" . var_export($locked, true) . ");\nexit(37);";
    file_put_contents($guard, str_replace('declare(strict_types=1);', $injection, $originalGuard));
    try {
        $restoreFailure = null;
        try {
            refreshDependencies($directory, $basePath, false, $fetch);
        } catch (Throwable $error) {
            $restoreFailure = $error;
        }
        dependencyTest(
            $restoreFailure instanceof RuntimeException
            && str_contains($restoreFailure->getMessage(), 'Could not restore dependency-refresh input')
            && $restoreFailure->getPrevious() instanceof RuntimeException
            && str_contains($restoreFailure->getPrevious()->getMessage(), 'Dependency guard failed'),
            'restore failure retains the guard failure as its cause'
        );
        dependencyTest(
            dependencyRead($directory . '/docs/ORM3-UPGRADE.md') === $beforeRestoreFailure['docs/ORM3-UPGRADE.md']
            && dependencyRead($directory . '/CHANGELOG') === $beforeRestoreFailure['CHANGELOG']
            && dependencyRead($directory . '/README.md') === $beforeRestoreFailure['README.md'],
            'restore continues to later inputs after the first failure'
        );
    } finally {
        if (is_dir($locked)) {
            rmdir($locked);
        }
        if (is_file($savedLock)) {
            rename($savedLock, $locked);
        }
        file_put_contents($guard, $originalGuard);
        $reset();
    }
    echo "ALL PASSED\n";
} finally {
    dependencyRemoveFixture($directory);
}
