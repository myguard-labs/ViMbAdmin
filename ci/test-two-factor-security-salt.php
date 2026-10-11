<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$rejectsSalt = static function (mixed $salt): bool {
    try {
        (new ReflectionClass(ViMbAdmin_TwoFactor::class))->newInstanceArgs(['CI-039', $salt]);
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage() === 'TOTP security salt must be a non-empty string.';
    }

    return false;
};

echo "== Two-factor security salt ==\n";

$twoFactor = new ViMbAdmin_TwoFactor('CI-039', 'installation-security-salt');
$secret = 'JBSWY3DPEHPK3PXP';
$encrypt = new ReflectionMethod(ViMbAdmin_TwoFactor::class, '_encrypt');
$decrypt = new ReflectionMethod(ViMbAdmin_TwoFactor::class, '_decrypt');
$ciphertext = $encrypt->invoke($twoFactor, $secret);
$check('a non-empty salt enables encrypted TOTP secret round-trip', $decrypt->invoke($twoFactor, $ciphertext) === $secret);
$check(
    'the stored TOTP secret is encrypted rather than plaintext',
    is_string($ciphertext) && $ciphertext !== $secret,
);

$minimal = new ViMbAdmin_TwoFactor('CI-039', 'x');
$minimalCiphertext = $encrypt->invoke($minimal, $secret);
$check('a one-character salt is accepted and decrypts its ciphertext', $decrypt->invoke($minimal, $minimalCiphertext) === $secret);

$check('an empty salt is rejected before key derivation', $rejectsSalt(''));
$check('a whitespace-only salt is rejected before key derivation', $rejectsSalt(" \t\n"));
$check('a non-string salt is rejected deterministically', $rejectsSalt(null));

echo "\n";
if ($failures !== 0) {
    echo "FAIL: {$failures} assertion(s) failed\n";
    exit(1);
}

echo "OK: all two-factor security-salt assertions passed (PHP " . PHP_VERSION . ")\n";
