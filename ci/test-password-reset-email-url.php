<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * @return array{href: string, visible: string}|null
 */
$resetLink = static function (string $template): ?array {
    if (preg_match_all(
        '/<a\s+href="(\{genUrl\b[^"]*\})">(\{genUrl\b[^<]*\})<\/a>/',
        $template,
        $matches,
        PREG_SET_ORDER
    ) !== 1) {
        return null;
    }

    return ['href' => $matches[0][1], 'visible' => $matches[0][2]];
};

$template = file_get_contents(__DIR__ . '/../application/views/auth/email/html/lost-password.phtml');
$link = is_string($template) ? $resetLink($template) : null;
$expectedUrl = "{genUrl controller='auth' action='reset-password' username=\$user->getUsername()}";

$check('password-reset email template is readable and has one generated URL link', $link !== null);
$check(
    'password-reset email href uses the working reset-password route',
    $link !== null && $link['href'] === $expectedUrl
);
$check(
    'password-reset email visible URL exactly matches its href',
    $link !== null && $link['visible'] === $link['href']
);
$check(
    'password-reset email keeps the username route boundary in both URL forms',
    $link !== null
        && substr_count($link['href'], 'username=$user->getUsername()') === 1
        && substr_count($link['visible'], 'username=$user->getUsername()') === 1
);

$legacyMismatch = <<<'HTML'
<a href="{genUrl controller='auth' action='reset-password' username=$user->getUsername()}">{genUrl controller='auth' action='password-reset' username=$user->getUsername()}</a>
HTML;
$legacyLink = $resetLink($legacyMismatch);
$check(
    'legacy password-reset visible route is rejected when the href uses reset-password',
    $legacyLink !== null && $legacyLink['visible'] !== $legacyLink['href']
);
$check(
    'malformed generated URL anchor is rejected',
    $resetLink('<a href="{genUrl controller=\'auth\' action=\'reset-password\'}">reset password</a>') === null
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
