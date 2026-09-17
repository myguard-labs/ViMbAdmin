<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';

final class AdminAuthContractState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function adminAuthCheck(string $label, bool $condition): void
{
    AdminAuthContractState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        AdminAuthContractState::$failures++;
    }
}

/** @return string|null */
function adminAuthFailure(callable $operation): ?string
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    return null;
}

function adminAuthControllerHelper(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AuthController::class, $method))
        ->invoke(null, ...$arguments);
}

echo "== required admin authentication fields ==\n";

$fresh = new \Entities\Admin();
adminAuthCheck(
    'pre-hydration getters preserve null',
    $fresh->getUsername() === null
        && $fresh->getPassword() === null
        && $fresh->getSuper() === null,
);
adminAuthCheck(
    'required username rejects pre-hydration null',
    adminAuthFailure($fresh->requiredUsername(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'email identity rejects pre-hydration null',
    adminAuthFailure($fresh->getEmail(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'formatted identity rejects pre-hydration null',
    adminAuthFailure($fresh->getFormattedName(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'required password rejects pre-hydration null',
    adminAuthFailure($fresh->requiredPassword(...)) === 'Admin password cannot be null.',
);
adminAuthCheck('pre-hydration super check denies privilege', $fresh->isSuper() === false);

$options = ['pwhash' => 'crypt:sha512'];
$hash = \OSS_Auth_Password::hash('correct horse', $options);
$initialized = (new \Entities\Admin())
    ->setUsername('admin@example.test')
    ->setPassword($hash)
    ->setSuper(true);
adminAuthCheck(
    'initialized identity helpers preserve the username',
    $initialized->requiredUsername() === 'admin@example.test'
        && $initialized->getEmail() === 'admin@example.test'
        && $initialized->getFormattedName() === 'admin@example.test',
);
adminAuthCheck('initialized required password preserves the hash', $initialized->requiredPassword() === $hash);
adminAuthCheck('initialized super check preserves privilege', $initialized->isSuper() === true);

$adminMatcher = new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AdminController::class, 'adminPasswordMatches');
$authMatcher = new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AuthController::class, 'verifiedAdminPassword');
adminAuthCheck(
    'admin password form rejects an uninitialized hash',
    $adminMatcher->invoke(null, $fresh, 'correct horse', $options) === false,
);
adminAuthCheck(
    'login rejects an uninitialized hash',
    $authMatcher->invoke(null, $fresh, 'correct horse', $options) === null,
);
adminAuthCheck(
    'login rejects malformed password configuration',
    $authMatcher->invoke(null, $initialized, 'correct horse', null) === null,
);
adminAuthCheck(
    'both authentication boundaries accept the initialized matching hash',
    $adminMatcher->invoke(null, $initialized, 'correct horse', $options) === true
        && $authMatcher->invoke(null, $initialized, 'correct horse', $options) === $hash,
);
adminAuthCheck(
    'both authentication boundaries reject an incorrect password',
    $adminMatcher->invoke(null, $initialized, 'wrong horse', $options) === false
        && $authMatcher->invoke(null, $initialized, 'wrong horse', $options) === null,
);

adminAuthCheck(
    'required string preserves exact input',
    adminAuthControllerHelper('requiredString', '', 'Field') === ''
);
adminAuthCheck(
    'required string rejects non-string input',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('requiredString', [], 'Field'))
        === 'Field must be a string'
);
adminAuthCheck(
    'optional string defaults only when absent',
    adminAuthControllerHelper('stringOrDefault', null, 'fallback', 'Field') === 'fallback'
        && adminAuthControllerHelper('stringOrDefault', '0', 'fallback', 'Field') === '0'
);
adminAuthCheck(
    'optional string rejects a present wrong type',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('stringOrDefault', false, '', 'Field'))
        === 'Field must be a string'
);
adminAuthCheck(
    'application redirect preserves only internal paths',
    adminAuthControllerHelper('applicationPathOrDefault', 'mailbox/list', '') === 'mailbox/list'
        && adminAuthControllerHelper('applicationPathOrDefault', '', 'fallback') === ''
);
adminAuthCheck(
    'application redirect rejects external and control-character targets',
    adminAuthControllerHelper('applicationPathOrDefault', 'https://example.test', '') === ''
        && adminAuthControllerHelper('applicationPathOrDefault', "mailbox\r\nX-Test: yes", '') === ''
);
adminAuthCheck(
    'positive integer accepts int and canonical decimal string',
    adminAuthControllerHelper('integerOrNull', 7) === 7
        && adminAuthControllerHelper('integerOrNull', '7') === 7
);
adminAuthCheck(
    'positive integer rejects ambiguous and out-of-domain input',
    adminAuthControllerHelper('integerOrNull', 0) === null
        && adminAuthControllerHelper('integerOrNull', '01') === null
        && adminAuthControllerHelper('integerOrNull', '1e2') === null
        && adminAuthControllerHelper('integerOrNull', []) === null
);
adminAuthCheck(
    'string-keyed map preserves valid configuration',
    adminAuthControllerHelper('stringKeyedArray', ['mode' => 'safe'], 'Config') === ['mode' => 'safe']
);
adminAuthCheck(
    'string-keyed map rejects list-shaped configuration',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('stringKeyedArray', ['unsafe'], 'Config'))
        === 'Config must use string keys'
);

$nested = ['resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512']]]];
adminAuthCheck(
    'option traversal preserves a nested configured value',
    adminAuthControllerHelper('option', $nested, 'resources', 'auth', 'oss', 'pwhash') === 'crypt:sha512'
);
adminAuthCheck(
    'option traversal preserves an absent value as null',
    adminAuthControllerHelper('option', $nested, 'resources', 'auth', 'missing') === null
);
adminAuthCheck(
    'option traversal rejects a malformed intermediate node',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper(
        'option',
        ['resources' => 'invalid'],
        'resources',
        'auth',
        'oss',
    )) === 'Configuration resources must be an array'
);
adminAuthCheck(
    'option traversal rejects list-shaped intermediate nodes',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper(
        'option',
        ['resources' => [['auth' => []]]],
        'resources',
        'auth',
        'oss',
    )) === 'Configuration resources must use string keys'
);
adminAuthCheck(
    'string option preserves configured zero and missing default',
    adminAuthControllerHelper('optionString', ['value' => '0'], 'fallback', 'value') === '0'
        && adminAuthControllerHelper('optionString', [], 'fallback', 'value') === 'fallback'
);
adminAuthCheck(
    'integer option accepts INI decimal strings and missing default',
    adminAuthControllerHelper('optionInt', ['value' => '12'], 8, 'value') === 12
        && adminAuthControllerHelper('optionInt', [], 8, 'value') === 8
);
adminAuthCheck(
    'integer option rejects coercive numeric shapes',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('optionInt', ['value' => '01x'], 8, 'value'))
        === 'Configuration value must be a non-negative integer'
);
adminAuthCheck(
    'boolean option accepts native INI encodings',
    adminAuthControllerHelper('optionBool', ['value' => '1'], false, 'value') === true
        && adminAuthControllerHelper('optionBool', ['value' => ''], true, 'value') === false
);
adminAuthCheck(
    'boolean option rejects non-boolean strings',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('optionBool', ['value' => 'yes'], false, 'value'))
        === 'Configuration value must be boolean'
);
adminAuthCheck(
    'password options preserve string and map configuration',
    adminAuthControllerHelper('passwordOptions', ['resources' => ['auth' => ['oss' => 'crypt:sha512']]])
        === 'crypt:sha512'
        && adminAuthControllerHelper('passwordOptions', $nested) === ['pwhash' => 'crypt:sha512']
);
adminAuthCheck(
    'password options reject missing security configuration',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper('passwordOptions', []))
        === 'Configuration resources.auth.oss must be an array'
);
adminAuthCheck(
    'authentication email configuration accepts documented defaults',
    adminAuthControllerHelper('validateAuthEmailOptions', []) === null
);
adminAuthCheck(
    'authentication email configuration rejects header controls',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper(
        'validateAuthEmailOptions',
        ['identity' => ['sitename' => "Site\nBcc: attacker@example.test"]],
    )) === 'Authentication email configuration contains control characters'
);
adminAuthCheck(
    'authentication email configuration rejects unknown formats',
    adminAuthFailure(static fn (): mixed => adminAuthControllerHelper(
        'validateAuthEmailOptions',
        ['resources' => ['auth' => ['oss' => ['email_format' => 'markdown']]]],
    )) === 'Configuration resources.auth.oss.email_format is invalid'
);

adminAuthCheck('fixed assertion count', AdminAuthContractState::$checks === 38);

echo AdminAuthContractState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . AdminAuthContractState::$failures . " assertion(s) failed\n";
exit(AdminAuthContractState::$failures === 0 ? 0 : 1);
