<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/bruteforce-state-path.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once __DIR__ . '/../application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AdminController;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

const AUTH_INPUT_TEST_CREDENTIAL = 'correct horse';

final class AuthInputShapeSession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
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
final class AuthInputShapeView
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

final class AuthInputShapeAdminRepository extends \Repositories\Admin
{
    public int $findCalls = 0;
    public int $findOneByCalls = 0;
    public static ?\Entities\Admin $admin = null;
    public static int $count = 1;
    /** @var list<array{0:string,1:string}> */
    public array $passwordSwaps = [];
    public int $passwordSwapAffected = 1;
    public bool $passwordSwapThrows = false;

    public function compareAndSwapPassword(\Entities\Admin $admin, string $verified, string $replacement): bool
    {
        $this->passwordSwaps[] = [$verified, $replacement];
        if ($this->passwordSwapThrows) {
            throw new RuntimeException('expected rehash persistence failure');
        }
        if ($this->passwordSwapAffected === 1) {
            $admin->setPassword($replacement);
            return true;
        }
        return false;
    }

    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->findCalls++;
        return self::$admin;
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $this->findOneByCalls++;
        return self::$admin;
    }

    public function getCount(): int
    {
        return self::$count;
    }
}

final class AuthInputShapeAdmin extends \Entities\Admin
{
    /** @var array<string,string> */
    private array $testPreferences = [];
    private bool $deactivateDuringTwoFactorEnable = false;
    private ?string $breakBruteForceStateOnPasswordRead = null;

    public function deactivateDuringTwoFactorEnable(): void
    {
        $this->deactivateDuringTwoFactorEnable = true;
    }

    public function breakBruteForceStateOnPasswordRead(string $stateDirectory): void
    {
        $this->breakBruteForceStateOnPasswordRead = $stateDirectory;
    }

    public function getPassword()
    {
        $password = parent::getPassword();
        $stateDirectory = $this->breakBruteForceStateOnPasswordRead;
        if ($stateDirectory !== null) {
            $this->breakBruteForceStateOnPasswordRead = null;
            $entries = scandir($stateDirectory);
            if (!is_array($entries)) {
                throw new RuntimeException('could not inspect late brute-force persistence fixture');
            }
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                unlink($stateDirectory . '/' . $entry);
            }
            if (!rmdir($stateDirectory)
                || file_put_contents($stateDirectory, 'not a directory') === false) {
                throw new RuntimeException('could not create late brute-force persistence fault');
            }
        }

        return $password;
    }

    public function getPreference($attribute, $index = 0, $includeExpired = false)
    {
        return $this->testPreferences[$attribute] ?? false;
    }

    public function setPreference($attribute, $value, $operator = '=', $expires = 0, $index = 0)
    {
        $this->testPreferences[$attribute] = $value;
        if ($this->deactivateDuringTwoFactorEnable && $attribute === \ViMbAdmin_TwoFactor::PREF_BACKUP) {
            $this->setActive(false);
        }
        return $this;
    }

    public function deletePreference($attribute, $index = null)
    {
        $removed = array_key_exists($attribute, $this->testPreferences) ? 1 : 0;
        unset($this->testPreferences[$attribute]);
        return $removed;
    }

    public function hasTestPreference(string $attribute): bool
    {
        return array_key_exists($attribute, $this->testPreferences);
    }
}

final class AuthInputShapeFlushListener
{
    public int $flushes = 0;
    public bool $stopBeforeDatabase = false;
    public function onFlush(): void
    {
        $this->flushes++;
        if ($this->stopBeforeDatabase) {
            throw new RuntimeException('expected test stop before database write');
        }
    }
}

final class AuthInputShapeBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EntityManager $entityManager,
        private AuthInputShapeSession $session,
        private AuthInputShapeView $view,
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

final class AuthInputShapeState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function authInputShapeCheck(string $label, bool $condition): void
{
    AuthInputShapeState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        AuthInputShapeState::$failures++;
    }
}

/**
 * @param array<string,mixed>|null $options
 * @return array{controller:AuthController,container:Container,entityManager:EntityManager,session:AuthInputShapeSession,listener:AuthInputShapeFlushListener,repository:AuthInputShapeAdminRepository}
 */
function authInputShapeController(
    mixed $pendingId,
    ?\Entities\Admin $admin = null,
    string $action = 'totp',
    ?array $options = null,
    int $adminCount = 1,
    bool $authenticated = false,
): array {
    AuthInputShapeAdminRepository::$admin = $admin
        ?? (new \Entities\Admin())
            ->setUsername('admin@example.test')
            ->setActive(true);
    AuthInputShapeAdminRepository::$count = $adminCount;
    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Admin::class)
        ->setCustomRepositoryClass('\\' . AuthInputShapeAdminRepository::class);
    $repository = $entityManager->getRepository(\Entities\Admin::class);
    if (!$repository instanceof AuthInputShapeAdminRepository) {
        throw new LogicException('Unexpected Admin repository type');
    }
    $listener = new AuthInputShapeFlushListener();
    $entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);

    $sessionData = [
        'csrfToken' => 'csrf-sentinel',
        'totp_pending_admin_id' => $pendingId,
        'totp_pending_via' => 'auth',
    ];
    if ($authenticated) {
        $sessionData['identity'] = ['id' => 41, 'username' => AuthInputShapeAdminRepository::$admin?->getUsername()];
    }
    $session = new AuthInputShapeSession($sessionData);
    $view = new AuthInputShapeView();
    $options ??= ['securitysalt' => str_repeat('s', 64)];
    $container = new Container(
        new AuthInputShapeBootstrap($entityManager, $session, $view, $options),
        new Auth($session, static fn (int $id): ?\Entities\Admin => $authenticated ? AuthInputShapeAdminRepository::$admin : null),
    );

    $method = match ($action) {
        'login' => 'loginAction',
        'setup' => 'setupAction',
        'totp-setup' => 'totpSetupAction',
        default => 'totpAction',
    };

    return [
        'controller' => new AuthController(
            $container,
            new RouteMatch('auth', $action, AuthController::class, $method, []),
        ),
        'container' => $container,
        'entityManager' => $entityManager,
        'session' => $session,
        'listener' => $listener,
        'repository' => $repository,
    ];
}

/** @param array<string,string> $preferences */
function authInputShapeAdmin(bool $active, array $preferences = []): AuthInputShapeAdmin
{
    $passwordOptions = ['pwhash' => 'crypt:sha512'];
    $admin = new AuthInputShapeAdmin();
    $admin->setUsername('admin@example.test');
    $admin->setPassword(\OSS_Auth_Password::hash(AUTH_INPUT_TEST_CREDENTIAL, $passwordOptions));
    $admin->setSuper(true);
    $admin->setActive($active);
    (new ReflectionMethod($admin, 'assignGeneratedId'))->invoke($admin, 41);
    foreach ($preferences as $key => $value) {
        $admin->setPreference($key, $value);
    }

    return $admin;
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function authInputShapeLoginOptions(string $stateDirectory, array $extra = []): array
{
    return $extra + [
        'securitysalt' => str_repeat('s', 64),
        'resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512']]],
        'bruteforce' => [
            'enabled' => '1',
            'max_attempts' => '20',
            'statedir' => $stateDirectory,
        ],
    ];
}

function authInputShapeBruteForceAttempts(string $stateDirectory, string $ip): ?int
{
    $path = bruteForceStatePath($stateDirectory, $ip);
    $contents = is_file($path) ? file_get_contents($path) : false;
    $state = is_string($contents) ? json_decode($contents, true) : null;

    return is_array($state) && is_int($state['attempts'] ?? null)
        ? $state['attempts']
        : null;
}

function authInputShapeLogin(AuthController $controller, string $password): \ViMbAdmin\Kernel\Http\Response
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $post = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'csrf' => 'csrf-sentinel',
        'username' => 'admin@example.test',
        'password' => $password,
    ];

    try {
        return $controller->loginAction();
    } finally {
        if ($requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $requestMethod;
        }
        $_POST = $post;
    }
}

echo "== auth controller input shapes ==\n";

$adminOptions = [
    'securitysalt' => str_repeat('s', 64),
    'resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512']]],
];
$quotedUsername = '"<b>admin</b>"@example.test';

$quotedSetup = authInputShapeController(null, null, 'setup', $adminOptions, 0);
$quotedSetup['listener']->stopBeforeDatabase = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'csrf-sentinel', 'salt' => str_repeat('s', 64), 'username' => $quotedUsername, 'password' => 'secure-password'];
$response = null;
$quotedSetupReachedFlush = false;
try {
    $response = $quotedSetup['controller']->setupAction();
} catch (RuntimeException $exception) {
    $quotedSetupReachedFlush = $exception->getMessage() === 'expected test stop before database write';
}
authInputShapeCheck(
    'setup rejects quoted-local-part admin usernames at the controller boundary',
    !$quotedSetupReachedFlush && $response?->status === 200 && str_contains($response->body, 'unquoted local part')
);
authInputShapeCheck(
    'rejected setup username performs no persistence or flush',
    $quotedSetup['listener']->flushes === 0
        && $quotedSetup['entityManager']->getUnitOfWork()->getScheduledEntityInsertions() === []
);

$normalSetup = authInputShapeController(null, null, 'setup', $adminOptions, 0);
$normalSetup['listener']->stopBeforeDatabase = true;
$_POST['username'] = 'admin@example.test';
$normalSetupReachedFlush = false;
try {
    $normalSetup['controller']->setupAction();
} catch (RuntimeException $exception) {
    $normalSetupReachedFlush = $exception->getMessage() === 'expected test stop before database write';
}
$setupInsertions = $normalSetup['entityManager']->getUnitOfWork()->getScheduledEntityInsertions();
authInputShapeCheck(
    'setup accepts a normal admin address and reaches persistence',
    $normalSetupReachedFlush
        && count(array_filter($setupInsertions, static fn (object $entity): bool => $entity instanceof \Entities\Admin
            && $entity->getUsername() === 'admin@example.test')) === 1
);

$addActor = (new \Entities\Admin())
    ->setUsername('actor@example.test')
    ->setPassword('unused')
    ->setSuper(true)
    ->setActive(true);
(new ReflectionMethod($addActor, 'assignGeneratedId'))->invoke($addActor, 41);
$quotedAdd = authInputShapeController(1, $addActor, 'login', $adminOptions, 1, true);
$quotedAdd['entityManager']->getUnitOfWork()->registerManaged($addActor, ['id' => 41], []);
$quotedAdd['listener']->stopBeforeDatabase = true;
$addController = new AdminController(
    $quotedAdd['container'],
    new RouteMatch('admin', 'add', AdminController::class, 'addAction', []),
);
$_POST = ['csrf' => 'csrf-sentinel', 'username' => $quotedUsername, 'password' => 'secure-password', 'super' => '1'];
$response = null;
$quotedAddReachedFlush = false;
try {
    $response = $addController->addAction();
} catch (RuntimeException $exception) {
    $quotedAddReachedFlush = $exception->getMessage() === 'expected test stop before database write';
}
authInputShapeCheck(
    'admin add rejects quoted-local-part usernames at the controller boundary',
    !$quotedAddReachedFlush && $response?->status === 200 && str_contains($response->body, 'unquoted local part')
);
authInputShapeCheck(
    'rejected add username performs no persistence or flush',
    $quotedAdd['listener']->flushes === 0
        && $quotedAdd['entityManager']->getUnitOfWork()->getScheduledEntityInsertions() === []
);

$normalAdd = authInputShapeController(1, $addActor, 'login', $adminOptions, 1, true);
$normalAdd['entityManager']->getUnitOfWork()->registerManaged($addActor, ['id' => 41], []);
$normalAdd['listener']->stopBeforeDatabase = true;
$addController = new AdminController(
    $normalAdd['container'],
    new RouteMatch('admin', 'add', AdminController::class, 'addAction', []),
);
$_POST['username'] = 'second-admin@example.test';
$normalAddReachedFlush = false;
try {
    $addController->addAction();
} catch (RuntimeException $exception) {
    $normalAddReachedFlush = $exception->getMessage() === 'expected test stop before database write';
}
$addInsertions = $normalAdd['entityManager']->getUnitOfWork()->getScheduledEntityInsertions();
authInputShapeCheck(
    'admin add accepts a normal address and reaches persistence',
    $normalAddReachedFlush
        && count(array_filter($addInsertions, static fn (object $entity): bool => $entity instanceof \Entities\Admin
            && $entity->getUsername() === 'second-admin@example.test')) === 1
);

$malformedPending = authInputShapeController('1junk');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$response = $malformedPending['controller']->totpAction();
authInputShapeCheck(
    'non-canonical pending admin id redirects to login',
    $response->status === 302 && ($response->headers['Location'] ?? null) === '/auth/login'
);
authInputShapeCheck(
    'non-canonical pending id is cleared before repository lookup',
    $malformedPending['session']->get('totp_pending_admin_id') === null
        && $malformedPending['repository']->findCalls === 0
);

$missingCsrf = authInputShapeController(1);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['code' => '123456'];
$response = $missingCsrf['controller']->totpAction();
authInputShapeCheck(
    'TOTP POST rejects a missing CSRF token in the rendered form',
    $response->status === 200
        && str_contains($response->body, 'Invalid or missing security token.')
);
authInputShapeCheck(
    'missing CSRF reaches no TOTP mutation or flush',
    $missingCsrf['listener']->flushes === 0
        && $missingCsrf['session']->get('totp_pending_admin_id') === 1
        && $missingCsrf['repository']->findCalls === 1
);

$containerCode = authInputShapeController('1');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'csrf-sentinel', 'code' => ['123456']];
$response = $containerCode['controller']->totpAction();
authInputShapeCheck(
    'TOTP form rejects a container-shaped authentication code',
    $response->status === 200 && str_contains($response->body, 'This field has an invalid type.')
);
authInputShapeCheck(
    'container-shaped code reaches no TOTP mutation or flush',
    $containerCode['listener']->flushes === 0
        && $containerCode['session']->get('totp_pending_admin_id') === '1'
        && $containerCode['repository']->findCalls === 1
);

$testIp = '192.0.2.41';
$_SERVER['REMOTE_ADDR'] = $testIp;
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

$inactiveState = __DIR__ . '/../var/tmp/test-inactive-admin-' . getmypid();
$wrongState = __DIR__ . '/../var/tmp/test-wrong-admin-' . getmypid();

$inactive = authInputShapeController(
    null,
    authInputShapeAdmin(false, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState),
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'csrf' => 'csrf-sentinel',
    'username' => 'admin@example.test',
    'password' => AUTH_INPUT_TEST_CREDENTIAL,
];
$inactiveResponse = $inactive['controller']->loginAction();
$inactiveMessages = $inactive['session']->get('flashMessages');

$wrongPassword = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($wrongState),
);
$_POST['password'] = 'wrong horse';
$wrongResponse = $wrongPassword['controller']->loginAction();
$wrongMessages = $wrongPassword['session']->get('flashMessages');

authInputShapeCheck(
    'inactive correct credentials retain the generic login response',
    $inactiveResponse->status === 200
        && $inactiveResponse->body === $wrongResponse->body
        && $inactiveMessages === $wrongMessages
        && $inactiveMessages === [[
            'text' => 'Invalid username or password. Please try again.',
            'level' => 'error',
            'isHtml' => false,
        ]]
);
authInputShapeCheck(
    'inactive login retains failed-attempt accounting',
    authInputShapeBruteForceAttempts($inactiveState, $testIp) === 1
        && authInputShapeBruteForceAttempts($wrongState, $testIp) === 1
);
authInputShapeCheck(
    'inactive password login grants no identity or second-factor state',
    $inactive['session']->get('identity') === null
        && $inactive['session']->get('totp_pending_admin_id') === null
        && $inactive['listener']->flushes === 0
);

$persistenceParent = sys_get_temp_dir() . '/vimbadmin-bruteforce-denied-' . bin2hex(random_bytes(8));
if (file_put_contents($persistenceParent, 'not a directory') === false) {
    throw new RuntimeException('could not create brute-force persistence fault fixture');
}
$persistenceState = $persistenceParent . '/state';
$persistenceFault = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($persistenceState, [
        'bruteforce' => [
            'enabled' => '1',
            'max_attempts' => '2',
            'statedir' => $persistenceState,
        ],
    ]),
);
$persistenceException = null;
try {
    authInputShapeLogin($persistenceFault['controller'], AUTH_INPUT_TEST_CREDENTIAL);
} catch (RuntimeException $exception) {
    $persistenceException = $exception;
} finally {
    if (is_file($persistenceParent)) {
        unlink($persistenceParent);
    }
}
authInputShapeCheck(
    'brute-force persistence failure denies authentication before credential lookup',
    $persistenceException?->getMessage() === 'bruteforce state persistence unavailable'
        && $persistenceFault['session']->get('identity') === null
        && $persistenceFault['repository']->findOneByCalls === 0
);

$latePersistenceState = sys_get_temp_dir() . '/vimbadmin-bruteforce-late-denied-' . bin2hex(random_bytes(8));
$latePersistenceAdmin = authInputShapeAdmin(true);
$latePersistenceAdmin->breakBruteForceStateOnPasswordRead($latePersistenceState);
$latePersistenceFault = authInputShapeController(
    null,
    $latePersistenceAdmin,
    'login',
    authInputShapeLoginOptions($latePersistenceState),
);
$latePersistenceException = null;
try {
    authInputShapeLogin($latePersistenceFault['controller'], AUTH_INPUT_TEST_CREDENTIAL);
} catch (RuntimeException $exception) {
    $latePersistenceException = $exception;
} finally {
    if (is_file($latePersistenceState)) {
        unlink($latePersistenceState);
    }
}
authInputShapeCheck(
    'late brute-force persistence failure denies session establishment',
    $latePersistenceException?->getMessage() === 'bruteforce state persistence unavailable'
        && $latePersistenceFault['repository']->findOneByCalls === 1
        && $latePersistenceFault['session']->get('identity') === null
        && $latePersistenceFault['session']->get('logged_in_via') === null
        && $latePersistenceFault['listener']->flushes === 0
);

$enabledInactiveAdmin = authInputShapeAdmin(false, [
    \ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret',
]);
$enabledInactive = authInputShapeController(
    null,
    $enabledInactiveAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$_POST['password'] = AUTH_INPUT_TEST_CREDENTIAL;
$enabledInactiveResponse = $enabledInactive['controller']->loginAction();
authInputShapeCheck(
    'inactive enrolled-2FA admin is denied before a TOTP challenge',
    $enabledInactiveResponse->status === 200
        && $enabledInactive['session']->get('identity') === null
        && $enabledInactive['session']->get('totp_pending_admin_id') === null
        && $enabledInactiveAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_SECRET)
);

$forcedInactiveAdmin = authInputShapeAdmin(false, [
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$forcedInactive = authInputShapeController(
    null,
    $forcedInactiveAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$forcedInactiveResponse = $forcedInactive['controller']->loginAction();
authInputShapeCheck(
    'inactive forced-2FA admin is denied before enrolment',
    $forcedInactiveResponse->status === 200
        && $forcedInactive['session']->get('identity') === null
        && $forcedInactive['session']->get('totp_pending_admin_id') === null
        && $forcedInactiveAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_FORCE)
);

$active = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$activeResponse = $active['controller']->loginAction();
$activeIdentity = $active['session']->get('identity');
authInputShapeCheck(
    'active password login retains normal session establishment',
    $activeResponse->status === 302
        && ($activeResponse->headers['Location'] ?? null) === '/'
        && is_array($activeIdentity)
        && ($activeIdentity['id'] ?? null) === 41
        && $active['session']->get('logged_in_via') === 'auth'
        && $active['listener']->flushes === 1
);

$staleAdmin = authInputShapeAdmin(true);
$staleAdmin->setPassword(\OSS_Auth_Password::hash(
    AUTH_INPUT_TEST_CREDENTIAL,
    ['pwhash' => 'bcrypt', 'hash_cost' => 4],
));
$staleHash = $staleAdmin->requiredPassword();
$rehashLogin = authInputShapeController(
    null,
    $staleAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, [
        'resources' => ['auth' => ['oss' => ['pwhash' => 'bcrypt', 'hash_cost' => 5]]],
        'bruteforce' => ['enabled' => '0'],
    ]),
);
$rehashResponse = authInputShapeLogin($rehashLogin['controller'], AUTH_INPUT_TEST_CREDENTIAL);
authInputShapeCheck(
    'successful active login rehashes a stale bcrypt cost before authentication completes',
    $rehashResponse->status === 302
        && $staleAdmin->requiredPassword() !== $staleHash
        && str_starts_with($staleAdmin->requiredPassword(), '$2a$05$')
        && $rehashLogin['listener']->flushes === 1
        && $rehashLogin['repository']->passwordSwaps[0][0] === $staleHash
);

$failedRehashAdmin = authInputShapeAdmin(true);
$failedRehashAdmin->setPassword($staleHash);
$failedRehash = authInputShapeController(
    null,
    $failedRehashAdmin,
    'login',
    authInputShapeLoginOptions($wrongState, [
        'resources' => ['auth' => ['oss' => ['pwhash' => 'bcrypt', 'hash_cost' => 5]]],
        'bruteforce' => ['enabled' => '0'],
    ]),
);
$failedRehashResponse = authInputShapeLogin($failedRehash['controller'], 'wrong horse');
authInputShapeCheck(
    'failed login keeps the generic response and performs no password write',
    $failedRehashResponse->status === 200
        && $failedRehashAdmin->requiredPassword() === $staleHash
        && $failedRehash['listener']->flushes === 0
        && $failedRehash['session']->get('flashMessages') === [[
            'text' => 'Invalid username or password. Please try again.',
            'level' => 'error',
            'isHtml' => false,
        ]]
);

$rehashErrorAdmin = authInputShapeAdmin(true);
$rehashErrorAdmin->setPassword($staleHash);
$rehashError = authInputShapeController(
    null,
    $rehashErrorAdmin,
    'login',
    authInputShapeLoginOptions($wrongState, [
        'resources' => ['auth' => ['oss' => ['pwhash' => 'bcrypt', 'hash_cost' => 17]]],
        'bruteforce' => ['enabled' => '0'],
    ]),
);
$rehashError['repository']->passwordSwapThrows = true;
$rehashErrorResponse = authInputShapeLogin($rehashError['controller'], AUTH_INPUT_TEST_CREDENTIAL);
authInputShapeCheck(
    'rehash database errors retain clean managed state without denying login',
    $rehashErrorResponse->status === 302
        && $rehashErrorAdmin->requiredPassword() === $staleHash
        && $rehashError['listener']->flushes === 1
        && is_array($rehashError['session']->get('identity'))
);

$racedResetAdmin = authInputShapeAdmin(true);
$racedResetAdmin->setPassword($staleHash);
$racedReset = authInputShapeController(
    null,
    $racedResetAdmin,
    'login',
    authInputShapeLoginOptions($wrongState, [
        'resources' => ['auth' => ['oss' => ['pwhash' => 'bcrypt', 'hash_cost' => 5]]],
        'bruteforce' => ['enabled' => '0'],
    ]),
);
$racedReset['repository']->passwordSwapAffected = 0;
$racedResetResponse = authInputShapeLogin($racedReset['controller'], AUTH_INPUT_TEST_CREDENTIAL);
authInputShapeCheck(
    'concurrent password reset wins a zero-row exact-hash CAS without denying login',
    $racedResetResponse->status === 302
        && $racedResetAdmin->requiredPassword() === $staleHash
        && $racedReset['repository']->passwordSwaps[0][0] === $staleHash
        && str_starts_with($racedReset['repository']->passwordSwaps[0][1], '$2a$05$')
        && $racedReset['listener']->flushes === 1
);

$enabledActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$enabledActiveResponse = $enabledActive['controller']->loginAction();
authInputShapeCheck(
    'active enrolled-2FA login still parks for TOTP',
    $enabledActiveResponse->status === 302
        && ($enabledActiveResponse->headers['Location'] ?? null) === '/auth/totp'
        && $enabledActive['session']->get('identity') === null
        && $enabledActive['session']->get('totp_pending_admin_id') === 41
        && $enabledActive['session']->get('totp_pending_via') === 'auth'
);

$forcedActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_FORCE => '1']),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$forcedActiveResponse = $forcedActive['controller']->loginAction();
authInputShapeCheck(
    'active forced-2FA login still parks for enrolment',
    $forcedActiveResponse->status === 302
        && ($forcedActiveResponse->headers['Location'] ?? null) === '/auth/totp-setup'
        && $forcedActive['session']->get('identity') === null
        && $forcedActive['session']->get('totp_pending_admin_id') === 41
);

$demoActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState, [
        'bruteforce' => ['enabled' => '0'],
        'demo' => ['account' => 'admin@example.test', 'password' => AUTH_INPUT_TEST_CREDENTIAL],
    ]),
);
$demoActiveResponse = $demoActive['controller']->loginAction();
authInputShapeCheck(
    'active demo login retains its configured 2FA bypass',
    $demoActiveResponse->status === 302
        && ($demoActiveResponse->headers['Location'] ?? null) === '/'
        && is_array($demoActive['session']->get('identity'))
        && $demoActive['session']->get('totp_pending_admin_id') === null
);

$recoveryAdmin = authInputShapeAdmin(true, [
    \ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret',
    \ViMbAdmin_TwoFactor::PREF_BACKUP => '[]',
    \ViMbAdmin_TwoFactor::PREF_LASTTS => '1',
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$recovery = authInputShapeController(
    null,
    $recoveryAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, [
        'bruteforce' => ['enabled' => '0'],
        'twofactor' => ['force_disable' => 'admin@example.test'],
    ]),
);
$recoveryResponse = $recovery['controller']->loginAction();
authInputShapeCheck(
    'active force-disable recovery still clears 2FA and logs in',
    $recoveryResponse->status === 302
        && ($recoveryResponse->headers['Location'] ?? null) === '/'
        && is_array($recovery['session']->get('identity'))
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_SECRET)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_LASTTS)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_FORCE)
        && $recovery['listener']->flushes === 2
);

$firstRun = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
    0,
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$firstRunResponse = $firstRun['controller']->loginAction();
authInputShapeCheck(
    'first-run login still redirects to setup before credential lookup',
    $firstRunResponse->status === 302
        && ($firstRunResponse->headers['Location'] ?? null) === '/auth/setup'
        && $firstRun['repository']->findOneByCalls === 0
);

foreach (['totp', 'totp-setup'] as $pendingAction) {
    $pendingInactive = authInputShapeController(
        41,
        authInputShapeAdmin(false),
        $pendingAction,
    );
    $pendingInactive['session']->set('totp_setup_secret', 'pending-secret');
    $pendingInactive['session']->set('totp_verified', true);
    $pendingInactive['session']->set('postAuthRedirect', 'mailbox/list');
    $pendingResponse = $pendingAction === 'totp'
        ? $pendingInactive['controller']->totpAction()
        : $pendingInactive['controller']->totpSetupAction();
    authInputShapeCheck(
        "inactive {$pendingAction} flow revokes pending authentication state",
        $pendingResponse->status === 302
            && ($pendingResponse->headers['Location'] ?? null) === '/auth/login'
            && $pendingInactive['session']->get('totp_pending_admin_id') === null
            && $pendingInactive['session']->get('totp_pending_via') === null
            && $pendingInactive['session']->get('totp_setup_secret') === null
            && $pendingInactive['session']->get('totp_verified') === null
            && $pendingInactive['session']->get('postAuthRedirect') === null
            && $pendingInactive['session']->get('flashMessages') === [[
                'text' => 'Invalid username or password. Please try again.',
                'level' => 'error',
                'isHtml' => false,
            ]]
    );
}

$totpClearState = __DIR__ . '/../var/tmp/test-totp-clear-failure-' . getmypid();
if (!mkdir($totpClearState, 0700, true)) {
    throw new RuntimeException('could not create TOTP clear-failure fixture');
}
$totpClearStateFile = bruteForceStatePath($totpClearState, $testIp);
if (!mkdir($totpClearStateFile, 0700)) {
    throw new RuntimeException('could not create TOTP clear-failure state entry');
}
$totpClearAdmin = authInputShapeAdmin(true, [
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$totpClearFailure = authInputShapeController(
    41,
    $totpClearAdmin,
    'totp-setup',
    authInputShapeLoginOptions($totpClearState, [
        'securitysalt' => str_repeat('s', 64),
        'bruteforce' => ['enabled' => '1', 'statedir' => $totpClearState],
    ]),
);
$totpClearSecret = 'JBSWY3DPEHPK3PXP';
$totpClearFailure['session']->set('totp_setup_secret', $totpClearSecret);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'code' => (new \RobThree\Auth\TwoFactorAuth(
        new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(2, '#ffffff', '#000000', 'svg'),
        'ViMbAdmin',
    ))->getCode($totpClearSecret),
];
$totpClearException = null;
try {
    $totpClearFailure['controller']->totpSetupAction();
} catch (RuntimeException $exception) {
    $totpClearException = $exception;
} finally {
    rmdir($totpClearStateFile);
    foreach (['/.lock', '/.reap-lock', '/.reap-stamp', '/.reap-cursor'] as $sidecar) {
        if (is_file($totpClearState . $sidecar)) {
            unlink($totpClearState . $sidecar);
        }
    }
    rmdir($totpClearState);
}
authInputShapeCheck(
    'TOTP enrolment remains untouched when brute-force state cannot be cleared',
    $totpClearException?->getMessage() === 'bruteforce state persistence unavailable'
        && !$totpClearAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_SECRET)
        && !$totpClearAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP)
        && $totpClearAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_FORCE)
        && $totpClearFailure['listener']->flushes === 0
        && $totpClearFailure['session']->get('identity') === null
        && $totpClearFailure['session']->get('totp_setup_secret') === $totpClearSecret
);

$deactivatedDuringSetupAdmin = authInputShapeAdmin(true, [
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$deactivatedDuringSetupAdmin->deactivateDuringTwoFactorEnable();
$deactivatedDuringSetup = authInputShapeController(
    41,
    $deactivatedDuringSetupAdmin,
    'totp-setup',
    ['securitysalt' => str_repeat('s', 64), 'bruteforce' => ['enabled' => '0']],
);
$setupSecret = 'JBSWY3DPEHPK3PXP';
$deactivatedDuringSetup['session']->set('totp_setup_secret', $setupSecret);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'code' => (new \RobThree\Auth\TwoFactorAuth(
        new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(2, '#ffffff', '#000000', 'svg'),
        'ViMbAdmin',
    ))->getCode($setupSecret),
];
$deactivatedDuringSetupResponse = $deactivatedDuringSetup['controller']->totpSetupAction();
authInputShapeCheck(
    'deactivation during TOTP enrolment revokes the pending login without rendering backup codes',
    $deactivatedDuringSetupResponse->status === 302
        && ($deactivatedDuringSetupResponse->headers['Location'] ?? null) === '/auth/login'
        && $deactivatedDuringSetup['session']->get('identity') === null
        && $deactivatedDuringSetup['session']->get('totp_pending_admin_id') === null
        && $deactivatedDuringSetup['session']->get('totp_pending_via') === null
        && $deactivatedDuringSetup['session']->get('totp_setup_secret') === null
        && !str_contains($deactivatedDuringSetupResponse->body, 'Two-factor is now enabled.')
);

authInputShapeCheck('fixed assertion count', AuthInputShapeState::$checks === 33);

echo AuthInputShapeState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . AuthInputShapeState::$failures . " assertion(s) failed\n";
exit(AuthInputShapeState::$failures === 0 ? 0 : 1);
