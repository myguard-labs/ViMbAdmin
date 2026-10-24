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
`npm ci --ignore-scripts --prefix ci/refactor-metrics`. The launcher selects
that module-owned `node_modules/.bin/jscpd` directly for its version check,
scan and tool identity, regardless of PATH or the scanned checkout. It fails
with setup instructions if the local executable is missing, and refuses a
different detector version. The owning static-analysis job provisions
Python/PHP and installs the exact detector through the npm integrity lock. Run
from the checkout, with a new output directory outside the checkout:

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

## Final sweep reconciliation (VC-D01)

The final measurement keeps the baseline recipe above unchanged: jscpd 5.0.12
from the project npm integrity lock, weak mode, 8 lines / 70 tokens, roots
`src application library`, and only `**/Proxies/**` excluded. The candidate was
reconstructed in a private Git index so every delivered source file was tracked,
including new helpers; the real delivery index was not used or modified. A fresh
capture of `841144d0cb300ac2e3bca3af3c3874e0d091d958` reproduced every checked-in
baseline aggregate and dataset hash before the final candidate was measured.

<!-- markdownlint-disable MD013 -- dense exact metrics table -->

| Maintained PHP/template measure | Baseline | Final | Delta |
| --- | ---: | ---: | ---: |
| First-party token-bearing physical lines, mapping included | 22,247 | 21,871 | -376 |
| Tracked source files in the three roots | 234 | 238 | +4 |
| jscpd clone occurrences | 125 | 108 | -17 |
| jscpd duplicated lines | 2,868 | 2,183 | -685 |
| jscpd duplicated tokens | 14,274 | 12,131 | -2,143 |
| jscpd scanned lines | 40,268 | 39,645 | -623 |
| jscpd scanned tokens | 195,894 | 193,725 | -2,169 |
| SCC PHP code lines (comments/blanks excluded) | 19,864 | 19,498 | -366 |
| Lizard functions | 1,515 | 1,459 | -56 |
| Lizard aggregate CCN | 5,063 | 4,950 | -113 |
| Lizard average function CCN | 3.3419 | 3.3927 | +0.0508 |

<!-- markdownlint-enable MD013 -->

The final 21,871 lines beat the 22,147 stop threshold by 276 lines, a net
reduction of 376 from the 22,247 baseline. SCC counted the tracked first-party
`.php` files; the target's PHP lexer and Lizard also included `.phtml`. These
tools report physical/token-bearing code, NCSS and static complexity, not
runtime-executed statement counts. Consequently no whole-program executable
statement total is claimed. The higher average CCN is reported rather than
treated as an improvement: total functions and aggregate CCN fell at different
rates. The retained `Mailbox::create` CCN 19 warning also remains; no
complexity-clean claim is made.

The following source sequence makes the net result attributable without adding
overlapping group bounds. Each row is a fresh capture with the same pinned
binary, roots, exclusions and runtime:

<!-- markdownlint-disable MD013 -- dense exact attribution table -->

| Delivered boundary | First-party lines | Line delta | Clone delta | Duplicated-line delta | Duplicated-token delta |
| --- | ---: | ---: | ---: | ---: | ---: |
| Baseline `841144d0` | 22,247 | — | — | — | — |
| PR274 `9c66a490` (fixtures/baselines) | 22,247 | 0 | 0 | 0 | 0 |
| PR276 `26697c13` (controller adapters) | 22,066 | -181 | -8 | -171 | -920 |
| PR277 `ef9e930d` (preference accessors) | 21,943 | -123 | -3 | -395 | -586 |
| PR278 `9522ff42` (queue execution) | 21,937 | -6 | 0 | 0 | 0 |
| PR279 `2a025f3c` (controller resources) | 21,893 | -44 | -3 | -63 | -265 |
| Accepted local candidate | 21,871 | -22 | -3 | -56 | -372 |

<!-- markdownlint-enable MD013 -->

Clone counts describe detector overlap only. They are not independent removable
blocks, runtime speedups or savings. The final source-derived census covers all
108 current occurrences: 97 first-party retained, 7 mapping retained, 3
attribution retained and 1 vendor retained, with zero unresolved candidate
occurrences. Eighty-seven final occurrences retain their earlier group only
after exact path and fragment-hash matching; the 21 changed identities were
classified from their final source ranges. Mapping, attribution and vendor
matches receive zero first-party savings credit.

### Local row accounting

<!-- markdownlint-disable MD013 -- dense exact row accounting -->

| Unit | Final production result | Separate additions or qualifications |
| --- | --- | --- |
| VR-L01 | Characterization only; zero production savings. | Proved the three service log bodies' actor, action, message, optional domain, timestamp, one-persist/no-flush and persist-error contracts. |
| VR-L02 | Service scope: 965 → 942 physical lines (-23), 535 → 521 SCC code lines (-14), 532 → 517 token-bearing/NLOC (-15), and 34,674 → 33,772 bytes (-902). Three log bodies became one trait method, so function count fell 27 → 25 and aggregate SCC complexity 71 → 69. | The shared trait, three uses and call sites are included. Average CCN worsened from 5.0 to 5.2; the unchanged CCN 19 warning remains. No flush, transaction or public service API moved. |
| VC-S01 / VC-S01b | Retain current; no adapter deletion, wrapper reduction or production savings. | Active ORM3/proxy, direct-include, web/CLI/MCP and Smarty reachability prevented a safe deletion. |
| VC-S02 | Selected template scope: 226 → 216 physical lines (-10); target metric -7 token-bearing lines; one clone, 10 duplicated lines and 123 duplicated tokens removed. | The new narrow partial and both include calls are included. Remaining list/modal spans keep their fields, body text, row actions, escaping, placement and skin behavior. |
| VC-S03 | Retain current; zero production deletion/savings. | Added 418 lines of workflow/shell/CJS test wiring; that is not production savings. |
| VC-S03b | Retain current; zero production and test delta. | Modal owners remain distinct after stale/failure/dismiss/reopen and duplicate-handler proof. |
| VC-S03c | Maintained JS: -42 physical lines and -1,657 bytes, including the helper, separator and 14 references. Fourteen equivalent callbacks now use one shared page-length owner; no inline equivalent remains. | Generated bundle +146 bytes and zero lines; its bytes are unchanged by the cache repair, but the maintained URL moved from v29 to v30 so old cached bytes cannot satisfy the new views. The native cache-boundary regression, workflow owner and versioned fixtures are test wiring, not production savings. The scoped detector changed by -2 clones, -38 lines and -335 tokens, but that is advisory and not a latency claim. |
| VC-P01 | Retain current; zero production delta/savings and no performance claim. | Twenty-four query plans and 48 measurements / 296 cases on each of PHP 8.3, 8.4 and 8.5 found no redundant in-scope fetch. Cold means a fresh process/entity manager/result cache with prewarmed MariaDB; RSS is lifetime high-water and excludes network/bootstrap latency. |
| VC-D01 / VC-D01b | Documentation only; zero production savings. | This reconciliation and the ownership/test map below; no behavior test is credited to prose. |

<!-- markdownlint-enable MD013 -->

No performance or hardening improvement is claimed by the local sweep. VC-P01
retains permission, count, row and visible usage queries; changing full entity
hydration, large `IN` plans or SQL shape needs separate admission. Security
evidence proves preservation, not an added hardening gain: authorization, CSRF,
ownership, error text/status, escaping, plugin order, audit actor/domain and
transaction/flush ordering remain the delivered contracts. Deduplication alone
does not establish latency.

## Final ownership and maintained entry points (VC-D01b)

Service mutation policy stays in `ViMbAdmin_Service_Admin`,
`ViMbAdmin_Service_Alias` and `ViMbAdmin_Service_Mailbox`. Their methods still
own authorization-adjacent ordering, entity changes, plugin hooks and caller
flush/transaction boundaries. `PersistsAuditLog` owns only construction and one
`persist()` of the proven audit record; it neither flushes nor starts or commits
a transaction. Domain and archive logging paths remain separate.

Input adapters have narrow owners. `Kernel\Input\Reader` owns scalar/array
parsing primitives, while `DataTableQuery` owns DataTables request normalization
and order-field selection. Controllers retain authentication, transport-specific
errors, response mapping and their explicit defaults.
`ConcreteControllerResources` shares only the concrete entity-manager/Admin
guards; the Alias controller keeps its `EntityManagerInterface` boundary and
repository-specific checks. No generic controller or CRUD dispatch layer was
introduced.

Repositories remain the owners of tenant/permission predicates, count and page
semantics, hydration and sort/filter query shape. In particular,
`Repositories\Mailbox::pagedForMailboxList`,
`Repositories\Domain::pagedForDomainList` and
`Repositories\Archive::pagedForArchiveList` stay behind their corresponding
list-data actions. MCP read operations retain token/allowlist and bounded-list
semantics, and `ViMbAdmin_Service_QueueRunner::scanOrphans` retains its single
batched known-mailbox query. The closeout does not move SQL ownership into
controllers or services.

The maintained focused entry points are:

- `ci/test-refactor-service-audit-log.php` for the shared persist-only audit
  policy, including binding and injected-flush/persist-failure controls;
- `ci/test-refactor-autoload-contract.php` for adapter/autoload/proxy and direct
  include reachability;
- `ci/test-refactor-list-templates.php` for partial rendering, escaping,
  subpaths, actions and containment controls;
- `ci/test-refactor-ui-events.cjs`, `ci/test-maintenance-modal-generation.cjs`,
  `ci/test-asset-cache-version-boundary.sh` and
  `tests/test-datatables-dependency-free-compat.sh` for request, modal,
  table-helper, source/bundle, duplicate/stale event behavior and the real
  cached-v29/current-view failure followed by a fresh v30 subpath load;
- `ci/test-refactor-runtime-baseline.php`, with
  `ci/test-maintenance-query-baseline.php` and
  `ci/maintenance-operation-observer.php`, for 1/50/500/1000 read-path,
  authorization/error, extra-query and query-plan evidence;
- `ci/test-refactor-metrics.sh` for the pinned capture/census path,
  repeatability and malformed/error/negative controls; and
- `/opt/myguard/tools/php-test-matrix --project vimbadmin --cwd "$checkout" --
  bash tests/run-unit-tests.sh` for the complete PHP 8.3/8.4/8.5 unit matrix.

The cache repair changes only the generated JS filename, its header URL and
their affected fixture/workflow/docs bytes; the compiled v30 content is
byte-identical to the fixed v29 candidate and CSS remains unchanged at v29.
The fresh private-index capture includes the untracked service helper, v30
bundle and cache regression while reproducing the same 21,871-line, 108-clone
source-root result and complete 108-occurrence census. Exact accepted receipts
for unchanged PHP, schema, database and runtime inputs remain the reuse
authority. The metrics fixture and Markdown/link gates are rerun for this
documentation change. Standard Psalm's 115 findings across 45 files, the six
taint-baseline findings, unavailable Phan configuration and line/branch coverage,
and 37 standing audit limitations remain explicit. Rendered Smarty/JS syntax
evidence does not imply complete raw-template ESLint/SAST coverage. No 100%,
exhaustive, aggregate-clean or delivery claim follows from this closeout.
