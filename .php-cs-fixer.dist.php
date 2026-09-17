<?php

/**
 * PSR-12 formatting contract for every tracked PHP source file.
 *
 * Risky fixers stay disabled: this configuration is only allowed to move
 * whitespace and brace placement, never to rewrite semantics. `vendor/` and
 * the generated Doctrine proxies are excluded because they are not ours.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/.github',
        __DIR__ . '/application',
        __DIR__ . '/bin',
        __DIR__ . '/library',
        __DIR__ . '/public',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools',
    ])
    ->append([__DIR__ . '/preload.php', __FILE__])
    // `.github/scripts` holds tracked CI helpers; the finder skips dot
    // directories unless it is told not to.
    ->ignoreDotFiles(false)
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PSR12' => true])
    ->setFinder($finder);
