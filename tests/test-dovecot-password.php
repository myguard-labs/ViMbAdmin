<?php

/**
 * Unit test: ViMbAdmin_Dovecot password generation and verification.
 *
 * Regression for the "cannot change / can't log in" bug: Dovecot stores hashes
 * with a leading {SCHEME} prefix (e.g. "{SHA512-CRYPT}$6$...") but the verifier
 * fed that whole string to crypt(), which returns "*0" and can never match — so
 * every mailbox with a {SCHEME}-prefixed hash failed BOTH login and the
 * self-service current-password check, surfacing as "Invalid username or
 * password". This locks in: prefix is stripped and drives dispatch; crypt
 * families, base64 digest schemes ({SHA*}/{SSHA*}) and bcrypt all verify;
 * legacy bare hashes still work; wrong passwords are always rejected.
 *
 * Pure logic — no framework, no DB. Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../library/ViMbAdmin/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Dovecot.php';

final class TestDovecotPasswordHarnessState
{
    public static int $count = 0;
}

$failures = & TestDovecotPasswordHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestDovecotPasswordHarnessState::$count++;
    }
}

// A password exercising the exact chars from the field report (17 chars, ! and &).
$pw    = 'Ab1!cdef&ghij9012';
$wrong = 'Ab1!cdef&ghij9013';

// --- password generation: supported schemes return usable bare hashes ---
foreach (['BLF-CRYPT' => '$2', 'SHA512-CRYPT' => '$6$', 'SHA256-CRYPT' => '$5$'] as $scheme => $prefix) {
    $generated = ViMbAdmin_Dovecot::password($scheme, $pw, 'u');
    check(
        "{$scheme} generation returns the expected bare hash",
        strncmp($generated, $prefix, strlen($prefix)) === 0
    );
    check(
        "{$scheme} generated hash verifies",
        ViMbAdmin_Dovecot::passwordVerify($scheme, $generated, $pw, 'u') === true
    );
    check(
        "{$scheme} generated hash rejects a wrong password",
        ViMbAdmin_Dovecot::passwordVerify($scheme, $generated, $wrong, 'u') === false
    );
}

$unsupportedRejected = false;
try {
    ViMbAdmin_Dovecot::password('PLAIN', $pw, 'u');
} catch (ViMbAdmin_Exception $e) {
    $unsupportedRejected = str_contains($e->getMessage(), 'Unsupported password scheme');
}
check('unsupported generation scheme retains its explicit error', $unsupportedRejected);

// Deterministic $6$ salt so the crypt template is stable across runs.
$sixCrypt = crypt($pw, '$6$0Z8YFxPS8T1Ac.0T');

// --- {SHA512-CRYPT} (the format actually stored for the reported account) ---
$stored = '{SHA512-CRYPT}' . $sixCrypt;
check(
    '{SHA512-CRYPT} prefixed, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $stored, $pw, 'u') === true
);
check(
    '{SHA512-CRYPT} prefixed, wrong pw rejected',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $stored, $wrong, 'u') === false
);

// --- {SHA256-CRYPT} ($5$) ---
$fiveCrypt = '{SHA256-CRYPT}' . crypt($pw, '$5$0Z8YFxPS8T1Ac.0T');
check(
    '{SHA256-CRYPT} prefixed, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $fiveCrypt, $pw, 'u') === true
);
check(
    '{SHA256-CRYPT} prefixed, wrong pw rejected',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $fiveCrypt, $wrong, 'u') === false
);

// --- {SHA512} plain base64 digest ---
$sha = '{SHA512}' . base64_encode(hash('sha512', $pw, true));
check(
    '{SHA512} digest, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $sha, $pw, 'u') === true
);
check(
    '{SHA512} digest, wrong pw rejected',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $sha, $wrong, 'u') === false
);

// --- {SHA256} plain base64 digest ---
$sha256 = '{SHA256}' . base64_encode(hash('sha256', $pw, true));
check(
    '{SHA256} digest, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $sha256, $pw, 'u') === true
);

// --- {SSHA512} salted base64 digest (digest || salt) ---
$salt  = "\x01\x02\x03\x04\x05\x06\x07\x08";
$ssha  = '{SSHA512}' . base64_encode(hash('sha512', $pw . $salt, true) . $salt);
check(
    '{SSHA512} salted, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $ssha, $pw, 'u') === true
);
check(
    '{SSHA512} salted, wrong pw rejected',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $ssha, $wrong, 'u') === false
);

// --- {SSHA256} salted base64 digest ---
$ssha256 = '{SSHA256}' . base64_encode(hash('sha256', $pw . $salt, true) . $salt);
check(
    '{SSHA256} salted, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $ssha256, $pw, 'u') === true
);

// Exact digest length is the zero-length-salt boundary for SSHA.
$ssha256EmptySalt = '{SSHA256}' . base64_encode(hash('sha256', $pw, true));
check(
    '{SSHA256} zero-length salt boundary verifies',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $ssha256EmptySalt, $pw, 'u') === true
);

// --- {BLF-CRYPT} bcrypt ---
$blf = '{BLF-CRYPT}' . password_hash($pw, PASSWORD_BCRYPT);
check(
    '{BLF-CRYPT} bcrypt, correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $blf, $pw, 'u') === true
);
check(
    '{BLF-CRYPT} bcrypt, wrong pw rejected',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $blf, $wrong, 'u') === false
);

// --- legacy bare hashes (no {SCHEME} prefix) must still verify ---
check(
    'bare $6$ crypt (no prefix), correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', $sixCrypt, $pw, 'u') === true
);
check(
    'bare $2 bcrypt (no prefix), correct pw',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', password_hash($pw, PASSWORD_BCRYPT), $pw, 'u') === true
);

// --- degenerate inputs ---
check(
    'empty stored hash → false',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', '', $pw, 'u') === false
);
check(
    'bad base64 in {SHA512} → false (not a crash)',
    ViMbAdmin_Dovecot::passwordVerify('dovecot', '{SHA512}!!!not-base64!!!', $pw, 'u') === false
);
check(
    'truncated {SSHA256} digest → false (not a crash)',
    ViMbAdmin_Dovecot::passwordVerify(
        'dovecot',
        '{SSHA256}' . base64_encode(substr(hash('sha256', $pw, true), 0, 31)),
        $pw,
        'u'
    ) === false
);
check(
    'oversized unsalted {SHA256} digest → false',
    ViMbAdmin_Dovecot::passwordVerify(
        'dovecot',
        '{SHA256}' . base64_encode(hash('sha256', $pw, true) . "\x00"),
        $pw,
        'u'
    ) === false
);

echo "\n" . ($failures === 0 ? "PASS" : "FAIL ({$failures})") . "\n";
if ($failures !== 0) {
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
