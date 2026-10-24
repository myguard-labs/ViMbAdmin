#!/usr/bin/env bash
set -euo pipefail

repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
tool=$repo/tools/refactor-metrics.py
real_jscpd=$repo/ci/refactor-metrics/node_modules/.bin/jscpd
scratch=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-metrics-test.XXXXXX")
trap 'rm -rf -- "$scratch"' EXIT
fixture=$scratch/fixture
mkdir -p "$fixture/src/Proxies" "$fixture/application/Entities" "$fixture/library/vendor"
git -C "$fixture" init -q

# Synthetic fixtures exercise the real detector/lexer, not a copied algorithm.
python3 - "$fixture" <<'PY'
from pathlib import Path
import sys
root = Path(sys.argv[1])
body = '\n'.join(f'    $total += $input[{i}] ?? {i};' for i in range(24))
php = '<?php\n// License retained.\nfunction calculate($input) {\n' + body + '\n    return $total;\n}\n'
for name in ('src/OwnedA.php', 'src/OwnedB.php', 'src/Proxies/Generated.php'):
    (root / name).write_text(php)
vendor = '<?php\nfunction vendorHash($vendorPayload) {\n' + '\n'.join(
    f"    $vendorHash = hash('sha256', $vendorPayload . 'vendor{i}');" for i in range(24)) + '\n}\n'
for name in ('library/vendor/ThirdPartyA.php', 'library/vendor/ThirdPartyB.php'):
    (root / name).write_text(vendor)
mapping = '<?php\nclass Mapping {\n' + '\n'.join(
    f"    #[ORM\\Column(type: 'string', length: 255)]\n    private ?string $field{i} = null;"
    for i in range(16)) + '\n}\n'
for name in ('application/Entities/MapA.php', 'application/Entities/MapB.php'):
    (root / name).write_text(mapping)
(root / 'src/Short.php').write_text('<?php\nfunction tiny() { return 1; }\n')
PY
git -C "$fixture" add src application library
git -C "$fixture" -c user.name=fixture -c user.email=fixture@example.invalid \
  -c commit.gpgsign=false commit -qm fixture
cp -- "$fixture/src/OwnedA.php" "$fixture/src/Untracked.php"

# A conflicting global detector must never displace the module-owned pin.
mkdir "$scratch/global-bin"
cat >"$scratch/global-bin/jscpd" <<'GLOBAL'
#!/usr/bin/env bash
printf 'called\n' >"${METRICS_GLOBAL_MARKER:?}"
printf 'jscpd 5.4.1\n'
GLOBAL
chmod +x "$scratch/global-bin/jscpd"
export METRICS_GLOBAL_MARKER=$scratch/global-invoked
export PATH="$scratch/global-bin:$PATH"
if ! python3 "$tool" capture --repo "$fixture" --output "$scratch/first" >"$scratch/capture.log" 2>&1; then
  printf 'FAIL: module-local detector must win over conflicting global PATH\n' >&2
  cat "$scratch/capture.log" >&2
  exit 1
fi
if [[ -e $METRICS_GLOBAL_MARKER ]]; then
  printf 'FAIL: conflicting global detector was invoked\n' >&2
  exit 1
fi
printf 'PASS: module-local detector wins over conflicting global PATH\n'
python3 "$tool" capture --repo "$fixture" --output "$scratch/second" >>"$scratch/capture.log"
cmp "$scratch/first/clones.json" "$scratch/second/clones.json"
cmp "$scratch/first/tracked-manifest.tsv" "$scratch/second/tracked-manifest.tsv"
cmp "$scratch/first/baseline.json" "$scratch/second/baseline.json"

python3 - "$scratch" "$real_jscpd" <<'PY'
import hashlib
import json
from pathlib import Path
import sys
root = Path(sys.argv[1])
launcher = Path(sys.argv[2]).resolve()
tool_manifest = json.loads((root / 'first/tool-manifest.json').read_text())
assert any(entry['path'] == str(launcher.relative_to(launcher.parent.parent))
           and entry['sha256'] == hashlib.sha256(launcher.read_bytes()).hexdigest()
           for entry in tool_manifest), 'tool identity did not use the pinned local launcher'
report = json.loads((root / 'first/clones.json').read_text())
paths = {s['path'] for c in report['clones'] for s in c['sites']}
assert 'src/OwnedA.php' in paths and 'src/OwnedB.php' in paths, 'first-party duplicate missing'
assert all('/Proxies/' not in p for p in paths), 'generated proxy included'
assert not any('Untracked' in p or 'Short' in p for p in paths), paths
policy = {'schema': 1, 'groups': {}, 'clones': {}}
for clone in report['clones']:
    sites = [s['path'] for s in clone['sites']]
    kind = 'vendor' if any('/vendor/' in p for p in sites) else (
        'mapping' if any('/Entities/' in p for p in sites) else 'first-party')
    policy['groups'][kind] = {'class': kind, 'disposition': 'candidate' if kind == 'first-party' else 'retained',
        'reason': {'vendor': 'Retain attributed third-party implementation; no first-party credit.',
                   'mapping': 'Retain concrete ORM mapping/schema declarations.',
                   'first-party': 'Investigate equivalent owned implementation after contract proof.'}[kind],
        'helper_overhead_code_lines': 5}
    policy['clones'][clone['id']] = kind
assert set(policy['groups']) == {'first-party', 'vendor', 'mapping'}, policy
(root / 'classification.json').write_text(json.dumps(policy))
PY

census() {
  python3 "$tool" census --report "$scratch/first/clones.json" \
    --classification "$1" --output "$scratch/census.json"
}
census "$scratch/classification.json" >"$scratch/census.log"
python3 - "$scratch/census.json" <<'PY'
import json, sys
result = json.load(open(sys.argv[1]))
assert result['counts']['first-party:candidate'] > 0
assert result['counts']['vendor:retained'] > 0
assert result['counts']['mapping:retained'] > 0
assert result['counts']['generated:retained'] == 0
for group in result['ranked_groups']:
    if group['class'] != 'first-party':
        assert group['optimistic_net_code_lines_upper_bound'] == 0, group
    else:
        assert group['optimistic_net_code_lines_upper_bound'] > 0, group
        assert group['helper_overhead_code_lines'] == 5
        assert group['recent_touch_commits'] == 1
        assert group['recent_cochange_commits'] == 1
        assert group['cochange_sites_max'] == 2
PY

expect_error() {
  local needle=$1
  shift
  local status=0
  "$@" >"$scratch/error.log" 2>&1 || status=$?
  if [[ $status -ne 2 ]] || ! grep -qF "$needle" "$scratch/error.log"; then
    printf 'FAIL: expected exit 2 containing %s; got %s\n' "$needle" "$status" >&2
    cat "$scratch/error.log" >&2
    exit 1
  fi
  printf 'PASS expected-error exit=2: %s\n' "$needle"
}

# Boundary: a valid empty census needs no invented candidate/classification.
printf '{"schema":1,"clones":[],"changes":[]}' >"$scratch/empty-report.json"
printf '{"schema":1,"groups":{},"clones":{}}' >"$scratch/empty-policy.json"
python3 "$tool" census --report "$scratch/empty-report.json" \
  --classification "$scratch/empty-policy.json" --output "$scratch/empty.json" >/dev/null
expect_error 'output must be a new directory' \
  python3 "$tool" capture --repo "$fixture" --output "$scratch/first"
expect_error 'output must be outside the checkout' \
  python3 "$tool" capture --repo "$fixture" --output "$fixture/metrics-output"
[[ ! -e $fixture/metrics-output ]] || {
  printf 'Rejected metrics output created a directory inside the checkout.\n' >&2
  exit 1
}
expect_error 'No such file' census "$scratch/missing.json"
printf '{broken' >"$scratch/bad.json"
expect_error 'Expecting property name' census "$scratch/bad.json"
printf '{"schema":1,"schema":1}' >"$scratch/bad.json"
expect_error 'duplicate JSON key' census "$scratch/bad.json"
printf '{}' >"$scratch/bad.json"
expect_error 'invalid classification schema' census "$scratch/bad.json"

# These are data mutations: every path must be rejected, including a clone
# silently omitted from a classification and vendor credit labeled candidate.
python3 - "$scratch" <<'PY'
import copy, json, sys
from pathlib import Path
root = Path(sys.argv[1])
p = json.loads((root / 'classification.json').read_text())
mutations = {}
x = copy.deepcopy(p); x['clones'].pop(next(iter(x['clones']))); mutations['missing-clone'] = x
x = copy.deepcopy(p); x['clones']['extra'] = 'first-party'; mutations['extra-clone'] = x
x = copy.deepcopy(p); x['clones'][next(iter(x['clones']))] = 'unknown'; mutations['unknown-group'] = x
x = copy.deepcopy(p); x['groups']['vendor']['disposition'] = 'candidate'; mutations['vendor-credit'] = x
x = copy.deepcopy(p); x['groups']['mapping']['disposition'] = 'candidate'; mutations['mapping-credit'] = x
x = copy.deepcopy(p); x['groups']['first-party']['helper_overhead_code_lines'] = -1; mutations['negative-overhead'] = x
x = copy.deepcopy(p); x['groups']['first-party']['helper_overhead_code_lines'] = True; mutations['boolean-overhead'] = x
x = copy.deepcopy(p); x['groups']['first-party']['reason'] = ''; mutations['empty-reason'] = x
x = copy.deepcopy(p); x['groups']['unused'] = copy.deepcopy(x['groups']['vendor']); mutations['unused-group'] = x
for name, value in mutations.items():
    (root / f'{name}.json').write_text(json.dumps(value))
PY
expect_error 'classification must cover every clone exactly once' census "$scratch/missing-clone.json"
expect_error 'classification must cover every clone exactly once' census "$scratch/extra-clone.json"
expect_error 'unknown clone group' census "$scratch/unknown-group.json"
expect_error 'non-first-party clone cannot earn savings' census "$scratch/vendor-credit.json"
expect_error 'non-first-party clone cannot earn savings' census "$scratch/mapping-credit.json"
expect_error 'invalid helper overhead' census "$scratch/negative-overhead.json"
expect_error 'invalid helper overhead' census "$scratch/boolean-overhead.json"
expect_error 'invalid class/disposition/reason' census "$scratch/empty-reason.json"
expect_error 'unused classification group' census "$scratch/unused-group.json"

# Snapshot must use working tracked bytes, not stale HEAD or untracked fixtures.
printf '\n// Working-only license note.\n' >>"$fixture/src/OwnedA.php"
python3 "$tool" capture --repo "$fixture" --output "$scratch/modified" >/dev/null
if cmp -s "$scratch/first/tracked-manifest.tsv" "$scratch/modified/tracked-manifest.tsv"; then
  printf 'FAIL: working tracked-byte change was omitted.\n' >&2
  exit 1
fi
rm -- "$fixture/src/OwnedA.php"
expect_error 'input must be a present regular file' \
  python3 "$tool" capture --repo "$fixture" --output "$scratch/missing-source"
# Empty roots and ambiguous root-relative detector names fail closed.
mkdir "$scratch/empty-repo"
git -C "$scratch/empty-repo" init -q
expect_error 'tracked source roots are empty' \
  python3 "$tool" capture --repo "$scratch/empty-repo" --output "$scratch/no-roots"
git -C "$fixture" checkout -- src/OwnedA.php
cp -- "$fixture/src/OwnedA.php" "$fixture/application/OwnedA.php"
git -C "$fixture" add application/OwnedA.php
expect_error 'ambiguous/untracked report path' \
  python3 "$tool" capture --repo "$fixture" --output "$scratch/ambiguous"
git -C "$fixture" rm -qf -- application/OwnedA.php

# Overlapping matches must not multiply estimated removable implementation.
python3 - "$scratch" "$tool" <<'PYTEST'
import copy, importlib.util, json, sys
from pathlib import Path
root = Path(sys.argv[1])
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('metrics', sys.argv[2])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
report = json.loads((root / 'first/clones.json').read_text())
policy = json.loads((root / 'classification.json').read_text())
clone = copy.deepcopy(next(c for c in report['clones'] if policy['clones'][c['id']] == 'first-party'))
clone['id'] = 'overlapping-fixture'
for site in clone['sites']:
    site['start'] += 1
    site['end'] -= 1
    site['code_lines'] = [n for n in site['code_lines'] if site['start'] <= n <= site['end']]
report['clones'].append(clone)
policy['clones'][clone['id']] = 'first-party'
(root / 'overlap-report.json').write_text(json.dumps(report))
(root / 'overlap-policy.json').write_text(json.dumps(policy))
module.census(root / 'overlap-report.json', root / 'overlap-policy.json', root / 'overlap.json')
original = json.loads((root / 'census.json').read_text())
overlap = json.loads((root / 'overlap.json').read_text())
a = next(g for g in original['ranked_groups'] if g['class'] == 'first-party')
b = next(g for g in overlap['ranked_groups'] if g['class'] == 'first-party')
assert a['token_bearing_lines_union'] == b['token_bearing_lines_union']
assert a['optimistic_net_code_lines_upper_bound'] == b['optimistic_net_code_lines_upper_bound']
# Detector corruption cannot silently generate a plausible measured baseline.
for defect in ('invalid-range', 'duplicate-identity'):
    raw = json.loads((root / 'first/jscpd-report.json').read_text())
    if defect == 'invalid-range':
        raw['duplicates'][0]['firstFile']['end'] = 99999
    else:
        raw['duplicates'].append(copy.deepcopy(raw['duplicates'][0]))
    (root / f'{defect}-raw.json').write_text(json.dumps(raw))
PYTEST

# Delegate version/normal scans to the installed real detector; only inject
# malformed report data for error-path tests. This also tests a standalone
# launcher (no sibling npm package.json) without assuming one install layout.
mkdir -p "$scratch/delegate" "$scratch/tool-owner/tools" \
  "$scratch/tool-owner/ci/refactor-metrics/node_modules/.bin"
cp -- "$tool" "$scratch/tool-owner/tools/refactor-metrics.py"
private_tool=$scratch/tool-owner/tools/refactor-metrics.py
private_jscpd=$scratch/tool-owner/ci/refactor-metrics/node_modules/.bin/jscpd
cat >"$scratch/delegate/jscpd" <<'DELEGATE'
#!/usr/bin/env bash
set -euo pipefail
if [[ -z ${METRICS_CORRUPT_REPORT:-} || $1 == --version ]]; then
  exec "$METRICS_REAL_JSCPD" "$@"
fi
while [[ $# -gt 0 ]]; do
  if [[ $1 == --output ]]; then
    mkdir -p "$2"
    cp -- "$METRICS_CORRUPT_REPORT" "$2/jscpd-report.json"
    exit 0
  fi
  shift
done
exit 99
DELEGATE
chmod +x "$scratch/delegate/jscpd"
ln -s "$scratch/delegate/jscpd" "$private_jscpd"
env METRICS_REAL_JSCPD="$real_jscpd" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/standalone" >/dev/null
expect_error 'invalid clone range' \
  env METRICS_REAL_JSCPD="$real_jscpd" \
  METRICS_CORRUPT_REPORT="$scratch/invalid-range-raw.json" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/invalid-range"
expect_error 'duplicate clone identity' \
  env METRICS_REAL_JSCPD="$real_jscpd" \
  METRICS_CORRUPT_REPORT="$scratch/duplicate-identity-raw.json" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/duplicate-identity"

# A different detector version cannot silently create a new baseline.
mkdir "$scratch/bin"
printf '#!/bin/sh\nprintf "cpd 0.0.0\\n"\n' >"$scratch/bin/jscpd"
chmod +x "$scratch/bin/jscpd"
rm -- "$private_jscpd"
ln -s "$scratch/bin/jscpd" "$private_jscpd"
mkdir "$scratch/valid-global"
printf '#!/bin/sh\nprintf "jscpd 5.0.12\\n"\n' >"$scratch/valid-global/jscpd"
chmod +x "$scratch/valid-global/jscpd"
expect_error "require jscpd 5.0.12, got 'cpd 0.0.0'" \
  env PATH="$scratch/valid-global:$PATH" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/wrong-version"

# Even a correct global version cannot replace a missing/non-executable local pin.
rm -- "$private_jscpd"
expect_error 'missing executable module-local jscpd' \
  env PATH="$scratch/valid-global:$PATH" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/missing-local"
printf '#!/bin/sh\nprintf "jscpd 5.0.12\\n"\n' >"$private_jscpd"
chmod 0644 "$private_jscpd"
expect_error 'missing executable module-local jscpd' \
  env PATH="$scratch/valid-global:$PATH" \
  python3 "$private_tool" capture --repo "$fixture" --output "$scratch/nonexecutable-local"
rm -- "$private_jscpd"
ln -s "$real_jscpd" "$private_jscpd"
python3 "$private_tool" capture --repo "$fixture" --output "$scratch/restored" >/dev/null
cmp "$scratch/first/clones.json" "$scratch/restored/clones.json"
printf 'PASS: restored local detector preserves the clone dataset\n'
printf 'ALL PASSED: tracked metrics, exclusions, classification, reproducibility and malformed/error controls\n'
