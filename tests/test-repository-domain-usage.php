<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$method = new ReflectionMethod(\Repositories\Domain::class, 'requiredDomainUsageByDomain');
$merge = new ReflectionMethod(\Repositories\Domain::class, 'mergeDomainUsageRows');
$failure = static function (mixed $rows) use ($method): ?string {
    try {
        $method->invoke(null, $rows);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};

echo "== Domain usage hydration ==\n";

$check('empty rows produce empty usage', $method->invoke(null, []) === []);
$check('integer and bigint-string byte totals preserve exact values', $method->invoke(null, [
    ['domain' => 'first.example', 'bytes' => 0],
    ['domain' => 'Large.Example', 'bytes' => '9223372036854775808'],
]) === ['first.example' => 0, 'large.example' => '9223372036854775808']);
$check('empty and unknown domain strings remain visible', $method->invoke(null, [
    ['domain' => '', 'bytes' => '3'], ['domain' => 'legacy', 'bytes' => 4],
]) === ['' => '3', 'legacy' => 4]);
$check(
    'scalar outer result is rejected',
    $failure('invalid') === 'Domain usage query result must be an array.'
);
$check('duplicate domain is rejected', $failure([
    ['domain' => 'example.test', 'bytes' => '1'],
    ['domain' => 'Example.Test', 'bytes' => '2'],
]) === 'Domain usage query returned a duplicate domain.');

foreach ([
    'scalar row' => ['invalid'],
    'string outer key' => ['row' => ['domain' => 'example.test', 'bytes' => '1']],
    'missing domain' => [['bytes' => '1']],
    'missing bytes' => [['domain' => 'example.test']],
    'extra field' => [['domain' => 'example.test', 'bytes' => '1', 'extra' => true]],
    'non-string domain' => [['domain' => null, 'bytes' => '1']],
] as $label => $rows) {
    $check(
        $label . ' is rejected',
        $failure($rows) === 'Domain usage query row has an invalid shape.'
    );
}

foreach ([null, false, 1.0, -1, '-1', '+1', '01', '1.5', '1e2', ' 1', 'abc'] as $bytes) {
    $check(
        'invalid bytes are rejected: ' . get_debug_type($bytes) . ':' . var_export($bytes, true),
        $failure([['domain' => 'example.test', 'bytes' => $bytes]])
            === 'Domain usage query row has invalid bytes.'
    );
}

$domainRows = [
    3 => ['name' => 'First.Example', 'active' => true],
    8 => ['name' => 'missing.example', 'active' => false],
];
$check('usage merge preserves rows and sparse keys with zero fallback', $merge->invoke(null, $domainRows, [
    'first.example' => '9223372036854775808',
]) === [
    3 => ['name' => 'First.Example', 'active' => true, 'mailboxes_size' => '9223372036854775808'],
    8 => ['name' => 'missing.example', 'active' => false, 'mailboxes_size' => 0],
]);
$mergeFailure = static function (mixed $rows) use ($merge): ?string {
    try {
        $merge->invoke(null, $rows, []);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};
$check(
    'usage merge rejects a scalar outer result',
    $mergeFailure('invalid') === 'Domain list rows must be an array.'
);
foreach ([
    'scalar row' => ['invalid'],
    'string outer key' => ['row' => ['name' => 'example.test']],
    'missing name' => [['active' => true]],
    'wrong name type' => [['name' => null]],
] as $label => $rows) {
    $check(
        'usage merge rejects ' . $label,
        $mergeFailure($rows) === 'Domain list row has an invalid usage shape.'
    );
}
$check(
    'usage merge rejects a non-string row field',
    $mergeFailure([['name' => 'example.test', 0 => 'invalid']])
        === 'Domain list row has an invalid usage field.'
);

$check('fixed assertion count', $checks === 29);

echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
