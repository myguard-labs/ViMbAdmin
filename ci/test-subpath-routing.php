<?php

declare(strict_types=1);

require __DIR__ . '/../src/Kernel/RouteMatch.php';
require __DIR__ . '/../src/Kernel/Router.php';

use ViMbAdmin\Kernel\Router;
use RuntimeException as EntrypointRuntimeException;

/** @return array{int,string} */
function observedEntrypointPath(string $root, string $requestPath, string $scriptName): array
{
    $script = tmpfile();
    if ($script === false) {
        throw new EntrypointRuntimeException('Unable to create the entrypoint test script');
    }
    $source = <<<'PHP'
<?php
namespace ViMbAdmin\Kernel\Http {
    final class Kernel
    {
        public static string $observedPath = '';
        public static function nativeControllers(): array { return ['domain']; }
        public function __construct(object $_router) {}
        public function canHandle(string $path): bool
        {
            self::$observedPath = $path;
            return false;
        }
    }
}
namespace {
    $_SERVER['REQUEST_URI'] = __REQUEST_PATH__;
    $_SERVER['SCRIPT_NAME'] = __SCRIPT_NAME__;
    ob_start();
    include __ENTRYPOINT__;
    ob_end_clean();
    echo \ViMbAdmin\Kernel\Http\Kernel::$observedPath;
}
PHP;
    fwrite($script, str_replace(
        ['__REQUEST_PATH__', '__SCRIPT_NAME__', '__ENTRYPOINT__'],
        [var_export($requestPath, true), var_export($scriptName, true), var_export($root . '/public/index.php', true)],
        $source,
    ));
    fflush($script);
    $metadata = stream_get_meta_data($script);
    $scriptPath = $metadata['uri'] ?? null;
    if (!is_string($scriptPath)) {
        fclose($script);
        throw new EntrypointRuntimeException('Temporary entrypoint script has no filesystem path');
    }
    $process = proc_open(
        [PHP_BINARY, $scriptPath],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        fclose($script);
        throw new EntrypointRuntimeException('Unable to start the entrypoint test process');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    fclose($script);

    return [$status, $output === false ? (string) $errors : $output];
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$router = new Router(['domain', 'index']);
$route = static fn (string $path, string $base): bool =>
    $router->match(Router::withoutBasePath($path, $base)) !== null;

echo "== mounted native routing ==\n";
$check('preserved Apache mount routes a native controller', $route('/vimbadmin/domain/list', '/vimbadmin'));
$check('nested mount routes a native controller', $route('/mail/admin/domain/list', '/mail/admin'));
$check('mount with trailing slash is normalized', $route('/vimbadmin/domain/list', '/vimbadmin/'));
$check('exact mount routes the native index controller', $route('/vimbadmin', '/vimbadmin'));

echo "== root and proxy-stripped boundaries ==\n";
$check('root deployment remains native', $route('/domain/list', ''));
$check('proxy-stripped native path remains unchanged', $route('/domain/list', '/vimbadmin'));
$check('root base does not remove the request slash', Router::withoutBasePath('/domain/list', '/') === '/domain/list');
$check('similar prefix is not treated as the configured mount', !$route('/vimbadmin-old/domain/list', '/vimbadmin'));
$check('short prefix is segment-boundary aware', !$route('/vimbadmin/domain/list', '/vimb'));

echo "== malformed and rejected routes ==\n";
$check('unknown mounted controller remains rejected', !$route('/vimbadmin/not-native/list', '/vimbadmin'));
$check('encoded mount lookalike remains rejected', !$route('/%76imbadmin/domain/list', '/vimbadmin'));
$check('dot-segment route remains rejected', !$route('/vimbadmin/../domain/list', '/vimbadmin'));

echo "== front-controller integration ==\n";
$root = dirname(__DIR__);
[$status, $path] = observedEntrypointPath($root, '/vimbadmin/domain/list', '/vimbadmin/index.php');
$check('entrypoint uses the Bootstrap mount for a preserved request', $status === 0 && $path === '/domain/list');
[$status, $path] = observedEntrypointPath($root, '/domain/list', '/index.php');
$check('entrypoint preserves a proxy-stripped request', $status === 0 && $path === '/domain/list');
[$status, $path] = observedEntrypointPath($root, '/vimbadmin-old/domain/list', '/vimbadmin/index.php');
$check('entrypoint preserves a mount-prefix boundary mismatch', $status === 0 && $path === '/vimbadmin-old/domain/list');
[$status, $path] = observedEntrypointPath($root, '/domain/domain/list', '/domain/index.php');
$check('entrypoint strips a preserved mount matching a controller', $status === 0 && $path === '/domain/list');
[$status, $path] = observedEntrypointPath($root, '/domain/list', '/index.php');
$check('entrypoint does not double-strip a proxy path matching its public mount', $status === 0 && $path === '/domain/list');

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
