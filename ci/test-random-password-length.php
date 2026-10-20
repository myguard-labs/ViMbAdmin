<?php

require __DIR__ . '/../library/OSS/String.php';

$invalid = [
    'negative' => [-1, InvalidArgumentException::class],
    'zero' => [0, InvalidArgumentException::class],
    'one' => [1, InvalidArgumentException::class],
    'two' => [2, InvalidArgumentException::class],
    'string' => ['three', TypeError::class],
    'numeric-string' => ['3', TypeError::class],
    'float' => [3.0, TypeError::class],
    'null' => [null, TypeError::class],
    'true' => [true, TypeError::class],
    'false' => [false, TypeError::class],
    'array' => [[3], TypeError::class],
    'object' => [new stdClass(), TypeError::class],
];

if (isset($argv[1])) {
    $case = $invalid[$argv[1]] ?? null;
    if ($case === null) {
        exit(2);
    }

    try {
        (new ReflectionMethod(OSS_String::class, 'randomPassword'))->invoke(null, $case[0]);
        echo "returned instead of throwing\n";
        exit(1);
    } catch (Throwable $exception) {
        echo get_class($exception) . "\n";
        exit($exception instanceof $case[1] ? 0 : 1);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$alphabet = '23456789abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
foreach (['minimum' => 3, 'ordinary' => 12, 'longer' => 64, 'default' => null] as $label => $length) {
    $valid = true;
    for ($sample = 0; $sample < 8; $sample++) {
        $password = $length === null ? OSS_String::randomPassword() : OSS_String::randomPassword($length);
        $valid = $valid
            && strlen($password) === ($length ?? 8)
            && strspn($password, $alphabet) === strlen($password)
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }
    $check($label . ' length, alphabet and character classes', $valid);
}

foreach ($invalid as $label => $case) {
    $process = proc_open([PHP_BINARY, __FILE__, $label], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        $check($label . ' starts a PHP child', false);
        continue;
    }

    fclose($pipes[0]);
    $deadline = microtime(true) + 2.0;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    if ($status['running']) {
        proc_terminate($process, 9);
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $ok = !$status['running']
        && $status['exitcode'] === 0
        && trim($output) === $case[1]
        && $errors === '';
    $check($label . ' throws ' . $case[1] . ($status['running'] ? ' (child timed out)' : ''), $ok);
    if ($status['running']) {
        break;
    }
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
