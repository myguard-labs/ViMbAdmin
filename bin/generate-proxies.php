#!/usr/bin/env php
<?php
/**
 * Generate the Doctrine ORM proxy classes from the XML mappings.
 *
 * Production runs with autogen_proxies = 0 on a read-only rootfs, so the proxy
 * classes must exist on disk before the app serves a request. Rather than
 * committing the generated __CG__*.php to git (where they silently drift from
 * the entities — see the maildir-size / last_login drift PHPStan caught), the
 * image build runs this script after `composer install` to bake fresh proxies
 * that always match the shipped entities + the pinned Doctrine version.
 *
 * No database is required: the metadata + proxy generation are driver-agnostic,
 * so a connection is configured but never opened (lazy connect).
 *
 * Usage:  php bin/generate-proxies.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Entities\ / Repositories\ live under application/ and are not in Composer's
// PSR map (the app registers them via the Zend/Doctrine loader at runtime).
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $root . '/application/' . $dir . '/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\DriverManager;

$xmlDir   = $root . '/doctrine2/xml';
$proxyDir = $root . '/application/Proxies';

if (!is_dir($xmlDir)) {
    fwrite(STDERR, "XML mapping dir not found: $xmlDir\n");
    exit(1);
}
@mkdir($proxyDir, 0o755, true);

$config = ORMSetup::createXMLMetadataConfiguration([$xmlDir], true);
$config->enableNativeLazyObjects(true); // ORM 3.x on PHP 8.4: native lazy-object proxies
$config->setProxyDir($proxyDir);
$config->setProxyNamespace('Proxies');

// Pin the platform so metadata loading never needs a live connection. The proxy
// generator only reads class metadata; the platform choice does not affect the
// emitted proxy code, it only satisfies the metadata factory.
$connection = DriverManager::getConnection(
    [
        'driver'        => 'pdo_mysql',
        'host'          => '127.0.0.1',
        'dbname'        => 'unused',
        'user'          => 'unused',
        'password'      => 'unused',
        'charset'       => 'utf8',
        'serverVersion' => '10.11.0-MariaDB', // avoids auto-detect (no connect)
    ],
    $config
);

$em       = new EntityManager($connection, $config);
$metadata = $em->getMetadataFactory()->getAllMetadata();

if ($metadata === []) {
    fwrite(STDERR, "no entity metadata discovered — mapping path wrong?\n");
    exit(1);
}

$em->getProxyFactory()->generateProxyClasses($metadata, $proxyDir);

printf("generated %d proxy classes into %s\n", count($metadata), $proxyDir);
