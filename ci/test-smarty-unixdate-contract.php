<?php

declare(strict_types=1);

/**
 * Unixdate contract support: literal calendar expectations and registered
 * renders exercise the maintained callback through the locked Smarty package.
 * Package-only Smarty loading intentionally avoids unrelated application
 * dependency floors; this is not a full application bootstrap test.
 */
function viSmartyUnixdateContractCheck(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

/** @return array{version:string,reference:string} */
function viSmartyUnixdatePackageMetadata(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read Smarty package metadata: ' . $path);
    }
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Smarty package metadata must be an object.');
    }
    foreach (['packages', 'packages-dev'] as $group) {
        $packages = $data[$group] ?? null;
        if ($group === 'packages-dev' && $packages === null) {
            continue;
        }
        if (!is_array($packages)) {
            throw new RuntimeException('Smarty package metadata requires an array of packages.');
        }
        foreach ($packages as $package) {
            if (!is_array($package) || ($package['name'] ?? null) !== 'smarty/smarty') {
                continue;
            }
            $version = $package['version'] ?? null;
            $source = $package['source'] ?? null;
            $reference = is_array($source) ? ($source['reference'] ?? null) : null;
            if (!is_string($version) || !is_string($reference)) {
                throw new RuntimeException('Smarty version and source reference must be strings.');
            }
            return ['version' => $version, 'reference' => $reference];
        }
    }
    throw new RuntimeException('Smarty package metadata is missing.');
}

/** @param array<string, Closure> $cases */
function viSmartyUnixdateContractRun(string $plugin, array $cases): void
{
    $root = getenv('VIMBADMIN_ROOT') ?: dirname(__DIR__);
    $vendor = getenv('SMARTY_DATES_VENDOR') ?: $root . '/vendor';
    $source = getenv('SMARTY_DATE_CALLBACK_FILE')
        ?: $root . '/library/ViMbAdmin/Smarty/functions/modifier.' . $plugin . '.php';
    $locked = viSmartyUnixdatePackageMetadata($root . '/composer.lock');
    $installed = viSmartyUnixdatePackageMetadata($vendor . '/composer/installed.json');
    viSmartyUnixdateContractCheck('locked Smarty version', $installed['version'], $locked['version']);
    viSmartyUnixdateContractCheck('locked Smarty source', $installed['reference'], $locked['reference']);
    $autoload = static function (string $class) use ($vendor): void {
        if (str_starts_with($class, 'Smarty\\')) {
            $path = $vendor . '/smarty/smarty/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    };
    spl_autoload_register($autoload);
    $timezone = date_default_timezone_get();
    $locale = setlocale(LC_TIME, '0');
    $reporting = error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $directory = sys_get_temp_dir() . '/smarty-dates-' . bin2hex(random_bytes(8));
    $failures = 0;
    try {
        mkdir($directory, 0700);
        require $source;
        viSmartyUnixdateContractCheck('C locale available', setlocale(LC_TIME, 'C'), 'C');
        $smarty = new Smarty\Smarty();
        viSmartyUnixdateContractCheck('locked engine loaded', Smarty\Smarty::SMARTY_VERSION, ltrim($locked['version'], 'v'));
        $smarty->setCompileDir($directory);
        $smarty->setCacheDir($directory);
        $smarty->setCaching(Smarty\Smarty::CACHING_OFF);
        $callback = 'smarty_modifier_' . $plugin;
        if (!is_callable($callback)) {
            throw new RuntimeException('Smarty date callback must be callable.');
        }
        $smarty->registerPlugin('modifier', $plugin, $callback);
        $arguments = $_SERVER['argv'] ?? [];
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Date contract arguments must be an array.');
        }
        $selected = $arguments[1] ?? 'all';
        if (!is_string($selected)) {
            throw new InvalidArgumentException('Named date contract must be a string.');
        }
        if ($selected !== 'all' && !array_key_exists($selected, $cases)) {
            throw new InvalidArgumentException('Unknown named date contract');
        }
        foreach ($cases as $name => $case) {
            if ($selected !== 'all' && $selected !== $name) {
                continue;
            }
            date_default_timezone_set('UTC');
            try {
                $case($smarty);
                echo 'PASS ' . $plugin . '.' . $name . "\n";
            } catch (Throwable $error) {
                ++$failures;
                echo 'FAIL ' . $plugin . '.' . $name . ': ' . get_class($error) . ': ' . $error->getMessage() . "\n";
            }
        }
    } finally {
        date_default_timezone_set($timezone);
        if ($locale !== false) {
            setlocale(LC_TIME, $locale);
        }
        restore_error_handler();
        error_reporting($reporting);
        spl_autoload_unregister($autoload);
        if (is_dir($directory)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo) {
                    throw new UnexpectedValueException('Smarty cleanup entries must be files.');
                }
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }
    echo 'LIMIT: timestamp arrays/objects are outside the documented input union; no rejection policy invented.' . "\n";
    echo 'RESULT ' . $plugin . ' failures=' . $failures . ' PHP=' . PHP_VERSION . "\n";
    if ($failures === 0) {
        echo "OK: all Smarty unixdate assertions passed (PHP " . PHP_VERSION . ")\n";
    }
    exit($failures === 0 ? 0 : 1);
}

// The callback's @param contract and mailbox/list.phtml:62 use Unix seconds;
// format arguments are PHP date() formats, not strftime percent directives.
viSmartyUnixdateContractRun('unixdate', [
    'registered_epoch' => static function (Smarty\Smarty $smarty): void {
        $smarty->assign('value', 1);
        viSmartyUnixdateContractCheck(
            'registered-only Unix seconds',
            $smarty->fetch('string:{$value|unixdate}'),
            '1970-01-01 00:00',
        );
    },
    'utc_epoch' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_unixdate')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_unixdate');
        }

        viSmartyUnixdateContractCheck('calendar oracle', date('Y-m-d H:i', 1), '1970-01-01 00:00');
        foreach ([1, '1'] as $value) {
            viSmartyUnixdateContractCheck('default Unix seconds', smarty_modifier_unixdate($value), '1970-01-01 00:00');
            viSmartyUnixdateContractCheck('explicit PHP format', smarty_modifier_unixdate($value, 'Y-m-d'), '1970-01-01');
            $smarty->assign('value', $value);
            viSmartyUnixdateContractCheck('registered default', $smarty->fetch('string:{$value|unixdate}'), '1970-01-01 00:00');
            viSmartyUnixdateContractCheck(
                'registered explicit format',
                $smarty->fetch('string:{$value|unixdate:"Y-m-d"}'),
                '1970-01-01',
            );
        }
    },
    'timezone' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_unixdate')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_unixdate');
        }

        date_default_timezone_set('America/New_York');
        viSmartyUnixdateContractCheck('calendar oracle', date('Y-m-d H:i:s', 1), '1969-12-31 19:00:01');
        viSmartyUnixdateContractCheck('callback local timezone', smarty_modifier_unixdate(1, 'Y-m-d H:i:s'), '1969-12-31 19:00:01');
        $smarty->assign('value', 1);
        viSmartyUnixdateContractCheck(
            'registered local timezone',
            $smarty->fetch('string:{$value|unixdate:"Y-m-d H:i:s"}'),
            '1969-12-31 19:00:01',
        );
    },
    'empty' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_unixdate')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_unixdate');
        }

        foreach ([null, '', 0, '0'] as $value) {
            viSmartyUnixdateContractCheck('documented empty timestamp', smarty_modifier_unixdate($value), '');
            $smarty->assign('value', $value);
            viSmartyUnixdateContractCheck('registered empty timestamp', $smarty->fetch('string:{$value|unixdate}'), '');
        }
    },
    'malformed_format' => static function (): void {
        $rejected = false;
        try {
            (new ReflectionFunction('smarty_modifier_unixdate'))->invoke(1, []);
        } catch (TypeError) {
            $rejected = true;
        }
        viSmartyUnixdateContractCheck('format array rejected by PHP date builtin', $rejected, true);
    },
]);
