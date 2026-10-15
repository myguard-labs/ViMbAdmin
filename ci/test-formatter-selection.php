<?php

declare(strict_types=1);

/** The effective formatter finder excludes generated proxies and keeps source roots. */
$root = dirname(__DIR__);
$proxyDirectory = $root . '/application/Proxies';
$proxyDirectoryExisted = is_dir($proxyDirectory);
$nearMissDirectory = $root . '/tools/Proxies';
$nearMissDirectoryExisted = is_dir($nearMissDirectory);
$proxyFile = $proxyDirectory . '/__CG__FormatterSelection' . bin2hex(random_bytes(8)) . '.php';
$nearMissFile = $nearMissDirectory . '/FormatterSelectionNeighbor' . bin2hex(random_bytes(8)) . '.php';

try {
    if (!$proxyDirectoryExisted && !mkdir($proxyDirectory, 0755, true) && !is_dir($proxyDirectory)) {
        throw new RuntimeException('could not create generated proxy fixture directory');
    }
    if (!$nearMissDirectoryExisted && !mkdir($nearMissDirectory, 0755, true) && !is_dir($nearMissDirectory)) {
        throw new RuntimeException('could not create unrelated proxy-named fixture directory');
    }
    $contents = "<?php\n\nfinal class FormatterSelectionProxyFixture {}\n";
    if (file_put_contents($proxyFile, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('could not write generated proxy fixture');
    }
    if (file_put_contents($nearMissFile, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('could not write unrelated proxy-named fixture');
    }

    $process = proc_open(
        ['php-cs-fixer', 'list-files', '--config', '.php-cs-fixer.dist.php'],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start PHP-CS-Fixer selection command');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('effective formatter finder failed: ' . $output);
    }

    $selected = [];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $file) {
        $file = trim($file, "'\" ");
        if (!str_starts_with($file, './')) {
            continue;
        }
        $file = substr($file, 2);
        $selected[$file] = true;
    }

    if (isset($selected['application/Proxies/' . basename($proxyFile)])) {
        throw new RuntimeException('generated Doctrine proxy is selected by PHP-CS-Fixer');
    }
    if (!isset($selected['tools/Proxies/' . basename($nearMissFile)])) {
        throw new RuntimeException('unrelated PHP under another Proxies directory is not selected');
    }

    $process = proc_open(
        ['git', 'ls-files', '-z', '--', '*.php'],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start tracked PHP source query');
    }
    $trackedOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('tracked PHP source query failed: ' . $trackedOutput);
    }
    $trackedFiles = array_fill_keys(explode("\0", rtrim($trackedOutput, "\0")), true);

    $representatives = [
        '.github' => '.github/scripts/assert-composer-lock.php',
        'application' => 'application/Entities/Admin.php',
        'bin' => 'bin/doctrine-cli.php',
        'ci' => 'ci/test-access-cache-invalidation.php',
        'library' => 'library/OSS/Auth/Password.php',
        'public' => 'public/index.php',
        'src' => 'src/Kernel/Bootstrap.php',
        'tests' => 'tests/render-alias-list-fixture.php',
        'tools' => 'tools/phpstan-codemod.php',
    ];
    foreach ($representatives as $directory => $file) {
        if (!isset($trackedFiles[$file])) {
            throw new RuntimeException('representative is not tracked in ' . $directory . ': ' . $file);
        }
        if (!isset($selected[$file])) {
            throw new RuntimeException('tracked PHP representative is not selected in ' . $directory . ': ' . $file);
        }
    }

    foreach (['preload.php', '.php-cs-fixer.dist.php'] as $file) {
        if (!isset($selected[$file])) {
            throw new RuntimeException('explicitly appended PHP file is not selected: ' . $file);
        }
    }

    echo "ALL PASSED: generated proxies are excluded and tracked formatter roots remain selected\n";
} finally {
    if (is_file($proxyFile)) {
        unlink($proxyFile);
    }
    if (is_file($nearMissFile)) {
        unlink($nearMissFile);
    }
    if (!$proxyDirectoryExisted && is_dir($proxyDirectory)) {
        rmdir($proxyDirectory);
    }
    if (!$nearMissDirectoryExisted && is_dir($nearMissDirectory)) {
        rmdir($nearMissDirectory);
    }
}
