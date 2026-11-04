# Project lint checks

Local agent pre-commit uses the shared MyGuard dispatcher. The repository-root
`.myguard-lint.json` selects explicit retirements; all other applicable tools,
including newly registered tools, keep their normal behavior. This profile does
not install a Git hook. It requires the shared dispatcher version that supports
project profiles; an older dispatcher does not implement these retirements.
The dispatcher must reject malformed profiles and stale profile receipts.

The project keeps these checks:

| Inputs | Retained checks and purpose |
| --- | --- |
| PHP | Syntax, PHPStan types, Psalm taint, PHP-CS-Fixer formatting, PHPCS security and compatibility, Progpilot taint |
| Composer manifests | Lock consistency, validation and dependency vulnerability checks |
| Python CI tools | Ruff, Mypy, Bandit and Pylint correctness diagnostics |
| Shell | ShellCheck and the shared shell-specific Semgrep rules |
| Browser sources | ESLint, Stylelint and HTMLHint where their parsers apply |
| Workflow/YAML | YAML parsing/style, actionlint, workflow traps and Zizmor |
| Containers/nginx/SQL | Hadolint, Trivy, Checkov, GIXY and SQLFluff when matching inputs change |
| Structured data/docs | JSON/TOML/XML parsing, schema validation and Markdown structure |
| Cross-language | Secret detection, dependency CVE checks, Semgrep and ast-grep |

Phan needs a project configuration, which this repository does not have. Other
language and special-purpose tools retain the dispatcher's input applicability;
the profile does not pretend they inspected files they cannot analyze.

Retirements are prioritization decisions, with their unique coverage made explicit:

| Retired check | Reason |
| --- | --- |
| Ordinary Psalm types | `psalm.xml` assigns Psalm taint analysis and PHPStan type analysis; the second full type pass is redundant for this contract. Psalm taint remains. |
| PHPMD | Clean-code, size, design and unused-code heuristics are optional maintainability advice here. Their findings are not claimed covered by another tool. |
| Lizard | Complexity thresholds are optional design advice. |
| Broad jscpd pass | Copy/paste advice is optional. CI retains its pinned PHP clone-contract self-test, which covers a narrower scope. |
| Codespell / Vale | Spelling and prose style are optional; no Vale style rules are configured. |
| shfmt advisory pass | Shell whitespace preferences are optional; ShellCheck remains. |
| Vulture | Heuristic unused-symbol advice for Python helpers is optional; no equivalent dead-code coverage is claimed. |
| EditorConfig advisory pass | Additional whitespace and encoding preferences are optional beyond the retained parsers and language formatters. |

Pylint remains enabled. `.pylintrc` removes only specific layout and numeric
design thresholds. Error/fatal diagnostics, warnings such as dangerous mutable
defaults, cyclic imports and resource-management recommendations remain enabled.

CI installs only the PHP analyzer used by each static job. The formatter needs
its verified PHAR and PHP CLI, without Composer or application extensions. The
legacy `static` setup profile retains all tools for callers that still use it.
`ci/test-php-setup-profiles.py` verifies selection and failure propagation.
PHAR downloads use bounded connection, transfer and retry settings; every
success or failure removes its owned temporary file, with checksum verification
required before installation.

CI also runs ast-grep against a snapshot of every tracked regular source in the
project's PHP/PHTML, shell, HTML, JavaScript/JSX, TypeScript and Python scope.
Tracked TSX fails explicitly because the pinned corpus has no native TSX rules;
this project currently contains no tracked TSX. Its
Linux x64 engine and public rule corpus are immutable pins; the native engine's
checksum is verified after npm's locked integrity checks. Utility rules are included;
the pinned corpus has no generic/regex pack. Missing engines, invalid rules,
wrong versions, empty input and incomplete scanning fail the job. Matches are
review candidates, consistent with local pre-commit; the existing Semgrep
findings gate remains unchanged. The artifact retains every finding's rule,
location and severity, with source/rule hashes and actual discovery counts.
Source snippets, captured values and expanded messages are omitted to avoid
publishing secrets. Each finding contains only `ruleId`, `file`, `range` and
`severity`. `ci/test-ast-grep.py` verifies representative real-engine detections
for every advertised format, clean input, explicit TSX refusal, serialized-output
source-value exclusion and failure paths using the real locked scanner.
