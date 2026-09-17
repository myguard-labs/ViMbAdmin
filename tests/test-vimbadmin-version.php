<?php

require __DIR__ . '/../library/ViMbAdmin/Version.php';

$failures = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '         expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n";
    }
};

echo "== ViMbAdmin version metadata ==\n";

// gitCommit(): ref-path hardening (CWE-22) and memoization.
// These drive the real method against a temp tree via its $root test seam,
// so mutating the guard in Version.php turns them red.
echo "\n== gitCommit() memoization and ref path hardening ==\n";

$tmpDir = sys_get_temp_dir() . '/vimbadmin-test-' . bin2hex(random_bytes(8));
$rmTree = static function (string $dir) use (&$rmTree): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? $rmTree($p) : @unlink($p);
    }
    @rmdir($dir);
};
// Write $head into a fresh tree and return what gitCommit() makes of it.
$probe = static function (string $head, ?string $refFile, ?string $refBody, ?string $planted = null) use ($tmpDir, $rmTree) {
    $rmTree($tmpDir);
    @mkdir($tmpDir . '/.git', 0700, true);
    // A readable file OUTSIDE .git, reachable only by traversing out of it.
    // refs/heads/ must physically exist or the '..' hops cannot resolve at all,
    // which would make the probe pass for the wrong reason.
    if ($planted !== null) {
        @mkdir($tmpDir . '/.git/refs/heads', 0700, true);
        file_put_contents($tmpDir . '/planted', $planted . "\n");
    }
    file_put_contents($tmpDir . '/.git/HEAD', $head);
    if ($refFile !== null) {
        @mkdir(dirname($tmpDir . '/.git/' . $refFile), 0700, true);
        file_put_contents($tmpDir . '/.git/' . $refFile, $refBody);
    }
    return ViMbAdmin_Version::gitCommit($tmpDir);
};

$sha = 'abcdef0123456789abcdef0123456789abcdef01';

try {
    // Accepts a normal ref and returns the SHA it points at.
    $check(
        'normal ref resolves to its SHA',
        $probe("ref: refs/heads/master\n", 'refs/heads/master', $sha . "\n"),
        $sha
    );

    // Traversal out of .git is refused even though the target IS readable --
    // 'planted' sits outside .git, so a null here can only come from the guard,
    // never from an unreadable path.
    $planted = 'cafebabe0123456789cafebabe0123456789cafe';
    $check(
        'traversal ref is rejected even when its target is readable',
        $probe("ref: refs/heads/../../../planted\n", null, null, $planted),
        null
    );
    $check(
        'traversal ref with single .. is rejected',
        $probe("ref: refs/../../planted\n", null, null, $planted),
        null
    );
    // Control: without traversal, that same planted content IS reachable,
    // proving the probe can see the file and the rejection above is the guard.
    $check(
        'non-traversing ref reaches a planted target',
        $probe("ref: refs/heads/planted\n", 'refs/heads/planted', $planted . "\n"),
        $planted
    );

    // A ref outside the refs/ namespace is refused. 'planted' is placed at the
    // exact path the ref names and IS readable, so null can only be the ^refs/
    // prefix constraint -- not a missing file. This is what pins the regex
    // independently of the '..' check.
    $check(
        'non-refs ref is rejected even when its target is readable',
        $probe("ref: config\n", 'config', $planted . "\n"),
        null
    );
    $check(
        'absolute-looking ref is rejected',
        $probe("ref: /etc/passwd\n", null, null),
        null
    );

    // A readable but corrupt ref file is not a commit SHA: it must not be
    // returned, and must not be memoized as one.
    $check(
        'corrupt loose ref is rejected',
        $probe("ref: refs/heads/master\n", 'refs/heads/master', "not-a-sha\n"),
        null
    );
    $check(
        'truncated loose ref is rejected',
        $probe("ref: refs/heads/master\n", 'refs/heads/master', "abcdef0\n"),
        null
    );

    // A detached 40-char SHA in HEAD is still honoured.
    $check(
        'detached HEAD sha is returned',
        $probe($sha . "\n", null, null),
        $sha
    );

    // Memoization: the seam path must NOT populate the production cache...
    $cacheProperty = new ReflectionProperty(ViMbAdmin_Version::class, '_gitCommitCache');
    $cacheProperty->setAccessible(true);
    $cacheProperty->setValue(null, false);
    $probe("ref: refs/heads/master\n", 'refs/heads/master', $sha . "\n");
    $check(
        'test seam does not poison the production memo',
        $cacheProperty->getValue(),
        false
    );

    // ...while the default path computes once and then serves the cache.
    $first = ViMbAdmin_Version::gitCommit();
    $check(
        'production call populates the memo',
        $cacheProperty->getValue(),
        $first
    );
    $cacheProperty->setValue(null, 'sentinel-from-cache');
    $check(
        'second production call is served from the memo',
        ViMbAdmin_Version::gitCommit(),
        'sentinel-from-cache'
    );
    $cacheProperty->setValue(null, false);
} finally {
    $rmTree($tmpDir);
    @unlink(sys_get_temp_dir() . '/vimbadmin-outside-target');
}

$commit = '0123456789abcdef0123456789abcdef01234567';
$shortCommit = new ReflectionMethod(ViMbAdmin_Version::class, '_shortCommit');
$check('build commit is shortened to twelve characters', $shortCommit->invoke(null, $commit), '0123456789ab');
$check('short build commits are not padded', $shortCommit->invoke(null, 'abcdef0'), 'abcdef0');
$check('missing build commit remains null', $shortCommit->invoke(null, null), null);

$decode = new ReflectionMethod(ViMbAdmin_Version::class, '_decodeGithubResponse');
$objectPayload = ['tag_name' => 'v4.0.1', 'metadata' => ['channel' => 'stable']];
$check(
    'GitHub object payload shape is retained',
    $decode->invoke(null, json_encode($objectPayload), ['HTTP/1.1 200 OK']),
    $objectPayload
);
$listPayload = [['name' => 'v4.0.1'], ['name' => 'v4.0.0']];
$check(
    'GitHub list payload shape is retained',
    $decode->invoke(null, json_encode($listPayload), ['HTTP/2 200 OK']),
    $listPayload
);
$check(
    'GitHub non-success status remains a failure',
    $decode->invoke(null, json_encode($objectPayload), ['HTTP/1.1 403 Forbidden']),
    null
);
$check(
    'malformed GitHub JSON remains a failure',
    $decode->invoke(null, '{', ['HTTP/1.1 200 OK']),
    null
);

final class FailingHttpsStream
{
    public mixed $context;

    /** @var list<array{path: string, mode: string, options: int, openedPath: string|null}> */
    public static array $calls = [];

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$calls[] = [
            'path' => $path,
            'mode' => $mode,
            'options' => $options,
            'openedPath' => $openedPath,
        ];
        return false;
    }
}

$github = new ReflectionMethod(ViMbAdmin_Version::class, '_github');
if (!stream_wrapper_unregister('https') || !stream_wrapper_register('https', FailingHttpsStream::class)) {
    fwrite(STDERR, "FAIL: could not install isolated HTTPS test wrapper\n");
    exit(1);
}
try {
    $check('network failure remains fail-soft', $github->invoke(null, 'releases/latest'), null);
    $check('network failure receives exactly one retry', count(FailingHttpsStream::$calls), 2);
    $check(
        'GitHub request path remains unchanged',
        FailingHttpsStream::$calls[0]['path'] ?? null,
        'https://api.github.com/repos/' . ViMbAdmin_Version::GITHUB_REPO . '/releases/latest'
    );
} finally {
    stream_wrapper_restore('https');
}

echo $failures === 0
    ? "OK: all ViMbAdmin version assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
