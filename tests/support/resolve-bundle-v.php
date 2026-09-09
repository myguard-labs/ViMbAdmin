<?php

declare(strict_types=1);

/**
 * Resolve the current JS bundle filename dynamically.
 *
 * Returns the bundle name (e.g., "min.bundle-v24.js").
 * Throws an exception if zero or multiple bundles exist.
 *
 * @return string
 * @throws RuntimeException
 */
function resolveBundleV(): string
{
    $baseDir = __DIR__ . '/../..';
    $pattern = $baseDir . '/public/js/min.bundle-v*.js';
    $matches = glob($pattern, GLOB_BRACE) ?: [];

    if (count($matches) === 0) {
        throw new RuntimeException('No JS bundle found matching public/js/min.bundle-v*.js');
    }

    if (count($matches) > 1) {
        $bundleNames = array_map(static fn(string $path): string => basename($path), $matches);
        throw new RuntimeException('Ambiguous bundle name; multiple matches found: ' . implode(', ', $bundleNames));
    }

    return basename($matches[0]);
}
