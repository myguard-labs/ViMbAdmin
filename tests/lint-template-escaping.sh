#!/usr/bin/env bash
#
# Regression guard: Smarty 5 JS-template escaping.
#
# Background (commits 455af0e, 3fc37ea, lesson
# feedback-vimbadmin-smarty5-js-escaping):
#
#   library/OSS/View/Smarty.php calls setEscapeHtml(true), so EVERY {$var}
#   in a template is run through htmlspecialchars(ENT_QUOTES) by default.
#   In HTML (.phtml) templates that is correct and desirable. In a JavaScript
#   template (application/views/*/js/*.js) it is a footgun: a json_encode()
#   array emitted as {$emails} becomes  [&quot;a@b&quot;]  — a JS syntax error
#   that throws and silently kills the whole <script> block. That is exactly
#   how the alias [+] (add-goto) button died after the Smarty 5 bump.
#
#   The correct shapes inside a .js template are:
#     * {$jsonArray nofilter}              for json_encode() output (raw)
#     * {$stringValue|escape:'javascript'} for a scalar string value
#
# This lint looks for the SPECIFIC dangerous pattern that bit us: a value that
# is assigned from a json_encode() Smarty variable, or fed to a typeahead/
# DataTables `source:` / used as a JS array/object literal, emitted as a bare
# {$var} (no nofilter / no escape:'javascript'). It deliberately does NOT flag
# simple token emits like {$value} / {$item.url} in the DataTables action
# builders — those are server-defined tokens without HTML-special chars and
# have always worked under escaping.
#
# Exit 0 = clean, 1 = a json/array value is emitted into JS unescaped.
#
set -euo pipefail

cd "$(dirname "$0")/.."

fail=0
shopt -s nullglob

echo "== JS templates: json/array values must be nofilter or escape:'javascript' =="

for f in application/views/*/js/*.js; do
    # Lines that look like a JS array/object source being fed a Smarty var:
    #   source: {$emails}      |  data: {$rows}   |  = {$foo}.split(   etc.
    # i.e. a {$var} that sits where a JSON literal is expected.
    hits=$(grep -nE '(source|data|aaData|aoColumns)[[:space:]]*:[[:space:]]*\{\$[A-Za-z_][^}]*\}' "$f" 2>/dev/null \
           | grep -vE 'nofilter|escape:'"'"'javascript'"'"'' || true)
    if [ -n "$hits" ]; then
        echo "  $f:"
        echo "$hits" | sed 's/^/    /'
        echo "    -> emit json_encode() arrays with |nofilter (raw) in a JS context."
        fail=1
    fi
done

if [ "$fail" -eq 0 ]; then
    echo "  OK: no json/array Smarty var emitted unescaped into a JS literal"
fi

exit $fail
