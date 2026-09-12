<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}

use ViMbAdmin\Kernel\Controller\AdminController;
use ViMbAdmin\Kernel\Controller\AliasController;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class BoundaryPreferenceAdmin extends Entities\Admin
{
    /** @var array<string,string> */
    private array $preferences = [];

    public function getPreference($attribute, $index = 0, $includeExpired = false)
    {
        return $this->preferences[$attribute] ?? false;
    }

    public function setPreference($attribute, $value, $operator = '=', $expires = 0, $index = 0)
    {
        $this->preferences[$attribute] = $value;
        return $this;
    }

    public function deletePreference($attribute, $index = null)
    {
        $removed = array_key_exists($attribute, $this->preferences) ? 1 : 0;
        unset($this->preferences[$attribute]);
        return $removed;
    }
}

final class BoundarySession implements SessionStorage
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values) {}
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __set(string $key, mixed $value): void { $this->set($key, $value); }
    public function __isset(string $key): bool { return $this->has($key); }
    public function __unset(string $key): void { $this->remove($key); }
}

final class BoundaryBootstrap
{
    public int $doctrineReads = 0;
    public function __construct(private BoundarySession $session) {}
    public function getResource(string $name): mixed
    {
        if ($name === 'namespace') { return $this->session; }
        if ($name === 'doctrine2') { $this->doctrineReads++; }
        throw new LogicException('Unexpected resource read: ' . $name);
    }
    /** @return array<string,mixed> */
    public function getOptions(): array { return []; }
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @param array<string,string|null> $params
 * @return array{T,BoundaryBootstrap}
 */
function boundaryController(string $class, string $action, array $params): array
{
    $session = new BoundarySession(['identity' => ['id' => 1], 'csrfToken' => 'csrf-sentinel']);
    $bootstrap = new BoundaryBootstrap($session);
    $admin = (new Entities\Admin())->setUsername('admin@example.test')->setSuper(true);
    $container = new Container($bootstrap, new Auth($session, static fn(int $id): Entities\Admin => $admin));
    $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action)))) . 'Action';
    return [new $class($container, new RouteMatch('test', $action, $class, $method, $params)), $bootstrap];
}

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) { $failures++; }
};
$invoke = static function (string $class, string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod($class, $method))->invoke(null, ...$arguments);
};
$fails = static function (callable $operation): bool {
    try { $operation(); } catch (LogicException) { return true; }
    return false;
};

echo "== admin, alias and two-factor input contracts ==\n";

$check('admin IDs preserve only positive canonical integers',
    $invoke(AdminController::class, 'positiveIntegerOrNull', 7) === 7
        && $invoke(AdminController::class, 'positiveIntegerOrNull', '12') === 12
        && $invoke(AdminController::class, 'positiveIntegerOrNull', '01') === null
        && $invoke(AdminController::class, 'positiveIntegerOrNull', '1junk') === null
        && $invoke(AdminController::class, 'positiveIntegerOrNull', true) === null
        && $invoke(AdminController::class, 'positiveIntegerOrNull', ['1']) === null);
$check('admin form strings and booleans reject container truthiness',
    $invoke(AdminController::class, 'requiredString', '0', 'Field') === '0'
        && $invoke(AdminController::class, 'checkboxBoolean', false, 'Flag') === false
        && $invoke(AdminController::class, 'checkboxBoolean', '1', 'Flag') === true
        && $fails(static fn(): mixed => $invoke(AdminController::class, 'requiredString', [], 'Field'))
        && $fails(static fn(): mixed => $invoke(AdminController::class, 'checkboxBoolean', ['1'], 'Flag')));
$check('malformed POST scalar fields become inert rather than stringifying',
    $invoke(AdminController::class, 'postStringOrEmpty', ['enable']) === ''
        && $invoke(AdminController::class, 'postStringOrEmpty', 'enable') === 'enable');
$check('malformed enrolment session state is regenerated by its caller',
    $invoke(AdminController::class, 'sessionSecret', null) === null
        && $invoke(AdminController::class, 'sessionSecret', '') === null
        && $invoke(AdminController::class, 'sessionSecret', ['secret']) === null
        && $invoke(AdminController::class, 'sessionSecret', 'BASE32') === 'BASE32');
$auth = ['resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512']]]];
$check('admin authentication options preserve an exact string-keyed map',
    $invoke(AdminController::class, 'requiredOptionArray', $auth, 'resources', 'auth', 'oss')
        === ['pwhash' => 'crypt:sha512']);
$check('admin security configuration fails closed on missing, scalar and list shapes',
    $fails(static fn(): mixed => $invoke(AdminController::class, 'requiredOptionArray', [], 'resources', 'auth', 'oss'))
        && $fails(static fn(): mixed => $invoke(AdminController::class, 'requiredOptionArray', ['resources' => 'bad'], 'resources', 'auth', 'oss'))
        && $fails(static fn(): mixed => $invoke(AdminController::class, 'requiredOptionArray', ['resources' => ['auth' => ['oss' => ['bad']]]], 'resources', 'auth', 'oss')));
$check('security salt defaults only when absent and rejects containers',
    $invoke(AdminController::class, 'optionString', [], '', 'securitysalt') === ''
        && $invoke(AdminController::class, 'optionString', ['securitysalt' => 'salt'], '', 'securitysalt') === 'salt'
        && $fails(static fn(): mixed => $invoke(AdminController::class, 'optionString', ['securitysalt' => ['salt']], '', 'securitysalt')));
[$invalidAdminController, $adminBootstrap] = boundaryController(
    AdminController::class,
    'purge',
    ['aid' => '1junk', 'csrf' => 'csrf-sentinel'],
);
$invalidAdminController->purgeAction();
$check('malformed admin id fails before repository or persistence access', $adminBootstrap->doctrineReads === 0);

$check('alias IDs reject direct-cast ambiguity',
    $invoke(AliasController::class, 'positiveIntegerOrNull', '9') === 9
        && $invoke(AliasController::class, 'positiveIntegerOrNull', '9junk') === null
        && $invoke(AliasController::class, 'positiveIntegerOrNull', 0) === null
        && $invoke(AliasController::class, 'positiveIntegerOrNull', false) === null);
$check('alias binary flags preserve documented encodings',
    $invoke(AliasController::class, 'binaryInteger', null, 0, 'Flag') === 0
        && $invoke(AliasController::class, 'binaryInteger', '1', 0, 'Flag') === 1
        && $invoke(AliasController::class, 'binaryInteger', '', 1, 'Flag') === 0
        && $fails(static fn(): mixed => $invoke(AliasController::class, 'binaryInteger', ['1'], 0, 'Flag')));
$check('alias DataTables scalar boundary never accepts container values',
    $invoke(AliasController::class, 'requestArray', ['draw' => '1', 0 => 'ignored']) === ['draw' => '1']
        && $fails(static fn(): mixed => $invoke(AliasController::class, 'requestArray', ['draw' => ['1']])));
$modernRequest = ['draw' => '2', 'search' => ['value' => 'mail'], 'order' => [['column' => '1', 'dir' => 'desc']], 'columns' => [['data' => 'address']]];
$check('alias DataTables boundary retains modern nested fields',
    $invoke(AliasController::class, 'requestArray', $modernRequest) === $modernRequest);
$check('alias DataTables boundary still rejects unrelated containers',
    $fails(static fn(): mixed => $invoke(AliasController::class, 'requestArray', ['did' => ['1']])));
$check('alias pagination config distinguishes absent from malformed',
    $invoke(AliasController::class, 'optionBoolean', [], false, 'defaults', 'server_side', 'pagination', 'enable') === false
        && $invoke(AliasController::class, 'optionBoolean', ['defaults' => ['server_side' => ['pagination' => ['enable' => '1']]]], false, 'defaults', 'server_side', 'pagination', 'enable') === true
        && $fails(static fn(): mixed => $invoke(AliasController::class, 'optionBoolean', ['defaults' => ['server_side' => ['pagination' => ['enable' => ['1']]]]], false, 'defaults', 'server_side', 'pagination', 'enable')));
$check('orphan alias relation fails before authorization or plugin context',
    $fails(static fn(): mixed => $invoke(AliasController::class, 'requiredAliasDomain', new Entities\Alias())));
[$invalidAliasController, $aliasBootstrap] = boundaryController(
    AliasController::class,
    'ajax-toggle-active',
    ['alid' => '1junk'],
);
$invalidAliasController->ajaxToggleActiveAction();
$check('malformed alias id fails before repository, authorization or plugin access', $aliasBootstrap->doctrineReads === 0);

$twoFactor = (new ReflectionClass(ViMbAdmin_TwoFactor::class))->newInstanceWithoutConstructor();
$check('TOTP normalizer accepts exact codes and rejects coercive shapes',
    $invoke(ViMbAdmin_TwoFactor::class, '_normalizedCode', '123 456', '/^\d{6}$/') === '123456'
        && $invoke(ViMbAdmin_TwoFactor::class, '_normalizedCode', ['123456'], '/^\d{6}$/') === null
        && $invoke(ViMbAdmin_TwoFactor::class, '_normalizedCode', '12345', '/^\d{6}$/') === null);
$check('TOTP verification rejects malformed secret and code containers',
    (new ReflectionMethod($twoFactor, 'verifyCode'))->invoke($twoFactor, ['secret'], ['123456']) === false);
$check('replay timestamp parser accepts canonical non-negative values only',
    $invoke(ViMbAdmin_TwoFactor::class, '_nonNegativeIntegerOrNull', 0) === 0
        && $invoke(ViMbAdmin_TwoFactor::class, '_nonNegativeIntegerOrNull', '12') === 12
        && $invoke(ViMbAdmin_TwoFactor::class, '_nonNegativeIntegerOrNull', '01') === null
        && $invoke(ViMbAdmin_TwoFactor::class, '_nonNegativeIntegerOrNull', -1) === null
        && $invoke(ViMbAdmin_TwoFactor::class, '_nonNegativeIntegerOrNull', ['12']) === null);
$check('missing replay state starts at zero while corrupt state fails closed',
    $invoke(ViMbAdmin_TwoFactor::class, '_storedReplaySlice', false) === 0
        && $invoke(ViMbAdmin_TwoFactor::class, '_storedReplaySlice', null) === 0
        && $invoke(ViMbAdmin_TwoFactor::class, '_storedReplaySlice', ['12']) === null);

$preferenceAdmin = new BoundaryPreferenceAdmin();
$check('2FA enabled state requires a non-empty stored string',
    $twoFactor->isEnabled($preferenceAdmin) === false
        && $preferenceAdmin->setPreference(ViMbAdmin_TwoFactor::PREF_SECRET, 'cipher') === $preferenceAdmin
        && $twoFactor->isEnabled($preferenceAdmin) === true);
$twoFactor->setForce($preferenceAdmin, true);
$forced = $twoFactor->isForced($preferenceAdmin);
$twoFactor->clearForce($preferenceAdmin);
$check('string-backed forced-enrolment preference remains compatible',
    $forced === true && $twoFactor->isForced($preferenceAdmin) === false);
$preferenceAdmin->setPreference(ViMbAdmin_TwoFactor::PREF_BACKUP, '[123]');
$check('corrupt backup-code members fail closed',
    $twoFactor->consumeBackupCode($preferenceAdmin, '23456789AB') === false
        && $twoFactor->backupCodesRemaining($preferenceAdmin) === 0);
$preferenceAdmin->setPreference(ViMbAdmin_TwoFactor::PREF_BACKUP, '{"named":"hash"}');
$check('object-shaped backup-code state fails closed',
    $twoFactor->consumeBackupCode($preferenceAdmin, '23456789AB') === false
        && $twoFactor->backupCodesRemaining($preferenceAdmin) === 0);
$check('backup-code input containers cannot stringify into credentials',
    (new ReflectionMethod($twoFactor, 'consumeBackupCode'))
        ->invoke($twoFactor, $preferenceAdmin, ['23456789AB']) === false);
$plainCodes = $twoFactor->regenerateBackupCodes($preferenceAdmin, 2);
$generatedCount = count($plainCodes);
$remainingBefore = $twoFactor->backupCodesRemaining($preferenceAdmin);
$firstUse = $twoFactor->consumeBackupCode($preferenceAdmin, $plainCodes[0]);
$secondUse = $twoFactor->consumeBackupCode($preferenceAdmin, $plainCodes[0]);
$remainingAfter = $twoFactor->backupCodesRemaining($preferenceAdmin);
$check('valid backup codes remain single-use and update the exact remaining count',
    [$generatedCount, $remainingBefore, $firstUse, $secondUse, $remainingAfter]
        === [2, 2, true, false, 1]);

$check('fixed assertion count', $checks === 26);
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
