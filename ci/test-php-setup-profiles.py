"""Exercise setup profile selection without downloads or container mutations.

Run with Python 3. The installer and host commands are replaced by call spies;
the maintained profile selector and extension assertions execute unchanged.
Real PHAR integrity and formatter execution remain separate CI checks.
"""

import hashlib
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / ".github/scripts/setup-php-container.sh"
SPY = r"""
install_verified_phar() {
  printf 'install:%s\n' "$1" >>"$CALLS"
  [[ ${FAIL_TOOL:-} != "$1" ]] || return 42
}
"""
COMMANDS = r"""
apt-get() { printf 'apt:%s\n' "$*" >>"$CALLS"; }
docker-php-ext-configure() { printf 'configure:%s\n' "$*" >>"$CALLS"; }
docker-php-ext-install() { printf 'extensions:%s\n' "$*" >>"$CALLS"; }
php() {
  if [[ $1 == -m ]]; then
    [[ ${PHP_MODULE_FAILURE:-0} != 1 ]] || return 43
    [[ ${COVERAGE_EXTENSION:-0} != 1 ]] || printf 'xdebug\n'
    return 0
  fi
  [[ $1 == --ri ]] || return 0
  [[ ${MISSING_EXTENSION:-} != "$2" ]]
}
nproc() { printf '1\n'; }
rm() { :; }
curl() { echo 'unexpected network command' >&2; return 99; }
export -f apt-get docker-php-ext-configure docker-php-ext-install php nproc rm curl
"""


def run_profile(profile, **settings):
    source = SOURCE.read_text()
    source, replacements = re.subn(
        r"(?ms)^install_verified_phar\(\) [({]\n.*?^[)}]\n", SPY, source
    )
    if replacements != 1:
        raise AssertionError("expected exactly one PHAR installer")
    # These are the script's two container configuration writes. Redirect only
    # their output paths; executing the test must never touch the host's /usr.
    source = source.replace("/usr/local/etc/php/conf.d/", "$SANDBOX/")
    with tempfile.TemporaryDirectory(prefix="vimbadmin-setup-profiles-") as scratch:
        directory = Path(scratch)
        calls = directory / "calls"
        calls.touch()
        driver = directory / "setup.sh"
        driver.write_text(COMMANDS + source)
        environment = dict(os.environ, CALLS=str(calls), SANDBOX=scratch, **settings)
        result = subprocess.run(
            ["bash", str(driver), profile],
            env=environment,
            capture_output=True,
            text=True,
            check=False,
        )
        return result, calls.read_text().splitlines()


class SetupProfiles(unittest.TestCase):
    def test_phar_integrity_failure_stops_before_install(self):
        source = SOURCE.read_text()
        installer = re.search(
            r"(?ms)^install_verified_phar\(\) [({]\n.*?^[)}]\n", source
        )
        self.assertIsNotNone(installer)
        with tempfile.TemporaryDirectory(prefix="vimbadmin-phar-integrity-") as scratch:
            directory = Path(scratch)
            payload = directory / "payload"
            payload.write_bytes(b"inert verified PHAR fixture\n")
            calls = directory / "calls"
            calls.touch()
            driver = directory / "driver.sh"
            driver.write_text(
                "set -euo pipefail\n"
                + installer[0]
                + r"""
curl() {
  [[ ${DOWNLOAD_FAILURE:-0} != 1 ]] || return 22
  [[ ${DOWNLOAD_FAILURE:-0} != missing ]] || return 127
  [[ $* == *'--connect-timeout 30 --max-time 300 --retry 3 --retry-max-time 600'* ]] || return 98
  local output=''
  while (( $# )); do
    case "$1" in
      --output) output=$2; shift 2 ;;
      *) shift ;;
    esac
  done
  [[ -n $output ]] || return 98
  cp -- "$PAYLOAD" "$output"
  [[ ${DOWNLOAD_FAILURE:-0} != partial ]] || return 18
}
install() {
  printf '%s\n' "$*" >>"$CALLS"
  # Stop before executing the destination. This spy never writes outside /tmp.
  return 42
}
install_verified_phar fixture "$EXPECTED_SHA" https://example.invalid/fixture
"""
            )
            digest = hashlib.sha256(payload.read_bytes()).hexdigest()
            for checksum, download_failure, expected in (
                (digest, "0", 42),
                ("0" * 64, "0", 1),
                (digest, "1", 22),
                (digest, "partial", 18),
                (digest, "missing", 127),
            ):
                calls.write_text("")
                environment = dict(
                    os.environ,
                    PAYLOAD=str(payload),
                    CALLS=str(calls),
                    EXPECTED_SHA=checksum,
                    DOWNLOAD_FAILURE=download_failure,
                    TMPDIR=scratch,
                )
                result = subprocess.run(
                    ["bash", str(driver)],
                    env=environment,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(result.returncode, expected, result.stderr)
                self.assertEqual(bool(calls.read_text()), expected == 42)
                self.assertEqual(
                    {p.name for p in directory.iterdir()},
                    {"payload", "calls", "driver.sh"},
                )

    def test_tool_profiles_install_only_their_analyzer(self):
        for profile, tools in (
            ("formatter", ["php-cs-fixer"]),
            ("tools", ["composer"]),
            ("runtime", ["composer"]),
            ("phpstan", ["composer", "phpstan"]),
            ("psalm", ["composer", "psalm"]),
            ("static", ["composer", "phpstan", "psalm", "php-cs-fixer"]),
        ):
            with self.subTest(profile=profile):
                result, calls = run_profile(profile)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(
                    [c for c in calls if c.startswith("install:")],
                    [f"install:{tool}" for tool in tools],
                )
                builds_extensions = any(c.startswith("extensions:") for c in calls)
                self.assertEqual(
                    builds_extensions, profile not in {"formatter", "tools"}
                )

    def test_unknown_profile_fails_before_any_side_effect(self):
        for profile in ("", "unknown", "Formatter"):
            result, calls = run_profile(profile)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(calls, [])

    def test_installer_failure_is_not_hidden_by_early_exit(self):
        for profile, tool in (
            ("formatter", "php-cs-fixer"),
            ("phpstan", "phpstan"),
            ("psalm", "psalm"),
            ("tools", "composer"),
        ):
            result, _ = run_profile(profile, FAIL_TOOL=tool)
            self.assertEqual(result.returncode, 42, result.stderr)

    def test_runtime_profiles_keep_required_extension_failure(self):
        for profile in ("runtime", "phpstan", "psalm", "static"):
            result, _ = run_profile(profile, MISSING_EXTENSION="pdo_mysql")
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(
                "Required PHP extension is unavailable: pdo_mysql", result.stderr
            )

    def test_module_inventory_errors_and_coverage_extensions_fail(self):
        for profile in ("runtime", "phpstan", "psalm", "static"):
            result, _ = run_profile(profile, PHP_MODULE_FAILURE="1")
            self.assertEqual(result.returncode, 43, result.stderr)
            result, _ = run_profile(profile, COVERAGE_EXTENSION="1")
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("Coverage extension loaded", result.stderr)

    def test_workflow_uses_specific_profiles(self):
        workflow = (ROOT / ".github/workflows/static-analysis.yml").read_text()
        for job, profile in (
            ("phpstan", "phpstan"),
            ("psalm-taint", "psalm"),
            ("php-cs-fixer", "formatter"),
        ):
            body = re.search(rf"(?ms)^  {job}:\n(.*?)(?=^  [\w-]+:|\Z)", workflow)
            self.assertIsNotNone(body)
            self.assertIn(
                f"run: bash .github/scripts/setup-php-container.sh {profile}\n", body[1]
            )


if __name__ == "__main__":
    unittest.main()
