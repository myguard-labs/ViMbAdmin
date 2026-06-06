#!/usr/bin/env php
<?php

/**
 * CLI script
 */

date_default_timezone_set(@date_default_timezone_get());
require_once( dirname( __FILE__ ) . '/../vendor/autoload.php' );
require_once( dirname( __FILE__ ) . '/utils.inc' );
defined( 'APPLICATION_ENV' ) || define( 'APPLICATION_ENV', scriptutils_get_application_env() );

define( 'SCRIPT_NAME', 'vimbadtool - ViMbAdmin CLI Management Tool' );
define( 'SCRIPT_COPY', '(c) Copyright 2010 - ' . date( 'Y' ) . ' Open Source Solutions Limited' );

// Show real errors, but not the framework's forward-compat deprecation noise
// (ZF1 / Smarty 5 on PHP 8.5) which would otherwise spam every CLI command.
error_reporting( E_ALL & ~E_DEPRECATED & ~E_STRICT );

ini_set( 'display_errors', true );

defined( 'APPLICATION_PATH' ) || define( 'APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application' ) );

if( getenv( 'APPLICATION_TESTING' ) )
    define( 'APPLICATION_TESTING', getenv( 'APPLICATION_TESTING' ) );
else
    define( 'APPLICATION_TESTING', 0 );

// Ensure library/ is on include_path
set_include_path( implode( PATH_SEPARATOR,
        array(
            realpath( APPLICATION_PATH . '/../library' ),
            get_include_path()
        )
    )
);

// ---------------------------------------------------------------------------
// WALL #2 (docs/ZF1-REMOVAL.md): native CLI path. Commands ported off ZF1
// (ViMbAdmin\Kernel\Cli\CliKernel) run WITHOUT Zend_Application; anything not yet
// migrated falls through to the ZF1 path below — the same opt-in strangler the
// web entry point uses. The native CliKernel boots only config + the Doctrine EM
// (no session under CLI), so a migrated command skips the full ZF1 bootstrap.
// ---------------------------------------------------------------------------
$cliOpts   = getopt( 'a:vdhc', array(
    'action:', 'verbose', 'debug', 'help', 'copyright',
    'username:', 'all', 'name:', 'scope:', 'ip:', 'domains:', 'days:', 'id:',
) );
$cliAction = isset( $cliOpts['action'] ) ? $cliOpts['action'] : ( isset( $cliOpts['a'] ) ? $cliOpts['a'] : null );

if( is_string( $cliAction ) )
{
    $cliKernel = new \ViMbAdmin\Kernel\Cli\CliKernel( APPLICATION_PATH, APPLICATION_ENV );
    if( $cliKernel->canHandle( $cliAction ) )
    {
        // Autoload the legacy library/ classes a native command reuses
        // (ViMbAdmin_Service_*, OSS_*) and the few Zend_ classes the residual
        // glue still touches — exactly as public/index.php's native zone does.
        // These are not in the composer classmap; the ZF1 path below registers
        // this autoloader only later, and the native path runs first.
        require_once 'Zend/Loader/Autoloader.php';
        $cliZendAutoloader = Zend_Loader_Autoloader::getInstance();
        $cliZendAutoloader->registerNamespace( 'OSS' );
        $cliZendAutoloader->registerNamespace( 'ViMbAdmin' );

        // Build the native resources, then wire the residual legacy glue some
        // library classes still read (the entity-preference layer
        // OSS_Doctrine2_WithPreferences fetches the EM from Zend_Registry 'd2em';
        // OSS_Utils reads 'options') — exactly as public/index.php does for the web.
        $cliContainer = $cliKernel->boot();
        Zend_Registry::set( 'd2em',    array( 'default' => $cliContainer->entityManager() ) );
        Zend_Registry::set( 'options', $cliContainer->options() );

        exit( $cliKernel->run( $cliAction, $cliOpts, $cliContainer ) );
    }
}

/** Zend_Application */
require_once 'Zend/Application.php';

// Create application, bootstrap, and run
$application = new Zend_Application( APPLICATION_ENV, APPLICATION_PATH . '/configs/application.ini' );

try
{
    $application->bootstrap();
    $bootstrap = $application->getBootstrap();
    $bootstrap->bootstrap( 'frontController' );
}
catch( Exception $e )
{
    die( print_r( $e, true ) );
}

try
{
    $opts = new Zend_Console_Getopt(
        array(
            'help|h'        => 'Displays usage information.',
            'action|a=s'    => 'Action to perform in format of module.controller.action',
            'verbose|v'     => 'Verbose messages will be dumped to the default output.',
            'debug|d'       => 'Enables debug mode.',
            'copyright|c'   => 'Display copyright information.',
            'username|u=s'  => 'Action-specific: target admin username (e.g. admin.cli-reset-totp).',
            'all'           => 'Action-specific: apply to all (e.g. admin.cli-reset-totp).',
            'name=s'        => 'MCP: token label (mcp.cli-token-generate / revoke).',
            'scope=s'       => 'MCP: token scope, e.g. "read" or "read write" (default: read).',
            'ip=s'          => 'MCP: token IP/CIDR allowlist (space/comma separated; default: any).',
            'days=i'        => 'MCP: token validity in days (default: no expiry).',
            'id=i'          => 'MCP: token id (mcp.cli-token-revoke).'
        )
    );

    $opts->parse();
}
catch( Zend_Console_Getopt_Exception $e )
{
    exit( $e->getMessage() ."\n\n". $e->getUsageMessage() );
}

if( isset( $opts->h ) )
{
    echo SCRIPT_NAME . "\n" . SCRIPT_COPY . "\n\n";
    echo $opts->getUsageMessage();

    exit;
}

if( isset( $opts->c ) )
{
    echo SCRIPT_NAME . "\n" . SCRIPT_COPY . "\n\n";
    echo "Information in this file is strictly confidential and the property of\n"
         . "Open Source Solutions Limited and may not be extracted or distributed,\n"
         . "in whole or in part, for any purpose whatsoever, without the express\n"
         . "written consent from Open Source Solutions Limited.\n\n";

    exit;
}

if( isset( $opts->a ) )
{
    try
    {
        $reqRoute = array_reverse( explode( '.', $opts->a ) );

        @list( $action, $controller, $module ) = $reqRoute;

        if ( ($action != '') && ($controller == '') )
        {
            $controller = $action;
            $action = 'index';
        }

        if ( $opts->d )
        {
            echo "Module:     $module\n";
            echo "Controller: $controller\n";
            echo "Action:     $action\n\n";
        }

        $front = $bootstrap->frontController;

        $front->throwExceptions( true );

        $front->setRequest(  new Zend_Controller_Request_Simple( $action, $controller, $module ) );
        $front->setRouter(   new OSS_Controller_Router_Cli() );
        $front->setResponse( new Zend_Controller_Response_Cli() );

        $front->setParam( 'noViewRenderer', true )
              ->setParam( 'disableOutputBuffering', true );
              
        if( $opts->v )
            $front->getRequest()->setParam( 'verbose', true );

        if( $opts->d )
        {
            $front->getRequest()->setParam( 'verbose', true );
            $front->getRequest()->setParam( 'debug', true );
        }

        // Forward action-specific named options to the request so controllers
        // can read them via $this->getRequest()->getParam( ... ).
        foreach( array( 'username', 'all', 'name', 'scope', 'ip', 'days', 'id' ) as $__opt )
            if( isset( $opts->$__opt ) )
                $front->getRequest()->setParam( $__opt, $opts->$__opt );

        $front->addModuleDirectory( APPLICATION_PATH . '/modules');

        $application->run();
    }
    catch( Exception $e )
    {
        echo "ERROR: " . $e->getMessage() . "\n\n";

        if( $opts->v )
        {
            echo $e->getTraceAsString();
        }
    }
}
else
{
    echo "\n\nERROR: no action specified. Please use --help for instructions.\n\n";
}
