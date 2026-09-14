<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Doctrine;

use InvalidArgumentException;

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
    /** @return array<string,mixed> */
    private static function stringMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \LogicException("{$name} must be an array");
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new \LogicException("{$name} must use string keys");
            }
        }

        return $value;
    }

    private static function requiredString(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new \LogicException("{$name} must be a non-empty string");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \LogicException("{$name} contains control characters");
        }

        return $value;
    }

    /** @param array<string,mixed> $map */
    private static function optionalString(array $map, string $key, string $name, string $default = ''): string
    {
        if (!array_key_exists($key, $map)) {
            return $default;
        }

        $value = $map[$key];
        if (!is_string($value)) {
            throw new \LogicException("{$name} must be a string");
        }

        return $value;
    }

    private static function proxyModeValue(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-4]$/D', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 0 && $value <= 4) {
            return $value;
        }

        throw new InvalidArgumentException(
            'resources.doctrine2.autogen_proxies must be a Doctrine proxy-generation mode from 0 through 4'
        );
    }

    /** @return array<string,mixed> */
    private static function connectionOptions(mixed $value): array
    {
        $options = self::stringMap($value, 'resources.doctrine2.connection.options');
        if (!array_key_exists('driver', $options)) {
            throw new \LogicException('resources.doctrine2.connection.options requires a driver');
        }
        $driver = self::requiredString($options['driver'], 'resources.doctrine2.connection.options.driver');
        $driver = match ($driver) {
            'ibm_db2' => 'ibm_db2', 'mysqli' => 'mysqli', 'oci8' => 'oci8',
            'pdo_mysql' => 'pdo_mysql', 'pdo_oci' => 'pdo_oci', 'pdo_pgsql' => 'pdo_pgsql',
            'pdo_sqlite' => 'pdo_sqlite', 'pdo_sqlsrv' => 'pdo_sqlsrv', 'pgsql' => 'pgsql',
            'sqlite3' => 'sqlite3', 'sqlsrv' => 'sqlsrv',
            default => throw new \LogicException('resources.doctrine2.connection.options.driver is unsupported'),
        };
        foreach (['dbname', 'user', 'password', 'host', 'charset', 'unix_socket', 'application_name'] as $key) {
            if (array_key_exists($key, $options) && !is_string($options[$key])) {
                throw new \LogicException("resources.doctrine2.connection.options.{$key} must be a string");
            }
        }
        if (array_key_exists('driverClass', $options) && !is_string($options['driverClass'])) {
            throw new \LogicException('resources.doctrine2.connection.options.driverClass must be a string');
        }
        if (array_key_exists('driverClass', $options)) {
            $driverClass = $options['driverClass'];
            if (!class_exists($driverClass) || !is_a($driverClass, \Doctrine\DBAL\Driver::class, true)) {
                throw new \LogicException('resources.doctrine2.connection.options.driverClass must implement Doctrine\\DBAL\\Driver');
            }
        }
        if (array_key_exists('port', $options)) {
            $port = $options['port'];
            if (is_string($port) && preg_match('/^[1-9][0-9]*$/D', $port) === 1) {
                $port = filter_var($port, FILTER_VALIDATE_INT);
            }
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new \LogicException('resources.doctrine2.connection.options.port must be an integer from 1 through 65535');
            }
            $options['port'] = $port;
        }
        if (array_key_exists('driverOptions', $options) && !is_array($options['driverOptions'])) {
            throw new \LogicException('resources.doctrine2.connection.options.driverOptions must be an array');
        }
        if (array_key_exists('memory', $options) && !is_bool($options['memory'])) {
            throw new \LogicException('resources.doctrine2.connection.options.memory must be boolean');
        }

        $options['driver'] = $driver;
        return $options;
    }

    /**
     * @phpstan-assert array{driver:'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv'} $options
     * @param array<string,mixed> $options
     */
    private static function assertConnectionShape(array $options): void
    {
        // connectionOptions() performs the runtime checks; this declaration
        // carries the validated DBAL parameter contract to the call site.
    }

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
        $dconfig = self::stringMap($dconfig, 'resources.doctrine2');
        $app = defined('APPLICATION_PATH') ? APPLICATION_PATH : '.';
        $config = $dconfig + [
            'models_path'            => $app,
            'proxies_path'           => $app . '/Proxies',
            'repositories_path'      => $app,
            'models_namespace'       => 'Entities',
            'proxies_namespace'      => 'Proxies',
            'repositories_namespace' => 'Repositories',
            'autogen_proxies'        => 0,
        ];
        foreach (['models_path', 'proxies_path', 'repositories_path'] as $key) {
            $config[$key] = self::requiredString($config[$key], "resources.doctrine2.{$key}");
        }
        foreach (['models_namespace', 'proxies_namespace', 'repositories_namespace'] as $key) {
            $config[$key] = self::requiredString($config[$key], "resources.doctrine2.{$key}");
            if (preg_match('/[^A-Za-z0-9_\\\\]/', $config[$key]) === 1) {
                throw new \LogicException("resources.doctrine2.{$key} contains an invalid namespace");
            }
        }
        $config['autogen_proxies'] = self::proxyModeValue($config['autogen_proxies']);

        return $config;
    }

    /**
     * @param array<string,mixed> $options the full options array
     */
    public static function create(array $options): object
    {
        $resources = self::stringMap($options['resources'] ?? [], 'resources');
        $dconfig = self::withLayoutDefaults(self::stringMap($resources['doctrine2'] ?? [], 'resources.doctrine2'));
        $cache   = self::buildCache(self::stringMap($resources['doctrine2cache'] ?? [], 'resources.doctrine2cache'));
        $connection = self::stringMap($dconfig['connection'] ?? null, 'resources.doctrine2.connection');
        $connectionOptions = self::connectionOptions($connection['options'] ?? null);
        self::assertConnectionShape($connectionOptions);

        self::registerLegacyTypes();

        $config = new \Doctrine\ORM\Configuration();
        // ORM 3.x on PHP 8.4: use native lazy objects for proxies. The old
        // Symfony var-exporter "LazyGhost" route was removed in Symfony 8, so on
        // this stack native lazy objects are the only working proxy backend.
        $config->enableNativeLazyObjects(true);
        $config->setMetadataCache($cache);

        // Mapping now lives in #[ORM\...] attributes on the entity classes
        // (was XML under doctrine2/xml). Scan the Entities directory.
        $modelsPath = self::requiredString($dconfig['models_path'], 'resources.doctrine2.models_path');
        $proxiesPath = self::requiredString($dconfig['proxies_path'], 'resources.doctrine2.proxies_path');
        $proxiesNamespace = self::requiredString($dconfig['proxies_namespace'], 'resources.doctrine2.proxies_namespace');
        $driver = new \Doctrine\ORM\Mapping\Driver\AttributeDriver(
            [rtrim($modelsPath, '/\\') . '/Entities']
        );
        $config->setMetadataDriverImpl($driver);

        $config->setQueryCache($cache);
        $config->setResultCache($cache);
        $config->setProxyDir($proxiesPath);
        $config->setProxyNamespace($proxiesNamespace);
        $config->setAutoGenerateProxyClasses(
            self::autoGenerateProxyMode($dconfig['autogen_proxies'])
        );

        // ORM 3.x dropped EntityManager::create(): build the DBAL connection
        // explicitly, then hand it to the constructor. Connection stays lazy —
        // no socket opens until the first query.
        $connection = \Doctrine\DBAL\DriverManager::getConnection(
            $connectionOptions,
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
        $resources = self::stringMap($options['resources'] ?? [], 'resources');
        $dconfig = self::withLayoutDefaults(self::stringMap($resources['doctrine2'] ?? [], 'resources.doctrine2'));

        $modelsNamespace = self::requiredString($dconfig['models_namespace'], 'resources.doctrine2.models_namespace');
        $repositoriesNamespace = self::requiredString($dconfig['repositories_namespace'], 'resources.doctrine2.repositories_namespace');
        $modelsPath = self::requiredString($dconfig['models_path'], 'resources.doctrine2.models_path');
        $repositoriesPath = self::requiredString($dconfig['repositories_path'], 'resources.doctrine2.repositories_path');
        $map = [$modelsNamespace => $modelsPath, $repositoriesNamespace => $repositoriesPath];

        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $base) {
                if ($class === $prefix || str_starts_with($class, $prefix . '\\')) {
                    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
                    $path     = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $relative;
                    $basePath = realpath($base);
                    $candidate = $basePath === false ? false : realpath($path);
                    if ($basePath !== false && $candidate !== false
                        && str_starts_with($candidate, rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                        && is_file($candidate)) {
                        require $candidate;
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
     * Build the direct PSR-6 pool used by Doctrine's cache setters.
     * APCu/Redis are attempted inside a try/catch and degrade to the per-request
     * Array pool when the extension is missing or the server is unreachable,
     * exactly as the ZF1 resource did
     * (minus its registry/logger writes).
     *
     * @param array<string,mixed> $cfg the `doctrine2cache` options
     */
    private static function buildCache(array $cfg): \Psr\Cache\CacheItemPoolInterface
    {
        $cfg = self::stringMap($cfg, 'resources.doctrine2cache');
        $namespace = self::optionalString($cfg, 'namespace', 'resources.doctrine2cache.namespace');
        $type = self::optionalString($cfg, 'type', 'resources.doctrine2cache.type', 'auto');
        $redis = self::stringMap($cfg['redis'] ?? [], 'resources.doctrine2cache.redis');
        $dsn = self::optionalString($redis, 'dsn', 'resources.doctrine2cache.redis.dsn', 'redis://127.0.0.1:6379');

        // Version the cache namespace by the running build so a code bump that
        // changes the entity mappings can never serve STALE ClassMetadata.
        //
        // APCu is a persistent, cross-request store (unlike opcache it is not
        // gated by validate_timestamps), so the FPM master keeps the metadata it
        // computed under the *previous* image alive across a redeploy. With a
        // fixed namespace ('ViMbAdmin3'), getUpdateSchemaSql() then diffs that
        // stale mapping against the live DB and emits phantom ALTERs forever —
        // the Maintenance tab shows "N pending statement(s)" that the CLI
        // auto-migrator (apc.enable_cli=0 -> fresh ArrayAdapter -> 0 pending)
        // can never clear. Suffixing the namespace with the build identity makes
        // a new build land on a fresh APCu key, so the metadata is recomputed
        // from the current mappings automatically. DBVERSION moves on every
        // schema change; the git commit moves on every build (belt and braces).
        if (class_exists(\ViMbAdmin_Version::class)) {
            $ver = (defined('ViMbAdmin_Version::DBVERSION') ? (string) \ViMbAdmin_Version::DBVERSION : '0')
                 . '.' . ((\ViMbAdmin_Version::gitCommitShort() ?? \ViMbAdmin_Version::VERSION));
            $namespace .= '_' . preg_replace('/[^A-Za-z0-9._]/', '', $ver);
        }

        $pool      = null;

        try {
            switch ($type) {
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

    /**
     * Normalize the scalar values produced by the INI loader to one of
     * Doctrine's documented proxy-generation modes.
     *
     * @return 0|1|2|3|4
     */
    /** @return 0|1|2|3|4 */
    private static function autoGenerateProxyMode(mixed $value): int
    {
        return match (self::proxyModeValue($value)) {
            0 => 0,
            1 => 1,
            2 => 2,
            3 => 3,
            4 => 4,
            default => throw new InvalidArgumentException('Invalid Doctrine proxy-generation mode'),
        };
    }
}
