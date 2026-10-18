<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AdditionalInfoController;
use ViMbAdmin\Kernel\Controller\LogController;
use ViMbAdmin\Kernel\Controller\MaintenanceController;
use ViMbAdmin\Kernel\Controller\QueueController;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ControllerResourceContractState
{
    public static int $checks = 0;
}

function controllerResourceCheck(string $label, bool $passed): void
{
    ++ControllerResourceContractState::$checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo '  ok   ' . $label . "\n";
}

final class ControllerResourceSession implements SessionStorage
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
}

/** @property mixed $timeOfLastAction */
final class ControllerResourceNamespace
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function __get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->data[$key]);
    }
}

final class ControllerResourceBootstrap
{
    public int $resourceLookups = 0;
    public int $optionLookups = 0;
    /** @var list<string> */
    public array $resourceNames = [];

    /** @param array<string,mixed> $options */
    public function __construct(
        private mixed $entityManager,
        private readonly ControllerResourceNamespace $namespace,
        private readonly array $options = [],
    ) {
    }

    public function getResource(string $name): mixed
    {
        ++$this->resourceLookups;
        $this->resourceNames[] = $name;

        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->namespace,
            default => null,
        };
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        ++$this->optionLookups;
        return $this->options;
    }
}

final class ControllerResourceAdminShape
{
    public function getId(): int
    {
        return 17;
    }

    public function getUsername(): string
    {
        return 'shape@example.test';
    }

    public function getSuper(): bool
    {
        return false;
    }

    public function getActive(): bool
    {
        return true;
    }
}

/** @return array<class-string<AbstractController>,string> */
function controllerResourceClasses(): array
{
    return [
        AdditionalInfoController::class => 'AdditionalInfoController',
        LogController::class => 'LogController',
        MaintenanceController::class => 'MaintenanceController',
        QueueController::class => 'QueueController',
    ];
}

function controllerResourceEntityManager(): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
    if (PHP_VERSION_ID >= 80400) {
        $configuration->enableNativeLazyObjects(true);
    } else {
        $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
        $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'ControllerResourceProxies');
    }

    return new EntityManager(
        DriverManager::getConnection(['driver' => 'pdo_mysql'], $configuration),
        $configuration,
    );
}

/**
 * @param class-string<AbstractController> $class
 * @param callable(int):?object $loader
 * @param array<string,mixed> $options
 * @return array{0:AbstractController,1:ControllerResourceBootstrap}
 */
function controllerResourceSubject(
    string $class,
    mixed $entityManager,
    ControllerResourceSession $identity,
    callable $loader,
    ControllerResourceNamespace $namespace,
    array $options = [],
): array {
    $bootstrap = new ControllerResourceBootstrap($entityManager, $namespace, $options);
    $container = new Container($bootstrap, new Auth($identity, $loader));
    $route = new RouteMatch('resource-contract', 'probe', $class, 'indexAction', []);

    return [new $class($container, $route), $bootstrap];
}

function controllerResourceInvoke(AbstractController $controller, string $method): mixed
{
    return (new ReflectionMethod($controller, $method))->invoke($controller);
}

/** @return array{0:?Throwable,1:mixed} */
function controllerResourceCapture(callable $operation): array
{
    try {
        return [null, $operation()];
    } catch (Throwable $error) {
        return [$error, null];
    }
}

echo "== concrete controller resources ==\n";

$realManager = controllerResourceEntityManager();
$interfaceOnlyManager = new class ($realManager) extends EntityManagerDecorator {
};

foreach (controllerResourceClasses() as $class => $label) {
    $anonymousLoader = static fn (int $id): ?object => null;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        new ControllerResourceSession(),
        $anonymousLoader,
        new ControllerResourceNamespace(),
    );
    [$error, $result] = controllerResourceCapture(
        static fn (): mixed => controllerResourceInvoke($controller, 'em')
    );
    controllerResourceCheck(
        $label . ' returns the exact concrete entity manager after one parent lookup',
        $error === null
        && $result === $realManager
        && $bootstrap->resourceLookups === 1
        && $bootstrap->resourceNames === ['doctrine2'],
    );

    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $interfaceOnlyManager,
        new ControllerResourceSession(),
        $anonymousLoader,
        new ControllerResourceNamespace(),
    );
    [$error] = controllerResourceCapture(static fn (): mixed => controllerResourceInvoke($controller, 'em'));
    controllerResourceCheck(
        $label . ' rejects an interface-only entity manager with the exact subclass guard',
        $error instanceof LogicException
        && $error->getMessage() === 'Doctrine entity manager resource has an invalid type'
        && $bootstrap->resourceLookups === 1,
    );

    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        new stdClass(),
        new ControllerResourceSession(),
        $anonymousLoader,
        new ControllerResourceNamespace(),
    );
    [$error] = controllerResourceCapture(static fn (): mixed => controllerResourceInvoke($controller, 'em'));
    controllerResourceCheck(
        $label . ' rejects a wrong object with the exact subclass guard',
        $error instanceof LogicException
        && $error->getMessage() === 'Doctrine entity manager resource has an invalid type'
        && $bootstrap->resourceLookups === 1,
    );

    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        null,
        new ControllerResourceSession(),
        $anonymousLoader,
        new ControllerResourceNamespace(),
    );
    [$error] = controllerResourceCapture(static fn (): mixed => controllerResourceInvoke($controller, 'em'));
    controllerResourceCheck(
        $label . ' preserves the parent null-resource failure before its subclass guard',
        $error instanceof TypeError
        && $error->getMessage() === 'doctrine2 resource must be an object'
        && $bootstrap->resourceLookups === 1,
    );

    $admin = (new \Entities\Admin())
        ->setUsername('active@example.test')
        ->setActive(true);
    $loaderCalls = 0;
    $namespace = new ControllerResourceNamespace(['timeOfLastAction' => time() - 5]);
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        new ControllerResourceSession(['identity' => ['id' => 17]]),
        static function (int $id) use ($admin, &$loaderCalls): object {
            ++$loaderCalls;
            return $admin;
        },
        $namespace,
        ['resources' => ['session' => ['idle_timeout' => 60]]],
    );
    $beforeRefresh = time();
    [$error, $result] = controllerResourceCapture(
        static fn (): mixed => controllerResourceInvoke($controller, 'admin')
    );
    controllerResourceCheck(
        $label . ' delegates once and returns the exact active typed admin',
        $error === null
        && $result === $admin
        && $loaderCalls === 1
        && $bootstrap->optionLookups === 1
        && $bootstrap->resourceNames === ['namespace'],
    );
    controllerResourceCheck(
        $label . ' preserves the parent idle-session refresh side effect',
        is_int($namespace->timeOfLastAction)
        && $namespace->timeOfLastAction >= $beforeRefresh
        && $namespace->timeOfLastAction <= time(),
    );
    controllerResourceInvoke($controller, 'admin');
    controllerResourceCheck(
        $label . ' performs one parent delegation per call without controller identity caching',
        $loaderCalls === 1
        && $bootstrap->optionLookups === 2
        && $bootstrap->resourceNames === ['namespace', 'namespace'],
    );

    $anonymousCalls = 0;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        new ControllerResourceSession(),
        static function (int $id) use (&$anonymousCalls): ?object {
            ++$anonymousCalls;
            return null;
        },
        new ControllerResourceNamespace(),
    );
    [$error, $result] = controllerResourceCapture(
        static fn (): mixed => controllerResourceInvoke($controller, 'admin')
    );
    controllerResourceCheck(
        $label . ' preserves anonymous null without touching idle-session resources',
        $error === null
        && $result === null
        && $anonymousCalls === 0
        && $bootstrap->optionLookups === 0
        && $bootstrap->resourceLookups === 0,
    );

    $shapeCalls = 0;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        new ControllerResourceSession(['identity' => ['id' => 17]]),
        static function (int $id) use (&$shapeCalls): object {
            ++$shapeCalls;
            return new ControllerResourceAdminShape();
        },
        new ControllerResourceNamespace(),
    );
    [$error] = controllerResourceCapture(static fn (): mixed => controllerResourceInvoke($controller, 'admin'));
    controllerResourceCheck(
        $label . ' rejects an auth-compatible non-Admin with the exact subclass guard',
        $error instanceof LogicException
        && $error->getMessage() === 'Authenticated admin has an invalid type'
        && $shapeCalls === 1
        && $bootstrap->optionLookups === 1,
    );

    $invalidCalls = 0;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        new ControllerResourceSession(['identity' => ['id' => 17]]),
        static function (int $id) use (&$invalidCalls): object {
            ++$invalidCalls;
            return new stdClass();
        },
        new ControllerResourceNamespace(),
    );
    [$error] = controllerResourceCapture(static fn (): mixed => controllerResourceInvoke($controller, 'admin'));
    controllerResourceCheck(
        $label . ' preserves the parent invalid-identity failure before its subclass guard',
        $error instanceof LogicException
        && $error->getMessage() === 'Authenticated admin has an invalid type'
        && $invalidCalls === 1
        && $bootstrap->optionLookups === 1,
    );

    $expiredIdentity = new ControllerResourceSession(['identity' => ['id' => 17]]);
    $expiredCalls = 0;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        $expiredIdentity,
        static function (int $id) use ($admin, &$expiredCalls): object {
            ++$expiredCalls;
            return $admin;
        },
        new ControllerResourceNamespace(['timeOfLastAction' => time() - 61]),
        ['resources' => ['session' => ['idle_timeout' => 60]]],
    );
    [$error, $result] = controllerResourceCapture(
        static fn (): mixed => controllerResourceInvoke($controller, 'admin')
    );
    controllerResourceCheck(
        $label . ' preserves idle expiry before loading and clears the identity',
        $error === null
        && $result === null
        && $expiredCalls === 0
        && !$expiredIdentity->has('identity')
        && $bootstrap->optionLookups === 1
        && $bootstrap->resourceNames === ['namespace'],
    );

    $inactive = (new \Entities\Admin())
        ->setUsername('inactive@example.test')
        ->setActive(false);
    $inactiveIdentity = new ControllerResourceSession(['identity' => ['id' => 18]]);
    $inactiveCalls = 0;
    [$controller, $bootstrap] = controllerResourceSubject(
        $class,
        $realManager,
        $inactiveIdentity,
        static function (int $id) use ($inactive, &$inactiveCalls): object {
            ++$inactiveCalls;
            return $inactive;
        },
        new ControllerResourceNamespace(),
    );
    [$error, $result] = controllerResourceCapture(
        static fn (): mixed => controllerResourceInvoke($controller, 'admin')
    );
    controllerResourceCheck(
        $label . ' preserves inactive-admin revocation at the shared auth boundary',
        $error === null
        && $result === null
        && $inactiveCalls === 1
        && !$inactiveIdentity->has('identity')
        && $bootstrap->optionLookups === 1,
    );

    $emReturn = (new ReflectionMethod($class, 'em'))->getReturnType();
    $adminReturn = (new ReflectionMethod($class, 'admin'))->getReturnType();
    controllerResourceCheck(
        $label . ' retains its concrete EntityManager and nullable Admin signatures',
        $emReturn instanceof ReflectionNamedType
        && $emReturn->getName() === EntityManager::class
        && !$emReturn->allowsNull()
        && $adminReturn instanceof ReflectionNamedType
        && $adminReturn->getName() === \Entities\Admin::class
        && $adminReturn->allowsNull(),
    );
}

if (ControllerResourceContractState::$checks !== 52) {
    throw new RuntimeException(
        'FAIL: fixed assertion count (' . ControllerResourceContractState::$checks . ' of 52)'
    );
}

echo 'ALL PASSED (' . ControllerResourceContractState::$checks . " checks)\n";
