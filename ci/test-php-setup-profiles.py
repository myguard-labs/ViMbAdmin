"""Exercise setup profile selection without downloads or container mutations.

Run with Python 3. The installer and host commands are replaced by call spies;
the maintained profile selector and extension assertions execute unchanged.
Real PHAR integrity and formatter execution remain separate CI checks.
"""

import hashlib
import re
import stat
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
PROFILE_SPIES = {
    "select_patched_phpstan": r"""
select_patched_phpstan() {
  printf 'select:phpstan\n' >>"$CALLS"
  [[ ${FAIL_HELPER:-} != select_patched_phpstan ]] || return 44
}
""",
    "install_psalm_optional_models": r"""
install_psalm_optional_models() {
  printf 'models:psalm\n' >>"$CALLS"
  [[ ${FAIL_HELPER:-} != install_psalm_optional_models ]] || return 44
}
""",
    "install_psalm_toolchain": r"""
install_psalm_toolchain() {
  printf 'toolchain:psalm\n' >>"$CALLS"
  [[ ${FAIL_HELPER:-} != install_psalm_toolchain ]] || return 44
  install_verified_phar psalm
}
""",
    "install_apcu": r"""
install_apcu() {
  printf 'build:apcu\n' >>"$CALLS"
  [[ ${FAIL_HELPER:-} != install_apcu ]] || return 44
}
""",
}
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
localedef() { printf 'locale:%s\n' "$*" >>"$CALLS"; }
docker-php-ext-enable() { printf 'enable:%s\n' "$*" >>"$CALLS"; }
rm() { :; }
curl() { echo 'unexpected network command' >&2; return 99; }
export -f apt-get docker-php-ext-configure docker-php-ext-install php nproc localedef docker-php-ext-enable rm curl
"""


def controlled_environment(scratch, **settings):
    return dict(
        PATH="/usr/bin:/bin", HOME=scratch, TMPDIR=scratch, LC_ALL="C",
        PHPIZE_DEPS="autoconf", **settings,
    )


def run_profile(profile, **settings):
    source = SOURCE.read_text()
    source, replacements = re.subn(
        r"(?ms)^install_verified_phar\(\) [({]\n.*?^[)}]\n", SPY, source
    )
    if replacements != 1:
        raise AssertionError("expected exactly one PHAR installer")
    for name, spy in PROFILE_SPIES.items():
        source, replacements = re.subn(
            rf"(?ms)^{name}\(\) [({{]\n.*?^[)}}]\n", lambda match: spy, source
        )
        if replacements != 1:
            raise AssertionError(f"expected exactly one {name} helper")
    # These are the script's two container configuration writes. Redirect only
    # their output paths; executing the test must never touch the host's /usr.
    source = source.replace("/usr/local/etc/php/conf.d/", "$SANDBOX/")
    with tempfile.TemporaryDirectory(prefix="vimbadmin-setup-profiles-") as scratch:
        directory = Path(scratch)
        calls = directory / "calls"
        calls.touch()
        driver = directory / "setup.sh"
        driver.write_text(COMMANDS + source)
        environment = controlled_environment(
            scratch, CALLS=str(calls), SANDBOX=scratch, **settings
        )
        result = subprocess.run(
            ["/bin/bash", str(driver), profile],
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
mktemp() {
  [[ ${TEMP_FAILURE:-0} != 1 ]] || return 71
  command mktemp
}
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
sha256sum() {
  [[ ${CHECKSUM_FAILURE:-0} != 1 ]] || return 65
  command sha256sum "$@"
}
install() {
  printf '%s\n' "$*" >>"$CALLS"
  [[ $1 == -m && $2 == 0755 && $4 == "$DESTINATION" ]] || return 98
  [[ ${INSTALL_FAILURE:-0} != 1 ]] || return 42
  # Copy only the inert payload to this fixture's private destination.
  cp -- "$3" "$4"
  chmod 0755 "$4"
}
# This is the actual toolchain's conditional call context: errexit is disabled
# inside the function, so every failed prerequisite must return explicitly.
if install_verified_phar psalm "$EXPECTED_SHA" https://example.invalid/fixture "$DESTINATION"; then
  exit 0
else
  exit "$?"
fi
"""
            )
            digest = hashlib.sha256(payload.read_bytes()).hexdigest()
            destination = directory / "checked-psalm"
            for checksum, download_failure, checksum_failure, install_failure, temp_failure, expected in (
                (digest, "0", "0", "0", "0", 0),
                (digest, "0", "0", "1", "0", 42),
                ("0" * 64, "0", "0", "0", "0", 1),
                (digest, "1", "0", "0", "0", 22),
                (digest, "partial", "0", "0", "0", 18),
                (digest, "missing", "0", "0", "0", 127),
                (digest, "0", "1", "0", "0", 65),
                (digest, "0", "0", "0", "1", 71),
            ):
                calls.write_text("")
                destination.unlink(missing_ok=True)
                environment = controlled_environment(
                    scratch,
                    PAYLOAD=str(payload),
                    CALLS=str(calls),
                    EXPECTED_SHA=checksum,
                    DOWNLOAD_FAILURE=download_failure,
                    CHECKSUM_FAILURE=checksum_failure,
                    INSTALL_FAILURE=install_failure,
                    TEMP_FAILURE=temp_failure,
                    DESTINATION=str(destination),
                )
                result = subprocess.run(
                    ["/bin/bash", str(driver)],
                    env=environment,
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(result.returncode, expected, result.stderr)
                self.assertEqual(bool(calls.read_text()), expected in (0, 42))
                self.assertEqual(destination.exists(), expected == 0)
                if expected == 0:
                    self.assertEqual(destination.read_bytes(), payload.read_bytes())
                    self.assertEqual(stat.S_IMODE(destination.stat().st_mode), 0o755)
                self.assertEqual(
                    {p.name for p in directory.iterdir()},
                    {"payload", "calls", "driver.sh"} | ({"checked-psalm"} if expected == 0 else set()),
                )

    def test_tool_profiles_install_only_their_analyzer(self):
        for profile, tools in (
            ("formatter", ["php-cs-fixer"]),
            ("tools", ["composer"]),
            ("runtime", ["composer"]),
            ("cache", ["composer"]),
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
                expected_helpers = []
                if profile == "cache":
                    expected_helpers.append("build:apcu")
                if profile in {"phpstan", "static"}:
                    expected_helpers.append("select:phpstan")
                if profile in {"psalm", "static"}:
                    expected_helpers.extend(["models:psalm", "toolchain:psalm"])
                self.assertEqual(
                    [c for c in calls if c.startswith(("select:", "models:", "toolchain:", "build:"))],
                    expected_helpers,
                )
                patch_calls = [
                    c for c in calls if c.startswith("apt:") and "patch" in c.split()
                ]
                self.assertEqual(
                    len(patch_calls), int(profile in {"phpstan", "psalm", "static"})
                )
                if "toolchain:psalm" in calls:
                    self.assertLess(calls.index(patch_calls[0]), calls.index("toolchain:psalm"))

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

    def test_profile_helper_failure_stops_dispatch(self):
        for profile, helper in (
            ("phpstan", "select_patched_phpstan"),
            ("psalm", "install_psalm_optional_models"),
            ("psalm", "install_psalm_toolchain"),
            ("cache", "install_apcu"),
        ):
            result, _ = run_profile(profile, FAIL_HELPER=helper)
            self.assertEqual(result.returncode, 44, result.stderr)

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

    def test_consumer_extracts_and_dispatches_current_function_forms(self):
        consumer = (ROOT / "ci/test-psalm-tool-consumer.sh").read_text()
        extraction = re.search(
            r"(?ms)^  awk '/\^run_psalm_contracts.*?^  source \"\$work/contracts.sh\"$",
            consumer,
        )
        self.assertIsNotNone(extraction)
        commands = "\n".join(line[2:] for line in extraction[0].splitlines())
        with tempfile.TemporaryDirectory(prefix="vimbadmin-consumer-functions-") as scratch:
            directory = Path(scratch)
            driver = directory / "consumer-functions.sh"
            driver.write_text(
                'set -euo pipefail\nsetup=$SETUP_SOURCE\nwork=$WORK\n'
                + "\n".join(re.findall(r"^readonly psalm_(?:version|sha256)=.*$", SOURCE.read_text(), re.M))
                + "\n" + commands + "\n"
                + r"""
declare -F install_verified_phar install_psalm_toolchain run_psalm_contracts >/dev/null
install_verified_phar() {
  [[ $1 == psalm && $2 == "$psalm_sha256" && $4 == "$UPSTREAM" ]] || return 97
  printf 'phar\n'
}
select_patched_psalm() {
  [[ $1 == "$REPO" && $2 == "$UPSTREAM" ]] || return 97
  PSALM_BIN=$WORK/checked/bin/psalm
  printf 'selector\n'
}
run_psalm_contracts() {
  [[ $1 == "$REPO" && $2 == "$UPSTREAM" && $3 == "$WORK/checked/engine/psalm" ]] || return 97
  printf 'contracts\n'
}
install_psalm_toolchain "$REPO" "$UPSTREAM"
"""
            )
            result = subprocess.run(
                ["/bin/bash", str(driver)],
                env=controlled_environment(
                    scratch, WORK=scratch, REPO=str(ROOT),
                    SETUP_SOURCE=str(SOURCE), UPSTREAM=str(directory / "upstream"),
                ),
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout.splitlines(), ["phar", "selector", "contracts"])


if __name__ == "__main__":
    unittest.main()
