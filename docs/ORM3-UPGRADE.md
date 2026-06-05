# Doctrine ORM 3 upgrade

> **Status: deferred, design only.** ViMbAdmin runs on Doctrine ORM 2.20, which
> is maintained and fine. There is no urgency (no CVE, no EOL). This documents
> the bounded migration so it can be done cleanly when desired. Dependabot is
> configured to ignore `doctrine/orm` major bumps until then.

## Why defer

- ORM 2.20 is the current 2.x line — maintained and secure. ZF1 is the EOL
  liability, not Doctrine.
- The upgrade is small and well-scoped (one bootstrap file + the cache wrapper),
  so there is no benefit to rushing it.

## Why it's clean when we do it

The ORM2-only APIs are concentrated in **one file**,
`library/OSS/Resource/Doctrine2.php`, plus the cache helper
`library/OSS/Resource/Doctrine2cache.php`. Mappings are **XML**
(`Doctrine\ORM\Mapping\Driver\XmlDriver`), which ORM 3 keeps — so there is **no
annotation-driver migration** (the usual ORM2→3 headache does not apply here).
We are already on `symfony/cache` 8, whose PSR-6 pool ORM 3 consumes directly.

## What ORM 3 removes (and the replacement)

All in `library/OSS/Resource/Doctrine2.php` unless noted:

| Current (ORM 2) | Line | ORM 3 replacement |
|---|---|---|
| `EntityManager::create($opts, $config)` | 121 | `new EntityManager(DriverManager::getConnection($opts, $config), $config)` |
| `$config->setMetadataCacheImpl($cache)` | 104 | `$config->setMetadataCache($psr6Pool)` |
| `$config->setQueryCacheImpl($cache)` | 111 | `$config->setQueryCache($psr6Pool)` |
| `$config->setResultCacheImpl($cache)` | 112 | `$config->setResultCache($psr6Pool)` |
| `new \Doctrine\ORM\Mapping\Driver\XmlDriver([$path])` | 106 | same class, check ctor signature (file-extension arg) for the installed 3.x |
| `new \Doctrine\Common\ClassLoader(...)` for models | 124 | composer PSR-4 / `spl_autoload_register` (Common\ClassLoader is gone) |
| `new \Doctrine\Common\ClassLoader(...)` for repositories | 128 | same — replace with an spl autoloader |

In `library/OSS/Resource/Doctrine2cache.php`:

| Current | Line | ORM 3 |
|---|---|---|
| `DoctrineProvider::wrap($pool)` returns a legacy cache | 127 | **Drop the wrapper.** ORM 3 takes the PSR-6 `$pool` directly via `setMetadataCache()` etc. Return the pool itself; update callers that used the legacy `->fetch()/->save()` API to PSR-6 (`getItem()/save()`), or keep a thin shim only where the legacy API is still used. |

The APCu / Redis / Array adapter selection stays as-is; only the wrapping changes.

## Steps

Each step is its own PR with green CI; the app keeps working.

1. **Bootstrap rewrite** (`Doctrine2.php`): replace `EntityManager::create` with
   `new EntityManager` + `DriverManager::getConnection`; switch the three
   `set*CacheImpl` calls to `set*Cache` passing the PSR-6 pool; replace the two
   `Common\ClassLoader` model/repository autoloaders with PSR-4 / `spl_autoload`;
   verify the `XmlDriver` constructor against the installed 3.x.
2. **Cache helper** (`Doctrine2cache.php`): stop wrapping with
   `DoctrineProvider::wrap`; expose the PSR-6 pool directly; migrate any remaining
   legacy `->fetch()/->save()` callers to PSR-6.
3. **Composer**: bump `doctrine/orm` `^2.20` → `^3`; remove the
   `doctrine/orm` major-version `ignore` entry from `.github/dependabot.yml`.
4. **CI**: in the regression `cache-wiring` job, flip the assertion from
   "ORM stays 2.x" to "ORM is 3.x", and update the cache-bootstrap smoke test to
   the ORM 3 cache API.
5. **Schema check**: run the existing schema-drift test — it builds the schema
   from the XML mappings and asserts no pending diff; confirms the mappings are
   still valid under ORM 3.
6. **Verify on a real container** before deploying, then rebuild + deploy the
   image.

## Pre-req check before starting

- Confirm `robthree/twofactorauth`, `bacon/bacon-qr-code`, and any other Doctrine
  consumers are compatible with ORM 3 (none currently pin ORM 2, but re-check at
  upgrade time).
- Confirm the installed ORM 3.x `XmlDriver` constructor signature.
