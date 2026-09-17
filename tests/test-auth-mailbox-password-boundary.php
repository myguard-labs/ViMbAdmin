<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/bruteforce-state-path.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class AuthMailboxPasswordSession implements SessionStorage
{
    /** @param array<string, mixed> $data */
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
final class AuthMailboxPasswordView
{
    public function __set(string $key, mixed $value): void
    {
    }
    public function render(string $script): string
    {
        return 'rendered:' . $script;
    }
}

final class AuthMailboxPasswordRepository extends \Repositories\Mailbox
{
    public static ?\Entities\Mailbox $mailbox = null;
    public static ?string $lookupMarker = null;

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        if (self::$lookupMarker !== null
            && file_put_contents(self::$lookupMarker, "lookup\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not record mailbox repository lookup');
        }
        return self::$mailbox;
    }
}

final class AuthMailboxPasswordFlushListener
{
    public int $flushes = 0;

    public function onFlush(): void
    {
        $this->flushes++;
    }
}

final class AuthMailboxPasswordBootstrap
{
    /** @param array<string, mixed> $options */
    public function __construct(
        private \Doctrine\ORM\EntityManager $entityManager,
        private AuthMailboxPasswordSession $session,
        private AuthMailboxPasswordView $view,
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

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

final class AuthMailboxPasswordState
{
    public static int $failures = 0;
}

function authMailboxPasswordCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        AuthMailboxPasswordState::$failures++;
    }
}

/**
 * @return array{mailbox:Entities\Mailbox,flushes:int,status:int,location:string|null,flashes:mixed}
 */
function authMailboxPasswordRun(
    ?string $storedPassword,
    string $submittedPassword,
    string $stateDirectory,
    int $maxAttempts = 5,
    ?string $demoAccount = null,
): array {
    $options = [
        'defaults' => ['mailbox' => [
            'min_password_length' => 8,
            'password_scheme' => 'crypt:sha512',
        ]],
        'bruteforce' => [
            'enabled' => true,
            'max_attempts' => $maxAttempts,
            'window' => 900,
            'lockout' => 900,
            'statedir' => $stateDirectory,
        ],
    ];
    if ($demoAccount !== null) {
        $options['demo'] = ['account' => $demoAccount];
    }
    $session = new AuthMailboxPasswordSession(['csrfToken' => 'csrf-sentinel']);
    $mailbox = (new \Entities\Mailbox())->setUsername('user@example.test');
    if ($storedPassword !== null) {
        $mailbox->setPassword($storedPassword);
    }
    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = \Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new \Doctrine\ORM\EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Mailbox::class)
        ->setCustomRepositoryClass('\\AuthMailboxPasswordRepository');
    AuthMailboxPasswordRepository::$mailbox = $mailbox;
    $flushListener = new AuthMailboxPasswordFlushListener();
    $entityManager->getEventManager()->addEventListener([\Doctrine\ORM\Events::onFlush], $flushListener);
    $bootstrap = new AuthMailboxPasswordBootstrap(
        $entityManager,
        $session,
        new AuthMailboxPasswordView(),
        $options,
    );
    $controller = new AuthController(
        new Container($bootstrap, new Auth($session, static fn (int $id): ?object => null)),
        new RouteMatch('auth', 'change-password', AuthController::class, 'changePasswordAction', []),
    );

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'csrf' => 'csrf-sentinel',
        'username' => 'user@example.test',
        'current_password' => $submittedPassword,
        'new_password' => 'new-password-456',
        'confirm_new_password' => 'new-password-456',
    ];
    $response = $controller->changePasswordAction();

    return [
        'mailbox' => $mailbox,
        'flushes' => $flushListener->flushes,
        'status' => $response->status,
        'location' => $response->headers['Location'] ?? null,
        'flashes' => $session->get('flashMessages'),
    ];
}

/** @return array{attempts:int,first:int,last:int,locked_until:int}|null */
function authMailboxPasswordState(string $stateDirectory, string $ip): ?array
{
    $path = bruteForceStatePath($stateDirectory, $ip);
    if (!is_file($path)) {
        return null;
    }
    $state = json_decode((string) file_get_contents($path), true);
    if (!is_array($state)
        || array_keys($state) !== ['attempts', 'first', 'last', 'locked_until']
        || !is_int($state['attempts'])
        || !is_int($state['first'])
        || !is_int($state['last'])
        || !is_int($state['locked_until'])) {
        return null;
    }
    return [
        'attempts' => $state['attempts'],
        'first' => $state['first'],
        'last' => $state['last'],
        'locked_until' => $state['locked_until'],
    ];
}

function authMailboxPasswordRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected mailbox password test directory entry');
        }
        if ($entry->isDir() && !$entry->isLink()) {
            authMailboxPasswordRemoveTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.23';

if (($argv[1] ?? null) === '--lockout-worker') {
    $stateDirectory = $argv[2] ?? throw new LogicException('Missing worker state directory');
    AuthMailboxPasswordRepository::$lookupMarker = $argv[3] ?? throw new LogicException('Missing lookup marker');
    $storedHash = \OSS_Auth_Password::hash('current-password-123', ['pwhash' => 'crypt:sha512']);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        authMailboxPasswordRun($storedHash, 'wrong-password-123', $stateDirectory, 2);
    }
    echo 'ACTION RETURNED AFTER LOCKOUT';
    exit(0);
}

$temporaryRoot = sys_get_temp_dir() . '/vimbadmin-auth-mailbox-password-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryRoot, 0700)) {
    throw new RuntimeException('Could not create mailbox password test directory');
}
register_shutdown_function(static function () use ($temporaryRoot): void {
    authMailboxPasswordRemoveTree($temporaryRoot);
});

echo "== mailbox password action boundary ==\n";

$options = ['pwhash' => 'crypt:sha512'];
$storedHash = \OSS_Auth_Password::hash('current-password-123', $options);
$wrongState = $temporaryRoot . '/wrong';
$missingState = $temporaryRoot . '/missing';
$correctState = $temporaryRoot . '/correct';
$demoState = $temporaryRoot . '/demo';
$wrong = authMailboxPasswordRun($storedHash, 'wrong-password-123', $wrongState);
$missing = authMailboxPasswordRun(null, 'current-password-123', $missingState);
(new ViMbAdmin_BruteForce(null, [
    'statedir' => $correctState,
    'max_attempts' => 5,
    'window' => 900,
    'lockout' => 900,
]))->record('admin@example.test', null);
$correct = authMailboxPasswordRun($storedHash, 'current-password-123', $correctState);
$demo = authMailboxPasswordRun(
    $storedHash,
    'current-password-123',
    $demoState,
    5,
    'user@example.test',
);

$generic = [[
    'text' => 'Invalid username or password.',
    'level' => 'error',
    'isHtml' => false,
]];
authMailboxPasswordCheck(
    'wrong credential uses the generic failure',
    $wrong['status'] === 200 && $wrong['flashes'] === $generic
);
authMailboxPasswordCheck(
    'wrong credential performs no update or flush',
    $wrong['flushes'] === 0 && $wrong['mailbox']->requiredPassword() === $storedHash
);
$wrongBruteForceState = authMailboxPasswordState($wrongState, $_SERVER['REMOTE_ADDR']);
authMailboxPasswordCheck(
    'wrong credential records one compatible source-IP attempt',
    $wrongBruteForceState !== null && $wrongBruteForceState['attempts'] === 1
        && $wrongBruteForceState['first'] > 0 && $wrongBruteForceState['last'] > 0
        && $wrongBruteForceState['locked_until'] === 0
);
authMailboxPasswordCheck(
    'null stored credential uses the identical generic failure',
    $missing['status'] === 200 && $missing['flashes'] === $generic
);
authMailboxPasswordCheck(
    'null stored credential performs no update or flush',
    $missing['flushes'] === 0 && $missing['mailbox']->getPassword() === null
);
authMailboxPasswordCheck(
    'correct credential alone updates and flushes once',
    $correct['status'] === 302 && $correct['flushes'] === 1
        && $correct['location'] === '/auth/change-password'
        && $correct['mailbox']->requiredPassword() !== $storedHash
);
authMailboxPasswordCheck(
    'successful update preserves configured hashing semantics',
    \OSS_Auth_Password::verify('new-password-456', $correct['mailbox']->requiredPassword(), $options)
);
$retainedState = authMailboxPasswordState($correctState, $_SERVER['REMOTE_ADDR']);
authMailboxPasswordCheck(
    'mailbox success cannot clear shared administrator-login failure state',
    $retainedState !== null && $retainedState['attempts'] === 1 && $retainedState['locked_until'] === 0
);

$demoFailure = [[
    'text' => 'Password changes are disabled for the demo account.',
    'level' => 'error',
    'isHtml' => false,
]];
authMailboxPasswordCheck(
    'demo-account refusal remains authoritative',
    $demo['status'] === 302 && $demo['location'] === '/auth/change-password'
        && $demo['flashes'] === $demoFailure && $demo['flushes'] === 0
        && $demo['mailbox']->requiredPassword() === $storedHash
);
authMailboxPasswordCheck(
    'demo-account refusal does not consume a credential attempt',
    authMailboxPasswordState($demoState, $_SERVER['REMOTE_ADDR']) === null
);

$lockoutState = $temporaryRoot . '/lockout';
$lookupMarker = $temporaryRoot . '/lookups';
$process = proc_open(
    [PHP_BINARY, __FILE__, '--lockout-worker', $lockoutState, $lookupMarker],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start mailbox password lockout worker');
}
$workerOutput = stream_get_contents($pipes[1]);
$workerError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$workerStatus = proc_close($process);
$lookups = is_file($lookupMarker) ? file($lookupMarker, FILE_IGNORE_NEW_LINES) : [];
$lockedState = authMailboxPasswordState($lockoutState, $_SERVER['REMOTE_ADDR']);

authMailboxPasswordCheck(
    'configured max_attempts locks on the second invalid POST',
    $lockedState !== null && $lockedState['attempts'] === 2 && $lockedState['locked_until'] > time()
);
authMailboxPasswordCheck(
    'max_attempts + 1 is rejected before mailbox password lookup',
    $workerStatus === 0 && $workerError === ''
        && $workerOutput === 'Too many failed login attempts. Try again later.'
        && $lookups === ['lookup', 'lookup']
);

echo AuthMailboxPasswordState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . AuthMailboxPasswordState::$failures . " FAILED\n";
exit(AuthMailboxPasswordState::$failures === 0 ? 0 : 1);
