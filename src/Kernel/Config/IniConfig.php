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
            // PHP converts numeric-string array keys to integers. INI keys are
            // textual, so restore that representation before building maps.
            $header = (string) $header;
            // A scalar, or a `key[] = ...` array-append in the section-less scope
            // (which PHP returns as a list-keyed array), is a global base key —
            // not a `[section]`. Real section bodies are string-keyed maps.
            if (!is_array($body) || ($body !== [] && array_is_list($body))) {
                $globals[$header] = $body;
                continue;
            }
            $parts = array_map('trim', explode(':', (string) $header));
            $name  = array_shift($parts);
            if (count($parts) > 1) {
                throw new \RuntimeException("Section '{$header}' extends more than one section");
            }
            $bodies[$name]  = self::stringMap($body);
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
        $owners = [];
        foreach ($flat as $key => $value) {
            $key = (string) $key;
            $out = self::insertDottedValue($out, explode('.', $key), $value, $key, $owners);
        }

        return self::stringMap($out);
    }

    /**
     * @param array<string,mixed> $out
     * @param non-empty-list<string> $segments
     * @param array<string,string> $owners
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private static function insertDottedValue(
        array $out,
        array $segments,
        mixed $value,
        string $key,
        array &$owners,
        array $path = []
    ): array {
        $segment = array_shift($segments);
        if ($segment === null) {
            throw new \LogicException('A dotted INI key must contain a segment');
        }
        $path[] = $segment;
        $pathKey = implode('.', $path);

        if ($segments === []) {
            if (array_key_exists($segment, $out)) {
                $existing = $out[$segment];
                if (self::dottedValuesCollide($existing, $value)) {
                    throw new \RuntimeException(
                        "INI keys '{$owners[$pathKey]}' and '{$key}' collide as scalar and nested values"
                    );
                }
            }
            $out[$segment] = $value;
            $owners[$pathKey] = $key;
            return $out;
        }

        if (!array_key_exists($segment, $out)) {
            $out[$segment] = [];
            $owners[$pathKey] = $key;
        } elseif (!is_array($out[$segment]) || array_is_list($out[$segment])) {
            throw new \RuntimeException(
                "INI keys '{$owners[$pathKey]}' and '{$key}' collide as scalar and nested values"
            );
        }
        $child = self::stringMap($out[$segment]);
        $out[$segment] = self::insertDottedValue($child, $segments, $value, $key, $owners, $path);

        return $out;
    }

    private static function dottedValuesCollide(mixed $existing, mixed $value): bool
    {
        if (is_array($existing) !== is_array($value)) {
            return true;
        }

        return is_array($existing) && is_array($value)
            && array_is_list($existing) !== array_is_list($value);
    }

    /**
     * @param array<array-key,mixed> $values
     * @return array<string,mixed>
     */
    private static function stringMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
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
        return self::stringMap(array_replace_recursive($base, $override));
    }
}
