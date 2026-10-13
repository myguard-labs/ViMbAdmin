# Doctrine ORM 3 upgrade

The ORM 3 migration is complete. The shipped PHP 8.2-compatible lock file
retains Doctrine ORM 3.6.8 and DBAL 4.4.4. Generated proxies support PHP 8.2–8.3;
native lazy objects are enabled on PHP 8.4–8.5. The application's legacy
object-type shim keeps its hardened decoder (`allowed_classes=false`).

The application builds the entity manager in
`src/Kernel/Doctrine/EntityManagerFactory.php`. It uses attribute mappings via
Doctrine's `AttributeDriver` and a DBAL connection from `DriverManager`. Direct
PSR-6 pools from `symfony/cache` provide metadata, query, and result caches. A
custom bounded SPL autoloader owns the legacy-layout `Entities` and
`Repositories` namespaces. The removed ZF1 resource and Doctrine cache wrappers
are no longer part of the runtime.

CI validates the locked dependency graph, exercises the locked ORM cache bootstrap,
and runs schema-drift checks against the attribute mappings. `phpstan.neon`
enforces PHPStan level 10; accepted historical diagnostics are isolated in the
generated baseline.
