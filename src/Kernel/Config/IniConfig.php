<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Config;

/**
 * Framework-free loader for `application/configs/application.ini`, the final
 * foundational slice of the ZF1 removal (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * The native Container has, until now, reused the merged options array the ZF1
 * bootstrap built (`$bootstrap->getOptions()`, produced by the ZF1 INI config).
 * To stand up the kernel WITHOUT the ZF1 application we need to read the same
 * `.ini` and produce the same nested array ourselves. This class reproduces the
 * three transforms the ZF1 INI config applies on top of PHP's INI parser, and
 * nothing else:
 *
 *   1. **Section inheritance** — a header `[child : parent]` makes `child`
 *      inherit every key of `parent` (which may itself extend another section),
 *      with the child's own keys overriding. Exactly one parent per section, as
 *      ZF1 enforced. Section-less keys (a file with no `[headers]` at all, the
 *      flattened `application.ini.dist`) form a base layer applied first, under
 *      any requested section; a deployed file may still add a `[docker : ...]`
 *      section that overrides the base.
 *   2. **Dotted-key nesting** — `a.b.c = v` becomes `['a']['b']['c'] = v`.
 *   3. **Constant concatenation** — `APPLICATION_PATH "/../library"` expands to
 *      the value of the defined constant followed by the quoted string.
 *
 * Transform (3) is delegated to PHP's own INI parser in its NORMAL scanner mode,
 * which both expands defined constants and concatenates a trailing quoted
 * literal — the very behaviour ZF1 relied on, including its boolean coercion
 * (`true`→`'1'`, `false`→`''`). The parser leaves section names and dotted keys
 * literal (it has no concept of either), so (1) and (2) are applied here.
 *
 * The result is value-for-value identical to what `getOptions()` returned, so
 * the Container and every native controller read it unchanged. Pure, no
 * framework, unit-testable against the shipped `application.ini.dist`.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class IniConfig
{
    /**
     * Load `$path` and return the merged, nested options array for the section
     * named `$section` (the application environment, e.g. `docker` or
     * `production`), resolving its inheritance chain.
     *
     * `APPLICATION_PATH` (and any other constant the `.ini` references) must be
     * defined before calling, since constant expansion happens during parsing.
     *
     * @return array<string,mixed>
     */
    public static function load(string $path, string $section): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read config file: {$path}");
        }

        return self::parse($contents, $section);
    }

    /**
     * The same as {@see self::load()} but over an in-memory INI string. Split
     * out so the inheritance/nesting logic can be unit-tested without a file.
     *
     * @return array<string,mixed>
     */
    public static function parse(string $contents, string $section): array
    {
        $raw = parse_ini_string($contents, true, INI_SCANNER_NORMAL);
        if ($raw === false) {
            throw new \RuntimeException('Failed to parse INI contents');
        }

        // Split the parse into section-less "global" keys (the base layer) and
        // the named "[name]" / "[name : parent]" sections. A flat config file
        // with no `[section]` headers is all globals; the legacy sectioned files
        // (and the deployed host config's `[docker : production]`) still resolve
        // their inheritance chain on top of whatever globals exist.
        $globals = [];
        $bodies  = [];
        $parents = [];
        foreach ($raw as $header => $body) {
            // A scalar, or a `key[] = ...` array-append in the section-less scope
            // (which PHP returns as a list-keyed array), is a global base key —
            // not a `[section]`. Real section bodies are string-keyed maps.
            if (!is_array($body) || array_is_list($body)) {
                $globals[$header] = $body;
                continue;
            }
            $parts = array_map('trim', explode(':', (string) $header));
            $name  = array_shift($parts);
            if (count($parts) > 1) {
                throw new \RuntimeException("Section '{$header}' extends more than one section");
            }
            $bodies[$name]  = $body;
            $parents[$name] = $parts[0] ?? null;
        }

        // Base layer: the section-less keys, always applied first.
        $merged = self::expandDottedKeys($globals);

        if (!array_key_exists($section, $bodies)) {
            // A flat file (globals only) loads under any environment name. Only
            // a sectioned file that lacks BOTH the requested section and any
            // globals is a genuine misconfiguration.
            if ($bodies === [] || $globals !== []) {
                return $merged;
            }
            throw new \RuntimeException("Section '{$section}' not found in config");
        }

        // Walk the parent chain root-first so child keys override parent keys,
        // then layer it on top of the globals base.
        $chain  = [];
        $cursor = $section;
        $seen   = [];
        while ($cursor !== null) {
            if (isset($seen[$cursor])) {
                throw new \RuntimeException("Circular section inheritance at '{$cursor}'");
            }
            $seen[$cursor] = true;
            if (!array_key_exists($cursor, $bodies)) {
                throw new \RuntimeException("Section '{$cursor}' extends unknown section");
            }
            array_unshift($chain, $cursor);
            $cursor = $parents[$cursor];
        }

        foreach ($chain as $name) {
            $merged = self::deepMerge($merged, self::expandDottedKeys($bodies[$name]));
        }

        return $merged;
    }

    /**
     * Expand a flat section body (`'a.b.c' => v`) into the nested array
     * (`['a']['b']['c'] => v`) ZF1's nesting separator produced.
     *
     * @param array<string,mixed> $flat
     * @return array<string,mixed>
     */
    private static function expandDottedKeys(array $flat): array
    {
        $out = [];
        foreach ($flat as $key => $value) {
            $segments = explode('.', (string) $key);
            $ref      = &$out;
            foreach ($segments as $segment) {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }
            $ref = $value;
            unset($ref);
        }

        return $out;
    }

    /**
     * Recursively merge `$override` onto `$base` the way ZF1's config merge did:
     * a key present in both is recursed when both sides are arrays, otherwise
     * the override wins.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
