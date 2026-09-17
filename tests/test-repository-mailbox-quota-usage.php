<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$quota = new ReflectionMethod(\Repositories\Mailbox::class, 'requiredQuotaUsageByUsername');
$login = new ReflectionMethod(\Repositories\Mailbox::class, 'requiredLastLoginByUsername');
$merge = new ReflectionMethod(\Repositories\Mailbox::class, 'mergeMailboxUsageRows');
$failure = static function (ReflectionMethod $method, mixed $rows): ?string {
    try {
        $method->invoke(null, $rows);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};

echo "== Mailbox quota and last-login hydration ==\n";

$check('empty quota rows produce empty usage', $quota->invoke(null, []) === []);
$check('quota values preserve integers and oversized bigint strings', $quota->invoke(null, [
    ['username' => 'First@Example.Test', 'bytes' => 0, 'messages' => '9223372036854775808'],
]) === ['first@example.test' => ['bytes' => 0, 'messages' => '9223372036854775808']]);
$check('empty quota username remains visible', $quota->invoke(null, [
    ['username' => '', 'bytes' => '1', 'messages' => 2],
]) === ['' => ['bytes' => '1', 'messages' => 2]]);
$check(
    'scalar quota result is rejected',
    $failure($quota, 'invalid') === 'Mailbox quota query result must be an array.'
);
$check('case-folded duplicate quota username is rejected', $failure($quota, [
    ['username' => 'user@example.test', 'bytes' => 1, 'messages' => 2],
    ['username' => 'User@Example.Test', 'bytes' => 3, 'messages' => 4],
]) === 'Mailbox quota query returned a duplicate username.');

foreach ([
    'scalar row' => ['invalid'],
    'string outer key' => ['row' => ['username' => 'user@example.test', 'bytes' => 1, 'messages' => 2]],
    'missing username' => [['bytes' => 1, 'messages' => 2]],
    'missing bytes' => [['username' => 'user@example.test', 'messages' => 2]],
    'missing messages' => [['username' => 'user@example.test', 'bytes' => 1]],
    'extra field' => [['username' => 'user@example.test', 'bytes' => 1, 'messages' => 2, 'extra' => true]],
    'non-string username' => [['username' => null, 'bytes' => 1, 'messages' => 2]],
] as $label => $rows) {
    $check(
        'quota ' . $label . ' is rejected',
        $failure($quota, $rows) === 'Mailbox quota query row has an invalid shape.'
    );
}

foreach ([null, false, 1.0, -1, '-1', '+1', '01', '1.5', '1e2', ' 1', 'abc'] as $value) {
    $check(
        'invalid quota bytes are rejected: ' . get_debug_type($value) . ':' . var_export($value, true),
        $failure($quota, [['username' => 'user@example.test', 'bytes' => $value, 'messages' => 1]])
            === 'Mailbox quota bytes has an invalid value.'
    );
}
foreach ([null, -1, '01'] as $value) {
    $check(
        'invalid quota messages are rejected: ' . get_debug_type($value) . ':' . var_export($value, true),
        $failure($quota, [['username' => 'user@example.test', 'bytes' => 1, 'messages' => $value]])
            === 'Mailbox quota messages has an invalid value.'
    );
}

$check('empty login rows produce empty timestamps', $login->invoke(null, []) === []);
$check('login username is normalized and canonical decimal timestamp stays lossless', $login->invoke(null, [
    ['username' => 'User@Example.Test', 'last_login' => '1700000000'],
]) === ['user@example.test' => '1700000000']);
$check('oversized bigint login timestamp remains representable', $login->invoke(null, [
    ['username' => 'user@example.test', 'last_login' => '9223372036854775808'],
]) === ['user@example.test' => '9223372036854775808']);
$check(
    'scalar login result is rejected',
    $failure($login, 'invalid') === 'Mailbox last-login query result must be an array.'
);
$check('case-folded duplicate login username is rejected', $failure($login, [
    ['username' => 'user@example.test', 'last_login' => 1],
    ['username' => 'User@Example.Test', 'last_login' => 2],
]) === 'Mailbox last-login query returned a duplicate username.');

foreach ([
    'scalar row' => ['invalid'],
    'string outer key' => ['row' => ['username' => 'user@example.test', 'last_login' => 1]],
    'missing username' => [['last_login' => 1]],
    'missing timestamp' => [['username' => 'user@example.test']],
    'extra field' => [['username' => 'user@example.test', 'last_login' => 1, 'extra' => true]],
    'non-string username' => [['username' => null, 'last_login' => 1]],
] as $label => $rows) {
    $check(
        'login ' . $label . ' is rejected',
        $failure($login, $rows) === 'Mailbox last-login query row has an invalid shape.'
    );
}

foreach ([null, false, 1.0, -1, '-1', '+1', '01', '1.5', '1e2', ' 1', 'abc'] as $value) {
    $check(
        'invalid login timestamp is rejected: ' . get_debug_type($value) . ':' . var_export($value, true),
        $failure($login, [['username' => 'user@example.test', 'last_login' => $value]])
            === 'Mailbox last-login timestamp has an invalid value.'
    );
}
$mailboxRows = [
    3 => ['id' => 7, 'username' => 'First@Example.Test', 'name' => null, 'active' => true,
        'quota' => '2048', 'domain' => 'Example.Test', 'delete_pending' => null],
    8 => ['id' => 9, 'username' => 'missing@example.test', 'name' => 'Missing', 'active' => false,
        'quota' => 0, 'domain' => 'example.test', 'delete_pending' => false],
    12 => ['id' => 10, 'username' => 'never@example.test', 'name' => '', 'active' => true,
        'quota' => 1, 'domain' => 'example.test', 'delete_pending' => true],
    14 => ['id' => 11, 'username' => 'never-string@example.test', 'name' => '', 'active' => true,
        'quota' => 1, 'domain' => 'example.test', 'delete_pending' => true],
];
$check('merge preserves mailbox rows, sparse keys, case-folded matches and null fallbacks', $merge->invoke(
    null,
    $mailboxRows,
    ['first@example.test' => ['bytes' => '9223372036854775808', 'messages' => 4]],
    ['first@example.test' => '9223372036854775808', 'never@example.test' => 0,
        'never-string@example.test' => '0']
) === [
    3 => $mailboxRows[3] + ['quota_bytes' => '9223372036854775808', 'quota_messages' => 4, 'last_login' => '9223372036854775808'],
    8 => $mailboxRows[8] + ['quota_bytes' => null, 'quota_messages' => null, 'last_login' => null],
    12 => $mailboxRows[12] + ['quota_bytes' => null, 'quota_messages' => null, 'last_login' => null],
    14 => $mailboxRows[14] + ['quota_bytes' => null, 'quota_messages' => null, 'last_login' => null],
]);

$check('fixed assertion count', $checks === 49);

echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
