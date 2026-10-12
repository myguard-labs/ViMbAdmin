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
        (new ReflectionClass(ViMbAdmin_TwoFactor::class))->newInstanceArgs(['CI-054', $salt]);
    } catch (InvalidArgumentException $exception) {
        return $exception->getMessage() === 'TOTP security salt must be a non-blank string of exactly 64 bytes.';
    }

    return false;
};

echo "== Two-factor security salt ==\n";

$salt = str_repeat('a', 64);
$twoFactor = new ViMbAdmin_TwoFactor('CI-054', $salt);
$secret = 'JBSWY3DPEHPK3PXP';
$encrypt = new ReflectionMethod(ViMbAdmin_TwoFactor::class, '_encrypt');
$decrypt = new ReflectionMethod(ViMbAdmin_TwoFactor::class, '_decrypt');
$ciphertext = $encrypt->invoke($twoFactor, $secret);
$check('an exact 64-byte salt enables encrypted TOTP secret round-trip', $decrypt->invoke($twoFactor, $ciphertext) === $secret);
$check(
    'the stored TOTP secret is encrypted rather than plaintext',
    is_string($ciphertext) && $ciphertext !== $secret,
);

$differentSalt = str_repeat('a', 63) . 'b';
$different = new ViMbAdmin_TwoFactor('CI-054', $differentSalt);
$check('ciphertext is not decrypted with different 64-byte key material', $decrypt->invoke($different, $ciphertext) === null);

$paddedSalt = ' ' . str_repeat('a', 62) . ' ';
$padded = new ViMbAdmin_TwoFactor('CI-054', $paddedSalt);
$paddedCiphertext = $encrypt->invoke($padded, $secret);
$check('accepted boundary whitespace remains part of the encryption key', $decrypt->invoke($twoFactor, $paddedCiphertext) === null);

$legacyStateAdmin = new class () {
    /** @var array<string,mixed> */
    public array $preferences = [
        ViMbAdmin_TwoFactor::PREF_SECRET => 'opaque-legacy-ciphertext',
        ViMbAdmin_TwoFactor::PREF_BACKUP => '["opaque-backup-hash"]',
        ViMbAdmin_TwoFactor::PREF_LASTTS => '123',
    ];

    public function getPreference(string $key): mixed
    {
        return $this->preferences[$key] ?? false;
    }

    public function deletePreference(string $key): void
    {
        unset($this->preferences[$key]);
    }
};
$check('legacy encrypted state is detected without deriving a key', ViMbAdmin_TwoFactor::hasStoredState($legacyStateAdmin));
ViMbAdmin_TwoFactor::clearStoredState($legacyStateAdmin);
$check('legacy encrypted state has an explicit key-independent reset path', $legacyStateAdmin->preferences === []);

$check('an empty salt is rejected before key derivation', $rejectsSalt(''));
$check('a whitespace-only salt is rejected before key derivation', $rejectsSalt(" \t\n"));
$check('a form-feed-only salt is rejected before key derivation', $rejectsSalt("\f"));
$check('an NBSP-only salt is rejected before key derivation', $rejectsSalt("\u{00A0}"));
$check('a non-string salt is rejected deterministically', $rejectsSalt(null));
$check('a one-character salt is rejected (old acceptance regression)', $rejectsSalt('x'));
$check('a 63-byte salt is rejected at the lower boundary', $rejectsSalt(str_repeat('a', 63)));
$check('a 65-byte salt is rejected at the upper boundary', $rejectsSalt(str_repeat('a', 65)));
$check('64 bytes of whitespace are rejected as blank key material', $rejectsSalt(str_repeat(' ', 64)));
$check('64 multibyte characters are rejected when they exceed 64 bytes', $rejectsSalt(str_repeat("\u{00E9}", 64)));

echo "\n";
if ($failures !== 0) {
    echo "FAIL: {$failures} assertion(s) failed\n";
    exit(1);
}

echo "OK: all two-factor security-salt assertions passed (PHP " . PHP_VERSION . ")\n";
