<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Entities/Domain.php';

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class DataTableWireSession implements SessionStorage
{
    /** @var array<string,mixed> */
    private array $values = ['identity' => ['id' => 1]];
    #[Override]
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    #[Override]
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    #[Override]
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    #[Override]
    public function remove(string $key): void { unset($this->values[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __isset(string $key): bool { return $this->has($key); }
}

final class DataTableWireResources
{
    public int $doctrineReads = 0;
    public bool $brokenConfig = false;
    public function __construct(private DataTableWireSession $session) {}
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        if ($this->brokenConfig) throw new TypeError('Internal config failure');
        return [];
    }
    public function getResource(string $name): object
    {
        if ($name === 'namespace') return $this->session;
        if ($name === 'doctrine2') {
            $this->doctrineReads++;
            throw new TypeError('Internal repository failure');
        }
        throw new LogicException('Unexpected resource: ' . $name);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};

foreach (['Domain', 'Mailbox', 'Alias', 'Archive', 'Log'] as $name) {
    $boundary = new ReflectionMethod('ViMbAdmin\\Kernel\\Controller\\' . $name . 'Controller',
        $name === 'Log' ? 'stringMap' : 'requestArray');
    $parse = static function (string $encoded) use ($boundary, $name): DataTableQuery {
        parse_str($encoded, $request);
        $parameters = $name === 'Log'
            ? $boundary->invoke(null, $request, 'GET data')
            : $boundary->invoke(null, $request);
        if (!is_array($parameters)) throw new RuntimeException('Controller boundary must return an array');
        $typedParameters = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key)) throw new RuntimeException('Controller boundary must return string keys');
            $typedParameters[$key] = $value;
        }
        return DataTableQuery::fromArray($typedParameters, 3);
    };
    $query = $parse('draw=7&start=20&length=25&search[value]=%2Aabc&search[regex]=false'
        . '&order[0][column]=2&order[0][dir]=desc&columns[0][data]=id&columns[0][search][value]=');
    $check($name . ': real URL encoding crosses the controller boundary',
        $query->draw === 7 && $query->start === 20 && $query->length === 25
        && $query->searchTerm === 'abc' && $query->contains
        && $query->sortColumn === 2 && $query->sortDir === 'DESC');
    foreach (['search=abc', 'search[value][]=abc', 'order=desc', 'order[0]=desc',
        'order[0][column][]=2', 'order[0][dir][]=desc'] as $malformed) {
        $rejected = false;
        try {
            $parse($malformed);
        } catch (TypeError) {
            $rejected = true;
        }
        $check($name . ': rejects ' . $malformed, $rejected);
    }

    $session = new DataTableWireSession();
    $resources = new DataTableWireResources($session);
    $admin = (new Entities\Admin())->setUsername('admin@example.test')->setSuper(true)->setActive(true);
    $container = new Container($resources, new Auth($session, static fn(int $id): Entities\Admin => $admin));
    $class = $boundary->getDeclaringClass();
    $controller = $class->newInstance($container,
        new RouteMatch(strtolower($name), 'list-data', $class->getName(), 'listDataAction', []));
    $action = $class->getMethod('listDataAction');
    $oldGet = $_GET;
    try {
        foreach (['search' => ['search' => ['value' => ['abc']]],
            'order' => ['order' => [['dir' => ['desc']]]]] as $shape => $request) {
            $_GET = $request;
            try {
                $response = $action->invoke($controller);
            } catch (TypeError) {
                $response = null;
            }
            $check($name . ': malformed nested ' . $shape . ' returns 400 before repository access',
                $response instanceof Response && $response->status === 400
                && $response->body === 'Invalid DataTables request' && $resources->doctrineReads === 0);
        }
        // Internal failures must keep propagating even when the request is bad.
        $resources->brokenConfig = true;
        $configFailed = false;
        try {
            $action->invoke($controller);
        } catch (TypeError $e) {
            $configFailed = $e->getMessage() === 'Internal config failure';
        }
        $check($name . ': config TypeError is not converted to a client error', $configFailed);
        $resources->brokenConfig = false;
        $_GET = [];
        $repositoryFailed = false;
        try {
            $action->invoke($controller);
        } catch (TypeError $e) {
            $repositoryFailed = $e->getMessage() === 'Internal repository failure';
        }
        $check($name . ': valid request reaches repository and preserves internal TypeError',
            $repositoryFailed && $resources->doctrineReads === 1);
    } finally {
        $_GET = $oldGet;
    }
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
