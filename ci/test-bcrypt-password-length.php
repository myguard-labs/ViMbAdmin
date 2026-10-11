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
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$throws = static function (callable $operation, string $class, string $message): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception instanceof $class && $exception->getMessage() === $message;
    }

    return false;
};

echo "== Bcrypt password byte boundary ==\n";

$boundary = str_repeat('A', 72);
$longX = $boundary . 'X';
$longY = $boundary . 'Y';

$bcrypt = new OSS_Crypt_Bcrypt(4);
$rawBoundaryHash = $bcrypt->hash($boundary);
$check('raw bcrypt accepts and verifies exactly 72 bytes', $bcrypt->verify($boundary, $rawBoundaryHash));
$check('raw bcrypt rejects a colliding 73-byte candidate', !$bcrypt->verify($longX, $rawBoundaryHash));
$check('raw bcrypt rejects hashing above 72 bytes with a domain error', $throws(
    static fn (): string => $bcrypt->hash($longX),
    OSS_Crypt_Exception::class,
    'Password must not exceed 72 bytes for bcrypt',
));

$authOptions = ['pwhash' => 'bcrypt', 'hash_cost' => 4];
$authBoundaryHash = OSS_Auth_Password::hash($boundary, $authOptions);
$check('configured bcrypt accepts and verifies exactly 72 bytes', OSS_Auth_Password::verify($boundary, $authBoundaryHash, $authOptions));
$check(
    'configured bcrypt rejects both colliding suffixes',
    !OSS_Auth_Password::verify($longX, $authBoundaryHash, $authOptions)
        && !OSS_Auth_Password::verify($longY, $authBoundaryHash, $authOptions),
);
$check('configured bcrypt rejects hashing above 72 bytes', $throws(
    static fn (): string => OSS_Auth_Password::hash($longX, $authOptions),
    OSS_Crypt_Exception::class,
    'Password must not exceed 72 bytes for bcrypt',
));

$dovecotBoundaryHash = ViMbAdmin_Dovecot::password('BLF-CRYPT', $boundary, 'user@example.test');
$check(
    'Dovecot BLF-CRYPT accepts and verifies exactly 72 bytes',
    ViMbAdmin_Dovecot::passwordVerify('BLF-CRYPT', $dovecotBoundaryHash, $boundary, 'user@example.test'),
);
$check(
    'Dovecot BLF-CRYPT rejects both colliding suffixes',
    !ViMbAdmin_Dovecot::passwordVerify('BLF-CRYPT', $dovecotBoundaryHash, $longX, 'user@example.test')
        && !ViMbAdmin_Dovecot::passwordVerify('BLF-CRYPT', $dovecotBoundaryHash, $longY, 'user@example.test'),
);
$check('Dovecot BLF-CRYPT rejects hashing above 72 bytes with a domain error', $throws(
    static fn (): string => ViMbAdmin_Dovecot::password('BLF-CRYPT', $longX, 'user@example.test'),
    ViMbAdmin_Exception::class,
    'Password must not exceed 72 bytes for bcrypt',
));

$dovecotOptions = ['pwhash' => 'dovecot:BLF-CRYPT', 'username' => 'user@example.test'];
$check('configured Dovecot bcrypt rejects hashing above 72 bytes', $throws(
    static fn (): string => OSS_Auth_Password::hash($longY, $dovecotOptions),
    ViMbAdmin_Exception::class,
    'Password must not exceed 72 bytes for bcrypt',
));
$check(
    'configured Dovecot bcrypt rejects a colliding verification candidate',
    !OSS_Auth_Password::verify($longX, $dovecotBoundaryHash, $dovecotOptions),
);

$legacy2aHash = crypt($longX, '$2a$04$abcdefghijklmnopqrstuu');
$legacyCryptOptions = ['pwhash' => 'crypt:blowfish'];
$check(
    'legacy $2a$ verification rejects passwords whose suffix bcrypt ignores',
    !OSS_Auth_Password::verify($longX, $legacy2aHash, $legacyCryptOptions)
        && !OSS_Auth_Password::verify($longY, $legacy2aHash, $legacyCryptOptions),
);

$longShaPassword = str_repeat('B', 80) . 'X';
$longShaHash = crypt($longShaPassword, '$6$length-regression$');
$check(
    'safe non-bcrypt legacy verification keeps accepting passwords above 72 bytes',
    OSS_Auth_Password::verify($longShaPassword, $longShaHash, ['pwhash' => 'crypt:sha512']),
);
$check(
    'safe non-bcrypt legacy verification still checks bytes after byte 72',
    !OSS_Auth_Password::verify(str_repeat('B', 80) . 'Y', $longShaHash, ['pwhash' => 'crypt:sha512']),
);

echo "\n";
if ($failures !== 0) {
    echo "FAIL: {$failures} assertion(s) failed\n";
    exit(1);
}

echo "OK: all bcrypt password byte-boundary assertions passed (PHP " . PHP_VERSION . ")\n";
