# Doctrine ORM 3 upgrade

The ORM 3 migration is complete. The shipped lock file currently pins Doctrine
ORM 3.6.8 and DBAL 4.4.4.

The application builds the entity manager in
`src/Kernel/Doctrine/EntityManagerFactory.php`. It uses attribute mappings
through
Doctrine's `AttributeDriver`, a DBAL connection from `DriverManager`, and direct
PSR-6 pools from `symfony/cache` for metadata, query, and result caches. A
custom
bounded SPL autoloader owns the legacy-layout `Entities` and `Repositories`
namespaces; the removed ZF1 resource and Doctrine cache wrappers are no longer
part of the runtime.

CI validates the locked dependency graph, exercises the ORM 3 cache bootstrap,
and runs schema-drift checks against the attribute mappings. PHPStan is
enforced at
level 10 through `phpstan.neon`; accepted historical diagnostics are isolated in
the generated baseline.
