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
    public const PREF_SECRET = 'auth.totp.secret';
    public const PREF_BACKUP = 'auth.totp.backup';
    public const PREF_LASTTS = 'auth.totp.lastts';   // last accepted TOTP timeslice (replay guard)
    public const PREF_FORCE  = 'auth.totp.force';    // admin must enrol 2FA at next login

    /** @var \RobThree\Auth\TwoFactorAuth */
    private $_tfa;

    /** @var string 32-byte key for sodium secretbox */
    private $_key;

    private static function _normalizedCode(mixed $code, string $pattern): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', $code);
        return is_string($normalized) && preg_match($pattern, $normalized) === 1
            ? $normalized
            : null;
    }

    private static function _nonNegativeIntegerOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return is_int($integer) ? $integer : null;
        }
        return null;
    }

    private static function _storedReplaySlice(mixed $value): ?int
    {
        return $value === false || $value === null
            ? 0
            : self::_nonNegativeIntegerOrNull($value);
    }

    /**
     * @param string $issuer       Label shown in the authenticator app.
     * @param string $securitysalt The app securitysalt (key material).
     */
    public function __construct($issuer = 'ViMbAdmin', $securitysalt = '')
    {
        // Use Bacon's SVG backend: pure PHP, no imagick/gd dependency. The
        // resulting data: URI embeds an inline SVG QR code.
        $qr = new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(
            2,            // padding (quiet zone)
            '#ffffff',    // background
            '#000000',    // foreground
            'svg'         // format
        );

        $this->_tfa = new \RobThree\Auth\TwoFactorAuth($qr, $issuer);

        // Derive a stable 32-byte key from the securitysalt. If the salt is
        // empty (misconfigured), fall back to a fixed-but-app-local digest so
        // we never key with an empty string.
        $this->_key = hash('sha256', 'vimbadmin-totp|' . $securitysalt, true);
    }

    // ---- enrolment -----------------------------------------------------

    /** @return string New base32 TOTP secret. */
    public function createSecret()
    {
        return $this->_tfa->createSecret();
    }

    /**
     * @param string $label
     * @param string $secret
     * @return string otpauth:// provisioning URI for the label and secret.
     */
    public function getProvisioningUri($label, $secret)
    {
        return $this->_tfa->getQRText($label, $secret);
    }

    /**
     * @param string $label
     * @param string $secret
     * @return string Inline data URI of the QR code.
     */
    public function getQrDataUri($label, $secret)
    {
        return $this->_tfa->getQRCodeImageAsDataUri($label, $secret);
    }

    // ---- verification --------------------------------------------------

    /**
     * Verify a 6-digit TOTP code against the secret (±1 time step for skew).
     *
     * @param string $secret
     * @param string $code
     * @return bool
     */
    public function verifyCode($secret, $code)
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }
        $code = self::_normalizedCode($code, '/^\d{6}$/');
        if ($code === null) {
            return false;
        }

        return $this->_tfa->verifyCode($secret, $code, 1);
    }

    // ---- per-admin state (encrypted at rest) ---------------------------

    /**
     * @param \Entities\Admin $admin
     * @return bool
     */
    public function isEnabled($admin)
    {
        $secret = $admin->getPreference(self::PREF_SECRET);
        return is_string($secret) && $secret !== '';
    }

    /**
     * Enable 2FA for an admin: store the encrypted secret and a fresh set of
     * backup codes. Returns the plaintext backup codes (show once, never
     * again).
     *
     * @param \Entities\Admin $admin
     * @param string $secret
     * @return string[] plaintext backup codes
     */
    public function enable($admin, $secret)
    {
        $admin->setPreference(self::PREF_SECRET, $this->_encrypt($secret));
        return $this->regenerateBackupCodes($admin);
    }

    /**
     * Provision 2FA for an admin with a freshly generated secret (used by a
     * super-admin setting it up on someone's behalf). Returns the plaintext
     * secret + backup codes so they can be shown / handed over.
     *
     * @param \Entities\Admin $admin
     * @return array{secret:string,backup:string[]}
     */
    public function provision($admin)
    {
        $secret = $this->createSecret();
        $backup = $this->enable($admin, $secret);
        $this->clearForce($admin);   // provisioned now, no need to force
        return [ 'secret' => $secret, 'backup' => $backup ];
    }

    /**
     * @param \Entities\Admin $admin
     * @return void
     */
    public function disable($admin)
    {
        $admin->deletePreference(self::PREF_SECRET);
        $admin->deletePreference(self::PREF_BACKUP);
        $admin->deletePreference(self::PREF_LASTTS);
    }

    // ---- force-at-next-login -------------------------------------------

    /**
     * @param \Entities\Admin $admin
     * @return bool
     */
    public function isForced($admin)
    {
        return (bool) $admin->getPreference(self::PREF_FORCE);
    }

    /**
     * @param \Entities\Admin $admin
     * @param bool $on
     * @return void
     */
    public function setForce($admin, $on = true)
    {
        if ($on) {
            $admin->setPreference(self::PREF_FORCE, '1');
        } else {
            $this->clearForce($admin);
        }
    }

    /**
     * @param \Entities\Admin $admin
     * @return void
     */
    public function clearForce($admin)
    {
        $admin->deletePreference(self::PREF_FORCE);
    }

    /**
     * @param \Entities\Admin $admin
     * @return string|null
     */
    public function getSecret($admin)
    {
        $enc = $admin->getPreference(self::PREF_SECRET);
        return is_string($enc) && $enc !== '' ? $this->_decrypt($enc) : null;
    }

    /**
     * Verify a submitted TOTP code for an enrolled admin, with replay
     * protection: a given time-slice can only be used once (a code captured by
     * a MITM cannot be replayed within its validity window).
     *
     * @param \Entities\Admin $admin
     * @param string $code
     * @return bool
     */
    public function verifyForAdmin($admin, $code)
    {
        $secret = $this->getSecret($admin);
        if ($secret === null) {
            return false;
        }

        $code = self::_normalizedCode($code, '/^\d{6}$/');
        if ($code === null) {
            return false;
        }

        $timeslice = 0;
        if (!$this->_tfa->verifyCode($secret, $code, 1, null, $timeslice)) {
            return false;
        }

        // Reject replay: the matched slice must be newer than the last one we
        // accepted for this admin.
        $last = self::_storedReplaySlice($admin->getPreference(self::PREF_LASTTS));
        if ($last === null) {
            return false;
        }
        if ($timeslice <= $last) {
            return false;
        }

        $admin->setPreference(self::PREF_LASTTS, (string) $timeslice);
        return true;
    }

    // ---- backup codes --------------------------------------------------

    /**
     * Generate, store (hashed) and return a fresh set of one-time backup
     * codes. Each is 10 chars from an unambiguous alphabet.
     *
     * @param \Entities\Admin $admin
     * @param int $count
     * @return string[] plaintext codes
     * @phpstan-impure
     */
    public function regenerateBackupCodes($admin, $count = 8)
    {
        $plain  = [];
        $hashed = [];
        for ($i = 0; $i < $count; $i++) {
            $code     = OSS_String::randomFromSet('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', 10);
            $plain[]  = $code;
            $hashed[] = password_hash($code, PASSWORD_BCRYPT);
        }
        $admin->setPreference(self::PREF_BACKUP, json_encode($hashed, JSON_THROW_ON_ERROR));
        return $plain;
    }

    /**
     * Consume a backup code: if it matches an unused stored code, remove it
     * and return true. Single use.
     *
     * @param \Entities\Admin $admin
     * @param string $code
     * @return bool
     * @phpstan-impure
     */
    public function consumeBackupCode($admin, $code)
    {
        $code = self::_normalizedCode($code, '/^[23456789A-HJ-NP-Z]{10}$/i');
        if ($code === null) {
            return false;
        }
        $code = strtoupper($code);
        $raw  = $admin->getPreference(self::PREF_BACKUP);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $hashes = json_decode($raw, true);
        if (!is_array($hashes) || !array_is_list($hashes)) {
            return false;
        }

        foreach ($hashes as $idx => $hash) {
            if (!is_string($hash)) {
                return false;
            }
            if (password_verify($code, $hash)) {
                unset($hashes[ $idx ]);
                $admin->setPreference(self::PREF_BACKUP, json_encode(array_values($hashes), JSON_THROW_ON_ERROR));
                return true;
            }
        }
        return false;
    }

    /**
     * @param \Entities\Admin $admin
     * @return int
     */
    public function backupCodesRemaining($admin)
    {
        $raw = $admin->getPreference(self::PREF_BACKUP);
        if (!is_string($raw) || $raw === '') {
            return 0;
        }
        $h = json_decode($raw, true);
        if (!is_array($h) || !array_is_list($h)) {
            return 0;
        }
        foreach ($h as $hash) {
            if (!is_string($hash)) {
                return 0;
            }
        }
        return count($h);
    }

    // ---- crypto --------------------------------------------------------

    /**
     * @param string $plaintext
     * @return string
     */
    private function _encrypt($plaintext)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = sodium_crypto_secretbox($plaintext, $nonce, $this->_key);
        return base64_encode($nonce . $ct);
    }

    /**
     * @param string $encoded
     * @return string|null
     */
    private function _decrypt($encoded)
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $pt    = sodium_crypto_secretbox_open($ct, $nonce, $this->_key);
        return $pt === false ? null : $pt;
    }
}
