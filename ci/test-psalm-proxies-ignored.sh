#!/usr/bin/env bash
#
# Prove psalm.xml excludes the generated Doctrine proxies from taint analysis.
#
# Psalm honours a single <ignoreFiles> element, so a second element for
# application/Proxies was silently dropped and all proxies were analysed. This
# counts the files Psalm really analyses: the shipped config must analyse none
# of application/Proxies, a seeded revert to two elements must analyse them
# all (control), and the intended first-party set must stay analysed.
#
# Requires psalm, vendor/autoload.php and generated proxies
# (php bin/generate-proxies.php). PSALM_BIN overrides the binary.

set -euo pipefail

repo_root=$(cd "$(dirname "$0")/.." && pwd -P)
cd "$repo_root"

if [ -n "${PSALM_BIN:-}" ]; then
  psalm=("$PSALM_BIN")
elif [ -x vendor/bin/psalm ]; then
  psalm=(vendor/bin/psalm)
else
  psalm=(psalm)
fi

proxies=$(find application/Proxies -maxdepth 1 -name '__CG__*.php' | wc -l)
if [ "$proxies" -eq 0 ]; then
  echo 'No generated proxies: run php bin/generate-proxies.php first.' >&2
  exit 1
fi

work=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-proxies.XXXXXX")
reverted=$(mktemp "$repo_root/.psalm-proxies-revert.XXXXXX.xml")
omitted=$(mktemp "$repo_root/.psalm-proxies-omitted.XXXXXX.xml")
swapped_config=$(mktemp "$repo_root/.psalm-proxies-swapped.XXXXXX.xml")
swapped_file=
cleanup() {
  rm -rf -- "${work:?}"
  rm -f -- "$reverted" "$omitted" "$swapped_config"
  if [ -n "$swapped_file" ]; then rm -f -- "$swapped_file"; fi
}
trap cleanup EXIT

if [ "$(stat -c %u "$repo_root")" != "$(id -u)" ]; then
  # The CI checkout belongs to another UID, and checkout's temporary Git
  # config is not inherited by the test container.
  if env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
    git -C "$repo_root" ls-files '*.php' >"$work/untrusted.out" 2>"$work/untrusted.err" ||
    ! grep -q 'dubious ownership' "$work/untrusted.err"; then
    echo 'Cross-UID negative control did not reject untrusted Git inventory.' >&2
    exit 1
  fi
  if ! env -u SUDO_UID GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 \
    git -C "$repo_root" -c safe.directory="$repo_root" ls-files -z '*.php' \
    >"$work/scoped.out" || [ ! -s "$work/scoped.out" ]; then
    echo 'Cross-UID scoped Git inventory failed.' >&2
    exit 1
  fi
  echo 'Cross-UID Git inventory rejects untrusted access and accepts only this checkout.'
fi

first_party_paths() { # first_party_paths <debug-log> <literal-root>
  awk -v root="$2" '
    {
      prefix = "Analyzing " root "/"
      if (index($0, prefix) != 1) next
      relative = substr($0, length(prefix) + 1)
      if (relative ~ /^(src|library|application)\// &&
          relative !~ /^application\/Proxies\//) print relative
    }
  ' "$1"
}
first_party_count() { first_party_paths "$@" | wc -l; }
printf 'Analyzing %s/src/A.php\nAnalyzing %s/application/Proxies/P.php\n' \
  "$work/repo+[x]" "$work/repo+[x]" >"$work/literal-root.log"
if [ "$(first_party_count "$work/literal-root.log" "$work/repo+[x]")" -ne 1 ]; then
  echo 'Literal-root first-party counter fails for regex metacharacters.' >&2
  exit 1
fi

analysed() {
  # Prints "<total> <proxies> <first-party src/library/application non-proxy>".
  local config=$1 log=$work/analysed.log
  if ! "${psalm[@]}" --config="$config" --no-progress --taint-analysis --debug \
    >"$work/psalm.out" 2>&1; then
    echo "Psalm failed while counting analysed files for $config:" >&2
    tail -40 "$work/psalm.out" >&2
    return 1
  fi
  grep -E '^Analyzing /' "$work/psalm.out" >"$log" || true
  first_party_paths "$log" "$repo_root" | LC_ALL=C sort >"$work/$(basename "$config").first-party"
  printf '%s %s %s\n' "$(wc -l <"$log")" \
    "$(grep -c '/application/Proxies/' "$log" || true)" \
    "$(first_party_count "$log" "$repo_root")"
}

# Seeded revert: the pre-fix shape with Proxies in a second ignoreFiles element.
sed -e '/<directory name="application\/Proxies" \/>/d' \
  -e '/<\/ignoreFiles>/a\
        <ignoreFiles allowMissingFiles="true">\
            <directory name="application/Proxies" />\
        </ignoreFiles>' psalm.xml >"$reverted"
if ! [ "$(grep -c '<ignoreFiles' "$reverted")" -ge 2 ]; then
  echo 'Seeded revert did not produce a second ignoreFiles element.' >&2
  exit 1
fi

analysed psalm.xml >"$work/shipped.counts"
read -r shipped_total shipped_proxies shipped_first <"$work/shipped.counts"
analysed "$reverted" >"$work/revert.counts"
read -r revert_total revert_proxies revert_first <"$work/revert.counts"
printf 'shipped: total=%s proxies=%s first-party=%s\n' \
  "$shipped_total" "$shipped_proxies" "$shipped_first"
printf 'revert:  total=%s proxies=%s first-party=%s\n' \
  "$revert_total" "$revert_proxies" "$revert_first"

if [ "$shipped_proxies" -ne 0 ]; then
  echo "psalm.xml still analyses $shipped_proxies generated proxy files." >&2
  exit 1
fi
if [ "$revert_proxies" -ne "$proxies" ]; then
  echo "Control failed: revert analysed $revert_proxies of $proxies proxies." >&2
  exit 1
fi
if [ "$shipped_first" -eq 0 ] || [ "$shipped_first" -ne "$revert_first" ]; then
  echo 'The first-party analysed set changed or is empty.' >&2
  exit 1
fi
# The tracked first-party PHP inventory is independent of either Psalm
# configuration. Equality of the two Psalm runs alone misses a root removed
# from both the shipped configuration and its seeded revert.
# shellcheck disable=SC2016 # PHP source is intentionally single-quoted.
git -c safe.directory="$repo_root" ls-files -z -- '*.php' | "${PHP_BINARY:-php}" -r '
  foreach (explode("\0", stream_get_contents(STDIN)) as $path) {
      if ($path === "") continue;
      if (!preg_match("~^(src|library|application)/.*\\.php$~", $path)
          || str_starts_with($path, "application/Proxies/")) continue;
      if (str_contains($path, "\n")) { fwrite(STDERR, "Unsupported newline in PHP path\n"); exit(1); }
      echo $path, "\n";
  }
' | LC_ALL=C sort >"$work/expected.first-party"
if ! cmp -s "$work/expected.first-party" "$work/psalm.xml.first-party" ||
  ! cmp -s "$work/expected.first-party" "$work/$(basename "$reverted").first-party"; then
  echo 'Psalm analysed paths differ from the tracked first-party PHP inventory.' >&2
  comm -3 "$work/expected.first-party" "$work/psalm.xml.first-party" >&2
  exit 1
fi
# A root removed from the config and its derived revert must be caught by
# the independent inventory even though their analysed counts still agree.
sed '/<directory name="library" \/>/d' psalm.xml >"$omitted"
analysed "$omitted" >"$work/omitted.counts"
comm -23 "$work/expected.first-party" "$work/$(basename "$omitted").first-party" >"$work/omitted.missing"
if cmp -s "$work/expected.first-party" "$work/$(basename "$omitted").first-party" ||
  ! grep -q '^library/' "$work/omitted.missing"; then
  echo 'Negative control: omitted library root was not detected.' >&2
  exit 1
fi
echo 'Negative control: omitted first-party root differs from tracked inventory.'
# Keep the count unchanged while replacing one tracked path with an untracked
# fixture. A count-only check would accept this different analysed set.
swapped_file=$(mktemp "$repo_root/src/.psalm-swap.XXXXXX.php")
printf '<?php\n' >"$swapped_file"
swapped_relative=${swapped_file#"$repo_root"/}
swap_target=$(git -c safe.directory="$repo_root" ls-files -- 'src/*.php' | sed -n '1p')
if [ -z "$swap_target" ]; then
  echo 'Negative control requires a tracked src PHP file.' >&2
  exit 1
fi
awk -v target="$swap_target" '
  /<ignoreFiles allowMissingFiles=/ {
    print
    print "            <file name=\"" target "\" />"
    next
  }
  { print }
' psalm.xml >"$swapped_config"
analysed "$swapped_config" >"$work/swapped.counts"
read -r _ swapped_proxies swapped_first <"$work/swapped.counts"
comm -23 "$work/expected.first-party" "$work/$(basename "$swapped_config").first-party" >"$work/swapped.missing"
comm -13 "$work/expected.first-party" "$work/$(basename "$swapped_config").first-party" >"$work/swapped.unexpected"
if [ "$swapped_first" -ne "$shipped_first" ] || [ "$swapped_proxies" -ne 0 ] ||
  ! grep -qxF "$swap_target" "$work/swapped.missing" ||
  ! grep -qxF "$swapped_relative" "$work/swapped.unexpected"; then
  echo 'Negative control: equal-count first-party path swap was not detected.' >&2
  exit 1
fi
echo 'Negative control: equal-count first-party path swap differs from tracked inventory.'
echo 'psalm.xml excludes generated proxies and keeps the first-party set.'
