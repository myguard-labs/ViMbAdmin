<?php

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== Password-reset URL origin ==\n";

/**
 * Parse enough of Angie syntax to identify executable directives and nesting.
 * Quoted braces, semicolons and comment markers are kept as literal content.
 *
 * @return list<array{parent: ?int, header: string, directives: list<string>}>
 */
$parseAngie = static function (string $input): array {
    $nodes = [['parent' => null, 'header' => 'root', 'directives' => []]];
    $stack = [0];
    $statement = '';
    $quote = null;
    $escaped = false;
    $comment = false;

    $length = strlen($input);
    for ($offset = 0; $offset < $length; $offset++) {
        $char = $input[$offset];
        if ($comment) {
            if ($char === "\n") {
                $comment = false;
                $statement .= ' ';
            }
            continue;
        }
        if ($escaped) {
            $statement .= $char;
            $escaped = false;
            continue;
        }
        if ($quote !== null && $char === '\\') {
            $statement .= $char;
            $escaped = true;
            continue;
        }
        if ($char === '"' || $char === "'") {
            $statement .= $char;
            $quote = $quote === null ? $char : ($quote === $char ? null : $quote);
            continue;
        }
        if ($quote === null && $char === '#') {
            $comment = true;
            continue;
        }
        if ($quote === null && ($char === ';' || $char === '{' || $char === '}')) {
            $statement = trim(preg_replace('/\\s+/', ' ', $statement) ?? $statement);
            if ($char === ';' && $statement !== '') {
                $nodes[$stack[array_key_last($stack)]]['directives'][] = $statement;
            } elseif ($char === '{') {
                $parent = $stack[array_key_last($stack)];
                $nodes[] = ['parent' => $parent, 'header' => $statement, 'directives' => []];
                $stack[] = array_key_last($nodes);
            } elseif ($char === '}') {
                array_pop($stack);
            }
            $statement = '';
            continue;
        }
        $statement .= $char;
    }

    return $nodes;
};

$trustedFastcgiScopes = static function (string $input) use ($parseAngie): bool {
    $nodes = $parseAngie($input);
    $phpScopes = [];
    foreach ($nodes as $id => $node) {
        foreach ($node['directives'] as $directive) {
            if (preg_match('/^fastcgi_pass\\s+/', $directive) === 1) {
                $phpScopes[] = $id;
            }
        }
    }
    if ($phpScopes === []) {
        return false;
    }

    foreach ($phpScopes as $scope) {
        $parameterScope = $scope;
        while ($parameterScope !== null) {
            $hasParameters = array_filter(
                $nodes[$parameterScope]['directives'],
                static fn (string $directive): bool => preg_match(
                    '/^(?:fastcgi_param\\s+|include\\s+fastcgi_params$)/',
                    $directive
                ) === 1
            ) !== [];
            if ($hasParameters) {
                break;
            }
            $parameterScope = $nodes[$parameterScope]['parent'];
        }
        if ($parameterScope === null) {
            return false;
        }

        $includePosition = null;
        $hostAssignments = [];
        foreach ($nodes[$parameterScope]['directives'] as $position => $directive) {
            if (preg_match('/^include\\s+fastcgi_params$/', $directive) === 1) {
                $includePosition = $position;
            }
            if (
                preg_match(
                    '/^fastcgi_param\\s+HTTP_HOST\\s+(\\S+)(?:\\s+if_not_empty)?$/',
                    $directive,
                    $matches
                ) === 1
            ) {
                $hostAssignments[$position] = $matches[1];
            }
        }
        if (
            $includePosition === null
            || count($hostAssignments) !== 1
            || array_values($hostAssignments) !== ['$vimb_trusted_http_host']
            || array_key_first($hostAssignments) <= $includePosition
        ) {
            return false;
        }
    }

    return true;
};

$trustedPortMap = static function (string $input) use ($parseAngie): bool {
    foreach ($parseAngie($input) as $node) {
        if ($node['header'] !== 'map $server_port $vimb_trusted_http_host') {
            continue;
        }
        $entries = [];
        foreach ($node['directives'] as $directive) {
            if (preg_match('/^(default|80|443)\\s+(\\S+)$/', $directive, $matches) === 1) {
                $entries[$matches[1]] = $matches[2];
            }
        }
        return $entries === [
            'default' => '$server_name:$server_port',
            '80' => '$server_name',
            '443' => '$server_name',
        ];
    }

    return false;
};

$config = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
$check('shipped Angie configuration is readable', is_string($config));
$check(
    'every PHP FastCGI scope overrides inherited HTTP_HOST with the trusted listener host',
    is_string($config) && $trustedFastcgiScopes($config)
);
$check(
    'trusted listener host omits default ports and retains a non-default port',
    is_string($config) && $trustedPortMap($config)
);

$oldFalseGreen = <<<'ANGIE'
server {
    include              fastcgi_params;
    # fastcgi_param        HTTP_HOST        $server_name;
    fastcgi_param HTTP_HOST $host;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
ANGIE;
$check(
    'commented canonical assignment cannot hide an active request-derived HTTP_HOST assignment',
    strpos($oldFalseGreen, 'include              fastcgi_params;') !== false
        && strpos($oldFalseGreen, 'fastcgi_param        HTTP_HOST        $server_name;') !== false
        && !str_contains($oldFalseGreen, 'fastcgi_param        HTTP_HOST        $http_host;')
        && !$trustedFastcgiScopes($oldFalseGreen)
);

$ifNotEmptyConflict = <<<'ANGIE'
server {
    include fastcgi_params;
    fastcgi_param HTTP_HOST $vimb_trusted_http_host;
    fastcgi_param HTTP_HOST $host if_not_empty;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
ANGIE;
$check(
    'if_not_empty cannot hide a conflicting request-derived HTTP_HOST assignment',
    !$trustedFastcgiScopes($ifNotEmptyConflict)
);

$localIncludeBreaksInheritance = <<<'ANGIE'
server {
    include fastcgi_params;
    fastcgi_param HTTP_HOST $vimb_trusted_http_host;
    location /child {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
ANGIE;
$check(
    'a child FastCGI include cannot inherit a parent HTTP_HOST override',
    !$trustedFastcgiScopes($localIncludeBreaksInheritance)
);

OSS_Runtime::configure([], '/vimbadmin', new stdClass());
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'vimbadmin.example.com';

$check(
    'FastCGI canonical host produces the password-reset email origin',
    OSS_Utils::genUrl('auth', 'reset-password', false, ['username' => 'admin'])
        === 'https://vimbadmin.example.com/vimbadmin/auth/reset-password/username/admin'
);

$_SERVER['HTTP_HOST'] = 'vimbadmin.example.com:8443';
$check(
    'trusted non-default listener port remains in the password-reset email origin',
    OSS_Utils::genUrl('auth', 'reset-password', false, ['username' => 'admin'])
        === 'https://vimbadmin.example.com:8443/vimbadmin/auth/reset-password/username/admin'
);

$_SERVER['HTTP_HOST'] = ['vimbadmin.example.com'];
$malformedHostRejected = false;
try {
    OSS_Utils::genUrl('auth', 'reset-password');
} catch (TypeError) {
    $malformedHostRejected = true;
}
$check('malformed FastCGI host fails closed', $malformedHostRejected);

$_SERVER['HTTP_HOST'] = 'vimbadmin.example.com';
OSS_Runtime::configure(
    ['utils' => ['genurl' => ['host_mode' => 'REPLACE', 'host_replace' => 'reset.example.test']]],
    '/vimbadmin',
    new stdClass()
);
$check(
    'explicit configured origin remains authoritative',
    OSS_Utils::genUrl('auth', 'reset-password', false, ['username' => 'admin'])
        === 'https://reset.example.test/vimbadmin/auth/reset-password/username/admin'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
