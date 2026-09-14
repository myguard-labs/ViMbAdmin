<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\Form\FormRenderer;
use ViMbAdmin\Kernel\Form\Validators;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native login / logout (docs/ZF1-REMOVAL.md) — the framework-free replacement
 * for the ZF1 framework-auth login, the deepest auth coupling.
 *
 * SECURITY: this reuses the same vetted password and 2FA primitives rather
 * than re-implementing any of them — password verification
 * ({@see \OSS_Auth_Password::verify}), the brute-force gate
 * ({@see \ViMbAdmin_BruteForce}), and the two-factor gate
 * ({@see \ViMbAdmin_TwoFactor}). The order and semantics mirror
 * `OSS_Controller_Trait_Auth::loginAction` + `AuthController::_postLoginChecks`:
 *
 *   1. already authenticated → bounce home;
 *   2. zero admins → first-run setup;
 *   3. brute-force: refuse a locked source (429 + exit), count this attempt;
 *   4. verify the credentials; a miss stays indistinguishable from an unknown
 *      account while the source brute-force counter remains authoritative;
 *   5. on success, BEFORE granting a session: the 2FA gate — an enabled or
 *      force-enrolled admin is parked (`totp_pending_admin_id`) and redirected to
 *      the native `auth/totp` / `auth/totp-setup` flow, so 2FA is never bypassed;
 *   6. otherwise regenerate the session id (fixation defence), grant the identity
 *      via {@see \ViMbAdmin\Kernel\Security\Auth::establish()} (which writes the
 *      native identity slot), clear
 *      the brute-force counter and stamp last-login.
 *
 * The unauthenticated lost-password page is rate-limited by its own
 * {@see \ViMbAdmin_BruteForce} instance -- a separate state namespace and its own
 * generous ceiling -- so a flood of captcha-minting renders throttles itself
 * without being able to spend the login budget and lock admins out of login.
 *
 * Remember-me cookies and login-history are intentionally NOT carried over in
 * this first cut (dropping remember-me is a safe reduction). Login, logout, setup,
 * the 2FA flow (totp / totp-setup), the mailbox self-service change-password and
 * the lost-password / reset-password flow and captcha image are all native.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AuthController extends AbstractController
{
    private const LOGIN_ERROR = 'Invalid username or password. Please try again.';

    /**
     * Brute-force state subdirectory for the unauthenticated lost-password
     * render budget. Keeping it out of the login directory is what stops a
     * lost-password flood from locking anyone out of /auth/login.
     */
    private const LOST_PASSWORD_BRUTE_FORCE_SCOPE = 'lost-password';

    /** Default ceiling for that budget (bruteforce.lost_password_max_attempts). */
    private const LOST_PASSWORD_MAX_ATTEMPTS = 30;

    private static function requiredString(mixed $value, string $name): string
    {
        return \ViMbAdmin\Kernel\Input\Reader::requiredString($value, $name);
    }

    private static function stringOrDefault(mixed $value, string $default, string $name): string
    {
        if ($value === null) {
            return $default;
        }

        return self::requiredString($value, $name);
    }

    private static function applicationPathOrDefault(mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_starts_with($value, '//')
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value) === 1) {
            return $default;
        }

        return $value;
    }

    private static function integerOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return is_int($integer) ? $integer : null;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function stringKeyedArray(mixed $value, string $name): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::stringKeyedArray($value, $name);
    }

    /** @param array<string,mixed> $options */
    private static function option(array $options, string ...$path): mixed
    {
        [$found, $value] = \ViMbAdmin\Kernel\Input\Reader::option($options, ...$path);
        return $found ? $value : null;
    }

    /** @param array<string,mixed> $options */
    private static function optionString(array $options, string $default, string ...$path): string
    {
        return self::stringOrDefault(
            self::option($options, ...$path),
            $default,
            'Configuration ' . implode('.', $path),
        );
    }

    /** @param array<string,mixed> $options */
    private static function optionNullableString(array $options, string ...$path): ?string
    {
        $value = self::option($options, ...$path);
        return $value === null
            ? null
            : self::requiredString($value, 'Configuration ' . implode('.', $path));
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private static function optionArray(array $options, array $default, string ...$path): array
    {
        $value = self::option($options, ...$path);
        return $value === null
            ? $default
            : self::stringKeyedArray($value, 'Configuration ' . implode('.', $path));
    }

    /** @param array<string,mixed> $options */
    private static function optionInt(array $options, int $default, string ...$path): int
    {
        $value = self::option($options, ...$path);
        if ($value === null) {
            return $default;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new \LogicException('Configuration ' . implode('.', $path) . ' must be a non-negative integer');
    }

    /** @param array<string,mixed> $options */
    private static function optionBool(array $options, bool $default, string ...$path): bool
    {
        $value = self::option($options, ...$path);
        if ($value === null) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        throw new \LogicException('Configuration ' . implode('.', $path) . ' must be boolean');
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|string
     */
    private static function passwordOptions(array $options): array|string
    {
        $value = self::option($options, 'resources', 'auth', 'oss');
        if (is_string($value)) {
            return $value;
        }

        return self::stringKeyedArray($value, 'Configuration resources.auth.oss');
    }

    /** @param array<string,mixed> $options */
    private static function validateAuthEmailOptions(array $options): void
    {
        $values = [
            self::optionString($options, '', 'identity', 'sitename'),
            self::optionString($options, 'do-not-reply@localhost', 'identity', 'mailer', 'email'),
            self::optionString($options, '', 'identity', 'mailer', 'name'),
        ];
        foreach ($values as $value) {
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \LogicException('Authentication email configuration contains control characters');
            }
        }

        $format = self::optionString($options, 'both', 'resources', 'auth', 'oss', 'email_format');
        if (!in_array($format, ['html', 'plaintext', 'both'], true)) {
            throw new \LogicException('Configuration resources.auth.oss.email_format is invalid');
        }
    }

    public function captchaImageAction(): Response
    {
        $path = \OSS_Captcha_Image::path(self::stringOrDefault($this->param('id'), '', 'Captcha id'));
        if ($path === null) {
            return Response::text('Not found', 404);
        }

        return new Response(
            (string) file_get_contents($path),
            200,
            'image/png',
            ['Cache-Control' => 'no-store, max-age=0']
        );
    }

    /**
     * GET|POST /auth/login.
     */
    public function loginAction(): Response
    {
        if ($this->admin() !== null) {
            return $this->redirect('');
        }

        if ((int) $this->adminRepository()->getCount() === 0) {
            return $this->redirect('auth/setup');
        }

        $options = $this->container->options();
        $bf      = $this->bruteForce($options);

        // Refuse a locked-out source (sends 429 + exits), then count this POST.
        $bf->assertNotLocked(null);

        $form = $this->buildLoginForm();

        if ($this->isPost()) {
            $post = $this->postData();
            $bf->record(self::stringOrDefault($post['username'] ?? null, '', 'Login username'), null);

            if ($form->isValid($post)) {
                $values   = $form->values();
                $username = self::requiredString($values['username'] ?? null, 'Login username');
                $admin    = $this->adminRepository()->findOneBy(['username' => $username]);
                $authOpts = self::option($options, 'resources', 'auth', 'oss');

                $plainPassword = self::requiredString($values['password'] ?? null, 'Login password');
                $verifiedHash = $admin === null
                    ? null
                    : self::verifiedAdminPassword($admin, $plainPassword, $authOpts);
                if ($admin !== null && $verifiedHash !== null && self::adminIsActive($admin)) {
                    $this->storeRehashedAdminPassword($admin, $verifiedHash);
                    return $this->completeLogin($admin, $bf, $options);
                }

                $this->flash(self::LOGIN_ERROR, FlashMessages::ERROR);
            }
        }

        return $this->view('auth/native-login.phtml', [
            'formHtml'     => (new FormRenderer())->render($form, '/auth/login', 'Log In'),
            'demoAccount'  => \ViMbAdmin_Demo::account($options),
            'demoPassword' => \ViMbAdmin_Demo::password($options),
        ]);
    }

    /**
     * GET|POST /auth/setup — first-run: create the initial super administrator.
     *
     * Faithful port of the ZF1 `setupAction` for the common case (a configured
     * 64-char `securitysalt`). Guards: it only runs when there are zero admins and
     * nobody is logged in (else it flashes and bounces, as ZF1 did). The
     * security-salt-not-yet-configured path renders the native setup-salt view
     * with generated values to paste into `application.ini`.
     *
     * With the salt configured, the submitted `salt` must match the configured
     * `securitysalt` (the first-run gate, exactly as ZF1) before the first admin is
     * created super + active, the Doctrine migration row is seeded, and the user is
     * sent to the login page. There is no logged-in actor on a first run, so —
     * unlike the authenticated add path — this writes no Log row and does not go
     * through `Service_Admin::create`. The first-admin welcome email remains
     * intentionally omitted from this bootstrap-only path.
     */
    public function setupAction(): Response
    {
        if ((int) $this->adminRepository()->getCount() !== 0) {
            $this->flash('Admins already exist in the system.', FlashMessages::INFO);
            return $this->redirect('auth/login');
        }

        if ($this->admin() !== null) {
            $this->flash('You are already logged in.', FlashMessages::INFO);
            return $this->redirect('');
        }

        $options = $this->container->options();
        $salt    = self::optionString($options, '', 'securitysalt');

        // Salt not configured yet (fresh install before the [user] section is
        // filled in): render the first-run "set your security salts" screen
        // natively, generating the same three salts the ZF1 screen offered.
        if (strlen($salt) !== 64) {
            return $this->view('auth/native-setup-salt.phtml', [
                'randomSalt'   => \OSS_String::salt(64),
                'rememberSalt' => \OSS_String::salt(64),
            ]);
        }

        $form = $this->buildSetupForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $values = $form->values();

            if (!hash_equals($salt, self::requiredString($values['salt'] ?? null, 'Setup salt'))) {
                $this->flash('Incorrect security salt provided. Please copy and paste it from the application.ini file.', FlashMessages::INFO);
                return $this->redirect('auth/login');
            }

            $admin = new \Entities\Admin();
            $admin->setUsername(self::requiredString($values['username'] ?? null, 'Setup username'));
            $admin->setPassword(
                \OSS_Auth_Password::hash(
                    self::requiredString($values['password'] ?? null, 'Setup password'),
                    self::passwordOptions($options),
                )
            );
            $admin->setSuper(true);
            $admin->setActive(true);
            $admin->setCreated(new \DateTime());
            $admin->setModified(new \DateTime());
            $this->em()->persist($admin);

            // Seed the Doctrine migration row, exactly as the ZF1 setup did.
            $dbversion = new \Entities\DatabaseVersion();
            $dbversion->setVersion(\ViMbAdmin_Version::DBVERSION);
            $dbversion->setName(\ViMbAdmin_Version::DBVERSION_NAME);
            $dbversion->setAppliedOn(new \DateTime());
            $this->em()->persist($dbversion);

            $this->em()->flush();

            $this->flash('Your administrator account has been added. Please log in below.');
            return $this->redirect('auth/login');
        }

        return $this->view('auth/native-setup.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/setup', 'Create Administrator'),
        ]);
    }

    /**
     * GET|POST /auth/totp — second-factor verification for a parked login.
     *
     * Faithful port of the ZF1 `totpAction`. It runs PRE-auth: the native login
     * (or totp-setup) parked an enabled 2FA admin in `totp_pending_admin_id` and
     * redirected here; the identity is granted only once a valid TOTP (or a
     * one-time backup) code is supplied. Both the verification and the secret
     * handling go through the already-framework-free `ViMbAdmin_TwoFactor`
     * (robthree/twofactorauth + libsodium), so there is no ZF1 dependency. The
     * rendered form's CSRF token is validated before a code reaches either TOTP
     * primitive. A wrong verified-form code is counted against the brute-force gate.
     */
    public function totpAction(): Response
    {
        if ($this->admin() !== null) {
            return $this->redirect('');
        }

        $session   = new MagicPropertyStorage($this->session());
        $pendingId = self::integerOrNull($session->get('totp_pending_admin_id'));
        if ($pendingId === null) {
            $session->remove('totp_pending_admin_id');
            return $this->redirect('auth/login');
        }

        $admin = $this->adminRepository()->find($pendingId);
        if (!$admin || !self::adminIsActive($admin)) {
            $this->abandonPendingLogin($session);
            $this->flash(self::LOGIN_ERROR, FlashMessages::ERROR);
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();
        $form = $this->buildTotpForm();

        if ($this->isPost()) {
            $post = $this->postData();
            if ($form->isValid($post)) {
                $tfa  = new \ViMbAdmin_TwoFactor('ViMbAdmin', self::optionString($options, '', 'securitysalt'));
                $code = self::requiredString($form->values()['code'] ?? null, 'Authentication code');
                $bf   = $this->bruteForce($options);

                if ($tfa->verifyForAdmin($admin, $code) || $tfa->consumeBackupCode($admin, $code)) {
                    $bf->clear($admin->getUsername(), null);
                    return $this->grantPendingLogin($admin, $session);
                }

                $bf->record($admin->getUsername(), null);
                $this->em()->flush();
                $this->flash('Invalid authentication code. Please try again.', FlashMessages::ERROR);
            }
        }

        return $this->view('auth/native-totp.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/totp', 'Verify'),
        ]);
    }

    /**
     * GET|POST /auth/totp-setup — forced first-time 2FA enrolment for a parked
     * login. Faithful port of the ZF1 `totpSetupAction`: it mints (and stashes in
     * the session) an enrolment secret, shows the QR + manual secret, and on a
     * verifying code enables 2FA (storing the libsodium-encrypted secret + backup
     * codes on the admin), clears the force flag, grants the identity and shows the
     * one-time backup codes. The demo account may not enrol. Uses the
     * framework-free `ViMbAdmin_TwoFactor`; no ZF1.
     */
    public function totpSetupAction(): Response
    {
        if ($this->admin() !== null) {
            return $this->redirect('');
        }

        $session   = new MagicPropertyStorage($this->session());
        $pendingId = self::integerOrNull($session->get('totp_pending_admin_id'));
        if ($pendingId === null) {
            $session->remove('totp_pending_admin_id');
            return $this->redirect('auth/login');
        }

        $admin = $this->adminRepository()->find($pendingId);
        if (!$admin || !self::adminIsActive($admin)) {
            $this->abandonPendingLogin($session);
            $this->flash(self::LOGIN_ERROR, FlashMessages::ERROR);
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();

        if (\ViMbAdmin_Demo::isLocked($options, $admin->getUsername())) {
            $session->remove('totp_pending_admin_id');
            $this->flash('Two-factor enrolment is disabled for the demo account.', FlashMessages::INFO);
            return $this->redirect('auth/login');
        }

        $tfa = new \ViMbAdmin_TwoFactor('ViMbAdmin', self::optionString($options, '', 'securitysalt'));

        $secret = $session->get('totp_setup_secret');
        if (!is_string($secret) || $secret === '') {
            $secret = $tfa->createSecret();
            $session->set('totp_setup_secret', $secret);
        }

        $code = self::stringOrDefault($this->postData()['code'] ?? null, '', 'Authentication code');
        if ($this->isPost() && trim($code) !== '') {
            if ($tfa->verifyCode($secret, trim($code))) {
                // Keep the fallible brute-force boundary before enrolment mutates
                // one-time credentials or commits them to the database.
                $this->bruteForce($options)->clear($admin->getUsername(), null);

                $backup = $tfa->enable($admin, $secret);
                $tfa->clearForce($admin);
                $this->em()->flush();
                $session->remove('totp_setup_secret');

                // The enable/flush boundary may observe a concurrent
                // deactivation.  In that case grantPendingLogin revokes the
                // pending session and its redirect must win over this view.
                if (!$this->pendingAdminIsStillActive($admin)) {
                    return $this->grantPendingLogin($admin, $session);
                }

                // Grant the identity, but render the one-time backup codes first.
                $this->grantPendingLogin($admin, $session);

                return $this->view('auth/totp-setup.phtml', [
                    'justEnabled' => true,
                    'backupCodes' => $backup,
                ]);
            }

            $this->flash('That code did not verify. Scan the QR and try again.', FlashMessages::ERROR);
        }

        return $this->view('auth/totp-setup.phtml', [
            'secret'    => $secret,
            'qrDataUri' => $tfa->getQrDataUri($admin->requiredUsername(), $secret),
        ]);
    }

    private static function verifiedAdminPassword(
        \Entities\Admin $admin,
        string $plain,
        mixed $options,
    ): ?string {
        $stored = $admin->getPassword();
        if ($stored === null || (!is_string($options) && !is_array($options))) {
            return null;
        }

        /** @var array<string, mixed>|string $options */
        return \OSS_Auth_Password::verifyAndRehash($plain, $stored, $options);
    }

    private function storeRehashedAdminPassword(
        \Entities\Admin $admin,
        string $verifiedHash,
    ): void {
        $stored = $admin->getPassword();
        if ($stored !== null && !hash_equals($stored, $verifiedHash)) {
            try {
                // Exact-hash CAS prevents a concurrent reset from being
                // overwritten. Do not mutate the managed entity: zero affected
                // rows and database errors both leave it clean for later flushes.
                $this->adminRepository()->compareAndSwapPassword($admin, $stored, $verifiedHash);
            } catch (\Throwable) {
                // Rehash persistence is opportunistic; a verified login remains
                // available when this best-effort upgrade cannot be written.
            }
        }
    }

    /**
     * GET|POST /auth/change-password — mailbox-owner self-service password change.
     *
     * Faithful port of the ZF1 `changePasswordAction`: a public (pre-auth) form
     * where a mailbox owner supplies their address + current password + a new one.
     * The current password is verified against the MAILBOX password (not an admin)
     * with the configured mailbox scheme, and on success the new password is hashed
     * + stored — all via the already-framework-free {@see \OSS_Auth_Password}
     * (PHP-native dovecot hashing), so no ZF1. The source-IP brute-force gate
     * refuses locked sources and counts failed credential checks. The demo
     * account is refused. A wrong username or current password gives the same
     * generic "Invalid username or password" as ZF1 (no user enumeration).
     */
    public function changePasswordAction(): Response
    {
        $options = $this->container->options();
        $bf      = $this->bruteForce($options);

        $bf->assertNotLocked(null);

        if ($this->isPost() && \ViMbAdmin_Demo::isLocked(
            $options,
            self::stringOrDefault($this->postData()['username'] ?? null, '', 'Mailbox username'),
        )) {
            $this->flash('Password changes are disabled for the demo account.', FlashMessages::ERROR);
            return $this->redirect('auth/change-password');
        }

        $minPw = self::optionInt($options, 8, 'defaults', 'mailbox', 'min_password_length');
        $form  = $this->buildChangePasswordForm($minPw);

        if ($this->isPost()) {
            $post = $this->postData();

            if ($form->isValid($post)) {
                $v        = $form->values();
                $username = self::requiredString($v['username'] ?? null, 'Mailbox username');
                $mailbox  = $this->mailboxRepository()->findOneBy(['username' => $username]);

                $pwOpts = [
                    'pwhash'   => self::optionNullableString($options, 'defaults', 'mailbox', 'password_scheme'),
                    'username' => $username,
                ];

                if ($mailbox !== null
                    && self::mailboxPasswordMatches(
                        $mailbox,
                        self::requiredString($v['current_password'] ?? null, 'Current mailbox password'),
                        $pwOpts,
                    )) {
                    $mailbox->setPassword(\OSS_Auth_Password::hash(
                        self::requiredString($v['new_password'] ?? null, 'New mailbox password'),
                        $pwOpts,
                    ));
                    $this->em()->flush();
                    $this->flash('You have successfully changed your password.');
                    return $this->redirect('auth/change-password');
                }

                // The limiter is shared with administrator login and keyed only
                // by source IP. Never let a valid mailbox credential clear that
                // shared state; only failed mailbox checks add an attempt.
                $bf->record($username, null);

                // Generic message — do not reveal whether the username exists.
                $this->flash('Invalid username or password.', FlashMessages::ERROR);
            }
        }

        return $this->view('auth/native-change-password.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/change-password', 'Change Password'),
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function mailboxPasswordMatches(\Entities\Mailbox $mailbox, string $plain, array $options): bool
    {
        $hash = $mailbox->getPassword();
        return $hash !== null && \OSS_Auth_Password::verify($plain, $hash, $options);
    }

    /**
     * GET /auth/logout — drop the identity and the session, then back to login.
     */
    public function logoutAction(): Response
    {
        $this->container->auth()->clear();

        // Fully drop the session on logout — clearing only the identity left
        // second-factor state behind (`totp_verified` is never reset), so a
        // later password-only login IN THE SAME SESSION skipped the 2FA gate.
        // Wipe all session data, then start a fresh empty session id.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
        }

        return $this->redirect('auth/login');
    }

    /**
     * GET|POST /auth/lost-password — request a password-reset token by email.
     *
     * Native port of `OSS_Controller_Trait_Auth::lostPasswordAction()`. On a
     * valid POST it looks the admin up by username and, to avoid revealing which
     * usernames exist, ALWAYS shows the same success message and redirects to the
     * reset form — only actually minting a token + sending mail when the admin is
     * found. The token is a 40-char random string stored as an indexed, expiring
     * (2h, max 5) `tokens.password_reset` preference on the admin entity (the
     * framework-free `OSS_Doctrine2_WithPreferences` API), and mailed as a
     * reset-link via the native {@see Mailer}.
     *
     * The captcha is honoured when `resources.auth.oss.lost_password.use_captcha`
     * is on: a fresh `OSS_Captcha_Image` is generated for the render and the
     * submitted text is validated (as a field rule) against the SUBMITTED captcha
     * id — exactly as ZF1 did. Clicking the image re-requests a new one
     * (`requestnewimage`), short-circuiting before validation.
     */
    public function lostPasswordAction(): Response
    {
        $options     = $this->container->options();
        self::validateAuthEmailOptions($options);
        $useCaptcha  = self::optionBool(
            $options,
            false,
            'resources',
            'auth',
            'oss',
            'lost_password',
            'use_captcha',
        );
        $entityClass = $this->authEntityClass($options);

        // Unauthenticated and free to hit: every request here mints a captcha
        // and does template work, so it is rate-limited per source. Refuse a
        // locked-out source (429 + exit), then count this request, so a
        // sustained flood locks itself out.
        //
        // Deliberately its OWN namespace and budget, NOT login's. Sharing them
        // would let anyone in the source prefix spend the login budget with
        // bare unauthenticated GETs -- no credentials, no POST, no CSRF -- and
        // lock every admin behind that prefix (a /24, a NAT, a residential /64)
        // out of /auth/login. The budget is also generous, because a user who
        // reloads the page or clicks "new image" a few times is not an attack.
        $bruteForce = $this->bruteForce(
            $options,
            self::LOST_PASSWORD_BRUTE_FORCE_SCOPE,
            self::optionInt(
                $options,
                self::LOST_PASSWORD_MAX_ATTEMPTS,
                'bruteforce',
                'lost_password_max_attempts',
            ),
        );
        $bruteForce->assertNotLocked(null);
        $bruteForce->record(null, null);

        $form = $this->buildLostPasswordForm($useCaptcha);
        $form->field('username')?->setValue(
            self::stringOrDefault($this->param('username'), '', 'Password-reset username'),
        );

        if ($this->isPost()) {
            $post = $this->postData();

            // "click image for a new one": re-render with a fresh captcha, keep
            // the typed username, do NOT validate yet.
            if ($useCaptcha && !empty($post['requestnewimage'])) {
                $form->field('username')?->setValue(
                    self::stringOrDefault($post['username'] ?? null, '', 'Password-reset username'),
                );
                return $this->renderLostPassword($form, $useCaptcha);
            }

            if ($form->isValid($post)) {
                $username = self::requiredString(
                    $form->values()['username'] ?? null,
                    'Password-reset username',
                );
                $user     = $this->em()->getRepository($entityClass)->findOneBy(['username' => $username]);

                // Anti-enumeration: identical response whether or not the user exists.
                if ($user === null) {
                    $this->flash(
                        'If your username was correct, then an email with a key to allow you to change your password below has been sent to you.'
                    );
                    return $this->redirect('auth/reset-password/username/' . rawurlencode($username));
                }

                if ($user->cleanExpiredPreferences()) {
                    $this->em()->flush();
                }

                $token = \OSS_String::random(40);

                try {
                    $user->addIndexedPreference('tokens.password_reset', $token, '=', time() + 2 * 60 * 60, 5);
                } catch (\OSS_Doctrine2_WithPreferences_IndexLimitException $e) {
                    $this->flash(
                        'The limit of password reset tokens has been reached. Please try again later when the existing ones will expire or contact support.',
                        FlashMessages::ERROR
                    );
                    return $this->redirect('auth/lost-password');
                }

                $this->em()->flush();

                $this->sendAuthEmail(
                    'lost-password',
                    self::optionString($options, '', 'identity', 'sitename') . ' - Password Reset Information',
                    $user,
                    ['token' => $token]
                );

                $this->flash(
                    'If your username was correct, then an email with a key to allow you to change your password below has been sent to you.'
                );
                error_log(sprintf('%s requested a reset password token', $user->getUsername()));

                return $this->redirect('auth/reset-password/username/' . rawurlencode($username));
            }
        }

        return $this->renderLostPassword($form, $useCaptcha);
    }

    /**
     * GET|POST /auth/reset-password — set a new password using an emailed token.
     *
     * Native port of `OSS_Controller_Trait_Auth::resetPasswordAction()`. The GET
     * (reached from the emailed link `/auth/reset-password/username/<u>/token/<t>`)
     * prefills username + token from the path. A valid POST verifies the token is
     * among the admin's live `tokens.password_reset` preferences, sets the new
     * password hash, clears ALL reset tokens, zeroes any failed-login counter,
     * mails a confirmation, and redirects to login. Every failure path uses the
     * SAME generic "invalid username / token" message (anti-enumeration).
     */
    public function resetPasswordAction(): Response
    {
        $options     = $this->container->options();
        self::validateAuthEmailOptions($options);
        $entityClass = $this->authEntityClass($options);
        $form        = $this->buildResetPasswordForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v    = $form->values();
            $username = self::requiredString($v['username'] ?? null, 'Password-reset username');
            $token = self::requiredString($v['token'] ?? null, 'Password-reset token');
            $user = $this->em()->getRepository($entityClass)->findOneBy(['username' => $username]);

            if ($user === null) {
                $this->flash('Invalid username / token combination. Please check your details and try again.', FlashMessages::ERROR);
            } else {
                if ($user->cleanExpiredPreferences()) {
                    $this->em()->flush();
                }

                $tokens = $user->getIndexedPreference('tokens.password_reset');

                $tokenMatches = false;
                if (is_array($tokens)) {
                    foreach ($tokens as $candidate) {
                        if (is_string($candidate) && hash_equals($candidate, $token)) {
                            $tokenMatches = true;
                        }
                    }
                }

                if (!$tokenMatches) {
                    $this->flash('Invalid username / token combination. Please check your details and try again.', FlashMessages::ERROR);
                } else {
                    $user->setPassword(\OSS_Auth_Password::hash(
                        self::requiredString($v['password'] ?? null, 'New admin password'),
                        self::passwordOptions($options),
                    ));
                    $user->deletePreference('tokens.password_reset');

                    if (method_exists($user, 'setFailedLogins')) {
                        $user->setFailedLogins(0);
                    }

                    $this->em()->flush();

                    $this->sendAuthEmail(
                        'reset-password',
                        self::optionString($options, '', 'identity', 'sitename') . ' - Your Password Has Been Reset',
                        $user,
                        []
                    );

                    $this->flash('Your password has been successfully changed. Please log in below with your new password.');
                    error_log(sprintf('%s has completed a password reset', $user->getUsername()));

                    return $this->redirect('auth/login');
                }
            }
        } else {
            // GET (incl. the emailed link): prefill from the path params.
            $form->field('username')?->setValue(
                self::stringOrDefault($this->param('username'), '', 'Password-reset username'),
            );
            $form->field('token')?->setValue(
                self::stringOrDefault($this->param('token'), '', 'Password-reset token'),
            );
        }

        return $this->view('auth/native-reset-password.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/reset-password', 'Reset Password'),
        ]);
    }

    /**
     * Finish a 2FA-gated login: regenerate the session id, mark 2FA done, grant
     * the identity (same legacy slot), and stamp last-login. Mirrors the ZF1
     * `_reauthenticate` + session bookkeeping. Returns the post-auth redirect
     * (honouring a stashed `postAuthRedirect`).
     */
    private function grantPendingLogin(\Entities\Admin $admin, MagicPropertyStorage $session): Response
    {
        if (!self::adminIsActive($admin)) {
            $this->abandonPendingLogin($session);
            $this->flash(self::LOGIN_ERROR, FlashMessages::ERROR);
            return $this->redirect('auth/login');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $session->set('totp_verified', true);
        $session->set('logged_in_via', $session->get('totp_pending_via') ?? 'auth');
        $session->remove('totp_pending_admin_id');
        $session->remove('totp_pending_via');

        $this->container->auth()->establish($admin);

        $session->set('timeOfLastAction', time());
        $admin->setLastLogin(new \DateTime());
        $this->em()->flush();

        $target = self::applicationPathOrDefault($session->get('postAuthRedirect'), '');
        if ($target !== '') {
            $session->remove('postAuthRedirect');
        }

        return $this->redirect($target);
    }

    private function abandonPendingLogin(MagicPropertyStorage $session): void
    {
        $session->remove('totp_pending_admin_id');
        $session->remove('totp_pending_via');
        $session->remove('totp_setup_secret');
        $session->remove('totp_verified');
        $session->remove('postAuthRedirect');
    }

    private static function adminIsActive(\Entities\Admin $admin): bool
    {
        return $admin->getActive() === true;
    }

    /** The entity may have changed while a successful TOTP enrolment ran. */
    private function pendingAdminIsStillActive(\Entities\Admin $admin): bool
    {
        return self::adminIsActive($admin);
    }

    /** The TOTP code form. CSRF-guarded (also gated by the pending-session id). */
    private function buildTotpForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('code', 'Authentication code', 'text', [Validators::string(), Validators::required()]));

        return $form;
    }

    /**
     * The mailbox self-service change-password form. CSRF-guarded (also gated by
     * the current password). Username + current + new + confirm (must match new).
     */
    private function buildChangePasswordForm(int $minPw): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Email address', 'text', [Validators::string(), Validators::required(), Validators::email()]))
             ->add(new Field('current_password', 'Current password', 'password', [Validators::string(), Validators::required()]))
             ->add(new Field('new_password', 'New password', 'password', [Validators::string(), Validators::required(), Validators::minLength($minPw)]))
             ->add(new Field('confirm_new_password', 'Confirm new password', 'password', [
                 Validators::string(),
                 Validators::required(),
                 Validators::matches(static fn() => $_POST['new_password'] ?? null, 'The passwords do not match.'),
             ]));

        return $form;
    }

    /**
     * Complete a verified login, enforcing the 2FA gate first.
     */
    /** @param array<string,mixed> $options */
    private function completeLogin(\Entities\Admin $admin, \ViMbAdmin_BruteForce $bf, array $options): Response
    {
        $tfa     = new \ViMbAdmin_TwoFactor('ViMbAdmin', self::optionString($options, '', 'securitysalt'));
        $session = new MagicPropertyStorage($this->session());

        // Every password authentication demands a fresh second factor: drop any
        // stale `totp_verified` (e.g. left in a shared-browser session by a prior
        // 2FA login) BEFORE the gate below reads it, so it can never bypass 2FA.
        $session->remove('totp_verified');

        // Lost-device recovery without DB surgery: application.ini
        // `twofactor.force_disable = "user@dom"` (or "*" for everyone) wipes the
        // matching admin's 2FA (secret + backup codes + replay state) and clears
        // any forced-enrolment flag at login, so they get back in. Remove the
        // setting again once recovered.
        $forceDisable = trim(self::optionString($options, '', 'twofactor', 'force_disable'));
        if ($forceDisable !== ''
            && ($forceDisable === '*' || strcasecmp($forceDisable, $admin->requiredUsername()) === 0)) {
            $tfa->disable($admin);
            $tfa->clearForce($admin);
            $this->em()->flush();
        }

        // 2FA gate: an enabled (or force-enrolled) admin is parked and sent to
        // the native TOTP flow (totpAction/totpSetupAction) — the identity is NOT
        // granted here.
        //
        // Demo exception: the public demo account skips the second-factor CHECK
        // at login (visitors can't supply its TOTP). Enrolment is NOT disabled —
        // a real admin can still set up/verify 2FA; we just don't park the demo
        // login behind it.
        $isDemo = \ViMbAdmin_Demo::isLocked($options, $admin->requiredUsername());

        if (!$isDemo && $tfa->isEnabled($admin) && !$session->get('totp_verified')) {
            $session->set('totp_pending_admin_id', $admin->getId());
            $session->set('totp_pending_via', 'auth');
            return $this->redirect('auth/totp');
        }

        if (!$isDemo && $tfa->isForced($admin) && !$tfa->isEnabled($admin) && !$session->get('totp_verified')) {
            $session->set('totp_pending_admin_id', $admin->getId());
            $session->set('totp_pending_via', 'auth');
            return $this->redirect('auth/totp-setup');
        }

        // Clearing the failed-attempt state can still fail on a late storage
        // or lock fault. Keep that boundary before session regeneration and
        // identity establishment so an error cannot leave an authenticated
        // session behind.
        $bf->clear($admin->getUsername(), null);

        // Session-fixation defence: fresh id on successful authentication.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Grant the identity through the native Auth service. SessionNamespace
        // retains the compatible identity slot used across native requests.
        $this->container->auth()->establish($admin);

        $session->set('logged_in_via', 'auth');

        $admin->setLastLogin(new \DateTime());
        $this->em()->flush();

        return $this->redirect('');
    }

    /**
     * The brute-force gate, built exactly as AuthController::_bruteForce() does.
     *
     * With no $scope this is the login budget: the state directory, the
     * attempt ceiling and every other [bruteforce] setting are used exactly as
     * configured. That default path must stay byte-identical, because existing
     * lockout state on disk lives under that directory.
     *
     * A $scope appends one fixed subdirectory segment to the resolved state
     * directory, giving that caller a private counter namespace that can never
     * spend (or be spent by) the login budget, and overrides the ceiling with
     * $maxAttempts. Window, lockout, prefix widths, whitelist and the
     * trusted-proxy policy are deliberately shared.
     *
     * @param array<string,mixed> $options
     * @param ?non-empty-string   $scope       fixed namespace segment, or null for the login budget
     * @param ?int                $maxAttempts ceiling for the scoped budget (ignored when $scope is null)
     */
    private function bruteForce(
        array $options,
        ?string $scope = null,
        ?int $maxAttempts = null,
    ): \ViMbAdmin_BruteForce {
        $opts = self::optionArray($options, [], 'bruteforce');
        $stateDir = self::optionNullableString($opts, 'statedir');
        if ($stateDir === null || $stateDir === '') {
            $appPath = defined('APPLICATION_PATH') ? APPLICATION_PATH : '';
            $opts['statedir'] = $appPath . '/../var/bruteforce';
        } else {
            $opts['statedir'] = $stateDir;
        }
        if ($scope !== null) {
            // The segment is a compile-time constant chosen by the caller, not
            // request data; rtrim keeps the join single-slashed either way.
            $opts['statedir'] = rtrim($opts['statedir'], '/') . '/' . $scope;
            if ($maxAttempts !== null) {
                $opts['max_attempts'] = $maxAttempts;
            }
        }
        if (isset($options['trustedproxy'])) {
            $opts['trustedproxy'] = self::optionArray($options, [], 'trustedproxy');
        }

        return new \ViMbAdmin_BruteForce($this->em(), $opts);
    }

    protected function em(): \Doctrine\ORM\EntityManager
    {
        $em = parent::em();
        if (!$em instanceof \Doctrine\ORM\EntityManager) {
            throw new \LogicException('Doctrine entity manager resource has an invalid type');
        }

        return $em;
    }

    private function adminRepository(): \Repositories\Admin
    {
        $repo = $this->em()->getRepository('\\Entities\\Admin');
        if (!$repo instanceof \Repositories\Admin) {
            throw new \LogicException('Admin repository has an invalid type');
        }

        return $repo;
    }

    private function mailboxRepository(): \Repositories\Mailbox
    {
        $repo = $this->em()->getRepository('\\Entities\\Mailbox');
        if (!$repo instanceof \Repositories\Mailbox) {
            throw new \LogicException('Mailbox repository has an invalid type');
        }

        return $repo;
    }

    /**
     * Resolve the configurable password-reset entity while preserving the
     * Admin contract required by the reset flow and email templates.
     *
     * @param array<string,mixed> $options
     * @return class-string<\Entities\Admin>
     */
    private function authEntityClass(array $options): string
    {
        $entityClass = self::option($options, 'resources', 'auth', 'oss', 'entity') ?? \Entities\Admin::class;
        if (!is_string($entityClass) || !is_a($entityClass, \Entities\Admin::class, true)) {
            throw new \LogicException('Authentication entity must extend Entities\\Admin');
        }

        return $entityClass;
    }

    /** The login form. CSRF-guarded (login-CSRF defence; the GET mints the token). */
    private function buildLoginForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Username', 'text', [Validators::string(), Validators::required()]))
             ->add(new Field('password', 'Password', 'password', [Validators::string(), Validators::required()]));

        return $form;
    }

    /**
     * The first-run setup form: the security salt (the first-run gate), the new
     * super admin's username (email) and password. CSRF-guarded (the GET that
     * renders it mints the token in the fresh session). Username uniqueness is not
     * needed — the action only runs when the admin table is empty.
     */
    private function buildSetupForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $form->add(new Field('salt', 'Security salt', 'text', [Validators::string(), Validators::required()]))
             ->add(new Field('username', 'Username (email)', 'text', [Validators::string(), Validators::required(), Validators::adminEmail()]))
             ->add(new Field('password', 'Password', 'password', [Validators::string(), Validators::required(), Validators::minLength(6)]));

        return $form;
    }

    /**
     * The lost-password form. Username (required — NOT email-validated, matching
     * the ZF1 nonemail username element, since an admin username need not be an
     * email). When the captcha is enabled it adds a `captchatext` field whose rule
     * validates the typed text against the SUBMITTED `captchaid` via
     * `OSS_Captcha_Image::_isValid()` (so a mismatch shows inline), plus the two
     * hidden fields the refresh widget needs. CSRF-guarded (also gated by captcha
     * + the angie rate-limit on `/auth/forgot`).
     */
    private function buildLostPasswordForm(bool $useCaptcha): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Username', 'text', [Validators::string(), Validators::required()]));

        if ($useCaptcha) {
            $form->add(new Field('captchatext', 'Verification', 'text', [
                Validators::required(),
                Validators::string(),
                static function (mixed $value): ?string {
                    $captchaId = $_POST['captchaid'] ?? null;
                    if (!is_string($captchaId) || !is_string($value)) {
                        return 'The entered text does not match that of the image.';
                    }

                    return \OSS_Captcha_Image::_isValid($captchaId, $value)
                        ? null
                        : 'The entered text does not match that of the image.';
                },
            ]))
                 ->add(new Field('captchaid', '', 'hidden', [Validators::string()]))
                 ->add(new Field('requestnewimage', '', 'hidden', [Validators::string()]));
        }

        return $form;
    }

    /**
     * Stamp the current captcha id onto the hidden fields and render the
     * lost-password page (captcha image + refresh wiring live in the view).
     */
    private function renderLostPassword(Form $form, bool $useCaptcha): Response
    {
        // Mint the captcha here, at the single site that actually renders the
        // page, so no request leaves behind a session entry for an image that
        // was never shown. Validation (above) still checks the captcha id the
        // user SAW (submitted), not this freshly minted one.
        $captchaId = $useCaptcha ? (new \OSS_Captcha_Image(0, 0))->generate() : null;

        if ($useCaptcha) {
            $form->field('captchaid')?->setValue((string) $captchaId);
            $form->field('requestnewimage')?->setValue('0');
        }

        return $this->view('auth/native-lost-password.phtml', [
            'formHtml'   => (new FormRenderer())->render($form, '/auth/lost-password', 'Reset Password'),
            'useCaptcha' => $useCaptcha,
            'captchaId'  => $captchaId,
        ]);
    }

    /**
     * The reset-password form: username + 40-char token + new password + confirm
     * (must match). Username/password are required only (matching the lax ZF1
     * elements — admin usernames need not be emails, and the original element set
     * no real minimum); the token is shape-checked to the `OSS_String::random(40)`
     * alphabet. CSRF-guarded (possession of the emailed token is the primary secret).
     */
    private function buildResetPasswordForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Email address', 'text', [Validators::string(), Validators::required()]))
             ->add(new Field('token', 'Token', 'text', [
                 Validators::string(),
                 Validators::required(),
                 Validators::regex('/^[A-Za-z0-9]{40}$/', 'Invalid token.'),
             ]))
             ->add(new Field('password', 'New password', 'password', [Validators::string(), Validators::required()]))
             ->add(new Field('password_confirm', 'Confirm new password', 'password', [
                 Validators::string(),
                 Validators::required(),
                 Validators::matches(static fn() => $_POST['password'] ?? null, 'The passwords do not match.'),
             ]));

        return $form;
    }

    /**
     * Render an auth email body template and send it through the native mailer,
     * honouring `resources.auth.oss.email_format` (html | plaintext | both —
     * default both, falling back to whichever template renders). Mirrors
     * `OSS_Controller_Trait_Auth::resolveTemplate()` + the From/To/Subject the
     * legacy actions set (`identity.mailer.*`, the admin email + formatted name).
     *
     * @param array<string,mixed> $vars extra template variables (e.g. the token)
     */
    private function sendAuthEmail(string $template, string $subject, \Entities\Admin $user, array $vars): void
    {
        $options = $this->container->options();

        $email = (new Email())
            ->from(new Address(
                self::optionString($options, 'do-not-reply@localhost', 'identity', 'mailer', 'email'),
                self::optionString($options, '', 'identity', 'mailer', 'name'),
            ))
            ->to(new Address($user->getEmail(), $user->getFormattedName()))
            ->subject($subject);

        $vars += ['user' => $user, 'options' => $options];
        $format = self::optionString($options, 'both', 'resources', 'auth', 'oss', 'email_format');

        $haveBody = false;
        if ($format === 'html' || $format === 'both') {
            $html = $this->tryRenderEmail("auth/email/html/{$template}.phtml", $vars);
            if ($html !== null) {
                $email->html($html);
                $haveBody = true;
            }
        }
        if ($format === 'plaintext' || $format === 'both') {
            $text = $this->tryRenderEmail("auth/email/plaintext/{$template}.txt", $vars);
            if ($text !== null) {
                $email->text($text);
                $haveBody = true;
            }
        }

        if (!$haveBody) {
            throw new \RuntimeException("Cannot render '{$template}' email body — no html or plaintext template found");
        }

        $this->mailer()->send($email);
    }

    /**
     * Render an email template to a string, or null if it does not exist (so the
     * caller can try the other format). The Smarty engine throws on a missing
     * template; that is the "absent" signal.
     *
     * @param array<string,mixed> $vars
     */
    private function tryRenderEmail(string $script, array $vars): ?string
    {
        try {
            return $this->renderPartial($script, $vars);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
