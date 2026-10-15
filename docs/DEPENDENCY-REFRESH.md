# Dependency refresh

After a Composer update, run this repository-owned command against a named,
trusted copy of the pre-update lock:

```sh
git show "$base_sha:composer.lock" > /tmp/vimbadmin-base-composer.lock
php tools/refresh-dependencies.php --base-lock /tmp/vimbadmin-base-composer.lock
```

Review and commit the resulting lock and documentation repair with the update.
The command does not run Composer or upgrade dependencies. It requires valid
checksums in the base lock, preserves those pins for unchanged URLs, downloads
changed URLs independently and computes Composer's SHA-1 archive checksum.
A populated candidate checksum must match the downloaded bytes. Missing inputs,
malformed metadata, fetch failures and mismatches stop the command.

Archive URLs are restricted to the existing immutable HTTPS GitHub zipball
format. Curl follows GitHub's archive redirect over HTTPS, with time, redirect
and 50 MiB size bounds. No credentials are accepted in URLs or passed to curl.
A new archive origin requires a reviewed policy change. Archive contents are
hashed without unpacking or executing them.

Only checksum values and the existing ORM3/DBAL4 version pair in
`docs/ORM3-UPGRADE.md` and `CHANGELOG` are updated. Other lock/document bytes
remain intact. Both existing integrity and documentation guards execute on the
result. Guard failure restores the three original input files. The tool needs
PHP 8.2 or newer and the existing curl executable; CI/tests use PHP 8.3/8.4/8.5.

Dependabot remains configured in `.github/dependabot.yml`. Its ordinary,
isolated PR job calls `.github/scripts/check-dependency-refresh.sh`, fetching
the immutable event base SHA and invoking the command with `--check`. That mode
runs both guards against the computed repair, restores input bytes, and fails
if any repair is needed. A failure blocks delivery and directs the operator to
run the same command locally. The workflow cannot push a repaired branch;
repository permissions remain read-only, without a new credential or privileged
PR event. Other jobs receive their own checkout and are not modified.

`ci/test-dependency-refresh.php` exercises real guards and the actual automation
helper using isolated file fixtures and stubbed Git/download transport. Network
smoke evidence is separate from deterministic regression tests. No downloaded
archive or temporary fixture belongs in the repository.
