<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\DomainController;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class DomainQuotaSession implements SessionStorage
{
    public function has(string $key): bool
    {
        return false;
    }
    public function get(string $key): mixed
    {
        return null;
    }
    public function set(string $key, mixed $value): void
    {
    }
    public function remove(string $key): void
    {
    }
}

/** @extends EntityRepository<\Entities\Domain> */
final class DomainQuotaRepository extends EntityRepository
{
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return null;
    }
}

final class DomainQuotaRepositoryFactory implements RepositoryFactory
{
    /** @template T of object
     *  @param class-string<T> $entityName
     *  @return EntityRepository<T> */
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        if (ltrim($entityName, '\\') !== \Entities\Domain::class) {
            throw new LogicException('Unexpected repository: ' . $entityName);
        }
        return new DomainQuotaRepository($manager, new ClassMetadata(\Entities\Domain::class));
    }
}

final class DomainQuotaManager extends EntityManager
{
    public ?\Entities\Domain $persisted = null;
    public int $flushes = 0;

    public function __construct()
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setRepositoryFactory(new DomainQuotaRepositoryFactory());
        parent::__construct(
            DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration),
            $configuration,
        );
    }

    public function persist(object $entity): void
    {
        if (!$entity instanceof \Entities\Domain) {
            throw new LogicException('Unexpected entity');
        }
        (new ReflectionMethod($entity, 'assignGeneratedId'))->invoke($entity, 1);
        $this->persisted = $entity;
    }

    public function flush(): void
    {
        $this->flushes++;
    }
}

final class DomainQuotaResources
{
    /** @param array<string,mixed> $options */
    public function __construct(private readonly DomainQuotaManager $manager, private readonly array $options)
    {
    }
    public function getResource(string $name): object
    {
        return $this->manager;
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

function quotaCheck(string $name, bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException($name);
    }
    echo "ok: {$name}\n";
}

/** @param array<string,mixed> $options
 *  @param array<string,mixed> $params
 *  @return array{\Entities\Domain,DomainQuotaManager} */
function mcpDomainWithDefaults(array $options, array $params = []): array
{
    $manager = new DomainQuotaManager();
    $session = new DomainQuotaSession();
    $container = new Container(
        new DomainQuotaResources($manager, $options),
        new Auth($session, static fn (int $id): null => null),
    );
    $controller = new McpController(
        $container,
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
    (new ReflectionMethod($controller, '_domainCreate'))->invoke(
        $controller,
        array_replace(['domain' => 'example.test'], $params),
    );
    if ($manager->persisted === null) {
        throw new LogicException('Domain was not persisted');
    }
    return [$manager->persisted, $manager];
}

/** @param array<string,mixed> $options */
function guiDomainWithDefaults(array $options): \Entities\Domain
{
    $manager = new DomainQuotaManager();
    $session = new DomainQuotaSession();
    $controller = new DomainController(
        new Container(
            new DomainQuotaResources($manager, $options),
            new Auth($session, static fn (int $id): null => null),
        ),
        new RouteMatch('domain', 'add', DomainController::class, 'addAction', []),
    );
    $form = (new ReflectionMethod($controller, 'buildDomainAddForm'))->invoke($controller, $options);
    if (!$form instanceof Form || $form->field('quota') === null || $form->field('max_quota') === null) {
        throw new LogicException('GUI quota fields are unavailable');
    }
    $values = [
        'description' => '', 'transport' => 'virtual', 'backupmx' => false, 'active' => true,
        'max_aliases' => 0, 'max_mailboxes' => 0,
        'quota' => $form->field('quota')->value(),
        'max_quota' => $form->field('max_quota')->value(),
    ];
    $multiplier = (new ReflectionMethod($controller, 'quotaMultiplier'))->invoke(null, $options);
    if (!is_string($multiplier)) {
        throw new LogicException('GUI quota multiplier is unavailable');
    }
    $domain = new \Entities\Domain();
    (new ReflectionMethod($controller, 'applyFormFields'))->invoke(
        $controller,
        $domain,
        $values,
        new \OSS_Filter_FileSize($multiplier),
    );
    return $domain;
}

foreach ([
    'MB configured defaults' => [['defaults' => ['domain' => ['quota' => 2, 'maxquota' => 3], 'quota' => ['multiplier' => 'MB']]], 2097152, 3145728],
    'fractional KB configured defaults' => [['defaults' => ['domain' => ['quota' => '.5', 'maxquota' => '1.5'], 'quota' => ['multiplier' => 'kb']]], 512, 1536],
    'float configured defaults' => [['defaults' => ['domain' => ['quota' => 1.25, 'maxquota' => 2.5], 'quota' => ['multiplier' => 'MB']]], 1310720, 2621440],
    'zero configured defaults' => [['defaults' => ['domain' => ['quota' => 0, 'maxquota' => 0], 'quota' => ['multiplier' => 'GB']]], 0, 0],
    'missing defaults use zero' => [[], 0, 0],
    'byte multiplier boundary' => [['defaults' => ['domain' => ['quota' => 1, 'maxquota' => 9223372036854774784], 'quota' => ['multiplier' => 'B']]], 1, 9223372036854774784],
] as $name => [$options, $quota, $maxQuota]) {
    [$mcp, $manager] = mcpDomainWithDefaults($options);
    $gui = guiDomainWithDefaults($options);
    quotaCheck(
        "{$name}: MCP persisted quota equals GUI byte conversion",
        $mcp->getQuota() === $quota && $mcp->getMaxQuota() === $maxQuota
            && $gui->getQuota() === $quota && $gui->getMaxQuota() === $maxQuota
            && $manager->flushes === 1,
    );
}

$options = ['defaults' => ['domain' => ['quota' => 2, 'maxquota' => 3], 'quota' => ['multiplier' => 'MB']]];
[$explicit] = mcpDomainWithDefaults($options, ['quota' => 11, 'maxquota' => '12']);
quotaCheck('explicit MCP quotas retain byte semantics', $explicit->getQuota() === 11 && $explicit->getMaxQuota() === 12);
[$explicitZero] = mcpDomainWithDefaults($options, ['quota' => 0, 'maxquota' => 0]);
quotaCheck('explicit zero MCP quotas override nonzero defaults', $explicitZero->getQuota() === 0 && $explicitZero->getMaxQuota() === 0);

foreach ([
    'negative configured default' => [['defaults' => ['domain' => ['quota' => -1]]], []],
    'malformed configured default' => [['defaults' => ['domain' => ['quota' => '1MB']]], []],
    'wrong-type configured default' => [['defaults' => ['domain' => ['quota' => []]]], []],
    'integer-overflow boundary' => [['defaults' => ['domain' => ['quota' => '9223372036854775808'], 'quota' => ['multiplier' => 'B']]], []],
    'overflowing configured default' => [['defaults' => ['domain' => ['quota' => str_repeat('9', 400)]]], []],
    'unsupported configured multiplier' => [['defaults' => ['domain' => ['quota' => 1], 'quota' => ['multiplier' => 'TB']]], []],
    'malformed explicit MCP quota' => [$options, ['quota' => '1MB']],
    'negative explicit MCP maxquota' => [$options, ['maxquota' => -1]],
] as $name => [$invalidOptions, $params]) {
    try {
        mcpDomainWithDefaults($invalidOptions, $params);
        throw new RuntimeException("{$name}: creation unexpectedly succeeded");
    } catch (ViMbAdmin_Mcp_Exception $e) {
        quotaCheck("{$name}: creation rejected", $e->getMessage() !== '');
    }
}
