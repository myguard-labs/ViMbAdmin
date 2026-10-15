<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Input;

final class Reader
{
    public static function requiredString(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new \LogicException("{$name} must be a string");
        }

        return $value;
    }

    /** @return array<string,mixed> */
    public static function stringKeyedArray(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new \LogicException("{$name} must be an array");
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
        $value = $options;
        $walked = [];
        foreach ($path as $key) {
            $value = self::stringKeyedArray(
                $value,
                'Configuration ' . ($walked === [] ? 'root' : implode('.', $walked)),
            );
            $walked[] = $key;
            if (!array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }

    public static function nonNegativeInteger(mixed $value, string $name): int
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

        throw new \LogicException("{$name} must be a non-negative integer");
    }

    public static function positiveInteger(mixed $value, string $name): int
    {
        $integer = self::nonNegativeInteger($value, $name);
        if ($integer < 1) {
            throw new \LogicException("{$name} must be greater than zero");
        }

        return $integer;
    }

    /** @param array<string,mixed> $options */
    public static function optionString(array $options, string $default, string ...$path): string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /** @param array<string,mixed> $options */
    public static function optionNonNegativeInteger(
        array $options,
        int $default,
        string ...$path,
    ): int {
        [$found, $value] = self::option($options, ...$path);
        return $found
            ? self::nonNegativeInteger($value, 'Configuration ' . implode('.', $path))
            : $default;
    }
}
