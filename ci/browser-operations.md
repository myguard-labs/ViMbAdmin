# Operations application browser coverage

Run `bash ci/test-browser-operations.sh` from the repository root. It uses the
maintained application fixture, real controllers and templates, locked Composer
dependencies, and a disposable MariaDB database in a bounded private tmpfs.
Chromium, Firefox and WebKit
each run a green candidate, a rollback mutation that must fail, and the restored
candidate. `VIMBADMIN_BROWSER` selects one engine for the CI matrix.

Covered entry points:

- Maintenance dashboard and its ten POST actions: super admin forms;
  anonymous, assigned and unassigned administrator denials; GET and invalid
  CSRF denials; two-step repair/recalc and prune confirmations; inactive-account
  rollback and successful update; schema preview and confirmed index repair;
  cached orphan imports and
  duplicate scans; backend/update-check errors.
- Queue dashboard, cancel, retry, delete, clear, run-now and run-task: super
  admin forms; all three denied roles; GET/CSRF and malformed/missing task IDs;
  terminal transitions, stale cancel replay and dependency failures.
- Queue trigger: missing/bad bearer rejection and an authorized deferred drain,
  with no session authority substituted for the key.
- MCP endpoint and all thirteen abilities: per-handler missing-bearer denial,
  valid reads and mutations, read-only/IP/revoked/expired/unknown token rejection,
  domain scope, malformed JSON, pagination boundary, duplicate creates/archives,
  archive snapshot errors and destructive rate boundary.
- Mailbox repair/archive/delete forms: assigned domain and super administrators
  enqueue one open task; foreign/unassigned requests preserve the database.
- Mailbox settings dialog: a real older application response is held until a
  newer mailbox response renders, then released; the current dialog must retain
  the newer mailbox. No email is sent.

The rollback control installs a private database trigger that rejects the alias
update after the mailbox update has executed. Every protected row digest must
remain unchanged. The deliberately broken application copy commits that first
update instead of rolling it back; the named assertion must fail. PHP opcache
is disabled and each interpreted source artifact's timestamp, size and SHA256
are recorded. The working source is never mutated.

Only fixture accounts, tokens, tasks and archives are used. Doveadm points at
the private verifier's rejecting endpoint, so backend failures are real HTTP
failures without a live mail service. Empty-path archive restore/delete exercise
their database paths without remote filesystem work. Remote update checks use
PHP's disabled URL-file transport and exercise the application's error path.
This suite does not certify a successful external Doveadm operation, remote
GitHub update discovery, or deployed mail storage.
