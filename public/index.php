<?php

defined('APPLICATION_PATH')
    || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));
defined('APPLICATION_ENV')
    || define('APPLICATION_ENV', getenv('APPLICATION_ENV') ?: 'development');

require_once APPLICATION_PATH . '/../vendor/autoload.php';

set_include_path(implode(PATH_SEPARATOR, [
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
]));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$router = new \ViMbAdmin\Kernel\Router(\ViMbAdmin\Kernel\Http\Kernel::nativeControllers());
$probe = new \ViMbAdmin\Kernel\Http\Kernel($router);

if (!$probe->canHandle($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    return;
}

try {
    $container = \ViMbAdmin\Kernel\Bootstrap::boot(APPLICATION_PATH, APPLICATION_ENV, 'ViMbAdmin_Auth');
    $kernel = new \ViMbAdmin\Kernel\Http\Kernel(
        $router,
        new \ViMbAdmin\Kernel\Mvc\Dispatcher(
            $container,
            \ViMbAdmin\Kernel\Http\Kernel::NATIVE_CONTROLLERS
        )
    );
    $response = $kernel->handle($path);
} catch (\Throwable $e) {
    error_log('ViMbAdmin request failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Internal server error\n";
    return;
}

if ($response === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    return;
}

http_response_code($response->status);
header('Content-Type: ' . $response->contentType);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
