<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Repositories/MailboxTask.php';

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$method = new ReflectionMethod(\Repositories\MailboxTask::class, 'requiredStatusCounts');
$failure = static function (mixed $rows) use ($method): ?string {
    try {
        $method->invoke(null, $rows);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};
$incrementDecimal = static function (string $digits): string {
    for ($position = strlen($digits) - 1; $position >= 0; $position--) {
        if ($digits[$position] !== '9') {
            $digits[$position] = (string) ((int) $digits[$position] + 1);
            return $digits;
        }
        $digits[$position] = '0';
    }
    return '1' . $digits;
};

echo "== Mailbox task status counts ==\n";

$check('empty rows produce empty counts', $method->invoke(null, []) === []);
$check('integer zero and decimal-string count are normalized', $method->invoke(null, [
    ['status' => 'PENDING', 'cnt' => 0],
    ['status' => 'RUNNING', 'cnt' => '12'],
    ['status' => 'MAXIMUM', 'cnt' => (string) PHP_INT_MAX],
]) === ['PENDING' => 0, 'RUNNING' => 12, 'MAXIMUM' => PHP_INT_MAX]);
$check('unknown and empty statuses remain supported', $method->invoke(null, [
    ['status' => '', 'cnt' => '3'],
    ['status' => 'LEGACY', 'cnt' => 4],
]) === ['' => 3, 'LEGACY' => 4]);

$check(
    'scalar outer result is rejected',
    $failure('invalid') === 'Mailbox task status query result must be an array.'
);
$check('equal duplicate status is rejected', $failure([
    ['status' => 'PENDING', 'cnt' => '1'], ['status' => 'PENDING', 'cnt' => '1'],
]) === 'Mailbox task status query returned a duplicate status.');
$check('conflicting duplicate status is rejected', $failure([
    ['status' => 'PENDING', 'cnt' => '1'], ['status' => 'PENDING', 'cnt' => '2'],
]) === 'Mailbox task status query returned a duplicate status.');

foreach ([
    'scalar row' => ['invalid'],
    'string outer key' => ['row' => ['status' => 'PENDING', 'cnt' => '1']],
    'missing status' => [['cnt' => '1']],
    'missing count' => [['status' => 'PENDING']],
    'extra field' => [['status' => 'PENDING', 'cnt' => '1', 'extra' => true]],
    'non-string status' => [['status' => null, 'cnt' => '1']],
] as $label => $rows) {
    $check(
        $label . ' is rejected',
        $failure($rows) === 'Mailbox task status query row has an invalid shape.'
    );
}

foreach ([
    null, false, 1.0, -1, '-1', '+1', '01', '1.5', '1e2', ' 1', 'abc',
    $incrementDecimal((string) PHP_INT_MAX),
] as $count) {
    $check(
        'invalid count is rejected: ' . get_debug_type($count) . ':' . var_export($count, true),
        $failure([['status' => 'PENDING', 'cnt' => $count]])
            === 'Mailbox task status query row has an invalid count.'
    );
}

$check('fixed assertion count', $checks === 24);

echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
