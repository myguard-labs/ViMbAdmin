<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Exception.php';
require __DIR__ . '/../library/OSS/Runtime.php';
require __DIR__ . '/../library/ViMbAdmin/Net.php';
require __DIR__ . '/../library/ViMbAdmin/Doveadm.php';
require __DIR__ . '/../library/ViMbAdmin/BruteForce.php';
require __DIR__ . '/support/bruteforce-state-path.php';

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$invoke = static function (string $class, string $method, mixed ...$args): mixed {
    return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
};
$fails = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception->getMessage() === $message;
    }
    return false;
};

echo "== security runtime input boundaries ==\n";

$doveadm = ViMbAdmin_Doveadm::fromOptions([
    'doveadm' => ['http' => ['url' => 'http://dovecot.example.test/v1', 'api_key' => 'secret', 'timeout' => '30']],
]);
$check('doveadm accepts canonical configured credentials and timeout', $doveadm instanceof ViMbAdmin_Doveadm);
$check('doveadm preserves leading-zero numeric timeout configuration', ViMbAdmin_Doveadm::fromOptions([
    'doveadm' => ['http' => ['url' => 'http://dovecot.example.test/v1', 'api_key' => 'secret', 'timeout' => '0030']],
]) instanceof ViMbAdmin_Doveadm);
$check('doveadm preserves the missing-configuration error', $fails(
    static fn (): mixed => ViMbAdmin_Doveadm::fromOptions([]),
    'doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)',
));
$check('doveadm rejects malformed nested credentials before construction', $fails(
    static fn (): mixed => ViMbAdmin_Doveadm::fromOptions(['doveadm' => ['http' => ['url' => ['bad'], 'api_key' => 'secret']]]),
    'doveadm.http.url must be a string',
));

$check('brute-force integer policy rejects lossy values', $fails(
    static fn (): mixed => $invoke(ViMbAdmin_BruteForce::class, 'intValue', '5junk', 'bruteforce.max_attempts', 1),
    'bruteforce.max_attempts must be a non-negative integer',
));
$check('brute-force preserves INI string false configuration', !(new ViMbAdmin_BruteForce(null, ['enabled' => '0']))->isLocked(null));
$check(
    'brute-force proxy and whitelist lists retain only strings',
    $invoke(ViMbAdmin_BruteForce::class, 'stringList', ['127.0.0.1', '10.0.0.0/8'], 'list') === ['127.0.0.1', '10.0.0.0/8']
    && $fails(
        static fn (): mixed => $invoke(ViMbAdmin_BruteForce::class, 'stringList', [['nested']], 'list'),
        'list must contain strings',
    )
);
$stateDir = sys_get_temp_dir() . '/vimbadmin-bf-shape-' . bin2hex(random_bytes(4));
$bruteForce = new ViMbAdmin_BruteForce(null, ['statedir' => $stateDir]);
// State is keyed on the source network prefix, not the exact address.
$file = bruteForceStatePath($stateDir, '127.0.0.1');
mkdir($stateDir, 0750, true);
file_put_contents($file, '{"attempts":"1","first":0,"last":0,"locked_until":0}');
$check('malformed brute-force state fails closed with an explicit abort', $fails(
    static fn (): mixed => (new ReflectionMethod($bruteForce, '_load'))->invoke($bruteForce, '127.0.0.1'),
    'bruteforce state is corrupt',
));
unlink($file);
file_put_contents($file, '{"attempts":1,"first":0,"last":0,"locked_until":"9999999999"}');
$check('numeric-string locked state fails closed with an explicit abort', $fails(
    static fn (): mixed => (new ReflectionMethod($bruteForce, '_load'))->invoke($bruteForce, '127.0.0.1'),
    'bruteforce state is corrupt',
));
unlink($file);
rmdir($stateDir);

$check(
    'doveadm and brute-force shape helpers reject arrays before downstream calls',
    $fails(static fn (): mixed => $invoke(ViMbAdmin_Doveadm::class, 'stringValue', ['url'], 'doveadm.http.url'), 'doveadm.http.url must be a string')
    && $fails(static fn (): mixed => new ViMbAdmin_BruteForce(null, ['enabled' => ['yes']]), 'bruteforce.enabled must be boolean')
);

$check('fixed assertion count', $checks === 10);
echo $failures === 0
    ? "OK: all security runtime input assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
