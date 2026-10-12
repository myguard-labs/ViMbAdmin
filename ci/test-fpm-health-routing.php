<?php

declare(strict_types=1);

namespace ViMbAdmin\Tests;

use RuntimeException;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array{default:int,exact:array<string,int>,regex:list<array{string,bool,int}>} */
$parseRouteMap = static function (string $config): array {
    if (preg_match('/^map \$uri \$vimb_bad_route \{(.*?)^\}/ms', $config, $match) !== 1) {
        throw new RuntimeException('route map is missing');
    }

    $default = null;
    $exact = [];
    $regex = [];
    foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
        $line = trim((string) preg_replace('/\s+#.*$/', '', $line));
        if ($line === '') {
            continue;
        }
        if (preg_match('/^default\s+([01]);$/', $line, $entry) === 1) {
            if ($default !== null) {
                throw new RuntimeException('route map has duplicate defaults');
            }
            $default = (int) $entry[1];
            continue;
        }
        if (preg_match('/^"((?:\\\\.|[^"])*)"\s+([01]);$/', $line, $entry) !== 1) {
            throw new RuntimeException("malformed route map entry: {$line}");
        }

        $key = str_replace(['\\\\', '\\"'], ['\\', '"'], $entry[1]);
        $value = (int) $entry[2];
        if (str_starts_with($key, '~*')) {
            $regex[] = [substr($key, 2), true, $value];
        } elseif (str_starts_with($key, '~')) {
            $regex[] = [substr($key, 1), false, $value];
        } else {
            $caseInsensitiveKey = strtolower($key);
            if (array_key_exists($caseInsensitiveKey, $exact)) {
                throw new RuntimeException("duplicate exact route: {$key}");
            }
            $exact[$caseInsensitiveKey] = $value;
        }
    }
    if ($default === null) {
        throw new RuntimeException('route map has no default');
    }

    return ['default' => $default, 'exact' => $exact, 'regex' => $regex];
};

/** @param array{default:int,exact:array<string,int>,regex:list<array{string,bool,int}>} $map */
function classifyFpmHealthRoute(array $map, string $uri): int
{
    $caseInsensitiveUri = strtolower($uri);
    if (array_key_exists($caseInsensitiveUri, $map['exact'])) {
        return $map['exact'][$caseInsensitiveUri];
    }
    foreach ($map['regex'] as [$pattern, $caseInsensitive, $value]) {
        $delimiterSafe = str_replace('#', '\#', $pattern);
        $matched = preg_match("#{$delimiterSafe}#" . ($caseInsensitive ? 'i' : ''), $uri);
        if ($matched === false) {
            throw new RuntimeException("invalid route-map regex: {$pattern}");
        }
        if ($matched === 1) {
            return $value;
        }
    }

    return $map['default'];
}

$assignment = static function (string $config, string $name): ?string {
    $quoted = preg_quote($name, '/');
    return preg_match("/^{$quoted}\s*=\s*([^;\s]+)(?:\s*;.*)?$/m", $config, $match) === 1
        ? $match[1]
        : null;
};

$exactLocation = static function (string $config, string $path): ?string {
    $quoted = preg_quote($path, '/');
    return preg_match("/^\s*location\s+=\s+{$quoted}\s*\{(.*?)^\s*\}/ms", $config, $match) === 1
        ? $match[1]
        : null;
};

$accessPolicy = static function (string $body): array {
    preg_match_all('/\b(allow|deny)\s+([^;]+);/', $body, $matches, PREG_SET_ORDER);

    return array_map(
        static fn (array $match): string => $match[1] . ' ' . trim($match[2]),
        $matches,
    );
};

$angie = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
$pool = file_get_contents(__DIR__ . '/../contrib/php-fpm/vimbadmin.conf');
$check('error: shipped Angie and PHP-FPM configurations are readable', is_string($angie) && is_string($pool));

$routeMap = null;
try {
    $routeMap = is_string($angie) ? $parseRouteMap($angie) : null;
} catch (RuntimeException $exception) {
    echo '  INFO route-map parse error: ' . $exception->getMessage() . "\n";
}
$check('error: shipped route map is complete and parseable', is_array($routeMap));

foreach (['/fpm-status', '/fpm-ping'] as $path) {
    $check(
        "positive: exact health route {$path} passes route classification",
        is_array($routeMap) && classifyFpmHealthRoute($routeMap, $path) === 0,
    );
}

foreach (['/fpm-status/', '/fpm-ping/', '/fpm-statusx', '/FPM-status'] as $path) {
    $check(
        "boundary: near-miss health route {$path} remains denied",
        is_array($routeMap) && classifyFpmHealthRoute($routeMap, $path) === 1,
    );
}

$malformedRejected = false;
try {
    $parseRouteMap("map \$uri \$vimb_bad_route {\n    default 1\n}\n");
} catch (RuntimeException) {
    $malformedRejected = true;
}
$check('malformed: invalid route-map syntax is rejected by the regression parser', $malformedRejected);

$socket = is_string($pool) ? $assignment($pool, 'listen') : null;
$healthTargets = [
    'pm.status_path' => is_string($pool) ? $assignment($pool, 'pm.status_path') : null,
    'ping.path' => is_string($pool) ? $assignment($pool, 'ping.path') : null,
];
foreach ($healthTargets as $setting => $path) {
    $body = is_string($angie) && is_string($path) ? $exactLocation($angie, $path) : null;
    $check(
        "negative: {$setting} keeps loopback allow/default-deny access",
        is_string($body) && $accessPolicy($body) === ['allow 127.0.0.1', 'allow ::1', 'deny all'],
    );
    $check(
        "positive: {$setting} reaches its configured FPM Unix socket",
        is_string($body)
            && is_string($socket)
            && preg_match('/^\s*fastcgi_pass\s+unix:' . preg_quote($socket, '/') . '\s*;/m', $body) === 1,
    );
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
