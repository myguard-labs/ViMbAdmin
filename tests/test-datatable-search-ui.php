<?php

declare(strict_types=1);

require __DIR__ . '/support/resolve-bundle-v.php';

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

echo "== DataTable minimum-search browser contract ==\n";

$helper = file_get_contents(__DIR__ . '/../public/js/990-vimbadmin.js');
$check('shared transport suppresses short nonempty server requests with feedback',
    is_string($helper)
        && str_contains($helper, 'searchLength > 0 && searchLength < minimum')
        && str_contains($helper, 'iTotalDisplayRecords: 0, aaData: []')
        && str_contains($helper, "'Enter at least ' + minimum + ' characters to search.'")
        && str_contains($helper, "error === 'parsererror' ? 'Invalid JSON response' : 'Ajax error'")
        && !str_contains($helper, 'error: function() { callback( emptyResult ); }'));
$check('shared transport counts Unicode code points like the server',
    is_string($helper)
        && str_contains($helper, "replace( /[\\uD800-\\uDBFF][\\uDC00-\\uDFFF]/g, '_' ).length"));

try {
    $bundleFile = resolveBundleV();
    $bundlePath = __DIR__ . '/../public/js/' . $bundleFile;
    $bundle = file_get_contents($bundlePath);
} catch (RuntimeException $e) {
    $bundle = null;
}

$check('production minified bundle exposes the shared transport',
    is_string($bundle)
        && str_contains($bundle, 'function vmDataTableServerData(')
        && str_contains($bundle, 'error==="parsererror"?"Invalid JSON response":"Ajax error"')
        && !str_contains($bundle, 'error:function(){callback(emptyResult)}'));

$lists = [
    'alias' => ['controller' => 'AliasController', 'resolver' => 'dataTableMinimumSearchLength()'],
    'mailbox' => ['controller' => 'MailboxController', 'resolver' => 'dataTableMinimumSearchLength()'],
    'domain' => ['controller' => 'DomainController', 'resolver' => "dataTableMinimumSearchLength('domain')"],
    'archive' => ['controller' => 'ArchiveController', 'resolver' => "dataTableMinimumSearchLength('archive')"],
    'log' => ['controller' => 'LogController', 'resolver' => "dataTableMinimumSearchLength('log')"],
];
foreach ($lists as $list => $contract) {
    $template = file_get_contents(__DIR__ . "/../application/views/{$list}/js/list.js");
    $controller = file_get_contents(__DIR__ . "/../src/Kernel/Controller/{$contract['controller']}.php");
    $check("{$list} browser suppresses short server-side searches", is_string($template)
        && str_contains($template, "'fnServerData': vm")
        && str_contains($template, 'vmDataTableServerData( source, data, callback, minimum')
        && str_contains($template, "'#list_table', settings"));
    $check("{$list} endpoint passes its configured minimum to fromArray", is_string($controller)
        && preg_match(
            '/DataTableQuery::fromArray\(\s*(?:self::[^\n]+\n\s*)?,?\s*\$this->'
                . preg_quote($contract['resolver'], '/') . ',?\s*\)/',
            $controller,
        ) === 1);
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
