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

/** Zend_Application */
require_once 'Zend/Application.php';

// Create application + bootstrap. The bootstrap builds the shared resources
// (Doctrine EM, Smarty view, auth, session); both the ZF1 front controller AND
// the optional native kernel (below) reuse them, so it always runs first.
$application = new Zend_Application(
    APPLICATION_ENV,
    APPLICATION_PATH . '/configs/application.ini'
);

// this solves the issue "Fatal error: spl_autoload() ... Class Doctrine_Event could not be loaded in Doctrine_Record ..."
register_shutdown_function( array( 'Zend_Session', 'writeClose' ), true );

$application->bootstrap();

// ---------------------------------------------------------------------------
// Phase 2b/3 (docs/ZF1-REMOVAL.md): optional framework-free kernel, opt-in via
// the VIMBADMIN_NATIVE_KERNEL env flag. DEFAULT (unset / not "1") = the
// historical ZF1 run() below, byte for byte. When enabled, the kernel serves
// the routes it has been given (the health probe + the migrated controllers in
// Kernel::NATIVE_CONTROLLERS); every other URL returns null from handle() and
// falls through to ZF1, so old and new dispatch run side by side.
//
// The native controllers REUSE the bootstrap's resources via the Container; the
// only Zend-specific wiring is the identity bridge for the Auth service — built
// here (a ZF1 zone) the same way ViMbAdmin_Controller_Action::_nativeAuth() does,
// so the kernel's src/ tree stays framework-free.
// ---------------------------------------------------------------------------
if (getenv('VIMBADMIN_NATIVE_KERNEL') === '1') {
    $bootstrap = $application->getBootstrap();
    $em        = $bootstrap->getResource('doctrine2');

    $auth = new \ViMbAdmin\Kernel\Security\Auth(
        new \ViMbAdmin\Kernel\Session\MagicPropertyStorage( new Zend_Session_Namespace( 'Zend_Auth' ) ),
        static function( int $id ) use ( $em ) {
            return $em->getRepository( '\\Entities\\Admin' )->find( $id );
        },
        'storage'
    );

    $container  = new \ViMbAdmin\Kernel\Container( $bootstrap, $auth );
    $dispatcher = new \ViMbAdmin\Kernel\Mvc\Dispatcher( $container, \ViMbAdmin\Kernel\Http\Kernel::NATIVE_CONTROLLERS );
    $kernel     = new \ViMbAdmin\Kernel\Http\Kernel(
        new \ViMbAdmin\Kernel\Router( \ViMbAdmin\Kernel\Http\Kernel::nativeControllers() ),
        $dispatcher
    );

    $path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
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

$application->run();
