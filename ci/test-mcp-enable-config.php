<?php

/** The published MCP setting must reach the environment selected at boot. */

require __DIR__ . '/../src/Kernel/Config/IniConfig.php';

use ViMbAdmin\Kernel\Config\IniConfig;

final class McpConfigTestState
{
    public static int $failures = 0;
}

function checkMcpConfig(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        McpConfigTestState::$failures++;
    }
}

/** @return array<string,mixed> */
function loadMcpFixture(string $contents, string $section): array
{
    $path = tempnam(sys_get_temp_dir(), 'vimbadmin-mcp-config-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary config');
    }
    try {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Cannot write temporary config');
        }
        return IniConfig::load($path, $section);
    } finally {
        unlink($path);
    }
}

/** @param array<string,mixed> $options */
function mcpEnabled(array $options): ?string
{
    $mcp = $options['mcp'] ?? null;
    if (!is_array($mcp)) {
        return null;
    }
    $enabled = $mcp['enabled'] ?? null;
    return is_string($enabled) ? $enabled : null;
}

$docPath = $argv[1] ?? __DIR__ . '/../docs/mcp-auth.md';
$doc = file_get_contents($docPath);
$snippet = null;
if (is_string($doc) && preg_match('/## Enable it\s+```ini\n(.*?)\n```/s', $doc, $match) === 1) {
    $snippet = trim($match[1]);
}
checkMcpConfig('error: the MCP enablement INI example is readable', $snippet !== null);
if ($snippet === null) {
    exit(1);
}

// Older guide text instructed readers to put this key in "([user] section)"
// without spelling out the header inside the fence. Apply that instruction.
if (preg_match('/^;[^\n]*\((\[[^\]]+\]) section\)/m', $snippet, $match) === 1) {
    $snippet = $match[1] . "\n" . $snippet;
}

// The shipped flat default is followed here by a selected environment section.
// A misplaced [user] header captures the setting and makes the positive check red.
$config = str_replace(
    'mcp.enabled = 0',
    $snippet,
    "mcp.enabled = 0\n[development]\napp.name = example\n[production]\napp.name = example\n"
);
$development = loadMcpFixture($config, 'development');
checkMcpConfig('positive: documented edit enables MCP in development', mcpEnabled($development) === '1');

$production = loadMcpFixture($config, 'production');
checkMcpConfig('boundary: documented base setting enables MCP in production', mcpEnabled($production) === '1');

$override = loadMcpFixture($config . "\n[staging]\nmcp.enabled = 0\n", 'staging');
checkMcpConfig('boundary: a selected environment can override the base', mcpEnabled($override) === '0');

$disabled = loadMcpFixture("mcp.enabled = 0\n[development]\n", 'development');
checkMcpConfig('negative: the unchanged shipped default stays disabled', mcpEnabled($disabled) === '0');

$malformed = false;
try {
    loadMcpFixture("[development : missing]\nmcp.enabled = 1\n", 'development');
} catch (RuntimeException $error) {
    $malformed = $error->getMessage() === "Section 'missing' extends unknown section";
}
checkMcpConfig('error: malformed environment inheritance is rejected', $malformed);

echo McpConfigTestState::$failures === 0 ? "ALL PASSED\n" : McpConfigTestState::$failures . " FAILED\n";
exit(McpConfigTestState::$failures === 0 ? 0 : 1);
