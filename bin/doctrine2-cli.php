#!/usr/bin/env php
<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once dirname(__FILE__) . '/../vendor/autoload.php';

defined('APPLICATION_PATH') || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));
defined('APPLICATION_ENV') || define('APPLICATION_ENV', getenv('APPLICATION_ENV') ?: 'development');

set_include_path(implode(PATH_SEPARATOR, [
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
]));

if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === '--database') {
    if (($_SERVER['argv'][2] ?? 'default') !== 'default') {
        fwrite(STDERR, "Only the default database connection is supported.\n");
        exit(1);
    }
    array_splice($_SERVER['argv'], 1, 2);
}

$container = \ViMbAdmin\Kernel\Bootstrap::boot(APPLICATION_PATH, APPLICATION_ENV, 'cli');
$provider = new \Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider(
    $container->entityManager()
);

\Doctrine\ORM\Tools\Console\ConsoleRunner::run($provider);
