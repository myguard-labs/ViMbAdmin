<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\MaintenanceController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class MaintenanceTestSession implements SessionStorage
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

final class MaintenanceTestResources
{
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
    public function getResource(string $name): object
    {
        return new stdClass();
    }
}

/** @param (callable(int): ?object) $loader */
function maintenanceController(MaintenanceTestSession $session, callable $loader): MaintenanceController
{
    $container = new Container(new MaintenanceTestResources(), new Auth($session, $loader));
    $route = new RouteMatch('maintenance', 'index', MaintenanceController::class, 'indexAction', []);

    return new MaintenanceController($container, $route);
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== native maintenance controller boundaries ==\n";

$anonymous = maintenanceController(new MaintenanceTestSession(), static fn (int $id): ?object => null);
$anonymousResponse = $anonymous->indexAction();
$check(
    'anonymous users are redirected to login',
    $anonymousResponse->status === 302
    && str_ends_with($anonymousResponse->headers['Location'] ?? '', '/auth/login')
);

$admin = new \Entities\Admin();
$admin->setUsername('operator@example.test');
$admin->setSuper(false);
$admin->setActive(true);
$normal = maintenanceController(
    new MaintenanceTestSession(['identity' => ['id' => 7]]),
    static fn (int $id): object => $admin,
);
$normalResponse = $normal->indexAction();
$check(
    'non-super admins are redirected to login',
    $normalResponse->status === 302
    && str_ends_with($normalResponse->headers['Location'] ?? '', '/auth/login')
);

$admin->setSuper(true);
$super = maintenanceController(
    new MaintenanceTestSession(['identity' => ['id' => 7]]),
    static fn (int $id): object => $admin,
);
$typeGuarded = false;
try {
    $super->indexAction();
} catch (LogicException $e) {
    $typeGuarded = $e->getMessage() === 'Doctrine entity manager resource has an invalid type';
}
$check('super-admin dashboard fails closed on an invalid entity manager resource', $typeGuarded);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all maintenance controller assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
