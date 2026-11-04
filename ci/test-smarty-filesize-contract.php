<?php

/**
 * Finite byte-display contract for the filesize Smarty modifier.
 *
 * The maintained modifier documentation specifies binary units, one decimal,
 * and zero/empty-string output. The PHP06 contract also requires null and
 * negative values to use zero, and caps the unit label at PB. These literal
 * expectations must not be calculated by the implementation under test.
 * Malformed strings and nonfinite values have no approved display policy yet.
 */

/** @return array{version:string,reference:string} */
function viSmartyFilesizePackageMetadata(string $path): array
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

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/library/ViMbAdmin/Smarty/functions/modifier.filesize.php';
if (!function_exists('smarty_modifier_filesize')) {
    throw new RuntimeException('Required Smarty callback is not loaded: smarty_modifier_filesize');
}


$lockedSmarty = viSmartyFilesizePackageMetadata($root . '/composer.lock');

if (
    ltrim(Composer\InstalledVersions::getPrettyVersion('smarty/smarty') ?? '', 'v')
        !== ltrim($lockedSmarty['version'], 'v')
    || Composer\InstalledVersions::getReference('smarty/smarty') !== $lockedSmarty['reference']
) {
    throw new RuntimeException('The installed Smarty must match composer.lock.');
}

$cases = [
    ['null', null, '0 B'],
    ['zero', 0, '0 B'],
    ['negative', -1, '0 B'],
    ['empty', '', '0 B'],
    ['byte maximum', 1023, '1023 B'],
    ['kilobyte boundary', 1024, '1 KB'],
    ['fractional kilobyte', 1536, '1.5 KB'],
    ['numeric string', '1536', '1.5 KB'],
    ['documented gigabytes', 2147483648, '2 GB'],
    ['petabyte boundary', 1125899906842624, '1 PB'],
    ['unit cap', 1152921504606846976, '1024 PB'],
];

$compile = sys_get_temp_dir() . '/vimbadmin-filesize-' . bin2hex(random_bytes(8));
if (!mkdir($compile, 0700)) {
    throw new RuntimeException('Cannot create private Smarty compile directory.');
}
$remove = static function (string $path) use (&$remove): void {
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            $remove($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
};
$failures = 0;
try {
    $smarty = new Smarty\Smarty();
    $smarty->setCompileDir($compile);
    $smarty->setEscapeHtml(true);
    $smarty->registerPlugin('modifier', 'filesize', 'smarty_modifier_filesize');
    foreach ($cases as [$label, $input, $expected]) {
        $direct = smarty_modifier_filesize($input);
        $smarty->assign('bytes', $input);
        $rendered = $smarty->fetch('string:{$bytes|filesize}');
        foreach (['callback' => $direct, 'registered render' => $rendered] as $route => $actual) {
            if ($actual !== $expected) {
                fwrite(STDERR, $label . ' ' . $route . ': expected ' . var_export($expected, true)
                    . ', got ' . var_export($actual, true) . "\n");
                ++$failures;
            }
        }
    }
} finally {
    $remove($compile);
}
if ($failures !== 0) {
    exit(1);
}
echo 'filesize: ' . count($cases) . ' cases, ' . (count($cases) * 2) . ' callback/render assertions; Smarty '
    . $lockedSmarty['version'] . '; PHP ' . PHP_VERSION . "\n";
echo 'OK: all Smarty filesize assertions passed (PHP ' . PHP_VERSION . ")\n";
