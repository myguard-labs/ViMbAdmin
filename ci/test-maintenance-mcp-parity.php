<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';

final class MaintenanceMcpParityState
{
    public int $bodyReads = 0;
    public int $authCalls = 0;
    public int $rateCalls = 0;
    public int $handlerCalls = 0;
    /** @var list<string> */
    public array $events = [];
    public ?ViMbAdmin_Mcp_Exception $authError = null;
    public ?ViMbAdmin_Mcp_Exception $rateError = null;
}

final class MaintenanceMcpParityAssertions
{
    public static int $failures = 0;
}

function maintenanceMcpParityCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MaintenanceMcpParityAssertions::$failures++;
    }
}

/**
 * @return array<string,array{
 *     handler:\Closure(array<string,mixed>):array<string,mixed>,
 *     scope:'read'|'write',
 *     destructive:bool
 * }>
 */
function maintenanceMcpParityMethods(MaintenanceMcpParityState $state): array
{
    $handler = static function (string $name) use ($state): Closure {
        return static function (array $params) use ($state, $name): array {
            $state->events[] = 'handler:' . $name;
            $state->handlerCalls++;
            if ($name === 'invalid') {
                throw new ViMbAdmin_Mcp_Exception('invalid fixture params');
            }
            if ($name === 'conflict') {
                throw new ViMbAdmin_Mcp_DomainException('fixture state conflict');
            }
            if ($name === 'explode') {
                throw new RuntimeException('fixture exploded');
            }
            return [ 'method' => $name, 'params' => $params ];
        };
    };

    return [
        'ping' => [ 'handler' => $handler('ping'), 'scope' => 'read', 'destructive' => false ],
        'danger' => [ 'handler' => $handler('danger'), 'scope' => 'write', 'destructive' => true ],
        'invalid' => [ 'handler' => $handler('invalid'), 'scope' => 'write', 'destructive' => false ],
        'conflict' => [ 'handler' => $handler('conflict'), 'scope' => 'write', 'destructive' => false ],
        'explode' => [ 'handler' => $handler('explode'), 'scope' => 'read', 'destructive' => false ],
    ];
}

function maintenanceMcpParityDispatcher(MaintenanceMcpParityState $state): ViMbAdmin_Mcp_Dispatcher
{
    $token = (new Entities\McpToken())
        ->setName('dispatcher fixture')
        ->setTokenHash(hash('sha256', 'dispatcher_fixture_token'))
        ->setScope('read write')
        ->setCreated(new DateTime());

    return new ViMbAdmin_Mcp_Dispatcher(
        maintenanceMcpParityMethods($state),
        static function (array $server, string $scope) use ($state, $token): Entities\McpToken {
            unset($server);
            $state->events[] = 'auth:' . $scope;
            $state->authCalls++;
            if ($state->authError !== null) {
                throw $state->authError;
            }
            return $token;
        },
        static function (Entities\McpToken $authenticated) use ($state, $token): void {
            if ($authenticated !== $token) {
                throw new LogicException('rate-limit fixture received the wrong token');
            }
            $state->events[] = 'rate';
            $state->rateCalls++;
            if ($state->rateError !== null) {
                throw $state->rateError;
            }
        },
    );
}

/** @return Closure():(string|false) */
function maintenanceMcpParityBody(MaintenanceMcpParityState $state, string|false $body): Closure
{
    return static function () use ($state, $body): string|false {
        $state->bodyReads++;
        $state->events[] = 'body';
        return $body;
    };
}

/** @param array<string,mixed> $params */
function maintenanceMcpParityRequest(string $method, string|int $id, array $params = []): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => (object) $params,
    ], JSON_THROW_ON_ERROR);
}

/** @return array<string,mixed> */
function maintenanceMcpParityJson(\ViMbAdmin\Kernel\Http\Response $response): array
{
    $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('MCP fixture response is not a JSON object');
    }
    $stringMap = [];
    foreach ($body as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP fixture response has a non-string key');
        }
        $stringMap[$key] = $value;
    }
    return $stringMap;
}

/** @return array{code:int,message:string} */
function maintenanceMcpParityError(\ViMbAdmin\Kernel\Http\Response $response): array
{
    $body = maintenanceMcpParityJson($response);
    $error = $body['error'] ?? null;
    if (!is_array($error) || !is_int($error['code'] ?? null) || !is_string($error['message'] ?? null)) {
        throw new RuntimeException('MCP fixture response has no valid error object');
    }
    return [ 'code' => $error['code'], 'message' => $error['message'] ];
}

echo "== maintenance MCP request dispatcher parity ==\n";

$state = new MaintenanceMcpParityState();
$dispatcher = maintenanceMcpParityDispatcher($state);
$request = maintenanceMcpParityRequest('ping', 'request-1');

$disabled = $dispatcher->dispatch(false, 'POST', maintenanceMcpParityBody($state, $request), []);
$disabledBody = maintenanceMcpParityJson($disabled);
maintenanceMcpParityCheck(
    'disabled MCP returns the existing 404 transport error without reading a body',
    $disabled->status === 404
        && array_key_exists('id', $disabledBody)
        && $disabledBody['id'] === null
        && maintenanceMcpParityError($disabled) === [ 'code' => -32000, 'message' => 'mcp disabled' ]
        && $state->bodyReads === 0
        && $state->authCalls === 0
        && $state->handlerCalls === 0,
);

$methodRejected = $dispatcher->dispatch(true, ['POST'], maintenanceMcpParityBody($state, $request), []);
maintenanceMcpParityCheck(
    'non-string HTTP methods retain the 405 response before body parsing',
    $methodRejected->status === 405
        && maintenanceMcpParityError($methodRejected) === [ 'code' => -32000, 'message' => 'POST required' ]
        && $state->bodyReads === 0,
);

$parseRejected = $dispatcher->dispatch(true, 'POST', maintenanceMcpParityBody($state, '{'), []);
$parseBody = maintenanceMcpParityJson($parseRejected);
maintenanceMcpParityCheck(
    'malformed JSON retains parse-error mapping and cannot reach authentication',
    $parseRejected->status === 200
        && array_key_exists('id', $parseBody)
        && $parseBody['id'] === null
        && maintenanceMcpParityError($parseRejected) === [ 'code' => -32700, 'message' => 'parse error' ]
        && $state->authCalls === 0,
);

$unknown = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('unknown', 'unknown-id')),
    [],
);
$unknownBody = maintenanceMcpParityJson($unknown);
maintenanceMcpParityCheck(
    'unknown RPC methods preserve their request id and fail before authentication',
    $unknown->status === 200
        && ($unknownBody['id'] ?? null) === 'unknown-id'
        && maintenanceMcpParityError($unknown) === [ 'code' => -32601, 'message' => "unknown method 'unknown'" ]
        && $state->authCalls === 0,
);

$eventOffset = count($state->events);
$success = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('ping', 7, ['value' => 'ok'])),
    [],
);
$successBody = maintenanceMcpParityJson($success);
maintenanceMcpParityCheck(
    'successful non-destructive requests authenticate then dispatch with the same id',
    $success->status === 200
        && ($successBody['id'] ?? null) === 7
        && ($successBody['result'] ?? null) === [ 'method' => 'ping', 'params' => ['value' => 'ok'] ]
        && array_slice($state->events, $eventOffset) === ['body', 'auth:read', 'handler:ping']
        && $state->rateCalls === 0,
);

$eventOffset = count($state->events);
$beforeHandlers = $state->handlerCalls;
$state->authError = new ViMbAdmin_Mcp_Exception('fixture bearer rejected', 401);
$authRejected = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('ping', 'auth-id')),
    [],
);
$authBody = maintenanceMcpParityJson($authRejected);
maintenanceMcpParityCheck(
    'authentication rejection preserves HTTP status, challenge, message and request id',
    $authRejected->status === 401
        && $authRejected->headers === [ 'WWW-Authenticate' => 'Bearer' ]
        && ($authBody['id'] ?? null) === 'auth-id'
        && maintenanceMcpParityError($authRejected) === [ 'code' => -32000, 'message' => 'fixture bearer rejected' ]
        && array_slice($state->events, $eventOffset) === ['body', 'auth:read']
        && $state->handlerCalls === $beforeHandlers,
);
$state->authError = null;

$eventOffset = count($state->events);
$beforeHandlers = $state->handlerCalls;
$state->rateError = new ViMbAdmin_Mcp_Exception('fixture rate rejected', 429);
$rateRejected = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('danger', 'rate-id')),
    [],
);
$rateBody = maintenanceMcpParityJson($rateRejected);
maintenanceMcpParityCheck(
    'rate rejection preserves HTTP status, message and request id',
    $rateRejected->status === 429
        && ($rateBody['id'] ?? null) === 'rate-id'
        && maintenanceMcpParityError($rateRejected) === [ 'code' => -32000, 'message' => 'fixture rate rejected' ],
);
maintenanceMcpParityCheck(
    'handler cannot run before authentication and rate guards',
    array_slice($state->events, $eventOffset) === ['body', 'auth:write', 'rate']
        && $state->handlerCalls === $beforeHandlers,
);
$state->rateError = null;

$invalid = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('invalid', 'invalid-id')),
    [],
);
$invalidBody = maintenanceMcpParityJson($invalid);
maintenanceMcpParityCheck(
    'handler input failures retain invalid-params mapping and request id',
    $invalid->status === 200
        && ($invalidBody['id'] ?? null) === 'invalid-id'
        && maintenanceMcpParityError($invalid) === [ 'code' => -32602, 'message' => 'invalid fixture params' ],
);

$conflict = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('conflict', 'conflict-id')),
    [],
);
$conflictBody = maintenanceMcpParityJson($conflict);
maintenanceMcpParityCheck(
    'domain conflicts retain server-error mapping and request id',
    $conflict->status === 200
        && ($conflictBody['id'] ?? null) === 'conflict-id'
        && maintenanceMcpParityError($conflict) === [ 'code' => -32010, 'message' => 'fixture state conflict' ],
);

$internal = $dispatcher->dispatch(
    true,
    'POST',
    maintenanceMcpParityBody($state, maintenanceMcpParityRequest('explode', 'internal-id')),
    [],
);
$internalBody = maintenanceMcpParityJson($internal);
maintenanceMcpParityCheck(
    'unexpected handler failures retain internal-error mapping and request id',
    $internal->status === 200
        && ($internalBody['id'] ?? null) === 'internal-id'
        && maintenanceMcpParityError($internal) === [ 'code' => -32603, 'message' => 'internal error' ],
);

$failures = MaintenanceMcpParityAssertions::$failures;
echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
