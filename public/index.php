<?php

// Define path to application directory
defined('APPLICATION_PATH')
    || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));

// Define application environment
defined('APPLICATION_ENV')
    || define('APPLICATION_ENV', (getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV') : 'development'));

// composer autoloader
require_once( APPLICATION_PATH . '/../vendor/autoload.php' );

// Ensure library/ is on include_path
set_include_path(implode(PATH_SEPARATOR, array(
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
)));

// ---------------------------------------------------------------------------
// Phase 2b (docs/ZF1-REMOVAL.md): optional framework-free kernel, opt-in via
// the VIMBADMIN_NATIVE_KERNEL env flag. DEFAULT (unset / not "1") = the
// historical ZF1 path below, byte for byte. When enabled, the kernel serves
// only the routes it has been given (currently just the no-auth health probe);
// every other URL returns null from handle() and falls through to ZF1, so old
// and new dispatch run side by side.
// ---------------------------------------------------------------------------
if (getenv('VIMBADMIN_NATIVE_KERNEL') === '1') {
    $kernel = new \ViMbAdmin\Kernel\Http\Kernel(
        new \ViMbAdmin\Kernel\Router(\ViMbAdmin\Kernel\Http\Kernel::nativeControllers())
    );

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $response = $kernel->handle(is_string($path) ? $path : '/');

    if ($response !== null) {
        http_response_code($response->status);
        header('Content-Type: ' . $response->contentType);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response->body;
        return; // request fully served by the native kernel
    }
    // else: not a native route — fall through to the ZF1 front controller.
}

/** Zend_Application */
require_once 'Zend/Application.php';

// Create application, bootstrap, and run
$application = new Zend_Application(
    APPLICATION_ENV,
    APPLICATION_PATH . '/configs/application.ini'
);

// this solves the issue "Fatal error: spl_autoload() ... Class Doctrine_Event could not be loaded in Doctrine_Record ..."
register_shutdown_function( array( 'Zend_Session', 'writeClose' ), true );

$application
    ->bootstrap()
    ->run();
