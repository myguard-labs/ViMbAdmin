<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Form;

/**
 * Factory for the common field validation rules (Phase 4, docs/ZF1-REMOVAL.md).
 *
 * Each method returns a `callable(mixed $value): ?string` — null when the value
 * passes, otherwise the error message. These are the framework-free
 * replacements for the handful of ZF1 validators the ViMbAdmin forms
 * actually use (NotEmpty, EmailAddress, StringLength, Regex, Identical, …); add
 * more here as forms are migrated rather than reaching for a validator library.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Validators
{
    /** The value must be present (non-empty after trimming strings). */
    public static function required(string $message = 'This field is required.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '' || $value === []) {
                return $message;
            }
            if (is_string($value) && trim($value) === '') {
                return $message;
            }

            return null;
        };
    }

    /** A syntactically valid email address (empty passes — combine with required()). */
    public static function email(string $message = 'Please enter a valid email address.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false ? $message : null;
        };
    }

    /** At least $min characters (empty passes — combine with required()). */
    public static function minLength(int $min, ?string $message = null): callable
    {
        $message ??= "Must be at least {$min} characters.";

        return static function (mixed $value) use ($min, $message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return strlen((string) $value) < $min ? $message : null;
        };
    }

    /** Match a PCRE pattern (empty passes — combine with required()). */
    public static function regex(string $pattern, string $message = 'Invalid value.'): callable
    {
        return static function (mixed $value) use ($pattern, $message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return preg_match($pattern, (string) $value) === 1 ? null : $message;
        };
    }

    /**
     * The value must be one of an allowed set (compared as strings) — the
     * framework-free equivalent of a select element's in-array validator, so a
     * forged option that was never offered is rejected. Empty passes (combine
     * with required()).
     *
     * @param array<int|string,mixed> $allowed values OR a value→label map (keys
     *        are the allowed values, matching how a select's options are keyed)
     */
    public static function inArray(array $allowed, string $message = 'Please select a valid option.'): callable
    {
        $set = array_map('strval', array_keys($allowed) === range(0, count($allowed) - 1) ? $allowed : array_keys($allowed));

        return static function (mixed $value) use ($set, $message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return in_array((string) $value, $set, true) ? null : $message;
        };
    }

    /**
     * The value must equal another field's value (e.g. password confirmation).
     * The other value is resolved lazily so it reads the bound data at validate
     * time.
     *
     * @param callable():mixed $other
     */
    public static function matches(callable $other, string $message = 'Values do not match.'): callable
    {
        return static function (mixed $value) use ($other, $message): ?string {
            return $value === $other() ? null : $message;
        };
    }

    /**
     * A non-negative number (integer or decimal). Used for size/quota fields,
     * where a negative or non-numeric value otherwise flows through
     * OSS_Filter_FileSize + (int) and yields a garbage quota. Empty passes
     * (combine with required() where the field is mandatory); 0 is allowed
     * (it means "unlimited" for quotas).
     */
    public static function nonNegativeNumber(string $message = 'Please enter a number of 0 or more.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $s = trim((string) $value);
            if (!is_numeric($s) || (float) $s < 0) {
                return $message;
            }

            return null;
        };
    }

    /**
     * A non-negative integer (whole number, 0 or more). For count limits such as
     * max mailboxes / max aliases. Empty passes; 0 is allowed (= unlimited).
     */
    public static function nonNegativeInt(string $message = 'Please enter a whole number of 0 or more.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $s = trim((string) $value);
            if (preg_match('/^\d+$/', $s) !== 1) {
                return $message;
            }

            return null;
        };
    }
}
