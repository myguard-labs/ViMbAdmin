<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/PasswordResetToken.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$token = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcd';
$other = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabce';
$verifier = ViMbAdmin_PasswordResetToken::verifier($token);

$check('stored verifier does not contain the live bearer', !str_contains($verifier, $token));
$check('stored verifier has an explicit version and fixed shape', preg_match('/^sha256:[a-f0-9]{64}$/D', $verifier) === 1);
$check('stored verifier is recognized for migration', ViMbAdmin_PasswordResetToken::isVerifier($verifier));
$check('valid bearer matches its verifier', ViMbAdmin_PasswordResetToken::matches($verifier, $token));
$check('one-byte boundary mismatch is rejected', !ViMbAdmin_PasswordResetToken::matches($verifier, $other));
$check('legacy raw database values fail closed', !ViMbAdmin_PasswordResetToken::matches($token, $token));
$check('malformed verifier fails closed', !ViMbAdmin_PasswordResetToken::matches('sha256:not-a-digest', $token));
$check('malformed verifier is removed by migration', !ViMbAdmin_PasswordResetToken::isVerifier('sha256:not-a-digest'));
$check('empty bearer cannot match a valid verifier', !ViMbAdmin_PasswordResetToken::matches($verifier, ''));

$mixedPreferences = [];
for ($index = 0; $index < 4; $index++) {
    $mixedPreferences[] = [
        'p_index' => $index,
        'p_value' => ViMbAdmin_PasswordResetToken::verifier(str_repeat((string) $index, 40)),
    ];
}
$mixedPreferences[] = ['p_index' => 4, 'p_value' => $token];
$legacyIndexes = ViMbAdmin_PasswordResetToken::legacyIndexes($mixedPreferences);
$check('migration selects only the legacy raw preference', $legacyIndexes === [4]);
$check(
    'migration preserves four verifiers so one new request reaches the five-token limit',
    count($mixedPreferences) - count($legacyIndexes) + 1 === 5
);
$check(
    'migration accepts an admin with no existing reset preferences',
    ViMbAdmin_PasswordResetToken::legacyIndexes(false) === []
);
$check(
    'migration ignores malformed preference rows safely',
    ViMbAdmin_PasswordResetToken::legacyIndexes([['p_index' => '0', 'p_value' => []]]) === []
);

$template = file_get_contents(__DIR__ . '/../application/views/auth/email/html/lost-password.phtml');
$controller = file_get_contents(__DIR__ . '/../src/Kernel/Controller/AuthController.php');
$repository = file_get_contents(__DIR__ . '/../application/Repositories/Admin.php');
$check(
    'reset email URL excludes the bearer token parameter',
    is_string($template)
        && !preg_match('/genUrl[^}]*\btoken\s*=/', $template)
        && str_contains($template, '<strong>{$token}</strong>')
);
$check(
    'reset GET never imports a token from route parameters',
    is_string($controller) && !preg_match('/param\([\'\"]token[\'\"]\)/', $controller)
);
$check(
    'new reset preferences persist only a verifier',
    is_string($controller)
        && str_contains($controller, 'ViMbAdmin_PasswordResetToken::verifier($token)')
        && str_contains($controller, 'ViMbAdmin_PasswordResetToken::legacyIndexes($storedTokens)')
        && !str_contains($controller, '$user->deletePreference(\'tokens.password_reset\');')
        && !str_contains($controller, 'addIndexedPreference(\'tokens.password_reset\', $token')
);
$check(
    'atomic reset lookup compares the derived verifier',
    is_string($repository)
        && str_contains($repository, 'ViMbAdmin_PasswordResetToken::verifier($token)')
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
