<?php
/**
 * Regression smoke test: Doctrine ORM cache wiring.
 *
 * Exercises the exact call shape used by OSS_Resource_Doctrine2cache and
 * OSS_Resource_Doctrine2 so a dependency bump that breaks the cache layer
 * (e.g. symfony/cache or doctrine/orm major) fails CI instead of fataling at
 * runtime in production.
 *
 * Covers:
 *   - symfony/cache adapters construct (Array always; Apcu when ext present)
 *   - Doctrine\Common\Cache\Psr6\DoctrineProvider::wrap() accepts the pool
 *   - the wrapped cache does a real save()/fetch() round-trip
 *   - Doctrine\ORM\Configuration accepts the cache via set*CacheImpl()
 *     and EntityManager::create() is callable (ORM 2.x API the app relies on;
 *     this line is exactly what dies under an accidental ORM 3 bump)
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
use Doctrine\Common\Cache\Psr6\DoctrineProvider;

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

check('ArrayAdapter wrap + save/fetch round-trip', function () {
    $c = DoctrineProvider::wrap(new ArrayAdapter());
    $c->save('k', 'v-array', 10);
    if ($c->fetch('k') !== 'v-array') {
        throw new RuntimeException('round-trip value mismatch');
    }
});

if (extension_loaded('apcu') && (PHP_SAPI === 'cli' ? ini_get('apc.enable_cli') : apcu_enabled())) {
    check('ApcuAdapter wrap + save/fetch round-trip', function () {
        $c = DoctrineProvider::wrap(new ApcuAdapter('vmbtest'));
        $c->save('k2', 'v-apcu', 10);
        if ($c->fetch('k2') !== 'v-apcu') {
            throw new RuntimeException('apcu round-trip value mismatch');
        }
    });
} else {
    echo "SKIP ApcuAdapter (apcu ext/cli not enabled)\n";
}

check('ORM Configuration + set*CacheImpl + EntityManager::create callable', function () {
    $c = DoctrineProvider::wrap(new ArrayAdapter());
    $config = new Doctrine\ORM\Configuration();
    $config->setMetadataCacheImpl($c);
    $config->setQueryCacheImpl($c);
    $config->setResultCacheImpl($c);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('Proxies');
    // Do not actually connect; just prove the ORM 2.x static factory exists.
    // (ORM 3 removed EntityManager::create — this is the canary for that bump.)
    if (!method_exists(Doctrine\ORM\EntityManager::class, 'create')) {
        throw new RuntimeException('EntityManager::create() is gone — doctrine/orm 3 detected');
    }
});

echo 'PHP ' . PHP_VERSION . "\n";
exit($failures === 0 ? 0 : 1);
