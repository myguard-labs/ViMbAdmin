<?php
/**
 * ViMbAdmin two-factor authentication (TOTP).
 *
 * Wraps robthree/twofactorauth for code generation / verification and stores
 * the per-admin secret + one-time backup codes in the admin's preferences
 * (Entities\Admin via OSS WithPreferences). The secret is encrypted at rest
 * with libsodium, keyed off the application's securitysalt, so a database
 * read alone does not yield usable TOTP secrets.
 *
 * Preference keys used (on the Admin entity):
 *   auth.totp.secret   - sodium-encrypted base32 TOTP secret (enabled = present)
 *   auth.totp.backup   - JSON array of bcrypt-hashed, single-use backup codes
 *
 * @package ViMbAdmin
 */
class ViMbAdmin_TwoFactor
{
    const PREF_SECRET = 'auth.totp.secret';
    const PREF_BACKUP = 'auth.totp.backup';

    /** @var \RobThree\Auth\TwoFactorAuth */
    private $_tfa;

    /** @var string 32-byte key for sodium secretbox */
    private $_key;

    /**
     * @param string $issuer       Label shown in the authenticator app.
     * @param string $securitysalt The app securitysalt (key material).
     */
    public function __construct( $issuer = 'ViMbAdmin', $securitysalt = '' )
    {
        // Use Bacon's SVG backend: pure PHP, no imagick/gd dependency. The
        // resulting data: URI embeds an inline SVG QR code.
        $qr = new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(
            2,            // padding (quiet zone)
            '#ffffff',    // background
            '#000000',    // foreground
            'svg'         // format
        );

        $this->_tfa = new \RobThree\Auth\TwoFactorAuth( $qr, $issuer );

        // Derive a stable 32-byte key from the securitysalt. If the salt is
        // empty (misconfigured), fall back to a fixed-but-app-local digest so
        // we never key with an empty string.
        $this->_key = hash( 'sha256', 'vimbadmin-totp|' . $securitysalt, true );
    }

    // ---- enrolment -----------------------------------------------------

    /** Generate a new base32 TOTP secret. */
    public function createSecret()
    {
        return $this->_tfa->createSecret();
    }

    /** otpauth:// provisioning URI (for QR code) for a label + secret. */
    public function getProvisioningUri( $label, $secret )
    {
        return $this->_tfa->getQRText( $label, $secret );
    }

    /** Inline data: URI PNG of the QR code, for embedding in a template. */
    public function getQrDataUri( $label, $secret )
    {
        return $this->_tfa->getQRCodeImageAsDataUri( $label, $secret );
    }

    // ---- verification --------------------------------------------------

    /**
     * Verify a 6-digit TOTP code against the secret (±1 time step for skew).
     *
     * @return bool
     */
    public function verifyCode( $secret, $code )
    {
        $code = preg_replace( '/\s+/', '', (string) $code );
        if( !preg_match( '/^\d{6}$/', $code ) )
            return false;

        return $this->_tfa->verifyCode( $secret, $code, 1 );
    }

    // ---- per-admin state (encrypted at rest) ---------------------------

    /** Is 2FA enabled for this admin? */
    public function isEnabled( $admin )
    {
        return (bool) $admin->getPreference( self::PREF_SECRET );
    }

    /**
     * Enable 2FA for an admin: store the encrypted secret and a fresh set of
     * backup codes. Returns the plaintext backup codes (show once, never
     * again).
     *
     * @return string[] plaintext backup codes
     */
    public function enable( $admin, $secret )
    {
        $admin->setPreference( self::PREF_SECRET, $this->_encrypt( $secret ) );
        return $this->regenerateBackupCodes( $admin );
    }

    /** Disable 2FA for an admin (clears secret + backup codes). */
    public function disable( $admin )
    {
        $admin->deletePreference( self::PREF_SECRET );
        $admin->deletePreference( self::PREF_BACKUP );
    }

    /** Decrypt and return the admin's TOTP secret (or null). */
    public function getSecret( $admin )
    {
        $enc = $admin->getPreference( self::PREF_SECRET );
        return $enc ? $this->_decrypt( $enc ) : null;
    }

    /** Verify a submitted TOTP code for an enrolled admin. */
    public function verifyForAdmin( $admin, $code )
    {
        $secret = $this->getSecret( $admin );
        return $secret !== null && $this->verifyCode( $secret, $code );
    }

    // ---- backup codes --------------------------------------------------

    /**
     * Generate, store (hashed) and return a fresh set of one-time backup
     * codes. Each is 10 chars from an unambiguous alphabet.
     *
     * @return string[] plaintext codes
     */
    public function regenerateBackupCodes( $admin, $count = 8 )
    {
        $plain  = [];
        $hashed = [];
        for( $i = 0; $i < $count; $i++ )
        {
            $code     = OSS_String::randomFromSet( '23456789ABCDEFGHJKLMNPQRSTUVWXYZ', 10 );
            $plain[]  = $code;
            $hashed[] = password_hash( $code, PASSWORD_BCRYPT );
        }
        $admin->setPreference( self::PREF_BACKUP, json_encode( $hashed ) );
        return $plain;
    }

    /**
     * Consume a backup code: if it matches an unused stored code, remove it
     * and return true. Single use.
     *
     * @return bool
     */
    public function consumeBackupCode( $admin, $code )
    {
        $code = strtoupper( preg_replace( '/\s+/', '', (string) $code ) );
        $raw  = $admin->getPreference( self::PREF_BACKUP );
        if( !$raw )
            return false;

        $hashes = json_decode( $raw, true );
        if( !is_array( $hashes ) )
            return false;

        foreach( $hashes as $idx => $hash )
        {
            if( password_verify( $code, $hash ) )
            {
                unset( $hashes[ $idx ] );
                $admin->setPreference( self::PREF_BACKUP, json_encode( array_values( $hashes ) ) );
                return true;
            }
        }
        return false;
    }

    /** How many backup codes remain unused. */
    public function backupCodesRemaining( $admin )
    {
        $raw = $admin->getPreference( self::PREF_BACKUP );
        if( !$raw )
            return 0;
        $h = json_decode( $raw, true );
        return is_array( $h ) ? count( $h ) : 0;
    }

    // ---- crypto --------------------------------------------------------

    private function _encrypt( $plaintext )
    {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = sodium_crypto_secretbox( $plaintext, $nonce, $this->_key );
        return base64_encode( $nonce . $ct );
    }

    private function _decrypt( $encoded )
    {
        $raw = base64_decode( $encoded, true );
        if( $raw === false || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES )
            return null;
        $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $pt    = sodium_crypto_secretbox_open( $ct, $nonce, $this->_key );
        return $pt === false ? null : $pt;
    }
}
