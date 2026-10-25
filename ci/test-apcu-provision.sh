#!/usr/bin/env bash
# Prove that the exact APCu installer fails closed before extraction/build.
set -euo pipefail
cd "$(dirname "$0")/.."
scratch=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-apcu-provision.XXXXXX")
trap 'rm -rf -- "${scratch:?}"' EXIT
sed -n '/^install_apcu() ($/,/^)/p' .github/scripts/setup-php-container.sh >"$scratch/installer.sh"
[[ $(grep -c '^install_apcu() (' "$scratch/installer.sh") == 1 ]] || exit 1
cat >"$scratch/driver.sh" <<'DRIVER'
set -euo pipefail
run_dir=$1
control=$2
apcu_revision=90899684be91c4f750512c2f840f487286ecb807
apcu_sha256=33f9038e98d236d4833b3e311c21e1d969a96d06b0d1e4427c8a0db2dd7123e2
mktemp() { mkdir "$run_dir/build"; printf '%s\n' "$run_dir/build"; }
curl() {
  [[ $* == *"https://codeload.github.com/krakjoe/apcu/tar.gz/90899684be91c4f750512c2f840f487286ecb807"* ]] || exit 90
  printf 'download\n' >>"$run_dir/calls"
  [[ $control != download-failure ]] || return 22
  : >"$run_dir/build/apcu.tgz"
}
sha256sum() {
  [[ $# == 2 && $1 == --check && $2 == --status ]] || {
    echo 'FAIL: APCu checksum requires --check --status' >&2
    exit 92
  }
  local input
  IFS= read -r input
  [[ $input == "$apcu_sha256  $run_dir/build/apcu.tgz" ]] || exit 91
  printf 'verify\n' >>"$run_dir/calls"
  [[ $control != checksum-failure ]]
}
if [[ $control == success ]]; then
  # Exercise the stub contract in this driver, without another external command.
  for options in '' '--check' '--status' '--status --check' '--check --bogus' '--check --status --bogus'; do
    read -r -a checksum_args <<<"$options"
    option_status=0
    (sha256sum "${checksum_args[@]}" <<<"$apcu_sha256  $run_dir/build/apcu.tgz") \
      >"$run_dir/option-output" 2>&1 || option_status=$?
    [[ $option_status == 92 ]] || {
      echo "FAIL: checksum stub accepted invalid options '$options' (exit $option_status)" >&2
      exit 1
    }
  done
fi
tar() {
  printf 'extract\n' >>"$run_dir/calls"
  [[ $control != archive-failure ]] || return 2
  # Configure receives its normal flag; record execution through the wrapper.
  printf '#!/usr/bin/env bash\nprintf "configure\\n" >>"%s/calls"\n' "$run_dir" >"$run_dir/build/configure"
  chmod +x "$run_dir/build/configure"
}
phpize() { printf 'phpize\n' >>"$run_dir/calls"; }
nproc() { printf '1\n'; }
make() {
  printf 'make %s\n' "$*" >>"$run_dir/calls"
  [[ $control != build-failure ]] || return 2
}
# shellcheck disable=SC1090 # Exact extracted source function, no full setup.
source "$3"
install_apcu
DRIVER

for control in success download-failure checksum-failure archive-failure build-failure; do
  run_dir=$scratch/$control
  mkdir "$run_dir"
  status=0
  bash "$scratch/driver.sh" "$run_dir" "$control" "$scratch/installer.sh" >"$run_dir/output" 2>&1 || status=$?
  [[ ! -e $run_dir/build ]] || {
    echo 'FAIL: APCu installer leaked its build directory' >&2
    exit 1
  }
  if [[ $control == success ]]; then
    [[ $status == 0 ]] || {
      cat "$run_dir/output" >&2
      exit 1
    }
    printf 'download\nverify\nextract\nphpize\nconfigure\nmake -j1\nmake install\n' >"$run_dir/expected"
    cmp "$run_dir/expected" "$run_dir/calls"
  else
    [[ $status != 0 ]] || {
      echo "FAIL: $control passed" >&2
      exit 1
    }
    case $control in
    download-failure) forbidden=verify ;;
    checksum-failure) forbidden=extract ;;
    archive-failure) forbidden=phpize ;;
    build-failure) forbidden='make install' ;;
    esac
    if grep -qxF "$forbidden" "$run_dir/calls"; then
      echo "FAIL: $control advanced past failure" >&2
      exit 1
    fi
  fi
  printf 'OK: APCu %s exit=%s; temporary source removed\n' "$control" "$status"
done

sed -n '/^if ! php --ri mbstring /,/^fi$/p' .github/scripts/setup-php-container.sh >"$scratch/mbstring.sh"
[[ $(grep -c '^if ! php --ri mbstring ' "$scratch/mbstring.sh") == 1 ]] || exit 1
cat >"$scratch/mbstring-driver.sh" <<'DRIVER'
set -euo pipefail
run_dir=$1
control=$2
php() { [[ $control == loaded ]]; }
apt-get() { echo apt >>"$run_dir/calls"; [[ $control != apt-failure ]]; }
nproc() { printf '1\n'; }
docker-php-ext-install() { echo compile >>"$run_dir/calls"; [[ $control != compile-failure ]]; }
# shellcheck disable=SC1090 # Exact extracted optional-extension setup.
source "$3"
DRIVER
for control in loaded missing apt-failure compile-failure; do
  run_dir=$scratch/mbstring-$control
  mkdir "$run_dir"
  status=0
  bash "$scratch/mbstring-driver.sh" "$run_dir" "$control" "$scratch/mbstring.sh" >"$run_dir/output" 2>&1 || status=$?
  case $control in
  loaded) [[ $status == 0 && ! -e $run_dir/calls ]] ;;
  missing)
    [[ $status == 0 ]]
    printf 'apt\ncompile\n' >"$run_dir/expected"
    cmp "$run_dir/expected" "$run_dir/calls"
    ;;
  apt-failure)
    [[ $status != 0 ]]
    [[ $(<"$run_dir/calls") == apt ]]
    ;;
  compile-failure) [[ $status != 0 ]] ;;
  esac
  printf 'OK: mbstring %s exit=%s\n' "$control" "$status"
done
