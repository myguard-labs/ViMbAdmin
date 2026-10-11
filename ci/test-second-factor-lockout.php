<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once __DIR__ . '/../application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class SecondFactorLockoutSession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }
}

#[AllowDynamicProperties]
final class SecondFactorLockoutView
{
    /** @var array<string,mixed> */
    private array $values = [];

    public function __set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function render(string $script): string
    {
        $form = $this->values['formHtml'] ?? null;
        return is_string($form) ? $form : $script;
    }
}

final class SecondFactorLockoutAdmin extends \Entities\Admin
{
    /** @var array<string,string> */
    private array $testPreferences = [];

    public function getPreference($attribute, $index = 0, $includeExpired = false)
    {
        return $this->testPreferences[$attribute] ?? false;
    }

    public function setPreference($attribute, $value, $operator = '=', $expires = 0, $index = 0)
    {
        $this->testPreferences[$attribute] = $value;
        return $this;
    }

    public function deletePreference($attribute, $index = null)
    {
        $removed = array_key_exists($attribute, $this->testPreferences) ? 1 : 0;
        unset($this->testPreferences[$attribute]);
        return $removed;
    }

    public function compareAndSwapPreference(
        string $attribute,
        string|false $expected,
        string $replacement,
    ): bool {
        if (($this->testPreferences[$attribute] ?? false) !== $expected) {
            return false;
        }
        $this->testPreferences[$attribute] = $replacement;
        return true;
    }
}

final class SecondFactorLockoutRepository extends \Repositories\Admin
{
    public static ?SecondFactorLockoutAdmin $admin = null;

    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return self::$admin;
    }
}

final class SecondFactorLockoutBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EntityManager $entityManager,
        private SecondFactorLockoutSession $session,
        private SecondFactorLockoutView $view,
        private array $options,
    ) {
    }

    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->session,
            'smarty' => $this->view,
            default => throw new LogicException('Unexpected resource: ' . $name),
        };
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

/**
 * @param array<string,mixed> $options
 * @return array{0:AuthController,1:Container,2:SecondFactorLockoutSession}
 */
function secondFactorLockoutController(
    SecondFactorLockoutAdmin $admin,
    array $options,
): array {
    SecondFactorLockoutRepository::$admin = $admin;
    $configuration = Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Admin::class)
        ->setCustomRepositoryClass('\\' . SecondFactorLockoutRepository::class);

    $session = new SecondFactorLockoutSession([
        'csrfToken' => 'ci038-csrf',
        'totp_pending_admin_id' => 38,
        'totp_pending_via' => 'auth',
    ]);
    $container = new Container(
        new SecondFactorLockoutBootstrap(
            $entityManager,
            $session,
            new SecondFactorLockoutView(),
            $options,
        ),
        new Auth($session, static fn (int $id): ?object => SecondFactorLockoutRepository::$admin),
    );
    OSS_Runtime::configure($options, '', $entityManager);

    return [
        new AuthController(
            $container,
            new RouteMatch('auth', 'totp', AuthController::class, 'totpAction', []),
        ),
        $container,
        $session,
    ];
}

/** @return array{0:SecondFactorLockoutAdmin,1:string} */
function secondFactorLockoutAdmin(string $scenario, string $securitySalt): array
{
    $admin = new SecondFactorLockoutAdmin();
    $admin->setUsername('ci038@example.test');
    $admin->setActive($scenario !== 'inactive');
    $admin->setSuper(true);
    (new ReflectionMethod($admin, 'assignGeneratedId'))->invoke($admin, 38);

    if ($scenario === 'valid-backup') {
        $code = '23456789AB';
        $admin->setPreference(
            ViMbAdmin_TwoFactor::PREF_BACKUP,
            json_encode([password_hash($code, PASSWORD_BCRYPT)], JSON_THROW_ON_ERROR),
        );
        return [$admin, $code];
    }
    if ($scenario === 'valid-totp') {
        $twoFactor = new ViMbAdmin_TwoFactor('ViMbAdmin', $securitySalt);
        $secret = $twoFactor->createSecret();
        $encrypted = (new ReflectionMethod($twoFactor, '_encrypt'))->invoke($twoFactor, $secret);
        $engine = (new ReflectionProperty($twoFactor, '_tfa'))->getValue($twoFactor);
        if (!is_string($encrypted) || !$engine instanceof RobThree\Auth\TwoFactorAuth) {
            throw new RuntimeException('Could not create the TOTP fixture');
        }
        $admin->setPreference(ViMbAdmin_TwoFactor::PREF_SECRET, $encrypted);
        return [$admin, $engine->getCode($secret)];
    }

    return [$admin, '000000'];
}

/** @param list<string> $argv */
function secondFactorLockoutProbe(array $argv): never
{
    $scenario = $argv[2] ?? '';
    $stateDirectory = $argv[3] ?? '';
    $seed = filter_var($argv[4] ?? null, FILTER_VALIDATE_INT);
    if ($scenario === '' || $stateDirectory === '' || !is_int($seed) || $seed < 0) {
        throw new InvalidArgumentException('Probe requires scenario, state directory and seed count');
    }

    $statusPath = $stateDirectory . '.http-status';
    register_shutdown_function(static function () use ($statusPath): void {
        file_put_contents($statusPath, (string) http_response_code());
    });

    $_SERVER['REMOTE_ADDR'] = '192.0.2.38';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $disabled = $scenario === 'disabled';
    $allowlisted = $scenario === 'allowlisted';
    $options = [
        'securitysalt' => str_repeat('s', 64),
        'bruteforce' => [
            'enabled' => $disabled ? '0' : '1',
            'max_attempts' => '3',
            'window' => '900',
            'lockout' => '900',
            'statedir' => $stateDirectory,
            'whitelist' => $allowlisted ? ['192.0.2.0/24'] : [],
        ],
    ];
    $bruteForce = new ViMbAdmin_BruteForce(null, $options['bruteforce']);
    for ($attempt = 0; $attempt < $seed; $attempt++) {
        $bruteForce->record('seed@example.test', null);
    }

    [$admin, $code] = secondFactorLockoutAdmin($scenario, $options['securitysalt']);
    [$controller, $container, $session] = secondFactorLockoutController($admin, $options);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => 'ci038-csrf', 'code' => $code];
    if ($scenario === 'missing-csrf') {
        unset($_POST['csrf']);
    } elseif ($scenario === 'malformed') {
        $_POST['code'] = ['000000'];
    }

    $response = $controller->totpAction();
    echo json_encode([
        'status' => $response->status,
        'location' => $response->headers['Location'] ?? null,
        'authenticated' => $container->auth()->isAuthenticated(),
        'pending' => $session->get('totp_pending_admin_id'),
        'pending_via' => $session->get('totp_pending_via'),
        'locked' => $bruteForce->isLocked(null),
        'body' => $response->body,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if (($argv[1] ?? null) === '--probe') {
    secondFactorLockoutProbe($argv);
}

$evidenceRoot = sys_get_temp_dir() . '/vimbadmin-ci038-' . bin2hex(random_bytes(8));
if (!mkdir($evidenceRoot, 0700, true) && !is_dir($evidenceRoot)) {
    throw new RuntimeException('Could not create probe state root');
}

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array{status:int,stdout:string,stderr:string,json:?array<string,mixed>,http_status:?int} */
$probe = static function (string $scenario, int $seed = 0) use ($evidenceRoot): array {
    $stateDirectory = $evidenceRoot . '/' . $scenario . '-' . bin2hex(random_bytes(4));
    $statusPath = $stateDirectory . '.http-status';
    $command = [PHP_BINARY, __FILE__, '--probe', $scenario, $stateDirectory, (string) $seed];
    $pipes = [];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__ . '/..');
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start probe');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $decoded = json_decode($stdout, true);
    $httpStatus = is_file($statusPath)
        ? filter_var(trim((string) file_get_contents($statusPath)), FILTER_VALIDATE_INT)
        : false;
    return [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'json' => is_array($decoded) ? $decoded : null,
        'http_status' => is_int($httpStatus) ? $httpStatus : null,
    ];
};

$below = $probe('invalid', 1);
$check(
    'boundary below threshold keeps the challenge available',
    $below['status'] === 0
        && $below['stderr'] === ''
        && ($below['json']['status'] ?? null) === 200
        && ($below['json']['locked'] ?? null) === false
        && ($below['json']['pending'] ?? null) === 38,
);

$threshold = $probe('invalid', 2);
$check(
    'threshold-crossing second-factor failure is rejected with the lockout response',
    $threshold['status'] === 0
        && $threshold['stderr'] === ''
        && $threshold['http_status'] === 429
        && $threshold['stdout'] === 'Too many failed login attempts. Try again later.',
);

$alreadyLocked = $probe('valid-backup', 3);
$check(
    'an already-locked source cannot submit another second-factor guess',
    $alreadyLocked['status'] === 0
        && $alreadyLocked['stderr'] === ''
        && $alreadyLocked['http_status'] === 429
        && $alreadyLocked['stdout'] === 'Too many failed login attempts. Try again later.',
);

foreach (['valid-totp', 'valid-backup'] as $successScenario) {
    $success = $probe($successScenario, 2);
    $check(
        $successScenario . ' succeeds below threshold and clears pending lockout state',
        $success['status'] === 0
            && $success['stderr'] === ''
            && ($success['json']['status'] ?? null) === 302
            && ($success['json']['location'] ?? null) === '/'
            && ($success['json']['authenticated'] ?? null) === true
            && array_key_exists('pending', $success['json'])
            && $success['json']['pending'] === null
            && ($success['json']['locked'] ?? null) === false,
    );
}

foreach (['missing-csrf', 'malformed'] as $invalidFormScenario) {
    $invalidForm = $probe($invalidFormScenario, 3);
    $check(
        $invalidFormScenario . ' form error is handled before second-factor lockout enforcement',
        $invalidForm['status'] === 0
            && $invalidForm['stderr'] === ''
            && ($invalidForm['json']['status'] ?? null) === 200
            && ($invalidForm['json']['authenticated'] ?? null) === false
            && ($invalidForm['json']['pending'] ?? null) === 38
            && ($invalidForm['json']['locked'] ?? null) === true,
    );
}

foreach (['disabled', 'allowlisted'] as $negativeScenario) {
    $negative = $probe($negativeScenario, 3);
    $check(
        $negativeScenario . ' brute-force policy does not reject or lock the failed code',
        $negative['status'] === 0
            && $negative['stderr'] === ''
            && ($negative['json']['status'] ?? null) === 200
            && ($negative['json']['locked'] ?? null) === false
            && ($negative['json']['pending'] ?? null) === 38,
    );
}

$inactive = $probe('inactive');
$check(
    'inactive pending administrator is rejected and pending session state is cleared',
    $inactive['status'] === 0
        && $inactive['stderr'] === ''
        && ($inactive['json']['status'] ?? null) === 302
        && ($inactive['json']['location'] ?? null) === '/auth/login'
        && ($inactive['json']['authenticated'] ?? null) === false
        && array_key_exists('pending', $inactive['json'])
        && $inactive['json']['pending'] === null
        && array_key_exists('pending_via', $inactive['json'])
        && $inactive['json']['pending_via'] === null,
);

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . '/' . $entry;
        is_dir($target) ? $removeTree($target) : unlink($target);
    }
    rmdir($path);
};
$removeTree($evidenceRoot);

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
