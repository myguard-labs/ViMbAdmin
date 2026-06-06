<?php
/**
 * Smoke test: the native Smarty view (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Builds a SmartyView over a temp template dir and proves the plumbing the
 * native controllers rely on: magic-property var assignment, render() of a real
 * template, auto HTML-escaping, and skin resolution (a _skins/<skin>/ copy wins
 * over the default). Uses the real \Smarty\Smarty engine from vendor — the full
 * chrome/OSS-plugin render is validated in the image when the native bootstrap
 * wires this in.
 *
 * Runs in the cache-wiring CI job (vendor present). Exit 0 = pass, non-zero = fail.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/../src/Kernel/View/SmartyView.php';

use ViMbAdmin\Kernel\View\SmartyView;

if (!class_exists(\Smarty\Smarty::class)) {
    echo "SKIP Smarty 5 not available\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/vmb-smartyview-' . getmypid();
@mkdir($tmp . '/tpl/_skins/myskin', 0770, true);
@mkdir($tmp . '/compile', 0770, true);
file_put_contents($tmp . '/tpl/hello.tpl', 'Hello {$name}!');
file_put_contents($tmp . '/tpl/raw.tpl', '{$html}');
file_put_contents($tmp . '/tpl/skinned.tpl', 'DEFAULT {$name}');
file_put_contents($tmp . '/tpl/_skins/myskin/skinned.tpl', 'SKIN {$name}');

$failures = 0;
function check(string $label, callable $fn): void {
    global $failures;
    try { $fn(); echo "OK   $label\n"; }
    catch (\Throwable $e) { $failures++; printf("FAIL %s :: %s: %s\n", $label, get_class($e), $e->getMessage()); }
}

$mk = fn() => new SmartyView(['templates' => $tmp . '/tpl', 'compiled' => $tmp . '/compile']);

check('magic __set + render a template', function () use ($mk) {
    $v = $mk();
    $v->name = 'World';
    if (trim($v->render('hello.tpl')) !== 'Hello World!') {
        throw new RuntimeException('got: ' . $v->render('hello.tpl'));
    }
});

check('auto HTML-escape on by default', function () use ($mk) {
    $v = $mk();
    $v->html = '<b>x</b>';
    $out = $v->render('raw.tpl');
    if (str_contains($out, '<b>')) {
        throw new RuntimeException('not escaped: ' . $out);
    }
    if (!str_contains($out, '&lt;b&gt;')) {
        throw new RuntimeException('unexpected escape output: ' . $out);
    }
});

check('compile dir is created when missing', function () use ($tmp) {
    $dir = $tmp . '/compile-fresh';
    @rmdir($dir);
    new SmartyView(['templates' => $tmp . '/tpl', 'compiled' => $dir]);
    if (!is_dir($dir)) {
        throw new RuntimeException('compile dir not created');
    }
});

check('default template used when no skin set', function () use ($mk) {
    $v = $mk();
    $v->name = 'Z';
    if (trim($v->render('skinned.tpl')) !== 'DEFAULT Z') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
});

check('skin override wins when skin set + file present', function () use ($mk) {
    $v = $mk();
    $v->setSkin('myskin');
    if ($v->getSkin() !== 'myskin') {
        throw new RuntimeException('skin not set');
    }
    if ($v->resolveTemplate('skinned.tpl') !== '_skins/myskin/skinned.tpl') {
        throw new RuntimeException('resolve: ' . $v->resolveTemplate('skinned.tpl'));
    }
    $v->name = 'Z';
    if (trim($v->render('skinned.tpl')) !== 'SKIN Z') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
});

check('skin with no override falls back to default file', function () use ($mk) {
    $v = $mk();
    $v->setSkin('myskin');
    // hello.tpl has no _skins/myskin copy -> default resolves.
    if ($v->resolveTemplate('hello.tpl') !== 'hello.tpl') {
        throw new RuntimeException('resolve: ' . $v->resolveTemplate('hello.tpl'));
    }
});

check('unknown skin throws', function () use ($mk) {
    try {
        $mk()->setSkin('does-not-exist');
    } catch (\RuntimeException) {
        return;
    }
    throw new RuntimeException('expected throw for unknown skin');
});

check('fromOptions reads resources.smarty.*', function () use ($tmp) {
    $v = SmartyView::fromOptions(['resources' => ['smarty' => [
        'templates' => $tmp . '/tpl',
        'compiled'  => $tmp . '/compile',
        'skin'      => 'myskin',
    ]]]);
    $v->name = 'Q';
    if (trim($v->render('skinned.tpl')) !== 'SKIN Q') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
});

// cleanup
array_map('unlink', glob($tmp . '/tpl/*.tpl') ?: []);
array_map('unlink', glob($tmp . '/tpl/_skins/myskin/*.tpl') ?: []);

echo 'PHP ' . PHP_VERSION . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
