<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Input;

final class Reader
{
    public static function requiredString(mixed $value, string $name, bool $terminalPeriod = false): string
    {
        if (!is_string($value)) {
            throw new \LogicException("{$name} must be a string" . ($terminalPeriod ? '.' : ''));
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function stringKeyedArray(mixed $value, string $name, bool $terminalPeriod = false): array
    {
        if (!is_array($value)) {
            throw new \LogicException("{$name} must be an array" . ($terminalPeriod ? '.' : ''));
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new \LogicException("{$name} must use string keys");
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{bool,mixed}
     */
    public static function option(array $options, string ...$path): array
    {
        $arrayName = array_key_exists('arrayName', $path) ? array_pop($path) : null;
        $value = $options;
        $walked = [];
        foreach ($path as $key) {
            $value = self::stringKeyedArray($value, $arrayName ?: 'Configuration ' . ($walked === [] ? 'root' : implode('.', $walked)), $arrayName !== null && $arrayName !== '');
            $walked[] = $key;
            if (!array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }

    public static function nonNegativeInteger(mixed $value, string $name, bool $terminalPeriod = false): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new \LogicException("{$name} must be a non-negative integer" . ($terminalPeriod ? '.' : ''));
    }

    public static function positiveInteger(mixed $value, string $name, bool $terminalPeriod = false): int
    {
        $integer = self::nonNegativeInteger($value, $name, $terminalPeriod);
        if ($integer < 1) {
            throw new \LogicException("{$name} must be greater than zero" . ($terminalPeriod ? '.' : ''));
        }

        return $integer;
    }

    /**
     * @param array<string,mixed> $options
     * @param list<string> $path
     */
    public static function optionString(array $options, string $default, array $path, bool $terminalPeriod = false, ?string $pathArrayName = null): string
    {
        [$found, $value] = self::option($options, ...$path, arrayName: $pathArrayName ?? '');
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path), $terminalPeriod) : $default;
    }

    /**
     * @param array<string,mixed> $options
     * @param list<string> $path
     */
    public static function optionNonNegativeInteger(
        array $options,
        int $default,
        array $path,
        bool $terminalPeriod = false,
        ?string $pathArrayName = null,
    ): int {
        [$found, $value] = self::option($options, ...$path, arrayName: $pathArrayName ?? '');
        return $found
            ? self::nonNegativeInteger($value, 'Configuration ' . implode('.', $path), $terminalPeriod)
            : $default;
    }
}
