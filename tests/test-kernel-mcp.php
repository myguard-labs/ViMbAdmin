<?php

require __DIR__ . '/../vendor/autoload.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class McpTestSession implements SessionStorage
{
    public function has(string $key): bool { return false; }
    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value): void {}
    public function remove(string $key): void {}
}

final class McpTestResources
{
    public function __construct(private array $options) {}
    public function getOptions(): array { return $this->options; }
    public function getResource(string $name): object { return new stdClass(); }
}

function controllerFor(array $options): McpController
{
    $auth = new Auth(new McpTestSession(), static fn(int $id): null => null);
    $container = new Container(new McpTestResources($options), $auth);
    $route = new RouteMatch('mcp', 'index', 'McpController', 'indexAction', []);

    return new McpController($container, $route);
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native MCP transport ==\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$disabled = controllerFor(['mcp' => ['enabled' => false]])->indexAction();
check('disabled MCP returns 404', $disabled->status === 404);
check('disabled MCP returns JSON-RPC error', str_contains($disabled->body, '"error"'));

$method = controllerFor(['mcp' => ['enabled' => true]])->indexAction();
check('enabled MCP rejects GET with 405', $method->status === 405);
check('method error remains JSON', str_starts_with($method->contentType, 'application/json'));

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
