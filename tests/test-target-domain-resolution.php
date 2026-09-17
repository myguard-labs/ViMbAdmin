<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Domain.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Repositories/Domain.php';

use ViMbAdmin\Kernel\Mvc\AbstractController;

final class TargetDomainRepository extends \Repositories\Domain
{
    public function __construct(private readonly ?\Entities\Domain $domain)
    {
    }

    public function find(mixed $id, \Doctrine\DBAL\LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->domain !== null && $this->domain->requiredId() === $id
            ? $this->domain
            : null;
    }
}

final class TargetDomainControllerProbe extends AbstractController
{
    public function resolve(object $admin, ?int $id, \Repositories\Domain $repository): ?\Entities\Domain
    {
        return $this->resolveAuthorizedTargetDomain($admin, $id, $repository);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$persist = static function (object $entity, int $id): void {
    $property = new ReflectionProperty($entity, 'id');
    $property->setValue($entity, $id);
};

/** @var TargetDomainControllerProbe $probe */
$probe = (new ReflectionClass(TargetDomainControllerProbe::class))->newInstanceWithoutConstructor();
$domain = new \Entities\Domain();
$persist($domain, 41);
$repository = new TargetDomainRepository($domain);
$owned = new \Entities\Admin();
$owned->setSuper(false)->addDomain($domain);
$unowned = new \Entities\Admin();
$unowned->setSuper(false);
$super = new \Entities\Admin();
$super->setSuper(true);

$invalidAdminRejected = false;
try {
    $probe->resolve(new stdClass(), 41, $repository);
} catch (LogicException) {
    $invalidAdminRejected = true;
}
$check('invalid administrator types fail closed', $invalidAdminRejected);
$check(
    'missing domains resolve to null',
    $probe->resolve($owned, 99, new TargetDomainRepository(null)) === null
);
$check('unowned domains are denied', $probe->resolve($unowned, 41, $repository) === null);
$check('owned domains are authorized', $probe->resolve($owned, 41, $repository) === $domain);
$check(
    'super administrators authorize any persisted domain',
    $probe->resolve($super, 41, $repository) === $domain
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
