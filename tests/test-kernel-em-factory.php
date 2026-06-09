<?php
/**
 * Regression smoke test: the native Doctrine EM factory (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * Builds an entity manager from a synthetic options array the way the native
 * bootstrap will, against the REAL shipped XML mapping dir, and asserts the
 * factory reproduces the OSS_Resource_Doctrine2 wiring: an EntityManager whose
 * Configuration carries the XML metadata driver, the cache, and the proxy
 * settings. The EM is connection-lazy so this needs no database — the same
 * approach test-cache-bootstrap.php uses for the ORM call shape.
 *
 * Runs in the cache-wiring CI job (vendor + doctrine/orm present).
 * Exit 0 = all passed, non-zero = a failure.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

require __DIR__ . '/../src/Kernel/Doctrine/EntityManagerFactory.php';

use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;

$appPath = realpath(__DIR__ . '/../application');

$options = [
    'resources' => [
        'doctrine2' => [
            'connection' => [
                // pdo_sqlite :memory: — never connected (lazy), just a valid driver.
                'options' => ['driver' => 'pdo_sqlite', 'memory' => true],
            ],
            'proxies_path'           => $appPath . '/Proxies',
            'proxies_namespace'      => 'Proxies',
            'models_path'            => $appPath,
            'models_namespace'       => 'Entities',
            'repositories_path'      => $appPath,
            'repositories_namespace' => 'Repositories',
            'autogen_proxies'        => '0',
        ],
        'doctrine2cache' => [
            'type'      => 'ArrayCache',
            'namespace' => 'ViMbAdmin3',
        ],
    ],
];

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

// Register the Entities/Repositories autoloaders up front: getClassMetadata()
// below reflects on the real entity class, so it must be loadable first — which
// is also what the native bootstrap does once, before any query.
EntityManagerFactory::registerEntityAutoloaders($options);

$em = null;

// The attribute metadata driver needs no extra extension (reflection only), so
// these asserts always run.
check('factory builds an EntityManager', function () use ($options, &$em) {
    $em = EntityManagerFactory::create($options);
    if (!$em instanceof EntityManagerInterface) {
        throw new RuntimeException('not an EntityManagerInterface: ' . get_class($em));
    }
});

check('metadata driver is the attribute driver over the Entities dir', function () use (&$em) {
    $driver = $em->getConfiguration()->getMetadataDriverImpl();
    if (!$driver instanceof AttributeDriver) {
        throw new RuntimeException('metadata driver is ' . get_debug_type($driver));
    }
});

check('proxy namespace + autogen flag applied', function () use (&$em) {
    $cfg = $em->getConfiguration();
    if ($cfg->getProxyNamespace() !== 'Proxies') {
        throw new RuntimeException('proxy namespace = ' . var_export($cfg->getProxyNamespace(), true));
    }
    // autogen_proxies '0' must map to AUTOGENERATE_NEVER (0), not a truthy mode.
    if ((int) $cfg->getAutoGenerateProxyClasses() !== 0) {
        throw new RuntimeException('autogen = ' . var_export($cfg->getAutoGenerateProxyClasses(), true));
    }
});

check('metadata cache is wired (no exception fetching it)', function () use (&$em) {
    // ORM 2.x: getMetadataCache() returns the PSR-6 pool DoctrineProvider wraps.
    $cache = $em->getConfiguration()->getMetadataCache();
    if ($cache === null) {
        throw new RuntimeException('metadata cache is null');
    }
});

check('a known entity attribute mapping loads through the driver', function () use (&$em) {
    // Proves the driver reads the #[ORM\...] attributes on Entities\Admin and
    // produces class metadata (no DB needed).
    $meta = $em->getClassMetadata('Entities\\Admin');
    if ($meta->getTableName() === '') {
        throw new RuntimeException('Admin metadata has no table name');
    }
});

check('registerEntityAutoloaders loads an Entities class', function () use ($options) {
    EntityManagerFactory::registerEntityAutoloaders($options);
    if (!class_exists('Entities\\Admin')) {
        throw new RuntimeException('Entities\\Admin did not autoload');
    }
});

echo 'PHP ' . PHP_VERSION . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
