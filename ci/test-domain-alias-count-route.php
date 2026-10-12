<?php

declare(strict_types=1);

require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Router.php';
require __DIR__ . '/../src/Kernel/Http/Kernel.php';

use ViMbAdmin\Kernel\Http\Kernel;
use ViMbAdmin\Kernel\Router;

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$aliasListController = static function (string $source): string {
    if (preg_match('/function formatAliases\s*\([^)]*\)\s*\{(.*?)\n\}/s', $source, $function) !== 1) {
        throw new RuntimeException('formatAliases function is missing or malformed');
    }

    $body = str_replace('\\"', '"', $function[1]);
    preg_match_all(
        '/\{genUrl\s+controller=(["\'])([^"\']+)\1\s+action=(["\'])list\3\}/',
        $body,
        $routes,
    );
    if (count($routes[2]) !== 1) {
        throw new RuntimeException('formatAliases must contain exactly one alias-count list route');
    }

    return $routes[2][0];
};

$root = dirname(__DIR__);
$source = file_get_contents($root . '/application/views/domain/js/list.js');
$config = file_get_contents($root . '/application/configs/application.ini.dist');
$check('error: domain list template and default configuration are readable', is_string($source) && is_string($config));

$controller = null;
try {
    $controller = is_string($source) ? $aliasListController($source) : null;
} catch (RuntimeException $exception) {
    echo '  INFO route extraction error: ' . $exception->getMessage() . "\n";
}

$nativeControllers = Kernel::nativeControllers();
$router = new Router($nativeControllers);
$defaultEnabled = is_string($config)
    && preg_match('/^defaults\.server_side\.pagination\.domain\.enable\s*=\s*true\s*$/m', $config) === 1;
$route = is_string($controller) ? $router->match("/{$controller}/list/did/42") : null;

$check(
    'positive: default domain-list alias-count route uses the registered alias controller',
    $defaultEnabled && $controller === 'alias' && $route?->controller === 'alias' && $route->params === ['did' => '42'],
);
$check(
    'boundary: alias route is allowlisted while its plural lookalike is not',
    in_array('alias', $nativeControllers, true)
        && !in_array('aliases', $nativeControllers, true)
        && $router->match('/alias/list/did/0') !== null
        && $router->match('/aliases/list/did/0') === null,
);

$malformedRejected = false;
try {
    $aliasListController('function formatAliases(id) { return id; }');
} catch (RuntimeException) {
    $malformedRejected = true;
}
$check('malformed: missing generated list route is rejected', $malformedRejected);

$ambiguousRejected = false;
try {
    $aliasListController(<<<'JS'
function formatAliases(id)
{
    return '{genUrl controller="alias" action="list"}'
        + '{genUrl controller="alias" action="list"}' + id;
}
JS);
} catch (RuntimeException) {
    $ambiguousRejected = true;
}
$check('error: duplicate generated list routes are rejected', $ambiguousRejected);
$check(
    'negative: the broken plural alias-count route remains unrouteable',
    $router->match('/aliases/list/did/42') === null,
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
