<?php

require __DIR__ . '/../library/OSS/Exception.php';
require __DIR__ . '/../library/OSS/Crypt/Exception.php';
require __DIR__ . '/../library/OSS/String.php';
require __DIR__ . '/../library/OSS/Crypt/Bcrypt.php';
require __DIR__ . '/../library/ViMbAdmin/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Dovecot.php';
require __DIR__ . '/../library/OSS/Auth/Password.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$throwsOss = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (OSS_Exception $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
};

echo "== OSS auth password ==\n";

foreach ([OSS_Auth_Password::HASH_PLAIN, OSS_Auth_Password::HASH_PLAINTEXT] as $mode) {
    $hash = OSS_Auth_Password::hash('plain-secret', $mode);
    $check("{$mode} string configuration hashes unchanged", $hash === 'plain-secret');
    $check("{$mode} string configuration verifies", OSS_Auth_Password::verify('plain-secret', $hash, $mode));
    $check("{$mode} string configuration rejects a wrong password", !OSS_Auth_Password::verify('wrong', $hash, $mode));
}

$bcryptHash = OSS_Auth_Password::hash('bcrypt-secret', ['pwhash' => 'bcrypt', 'hash_cost' => '04']);
$check('bcrypt array configuration retains numeric-string cost', str_starts_with($bcryptHash, '$2a$04$'));
$check('bcrypt array configuration verifies', OSS_Auth_Password::verify('bcrypt-secret', $bcryptHash, ['pwhash' => 'bcrypt', 'hash_cost' => 4]));
$check('bcrypt verification uses the stored hash cost, not generation policy', OSS_Auth_Password::verify('bcrypt-secret', $bcryptHash, ['pwhash' => 'bcrypt', 'hash_cost' => 31]));
$check('bcrypt rejects a wrong password', !OSS_Auth_Password::verify('wrong', $bcryptHash, ['pwhash' => 'bcrypt', 'hash_cost' => 4]));
$upgradedBcrypt = OSS_Auth_Password::verifyAndRehash(
    'bcrypt-secret',
    $bcryptHash,
    ['pwhash' => 'bcrypt', 'hash_cost' => 5],
);
$check(
    'successful bcrypt verification upgrades a stale cost',
    is_string($upgradedBcrypt) && str_starts_with($upgradedBcrypt, '$2a$05$')
        && OSS_Auth_Password::verify('bcrypt-secret', $upgradedBcrypt, ['pwhash' => 'bcrypt'])
);
$check(
    'a stronger stored bcrypt cost is never downgraded',
    OSS_Auth_Password::verifyAndRehash(
        'bcrypt-secret',
        $bcryptHash,
        ['pwhash' => 'bcrypt', 'hash_cost' => 4],
    ) === $bcryptHash
);
$check(
    'failed verification neither rehashes nor evaluates an invalid target cost',
    OSS_Auth_Password::verifyAndRehash(
        'wrong',
        $bcryptHash,
        ['pwhash' => 'bcrypt', 'hash_cost' => 17],
    ) === null
);

$defaultBcryptHash = OSS_Auth_Password::hash('default-cost', OSS_Auth_Password::HASH_BCRYPT);
$check('bcrypt string configuration retains default cost 12', str_starts_with($defaultBcryptHash, '$2a$12$'));
$check('bcrypt string configuration verifies', OSS_Auth_Password::verify('default-cost', $defaultBcryptHash, OSS_Auth_Password::HASH_BCRYPT));
$defaultArrayBcryptHash = OSS_Auth_Password::hash('default-array-cost', ['pwhash' => 'bcrypt']);
$check('bcrypt array configuration retains default cost 12', str_starts_with($defaultArrayBcryptHash, '$2a$12$'));
$check('bcrypt rejects malformed cost instead of coercing it', $throwsOss(
    static fn (): string => OSS_Auth_Password::hash('malformed-cost', ['pwhash' => 'bcrypt', 'hash_cost' => '5junk']),
    'Bcrypt cost must be an integer between 4 and 16'
));
$check('bcrypt rejects operationally excessive cost before hashing', $throwsOss(
    static fn (): string => OSS_Auth_Password::hash('excessive-cost', ['pwhash' => 'bcrypt', 'hash_cost' => 17]),
    'Bcrypt cost must be an integer between 4 and 16'
));

foreach (['crypt:md5', 'crypt:blowfish', 'crypt:sha256', 'crypt:sha512'] as $mode) {
    $hash = OSS_Auth_Password::hash('crypt-secret', $mode);
    $check(
        "{$mode} emits a complete password_hash bcrypt shape",
        preg_match('/^\$2y\$12\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1
    );
    $check("{$mode} never stores plaintext", $hash !== 'crypt-secret');
    $check("{$mode} verifies", OSS_Auth_Password::verify('crypt-secret', $hash, $mode));
    $check("{$mode} rejects a wrong password", !OSS_Auth_Password::verify('wrong', $hash, $mode));

    $boundaryHash = OSS_Auth_Password::hash(str_repeat('A', 72), $mode);
    $check(
        "{$mode} accepts exactly 72 password bytes",
        OSS_Auth_Password::verify(str_repeat('A', 72), $boundaryHash, $mode)
    );
    $check(
        "{$mode} bcrypt hash rejects a candidate sharing its first 72 bytes",
        !OSS_Auth_Password::verify(str_repeat('A', 72) . 'X', $boundaryHash, $mode)
    );
    $check("{$mode} rejects a 73-byte password before bcrypt truncation", $throwsOss(
        static fn (): string => OSS_Auth_Password::hash(str_repeat('A', 72) . 'X', $mode),
        'Password must not exceed 72 bytes for legacy crypt configuration'
    ));
    $check("{$mode} rejects a different suffix sharing the first 72 bytes", $throwsOss(
        static fn (): string => OSS_Auth_Password::hash(str_repeat('A', 72) . 'Y', $mode),
        'Password must not exceed 72 bytes for legacy crypt configuration'
    ));
}

$legacyCryptHashes = [
    'crypt:md5' => '$1$12345678$v..6KAKFNqBJISjodv1W81',
    'crypt:blowfish' => '$2a$' . '12$abcdefghijklmnopqrstuufRU2BlUoJSwNYepqy8lSxodZnugpiIe',
    'crypt:sha256' => '$5$1234567890abcdef$e.nlasQaKYlbEWWR1vxqFIhaX4ixu3EOFpf.O7QCIo5',
    'crypt:sha512' => '$6$1234567890abcdef$/x1MArqV01QAYaUJIpaJb1KgMZbys/cDZ3r63/7GLVG42FRJ61Z/jp6Q8jxf7fWO4Cfzy9sbh60rJl63GWJWV.',
];
foreach ($legacyCryptHashes as $mode => $legacyHash) {
    $check(
        "{$mode} verifies an existing stored hash",
        OSS_Auth_Password::verify('crypt-secret', $legacyHash, $mode)
    );
    $check(
        "{$mode} existing stored hash rejects a wrong password",
        !OSS_Auth_Password::verify('wrong', $legacyHash, $mode)
    );
}
$longLegacyBlowfishPassword = str_repeat('A', 72) . 'X';
$longLegacyBlowfishHash = crypt($longLegacyBlowfishPassword, '$2a$04$abcdefghijklmnopqrstuu');
$check(
    'crypt:blowfish existing $2a$ hash retains over-72-byte verification',
    OSS_Auth_Password::verify($longLegacyBlowfishPassword, $longLegacyBlowfishHash, 'crypt:blowfish')
);
$longLegacyPassword = str_repeat('A', 72) . 'X';
foreach (['crypt:md5' => '$1$12345678$', 'crypt:sha256' => '$5$1234567890abcdef$', 'crypt:sha512' => '$6$1234567890abcdef$'] as $mode => $salt) {
    $longLegacyHash = crypt($longLegacyPassword, $salt);
    $check(
        "{$mode} existing non-bcrypt hash retains full-length verification",
        OSS_Auth_Password::verify($longLegacyPassword, $longLegacyHash, $mode)
    );
    $check(
        "{$mode} existing non-bcrypt hash checks bytes beyond 72",
        !OSS_Auth_Password::verify(str_repeat('A', 72) . 'Y', $longLegacyHash, $mode)
    );
}

$dovecotConfig = ['pwhash' => 'dovecot:sha256-crypt', 'username' => 'user@example.test'];
$dovecotHash = OSS_Auth_Password::hash('dovecot-secret', $dovecotConfig);
$check('dovecot configuration preserves its scheme', str_starts_with($dovecotHash, '$5$'));
$check('dovecot configuration verifies', OSS_Auth_Password::verify('dovecot-secret', $dovecotHash, $dovecotConfig));
$check('dovecot configuration rejects a wrong password', !OSS_Auth_Password::verify('wrong', $dovecotHash, $dovecotConfig));
$legacyDovecotHash = crypt('dovecot-secret', '$6$legacy-upgrade$');
$upgradedDovecotHash = OSS_Auth_Password::verifyAndRehash(
    'dovecot-secret',
    $legacyDovecotHash,
    ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'],
);
$check(
    'successful Dovecot verification upgrades a stale scheme',
    is_string($upgradedDovecotHash) && str_starts_with($upgradedDovecotHash, '$2y$')
        && OSS_Auth_Password::verify(
            'dovecot-secret',
            $upgradedDovecotHash,
            ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'],
        )
);
$check(
    'failed Dovecot verification performs no scheme upgrade',
    OSS_Auth_Password::verifyAndRehash(
        'wrong',
        $legacyDovecotHash,
        ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'],
    ) === null
);
$prefixedStrongBcrypt = '{BLF-CRYPT}' . password_hash(
    'dovecot-secret',
    PASSWORD_BCRYPT,
    ['cost' => 13],
);
$check(
    'prefixed stronger Dovecot bcrypt is preserved without a downgrade',
    OSS_Auth_Password::verifyAndRehash(
        'dovecot-secret',
        $prefixedStrongBcrypt,
        ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'],
    ) === $prefixedStrongBcrypt
);
$prefixedLegacyDovecot = '{SHA512-CRYPT}' . $legacyDovecotHash;
$check(
    'prefixed legacy Dovecot scheme is normalized before upgrade classification',
    str_starts_with((string) OSS_Auth_Password::verifyAndRehash(
        'dovecot-secret',
        $prefixedLegacyDovecot,
        ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'],
    ), '$2y$')
);

$check('missing array hash method still throws', $throwsOss(
    static fn (): mixed => (new ReflectionMethod(OSS_Auth_Password::class, 'hash'))->invoke(null, 'secret', []),
    'Cannot hash password without a hash method'
));
$check('missing verify hash method still throws', $throwsOss(
    static fn (): mixed => (new ReflectionMethod(OSS_Auth_Password::class, 'verify'))->invoke(null, 'secret', 'hash', []),
    'Cannot verify password without a hash method'
));
$check('unknown string hash method still throws', $throwsOss(
    static fn (): string => OSS_Auth_Password::hash('secret', 'unknown'),
    'Unknown password hashing method'
));
$check('unknown crypt method still throws', $throwsOss(
    static fn (): string => OSS_Auth_Password::hash('secret', 'crypt:unknown'),
    'Unknown crypt password hashing method'
));

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Auth_Password assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
