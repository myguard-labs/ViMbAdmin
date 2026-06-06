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
// WALL #2 (docs/ZF1-REMOVAL.md): the NATIVE BOOTSTRAP is now the DEFAULT path.
// The whole interactive admin UI runs with resources built natively
// (ViMbAdmin\Kernel\Bootstrap: config + Doctrine EM + session + Smarty + auth)
// WITHOUT ever constructing Zend_Application. Set VIMBADMIN_NATIVE_BOOTSTRAP=0 to
// force the historical ZF1 Zend_Application path below (the escape hatch).
//
// Routing happens FIRST, before any resource is built: only a path the kernel
// can serve boots the native stack. Every other path — the remaining non-UI
// tail (mailer/CLI/remote) — reaches the ZF1 path with NOTHING started, which is
// essential: the native bootstrap opens the PHP session, and ZF1's
// Zend_Session::start() fatals if a session is already active. For the one case
// that can only be known after dispatch (a native controller whose specific
// action is not yet ported, e.g. mailbox emailSettings), the session the
// bootstrap opened is closed before falling through.
//
// A thin Zend autoloader is still registered: the legacy library/ classes the
// native controllers reuse (ViMbAdmin_Service_*, OSS_*) and the template helpers
// (OSS_Utils::genUrl reading Zend_Registry / the front-controller base URL) are
// not yet de-Zended. Bootstrap stays framework-free; this ZF1-aware entry point
// wires those residual shims around the Container it returns. Removing the shims
// (and this autoloader) is the final cleanup slice.
// ---------------------------------------------------------------------------
if (getenv('VIMBADMIN_NATIVE_BOOTSTRAP') !== '0') {
    require_once 'Zend/Loader/Autoloader.php';
    $zendAutoloader = Zend_Loader_Autoloader::getInstance();
    $zendAutoloader->registerNamespace('OSS');
    $zendAutoloader->registerNamespace('ViMbAdmin');

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    // Route before building anything: only a path the kernel can fully serve
    // boots the native stack. A non-native path (the mailer/CLI/remote tail)
    // reaches the ZF1 path with NOTHING started — essential, because the native
    // bootstrap opens the PHP session and Zend_Session::start() fatals on the
    // already-defined SID. canHandle() decides this without any resource.
    $router = new \ViMbAdmin\Kernel\Router(\ViMbAdmin\Kernel\Http\Kernel::nativeControllers());
    if ((new \ViMbAdmin\Kernel\Http\Kernel($router))->canHandle($path)) {
        $container = \ViMbAdmin\Kernel\Bootstrap::boot(APPLICATION_PATH, APPLICATION_ENV, 'Zend_Auth');
        $options   = $container->options();

        // Residual ZF1 glue the OSS template helpers still read.
        Zend_Registry::set('options', $options);
        Zend_Registry::set('d2em', array('default' => $container->entityManager()));
        Zend_Controller_Front::getInstance()->setBaseUrl(\ViMbAdmin\Kernel\Bootstrap::baseUrl());

        // Bridge ZF1 Zend_Session to the already-open native PHP session. A few
        // legacy helpers the native controllers still reuse instantiate a
        // Zend_Session_Namespace (e.g. the OSS/Zend image captcha on
        // auth/lost-password). Zend_Session::start() fatals when a PHP session is
        // already active that it did not itself start (the native bootstrap opened
        // it), so mark it started — the namespace then attaches to the live native
        // session. (6c removes the ZF1 captcha and this shim.)
        if (session_status() === PHP_SESSION_ACTIVE && !Zend_Session::isStarted()) {
            // Set the three flags Zend_Session::start() would have set, so a
            // namespace is started + readable + writable against the native session.
            foreach (['_sessionStarted', '_readable', '_writable'] as $zProp) {
                $rp = new ReflectionProperty('Zend_Session', $zProp);
                $rp->setAccessible(true);
                $rp->setValue(null, true);
            }
        }

        $kernel = new \ViMbAdmin\Kernel\Http\Kernel(
            $router,
            new \ViMbAdmin\Kernel\Mvc\Dispatcher($container, \ViMbAdmin\Kernel\Http\Kernel::NATIVE_CONTROLLERS)
        );

        $response = $kernel->handle($path);

        http_response_code($response->status);
        header('Content-Type: ' . $response->contentType);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response->body;
        return; // request fully served by the native kernel, no ZF1 at all
    }
    // else: non-native path — nothing booted, clean fall through to the ZF1 path.
}

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

    // The ZF1 base controller registers these in Zend_Registry on construction
    // (OSS_Controller_Action), and view helpers such as genUrl read them
    // (Zend_Registry::get('options')). A native render never constructs a ZF1
    // controller, so register them here once before dispatch — same keys, same
    // values — or the chrome templates fatal with "No entry registered for
    // key 'options'".
    $options = $bootstrap->getOptions();
    Zend_Registry::set( 'options', $options );
    Zend_Registry::set( 'bootstrap', $bootstrap );
    // Some native-served code paths reuse legacy helpers that fetch the entity
    // manager from the registry (e.g. ViMbAdmin_TwoFactor / OSS WithPreferences
    // via Zend_Registry::get('d2em')['default']); the ZF1 Doctrine2 trait
    // registers it lazily as a connection-keyed array on first getD2EM(), which a
    // native request never calls. Register the same shape here.
    Zend_Registry::set( 'd2em', [ 'default' => $em ] );

    // Pre-compute the chrome view vars a native page render needs that depend on
    // the ZF1 front controller (here: the skin stylesheet URL — mirrors
    // ViMbAdmin_Controller_Action::_skinCssUrl()). Done in this ZF1 zone and
    // injected so AbstractController::view() stays framework-free.
    $skin    = isset( $options['resources']['smarty']['skin'] )
        ? trim( (string) $options['resources']['smarty']['skin'] ) : '';
    $skinCss = '';
    if ( $skin !== '' && preg_match( '/^[A-Za-z0-9_-]+$/', $skin ) ) {
        $rel = 'css/_skins/' . $skin . '/skin.css';
        if ( is_readable( APPLICATION_PATH . '/../public/' . $rel ) ) {
            $skinCss = rtrim( (string) Zend_Controller_Front::getInstance()->getBaseUrl(), '/' ) . '/' . $rel;
        }
    }

    $container  = new \ViMbAdmin\Kernel\Container( $bootstrap, $auth, [ 'skinCss' => $skinCss ] );
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
