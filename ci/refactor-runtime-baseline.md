# Runtime baseline

`test-refactor-runtime-baseline.php` measures the existing mailbox, domain and
archive HTTP list actions, mailbox/domain MCP dispatchers, and QueueRunner's
orphan backend scan. The MCP method table exposes archive write mutations but
no archive list method; no new adapter is introduced.

Run the owning regression workflow's MariaDB fixture step, or use the project's
`php-test-matrix` runner with a disposable, isolated MariaDB service and these
environment variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and
`VIMBADMIN_RUNTIME_FIXTURE=1`. Credentials belong in the environment only.
`DB_PORT` selects the TCP port; when omitted it defaults to `3306`. An explicit
value must be a decimal integer from `1` through `65535`; an empty or malformed
value fails before connecting. The port applies to fixture creation and samples.
For this fixture, `DB_HOST=localhost` is normalized to `127.0.0.1` so PDO uses
TCP and honors `DB_PORT` in both paths. Other host values are passed through.
`DB_NAME` must start with `vimbadmin_runtime`; the fixture rebuilds every table
in that database. Use the supported PHP 8.3/8.4/8.5 matrix and locked Composer
dependencies. The workflow already provisions its MariaDB service.

```sh
/opt/myguard/tools/php-test-matrix --project vimbadmin --cwd "$checkout" -- \
  php -d apc.enable_cli=1 -d opcache.enable_cli=0 ci/test-refactor-runtime-baseline.php
```

Set `VIMBADMIN_RUNTIME_REPORT_DIR` to a new local evidence directory. Each PHP
minor writes detailed reports there; these reports and raw SQL stay outside the
repository. `refactor-runtime-baseline.json` is the compact source-bound receipt.
It records fixture identities and aggregate measurement ranges; the detailed
per-size paired samples remain in the recorded local reports.

Each dataset has 1/50/500/1000 authorized domains, mailboxes and archives, plus
one unowned domain. Cross-domain cases temporarily populate that tenant with
mailbox/archive canaries. Normal backend enumeration has at most 500 entries,
independently of database cardinality; a separate 501-entry case must reject
before resource SQL. Fixed dates, token metadata and archive generations make
the fixture digest reproducible across PHP minors.

Each of seven trials uses a fresh PHP process and ORM/result cache. Its warm
operation reuses the same EntityManager, Auth and result cache as its cold
operation. CLI opcache is disabled. MariaDB's buffer pool is prewarmed by fixture
creation and retained across trials; this is not a cold database measurement.
The timed interval covers the real action/dispatcher/scan invocation, excluding
process startup and container construction. The Doveadm client retains real
framing/validation, with a deterministic transport fixture. No measurement here
represents network or deployed HTTP latency.

RSS is Linux process-lifetime `ru_maxrss`, including bootstrap and the previous
cold result, rather than an operation allocation delta. Reported p95 is the
nearest-rank maximum of seven samples. Shared-host spread is measured and can
be large; this baseline alone makes no optimization or speedup claim.

The operation-local DBAL logger is shared with the existing query and workflow
observers. SQL execution, ORM hydration, permission checks and backend parsing
remain production computations. The fixture adds adapter invocation and paired
measurement orchestration, rather than another runtime observer. Real revoked
identities, token scopes, populated foreign tenants, malformed requests,
missing tables and backend faults exercise rejection paths. Counts, bounds,
pagination and output hashes are checked independently of timing.

Named temporary source controls add a real query or disable each adapter's
fetch/enumeration bound. They must fail the owning test and return green after
exact source restoration. Detailed mutation receipts are local evidence; no
mutant or production change is shipped by this baseline.
