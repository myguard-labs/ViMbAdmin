<?php

/**
 * Focused behaviour test for the MailboxAutomaticAliases plugin. It uses the
 * native mutation-context contracts and a small Doctrine double: no database
 * is needed to prove that configured aliases are persisted and domain aliases
 * suppress redundant automatic aliases.
 */

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/../application/' . $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/../library/OSS/Plugin/Observer.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MutationContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/AliasContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MailboxContext.php';
require __DIR__ . '/../application/plugins/MailboxAutomaticAliases.php';

if (!function_exists('_')) {
    function _(string $message): string
    {
        return $message;
    }
}

final class AutomaticAliasRepository extends \Repositories\Alias
{
    /** @var array<string, \Entities\Alias> */
    public array $aliases = [];

    public function __construct()
    {
    }

    public function findOneBy(array $criteria, array|null $orderBy = null): object|null
    {
        $address = $criteria['address'] ?? null;
        $domain = $criteria['Domain'] ?? null;
        $alias = is_string($address) ? ($this->aliases[$address] ?? null) : null;
        if (!$alias instanceof \Entities\Alias) {
            return null;
        }

        if (!$domain instanceof \Entities\Domain) {
            return $alias;
        }

        $aliasDomain = $alias->getDomain();
        return $aliasDomain instanceof \Entities\Domain
            && $aliasDomain->requiredId() === $domain->requiredId() ? $alias : null;
    }

    /** @return array<int, array{id: int|string, address: string, goto: string, active: bool, domain: string}> */
    public function filterForAliasList($filter, $admin, $domain = null, $ima = false): array
    {
        foreach ($this->aliases as $address => $alias) {
            if (str_starts_with($address, $filter)) {
                return [[
                    'id' => 1,
                    'address' => $alias->getAddress() ?? '',
                    'goto' => $alias->getGoto() ?? '',
                    'active' => $alias->getActive() ?? false,
                    'domain' => 'example.test',
                ]];
            }
        }

        return [];
    }
}

final class AutomaticAliasEntityManager extends \Doctrine\ORM\Decorator\EntityManagerDecorator
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;
    public int $repositoryCalls = 0;

    public function __construct(private AutomaticAliasRepository $repository)
    {
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([], true);
        $config->enableNativeLazyObjects(true);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql'], $config);
        parent::__construct(new \Doctrine\ORM\EntityManager($connection, $config));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return \Doctrine\ORM\EntityRepository<T>
     */
    public function getRepository(string $class): \Doctrine\ORM\EntityRepository
    {
        $this->repositoryCalls++;
        if (ltrim($class, '\\') !== \Entities\Alias::class) {
            throw new \LogicException('The automatic-alias double only serves the Alias repository.');
        }

        /** @var \Doctrine\ORM\EntityRepository<T> $repository */
        $repository = $this->repositoryForAlias();
        return $repository;
    }

    private function repositoryForAlias(): mixed
    {
        return $this->repository;
    }
    public function persist(object $entity): void
    {
        $this->persisted[] = $entity;
    }
    public function flush(): void
    {
        $this->flushes++;
    }
}

final class AutomaticAliasMailboxContext implements ViMbAdmin_Plugin_MailboxContext
{
    /** @var list<mixed> */
    public array $messages = [];
    /** @var list<mixed> */
    public array $messageClasses = [];

    /** @param array<string, mixed> $options */
    public function __construct(
        private array $options,
        private AutomaticAliasEntityManager $entityManager,
        private \Entities\Admin $admin,
        private \Entities\Domain $domain,
        private \Entities\Mailbox $mailbox,
    ) {
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
    public function getD2EM(): AutomaticAliasEntityManager
    {
        return $this->entityManager;
    }
    public function getAdmin(): \Entities\Admin
    {
        return $this->admin;
    }
    public function getDomain(): \Entities\Domain
    {
        return $this->domain;
    }
    public function getMailbox(): \Entities\Mailbox
    {
        return $this->mailbox;
    }
    public function addMessage(mixed $message, mixed $class = null, mixed $type = null): void
    {
        $this->messages[] = $message;
        $this->messageClasses[] = $class;
    }
}

final class AutomaticAliasAliasContext implements ViMbAdmin_Plugin_AliasContext
{
    /** @var list<mixed> */
    public array $messages = [];
    /** @var list<mixed> */
    public array $messageClasses = [];

    /** @param array<string, mixed> $options */
    public function __construct(
        private array $options,
        private AutomaticAliasEntityManager $entityManager,
        private \Entities\Admin $admin,
        private \Entities\Domain $domain,
        private \Entities\Alias $alias,
    ) {
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
    public function getD2EM(): AutomaticAliasEntityManager
    {
        return $this->entityManager;
    }
    public function getAdmin(): \Entities\Admin
    {
        return $this->admin;
    }
    public function getDomain(): \Entities\Domain
    {
        return $this->domain;
    }
    public function getAlias(): \Entities\Alias
    {
        return $this->alias;
    }
    public function addMessage(mixed $message, mixed $class = null, mixed $type = null): void
    {
        $this->messages[] = $message;
        $this->messageClasses[] = $class;
    }
}

$failures = 0;
function checkAutomaticAlias(string $label, bool $ok): int
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    return $ok ? 0 : 1;
}

function automaticAliasThrows(string $message, \Closure $operation): bool
{
    try {
        $operation();
    } catch (\LogicException $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
}

function automaticAliasDomain(bool $initialized = true, bool $named = true): \Entities\Domain
{
    $domain = new \Entities\Domain();
    if ($named) {
        $domain->setDomain('example.test');
    }
    if ($initialized) {
        (new ReflectionMethod($domain, 'assignGeneratedId'))->invoke($domain, 17);
    }
    return $domain;
}

function makeContext(AutomaticAliasRepository $repository, bool $initialized = true, bool $named = true, bool $mailboxNamed = true): AutomaticAliasMailboxContext
{
    $domain = automaticAliasDomain($initialized, $named);
    $admin = new \Entities\Admin();
    $admin->addDomain($domain);
    $mailbox = new \Entities\Mailbox();
    if ($mailboxNamed) {
        $mailbox->setUsername('user@example.test');
    }
    return new AutomaticAliasMailboxContext(
        ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
            'defaultAliases' => ['postmaster', 'abuse'],
            'defaultMapping' => ['postmaster' => 'root@example.test', 'abuse' => 'root@example.test'],
        ]]],
        new AutomaticAliasEntityManager($repository),
        $admin,
        $domain,
        $mailbox,
    );
}

function makeAliasContext(AutomaticAliasRepository $repository, \Entities\Alias $alias): AutomaticAliasAliasContext
{
    $domain = automaticAliasDomain()->setAliasCount(0);
    $admin = new \Entities\Admin();
    $admin->addDomain($domain);
    return new AutomaticAliasAliasContext(
        ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
            'defaultAliases' => ['postmaster'],
            'defaultMapping' => ['postmaster' => 'root@example.test'],
        ]]],
        new AutomaticAliasEntityManager($repository),
        $admin,
        $domain,
        $alias,
    );
}

echo "== MailboxAutomaticAliases ==\n";

$repository = new AutomaticAliasRepository();
$context = makeContext($repository);
$plugin = new ViMbAdminPlugin_MailboxAutomaticAliases($context);
$plugin->mailbox_add_addPostflush($context, ['options' => []]);
$entityManager = $context->getD2EM();
$alias = $entityManager->persisted[0] ?? null;
$failures += checkAutomaticAlias('creates the configured automatic alias', $alias instanceof \Entities\Alias);
$failures += checkAutomaticAlias('uses the configured goto mapping', $alias instanceof \Entities\Alias && $alias->getGoto() === 'root@example.test');
$failures += checkAutomaticAlias('creates the configured alias group and flushes once', count($entityManager->persisted) === 2 && $alias instanceof \Entities\Alias && $alias->getActive() === true && $entityManager->flushes === 1);
$failures += checkAutomaticAlias('reports every created alias', count($context->messages) === 2);
$persistedIdentities = [];
foreach ($entityManager->persisted as $item) {
    $persistedIdentities[] = $item instanceof \Entities\Alias
        ? [$item->getAddress(), $item->getGoto()]
        : [null, null];
}
$failures += checkAutomaticAlias('persists both exact automatic alias identities', $persistedIdentities === [
    ['postmaster@example.test', 'root@example.test'],
    ['abuse@example.test', 'root@example.test'],
]);
$failures += checkAutomaticAlias('reports both exact automatic alias identities', $context->messages === [
    'Auto-created alias postmaster@example.test -> root@example.test.',
    'Auto-created alias abuse@example.test -> root@example.test.',
]);

$duplicateRepository = new AutomaticAliasRepository();
$duplicateDomain = automaticAliasDomain();
$duplicateAdmin = new \Entities\Admin();
$duplicateAdmin->addDomain($duplicateDomain);
$duplicateContext = new AutomaticAliasMailboxContext(
    ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
        'defaultAliases' => ['postmaster', 'POSTMASTER'],
        'defaultMapping' => ['postmaster' => 'root@example.test'],
    ]]],
    new AutomaticAliasEntityManager($duplicateRepository),
    $duplicateAdmin,
    $duplicateDomain,
    (new \Entities\Mailbox())->setUsername('user@example.test'),
);
(new ViMbAdminPlugin_MailboxAutomaticAliases($duplicateContext))
    ->mailbox_add_addPostflush($duplicateContext, ['options' => []]);
$failures += checkAutomaticAlias(
    'duplicate configured aliases persist once and flush once',
    count($duplicateContext->getD2EM()->persisted) === 1
    && $duplicateContext->getD2EM()->flushes === 1
    && count($duplicateContext->messages) === 1
);

$unpersistedContext = makeContext(new AutomaticAliasRepository(), false);
$failures += checkAutomaticAlias(
    'automatic alias lookup rejects a null domain id',
    automaticAliasThrows(
        'Domain id cannot be null.',
        static function () use ($unpersistedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unpersistedContext))
                ->mailbox_add_addPostflush($unpersistedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'null domain id fails before alias persistence or flush',
    $unpersistedContext->getD2EM()->persisted === []
        && $unpersistedContext->getD2EM()->flushes === 0
        && $unpersistedContext->messages === [],
);

$unnamedContext = makeContext(new AutomaticAliasRepository(), true, false);
$failures += checkAutomaticAlias(
    'automatic alias construction rejects a null domain name',
    automaticAliasThrows(
        'Domain name cannot be null.',
        static function () use ($unnamedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unnamedContext))
                ->mailbox_add_addPostflush($unnamedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'null domain name fails before alias persistence or flush',
    $unnamedContext->getD2EM()->persisted === []
        && $unnamedContext->getD2EM()->flushes === 0
        && $unnamedContext->messages === [],
);

$unnamedMailboxContext = makeContext(new AutomaticAliasRepository(), true, true, false);
$failures += checkAutomaticAlias(
    'automatic alias construction rejects a null mailbox username',
    automaticAliasThrows(
        'Mailbox username cannot be null.',
        static function () use ($unnamedMailboxContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unnamedMailboxContext))
                ->mailbox_add_addPostflush($unnamedMailboxContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'null mailbox username fails before alias persistence or flush',
    $unnamedMailboxContext->getD2EM()->persisted === []
        && $unnamedMailboxContext->getD2EM()->flushes === 0
        && $unnamedMailboxContext->messages === [],
);

$repository = new AutomaticAliasRepository();
$sourceAlias = (new \Entities\Alias())
    ->setAddress('source@example.test')
    ->setGoto('destination@example.test');
$aliasContext = makeAliasContext($repository, $sourceAlias);
(new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))->alias_add_addPostflush($aliasContext, ['options' => []]);
$createdFromAlias = $aliasContext->getD2EM()->persisted[0] ?? null;
$failures += checkAutomaticAlias(
    'alias add creates the configured automatic alias',
    $createdFromAlias instanceof \Entities\Alias
        && $createdFromAlias->getAddress() === 'postmaster@example.test'
        && $createdFromAlias->getGoto() === 'root@example.test',
);

$repository = new AutomaticAliasRepository();
$malformedAlias = (new \Entities\Alias())->setGoto('destination@example.test');
$malformedContext = makeAliasContext($repository, $malformedAlias);
$malformedDomainCount = $malformedContext->getDomain()->getAliasCount();
$failures += checkAutomaticAlias(
    'alias add rejects a null source address',
    automaticAliasThrows(
        'Alias address cannot be null.',
        static function () use ($malformedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($malformedContext))
                ->alias_add_addPostflush($malformedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'alias add identity failure has no side effects',
    $malformedContext->getD2EM()->persisted === []
        && $malformedContext->getD2EM()->flushes === 0
        && $malformedContext->getDomain()->getAliasCount() === $malformedDomainCount
        && $malformedContext->messages === [],
);

$repository = new AutomaticAliasRepository();
$repository->aliases['@example.test'] = (new \Entities\Alias())
    ->setAddress('@example.test')
    ->setGoto('catchall@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$context = makeContext($repository);
(new ViMbAdminPlugin_MailboxAutomaticAliases($context))->mailbox_add_addPostflush($context, ['options' => []]);
$failures += checkAutomaticAlias('does not create aliases when an active domain alias exists', $context->getD2EM()->persisted === [] && $context->messages === []);

$repository = new AutomaticAliasRepository();
$repository->aliases['postmaster@example.test.au'] = (new \Entities\Alias())
    ->setAddress('postmaster@example.test.au')
    ->setGoto('source@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$context = makeContext($repository);
(new ViMbAdminPlugin_MailboxAutomaticAliases($context))->mailbox_add_addPostflush($context, ['options' => []]);
$createdBesidePrefixNeighbour = $context->getD2EM()->persisted[0] ?? null;
$failures += checkAutomaticAlias(
    'same-prefix neighbour does not suppress automatic alias creation',
    $createdBesidePrefixNeighbour instanceof \Entities\Alias
        && $createdBesidePrefixNeighbour->getAddress() === 'postmaster@example.test',
);

$sourceAlias = (new \Entities\Alias())
    ->setAddress('source@example.test')
    ->setGoto('destination@example.test');
$aliasContext = makeAliasContext($repository, $sourceAlias);
$deleteAllowed = (new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))
    ->alias_delete_preRemove($aliasContext, ['options' => []]);
$failures += checkAutomaticAlias(
    'same-prefix neighbour does not block unrelated alias deletion',
    $deleteAllowed && $aliasContext->messages === [],
);

$repository = new AutomaticAliasRepository();
$repository->aliases['postmaster@example.test'] = (new \Entities\Alias())
    ->setAddress('postmaster@example.test')
    ->setGoto('user@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$context = makeContext($repository);
$mailboxToggleAllowed = (new ViMbAdminPlugin_MailboxAutomaticAliases($context))
    ->mailbox_toggleActive_preToggle($context, ['active' => true]);
$failures += checkAutomaticAlias(
    'mailbox toggle guard reports an error and returns false',
    $mailboxToggleAllowed === false
        && count($context->messages) === 1
        && $context->messageClasses === [OSS_Message::ERROR],
);

$domainAlias = (new \Entities\Alias())
    ->setAddress('@example.test')
    ->setGoto('catchall@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$aliasContext = makeAliasContext(new AutomaticAliasRepository(), $domainAlias);
$domainAliasToggleAllowed = (new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))
    ->alias_toggleActive_preToggle($aliasContext, ['active' => true]);
$failures += checkAutomaticAlias(
    'domain-alias toggle guard reports an error and returns false',
    $domainAliasToggleAllowed === false
        && count($aliasContext->messages) === 1
        && $aliasContext->messageClasses === [OSS_Message::ERROR],
);

$automaticAlias = (new \Entities\Alias())
    ->setAddress('postmaster@example.test')
    ->setGoto('root@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$aliasContext = makeAliasContext(new AutomaticAliasRepository(), $automaticAlias);
$automaticAliasToggleAllowed = (new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))
    ->alias_toggleActive_preToggle($aliasContext, ['active' => true]);
$failures += checkAutomaticAlias(
    'required-alias toggle guard reports an error and returns false',
    $automaticAliasToggleAllowed === false
        && count($aliasContext->messages) === 1
        && $aliasContext->messageClasses === [OSS_Message::ERROR],
);

$repository = new AutomaticAliasRepository();
$repository->aliases['postmaster@example.test'] = (new \Entities\Alias())
    ->setAddress('postmaster@example.test')
    ->setGoto('source@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$sourceAlias = (new \Entities\Alias())
    ->setAddress('source@example.test')
    ->setGoto('destination@example.test')
    ->setActive(true)
    ->setDomain(automaticAliasDomain());
$aliasContext = makeAliasContext($repository, $sourceAlias);
$gotoAliasToggleAllowed = (new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))
    ->alias_toggleActive_preToggle($aliasContext, ['active' => true]);
$failures += checkAutomaticAlias(
    'goto-alias toggle guard reports an error and returns false',
    $gotoAliasToggleAllowed === false
        && count($aliasContext->messages) === 1
        && $aliasContext->messageClasses === [OSS_Message::ERROR],
);

$otherDomain = (new \Entities\Domain())->setDomain('other.test');
(new ReflectionMethod($otherDomain, 'assignGeneratedId'))->invoke($otherDomain, 18);
$repository = new AutomaticAliasRepository();
$repository->aliases['postmaster@example.test'] = (new \Entities\Alias())
    ->setAddress('postmaster@example.test')
    ->setGoto('root@other.test')
    ->setActive(true)
    ->setDomain($otherDomain);
$context = makeContext($repository);
(new ViMbAdminPlugin_MailboxAutomaticAliases($context))->mailbox_add_addPostflush($context, ['options' => []]);
$crossDomainCreation = $context->getD2EM()->persisted[0] ?? null;
$failures += checkAutomaticAlias(
    'exact-address alias in another domain does not suppress creation',
    $crossDomainCreation instanceof \Entities\Alias
        && $crossDomainCreation->getAddress() === 'postmaster@example.test',
);

$repository = new AutomaticAliasRepository();
$unauthorizedDomain = automaticAliasDomain();
$unauthorizedContext = new AutomaticAliasMailboxContext(
    ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
        'defaultAliases' => ['postmaster'],
        'defaultMapping' => ['postmaster' => 'root@example.test'],
    ]]],
    new AutomaticAliasEntityManager($repository),
    new \Entities\Admin(),
    $unauthorizedDomain,
    (new \Entities\Mailbox())->setUsername('user@example.test'),
);
$failures += checkAutomaticAlias(
    'non-super admin without domain ownership is rejected',
    automaticAliasThrows(
        'Admin cannot manage the automatic alias domain.',
        static function () use ($unauthorizedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unauthorizedContext))
                ->mailbox_add_addPostflush($unauthorizedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'unauthorized lookup fails before persistence or flush',
    $unauthorizedContext->getD2EM()->persisted === []
        && $unauthorizedContext->getD2EM()->flushes === 0
        && $unauthorizedContext->getD2EM()->repositoryCalls === 0
        && $unauthorizedContext->messages === [],
);

echo "\n";
if ($failures === 0) {
    echo "OK: all MailboxAutomaticAliases assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
