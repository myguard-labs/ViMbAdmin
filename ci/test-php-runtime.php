<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Composer\InstalledVersions;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "OK $label\n";
};
foreach (['doctrine/orm' => '3.', 'doctrine/dbal' => '4.'] as $package => $major) {
    $check(str_starts_with((string) InstalledVersions::getVersion($package), $major), 'locked dependency major: ' . $package);
}
$manager = EntityManagerFactory::create([
    'resources' => [
        'doctrine2' => [
            'models_path' => __DIR__ . '/../application',
            'proxies_path' => sys_get_temp_dir(),
            'connection' => ['options' => ['driver' => 'pdo_sqlite', 'memory' => true]],
        ],
        'doctrine2cache' => ['type' => 'ArrayCache'],
    ],
]);
if (!$manager instanceof Doctrine\ORM\EntityManagerInterface) {
    throw new RuntimeException('factory did not return an ORM entity manager');
}
$check($manager->getConfiguration()->isNativeLazyObjectsEnabled() === (PHP_VERSION_ID >= 80400), 'lazy-object backend matches the PHP runtime');
$check(!$manager->getConnection()->isConnected(), 'bootstrap remains connection-lazy');
$rejected = false;
try {
    EntityManagerFactory::create(['resources' => ['doctrine2' => ['connection' => ['options' => ['driver' => []]]]]]);
} catch (LogicException) {
    $rejected = true;
}
$check($rejected, 'malformed connection configuration is rejected');
echo "ALL PASSED\n";
