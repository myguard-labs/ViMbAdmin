<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

final class MaintenanceRpcDuplicateAssertions
{
    public static int $failures = 0;
}

function maintenanceRpcDuplicateCheck(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MaintenanceRpcDuplicateAssertions::$failures++;
    }
}

/** @return array{code:int,id:mixed,message:string,respond:bool} */
function maintenanceRpcDuplicateFailure(string $body): array
{
    try {
        ViMbAdmin_Mcp_Request::parse($body);
    } catch (ViMbAdmin_Mcp_ProtocolException $error) {
        return [
            'code' => $error->rpcCode(),
            'id' => $error->rpcId(),
            'message' => $error->getMessage(),
            'respond' => $error->shouldRespond(),
        ];
    }

    throw new RuntimeException('Expected protocol failure for ' . $body);
}

/** @return array{jsonrpc:'2.0',id:mixed,method:string,params:array<string,mixed>} */
function maintenanceRpcDuplicateParse(string $body): array
{
    return ViMbAdmin_Mcp_Request::parse($body);
}

echo "MCP duplicate JSON member regression tests\n";

$unique = maintenanceRpcDuplicateParse(
    '{"jsonrpc":"2.0","id":"unique-1","method":"ping","params":{"name":"first","Name":"second"}}'
);
maintenanceRpcDuplicateCheck(
    'valid request objects with distinct member names remain accepted',
    $unique['id'] === 'unique-1'
        && $unique['method'] === 'ping'
        && $unique['params']['name'] === 'first'
        && $unique['params']['Name'] === 'second'
);

$largeValue = str_repeat('x', 262144);
$large = maintenanceRpcDuplicateParse((string) json_encode([
    'jsonrpc' => '2.0',
    'id' => 262144,
    'method' => 'ping',
    'params' => (object) ['value' => $largeValue],
], JSON_THROW_ON_ERROR));
maintenanceRpcDuplicateCheck(
    'valid large request values retain their existing size behavior',
    $large['id'] === 262144 && $large['params']['value'] === $largeValue
);

$nestedValue = str_repeat('{"x":', 509) . '0' . str_repeat('}', 509);
$depthBoundary = maintenanceRpcDuplicateParse(
    '{"jsonrpc":"2.0","id":509,"method":"ping","params":{"x":' . $nestedValue . '}}'
);
maintenanceRpcDuplicateCheck(
    'the deepest request accepted by the existing decoder limit remains accepted',
    $depthBoundary['id'] === 509
);

$tooDeepValue = str_repeat('{"x":', 510) . '0' . str_repeat('}', 510);
$tooDeep = maintenanceRpcDuplicateFailure(
    '{"jsonrpc":"2.0","id":510,"method":"ping","params":{"x":' . $tooDeepValue . '}}'
);
maintenanceRpcDuplicateCheck(
    'requests beyond the existing decoder depth remain parse errors without an id',
    $tooDeep === ['code' => -32700, 'id' => null, 'message' => 'parse error', 'respond' => true]
);

$malformed = maintenanceRpcDuplicateFailure('{"jsonrpc":"2.0","id":"malformed"');
maintenanceRpcDuplicateCheck(
    'malformed JSON retains parse-error and response behavior',
    $malformed === ['code' => -32700, 'id' => null, 'message' => 'parse error', 'respond' => true]
);

$correlatedInvalid = maintenanceRpcDuplicateFailure(
    '{"jsonrpc":"1.0","id":"correlate-me","method":"ping"}'
);
maintenanceRpcDuplicateCheck(
    'unique invalid requests retain their correlatable id',
    $correlatedInvalid === [
        'code' => -32600,
        'id' => 'correlate-me',
        'message' => 'invalid request',
        'respond' => true,
    ]
);

foreach ([
    'top-level duplicate names replace neither method value' =>
        '{"jsonrpc":"2.0","id":1,"method":"domains.list","method":"ping","params":{}}',
    'escaped-equivalent top-level names are duplicates' =>
        '{"jsonrpc":"2.0","id":2,"\\u0069d":3,"method":"ping","params":{}}',
    'literal and escaped Unicode names are duplicates' =>
        '{"jsonrpc":"2.0","id":4,"method":"ping","params":{"é":1,"\\u00e9":2}}',
    'duplicates in nested parameter objects are rejected' =>
        '{"jsonrpc":"2.0","id":5,"method":"ping","params":{"filter":{"domain":"first","\\u0064omain":"last"}}}',
    'duplicates in objects nested inside arrays are rejected' =>
        '{"jsonrpc":"2.0","id":6,"method":"ping","params":{"filters":[{"active":true,"active":false}]}}',
] as $label => $body) {
    $failure = maintenanceRpcDuplicateFailure($body);
    maintenanceRpcDuplicateCheck(
        $label,
        $failure === ['code' => -32700, 'id' => null, 'message' => 'parse error', 'respond' => true]
    );
}

echo MaintenanceRpcDuplicateAssertions::$failures === 0
    ? "ALL PASSED\n"
    : MaintenanceRpcDuplicateAssertions::$failures . " FAILED\n";
exit(MaintenanceRpcDuplicateAssertions::$failures === 0 ? 0 : 1);
