<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel {
    // Stop exactly at resource construction. The entry point, real router,
    // controller reflection and configured autoloader all run unchanged.
    final class Bootstrap
    {
        public static function scriptBaseUrl(): string
        {
            return '';
        }

        public static function boot(string $appPath, string $environment, string $authNamespace): never
        {
            throw new \RuntimeException('entry-autoload probe reached resource construction');
        }
    }
}

namespace {
    [$script, $root, $application, $entry, $route] = $argv;
    unset($script);
    define('APPLICATION_PATH', $application);
    define('APPLICATION_ENV', 'probe');
    require $root . '/vendor/autoload.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = $route === 'malformed' ? [] : $route;
    ob_start();
    require $entry;
    $body = ob_get_clean();
    $entity = class_exists('Entities\\Admin', false);
    $repository = $entity && class_exists('Repositories\\Domain');
    echo json_encode([
        'status' => http_response_code(),
        'body' => $body,
        'session' => session_status(),
        'entity' => $entity,
        'entityFile' => $entity ? (new \ReflectionClass('Entities\\Admin'))->getFileName() : null,
        'repository' => $repository,
        'repositoryFile' => $repository ? (new \ReflectionClass('Repositories\\Domain'))->getFileName() : null,
    ], JSON_THROW_ON_ERROR);
}
