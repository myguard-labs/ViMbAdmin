#!/usr/bin/env php
<?php

date_default_timezone_set(@date_default_timezone_get());
require_once dirname(__FILE__) . '/../vendor/autoload.php';

defined('APPLICATION_ENV') || define('APPLICATION_ENV', getenv('APPLICATION_ENV') ?: 'development');
defined('APPLICATION_PATH') || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));

const SCRIPT_NAME = 'vimbadtool - ViMbAdmin CLI Management Tool';
define('SCRIPT_COPY', '(c) Copyright 2010 - ' . date('Y') . ' Open Source Solutions Limited');

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

set_include_path(implode(PATH_SEPARATOR, [
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
]));

$opts = getopt('a:vdhc', [
    'action:', 'verbose', 'debug', 'help', 'copyright',
    'username:', 'all', 'name:', 'scope:', 'ip:', 'domains:', 'days:', 'id:',
]);

if (isset($opts['h']) || isset($opts['help'])) {
    echo SCRIPT_NAME . "\n";
    echo "Usage: vimbtool.php -a controller.action [options]\n\n";
    echo "Actions:\n";
    echo "  queue.cli-run\n  admin.cli-reset-totp\n";
    echo "  maintenance.cli-schema-update\n  maintenance.cli-precompile-templates\n  mcp.cli-token-generate\n";
    echo "  mcp.cli-token-list\n  mcp.cli-token-revoke\n";
    exit(0);
}

if (isset($opts['c']) || isset($opts['copyright'])) {
    echo SCRIPT_NAME . "\n" . SCRIPT_COPY . "\n";
    exit(0);
}

$action = $opts['action'] ?? $opts['a'] ?? null;
if (!is_string($action) || $action === '') {
    fwrite(STDERR, "ERROR: no action specified. Use --help for instructions.\n");
    exit(1);
}

$kernel = new \ViMbAdmin\Kernel\Cli\CliKernel(APPLICATION_PATH, APPLICATION_ENV);
if (!$kernel->canHandle($action)) {
    fwrite(STDERR, "ERROR: unknown action '{$action}'. Use --help for supported actions.\n");
    exit(1);
}

$container = $kernel->boot();
exit($kernel->run($action, $opts, $container));
