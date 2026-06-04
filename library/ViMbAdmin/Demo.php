<?php

/**
 * Demo-account lock.
 *
 * For a public demo deployment you want ONE well-known account that visitors
 * can log in with, but that they cannot lock everyone else out of by changing
 * its password or enrolling 2FA. Configure it in application.ini:
 *
 *   [demo]
 *   demo.account  = "demo@example.com"
 *   demo.password = "letmein"
 *
 * When `demo.account` is set:
 *   - that account is blocked from password changes and from enrolling /
 *     changing 2FA (the actions refuse with a "disabled in the demo" message);
 *   - the login page advertises the credentials so visitors can get in.
 *
 * The password value is shown verbatim on the login page (it is, by design, a
 * shared public secret), so do NOT point this at a real account.
 *
 * All reads are null-safe: with no [demo] section the helper is inert and the
 * panel behaves normally.
 */
class ViMbAdmin_Demo
{
    /**
     * The configured demo account address, or null if the demo lock is off.
     *
     * @param array $options  merged application.ini options
     * @return string|null
     */
    public static function account( array $options )
    {
        $acct = isset( $options['demo']['account'] ) ? trim( (string) $options['demo']['account'] ) : '';
        return $acct !== '' ? $acct : null;
    }

    /**
     * The advertised demo password (shown on the login page), or null.
     *
     * @param array $options
     * @return string|null
     */
    public static function password( array $options )
    {
        if( self::account( $options ) === null )
            return null;
        $pw = isset( $options['demo']['password'] ) ? (string) $options['demo']['password'] : '';
        return $pw !== '' ? $pw : null;
    }

    /**
     * Is the demo lock active at all?
     *
     * @param array $options
     * @return bool
     */
    public static function enabled( array $options )
    {
        return self::account( $options ) !== null;
    }

    /**
     * Is $username the locked demo account? Case-insensitive. False when the
     * demo lock is off or $username is empty.
     *
     * @param array  $options
     * @param string $username
     * @return bool
     */
    public static function isLocked( array $options, $username )
    {
        $acct = self::account( $options );
        if( $acct === null || $username === null || $username === '' )
            return false;
        return strcasecmp( $acct, (string) $username ) === 0;
    }
}
