<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/tests/support/native-workflow-harness.php';
foreach (glob(dirname(__DIR__) . '/application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once dirname(__DIR__) . '/application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\Mail\Mailer;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ResetCeilingState
{
    public static ?Entities\Admin $admin = null;
    public static int $lookups = 0;
}
final class ResetCeilingRepository extends Repositories\Admin
{
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        ResetCeilingState::$lookups++;
        return ResetCeilingState::$admin;
    }
}
final class ResetCeilingRepositoryFactory implements RepositoryFactory
{
    public function getRepository(EntityManagerInterface $manager, string $entityName): EntityRepository
    {
        if (ltrim($entityName, '\\') !== Entities\Admin::class) {
            throw new LogicException('Unexpected reset repository ' . $entityName);
        }
        return new ResetCeilingRepository($manager, new ClassMetadata(Entities\Admin::class));
    }
}
final class ResetCeilingManager extends EntityManager
{
    public int $persists = 0;
    public int $flushes = 0;
    public function __construct()
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/application/Entities']);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        } else {
            $configuration->setProxyDir(sys_get_temp_dir());
            $configuration->setProxyNamespace('ResetCeilingProxies');
            $configuration->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        }
        $configuration->setRepositoryFactory(new ResetCeilingRepositoryFactory());
        parent::__construct(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        $this->getConnection()->executeStatement('CREATE TABLE admin_pref (id INTEGER PRIMARY KEY, Admin_id INTEGER, attribute TEXT, ix INTEGER, op TEXT, value TEXT, expire INTEGER)');
    }
    public function persist(object $object): void
    {
        $this->persists++;
    }
    public function flush(): void
    {
        $this->flushes++;
    }
}
final class ResetCeilingSession implements SessionStorage
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
    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
#[AllowDynamicProperties]
final class ResetCeilingView
{
    /** @var array<string,mixed> */
    public array $values = [];
    /** @var list<string> */
    public array $renders = [];
    public function __set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
    public function render(string $script): string
    {
        $this->renders[] = $script;
        return $script;
    }
}
final class ResetCeilingBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(private ResetCeilingManager $manager, private ResetCeilingSession $session, private ResetCeilingView $view, private array $options)
    {
    }
    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->manager, 'namespace' => $this->session, 'smarty' => $this->view,
            default => throw new LogicException('Unexpected resource ' . $name),
        };
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

/** @return array{response:ViMbAdmin\Kernel\Http\Response,session:ResetCeilingSession,view:ResetCeilingView,manager:ResetCeilingManager,transport:WorkflowTransport,lookups:int} */
function resetCeilingRun(?int $tokenCount, bool $validCsrf = true): array
{
    $manager = new ResetCeilingManager();
    $admin = $tokenCount === null ? null : (new Entities\Admin())->setUsername('admin@example.test');
    if ($admin !== null) {
        (new ReflectionMethod($admin, 'assignGeneratedId'))->invoke($admin, 1);
        $preferences = (new ReflectionProperty($admin, 'Preferences'))->getValue($admin);
        for ($index = 0; $index < $tokenCount; $index++) {
            $preference = (new Entities\AdminPreference())->setAdmin($admin)
                ->setAttribute('tokens.password_reset')->setIx($index)->setOp('=')
                ->setValue('sha256:' . str_repeat((string) ($index % 10), 64))->setExpire(time() + 3600);
            $preferences->add($preference);
            $manager->getConnection()->insert('admin_pref', [
                'Admin_id' => 1, 'attribute' => 'tokens.password_reset', 'ix' => $index, 'op' => '=',
                'value' => 'sha256:' . str_repeat((string) ($index % 10), 64), 'expire' => time() + 3600,
            ]);
        }
    }
    $manager->persists = 0;
    $manager->flushes = 0;
    ResetCeilingState::$admin = $admin;
    ResetCeilingState::$lookups = 0;
    $session = new ResetCeilingSession(['csrfToken' => 'reset-csrf']);
    $view = new ResetCeilingView();
    $options = [
        'securitysalt' => str_repeat('s', 64),
        'demo' => ['account' => 'mail-disabled@example.test', 'password' => 'public'],
        'identity' => ['sitename' => 'ViMbAdmin', 'mailer' => ['email' => 'noreply@example.test', 'name' => 'ViMbAdmin']],
        'resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512', 'lost_password' => ['use_captcha' => '0']]]],
        'bruteforce' => ['enabled' => '0'],
    ];
    OSS_Runtime::configure($options, '', $manager);
    $container = new Container(new ResetCeilingBootstrap($manager, $session, $view, $options), new Auth($session, static fn (int $id): ?Entities\Admin => null));
    $transport = new WorkflowTransport();
    $mailer = new Mailer([], getenv('VIMBADMIN_RESET_NOOP_MAILER') === '1');
    (new ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);
    (new ReflectionProperty(Container::class, 'mailer'))->setValue($container, $mailer);
    $controller = new AuthController($container, new RouteMatch('auth', 'lost-password', AuthController::class, 'lostPasswordAction', []));
    $savedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $savedPost = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['username' => 'admin@example.test', 'csrf' => $validCsrf ? 'reset-csrf' : 'wrong'];
    try {
        $response = $controller->lostPasswordAction();
    } finally {
        $_POST = $savedPost;
        if ($savedMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $savedMethod;
        }
    }
    return compact('response', 'session', 'view', 'manager', 'transport') + ['lookups' => ResetCeilingState::$lookups];
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$cases = ['missing' => null, 'below' => 4, 'at' => 5, 'above' => 6];
$results = [];
foreach ($cases as $name => $count) {
    $results[$name] = resetCeilingRun($count);
    $response = $results[$name]['response'];
    $flashes = $results[$name]['session']->all()['flashMessages'] ?? [];
    $check("{$name}: real action returns the generic reset redirect", $response->status === 302
        && ($response->headers['Location'] ?? null) === '/auth/reset-password/username/admin%40example.test'
        && is_array($flashes) && count($flashes) === 1
        && ($flashes[0]['text'] ?? null) === 'If your username was correct, then an email with a key to allow you to change your password below has been sent to you.');
}
$check('response parity: missing/below/at/above are indistinguishable', count(array_unique(array_map(
    static fn (array $result): string => serialize([$result['response']->status, $result['response']->headers, $result['session']->all()['flashMessages'] ?? []]),
    $results,
))) === 1);
$check(
    'mail: the fresh existing account sends exactly one reset email',
    count($results['below']['view']->renders) === 2 && count($results['below']['transport']->messages) === 1
);
$check(
    'mail: missing and exhausted accounts send no reset email',
    count($results['missing']['view']->renders) === 0 && count($results['missing']['transport']->messages) === 0
    && count($results['at']['view']->renders) === 0 && count($results['at']['transport']->messages) === 0
    && count($results['above']['view']->renders) === 0 && count($results['above']['transport']->messages) === 0
);
$check(
    'persistence: only the allowed request persists and flushes a token',
    $results['below']['manager']->persists === 1 && $results['below']['manager']->flushes === 1
    && $results['missing']['manager']->persists === 0 && $results['missing']['manager']->flushes === 0
    && $results['at']['manager']->persists === 0 && $results['at']['manager']->flushes === 0
    && $results['above']['manager']->persists === 0 && $results['above']['manager']->flushes === 0
);
$csrf = resetCeilingRun(0, false);
$check(
    'abuse guard: invalid CSRF reaches no account lookup, persistence, or mail',
    $csrf['lookups'] === 0 && $csrf['manager']->persists === 0 && $csrf['manager']->flushes === 0
    && count($csrf['view']->renders) === 1 && count($csrf['transport']->messages) === 0
    && $csrf['response']->status === 200
);
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
