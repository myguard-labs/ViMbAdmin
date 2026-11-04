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
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir($config->getProxyDir() ?? sys_get_temp_dir());
            $config->setProxyNamespace($config->getProxyNamespace() ?? 'Proxies');
        }
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

/** The delegate remains the existing fake; this wrapper injects an exact flush error. */
final class PostflushReturnFaultManager extends \Doctrine\ORM\Decorator\EntityManagerDecorator
{
    public function __construct(AutomaticAliasEntityManager $delegate, public readonly \RuntimeException $sentinel)
    {
        parent::__construct($delegate);
    }
    public function flush(): void
    {
        throw $this->sentinel;
    }
}

use ViMbAdmin\Kernel\Plugin\PluginHost;
use ViMbAdmin\Kernel\Plugin\MailboxContext;
use ViMbAdmin\Kernel\Plugin\AliasContext;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

$failures = 0;
$checks = 0;
function postflushReturnCheck(string $label, bool $ok): void
{
    $GLOBALS['checks']++;
    if (!$ok) {
        $GLOBALS['failures']++;
    }
    echo ($ok ? 'ok: ' : 'FAIL: ') . $label . "\n";
}

/** @return array{MailboxContext|AliasContext, AutomaticAliasEntityManager, FlashMessages, ?RuntimeException} */
function postflushReturnContext(string $kind, string $mode): array
{
    $repository = new AutomaticAliasRepository();
    $domain = automaticAliasDomain()->setAliasCount(0);
    $actor = new \Entities\Admin();
    $actor->addDomain($domain);
    $options = ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
        'enabled' => $mode !== 'disabled',
        'defaultAliases' => $mode === 'empty' ? [] : ['postmaster', 'abuse'],
        'defaultMapping' => [],
    ]]];
    if ($mode === 'domain-skip') {
        $repository->aliases['@example.test'] = (new \Entities\Alias())
            ->setAddress('@example.test')->setGoto('catchall@example.test')
            ->setActive(true)->setDomain($domain);
    }
    if ($mode === 'all-existing' || $mode === 'one-existing') {
        foreach ($mode === 'all-existing' ? ['postmaster', 'abuse'] : ['postmaster'] as $name) {
            $repository->aliases[$name . '@example.test'] = (new \Entities\Alias())
                ->setAddress($name . '@example.test')->setGoto('existing@example.test')
                ->setActive(true)->setDomain($domain);
        }
    }
    $delegate = new AutomaticAliasEntityManager($repository);
    $sentinel = $mode === 'flush-fault' ? new RuntimeException('postflush return fixture flush sentinel') : null;
    $em = $sentinel === null ? $delegate : new PostflushReturnFaultManager($delegate, $sentinel);
    $flash = new FlashMessages(new MagicPropertyStorage(new stdClass()));
    $context = $kind === 'mailbox'
        ? new MailboxContext($em, $actor, $domain, (new \Entities\Mailbox())->setUsername('user@example.test'), $options, $flash)
        : new AliasContext($em, $actor, $domain, (new \Entities\Alias())->setAddress('source@example.test')->setGoto('destination@example.test'), $options, $flash);
    return [$context, $delegate, $flash, $sentinel];
}

foreach (['mailbox', 'alias'] as $kind) {
    foreach (['empty', 'domain-skip', 'all-existing', 'one-existing', 'configured', 'disabled', 'flush-fault'] as $mode) {
        foreach ($mode === 'disabled' ? ['native'] : ['native', 'direct'] as $route) {
            [$context, $delegate, $flash, $sentinel] = postflushReturnContext($kind, $mode);
            $label = $kind . '/' . $mode . '/' . $route;
            $error = null;
            $result = null;
            try {
                if ($route === 'native') {
                    $host = new PluginHost($context, __DIR__ . '/../application/plugins');
                    postflushReturnCheck($label . ': observer enrollment', $host->observerCount() === ($mode === 'disabled' ? 0 : 1));
                    $result = $host->notify($kind, 'add', 'addPostflush', $context, ['options' => $context->getOptions()]);
                } else {
                    $method = $kind . '_add_addPostflush';
                    $result = (new ViMbAdminPlugin_MailboxAutomaticAliases($context))->$method($context, ['options' => $context->getOptions()]);
                }
            } catch (Throwable $caught) {
                $error = $caught;
            }
            $created = in_array($mode, ['configured', 'flush-fault'], true) ? 2 : ($mode === 'one-existing' ? 1 : 0);
            $expectedGoto = $kind === 'mailbox' ? 'user@example.test' : 'source@example.test';
            $names = $created === 2 ? ['postmaster', 'abuse'] : ($created === 1 ? ['abuse'] : []);
            $expected = array_map(static fn (string $name): array => [$name . '@example.test', $expectedGoto], $names);
            $actual = array_map(static function (object $entity): array {
                if (!$entity instanceof \Entities\Alias) {
                    throw new LogicException('Automatic-alias persistence must contain only Alias entities.');
                }
                return [$entity->getAddress(), $entity->getGoto()];
            }, $delegate->persisted);
            postflushReturnCheck($label . ': exact persisted identities', $actual === $expected);
            postflushReturnCheck($label . ': counter and feedback preserved', $context->getDomain()->getAliasCount() === $created && count($flash->peek()) === $created);
            postflushReturnCheck($label . ': flush count preserved', $delegate->flushes === ($created > 0 && $sentinel === null ? 1 : 0));
            if ($sentinel !== null) {
                postflushReturnCheck($label . ': same flush exception propagates', $error === $sentinel);
            } else {
                postflushReturnCheck($label . ': true success without adapter error', $error === null && $result === true);
            }
        }
    }
}
echo $failures === 0 ? 'ALL PASSED (' . $checks . " checks)\n" : 'FAILED (' . $failures . "/" . $checks . ")\n";
exit($failures === 0 ? 0 : 1);
