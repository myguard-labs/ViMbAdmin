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

repo_root=$(cd "$(dirname "$0")/.." && pwd)
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
cleanup() {
  rm -rf -- "${work:?}"
  rm -f -- "$reverted"
}
trap cleanup EXIT

first_party_count() { # first_party_count <debug-log> <literal-root>
  awk -v root="$2" '
    {
      prefix = "Analyzing " root "/"
      if (index($0, prefix) != 1) next
      relative = substr($0, length(prefix) + 1)
      if (relative ~ /^(src|library|application)\// &&
          relative !~ /^application\/Proxies\//) count++
    }
    END { print count + 0 }
  ' "$1"
}
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
echo 'psalm.xml excludes generated proxies and keeps the first-party set.'
