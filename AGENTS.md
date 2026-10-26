# Project instructions

Follow `/opt/myguard/AGENTS.md` when working in the MyGuard workspace.

## PHP validation

Tested PHP minors: 8.3, 8.4, 8.5, 8.6. Use the shared runner for local PHP
validation of every behavior change:

```bash
/opt/myguard/tools/php-test-matrix --project vimbadmin \
  --cwd "$checkout" -- bash tests/run-unit-tests.sh
```

Set `checkout` to the active linked worktree. Provision locked dependencies
and CI fixtures first; run invasive suites in disposable Docker/Incus.
The project default selects the complete tested matrix. Do not narrow it
for acceptance or skip missing interpreters. Use jobs=1 unless writable
artifacts and services are independent. Run additional dedicated CI suites,
syntax, coverage, static analysis and review gates required by the workflows;
the command above covers only its named suite. Preserve full logs and inspect
the runner's summary. Remote CI must cover the same tested minors.

Retain Doctrine ORM 3 and DBAL 4. PHP 8.0 and 8.1 are unsupported.

## Preserve login sessions during CI

CI and local test runs must preserve existing operator and user login sessions.
Use disposable test accounts, private session storage and fresh browser contexts.
Logout, expiry, password changes and session-invalidation tests may affect only
sessions and accounts created by that test run. Never reuse an operator browser
profile or authenticated live session, clear shared session storage, revoke live
remember-me tokens, or restart a live service to prepare or clean up tests.
Cleanup must target only resources owned by the test run.
