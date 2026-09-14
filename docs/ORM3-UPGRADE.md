# Doctrine ORM 3 upgrade

The ORM 3 migration is complete. The shipped lock file currently pins Doctrine
ORM 3.6.8 and DBAL 4.4.4.

The application builds the entity manager in
`src/Kernel/Doctrine/EntityManagerFactory.php`. It uses XML mappings through
Doctrine's `XmlDriver`, a DBAL connection from `DriverManager`, and PSR-6 pools
from `symfony/cache` for metadata, query, and result caches. Composer autoloading
owns entity and repository class loading; the removed ZF1 resource and legacy
Doctrine cache wrappers are no longer part of the runtime.

CI validates the locked dependency graph, exercises the ORM 3 cache bootstrap,
and runs schema-drift checks against the XML mappings. PHPStan is enforced at
level 10 through `phpstan.neon`; accepted historical diagnostics are isolated in
the generated baseline.
