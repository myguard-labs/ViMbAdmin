<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}

use ViMbAdmin\Kernel\Controller\ArchiveController;
use ViMbAdmin\Kernel\Controller\MaintenanceController;
use ViMbAdmin\Kernel\Controller\QueueController;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class NativeControllerOrderSession implements SessionStorage
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

final class NativeControllerOrderResources
{
    public int $nonSessionReads = 0;
    public function __construct(private readonly NativeControllerOrderSession $session) {}
    /** @return array<string,mixed> */
    public function getOptions(): array { return []; }
    public function getResource(string $name): object
    {
        if ($name === 'namespace') {
            return $this->session;
        }
        $this->nonSessionReads++;
        throw new LogicException('Unexpected resource read: ' . $name);
    }
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
$invoke = static function (string $class, string $method, mixed ...$args): mixed {
    return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
};
$fails = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (LogicException $exception) {
        return $exception->getMessage() === $message;
    }
    return false;
};

echo "== native controller input and order boundaries ==\n";

$check('queue positive ids reject coercion and preserve canonical decimals',
    $invoke(QueueController::class, 'positiveIntegerOrNull', 7) === 7
    && $invoke(QueueController::class, 'positiveIntegerOrNull', '12') === 12
    && $invoke(QueueController::class, 'positiveIntegerOrNull', '01') === null
    && $invoke(QueueController::class, 'positiveIntegerOrNull', '12junk') === null
    && $invoke(QueueController::class, 'positiveIntegerOrNull', ['12']) === null);
$check('queue runner defaults are retained but malformed values fail closed',
    $invoke(QueueController::class, 'optionInt', [], 5, 'queue', 'runner', 'max_per_run') === 5
    && $invoke(QueueController::class, 'optionInt', ['queue' => ['runner' => ['max_per_run' => '8']]], 5, 'queue', 'runner', 'max_per_run') === 8
    && $fails(
        static fn(): mixed => $invoke(QueueController::class, 'optionInt', ['queue' => ['runner' => ['max_per_run' => null]]], 5, 'queue', 'runner', 'max_per_run'),
        'Configuration queue.runner.max_per_run must be a non-negative integer',
    ));
$check('trusted proxy lists retain numeric entries without array casts',
    $invoke(QueueController::class, 'proxyList', ['127.0.0.1', '::1']) === ['127.0.0.1', '::1']
    && $invoke(QueueController::class, 'proxyList', '127.0.0.1') === ['127.0.0.1']
    && $fails(
        static fn(): mixed => $invoke(QueueController::class, 'proxyList', [0 => ['nested']]),
        'Configuration trustedproxy.proxies must contain strings',
    ));

$check('archive list ids reject mixed numeric strings before repository access',
    $invoke(ArchiveController::class, 'positiveIntegerOrNull', '9') === 9
    && $invoke(ArchiveController::class, 'positiveIntegerOrNull', '9junk') === null
    && $invoke(ArchiveController::class, 'positiveIntegerOrNull', '01') === null);
$check('archive DataTables containers fail before query parsing', $fails(
    static fn(): mixed => $invoke(ArchiveController::class, 'requestArray', ['length' => ['100']]),
    'DataTables parameter length must be a string',
));
$snapshot = $invoke(ArchiveController::class, 'mailboxSnapshot', [
    'username' => 'alice@example.test', 'local_part' => 'alice', 'name' => null,
    'password' => '{SSHA}hash', 'quota' => '1024', 'active' => true,
]);
$check('archive snapshots preserve exact mailbox fields and scalar types',
    $snapshot === [
        'username' => 'alice@example.test', 'local_part' => 'alice', 'name' => null,
        'password' => '{SSHA}hash', 'quota' => 1024, 'active' => true,
    ]);
$check('archive malformed snapshots are rejected before persistence fields are read', $fails(
    static fn(): mixed => $invoke(ArchiveController::class, 'mailboxSnapshot', [
        'username' => 'alice@example.test', 'local_part' => 'alice', 'name' => null,
        'password' => '{SSHA}hash', 'quota' => -1, 'active' => 1,
    ]),
    'Archive mailbox snapshot active must be boolean',
));

$check('maintenance confirmation accepts only the shipped binary forms',
    $invoke(MaintenanceController::class, 'binaryFlag', true, 'confirm') === true
    && $invoke(MaintenanceController::class, 'binaryFlag', '1', 'confirm') === true
    && $invoke(MaintenanceController::class, 'binaryFlag', '', 'confirm') === false
    && $fails(
        static fn(): mixed => $invoke(MaintenanceController::class, 'binaryFlag', ['1'], 'confirm'),
        'confirm must be zero or one',
    ));
$check('maintenance maildir names cannot escape the configured root',
    $invoke(MaintenanceController::class, 'safeMaildirName', 'alice@example.test') === 'alice@example.test'
    && $fails(
        static fn(): mixed => $invoke(MaintenanceController::class, 'safeMaildirName', '../outside'),
        'Maildir name is not a safe path component',
    ));

$orderSession = new NativeControllerOrderSession([
    'identity' => ['id' => 1],
    'csrfToken' => 'native-controller-test-token',
]);
$orderResources = new NativeControllerOrderResources($orderSession);
$orderAdmin = (new Entities\Admin())
    ->setUsername('admin@example.test')
    ->setSuper(true)
    ->setActive(true);
$orderController = new MaintenanceController(
    new Container($orderResources, new Auth($orderSession, static fn(int $id): Entities\Admin => $orderAdmin)),
    new RouteMatch('maintenance', 'backup-orphans', MaintenanceController::class, 'backupOrphansAction', []),
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'native-controller-test-token', 'confirm' => ['1']];
$orderFailure = false;
try {
    $orderController->backupOrphansAction();
} catch (LogicException $exception) {
    $orderFailure = $exception->getMessage() === 'Orphan import confirmation must be zero or one';
}
$check('malformed orphan confirmation fails before filesystem or entity-manager access',
    $orderFailure && $orderResources->nonSessionReads === 0);

$_POST = ['csrf' => 'native-controller-test-token', 'username' => ['alice@example.test'], 'confirm' => '1'];
$usernameFailure = false;
try {
    $orderController->backupOrphansAction();
} catch (LogicException $exception) {
    $usernameFailure = $exception->getMessage() === 'Orphan username must be a string';
}
$check('malformed orphan username fails before the orphan scan',
    $usernameFailure && $orderResources->nonSessionReads === 0);

$check('fixed assertion count', $checks === 11);
echo $failures === 0
    ? "OK: all native controller input assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
