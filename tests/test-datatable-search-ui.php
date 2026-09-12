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
        && preg_match('/recordsFiltered\s*:\s*0\s*,/', $helper) === 1
        && str_contains($helper, "'Enter at least ' + minimum")
        && str_contains($helper, "error === 'parsererror'")
        && str_contains($helper, "'Invalid JSON response'")
        && str_contains($helper, "xhr.readyState === 4")
        && str_contains($helper, "'Ajax error'")
        && !str_contains($helper, 'error: function() { callback( emptyResult ); }'));
$check('shared transport counts Unicode code points like the server',
    is_string($helper)
        && str_contains($helper, "replace(/[\\uD800-\\uDBFF][\\uDC00-\\uDFFF]/g, '_').length"));

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
        && str_contains($bundle, 'vmDataTableLogAjaxError(api,1,"Invalid JSON response")')
        && str_contains($bundle, 'vmDataTableLogAjaxError(api,7,"Ajax error")')
        && !str_contains($bundle, 'error:function(){callback(emptyResult)}'));

$lists = [
    'alias' => ['controller' => 'AliasController', 'resolver' => 'dataTableMinimumSearchLength()', 'fn' => 'vmAliasServerData'],
    'mailbox' => ['controller' => 'MailboxController', 'resolver' => 'dataTableMinimumSearchLength()', 'fn' => 'vmMailboxServerData'],
    'domain' => ['controller' => 'DomainController', 'resolver' => "dataTableMinimumSearchLength('domain')", 'fn' => 'vmDomainServerData'],
    'archive' => ['controller' => 'ArchiveController', 'resolver' => "dataTableMinimumSearchLength('archive')", 'fn' => 'vmArchiveServerData'],
    'log' => ['controller' => 'LogController', 'resolver' => "dataTableMinimumSearchLength('log')", 'fn' => 'vmLogServerData'],
];
foreach ($lists as $list => $contract) {
    $template = file_get_contents(__DIR__ . "/../application/views/{$list}/js/list.js");
    $controller = file_get_contents(__DIR__ . "/../src/Kernel/Controller/{$contract['controller']}.php");
    $check("{$list} browser suppresses short server-side searches", is_string($template)
        && str_contains($template, "'ajax': {$contract['fn']}(")
        && str_contains($template, 'vmDataTableServerData( source, minimum )'));
    $check("{$list} endpoint passes its configured minimum to fromArray", is_string($controller)
        && str_contains($controller, '$minimum = $this->' . $contract['resolver'] . ';')
        && preg_match(
            '/DataTableQuery::fromArray\(\s*(?:self::(?:requestArray|stringMap)\(\$_GET(?:, \'GET data\')?\)|\$request),\s*\$minimum,?\s*\)/',
            $controller,
        ) === 1
        && (preg_match('/DataTableQuery::fromArray\(\s*\$request/', $controller) !== 1
            || str_contains($controller, '$request = self::requestArray($_GET);')));
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
