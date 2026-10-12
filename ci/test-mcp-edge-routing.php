<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Router.php';
require __DIR__ . '/../src/Kernel/Mvc/AbstractController.php';
require __DIR__ . '/../application/controllers/McpController.php';

use ViMbAdmin\Kernel\Router;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== MCP edge routing policy ==\n";

$config = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
$check('shipped Angie configuration is readable', is_string($config));

$locationPattern = null;
$locationBody = null;
$locationOffset = null;
if (is_string($config) && preg_match(
    '/^\s*location\s+~\s+(\^\/mcp\(\?:\/\|\$\))\s*\{(.*?)^\s*\}/ms',
    $config,
    $matches,
    PREG_OFFSET_CAPTURE
) === 1) {
    $locationPattern = $matches[1][0];
    $locationBody = $matches[2][0];
    $locationOffset = $matches[0][1];
}

$check('MCP routes share one boundary-aware regex location', $locationPattern !== null);
$firstRegexOffset = null;
if (is_string($config) && preg_match('/^\s*location\s+~\*?\s+/m', $config, $match, PREG_OFFSET_CAPTURE) === 1) {
    $firstRegexOffset = $match[0][1];
}
$check(
    'MCP policy precedes every competing regex location',
    $locationOffset !== null && $locationOffset === $firstRegexOffset
);
$check(
    'MCP location keeps the loopback allowlist and default deny',
    is_string($locationBody)
        && preg_match('/^\s*allow\s+127\.0\.0\.1\s*;/m', $locationBody) === 1
        && preg_match('/^\s*allow\s+::1\s*;/m', $locationBody) === 1
        && preg_match('/^\s*deny\s+all\s*;/m', $locationBody) === 1
);
$check(
    'MCP location dispatches through the front controller',
    is_string($locationBody)
        && preg_match(
            '/^\s*fastcgi_param\s+SCRIPT_FILENAME\s+\$document_root\/index\.php\s*;/m',
            $locationBody
        ) === 1
);

$router = new Router(['mcp']);
$appHandles = static function (string $path) use ($router): bool {
    $match = $router->match($path);

    return $match !== null
        && $match->controller === 'mcp'
        && method_exists(McpController::class, $match->actionMethod);
};
$edgeGuards = static function (string $path) use (&$locationPattern): bool {
    return is_string($locationPattern)
        && preg_match('#' . str_replace('#', '\\#', $locationPattern) . '#', $path) === 1;
};

foreach (['/mcp', '/mcp/', '/mcp/index', '/mcp/index/key/value', '/mcp//index'] as $path) {
    $check("application-handled {$path} receives the MCP edge policy", $appHandles($path) && $edgeGuards($path));
}

foreach (['/mcp/missing', '/mcp/%'] as $path) {
    $check("rejected MCP route {$path} still fails behind the edge policy", !$appHandles($path) && $edgeGuards($path));
}

foreach (['/mcpish', '/domain/index'] as $path) {
    $check("non-MCP boundary {$path} does not inherit the MCP policy", !$appHandles($path) && !$edgeGuards($path));
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
