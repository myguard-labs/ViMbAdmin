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
    private static function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    /** The value must be a string when present (empty passes for optional fields). */
    public static function string(string $message = 'This field has an invalid type.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            return $value === null || is_string($value) ? null : $message;
        };
    }

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

    /**
     * A syntactically valid email address (empty passes — combine with required()).
     *
     * @return callable(mixed):?string
     */
    public static function email(string $message = 'Please enter a valid email address.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $string = self::scalarString($value);
            return $string === null || filter_var($string, FILTER_VALIDATE_EMAIL) === false ? $message : null;
        };
    }

    /**
     * A syntactically valid administrator email address with an unquoted local
     * part. Admin usernames are displayed in security-sensitive UI messages;
     * accepting quoted local parts adds no operational value and historically
     * exposed surprising markup-bearing identities.
     *
     * @return callable(mixed):?string
     */
    public static function adminEmail(string $message = 'Please enter a valid email address with an unquoted local part.'): callable
    {
        $email = self::email($message);

        return static function (mixed $value) use ($message, $email): ?string {
            $error = $email($value);
            if ($error !== null || $value === null || $value === '') {
                return $error;
            }

            $string = self::scalarString($value);
            return $string !== null && !str_starts_with($string, '"') ? null : $message;
        };
    }

    /**
     * A syntactically valid email *local part* (the bit before the @): an RFC
     * 5321 dot-atom of the unreserved/permitted characters, with no leading,
     * trailing or consecutive dot. This is stricter than the old
     * `/^[A-Za-z0-9._%+\-]+$/` which let through `.foo`, `foo.`, `a..b` and `%`
     * — shapes that are invalid addresses and can also map to surprising
     * Dovecot maildir paths. Length capped at 64 (RFC 5321 §4.5.3.1.1). Empty
     * passes (combine with required()).
     */
    public static function localPart(string $message = 'Please enter a valid local part (the bit before the @).'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $s = self::scalarString($value);
            if ($s === null) {
                return $message;
            }
            if (strlen($s) > 64) {
                return $message;
            }
            // dot-atom: one or more atoms of permitted chars, joined by single
            // dots; no leading/trailing/double dot. '/' is intentionally NOT
            // permitted — it is a path separator that would create a nested
            // maildir segment (see backupDest/removeMaildirHome), and RFC 5321
            // dot-atoms do not include it.
            $atom = '[A-Za-z0-9!#$&\'*+=?^_`{|}~\-]+';

            return preg_match('/^' . $atom . '(\.' . $atom . ')*$/', $s) === 1 ? null : $message;
        };
    }

    /**
     * A valid DNS hostname / domain name (labels of letters, digits and hyphens,
     * not starting/ending with a hyphen, joined by dots; at least two labels).
     * Rejects spaces, slashes, '@', control characters and other shapes that
     * would corrupt a Postfix/Dovecot lookup key. Empty passes (combine with
     * required()).
     */
    public static function hostname(string $message = 'Please enter a valid domain name.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $s = self::scalarString($value);
            if ($s === null) {
                return $message;
            }
            if (strlen($s) > 253) {
                return $message;
            }

            return preg_match(
                '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i',
                $s
            ) === 1 ? null : $message;
        };
    }

    /**
     * Reject control characters (CR, LF, NUL, …) in a free-text field. Names,
     * descriptions and transport strings flow into mail headers, Postfix maps
     * and Dovecot config lookups, where an embedded newline is a header- /
     * lookup-injection vector. Empty passes (combine with required()).
     */
    public static function noControlChars(string $message = 'This value contains illegal control characters.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $s = self::scalarString($value);
            if ($s === null) {
                return $message;
            }

            // Reject invalid UTF-8 outright: it has no business in a name/domain
            // field and would make the /u match below return false (mis-read as
            // "clean" by a === 1 test).
            if (preg_match('//u', $s) !== 1) {
                return $message;
            }

            // C0 controls + DEL + C1 controls, plus the Unicode line/paragraph
            // separators (U+2028/U+2029) — all newline-equivalents a header- or
            // lookup-injection could ride on. The /u flag makes \x{…} match the
            // decoded code points, so this catches their UTF-8 encodings too.
            return preg_match('/[\x00-\x1F\x7F-\x9F\x{2028}\x{2029}]/u', $s) === 1 ? $message : null;
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

            $string = self::scalarString($value);
            return $string === null || strlen($string) < $min ? $message : null;
        };
    }

    /** Match a PCRE pattern (empty passes — combine with required()). */
    public static function regex(string $pattern, string $message = 'Invalid value.'): callable
    {
        return static function (mixed $value) use ($pattern, $message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $string = self::scalarString($value);
            return $string !== null && preg_match($pattern, $string) === 1 ? null : $message;
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
        $candidates = array_keys($allowed) === range(0, count($allowed) - 1) ? $allowed : array_keys($allowed);
        $set = [];
        foreach ($candidates as $candidate) {
            $string = self::scalarString($candidate);
            if ($string === null) {
                throw new \InvalidArgumentException('Allowed validator values must be scalar');
            }
            $set[] = $string;
        }

        return static function (mixed $value) use ($set, $message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $string = self::scalarString($value);
            return $string !== null && in_array($string, $set, true) ? null : $message;
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
     * A non-negative number (integer or decimal), optionally followed by a
     * B/KB/MB/GB unit accepted by OSS_Filter_FileSize. Used for size/quota
     * fields, including values that OSS_Filter_FileSize::unfilter() rendered
     * for an unchanged form. Empty passes (combine with required() where the
     * field is mandatory); 0 is allowed (it means "unlimited" for quotas).
     */
    public static function nonNegativeNumber(string $message = 'Please enter a number of 0 or more.'): callable
    {
        return static function (mixed $value) use ($message): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $string = self::scalarString($value);
            if ($string === null) {
                return $message;
            }

            // OSS_Filter_FileSize removes literal spaces before parsing. Keep
            // this grammar aligned while rejecting signs, exponents, unknown
            // units and malformed decimals that its permissive digit stripping
            // would otherwise reinterpret.
            $s = str_replace(' ', '', $string);
            if (preg_match(
                '/^([0-9]+(?:\.[0-9]*)?|\.[0-9]+)(B|[KMG]B?)?$/iD',
                $s,
                $matches,
            ) !== 1) {
                return $message;
            }

            $multipliers = [
                '' => 1.0,
                'B' => 1.0,
                'K' => 1024.0,
                'KB' => 1024.0,
                'M' => 1048576.0,
                'MB' => 1048576.0,
                'G' => 1073741824.0,
                'GB' => 1073741824.0,
            ];
            $unit = strtoupper($matches[2] ?? '');
            if (!array_key_exists($unit, $multipliers)) {
                return $message;
            }
            $bytes = (float) $matches[1] * $multipliers[$unit];

            // PHP_INT_MAX rounds up to 2^63 as a double on 64-bit builds, so
            // `<= PHP_INT_MAX` also admits the first value that casts to
            // PHP_INT_MIN. The first out-of-range integer is the exact
            // exclusive limit on both 32- and 64-bit builds.
            $integerLimit = PHP_INT_MAX + 1.0;

            return is_finite($bytes) && $bytes < $integerLimit ? null : $message;
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

            $string = self::scalarString($value);
            if ($string === null) {
                return $message;
            }
            $s = trim($string);
            if (preg_match('/^\d+$/', $s) !== 1) {
                return $message;
            }

            return null;
        };
    }
}
