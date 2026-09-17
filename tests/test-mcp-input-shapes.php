<?php

declare(strict_types=1);

require __DIR__ . '/../library/ViMbAdmin/Mcp/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/Input.php';

final class McpInputShapeState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function mcpInputShapeCheck(string $label, bool $ok): void
{
    McpInputShapeState::$checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        McpInputShapeState::$failures++;
    }
}

/** @param callable():mixed $call */
function mcpInputShapeRejects(string $label, callable $call): void
{
    try {
        $call();
        mcpInputShapeCheck($label, false);
    } catch (ViMbAdmin_Mcp_Exception) {
        mcpInputShapeCheck($label, true);
    }
}

echo "== MCP request and configuration shapes ==\n";

mcpInputShapeCheck(
    'string-keyed request objects preserve values',
    ViMbAdmin_Mcp_Input::map(['domain' => 'example.test'], 'params') === ['domain' => 'example.test']
);
mcpInputShapeCheck('empty params object remains valid', ViMbAdmin_Mcp_Input::map([], 'params') === []);
mcpInputShapeRejects(
    'list-shaped params fail closed',
    static fn (): array => ViMbAdmin_Mcp_Input::map(['value'], 'params')
);
mcpInputShapeRejects(
    'container-shaped scalar params fail closed',
    static fn (): string => ViMbAdmin_Mcp_Input::string(['value'], 'param "domain"')
);
mcpInputShapeCheck(
    'string input is trimmed without coercion',
    ViMbAdmin_Mcp_Input::string('  value  ', 'value', true) === 'value'
);
mcpInputShapeRejects(
    'required empty strings fail closed',
    static fn (): string => ViMbAdmin_Mcp_Input::string('  ', 'value', true)
);
mcpInputShapeRejects(
    'integer-to-string coercion mutant is rejected',
    static fn (): string => ViMbAdmin_Mcp_Input::string(42, 'value')
);

mcpInputShapeCheck(
    'native JSON booleans are preserved',
    ViMbAdmin_Mcp_Input::boolean(false, 'active') === false
);
mcpInputShapeRejects(
    'truthy strings cannot enable request flags',
    static fn (): bool => ViMbAdmin_Mcp_Input::boolean('false', 'active')
);
mcpInputShapeCheck(
    'canonical integer strings remain compatible with INI configuration',
    ViMbAdmin_Mcp_Input::nonNegativeInteger('3600', 'window') === 3600
);
mcpInputShapeCheck(
    'native zero remains a valid explicit limit',
    ViMbAdmin_Mcp_Input::nonNegativeInteger(0, 'max') === 0
);
foreach ([-1, 1.5, true, '01', '1e2', ' 1', [], PHP_INT_MAX . '0'] as $badInteger) {
    mcpInputShapeRejects(
        'ambiguous integer shape is rejected: ' . get_debug_type($badInteger),
        static fn (): int => ViMbAdmin_Mcp_Input::nonNegativeInteger($badInteger, 'limit')
    );
}

$options = [
    'mcp' => ['enabled' => '1', 'ratelimit' => ['destructive' => ['max' => '10']]],
    'trustedproxy' => ['mode' => 'on', 'proxies' => ['10.0.0.0/8']],
];
mcpInputShapeCheck(
    'nested boolean configuration preserves documented INI values',
    ViMbAdmin_Mcp_Input::optionBoolean($options, false, 'mcp', 'enabled')
);
mcpInputShapeCheck(
    'nested integer configuration preserves canonical INI values',
    ViMbAdmin_Mcp_Input::optionInteger($options, 0, 'mcp', 'ratelimit', 'destructive', 'max') === 10
);
mcpInputShapeCheck(
    'absent nested configuration alone receives its default',
    ViMbAdmin_Mcp_Input::optionInteger($options, 3600, 'mcp', 'ratelimit', 'destructive', 'window') === 3600
);
mcpInputShapeRejects(
    'explicit null configuration cannot masquerade as absent',
    static fn (): int => ViMbAdmin_Mcp_Input::optionInteger(['mcp' => ['ratelimit' => ['destructive' => ['window' => null]]]], 3600, 'mcp', 'ratelimit', 'destructive', 'window')
);
mcpInputShapeRejects(
    'malformed intermediate configuration never masquerades as absent',
    static fn (): int => ViMbAdmin_Mcp_Input::optionInteger(['mcp' => 'on'], 10, 'mcp', 'ratelimit', 'max')
);
mcpInputShapeCheck(
    'trusted proxy tuple retains exact types',
    ViMbAdmin_Mcp_Input::trustedProxy($options['trustedproxy']) === $options['trustedproxy']
);
mcpInputShapeRejects(
    'trusted proxy lists reject container entries',
    static fn (): array => ViMbAdmin_Mcp_Input::trustedProxy(['proxies' => [['10.0.0.0/8']]])
);
mcpInputShapeRejects(
    'trusted proxy maps reject list-shaped configuration',
    static fn (): array => ViMbAdmin_Mcp_Input::trustedProxy(['on'])
);
mcpInputShapeRejects(
    'unknown trusted proxy modes cannot enable forwarded-header trust',
    static fn (): array => ViMbAdmin_Mcp_Input::trustedProxy(['mode' => 'bogus', 'proxies' => ['10.0.0.0/8']])
);

$snapshot = [
    'username' => 'box@example.test',
    'local_part' => 'box',
    'name' => ' Mailbox ',
    'password' => '{CRYPT}hash',
    'quota' => 1024,
    'active' => true,
];
mcpInputShapeCheck(
    'complete archive snapshot preserves exact restoration types',
    ViMbAdmin_Mcp_Input::mailboxSnapshot($snapshot) === $snapshot
);
mcpInputShapeCheck(
    'nullable archive mailbox display names preserve the entity contract',
    ViMbAdmin_Mcp_Input::mailboxSnapshot(array_replace($snapshot, ['name' => null]))['name'] === null
);
mcpInputShapeRejects(
    'incomplete archive snapshots fail before restoration mutation',
    static fn (): array => ViMbAdmin_Mcp_Input::mailboxSnapshot(['username' => 'box@example.test'])
);
mcpInputShapeRejects(
    'archive snapshot active flag rejects truthy strings',
    static fn (): array => ViMbAdmin_Mcp_Input::mailboxSnapshot(array_replace($snapshot, ['active' => '1']))
);
mcpInputShapeRejects(
    'archive snapshot quota rejects lossy numeric coercion',
    static fn (): array => ViMbAdmin_Mcp_Input::mailboxSnapshot(array_replace($snapshot, ['quota' => 1.5]))
);

mcpInputShapeCheck('fixed assertion count', McpInputShapeState::$checks === 33);
$failures = McpInputShapeState::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit(min(1, $failures));
