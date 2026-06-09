<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Doctrine;

/**
 * Framework-free factory for the Doctrine entity manager (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * The native Container has, until now, reused the EM the ZF1 `doctrine2`
 * resource built. To stand the kernel up without the ZF1 application, the
 * native side must build the same EM itself, from the same options array (now
 * produced framework-free by {@see \ViMbAdmin\Kernel\Config\IniConfig}). This
 * class is a verbatim, framework-free port of the two ZF1 resource plugins —
 * `OSS_Resource_Doctrine2` and `OSS_Resource_Doctrine2cache` — minus the
 * registry/logger side effects that only made sense inside the framework:
 *
 *   - {@see self::create()} mirrors `OSS_Resource_Doctrine2::getDoctrine2()`:
 *     a `Configuration` wired with the cache, the attribute metadata driver over
 *     `application/Entities` (mapping lives in #[ORM\...] attributes), the proxy
 *     dir/namespace/autogen flag, then (ORM 3.x) a DBAL connection from
 *     `DriverManager::getConnection()` and `new EntityManager(...)`.
 *   - {@see self::buildCache()} mirrors `OSS_Resource_Doctrine2cache`: a PSR-6
 *     pool (Apcu / Redis / per-request Array) handed straight to the ORM 3.x
 *     cache setters, degrading to the Array pool when an extension/server is
 *     unavailable.
 *   - {@see self::registerEntityAutoloaders()} replaces the Doctrine
 *     `ClassLoader`s the resource pushed onto the ZF1 autoloader, since the
 *     `Entities`/`Repositories` namespaces are not in Composer's map. Proxy
 *     classes are left to Doctrine's own `ProxyFactory` (the resource's
 *     `Doctrine\ORM\Proxy\Autoloader` was removed in ORM 2.20).
 *
 * The EM is connection-lazy: `create()` does not touch the database until the
 * first query, so this is unit-testable host-side without a server. Full
 * runtime validation (a real query against the dev MariaDB) happens when the
 * native bootstrap wires it in a later slice.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class EntityManagerFactory
{
    /**
     * Build the Doctrine entity manager from the merged application options
     * (`$options['resources']['doctrine2']` + `['doctrine2cache']`).
     *
     * Returned as a bare `object` so the kernel tree never names the Doctrine
     * classes (the same purity rule the Container follows); callers use it via
     * its public API.
     *
     * @param array<string,mixed> $options the full options array
     */
    /**
     * Fill in the standard doctrine2 path/namespace layout so application.ini
     * need not spell it out. All paths derive from APPLICATION_PATH and the
     * namespaces are fixed (Entities / Proxies / Repositories). Any explicitly
     * configured key still wins.
     *
     * @param array<string,mixed> $dconfig the `resources.doctrine2` sub-array
     * @return array<string,mixed>
     */
    private static function withLayoutDefaults(array $dconfig): array
    {
        $app = defined('APPLICATION_PATH') ? APPLICATION_PATH : '.';

        return $dconfig + [
            'models_path'            => $app,
            'proxies_path'           => $app . '/Proxies',
            'repositories_path'      => $app,
            'models_namespace'       => 'Entities',
            'proxies_namespace'      => 'Proxies',
            'repositories_namespace' => 'Repositories',
            'autogen_proxies'        => 0,
        ];
    }

    public static function create(array $options): object
    {
        $dconfig = self::withLayoutDefaults($options['resources']['doctrine2'] ?? []);
        $cache   = self::buildCache($options['resources']['doctrine2cache'] ?? []);

        self::registerLegacyTypes();

        $config = new \Doctrine\ORM\Configuration();
        // ORM 3.x on PHP 8.4: use native lazy objects for proxies. The old
        // Symfony var-exporter "LazyGhost" route was removed in Symfony 8, so on
        // this stack native lazy objects are the only working proxy backend.
        $config->enableNativeLazyObjects(true);
        $config->setMetadataCache($cache);

        // Mapping now lives in #[ORM\...] attributes on the entity classes
        // (was XML under doctrine2/xml). Scan the Entities directory.
        $driver = new \Doctrine\ORM\Mapping\Driver\AttributeDriver(
            [rtrim((string) $dconfig['models_path'], '/\\') . '/Entities']
        );
        $config->setMetadataDriverImpl($driver);

        $config->setQueryCache($cache);
        $config->setResultCache($cache);
        $config->setProxyDir((string) $dconfig['proxies_path']);
        $config->setProxyNamespace((string) $dconfig['proxies_namespace']);
        $config->setAutoGenerateProxyClasses((int) ($dconfig['autogen_proxies'] ?? 0));

        // ORM 3.x dropped EntityManager::create(): build the DBAL connection
        // explicitly, then hand it to the constructor. Connection stays lazy —
        // no socket opens until the first query.
        $connection = \Doctrine\DBAL\DriverManager::getConnection(
            $dconfig['connection']['options'],
            $config
        );

        return new \Doctrine\ORM\EntityManager($connection, $config);
    }

    /**
     * Register PSR-0 autoloaders for the `Entities` and `Repositories`
     * namespaces, which live under `models_path` / `repositories_path`
     * (`APPLICATION_PATH`) and are NOT in Composer's autoload map — the ZF1
     * `doctrine2` resource pushed Doctrine `ClassLoader`s for them onto the ZF1
     * autoloader. Idempotent enough to call once at native bootstrap.
     *
     * @param array<string,mixed> $options the full options array
     */
    public static function registerEntityAutoloaders(array $options): void
    {
        $dconfig = self::withLayoutDefaults($options['resources']['doctrine2'] ?? []);

        $map = [
            (string) $dconfig['models_namespace']       => (string) $dconfig['models_path'],
            (string) $dconfig['repositories_namespace'] => (string) $dconfig['repositories_path'],
        ];

        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $base) {
                if ($class === $prefix || str_starts_with($class, $prefix . '\\')) {
                    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
                    $path     = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $relative;
                    if (is_file($path)) {
                        require $path;
                    }
                    return;
                }
            }
        });
    }

    /**
     * Register custom DBAL types ViMbAdmin's mappings rely on but DBAL 4
     * dropped. Currently just the legacy `object` type (see
     * {@see \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType}); idempotent so
     * it is safe to call on every EM build.
     */
    private static function registerLegacyTypes(): void
    {
        if (! \Doctrine\DBAL\Types\Type::hasType(\ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME)) {
            \Doctrine\DBAL\Types\Type::addType(
                \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME,
                \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::class
            );
        }
    }

    /**
     * Build the Doctrine cache (a PSR-6 pool wrapped by `DoctrineProvider`),
     * mirroring `OSS_Resource_Doctrine2cache`. APCu/Redis are attempted inside a
     * try/catch and degrade to the per-request Array pool when the extension is
     * missing or the server is unreachable, exactly as the ZF1 resource did
     * (minus its registry/logger writes).
     *
     * @param array<string,mixed> $cfg the `doctrine2cache` options
     */
    private static function buildCache(array $cfg): object
    {
        $namespace = isset($cfg['namespace']) ? (string) $cfg['namespace'] : '';
        $pool      = null;

        try {
            switch ($cfg['type'] ?? 'auto') {
                case 'auto':
                    // Default: prefer APCu (a persistent, cross-request metadata/
                    // query cache) whenever the extension is loaded and enabled
                    // for this SAPI; otherwise fall through to the per-request
                    // Array pool below. This gives a from-source install the same
                    // cached-metadata speed the Docker image gets, with no config
                    // and no hard dependency (graceful degrade).
                    if (
                        extension_loaded('apcu')
                        && (PHP_SAPI === 'cli' ? (bool) ini_get('apc.enable_cli') : apcu_enabled())
                    ) {
                        $pool = new \Symfony\Component\Cache\Adapter\ApcuAdapter($namespace);
                    }
                    break;

                case 'ApcCache':
                case 'ApcuCache':
                    $pool = new \Symfony\Component\Cache\Adapter\ApcuAdapter($namespace);
                    break;

                case 'RedisCache':
                case 'PredisCache':
                    $dsn    = isset($cfg['redis']['dsn']) ? (string) $cfg['redis']['dsn'] : 'redis://127.0.0.1:6379';
                    $client = \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection($dsn);
                    $pool   = new \Symfony\Component\Cache\Adapter\RedisAdapter($client, $namespace);
                    break;

                // 'ArrayCache' and anything unrecognised -> per-request cache.
            }
        } catch (\Throwable) {
            // Extension missing / server unreachable: degrade, don't die.
            $pool = null;
        }

        if ($pool === null) {
            $pool = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        }

        // ORM 3.x consumes PSR-6 pools directly; the old doctrine/cache
        // DoctrineProvider wrapper was removed with that package.
        return $pool;
    }
}
