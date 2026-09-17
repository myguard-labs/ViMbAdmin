<?php

/**
 * OSS Framework
 *
 * This file is part of the "OSS Framework" - a library of tools, utilities and
 * extensions to the Zend Framework V1.x used for PHP application development.
 *
 * Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Open Source Solutions Limited is a company registered in Dublin,
 * Ireland with the Companies Registration Office (#438231). We
 * trade as Open Solutions with registered business name (#329120).
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * It is also available through the world-wide-web at this URL:
 *     http://www.opensolutions.ie/licenses/new-bsd
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@opensolutions.ie so we can send you a copy immediately.
 *
 * @category   OSS
 * @package    OSS_Auth
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

/**
 * A class to hash and verify passwords using verious methods
 *
 * @category   OSS
 * @package    OSS_Auth
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */
class OSS_Auth_Password
{
    private static function stringValue(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new OSS_Exception($name . ' must be a string');
        }
        return $value;
    }

    private static function costValue(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 4 || $value > 16) {
            throw new OSS_Exception('Bcrypt cost must be an integer between 4 and 16');
        }
        return $value;
    }
    public const HASH_PLAINTEXT    = 'plaintext';
    public const HASH_PLAIN        = 'plain';
    public const HASH_BCRYPT       = 'bcrypt';
    public const HASH_DOVECOT      = 'dovecot:';
    public const HASH_CRYPT        = 'crypt:';
    public const HASH_UNKNOWN      = '*unknown*';

    /**
     * A generic password hashing method using a given configuration array
     *
     * The parameters expected in `$config` are:
     *
     * * `pwhash`      - a hashing method from the `HASH_` constants in this class
     * * `hash_cost`   - a *cost* parameter for certain hashing functions - e.g. bcrypt (defaults to 12)
     *
     * @param string $pw The plaintext password to hash
     * @param array<string, mixed>|string $config
     *     The resources.auth.oss array from `application.ini`, or a hash method
     * @throws OSS_Exception
     * @return string The hashed password
     */
    public static function hash($pw, $config)
    {
        $hash = self::HASH_UNKNOWN;

        if (is_array($config)) {
            if (!isset($config['pwhash'])) {
                throw new OSS_Exception('Cannot hash password without a hash method');
            }

            $hash = self::stringValue($config['pwhash'], 'Password hash method');
        } else {
            $hash = self::stringValue($config, 'Password hash method');
        }

        $username = is_array($config) && array_key_exists('username', $config)
            ? self::stringValue($config['username'], 'Password username') : '';

        if (substr($hash, 0, 8) == 'dovecot:') {
            return ViMbAdmin_Dovecot::password(substr($hash, 8), $pw, $username);
        } elseif (substr($hash, 0, 6) == 'crypt:') {
            if (!in_array($hash, [ 'crypt:md5', 'crypt:blowfish', 'crypt:sha256', 'crypt:sha512' ], true)) {
                throw new OSS_Exception('Unknown crypt password hashing method');
            }

            if (strlen($pw) > 72) {
                throw new OSS_Exception('Password must not exceed 72 bytes for legacy crypt configuration');
            }

            // Keep accepting legacy crypt:* configuration names, but never
            // create their manually constructed hashes. Existing hashes are
            // still checked by verify()'s crypt-compatible legacy path.
            return password_hash($pw, PASSWORD_BCRYPT, [ 'cost' => 12 ]);
        } else {
            switch ($hash) {
                case self::HASH_PLAINTEXT:
                case self::HASH_PLAIN:
                    return $pw;

                case self::HASH_BCRYPT:
                    $cost = is_array($config) && array_key_exists('hash_cost', $config)
                        ? self::costValue($config['hash_cost']) : 12;
                    $bcrypt = new OSS_Crypt_Bcrypt($cost);
                    return $bcrypt->hash($pw);

                    // UPDATE PHPDOC ABOVE WHEN ADDING NEW METHODS!

                default:
                    throw new OSS_Exception('Unknown password hashing method');
            }
        }
    }

    /**
     * A generic password verification function for various hashing methods using a given configuration array
     *
     * @see hash() for full documentation
     *
     * @param string $pwplain The plaintext password
     * @param string $pwhash The hashed password to use for verification
     * @param array<string, mixed>|string $config
     *     The resources.auth.oss array from `application.ini`, or a hash method
     * @throws OSS_Exception
     * @return bool True if the passwords match
     */
    public static function verify($pwplain, $pwhash, $config)
    {
        $hash = self::HASH_UNKNOWN;

        if (is_array($config)) {
            if (!isset($config['pwhash'])) {
                throw new OSS_Exception('Cannot verify password without a hash method');
            }

            $hash = self::stringValue($config['pwhash'], 'Password hash method');
        } else {
            $hash = self::stringValue($config, 'Password hash method');
        }

        $username = is_array($config) && array_key_exists('username', $config)
            ? self::stringValue($config['username'], 'Password username') : '';

        switch ($hash) {
            case self::HASH_BCRYPT:
                return OSS_Crypt_Bcrypt::verify($pwplain, $pwhash);
        }

        if (substr($hash, 0, 6) == 'crypt:') {
            if (str_starts_with($pwhash, '$2y$') && strlen($pwplain) > 72) {
                return false;
            }

            return hash_equals($pwhash, crypt($pwplain, $pwhash));
        }

        if (substr($hash, 0, 8) == 'dovecot:') {
            return ViMbAdmin_Dovecot::passwordVerify(substr($hash, 8), $pwhash, $pwplain, $username);
        }


        // Constant-time comparison to avoid leaking the hash via timing.
        return hash_equals($pwhash, self::hash($pwplain, $config));
    }

    /**
     * Verify a credential and return the hash that should be stored.
     *
     * A null result is an authentication failure.  On success, the existing
     * hash is returned unless the configured generation policy is stronger;
     * callers can therefore persist an upgrade without handling plaintext
     * beyond the request that already supplied it.
     *
     * @param array<string, mixed>|string $config
     * @return string|null
     */
    public static function verifyAndRehash(string $pwplain, string $pwhash, $config): ?string
    {
        if (!self::verify($pwplain, $pwhash, $config)) {
            return null;
        }

        try {
            if (!self::needsRehash($pwhash, $config)) {
                return $pwhash;
            }

            $replacement = self::hash($pwplain, $config);
            return is_string($replacement) ? $replacement : $pwhash;
        } catch (Throwable) {
            // Rehashing is opportunistic.  A bad generation policy or a local
            // hashing failure must not turn a verified credential into an
            // authentication failure; retain the usable stored hash instead.
            return $pwhash;
        }
    }

    /** @param array<string, mixed>|string $config */
    private static function needsRehash(string $pwhash, $config): bool
    {
        $classifiedHash = $pwhash;
        if (str_starts_with($classifiedHash, '{')
            && ($prefixEnd = strpos($classifiedHash, '}')) !== false) {
            $classifiedHash = substr($classifiedHash, $prefixEnd + 1);
        }

        $method = is_array($config)
            ? self::stringValue($config['pwhash'] ?? null, 'Password hash method')
            : self::stringValue($config, 'Password hash method');

        $desiredBcryptCost = null;
        if ($method === self::HASH_BCRYPT) {
            $desiredBcryptCost = is_array($config) && array_key_exists('hash_cost', $config)
                ? self::costValue($config['hash_cost']) : 12;
        } elseif (str_starts_with($method, self::HASH_CRYPT)) {
            $desiredBcryptCost = 12;
        } elseif (strcasecmp($method, 'dovecot:BLF-CRYPT') === 0) {
            $desiredBcryptCost = PASSWORD_BCRYPT_DEFAULT_COST;
        }

        if ($desiredBcryptCost !== null) {
            if (preg_match('/^\$2[aby]\$(\d{2})\$/', $classifiedHash, $matches) !== 1) {
                return true;
            }

            // Never turn a login into an accidental work-factor downgrade.
            return (int) $matches[1] < $desiredBcryptCost;
        }

        // Plaintext policies are retained for compatibility, but must never
        // replace an already hashed credential with plaintext during login.
        if ($method === self::HASH_PLAIN || $method === self::HASH_PLAINTEXT) {
            return false;
        }

        // For Dovecot crypt policies, upgrade older schemes while preserving a
        // bcrypt credential if an installation changes to a weaker policy.
        if (str_starts_with($method, self::HASH_DOVECOT)) {
            if (preg_match('/^\$2[aby]\$/', $classifiedHash) === 1) {
                return false;
            }

            return match(strtoupper(substr($method, 8))) {
                'SHA512-CRYPT' => !str_starts_with($classifiedHash, '$6$'),
                'SHA256-CRYPT' => !str_starts_with($classifiedHash, '$5$'),
                default => false,
            };
        }

        return false;
    }
}
