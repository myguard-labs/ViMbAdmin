<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function readContractJson(string $contents): array
{
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new UnexpectedValueException('Composer contract must be a JSON object');
    }

    return $decoded;
}

/** @param array<string, mixed> $contract */
function requiresCurl(array $contract, string $section): bool
{
    $requirements = $contract[$section] ?? null;

    return is_array($requirements) && ($requirements['ext-curl'] ?? null) === '*';
}

$root = dirname(__DIR__);
$composer = readContractJson((string) file_get_contents($root . '/composer.json'));
$lock = readContractJson((string) file_get_contents($root . '/composer.lock'));
$readme = (string) file_get_contents($root . '/README.md');
$failures = 0;
$check = static function (string $label, bool $result) use (&$failures): void {
    echo ($result ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$result) {
        ++$failures;
    }
};

echo "== cURL installation contract ==\n";
$check(
    'positive: cURL is required by Composer and the lock platform',
    requiresCurl($composer, 'require') && requiresCurl($lock, 'platform')
);
$check(
    'positive: source-install guide names the PHP cURL extension',
    preg_match('/PHP \*\*8\.2\.0\+\*\* with [\s\S]{0,150}`curl`/', $readme) === 1
);
$check(
    'boundary: an optional suggestion does not satisfy a required extension',
    !requiresCurl(['suggest' => ['ext-curl' => '*']], 'require')
);
$check(
    'negative: missing or relaxed cURL requirements are rejected',
    !requiresCurl(['require' => []], 'require')
        && !requiresCurl(['require' => ['ext-curl' => '>=7']], 'require')
);
$malformedRejected = false;
try {
    readContractJson('[]');
} catch (UnexpectedValueException) {
    $malformedRejected = true;
}
$check('error/malformed: a non-object contract is rejected', $malformedRejected);

exit($failures === 0 ? 0 : 1);
