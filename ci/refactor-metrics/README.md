# Tracked clone baseline

This is the VC-B01/B01b PHP inventory, before production consolidation. It
reproduces the original jscpd 5.0.12 weak-mode PHP scan, at 8 lines / 70
tokens, with roots `src application library` and only `**/Proxies/**`
excluded. It keeps licenses and scans current bytes of tracked regular files
in a clean temporary snapshot. Untracked files (including ignored
dependencies) and generated proxy files cannot enter the scan.
`tracked-manifest.tsv` records mode, SHA-256 and path for tracked files under
the three roots; the baseline separately hashes eligible PHP inputs.

Install Python 3, PHP CLI (lexer), Git and Node/npm, then run
`npm ci --ignore-scripts --prefix ci/refactor-metrics` and put its
`node_modules/.bin` on PATH. The owning static-analysis job provisions
Python/PHP and installs the exact detector through the npm integrity lock.
The command refuses a different detector version. Run from the checkout, with
a new output directory outside the checkout:

```sh
python3 tools/refactor-metrics.py capture --output /tmp/vimbadmin-metrics-new
python3 tools/refactor-metrics.py census \
  --report /tmp/vimbadmin-metrics-new/clones.json \
  --classification ci/refactor-metrics/classification.json \
  --output /tmp/vimbadmin-metrics-new/census.json
bash ci/test-refactor-metrics.sh
```

`baseline.json` identifies commit/tree, working candidate, datasets, history and
tools. The candidate hash includes tracked and nonignored untracked files and
file modes; only the baseline and source-manifest receipt files exclude themselves
to avoid a circular fingerprint. Detailed candidate/tool manifests, raw reports,
source-fragment hashes, token-bearing line sets and history remain in the local
output directory. The tool hashes the installed detector package including its
native binary. The baseline's working candidate hash identifies the capture
point, not subsequent edits.

`classification.json` assigns each source-hashed clone ID to exactly one group.
`census.tsv` makes every baseline site's disposition readable without retaining
raw source snippets. Missing, extra, duplicate or unknown classification entries
fail closed. Vendor, generated, mapping and attribution groups must be retained
and receive zero first-party savings credit. Mapping and vendor are distinct;
vendor similarity remains in the original raw scan, never in first-party credit.
Changed clone sites require deliberate reclassification, not forced old counts.

The 125 matches are overlap records, not 125 independently removable blocks or
new tasks. The 69 candidate records form 18 bounded investigation groups. Their
exact source ranges can include adjacent differing methods. `ranking.tsv` ranks
groups by an optimistic code-line reduction estimate after estimated helper
scaffolding, then recent touch/co-change frequency. These are PHP lexer
non-comment/non-whitespace physical lines, including declarations/braces and
Smarty HTML, **not executable statement counts or proven removable LOC**. Within
a group, overlapping sites are counted once. The estimate keeps the largest site
as the potential shared implementation and subtracts stated helper overhead.
Different groups may overlap: never sum their upper bounds. Recent history is
the last 100 nonmerge commits touching the roots at the recorded HEAD; co-change
means at least two distinct group files changed in the same commit.

Preference accessors are the clearest method-only investigation; larger template,
request and resource-access ranges mix distinct contracts and need narrower
semantic proof. Existing Reader, WithPreferences, MailboxCreationPolicy,
Recipients and resource claims remain reuse points. No whole-program target is
selected here (VC-B01c owns that decision). Production executable LOC, clone
occurrences, wrappers and method complexity are unchanged by this tooling slice.
No runtime/SQL/latency improvement is claimed, and there is no JS/vendor-bundle
savings claim.

The classification JSON is the disposition authority; census/ranking TSVs are
derived baseline views, not independently editable inventories. Recompute them
from the capture and census output when deliberately refreshing this baseline.

The small Python wrapper delegates clone detection to jscpd and line lexing to
PHP. It supplies the missing tracked-only snapshot, exact-input hashing,
classification validation, overlap accounting and fixed-history ranking; it does
not implement a second clone detector or runtime observer. The shell test runs
that maintained path with the pinned real detector, synthetic first-party,
vendor, mapping, generated, untracked and below-threshold samples, repeatability,
overlap and malformed/error cases. The coverage guard and its self-test require
an unconditional workflow owner. Runtime observer extension belongs to VC-B02.

## VC-B01c reduction target and stop rule

This decision replaces the provisional “No whole-program target” statement
above. Target **at least 100 net fewer maintained implementation code lines**
across the whole measured first-party program: tracked `.php`/`.phtml` in
`src application library`, excluding attributed `library/OSS/` and the same
proxy paths. The baseline is **22,247** token-bearing physical lines, including
mapping and template HTML; the target is therefore **22,147 or fewer** after
all scoped production additions, including helpers, wrappers and guards.
This is an absolute PHP/template target, not executable statement LOC or an
unmeasured all-language percentage. Report executable production LOC, clone
occurrences, removed wrappers and changed-method complexity separately under
criterion 1; a physical-line reduction alone does not prove those measures.

Use [baseline.json](baseline.json), [classification.json](classification.json)
and their derived [census](census.tsv) and [ranking](ranking.tsv). Baseline
identity (the capture-point candidate fingerprint remains historical):

- HEAD: `841144d0cb300ac2e3bca3af3c3874e0d091d958`.
- Baseline file SHA-256:
  `d4c8440ce7ff871ffd4750789ac4cc5f69bc3524bab52fb573b83554d36b2c02`.
- Eligible source input SHA-256:
  `06d5bfe26f73fba056d2580777f0f07f39132ff98fa82b853016d6cd041a81d1`.

The supporting subset is narrower than the preference clone ranges: five
scalar setter/getter pairs (`attribute`, `ix`, `op`, `value`, `expire`) plus
`getId`. Exact declarations and bodies are identical across all four owners:

| Source under `application/Entities/` | Method span | Code lines |
| --- | --- | ---: |
| `AdminPreference.php` | 70–187 | 49 |
| `AliasPreference.php` | 72–189 | 49 |
| `DomainPreference.php` | 72–189 | 49 |
| `MailboxPreference.php` | 72–189 | 49 |

Four copies contain 196 code lines. Keeping one 49-line implementation and
allowing eight scaffolding lines (namespace, trait declaration/braces and
four use statements) gives **139 estimated net lines**, with **39 lines of
headroom** over the target. Unlike the ranking's optimistic 145-line bound,
this excludes adjacent owner-method declarations caught by the clone ranges.
Source equality/count evidence is
`/tmp/vimbadmin-c67-target/source-evidence.json`; baseline fixture, mutation
and coverage evidence is
`/tmp/vimbadmin-c67-metrics/VC-B01-B01b-receipt.json` and its named logs.
The 234 tracked source-file hashes and unchanged metric/coverage inputs were
verified for this prose decision; no new source scan or runtime test is claimed.

Accept these identical scalar methods as the target's supporting subset,
subject to VC-E01's persist/reload, null/expiry/index/value, fluent-return,
cross-owner and both lazy-strategy contract proof. Method-only sharing is the
recommendation, not a sealed trait/interface choice or permission to move
mapping, generated IDs or owner associations. VC-E01/E02 retain that decision.
Prefer direct existing reuse for proven identical policy before abstractions:
Reader, WithPreferences, MailboxCreationPolicy, Recipients and resource claims
remain the reuse points. QueueRunner already has orphan stages; file size
alone justifies no new component.

All other candidate ranges remain provisional investigations; the 69 records
are 18 overlapping groups, not additional tasks or additive savings. Credit
none of their bounds toward the target. Keep every retained site's existing
classification reason: mapping/schema, distinct authorization or lifecycle,
ownership/pagination, concrete entity contract, extension point or attribution.
No vendor/generated deletion, changed exclusions, removed tests/docs/licenses
or line packing earns savings. This decision activates none of the work beyond
the current 38 row units and changes no C/E/Q contract or common check G.

For each extraction, stop if its net reduction after all helper costs is
**zero or negative**, or if sharing requires more branching/dispatch,
inheritance, storage or lifecycle complexity than the duplication removed.
Keep the explicit methods in that case. Reaching 100 is no reason to waive
required rows or checks; after every row is delivered or evidence-retained,
all required checks pass and final measurements meet the target, stop further
consolidation. Preserve PHP >=8.2, tests on 8.3/8.4/8.5, ORM 3/DBAL 4 and every
authorization, error, password, schema, lease/cache and other G invariant.

If contract proof or necessary production guards exhaust the headroom, record
the exact retained sites, failing contract or complexity evidence, actual net
result and shortfall against **100** in the existing row disposition and
VC-D01 reconciliation. Report the unmet target explicitly to the supervisor;
changing it requires an explicit recorded acceptance decision. Never silently
lower it, narrow the measurement/checks, force unsafe reuse or activate extra
work to declare completion. No production reduction is claimed by this row.
