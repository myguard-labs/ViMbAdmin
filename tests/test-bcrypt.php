<?php

require __DIR__ . '/../library/OSS/Exception.php';
require __DIR__ . '/../library/OSS/Crypt/Exception.php';
require __DIR__ . '/../library/OSS/String.php';
require __DIR__ . '/../library/OSS/Crypt/Bcrypt.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$rejectsCost = static function (mixed $cost): bool {
    try {
        $bcryptClass = new ReflectionClass(OSS_Crypt_Bcrypt::class);
        $bcryptClass->newInstance($cost);
    } catch (OSS_Crypt_Exception) {
        return true;
    }

    return false;
};

echo "== OSS bcrypt ==\n";

$bcrypt = new OSS_Crypt_Bcrypt(4);
$hash = $bcrypt->hash('correct horse battery staple');
$check('hashing succeeds', is_string($hash));
$check('hash preserves the $2a$ format and configured cost', is_string($hash) && preg_match('/^\$2a\$04\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1);
$check('the generated hash verifies', is_string($hash) && $bcrypt->verify('correct horse battery staple', $hash));
$check('a wrong password is rejected', is_string($hash) && !$bcrypt->verify('wrong password', $hash));
$check('a malformed hash is rejected', !$bcrypt->verify('correct horse battery staple', 'not-a-bcrypt-hash'));

$knownHash = crypt('password', '$2a$04$usesomesillystringfore');
$check('a compatible existing $2a$ hash verifies', $bcrypt->verify('password', $knownHash));

$firstSalt = $bcrypt->generateSalt();
$secondSalt = $bcrypt->generateSalt();
$check('salt preserves the $2a$ format and configured cost', preg_match('/^\$2a\$04\$[.\/A-Za-z0-9]{21}[.Oeu]$/D', $firstSalt) === 1);
$check('successive salts use fresh randomness', $firstSalt !== $secondSalt);

$canonicalSalts = true;
for ($i = 0; $i < 32; $i++) {
    if (preg_match('/^\$2a\$04\$[.\/A-Za-z0-9]{21}[.Oeu]$/D', $bcrypt->generateSalt()) !== 1) {
        $canonicalSalts = false;
        break;
    }
}
$check('generated salts always use a canonical bcrypt final character', $canonicalSalts);

$roundTripSalt = $bcrypt->generateSalt();
$roundTripHash = crypt('round-trip', $roundTripSalt);
$check('crypt preserves the generated canonical salt', substr($roundTripHash, 0, 29) === $roundTripSalt);

$costProperty = new ReflectionProperty(OSS_Crypt_Bcrypt::class, '_cost');
$costProperty->setValue($bcrypt, 3);
try {
    $bcrypt->hash('must fail');
    $hashFailureRejected = false;
} catch (OSS_Crypt_Exception $exception) {
    $hashFailureRejected = $exception->getMessage() === 'Bcrypt hashing failed';
} finally {
    $costProperty->setValue($bcrypt, 4);
}
$check('crypt failure raises the bcrypt hashing exception', $hashFailureRejected);

$numericCostBcrypt = new OSS_Crypt_Bcrypt('04');
$check('numeric configuration strings remain compatible', str_starts_with($numericCostBcrypt->generateSalt(), '$2a$04$'));
$maximumCostBcrypt = new OSS_Crypt_Bcrypt(31);
$check('the maximum valid cost is retained', str_starts_with($maximumCostBcrypt->generateSalt(), '$2a$31$'));

$highCostBcrypt = new OSS_Crypt_Bcrypt(5);
$lowCostBcrypt = new OSS_Crypt_Bcrypt(4);
$lowCostBcrypt->hash('low-cost-first-pass');
$laterHighCostHash = $highCostBcrypt->hash('high-cost-first-pass');
$check('low-cost instance cannot downgrade a later high-cost hash', str_starts_with($laterHighCostHash, '$2a$05$'));

$check('cost below the bcrypt minimum is rejected', $rejectsCost(3));
$check('cost above the bcrypt maximum is rejected', $rejectsCost(32));
$check('non-integer cost text is rejected', $rejectsCost('12x'));
$check('fractional costs are rejected', $rejectsCost(12.5));

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Crypt_Bcrypt assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
