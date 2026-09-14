<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../application/Entities/Admin.php';
require_once __DIR__ . '/../../application/Entities/AdminPreference.php';
require_once __DIR__ . '/../../application/Entities/Alias.php';
require_once __DIR__ . '/../../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../../application/Entities/Mailbox.php';
require_once __DIR__ . '/../../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../../application/Entities/Domain.php';
require_once __DIR__ . '/../../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../../application/Entities/Log.php';
require_once __DIR__ . '/../../application/Entities/DatabaseVersion.php';
require_once __DIR__ . '/../../application/Repositories/Admin.php';
require_once __DIR__ . '/../../application/Repositories/Domain.php';
require_once __DIR__ . '/../../application/Repositories/Mailbox.php';
require_once __DIR__ . '/../../application/Repositories/Alias.php';

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Mail\Mailer;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class WorkflowSession implements SessionStorage
{
    /** @var array<string,mixed> */
    private array $data = ['csrfToken' => 'workflow-csrf'];
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __set(string $key, mixed $value): void { $this->set($key, $value); }
    public function __isset(string $key): bool { return $this->has($key); }
    public function __unset(string $key): void { $this->remove($key); }
}

final class WorkflowView
{
    /** @var array<string,mixed> */
    private array $values = [];
    public function __set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function render(string $script): string
    {
        $form = $this->values['formHtml'] ?? null;
        return is_string($form) ? $form : 'rendered:' . $script;
    }
}

final class WorkflowTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];
    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        $this->messages[] = $message;
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
    public function __toString(): string { return 'workflow-spy'; }
}

final class WorkflowAdminRepository extends \Repositories\Admin
{
    public function __construct(private WorkflowHarness $harness) {}
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): object
    {
        return $id === 1 || $id === '1' ? $this->harness->actor : $this->harness->target;
    }
    public function findOneBy(array $criteria, ?array $orderBy = null): object { return $this->harness->actor; }
    public function getCount(): int { return $this->harness->setup ? 0 : 1; }
}

final class WorkflowDomainRepository extends \Repositories\Domain
{
    public function __construct(private WorkflowHarness $harness) {}
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $id === 1 || $id === '1' ? $this->harness->domain : null;
    }
    public function loadForAdminAsArray($admin, $onlyNames = false): array { return [1 => 'example.test']; }
}

final class WorkflowMailboxRepository extends \Repositories\Mailbox
{
    public function __construct(private WorkflowHarness $harness) {}
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): object
    {
        return $this->harness->mailbox;
    }
    public function isUnique($email): bool { return true; }
}

final class WorkflowAliasRepository extends \Repositories\Alias
{
    public function __construct(private WorkflowHarness $harness) {}
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): object
    {
        return $this->harness->alias;
    }
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object { return null; }
}

final class WorkflowAdmin extends \Entities\Admin
{
    public function _getPreferences(): array { return array_values($this->getPreferences()->toArray()); }
}

final class WorkflowMailbox extends \Entities\Mailbox
{
    public function _getPreferences(): array { return array_values($this->getPreferences()->toArray()); }
}

final class WorkflowAlias extends \Entities\Alias
{
    public function _getPreferences(): array { return array_values($this->getPreferences()->toArray()); }
}

// Only the persistence boundary is replaced. Real controller, service, form,
// password, mailer, and plugin code execute; an unexpected DB call fails.
final class WorkflowPersistence extends \Doctrine\ORM\UnitOfWork
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;
    public function persist(object $object): void { $this->persisted[] = $object; }
    public function commit(): void { $this->flushes++; }
}

final class WorkflowRepositoryFactory implements \Doctrine\ORM\Repository\RepositoryFactory
{
    /** @var array<string,EntityRepository<covariant object>> */
    private array $repositories;
    public function __construct(WorkflowHarness $harness)
    {
        $this->repositories = [
            'Entities\\Admin' => new WorkflowAdminRepository($harness),
            'Entities\\Domain' => new WorkflowDomainRepository($harness),
            'Entities\\Mailbox' => new WorkflowMailboxRepository($harness),
            'Entities\\Alias' => new WorkflowAliasRepository($harness),
        ];
    }
    /**
     * @template T of object
     * @param class-string<T> $entityName
     * @return EntityRepository<T>
     */
    public function getRepository(\Doctrine\ORM\EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $repository = $this->repositories[ltrim($entityName, '\\')]
            ?? throw new LogicException('Unexpected repository ' . $entityName);
        // The exhaustive mapping above binds each entity to its repository.
        /** @var EntityRepository<T> $repository */
        return $repository;
    }
}

final class WorkflowHarness
{
    public const PASSWORD = 'workflow example password';
    public \Doctrine\ORM\EntityManager $em;
    public WorkflowPersistence $persistence;
    public WorkflowSession $session;
    public WorkflowTransport $transport;
    public Container $container;
    public \Entities\Admin $actor;
    public \Entities\Admin $target;
    public \Entities\Domain $domain;
    public \Entities\Mailbox $mailbox;
    public \Entities\Alias $alias;
    /** @var array<string,mixed> */
    public array $options;

    public function __construct(public bool $setup = false, bool $guest = false, bool $plugins = true, bool $mailboxPlugin = true)
    {
        $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([]);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setRepositoryFactory(new WorkflowRepositoryFactory($this));
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration);
        $this->em = new \Doctrine\ORM\EntityManager($connection, $configuration);
        $this->persistence = new WorkflowPersistence($this->em);
        (new ReflectionProperty(\Doctrine\ORM\EntityManager::class, 'unitOfWork'))->setValue($this->em, $this->persistence);
        $this->session = new WorkflowSession();
        $this->transport = new WorkflowTransport();
        $hash = \OSS_Auth_Password::hash(self::PASSWORD, ['pwhash' => 'crypt:sha512']);
        $this->actor = (new WorkflowAdmin())->setUsername('actor@example.test')->setActive(true)->setSuper(true)->setPassword($hash);
        $this->target = (new WorkflowAdmin())->setUsername('target@example.test')->setActive(true)->setSuper(false)->setPassword($hash);
        $this->domain = (new \Entities\Domain())->setDomain('example.test')->setActive(true)->setQuota(0)->setMaxQuota(0)->setMaxAliases(0)->setMaxMailboxes(0)->setAliasCount(1)->setMailboxCount(1);
        $this->mailbox = (new WorkflowMailbox())->setUsername('old@example.test')->setLocalPart('old')->setDomain($this->domain)->setPassword($hash)->setQuota(0);
        $this->alias = (new WorkflowAlias())->setAddress('alias@example.test')->setGoto('old@example.test')->setDomain($this->domain);
        foreach ([$this->actor, $this->domain, $this->mailbox, $this->alias] as $entity) {
            (new ReflectionProperty(get_parent_class($entity) ?: $entity::class, 'id'))->setValue($entity, 1);
        }
        (new ReflectionProperty(\Entities\Admin::class, 'id'))->setValue($this->target, 2);
        $this->alias->addPreference((new \Entities\AliasPreference())->setAttribute('xpiInfo.department')->setValue('existing-alias-value')->setIx(0)->setExpire(0)->setAlias($this->alias));
        $this->mailbox->addPreference((new \Entities\MailboxPreference())->setAttribute('xpiInfo.department')->setValue('existing-mailbox-value')->setIx(0)->setExpire(0)->setMailbox($this->mailbox));
        $element = ['options' => ['label' => 'Department', 'required' => true]];
        $this->options = [
            'securitysalt' => str_repeat('s', 64),
            'resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512', 'rememberme' => ['enabled' => true, 'timeout' => 86400, 'salt' => str_repeat('r', 64), 'secure' => true]]]],
            'bruteforce' => ['enabled' => false, 'statedir' => __DIR__ . '/../../var/tmp/workflow-bruteforce'],
            'defaults' => ['mailbox' => ['min_password_length' => 8, 'password_scheme' => 'crypt:sha512']],
            'server' => ['email' => ['address' => 'support@example.test', 'name' => 'Support']],
            'vimbadmin_plugins' => [
                'AdditionalInfo' => ['enabled' => $plugins && $mailboxPlugin, 'elements' => ['department' => $element], 'alias' => ['elements' => ['department' => $element]]],
                'WorkflowProbe' => ['enabled' => $plugins],
            ],
        ];
        $resources = new class ($this, new WorkflowView()) {
            public function __construct(private WorkflowHarness $harness, private WorkflowView $view) {}
            /** @return array<string,mixed> */
            public function getOptions(): array { return $this->harness->options; }
            public function getResource(string $name): mixed
            {
                return match ($name) {
                    'namespace' => $this->harness->session,
                    'doctrine2' => $this->harness->em,
                    'smarty' => $this->view,
                    default => null,
                };
            }
        };
        $auth = new Auth($this->session, fn(int $id): object => $this->actor);
        if (!$setup && !$guest) { $auth->establish($this->actor); }
        $this->container = new Container($resources, $auth);
        $mailer = new Mailer([]);
        (new ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $this->transport);
        (new ReflectionProperty(Container::class, 'mailer'))->setValue($this->container, $mailer);
    }

    /**
     * @param array<string,?string> $params
     * @param array<string,mixed>|null $post
     */
    public function run(string $controller, string $action, array $params = [], ?array $post = null): \ViMbAdmin\Kernel\Http\Response
    {
        $_GET = [];
        $_POST = $post ?? [];
        $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
        $class = 'ViMbAdmin\\Kernel\\Controller\\' . ucfirst($controller) . 'Controller';
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action)))) . 'Action';
        $route = new \ViMbAdmin\Kernel\RouteMatch($controller, $action, $class, $method, $params);
        $response = (new $class($this->container, $route))->{$method}();
        if (!$response instanceof \ViMbAdmin\Kernel\Http\Response) {
            throw new LogicException('Unexpected controller response');
        }
        return $response;
    }
}
