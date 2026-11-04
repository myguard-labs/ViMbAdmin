<?php

declare(strict_types=1);

/**
 * PHP06 date contracts: plugin docs and active footer templates distinguish
 * PHP date() formats from the legacy strftime dialect. Literal UTC expectations
 * below are calendar facts, not a copy of the callback's conversion algorithm.
 * Package-only Smarty loading intentionally avoids unrelated application
 * dependency floors; this is not a full application bootstrap test.
 */
function vimbadminDateFormatterContractCheck(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

/** @return array{version:string, reference:string} */
function vimbadminDateFormatterSmartyPackage(mixed $metadata): array
{
    if (!is_array($metadata) || !isset($metadata['packages']) || !is_array($metadata['packages'])) {
        throw new RuntimeException('Smarty package metadata requires a packages array');
    }
    foreach ($metadata['packages'] as $package) {
        if (!is_array($package) || ($package['name'] ?? null) !== 'smarty/smarty') {
            continue;
        }
        if (!isset($package['version']) || !is_string($package['version'])
            || !isset($package['source']) || !is_array($package['source'])
            || !isset($package['source']['reference']) || !is_string($package['source']['reference'])) {
            throw new RuntimeException('Smarty package version and source reference must be strings');
        }
        return ['version' => $package['version'], 'reference' => $package['source']['reference']];
    }
    throw new RuntimeException('Smarty package metadata is missing');
}

/** @return array{version:string, reference:string} */
function vimbadminDateFormatterReadPackage(string $path): array
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Cannot read Smarty package metadata');
    }
    return vimbadminDateFormatterSmartyPackage(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
}

function vimbadminDateFormatterEventCount(Closure $events): int
{
    $count = $events();
    if (!is_int($count)) {
        throw new RuntimeException('Dependency deprecation count must be an integer');
    }
    return $count;
}

/** @param array<string, Closure> $cases */
function vimbadminDateFormatterContractRun(string $plugin, array $cases): void
{
    $root = getenv('VIMBADMIN_ROOT') ?: dirname(__DIR__);
    $vendor = getenv('SMARTY_DATES_VENDOR') ?: $root . '/vendor';
    $source = getenv('SMARTY_DATE_CALLBACK_FILE')
        ?: $root . '/library/ViMbAdmin/Smarty/functions/modifier.' . $plugin . '.php';
    $locked = vimbadminDateFormatterReadPackage($root . '/composer.lock');
    $installed = vimbadminDateFormatterReadPackage($vendor . '/composer/installed.json');
    vimbadminDateFormatterContractCheck('locked Smarty version', $installed['version'], $locked['version']);
    vimbadminDateFormatterContractCheck('locked Smarty source', $installed['reference'], $locked['reference']);
    $autoload = static function (string $class) use ($vendor): void {
        if (str_starts_with($class, 'Smarty\\')) {
            $path = $vendor . '/smarty/smarty/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    };
    spl_autoload_register($autoload);
    // Composer loads this declared Smarty autoload file in the application.
    require_once $vendor . '/smarty/smarty/src/functions.php';
    $legacyDependency = realpath($vendor . '/smarty/smarty/src/Extension/DefaultExtension.php');
    $dependencyDeprecations = [];
    $timezone = date_default_timezone_get();
    $locale = setlocale(LC_TIME, '0');
    $reporting = error_reporting(E_ALL);
    set_error_handler(static function (
        int $severity,
        string $message,
        string $file,
        int $line,
    ) use (
        $legacyDependency,
        &$dependencyDeprecations,
    ): bool {
        // Qualify only the locked dependency's explicitly masked strftime
        // deprecation. All unmasked or unrelated diagnostics remain errors.
        if (
            $severity === E_DEPRECATED && str_contains($message, 'strftime')
            && realpath($file) === $legacyDependency && (error_reporting() & $severity) === 0
        ) {
            $dependencyDeprecations[] = $severity;
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $directory = sys_get_temp_dir() . '/smarty-dates-' . bin2hex(random_bytes(8));
    $failures = 0;
    try {
        mkdir($directory, 0700);
        require $source;
        vimbadminDateFormatterContractCheck('C locale available', setlocale(LC_TIME, 'C'), 'C');
        $smarty = new Smarty\Smarty();
        vimbadminDateFormatterContractCheck(
            'locked engine loaded',
            Smarty\Smarty::SMARTY_VERSION,
            ltrim($locked['version'], 'v'),
        );
        $smarty->setCompileDir($directory);
        $smarty->setCacheDir($directory);
        $smarty->setCaching(Smarty\Smarty::CACHING_OFF);
        $callback = 'smarty_modifier_' . $plugin;
        if (!is_callable($callback)) {
            throw new RuntimeException('Date modifier callback is unavailable');
        }
        $smarty->registerPlugin('modifier', $plugin, $callback);
        $arguments = $_SERVER['argv'] ?? [];
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Date contract arguments must be an array');
        }
        $selected = $arguments[1] ?? 'all';
        if (!is_string($selected)) {
            throw new InvalidArgumentException('Date contract selection must be a string');
        }
        if ($selected !== 'all' && !array_key_exists($selected, $cases)) {
            throw new InvalidArgumentException('Unknown named date contract');
        }
        foreach ($cases as $name => $case) {
            if ($selected !== 'all' && $selected !== $name) {
                continue;
            }
            date_default_timezone_set('UTC');
            setlocale(LC_TIME, 'C');
            try {
                $case($smarty, static function () use (&$dependencyDeprecations): int {
                    return count($dependencyDeprecations);
                });
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
                    throw new RuntimeException('Date contract cleanup requires file information');
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
        echo 'OK: all Smarty ' . $plugin . ' assertions passed (PHP ' . PHP_VERSION . ")\n";
    }
    exit($failures === 0 ? 0 : 1);
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (!is_string($script) || realpath($script) !== __FILE__) {
    return;
}

vimbadminDateFormatterContractRun('date_formatter', [
    'utc_epoch' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        vimbadminDateFormatterContractCheck('PHP date calendar oracle', date('Y-m-d', 1), '1970-01-01');
        foreach ([1, '1', 0, '0'] as $value) {
            vimbadminDateFormatterContractCheck(
                'explicit PHP format',
                smarty_modifier_date_formatter($value, 'Y-m-d'),
                '1970-01-01',
            );
            $smarty->assign('value', $value);
            vimbadminDateFormatterContractCheck(
                'registered explicit PHP format',
                $smarty->fetch('string:{$value|date_formatter:"Y-m-d"}'),
                '1970-01-01',
            );
        }
    },
    'timezone' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        date_default_timezone_set('America/New_York');
        vimbadminDateFormatterContractCheck(
            'local timezone calendar oracle',
            date('Y-m-d H:i:s', 1),
            '1969-12-31 19:00:01',
        );
        vimbadminDateFormatterContractCheck(
            'callback follows local timezone',
            smarty_modifier_date_formatter(1, 'Y-m-d H:i:s'),
            '1969-12-31 19:00:01',
        );
        $smarty->assign('value', 1);
        vimbadminDateFormatterContractCheck(
            'registered timezone',
            $smarty->fetch('string:{$value|date_formatter:"Y-m-d H:i:s"}'),
            '1969-12-31 19:00:01',
        );
    },
    'empty' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        foreach (['', null, '0000-00-00', '0000-00-00 00:00:00'] as $value) {
            vimbadminDateFormatterContractCheck(
                'empty callback is null',
                smarty_modifier_date_formatter($value, 'Y-m-d'),
                null,
            );
            $smarty->assign('value', $value);
            vimbadminDateFormatterContractCheck(
                'registered empty output',
                $smarty->fetch('string:{$value|date_formatter:"Y-m-d"}'),
                '',
            );
        }
    },
    'fallback' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        foreach (['', null, '0000-00-00', '0000-00-00 00:00:00'] as $value) {
            vimbadminDateFormatterContractCheck(
                'default selected',
                smarty_modifier_date_formatter($value, 'Y-m-d', '1970-01-02'),
                '1970-01-02',
            );
            $smarty->assign('value', $value);
            vimbadminDateFormatterContractCheck(
                'registered default selected',
                $smarty->fetch('string:{$value|date_formatter:"Y-m-d":"1970-01-02"}'),
                '1970-01-02',
            );
        }
    },
    'legacy_builtin_deprecation' => static function (): void {
        $events = [];
        set_error_handler(static function (int $severity, string $message) use (&$events): bool {
            if ($severity !== E_DEPRECATED || !str_contains($message, 'strftime')) {
                throw new ErrorException($message, 0, $severity);
            }
            $events[] = $severity;
            return true;
        });
        try {
            $expected = strftime('%Y-%m-%d', 1);
        } finally {
            restore_error_handler();
        }
        vimbadminDateFormatterContractCheck('legacy calendar oracle', $expected, '1970-01-01');
        vimbadminDateFormatterContractCheck('PHP strftime deprecation is observed', $events, [E_DEPRECATED]);
        echo 'EXPECTED-DEPRECATION: native strftime E_DEPRECATED; '
            . 'callback uses no deprecated builtin on PHP-format path.' . "\n";
    },
    'legacy_percent' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        // Actual active _skins/myskin/footer.phtml:33 passes "%Y".
        vimbadminDateFormatterContractCheck('legacy percent year', smarty_modifier_date_formatter(1, '%Y'), '1970');
    },
    'legacy_explicit' => static function (): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        vimbadminDateFormatterContractCheck(
            'explicit legacy formatter',
            smarty_modifier_date_formatter(1, '%Y-%m-%d', '', 'strftime'),
            '1970-01-01',
        );
    },
    'legacy_percent_render' => static function (Smarty\Smarty $smarty): void {
        $smarty->assign('value', 1);
        vimbadminDateFormatterContractCheck(
            'registered legacy percent year',
            $smarty->fetch('string:{$value|date_formatter:"%Y"}'),
            '1970',
        );
    },
    'legacy_default' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        vimbadminDateFormatterContractCheck(
            'legacy omitted format in C locale',
            smarty_modifier_date_formatter(1),
            'Jan  1, 1970',
        );
    },
    'legacy_default_render' => static function (Smarty\Smarty $smarty): void {
        $smarty->assign('value', 1);
        vimbadminDateFormatterContractCheck(
            'registered omitted format',
            $smarty->fetch('string:{$value|date_formatter}'),
            'Jan  1, 1970',
        );
    },
    'legacy_generic_formats' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        $format = '%a %A %b %B %C %d %e %j %u %w %V %G %z %Z %%';
        $expected = 'Thu Thursday Jan January 19 01  1 001 4 4 01 1970 +0000 UTC %';
        vimbadminDateFormatterContractCheck(
            'generic locale/calendar formats',
            smarty_modifier_date_formatter(1, $format),
            $expected,
        );
        $smarty->assign('value', 1);
        $smarty->assign('format', $format);
        vimbadminDateFormatterContractCheck(
            'registered generic formats',
            $smarty->fetch('string:{$value|date_formatter:$format}'),
            $expected,
        );
    },
    'legacy_timezone' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        date_default_timezone_set('America/New_York');
        $expected = '1969-12-31 19:00:01 -0500 EST';
        $format = '%Y-%m-%d %H:%M:%S %z %Z';
        vimbadminDateFormatterContractCheck(
            'legacy follows current timezone',
            smarty_modifier_date_formatter(1, $format),
            $expected,
        );
        $smarty->assign('value', 1);
        $smarty->assign('format', $format);
        vimbadminDateFormatterContractCheck(
            'registered legacy timezone',
            $smarty->fetch('string:{$value|date_formatter:$format}'),
            $expected,
        );
    },
    'legacy_locale' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        $locale = setlocale(LC_TIME, 'de_DE.utf8', 'de_DE.UTF-8');
        if ($locale === false) {
            throw new RuntimeException('Required German locale unavailable; locale contract not executed');
        }
        vimbadminDateFormatterContractCheck(
            'legacy localized month',
            smarty_modifier_date_formatter(1, '%B %Y'),
            'Januar 1970',
        );
        $smarty->assign('value', 1);
        vimbadminDateFormatterContractCheck(
            'registered localized month',
            $smarty->fetch('string:{$value|date_formatter:"%B %Y"}'),
            'Januar 1970',
        );
        setlocale(LC_TIME, 'C');
        vimbadminDateFormatterContractCheck(
            'cached adapter follows changed locale',
            smarty_modifier_date_formatter(1, '%B %Y'),
            'January 1970',
        );
    },
    'legacy_boundaries' => static function (Smarty\Smarty $smarty): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        foreach ([0, '0', -1, '19700101000000'] as $value) {
            $expected = date('Y-m-d', (int) $value);
            vimbadminDateFormatterContractCheck(
                'already resolved epoch preserved',
                smarty_modifier_date_formatter($value, '%Y-%m-%d'),
                $expected,
            );
            $smarty->assign('value', $value);
            vimbadminDateFormatterContractCheck(
                'registered resolved epoch',
                $smarty->fetch('string:{$value|date_formatter:"%Y-%m-%d"}'),
                $expected,
            );
        }
        vimbadminDateFormatterContractCheck(
            'explicit empty legacy format stays empty',
            smarty_modifier_date_formatter(1, '', '', 'strftime'),
            '',
        );
        vimbadminDateFormatterContractCheck('empty PHP format stays empty', smarty_modifier_date_formatter(1, ''), '');
        vimbadminDateFormatterContractCheck(
            'legacy fallback selected',
            smarty_modifier_date_formatter('', '%Y-%m-%d', '1970-01-02'),
            '1970-01-02',
        );
    },
    'dependency_deprecation_policy' => static function (Smarty\Smarty $smarty, Closure $events): void {
        if (!function_exists('smarty_modifier_date_formatter')) {
            throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_date_formatter');
        }

        $before = vimbadminDateFormatterEventCount($events);
        ob_start();
        try {
            $result = smarty_modifier_date_formatter(1, '%Y');
        } finally {
            $output = ob_get_clean();
        }
        vimbadminDateFormatterContractCheck('dependency legacy result', $result, '1970');
        vimbadminDateFormatterContractCheck('dependency emits no diagnostic output', $output, '');
        vimbadminDateFormatterContractCheck('one qualified masked dependency deprecation', vimbadminDateFormatterEventCount($events) - $before, 1);
        $before = vimbadminDateFormatterEventCount($events);
        vimbadminDateFormatterContractCheck(
            'PHP branch bypasses deprecated adapter',
            smarty_modifier_date_formatter(1, 'Y'),
            '1970',
        );
        vimbadminDateFormatterContractCheck('PHP branch has no dependency deprecation', vimbadminDateFormatterEventCount($events) - $before, 0);
    },
    'malformed_format' => static function (): void {
        $rejected = false;
        try {
            // Reflection deliberately crosses the documented string-format boundary.
            (new ReflectionFunction('smarty_modifier_date_formatter'))->invoke(1, []);
        } catch (TypeError $error) {
            $rejected = str_contains($error->getMessage(), 'strpos');
        }
        vimbadminDateFormatterContractCheck('format array rejected under documented string format', $rejected, true);
        foreach ([null, [], ['packages' => null], ['packages' => []],
            ['packages' => [['name' => 'smarty/smarty', 'version' => 5]]],
            ['packages' => [['name' => 'smarty/smarty', 'version' => 'v5', 'source' => ['reference' => 5]]]],
        ] as $metadata) {
            $invalid = false;
            try {
                vimbadminDateFormatterSmartyPackage($metadata);
            } catch (RuntimeException) {
                $invalid = true;
            }
            vimbadminDateFormatterContractCheck('malformed package metadata rejected', $invalid, true);
        }
    },
]);
