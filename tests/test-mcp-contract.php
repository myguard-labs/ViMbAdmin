<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/McpToken.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class McpContractSession implements SessionStorage
{
    public function has(string $key): bool
    {
        return false;
    }
    public function get(string $key): mixed
    {
        return null;
    }
    public function set(string $key, mixed $value): void
    {
    }
    public function remove(string $key): void
    {
    }
}

final class McpContractResources
{
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
    public function getResource(string $name): object
    {
        return new stdClass();
    }
}

function mcpContractController(): McpController
{
    $session = new McpContractSession();
    $container = new Container(
        new McpContractResources(),
        new Auth($session, static fn (int $id): null => null),
    );
    return new McpController(
        $container,
        new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
    );
}

final class McpContractState
{
    public static int $failures = 0;
}

function mcpContractCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        McpContractState::$failures++;
    }
}

function mcpProtocolException(string $json): ViMbAdmin_Mcp_ProtocolException
{
    try {
        ViMbAdmin_Mcp_Request::parse($json);
    } catch (ViMbAdmin_Mcp_ProtocolException $error) {
        return $error;
    }
    throw new RuntimeException('Expected protocol failure for ' . $json);
}

/** @return array{code:int,id:mixed,message:string,respond:bool} */
function mcpProtocolFailure(string $json): array
{
    $error = mcpProtocolException($json);
    return [
        'code' => $error->rpcCode(),
        'id' => $error->rpcId(),
        'message' => $error->getMessage(),
        'respond' => $error->shouldRespond(),
    ];
}

function mcpProtocolResponse(
    McpController $controller,
    ViMbAdmin_Mcp_ProtocolException $error,
): Response {
    $response = (new ReflectionMethod($controller, '_protocolError'))->invoke($controller, $error);
    if (!$response instanceof Response) {
        throw new RuntimeException('MCP protocol error did not return a response');
    }
    return $response;
}

function mcpApplicationError(
    McpController $controller,
    mixed $id,
    ViMbAdmin_Mcp_Exception $error,
): Response {
    $response = (new ReflectionMethod($controller, '_applicationError'))->invoke($controller, $id, $error);
    if (!$response instanceof Response) {
        throw new RuntimeException('MCP application error did not return a response');
    }
    return $response;
}

/** @return array{id:mixed,error:array{code:int,message:string}} */
function mcpErrorBody(Response $response): array
{
    $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)
        || !array_key_exists('id', $decoded)
        || !isset($decoded['error'])
        || !is_array($decoded['error'])
        || !isset($decoded['error']['code'])
        || !is_int($decoded['error']['code'])
        || !isset($decoded['error']['message'])
        || !is_string($decoded['error']['message'])
    ) {
        throw new RuntimeException('MCP error response has an invalid shape');
    }
    return [
        'id' => $decoded['id'],
        'error' => [
            'code' => $decoded['error']['code'],
            'message' => $decoded['error']['message'],
        ],
    ];
}

echo "== MCP wire and ability contracts ==\n";

$expected = [
    'ping'            => ['scope' => 'read',  'destructive' => false],
    'domains.list'    => ['scope' => 'read',  'destructive' => false],
    'mailboxes.list'  => ['scope' => 'read',  'destructive' => false],
    'aliases.list'    => ['scope' => 'read',  'destructive' => false],
    'domain.create'   => ['scope' => 'write', 'destructive' => false],
    'domain.delete'   => ['scope' => 'write', 'destructive' => true],
    'mailbox.create'  => ['scope' => 'write', 'destructive' => false],
    'mailbox.delete'  => ['scope' => 'write', 'destructive' => true],
    'alias.create'    => ['scope' => 'write', 'destructive' => false],
    'alias.delete'    => ['scope' => 'write', 'destructive' => false],
    'mailbox.archive' => ['scope' => 'write', 'destructive' => true],
    'archive.restore' => ['scope' => 'write', 'destructive' => true],
    'archive.delete'  => ['scope' => 'write', 'destructive' => true],
];
$controller = mcpContractController();
$archiveResult = new ReflectionMethod($controller, '_mailboxArchiveResult');
mcpContractCheck(
    'duplicate archive requests report an idempotent already-queued result',
    $archiveResult->invoke(null, false, 'user@example.test') === [
        'queued' => false,
        'already_queued' => true,
        'username' => 'user@example.test',
    ]
);
mcpContractCheck(
    'new archive requests retain the existing queued response',
    $archiveResult->invoke(null, true, 'user@example.test') === [
        'queued' => 'ARCHIVE',
        'username' => 'user@example.test',
    ]
);
$table = (new ReflectionMethod($controller, '_methodTable'))->invoke($controller);
$actual = [];
$handlersValid = is_array($table);
foreach ($expected as $method => $definition) {
    if (!is_array($table) || !isset($table[$method]) || !is_array($table[$method])) {
        $handlersValid = false;
        continue;
    }
    $row = $table[$method];
    $handlersValid = $handlersValid && ($row['handler'] ?? null) instanceof Closure;
    $actual[$method] = [
        'scope' => $row['scope'] ?? null,
        'destructive' => $row['destructive'] ?? null,
    ];
}
mcpContractCheck(
    'the complete shipped method set has one authoritative definition',
    $actual === $expected && is_array($table) && count($table) === count($expected)
);
mcpContractCheck('every table row carries its direct dispatch closure', $handlersValid);
$pingDefinition = (new ReflectionMethod($controller, '_methodDefinition'))->invoke($controller, 'ping');
$pingHandler = is_array($pingDefinition) ? ($pingDefinition['handler'] ?? null) : null;
$pingResult = $pingHandler instanceof Closure ? $pingHandler([]) : null;
mcpContractCheck(
    'dispatch executes the handler obtained directly from the authoritative row',
    is_array($pingResult) && ($pingResult['pong'] ?? null) === true
);

$unknownMethod = null;
try {
    (new ReflectionMethod($controller, '_methodDefinition'))->invoke($controller, 'future.unclassified');
} catch (ViMbAdmin_Mcp_ProtocolException $error) {
    $unknownMethod = $error->rpcCode();
}
mcpContractCheck(
    'unknown methods fail before dispatch, scope or destructive classification',
    $unknownMethod === -32601
);

$valid = ViMbAdmin_Mcp_Request::parse('{"jsonrpc":"2.0","id":"req-7","method":"domains.list","params":{}}');
mcpContractCheck(
    'valid request objects preserve method, named params and string ids',
    $valid === ['jsonrpc' => '2.0', 'id' => 'req-7', 'method' => 'domains.list', 'params' => []]
);
$emptyPositional = ViMbAdmin_Mcp_Request::parse('{"jsonrpc":"2.0","id":8,"method":"ping","params":[]}');
mcpContractCheck(
    'the existing empty positional params compatibility is preserved',
    $emptyPositional['id'] === 8 && $emptyPositional['params'] === []
);

mcpContractCheck(
    'malformed JSON alone maps to parse error',
    mcpProtocolFailure('{')['code'] === -32700
);
foreach (['[]', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', 'null', '7', '{}'] as $request) {
    $failure = mcpProtocolFailure($request);
    mcpContractCheck(
        "well-formed non-request {$request} maps to invalid request",
        $failure['code'] === -32600 && $failure['id'] === null && $failure['respond'] === true
    );
}
$wrongVersion = mcpProtocolFailure('{"jsonrpc":"1.0","id":"version-id","method":"ping"}');
mcpContractCheck(
    'wrong JSON-RPC versions are invalid requests with id correlation',
    $wrongVersion['code'] === -32600 && $wrongVersion['id'] === 'version-id'
);
$missingVersion = mcpProtocolFailure('{"id":9,"method":"ping"}');
mcpContractCheck(
    'missing JSON-RPC versions are invalid requests with id correlation',
    $missingVersion['code'] === -32600 && $missingVersion['id'] === 9
);
$invalidMethod = mcpProtocolFailure('{"jsonrpc":"2.0","id":10,"method":[]}');
mcpContractCheck(
    'non-string method members are invalid requests',
    $invalidMethod['code'] === -32600 && $invalidMethod['id'] === 10
);
foreach ([
    '{}' => '{}',
    'wrong version without id' => '{"jsonrpc":"1.0","method":"ping"}',
    'bad method without id' => '{"jsonrpc":"2.0","method":[]}',
] as $shape => $request) {
    $invalidMissingId = mcpProtocolFailure($request);
    mcpContractCheck(
        "{$shape} remains an invalid request response, not a notification",
        $invalidMissingId['respond'] === true
            && in_array($invalidMissingId['code'], [-32600, -32602], true)
            && $invalidMissingId['id'] === null
    );
}
$invalidObjectResponse = mcpProtocolResponse($controller, mcpProtocolException('{}'));
$invalidObjectBody = mcpErrorBody($invalidObjectResponse);
mcpContractCheck(
    'invalid missing-id objects retain an HTTP 200 JSON-RPC envelope',
    $invalidObjectResponse->status === 200
        && $invalidObjectBody['id'] === null
        && $invalidObjectBody['error']['code'] === -32600
);
foreach ([
    'no params' => '{"jsonrpc":"2.0","method":"ping"}',
    'invalid null params' => '{"jsonrpc":"2.0","method":"ping","params":null}',
    'unsupported positional params' => '{"jsonrpc":"2.0","method":"ping","params":[1]}',
] as $shape => $request) {
    $notificationError = mcpProtocolException($request);
    $notificationResponse = mcpProtocolResponse($controller, $notificationError);
    mcpContractCheck(
        "notification-shaped calls with {$shape} are rejected without a JSON-RPC response",
        $notificationError->rpcCode() === -32600
            && $notificationError->shouldRespond() === false
            && $notificationResponse->status === 400
            && $notificationResponse->body === ''
    );
}
foreach ([
    'null' => '{"jsonrpc":"2.0","id":null,"method":"ping"}',
    'boolean' => '{"jsonrpc":"2.0","id":true,"method":"ping"}',
    'fractional' => '{"jsonrpc":"2.0","id":1.5,"method":"ping"}',
    'container' => '{"jsonrpc":"2.0","id":[],"method":"ping"}',
] as $shape => $request) {
    $invalidId = mcpProtocolFailure($request);
    mcpContractCheck(
        "{$shape} request ids cannot execute as notifications",
        $invalidId['code'] === -32600 && $invalidId['id'] === null
    );
}
foreach (['null', '"domain"', '["example.test"]'] as $params) {
    $failure = mcpProtocolFailure('{"jsonrpc":"2.0","id":11,"method":"domains.list","params":' . $params . '}');
    mcpContractCheck(
        "parameter shape {$params} maps to invalid params",
        $failure['code'] === -32602 && $failure['id'] === 11
    );
}

mcpContractCheck(
    'canonical identities preserve web trim/lowercase behaviour',
    ViMbAdmin_Identity::canonical('  User@Example.TEST  ') === 'user@example.test'
);
mcpContractCheck(
    'valid exact MCP identities use the same lowercase canonical form',
    ViMbAdmin_Mcp_Input::identity('User@Example.TEST', 'param "username"', true) === 'user@example.test'
);
$whitespaceRejected = false;
try {
    ViMbAdmin_Mcp_Input::identity(' user@example.test ', 'param "username"', true);
} catch (ViMbAdmin_Mcp_Exception $error) {
    $whitespaceRejected = $error->getMessage() === 'param "username" must not contain surrounding whitespace';
}
mcpContractCheck(
    'MCP identity parameters reject surrounding whitespace instead of silently retargeting',
    $whitespaceRejected
);
foreach (["\u{00A0}", "\u{2003}"] as $unicodeWhitespace) {
    $unicodeRejected = false;
    try {
        ViMbAdmin_Mcp_Input::identity(
            $unicodeWhitespace . 'user@example.test' . $unicodeWhitespace,
            'param "username"',
            true,
        );
    } catch (ViMbAdmin_Mcp_Exception $error) {
        $unicodeRejected = $error->getMessage() === 'param "username" must not contain surrounding whitespace';
    }
    mcpContractCheck('MCP identity parameters reject Unicode boundary whitespace', $unicodeRejected);
}
$invalidUtf8Rejected = false;
try {
    ViMbAdmin_Mcp_Input::identity("user\xC3\x28@example.test", 'param "username"', true);
} catch (ViMbAdmin_Mcp_Exception $error) {
    $invalidUtf8Rejected = $error->getMessage() === 'param "username" must be valid UTF-8';
}
mcpContractCheck('MCP identity parameters reject invalid UTF-8 deterministically', $invalidUtf8Rejected);
$token = (new Entities\McpToken())->setAllowedDomains(' Example.TEST, OTHER.test ');
mcpContractCheck(
    'domain authorization shares canonical case and whitespace handling',
    $token->allowsDomain('EXAMPLE.test') && $token->allowsDomain('other.TEST') && !$token->allowsDomain('outside.test')
);

$invalidParamsResponse = mcpApplicationError(
    $controller,
    'params-id',
    new ViMbAdmin_Mcp_Exception('param "domain" must be a string'),
);
$stateResponse = mcpApplicationError(
    $controller,
    'state-id',
    new ViMbAdmin_Mcp_DomainException('unknown domain'),
);
$invalidParamsBody = mcpErrorBody($invalidParamsResponse);
$stateBody = mcpErrorBody($stateResponse);
mcpContractCheck(
    'parameter-shape failures retain -32602 and their request id',
    $invalidParamsBody['id'] === 'params-id' && $invalidParamsBody['error']['code'] === -32602
);
mcpContractCheck(
    'domain and state conflicts use the documented server range with id correlation',
    $stateBody['id'] === 'state-id' && $stateBody['error']['code'] === -32010
);

echo McpContractState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . McpContractState::$failures . " FAILED\n";
exit(McpContractState::$failures === 0 ? 0 : 1);
