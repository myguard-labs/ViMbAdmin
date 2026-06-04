<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Open Source Solutions Limited T/A Open Solutions
 *   147 Stepaside Park, Stepaside, Dublin 18, Ireland.
 *   Barry O'Donovan <barry _at_ opensolutions.ie>
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Open Source Solutions Limited <info _at_ opensolutions.ie>
 * @author Barry O'Donovan <barry _at_ opensolutions.ie>
 */

/*
 * @package ViMbAdmin
 * @subpackage Library
 */
class ViMbAdmin_Dovecot
{

    // crypt() scheme identifiers, keyed by Dovecot scheme name. These produce
    // exactly the bare hash strings Dovecot stores after its {SCHEME} prefix:
    //   BLF-CRYPT    -> $2y$...   (bcrypt / Blowfish)
    //   SHA512-CRYPT -> $6$...
    //   SHA256-CRYPT -> $5$...
    const SUPPORTED_SCHEMES = [ 'BLF-CRYPT', 'SHA512-CRYPT', 'SHA256-CRYPT' ];

    /**
     * Generate a Dovecot-compatible password hash natively in PHP (no external
     * doveadm binary, no network round-trip). Returns the BARE hash WITHOUT the
     * leading {SCHEME} marker, matching the value Dovecot's `doveadm pw` printed
     * (the panel stores the bare hash; the "dovecot:" marker lives elsewhere).
     *
     * Only the crypt-family schemes Dovecot and PHP share are supported
     * (BLF-CRYPT, SHA512-CRYPT, SHA256-CRYPT). These cover the panel's offered
     * options; any other scheme throws rather than silently producing a hash
     * Dovecot would reject.
     *
     * @param string $scheme The Dovecot scheme (e.g. "BLF-CRYPT")
     * @param string $pass The plaintext password
     * @param string $user The username (unused for crypt schemes; kept for API)
     * @throws ViMbAdmin_Exception
     * @return string The bare crypt() hash (no {SCHEME} prefix)
     */
    public static function password( $scheme, $pass, $user )
    {
        $scheme = strtoupper( $scheme );

        switch( $scheme )
        {
            case 'BLF-CRYPT':
                // password_hash() emits the modern $2y$ bcrypt prefix Dovecot
                // accepts under {BLF-CRYPT}.
                $hash = password_hash( $pass, PASSWORD_BCRYPT );
                break;

            case 'SHA512-CRYPT':
                $hash = crypt( $pass, '$6$' . self::_cryptSalt() . '$' );
                break;

            case 'SHA256-CRYPT':
                $hash = crypt( $pass, '$5$' . self::_cryptSalt() . '$' );
                break;

            default:
                throw new ViMbAdmin_Exception( sprintf(
                    _( 'Unsupported password scheme "%s" — supported: %s' ),
                    $scheme, implode( ', ', self::SUPPORTED_SCHEMES ) ) );
        }

        if( !is_string( $hash ) || strlen( $hash ) < 13 )
            throw new ViMbAdmin_Exception( _( 'Password hashing failed' ) );

        return $hash;
    }

    /**
     * Verify a plaintext password against a stored bare hash.
     *
     * Works directly off the crypt() string regardless of scheme — bcrypt via
     * password_verify(), the $5$/$6$ families via a constant-time crypt()
     * re-hash compare — so $scheme is advisory only.
     *
     * @param string $scheme The Dovecot scheme (advisory)
     * @param string $pwhash The stored bare hash (no {SCHEME} prefix)
     * @param string $pwplain The plaintext password
     * @param string $user The username (unused for crypt schemes; kept for API)
     * @return bool True if the password matches
     */
    public static function passwordVerify( $scheme, $pwhash, $pwplain, $user )
    {
        if( !is_string( $pwhash ) || $pwhash === '' )
            return false;

        // bcrypt: password_verify handles $2y$/$2a$/$2b$.
        if( strncmp( $pwhash, '$2', 2 ) === 0 )
            return password_verify( $pwplain, $pwhash );

        // $5$ (SHA-256) / $6$ (SHA-512): re-crypt with the stored hash as the
        // salt template and compare in constant time.
        return hash_equals( $pwhash, (string) crypt( $pwplain, $pwhash ) );
    }

    /**
     * Generate a 16-char base64-ish salt for the $5$/$6$ crypt families.
     *
     * @return string
     */
    private static function _cryptSalt()
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';
        for( $i = 0; $i < 16; $i++ )
            $salt .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
        return $salt;
    }

}
