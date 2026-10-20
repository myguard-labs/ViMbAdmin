<?php

declare(strict_types=1);

/**
 * contrib/angie/vimbadmin.conf must hide the Angie version (gixy
 * version_disclosure): every server{} in the shipped vhost resolves
 * server_tokens to "off".
 *
 * Two inputs share one resolver:
 *   php ci/test-angie-server-tokens.php
 *       parses the shipped file (the file is include()d into http{}, so its
 *       top level stands in for http scope). Runs in CI without Angie.
 *   php ci/test-angie-server-tokens.php --angie-dump=FILE
 *       parses `angie -T` output of a full configuration (nested http{}),
 *       for the servers whose server_name is vimbadmin.example.com.
 *       See ci/README or the PR for the disposable-container command.
 *
 * Built-in negative controls feed seeded variants through the same resolver:
 * removed directive, "on", wrong scope (location only), commented out and a
 * value that only appears inside a quoted string must all be rejected.
 */

/**
 * Split a config into tokens: words, '{', '}', ';'. A '#' starting a token
 * begins a comment that is dropped (inside a word it is literal, as in nginx);
 * quoted strings stay a single word.
 *
 * @return list<string>
 */
function angieTokens(string $text): array
{
    $tokens = [];
    $length = strlen($text);
    $word = '';
    for ($i = 0; $i < $length; $i++) {
        $c = $text[$i];
        if ($c === '#' && $word === '') {
            while ($i < $length && $text[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $word .= $c;
            for ($i++; $i < $length && $text[$i] !== $quote; $i++) {
                if ($text[$i] === '\\' && $i + 1 < $length) {
                    $word .= $text[$i++];
                }
                $word .= $text[$i];
            }
            $word .= $quote;
            continue;
        }
        if ($c === '{' || $c === '}' || $c === ';') {
            if ($word !== '') {
                $tokens[] = $word;
                $word = '';
            }
            $tokens[] = $c;
            continue;
        }
        if (ctype_space($c)) {
            if ($word !== '') {
                $tokens[] = $word;
                $word = '';
            }
            continue;
        }
        $word .= $c;
    }
    if ($word !== '') {
        $tokens[] = $word;
        $word = '';
    }
    return $tokens;
}

final class AngieNode
{
    /**
     * @param list<string> $args
     * @param list<AngieNode>|null $children null for a simple directive
     */
    public function __construct(
        public readonly array $args,
        public readonly ?array $children,
    ) {
    }

    public function name(): string
    {
        return $this->args[0] ?? '';
    }
}

/**
 * @param list<string> $tokens
 * @return list<AngieNode>
 */
function angieParse(array $tokens, int &$pos = 0, bool $nested = false): array
{
    $nodes = [];
    $args = [];
    $count = count($tokens);
    while ($pos < $count) {
        $t = $tokens[$pos++];
        if ($t === ';') {
            if ($args !== []) {
                $nodes[] = new AngieNode($args, null);
            }
            $args = [];
        } elseif ($t === '{') {
            $nodes[] = new AngieNode($args, angieParse($tokens, $pos, true));
            $args = [];
        } elseif ($t === '}') {
            if (!$nested) {
                throw new RuntimeException('unbalanced closing brace');
            }
            return $nodes;
        } else {
            $args[] = $t;
        }
    }
    if ($nested) {
        throw new RuntimeException('unterminated block');
    }
    return $nodes;
}

/**
 * Value of the last direct server_tokens directive among $nodes, or null.
 *
 * @param list<AngieNode> $nodes
 */
function directTokens(array $nodes): ?string
{
    $value = null;
    foreach ($nodes as $node) {
        if ($node->children === null && $node->name() === 'server_tokens') {
            $value = $node->args[1] ?? '';
        }
    }
    return $value;
}

/**
 * Effective server_tokens per matching server.
 *
 * @param list<AngieNode> $nodes scope nodes
 * @return array<string,string>
 */
function effectiveTokens(array $nodes, ?string $inherited, ?string $serverName): array
{
    $scope = directTokens($nodes) ?? $inherited;
    $result = [];
    foreach ($nodes as $node) {
        if ($node->children === null) {
            continue;
        }
        if ($node->name() === 'server') {
            $names = '';
            foreach ($node->children as $child) {
                if ($child->children === null && $child->name() === 'server_name') {
                    $names = implode(' ', array_slice($child->args, 1));
                }
            }
            if ($serverName !== null && !in_array($serverName, explode(' ', $names), true)) {
                continue;
            }
            $result['server#' . (count($result) + 1) . ' ' . $names] = directTokens($node->children) ?? $scope ?? 'on';
        } elseif ($node->name() === 'http') {
            foreach (effectiveTokens($node->children, $scope, $serverName) as $key => $value) {
                $result['http/' . count($result) . ' ' . $key] = $value;
            }
        }
    }
    return $result;
}

/** @return array<string,string> */
function resolveConfig(string $text, ?string $serverName): array
{
    $pos = 0;
    return effectiveTokens(angieParse(angieTokens($text), $pos), null, $serverName);
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

/** True when at least one server is found and every one is "off". */
$allOff = static function (string $text, ?string $name = null): bool {
    $servers = resolveConfig($text, $name);
    return $servers !== [] && array_unique(array_values($servers)) === ['off'];
};

$options = getopt('', ['angie-dump:']);
if (isset($options['angie-dump'])) {
    $dumpPath = $options['angie-dump'];
    $dump = is_string($dumpPath) ? file_get_contents($dumpPath) : false;
    if (!is_string($dump)) {
        throw new RuntimeException('Cannot read angie dump');
    }
    $servers = resolveConfig($dump, 'vimbadmin.example.com');
    $check('angie -T dump holds exactly the 2 vimbadmin servers', count($servers) === 2);
    $check('angie -T dump: both servers resolve server_tokens off', $allOff($dump, 'vimbadmin.example.com'));
    echo $failures === 0 ? "ALL PASSED\n" : "FAILED: {$failures}\n";
    exit($failures === 0 ? 0 : 1);
}

$source = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
if (!is_string($source)) {
    throw new RuntimeException('Cannot read contrib/angie/vimbadmin.conf');
}

$servers = resolveConfig($source, null);
$check('vhost declares exactly two server blocks (HTTP redirect + HTTPS)', count($servers) === 2);
$check('both servers resolve server_tokens off', $allOff($source));

// Negative controls: seeded variants through the same resolver.
$twoServers = "server { listen 80; server_name a; server_tokens off; }\nserver { listen 443; server_name b; server_tokens off; }\n";
$check('control baseline: two explicit off servers pass', $allOff($twoServers));
$check(
    'control removed from one server is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; }\n")
);
$check(
    'control removed from all servers is rejected',
    !$allOff("server { server_name a; }\nserver { server_name b; }\n")
);
$check(
    'control "on" is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; server_tokens on; }\n")
);
$check(
    'control "build" is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; server_tokens build; }\n")
);
$check(
    'control commented-out directive is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; # server_tokens off;\n}\n")
);
$check(
    'control directive only inside a location is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; location / { server_tokens off; } }\n")
);
$check(
    'control directive text only inside a quoted string is rejected',
    !$allOff("server { server_name a; server_tokens off; }\nserver { server_name b; add_header X \"server_tokens off;\"; }\n")
);
$check('control empty config yields no server and is rejected', !$allOff(''));
$check(
    'mid-word # is literal (not a comment) so a directive after it on the same line counts',
    $allOff("server { server_name a; server_tokens off; }\nserver { server_name b; location ~ a#b { } server_tokens off; }\n")
);
$check(
    'inherited http-scope off (file top level) satisfies a server without its own',
    $allOff("server_tokens off;\nserver { server_name a; }\nserver { server_name b; }\n")
);
$check(
    'server-level on overrides inherited off and is rejected',
    !$allOff("server_tokens off;\nserver { server_name a; server_tokens on; }\n")
);
$check(
    'nested http{} dump form resolves per server',
    $allOff("http { server_tokens off; server { server_name a; } server { server_name b; } }")
);
$check(
    'server_name filter ignores unrelated servers',
    $allOff("http { server { server_name other; } server { server_name a; server_tokens off; } }", 'a')
);
$check('unbalanced closing brace is rejected as malformed', (static function (): bool {
    try {
        resolveConfig("server { server_name a; } }", null);
    } catch (RuntimeException) {
        return true;
    }
    return false;
})());
$check('unterminated block is rejected as malformed', (static function (): bool {
    try {
        resolveConfig("server { server_name a; ", null);
    } catch (RuntimeException) {
        return true;
    }
    return false;
})());

echo $failures === 0 ? "ALL PASSED\n" : "FAILED: {$failures}\n";
exit($failures === 0 ? 0 : 1);
