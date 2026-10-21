<?php

declare(strict_types=1);

$public = getenv('VIMBADMIN_FIXTURE_PUBLIC');
$public = is_string($public) ? realpath($public) : false;
if ($public === false || !is_file($public . '/index.php')) {
    http_response_code(500);
    echo "Internal server error\n";
    return true;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : false;
if (is_string($requestPath)) {
    $candidate = realpath($public . '/' . ltrim(rawurldecode($requestPath), '/'));
    if (
        $candidate !== false
        && $candidate !== $public . '/index.php'
        && str_starts_with($candidate, $public . '/')
        && is_file($candidate)
    ) {
        return false;
    }
}

// These two injection triggers only alter the entry boundary. The response
// still comes from the checked-out public/index.php and its normal status
// handling. /auth/%zz reaches this router unchanged, then deliberately becomes
// a non-string URI; this exercises the entry type guard, not percent decoding.
if ($requestUri === '/auth/%zz' || isset($_SERVER['HTTP_X_VIMBADMIN_FIXTURE_MALFORMED_URI'])) {
    $_SERVER['REQUEST_URI'] = [];
}
if (isset($_SERVER['HTTP_X_VIMBADMIN_FIXTURE_BOOTSTRAP_FAILURE'])) {
    define('APPLICATION_ENV', 'browser-fixture-failure');
}

// PHP's development router otherwise reports the requested route itself as
// SCRIPT_NAME. A real front-controller deployment reports /index.php; preserve
// that deployment contract so Bootstrap does not mistake /auth for a base path.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
require $public . '/index.php';
return true;
