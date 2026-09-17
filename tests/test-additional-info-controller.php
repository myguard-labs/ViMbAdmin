<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Repositories/MailboxPreference.php';

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\DBAL\DriverManager;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AdditionalInfoController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class AdditionalInfoTestSession implements SessionStorage
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

final class AdditionalInfoTestResources
{
    public function __construct(private readonly object $entityManager)
    {
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function getResource(string $name): object
    {
        return $this->entityManager;
    }
}

final class AdditionalInfoTestRepository extends \Repositories\MailboxPreference
{
    /** @var list<array{0:string,1:\Entities\Admin}> */
    public array $calls = [];
    /** @param list<mixed> $values */
    public function __construct(private readonly array $values = [], private readonly ?Throwable $error = null)
    {
    }
    /** @return list<mixed> */
    public function loadPrefrenceValuesByAttribute($attribute, $admin)
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        $this->calls[] = [(string) $attribute, $admin];
        return $this->values;
    }
}

/** @extends EntityRepository<object> */
final class AdditionalInfoWrongRepository extends EntityRepository
{
    public function __construct()
    {
    }
}

final class AdditionalInfoTestRepositoryFactory implements RepositoryFactory
{
    /** @param EntityRepository<covariant object> $repository */
    public function __construct(private readonly EntityRepository $repository)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $entityName
     * @return EntityRepository<T>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        /** @var EntityRepository<T> $repository */
        $repository = $this->repository;
        return $repository;
    }
}

/**
 * @param EntityRepository<covariant object> $repository
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.MissingImport")
 */
function additionalInfoEntityManager(EntityRepository $repository): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
    $configuration->enableNativeLazyObjects(true);
    $configuration->setRepositoryFactory(new AdditionalInfoTestRepositoryFactory($repository));
    $connection = DriverManager::getConnection(['driver' => 'pdo_mysql'], $configuration);
    return new EntityManager($connection, $configuration);
}

/**
 * @param callable(int): ?object $loader
 * @param array<string,string|null> $params
 * @SuppressWarnings("PHPMD.MissingImport")
 */
function additionalInfoController(object $entityManager, callable $loader, array $params = []): AdditionalInfoController
{
    $session = new AdditionalInfoTestSession(['identity' => ['id' => 7]]);
    $container = new Container(new AdditionalInfoTestResources($entityManager), new Auth($session, $loader));
    $route = new RouteMatch('additionalinfo', 'typeahead', AdditionalInfoController::class, 'typeaheadAction', $params);
    return new AdditionalInfoController($container, $route);
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== additional-info controller boundaries ==\n";

$anonymousContainer = new Container(
    new AdditionalInfoTestResources(new stdClass()),
    new Auth(new AdditionalInfoTestSession(), static fn (int $id): ?object => null),
);
$anonymous = new AdditionalInfoController(
    $anonymousContainer,
    new RouteMatch('additionalinfo', 'typeahead', AdditionalInfoController::class, 'typeaheadAction', ['type' => 'department']),
);
$anonymousResponse = $anonymous->typeaheadAction();
$check(
    'anonymous requests return an empty JSON list without touching Doctrine',
    $anonymousResponse->status === 200 && $anonymousResponse->body === '[]'
);

$admin = new \Entities\Admin();
$admin->setUsername('operator@example.test');
$admin->setActive(true);
$repository = new AdditionalInfoTestRepository(['Sales', 'Support']);
$response = additionalInfoController(
    additionalInfoEntityManager($repository),
    static fn (int $id): object => $admin,
    ['type' => 'department'],
)->typeaheadAction();
$check(
    'authenticated requests preserve the repository values and JSON response',
    $response->body === '["Sales","Support"]' && $response->contentType === 'application/json; charset=utf-8'
);
$check(
    'the route type and authenticated admin reach the mailbox-preference repository',
    $repository->calls === [['xpiInfo.department', $admin]]
);

$defaultRepository = new AdditionalInfoTestRepository([]);
additionalInfoController(
    additionalInfoEntityManager($defaultRepository),
    static fn (int $id): object => $admin,
)->typeaheadAction();
$check(
    'a missing type preserves the empty-suffix lookup',
    $defaultRepository->calls === [['xpiInfo.', $admin]]
);

$invalidManagerGuarded = false;
try {
    additionalInfoController(new stdClass(), static fn (int $id): object => $admin)->typeaheadAction();
} catch (LogicException $e) {
    $invalidManagerGuarded = $e->getMessage() === 'Doctrine entity manager resource has an invalid type';
}
$check('an invalid entity manager fails closed', $invalidManagerGuarded);

$invalidRepositoryGuarded = false;
try {
    additionalInfoController(
        additionalInfoEntityManager(new AdditionalInfoWrongRepository()),
        static fn (int $id): object => $admin,
    )->typeaheadAction();
} catch (LogicException $e) {
    $invalidRepositoryGuarded = $e->getMessage() === 'Mailbox preference repository has an invalid type';
}
$check('an unexpected repository fails closed without fallback', $invalidRepositoryGuarded);

$repositoryError = new RuntimeException('lookup failed');
$errorPropagated = false;
try {
    additionalInfoController(
        additionalInfoEntityManager(new AdditionalInfoTestRepository([], $repositoryError)),
        static fn (int $id): object => $admin,
    )->typeaheadAction();
} catch (RuntimeException $e) {
    $errorPropagated = $e === $repositoryError;
}
$check('repository errors propagate unchanged', $errorPropagated);

$invalidAdminGuarded = false;
try {
    additionalInfoController(
        additionalInfoEntityManager(new AdditionalInfoTestRepository()),
        static fn (int $id): object => new stdClass(),
    )->typeaheadAction();
} catch (LogicException $e) {
    $invalidAdminGuarded = $e->getMessage() === 'Authenticated admin has an invalid type';
}
$check('an invalid authenticated identity fails closed', $invalidAdminGuarded);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all additional-info controller assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
