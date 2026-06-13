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

// Detached after-send work (e.g. the queue trigger draining autonomously):
// flush the response, close the client connection, THEN run the callback so
// the caller gets its OK and disconnects while the work continues in this same
// FPM worker — no forked process, no shell-out. Falls back to running inline
// when fastcgi_finish_request() is unavailable (CLI / non-FPM SAPI).
//
// Release the PHP session lock FIRST. With file-based sessions PHP holds an
// exclusive flock on the session file for the whole request and only frees it
// at script end. fastcgi_finish_request() flushes the response but the script
// keeps running the (potentially slow) drain, so without this the session stays
// locked for the entire backup/delete. The browser then follows the 302 to
// mailbox/list, whose session_start() blocks on that lock until the drain
// finishes — and times out as a 504. The detached work needs $em + options, not
// $_SESSION, so closing the session here is safe.
if (is_callable($response->afterSend)) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    ($response->afterSend)();
}
