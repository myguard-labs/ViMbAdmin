<?php

/**
 * Subprocess probe for tests/test-captcha-session-bounds.php.
 *
 * ViMbAdmin_BruteForce::assertNotLocked() answers a locked source with HTTP 429
 * and then `exit`s, which cannot be observed from inside the test process. This
 * script drives lostPasswordAction() once against an already-locked state
 * directory and is expected to print the 429 body and terminate; if the gate is
 * missing it falls through and prints NOT-REFUSED instead.
 *
 * The caller passes the BASE brute-force state directory (the one the login
 * budget uses; the controller appends its own lost-password subdirectory) and a
 * writable temp root in the CAPTCHA_BOUNDS_PROBE_STATEDIR and
 * CAPTCHA_BOUNDS_PROBE_ROOT environment variables, along with the source address
 * in CAPTCHA_BOUNDS_PROBE_REMOTE_ADDR and the lost-password ceiling in
 * CAPTCHA_BOUNDS_PROBE_MAX_ATTEMPTS.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
foreach (glob(__DIR__ . '/../../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once __DIR__ . '/../../application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class CaptchaProbeSession implements SessionStorage
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
final class CaptchaProbeView
{
    public function __set(string $key, mixed $value): void
    {
    }
    public function render(string $script): string
    {
        return $script;
    }
}

final class CaptchaProbeAdminRepository extends \Repositories\Admin
{
    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return null;
    }
    public function getCount(): int
    {
        return 1;
    }
}

final class CaptchaProbeBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EntityManager $entityManager,
        private CaptchaProbeSession $session,
        private CaptchaProbeView $view,
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

function captchaProbeEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, $name . " is not set\n");
        exit(2);
    }

    return $value;
}

$probeRoot = captchaProbeEnv('CAPTCHA_BOUNDS_PROBE_ROOT');
$probeStateDir = captchaProbeEnv('CAPTCHA_BOUNDS_PROBE_STATEDIR');
$probeMaxAttempts = captchaProbeEnv('CAPTCHA_BOUNDS_PROBE_MAX_ATTEMPTS');
$_SERVER['REMOTE_ADDR'] = captchaProbeEnv('CAPTCHA_BOUNDS_PROBE_REMOTE_ADDR');

$_SESSION = [];
OSS_Runtime::configure(
    ['temporary_directory' => $probeRoot],
    '',
    new stdClass(),
);

$configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
    __DIR__ . '/../../application/Entities',
]);
$configuration->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'serverVersion' => '8.0',
], $configuration);
$entityManager = new EntityManager($connection, $configuration);
$entityManager->getClassMetadata(\Entities\Admin::class)
    ->setCustomRepositoryClass('\\' . CaptchaProbeAdminRepository::class);

$session = new CaptchaProbeSession(['csrfToken' => 'csrf-sentinel']);
$options = [
    'securitysalt' => str_repeat('s', 64),
    'identity' => ['sitename' => 'ViMbAdmin', 'mailer' => ['email' => 'do-not-reply@localhost', 'name' => 'ViMbAdmin']],
    'resources' => ['auth' => ['oss' => [
        'pwhash' => 'crypt:sha512',
        'lost_password' => ['use_captcha' => '1'],
    ]]],
    'bruteforce' => [
        'enabled' => '1',
        // Deliberately generous, so the probe can only be refused by the
        // lost-password budget it is meant to exercise -- never by login's.
        'max_attempts' => '1000',
        'lost_password_max_attempts' => $probeMaxAttempts,
        'statedir' => $probeStateDir,
    ],
];

$container = new Container(
    new CaptchaProbeBootstrap($entityManager, $session, new CaptchaProbeView(), $options),
    new Auth($session, static fn (int $id): ?\Entities\Admin => null),
);
$controller = new AuthController(
    $container,
    new RouteMatch('auth', 'lost-password', AuthController::class, 'lostPasswordAction', []),
);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$controller->lostPasswordAction();

echo "NOT-REFUSED\n";
