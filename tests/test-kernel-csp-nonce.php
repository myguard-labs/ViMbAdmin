<?php
/**
 * Unit test: ViMbAdmin\Kernel\Security\ContentSecurityPolicy (VIM-D07).
 *
 * Pure logic with no framework, database or Composer install: it requires the
 * source file directly. Covers the three properties the policy exists for —
 * the nonce is fresh per response, script-src carries that nonce, and script-src
 * no longer carries 'unsafe-inline' — plus the template-side contract that every
 * inline <script> in the shipped views actually stamps the nonce.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Security/ContentSecurityPolicy.php';

use ViMbAdmin\Kernel\Security\ContentSecurityPolicy;

/** Failure counter, in a class so the type is not `mixed` under static analysis. */
final class CspNonceTestState
{
    public static int $failures = 0;
}

function check(string $label, bool $ok): void
{
    if ($ok) {
        return;
    }
    CspNonceTestState::$failures++;
    fwrite(STDERR, "FAIL: $label\n");
}

// --- the nonce is fresh per response -------------------------------------
// Two policies are two responses. A generator that returned a constant — or
// that memoised across instances — would collide here.
$seen = [];
for ($i = 0; $i < 64; $i++) {
    $seen[] = (new ContentSecurityPolicy())->nonce();
}
check('nonce differs between two responses', $seen[0] !== $seen[1]);
check('64 generated nonces are all distinct', count(array_unique($seen)) === 64);

// Fresh, but stable within one response: the header and the value handed to
// the views must be the same string or every inline script is blocked.
$policy = new ContentSecurityPolicy();
check('nonce() is stable within one response', $policy->nonce() === $policy->nonce());
check('header() is stable within one response', $policy->header() === $policy->header());

// --- the nonce is cryptographically sized --------------------------------
$raw = base64_decode($policy->nonce(), true);
check('nonce is valid base64', $raw !== false);
check('nonce carries 128 bits of entropy', is_string($raw) && strlen($raw) === 16);

// --- the emitted header ---------------------------------------------------
$header = $policy->header();
check(
    "header contains 'nonce-<value>'",
    str_contains($header, "'nonce-" . $policy->nonce() . "'")
);

// script-src must not admit inline script by blanket allowance any more. Slice
// the directive out rather than searching the whole policy, because style-src
// deliberately keeps 'unsafe-inline' and would mask the assertion.
$directives = [];
foreach (explode(';', $header) as $directive) {
    $parts = preg_split('/\s+/', trim($directive), 2);
    if (is_array($parts) && $parts[0] !== '') {
        $directives[$parts[0]] = $parts[1] ?? '';
    }
}
check('policy declares script-src', array_key_exists('script-src', $directives));
$scriptSrc = $directives['script-src'] ?? '';
check("script-src does not contain 'unsafe-inline'", !str_contains($scriptSrc, "'unsafe-inline'"));
check("script-src does not contain 'unsafe-eval'", !str_contains($scriptSrc, "'unsafe-eval'"));
check("script-src keeps 'self'", str_contains($scriptSrc, "'self'"));
check('script-src carries the nonce', str_contains($scriptSrc, "'nonce-" . $policy->nonce() . "'"));

// This item is script-src only; style-src keeps 'unsafe-inline' deliberately.
check(
    "style-src still allows 'unsafe-inline'",
    str_contains($directives['style-src'] ?? '', "'unsafe-inline'")
);

// The other hardening directives must survive the rewrite.
foreach (['default-src', 'img-src', 'font-src', 'connect-src', 'form-action', 'frame-ancestors', 'base-uri', 'object-src'] as $required) {
    check("policy retains $required", array_key_exists($required, $directives));
}

// Header map shape, as handed to Response.
$headers = $policy->headers();
check('headers() is keyed by the CSP header name', array_keys($headers) === ['Content-Security-Policy']);
check('headers() carries the policy', ($headers['Content-Security-Policy'] ?? null) === $header);

// A header value must never contain a CR/LF that could split the response.
check('header has no CR/LF', preg_match('/[\r\n]/', $header) !== 1);
check('nonce has no CR/LF or quote', preg_match('/["\'\r\n<>]/', $policy->nonce()) !== 1);

// --- the view contract ----------------------------------------------------
// A nonced policy is only safe if every inline <script> in the shipped views
// stamps it; an unstamped one silently stops working. External `src=` tags need
// no nonce under script-src 'self' and must not be flagged.
/** @var list<string> $views */
$views = [];
/** @var iterable<string,SplFileInfo> $it */
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../application/views', FilesystemIterator::SKIP_DOTS)
);
/** @var list<string> $viewJs */
$viewJs = [];
foreach ($it as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    if (str_ends_with($file->getFilename(), '.phtml')) {
        $views[] = $file->getPathname();
    } elseif (str_ends_with($file->getFilename(), '.js')) {
        $viewJs[] = $file->getPathname();
    }
}
check('view scan found templates', count($views) > 0);

// A .js fragment counts as a template only when its first meaningful token is
// a <script> tag. The tag name is delimited so a near miss like <script-data>
// is not mistaken for one, and the BOM is matched as raw bytes rather than
// under /u so an invalid-UTF-8 fragment cannot make preg_match() return false
// and silently reinstate the blind spot this anchor exists to close.
$anchor = '/\A(?:\xEF\xBB\xBF)?\s*(?:\{\*.*?\*\}\s*)*<script(?=[\s\/>])/is';

// Pin the anchor's behaviour directly, so the cases below stay covered without
// fixture files on disk: [body, is-a-template].
foreach ([
    ["<script>x</script>", true],
    ["\xEF\xBB\xBF<script>x</script>", true],
    ["{* smarty *}\n<script>x</script>", true],
    ["\xEF\xBB\xBF{* a *}{* b *}\n<script >x</script>", true],
    ["<script-data>x</script-data>", false],
    ["// prose mentioning <script> further down", false],
] as $i => $case) {
    [$body, $want] = $case;
    check("fragment anchor case {$i}", preg_match($anchor, $body) === ($want ? 1 : 0));
}

// A .js file under application/views/ can be a template fragment that opens
// with a literal <script> tag, so the inline-script scan must cover those too:
// one that is included but unstamped is silently blocked by the nonce-only
// script-src, and scanning only .phtml would never see it.
$unstamped = [];
$inlineCount = 0;
$inlineScanTargets = $views;
foreach ($viewJs as $jsPath) {
    $jsBody = file_get_contents($jsPath);
    // Only a .js fragment that IS a template -- one whose very first
    // meaningful token is a <script> tag -- carries an inline script. A prose
    // mention of <script> inside a comment further down is not one, so anchor
    // rather than scanning the whole body and flagging documentation.
    //
    // The anchor still has to tolerate what a real template may legally put in
    // front of that tag: a UTF-8 BOM, and any run of Smarty {* ... *} comments.
    // Neither is executable content, so skipping such a fragment would let an
    // unstamped inline script through this gate unseen (VIM-D16).
    // The BOM is matched as raw bytes rather than under /u, so an invalid-UTF-8
    // fragment cannot make preg_match() return false and silently reinstate the
    // blind spot this anchor exists to close.
    if (!is_string($jsBody)) {
        continue;
    }
    $isTemplate = preg_match($anchor, $jsBody);
    check("inline-script anchor evaluated for {$jsPath}", $isTemplate !== false);
    if ($isTemplate === 1) {
        $inlineScanTargets[] = $jsPath;
    }
}
foreach ($inlineScanTargets as $view) {
    $body = file_get_contents($view);
    if (!is_string($body)) {
        continue;
    }
    if (preg_match_all('/<script\b[^>]*>/i', $body, $m) === false) {
        continue;
    }
    foreach ($m[0] as $tag) {
        if (preg_match('/\bsrc\s*=/i', $tag) === 1) {
            continue; // external file: covered by script-src 'self'
        }
        $inlineCount++;
        if (preg_match('/\bnonce\s*=\s*"\{\$cspNonce\}"/', $tag) !== 1) {
            $unstamped[] = basename(dirname($view)) . '/' . basename($view) . ': ' . $tag;
        }
    }
}
check('the views do carry inline scripts to protect', $inlineCount > 0);
check(
    'every inline <script> in the views stamps the nonce (' . implode(', ', $unstamped) . ')',
    $unstamped === []
);

// --- no inline event handlers ---------------------------------------------
// A CSP nonce whitelists <script> elements only; it does NOT whitelist inline
// event-handler attributes, which 'unsafe-inline' used to permit. So a single
// on*= attribute creeping back into a view is a silently dead handler --
// including the confirm() guards on destructive actions. Scan the view JS files
// too: several build HTML strings that used to carry the attributes.
/** @var list<string> $handlerSources */
$handlerSources = array_merge($views, $viewJs);

$withHandlers = [];
foreach ($handlerSources as $source) {
    $body = file_get_contents($source);
    if (!is_string($body)) {
        continue;
    }
    // Attribute position only: preceded by whitespace and followed by `=`, so
    // JS like `el.onclick` or a prose mention of onsubmit does not match.
    $handlerHits = preg_match_all('/\son(?:click|submit|change|load|error|keyup|keydown|blur|focus|mouseover|mouseout|input|dblclick)\s*=/i', $body, $hits);
    if ($handlerHits !== false && $handlerHits > 0) {
        $withHandlers[] = basename(dirname($source)) . '/' . basename($source)
            . ' (' . implode(' ', array_unique($hits[0])) . ')';
    }
}
check(
    'no inline on*= event handler remains in application/views (' . implode(', ', $withHandlers) . ')',
    $withHandlers === []
);

// The confirmations they carried must still exist, as data-confirm attributes
// enforced by the delegated guard. A migration that dropped them instead of
// moving them would leave destructive actions unguarded.
$confirmCount = 0;
foreach ($handlerSources as $source) {
    $body = file_get_contents($source);
    if (is_string($body)) {
        $confirmCount += substr_count($body, 'data-confirm="');
    }
}
check('the destructive-action confirmations survived the migration', $confirmCount >= 25);

// And the guard that enforces them must ship.
$appJs = file_get_contents(__DIR__ . '/../public/js/990-vimbadmin.js');
check('990-vimbadmin.js readable', is_string($appJs));
if (is_string($appJs)) {
    check(
        'a delegated submit guard binds form[data-confirm]',
        str_contains($appJs, "'submit', 'form[data-confirm]'")
    );
    check(
        'the guard blocks the submit when confirm() is declined',
        str_contains($appJs, 'if ( !window.confirm( message ) )')
            && str_contains($appJs, 'event.preventDefault();')
    );
}

// Production serves the prebuilt bundle when use_minified_js is on, so the guard
// has to be in there too -- shipping it only in 990-vimbadmin.js would leave
// every minified deployment with unguarded destructive actions.
$bundle = file_get_contents(__DIR__ . '/../public/js/min.bundle-v24.js');
check('min.bundle-v24.js readable', is_string($bundle));
if (is_string($bundle)) {
    check(
        'the minified bundle carries the delegated confirm guard',
        str_contains($bundle, 'form[data-confirm]') && str_contains($bundle, 'window.confirm(')
    );
}

// The ajax-loaded modal fragment must ship no script at all: it is injected into
// an already-loaded page, so it could only ever carry the wrong nonce.
$fragment = file_get_contents(__DIR__ . '/../application/views/mailbox/native-email-settings.phtml');
check('email-settings fragment readable', is_string($fragment));
check(
    'the ajax email-settings fragment carries no <script>',
    is_string($fragment) && stripos($fragment, '<script') === false
);

// The shipped Angie config must not also emit a dynamic-HTML CSP: a second
// add_header would replace the application's nonced policy on those responses.
$conf = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
check('angie config readable', is_string($conf));
if (is_string($conf)) {
    $serverScope = 0;
    foreach (explode("\n", $conf) as $line) {
        if (preg_match('/^\s{0,4}add_header\s+Content-Security-Policy/', $line) === 1) {
            $serverScope++;
        }
    }
    check('angie no longer sets a server-scope (dynamic HTML) CSP', $serverScope === 0);
    check(
        "the static-asset CSP dropped 'unsafe-inline' from script-src",
        !str_contains($conf, "script-src 'self' 'unsafe-inline'")
    );
}

if (CspNonceTestState::$failures === 0) {
    echo "OK: all ContentSecurityPolicy assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}

echo 'FAIL: ' . CspNonceTestState::$failures . " assertion(s) failed\n";
exit(1);
