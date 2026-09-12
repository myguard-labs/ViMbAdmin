<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tests/render-control-behaviour-fixture.php OUTPUT BUNDLE_URI\n");
    exit(2);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

// This fixture does not exercise translations. The pinned PHP 8.4
// browser-support image intentionally omits gettext, so provide the identity
// behavior that the rendered template needs without masking production gates.
if (!function_exists('_')) {
    function _(string $message): string
    {
        return $message;
    }
}

$output = $argv[1];
// Directory holding the copies of public/js/* this fixture is to load. The
// driving script stages them there; see the SOURCE, NOT THE BUNDLE note below.
$scriptDir = rtrim($argv[2], '/');
$tmp = dirname($output);
$stubs = $tmp . '/template-stubs';
$compile = $tmp . '/templates-c';

foreach ([$stubs, $compile] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create {$dir}");
    }
}

// A minimal header/footer: real production markup this fixture does not need
// (nav, CSP nonce wiring for stylesheets, etc.) would just be noise here. The
// header stub still emits the CSP nonce placeholder resolution the inline
// <script> tags in alias/list.phtml and alias/js/list.js depend on, and a
// data-test-result marker the driving script updates once it has verdicts for
// every asserted control.
//
// SOURCE, NOT THE BUNDLE.
//
// The script list is PARSED FROM application/views/header-js.phtml's
// non-minified branch rather than duplicated here, so a script added to or
// removed from the application cannot silently drift out of this gate's
// coverage. An unparsable list, a suspiciously short one, or a listed file
// that was not staged is a HARD FAILURE -- never a silently script-less page,
// which would fail every behavioural assertion for the wrong reason and read
// exactly like a real regression.
$headerJs = __DIR__ . '/../application/views/header-js.phtml';
$headerJsSrc = @file_get_contents($headerJs);
if ($headerJsSrc === false) {
    throw new RuntimeException("could not read {$headerJs}");
}
// Only the {else} (non-minified) branch: that is the authoritative,
// developer-facing load order, and it is what a source-level gate must mirror.
$elsePos = strpos($headerJsSrc, '{else}');
$endPos = $elsePos === false ? false : strpos($headerJsSrc, '{/if}', $elsePos);
if ($elsePos === false || $endPos === false) {
    throw new RuntimeException(
        "could not locate the non-minified {else}...{/if} branch in {$headerJs}"
    );
}
$branch = substr($headerJsSrc, $elsePos, $endPos - $elsePos);
if (preg_match_all('#\{genUrl\}/js/([A-Za-z0-9._-]+\.js)#', $branch, $m) === false) {
    throw new RuntimeException("script-list match failed against {$headerJs}");
}
$scripts = $m[1];
if (count($scripts) < 2) {
    throw new RuntimeException(
        'parsed ' . count($scripts) . ' scripts from the non-minified branch of '
        . $headerJs . '; refusing to render a script-less page that would fail '
        . 'every behavioural assertion for the wrong reason'
    );
}
foreach ($scripts as $script) {
    if (!is_file($scriptDir . '/' . $script)) {
        throw new RuntimeException(
            "header-js.phtml lists {$script} but it was not staged in {$scriptDir}"
        );
    }
}

$header = '<!doctype html><meta charset="utf-8">';
foreach ($scripts as $script) {
    $header .= '<script src="'
        . htmlspecialchars(
            'file://' . $scriptDir . '/' . $script,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )
        . '"></script>';
}
$header .= '<body data-test-result="pending">';
if (file_put_contents($stubs . '/header.phtml', $header) === false
    || file_put_contents($stubs . '/footer.phtml', '') === false) {
    throw new RuntimeException('could not write template stubs');
}

$smarty = new \Smarty\Smarty();
$smarty->setEscapeHtml(true);
$smarty->setTemplateDir([$stubs, __DIR__ . '/../application/views']);
$smarty->setCompileDir($compile);
@$smarty->addPluginsDir([
    __DIR__ . '/../library/ViMbAdmin/Smarty/functions',
    __DIR__ . '/../library/OSS/Smarty/functions',
]);
foreach (['strlen', 'count', 'in_array', 'is_array'] as $modifier) {
    $smarty->registerPlugin('modifier', $modifier, $modifier, true);
}

// alias/list.phtml is the anchor page: it statically renders both controls
// under test (the #purge_dialog modal, and via {tmplinclude}-inlined
// alias/js/list.js, the DataTables draw callback that wires up the delete
// button which opens that modal) with no additional plugin or runtime data
// dependency. It deliberately does NOT set $alias_actions -- see the
// exemption note in tests/test-control-behaviour-rendering.sh for why a
// second dropdown/tab control on this same page is out of scope, not merely
// unexercised.
$smarty->assign([
    'session' => new stdClass(),
    'ima' => 0,
    'options' => [
        'defaults' => [
            'server_side' => ['pagination' => ['enable' => false]],
            'table' => ['entries' => 10],
        ],
    ],
    'aliases' => [[
        'id' => 41,
        'address' => 'control-behaviour-regression@example.com',
        'domain' => 'example.com',
        'active' => true,
        'goto' => 'destination@example.com',
    ]],
    'csrfToken' => 'test-csrf-token',
]);

$rendered = $smarty->fetch('alias/list.phtml');
if (file_put_contents($output, $rendered) === false) {
    throw new RuntimeException("could not write {$output}");
}
