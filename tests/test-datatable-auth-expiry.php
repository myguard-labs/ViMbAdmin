<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Entities/Domain.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class DataTableAnonymousSession implements SessionStorage
{
    #[Override]
    public function has(string $key): bool { return false; }
    #[Override]
    public function get(string $key): mixed { return null; }
    #[Override]
    public function set(string $key, mixed $value): void {}
    #[Override]
    public function remove(string $key): void {}
}

final class DataTableAnonymousResources
{
    public int $doctrineReads = 0;
    public function __construct(private readonly DataTableAnonymousSession $session) {}
    /** @return array<string,mixed> */
    public function getOptions(): array { return []; }
    public function getResource(string $name): object
    {
        if ($name === 'namespace') return $this->session;
        if ($name === 'doctrine2') {
            $this->doctrineReads++;
            throw new LogicException('anonymous request reached Doctrine');
        }
        throw new LogicException('Unexpected resource: ' . $name);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};

$expectedBody = getenv('VIMBADMIN_AUTH_EXPIRY_HTML_MUTATION') === '1'
    ? '<html><body>Login</body></html>'
    : '{"error":"Authentication required"}';

foreach (['Alias', 'Archive', 'Domain', 'Log', 'Mailbox'] as $name) {
    $session = new DataTableAnonymousSession();
    $resources = new DataTableAnonymousResources($session);
    $container = new Container($resources, new Auth($session, static fn(int $id): never => throw new LogicException('not used')));
    $class = 'ViMbAdmin\\Kernel\\Controller\\' . $name . 'Controller';
    $controller = new $class($container,
        new RouteMatch(strtolower($name), 'list-data', $class, 'listDataAction', []));
    $response = $controller->listDataAction();

    $check($name . ': anonymous list-data returns the shared JSON expiry contract',
        $response instanceof Response
        && $response->status === 401
        && $response->contentType === 'application/json; charset=utf-8'
        && $response->body === $expectedBody
        && $resources->doctrineReads === 0);
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
