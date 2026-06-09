<?php
/**
 * Regression smoke test: Doctrine ORM 3 cache wiring.
 *
 * Exercises the exact call shape used by
 * {@see \ViMbAdmin\Kernel\Doctrine\EntityManagerFactory} so a dependency bump
 * that breaks the cache/proxy layer (symfony/cache, doctrine/orm,
 * doctrine/dbal) fails CI instead of fataling at runtime in production.
 *
 * Covers (ORM 3.x API):
 *   - symfony/cache adapters construct (Array always; Apcu when ext present)
 *     and do a real PSR-6 save()/get() round-trip
 *   - Doctrine\ORM\Configuration accepts the PSR-6 pool directly via
 *     setMetadataCache()/setQueryCache()/setResultCache() (no more
 *     doctrine/cache DoctrineProvider wrapper — that package was removed)
 *   - native lazy objects can be enabled and a real EntityManager is
 *     constructible (the ORM 3 proxy backend; the old EntityManager::create()
 *     static factory is gone)
 *
 * Exit 0 = all good, non-zero = regression.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\DriverManager;

$failures = 0;
function check(string $label, callable $fn): void {
    global $failures;
    try {
        $fn();
        echo "OK   $label\n";
    } catch (\Throwable $e) {
        $failures++;
        printf("FAIL %s :: %s: %s\n", $label, get_class($e), $e->getMessage());
    }
}

/** PSR-6 round-trip helper (replaces the old Doctrine save/fetch). */
function psr6RoundTrip(\Psr\Cache\CacheItemPoolInterface $pool, string $key, string $val): void {
    $item = $pool->getItem($key);
    $item->set($val);
    $pool->save($item);
    if ($pool->getItem($key)->get() !== $val) {
        throw new RuntimeException('round-trip value mismatch');
    }
}

check('ArrayAdapter PSR-6 save/get round-trip', function () {
    psr6RoundTrip(new ArrayAdapter(), 'k', 'v-array');
});

if (extension_loaded('apcu') && (PHP_SAPI === 'cli' ? ini_get('apc.enable_cli') : apcu_enabled())) {
    check('ApcuAdapter PSR-6 save/get round-trip', function () {
        psr6RoundTrip(new ApcuAdapter('vmbtest'), 'k2', 'v-apcu');
    });
} else {
    echo "SKIP ApcuAdapter (apcu ext/cli not enabled)\n";
}

check('ORM3 Configuration accepts PSR-6 pools + native lazy + EntityManager constructible', function () {
    $pool   = new ArrayAdapter();
    $config = new Configuration();
    $config->enableNativeLazyObjects(true);
    $config->setMetadataCache($pool);
    $config->setQueryCache($pool);
    $config->setResultCache($pool);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('Proxies');
    $config->setMetadataDriverImpl(
        new Doctrine\ORM\Mapping\Driver\AttributeDriver([realpath(__DIR__ . '/../application/Entities')])
    );

    // ORM 3 removed EntityManager::create(); construction now takes a DBAL
    // connection. Pin serverVersion so this never opens a socket.
    $connection = DriverManager::getConnection([
        'driver'        => 'pdo_mysql',
        'host'          => '127.0.0.1',
        'dbname'        => 'unused',
        'user'          => 'unused',
        'password'      => 'unused',
        'serverVersion' => '11.0.0-MariaDB',
    ], $config);

    $em = new EntityManager($connection, $config);
    if (!$em->getConfiguration()->isNativeLazyObjectsEnabled()) {
        throw new RuntimeException('native lazy objects not enabled on the EM');
    }
});

echo 'PHP ' . PHP_VERSION . "\n";
exit($failures === 0 ? 0 : 1);
