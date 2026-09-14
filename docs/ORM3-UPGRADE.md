# Doctrine ORM 3 upgrade

The ORM 3 migration is complete. The shipped lock file currently pins Doctrine
ORM 3.6.8 and DBAL 4.4.4.

The application builds the entity manager in
`src/Kernel/Doctrine/EntityManagerFactory.php`. It uses attribute mappings via
Doctrine's `AttributeDriver` and a DBAL connection from `DriverManager`. Direct
PSR-6 pools from `symfony/cache` provide metadata, query, and result caches. A
custom bounded SPL autoloader owns the legacy-layout `Entities` and
`Repositories` namespaces. The removed ZF1 resource and Doctrine cache wrappers
are no longer part of the runtime.

CI validates the locked dependency graph, exercises the ORM 3 cache bootstrap,
and runs schema-drift checks against the attribute mappings. `phpstan.neon`
enforces PHPStan level 10; accepted historical diagnostics are isolated in the
generated baseline.
