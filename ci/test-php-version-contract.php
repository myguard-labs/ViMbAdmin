<?php

declare(strict_types=1);

namespace ViMbAdmin\Ci;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use SplFileObject;

function readText(string $path): string
{
    $file = new SplFileObject($path, 'r');
    $contents = '';
    while (!$file->eof()) {
        $contents .= $file->fgets();
    }

    return $contents;
}

/** @return array<mixed, mixed> */
function readJsonObject(string $path): array
{
    $decoded = json_decode(readText($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$path} must contain a JSON object");
    }

    return $decoded;
}

function minimumPhpVersion(mixed $constraint): string
{
    if (!is_string($constraint)
        || preg_match('/^>=([0-9]+\.[0-9]+\.[0-9]+)$/D', $constraint, $matches) !== 1
    ) {
        throw new InvalidArgumentException('The root PHP constraint must use >=major.minor.patch');
    }

    return $matches[1];
}

/** @return list<string> */
function supportDocumentation(string $root): array
{
    /** @var list<string> $paths */
    $paths = [];
    $roots = [
        $root . '/README.md',
        $root . '/CHANGELOG',
        $root . '/CHANGELOG-4.0.0.md',
        $root . '/docs',
        $root . '/contrib',
    ];

    foreach ($roots as $path) {
        if ((new SplFileInfo($path))->isFile()) {
            $paths[] = $path;
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (in_array($extension, ['md', 'conf', 'ini', 'list'], true)
                || preg_match('/^(?:README|CHANGELOG)/i', $file->getBasename()) === 1
            ) {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);
    return $paths;
}

/** @param array<mixed, mixed> $object */
function nestedValue(array $object, string ...$keys): mixed
{
    $value = $object;
    foreach ($keys as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

function hasUnsupportedPhpSupportClaim(string $documentation, string $minimum): bool
{
    $matchCount = preg_match_all(
        '/\bPHP\s+(?:\*{1,2}|_{1,2})?(?<version>\d+\.\d+(?:\.\d+)?)'
            . '(?:\*{1,2}|_{1,2})?\s*'
            . '(?:\+|or\s+newer|(?:→|->|through|to|\/)\s*\d+\.\d+(?:\.\d+)?)/i',
        $documentation,
        $matches
    );
    if ($matchCount === false) {
        throw new RuntimeException('Cannot inspect documented PHP support claims');
    }

    foreach ($matches['version'] as $version) {
        if (substr_count($version, '.') === 1) {
            $version .= '.0';
        }
        if (version_compare($version, $minimum, '<')) {
            return true;
        }
    }

    return false;
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$root = __DIR__ . '/..';
$composer = readJsonObject($root . '/composer.json');
$lock = readJsonObject($root . '/composer.lock');
$minimum = minimumPhpVersion(nestedValue($composer, 'require', 'php'));
$platform = nestedValue($composer, 'config', 'platform', 'php');
$lockedConstraint = nestedValue($lock, 'platform', 'php');
$lockedPlatform = nestedValue($lock, 'platform-overrides', 'php');

echo "== PHP version contract ==\n";

$check(
    'positive: Composer, configured platform, and lock metadata share PHP 8.2.0',
    $minimum === '8.2.0'
        && $platform === $minimum
        && $lockedConstraint === '>=' . $minimum
        && $lockedPlatform === $minimum
);

$check(
    'boundary: the minimum and newer releases pass while the prior patch fails',
    version_compare('8.2.0', $minimum, '>=')
        && version_compare('8.5.0', $minimum, '>=')
        && !version_compare('8.1.99', $minimum, '>=')
);

$malformedRejected = false;
try {
    minimumPhpVersion('8.2.0');
} catch (InvalidArgumentException) {
    $malformedRejected = true;
}
$check('error/malformed: an ambiguous root constraint is rejected', $malformedRejected);

$claims = '';
foreach (supportDocumentation($root) as $path) {
    $claims .= "\n" . readText($path);
}

$check(
    'positive: user-facing and deployment documentation advertises the Composer floor',
    str_contains($claims, 'PHP **8.2.0+**')
        && str_contains($claims, '**PHP 8.2.0 → 8.5** clean')
        && str_contains($claims, 'running on PHP 8.2.0+')
);

$check(
    'positive: a Markdown-emphasized unsupported PHP claim is detected',
    hasUnsupportedPhpSupportClaim('Requires PHP **8.1+**.', $minimum)
);
$check(
    'boundary: an emphasized PHP patch below the Composer floor is detected',
    hasUnsupportedPhpSupportClaim('Requires PHP **8.1.99+**.', $minimum)
);
$check(
    'near-miss: a Markdown-emphasized supported PHP claim remains allowed',
    !hasUnsupportedPhpSupportClaim('Requires PHP **8.2.0+**.', $minimum)
);
$check(
    'negative: documentation does not advertise support below the Composer floor',
    !hasUnsupportedPhpSupportClaim($claims, $minimum)
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
