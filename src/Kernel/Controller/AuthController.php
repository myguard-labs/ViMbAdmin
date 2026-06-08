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
 * SECURITY: this re-uses the same vetted password and 2FA primitives rather
 * than re-implementing any of them — password verification
 * ({@see \OSS_Auth_Password::verify}), the brute-force gate
 * ({@see \ViMbAdmin_BruteForce}), and the two-factor gate
 * ({@see \ViMbAdmin_TwoFactor}). The order and semantics mirror
 * `OSS_Controller_Trait_Auth::loginAction` + `AuthController::_postLoginChecks`:
 *
 *   1. already authenticated → bounce home;
 *   2. zero admins → first-run setup;
 *   3. brute-force: refuse a locked source (429 + exit), count this attempt;
 *   4. verify the credentials; a miss increments the admin's failed-login
 *      counter exactly as the ZF1 adapter did;
 *   5. on success, BEFORE granting a session: the 2FA gate — an enabled or
 *      force-enrolled admin is parked (`totp_pending_admin_id`) and redirected to
 *      the native `auth/totp` / `auth/totp-setup` flow, so 2FA is never bypassed;
 *   6. otherwise regenerate the session id (fixation defence), grant the identity
 *      via {@see \ViMbAdmin\Kernel\Security\Auth::establish()} (which writes the
 *      native identity slot), clear
 *      the brute-force counter and stamp last-login.
 *
 * Like the ZF1 login this form carries NO CSRF token (it is credential- and
 * brute-force-gated; a CSRF requirement would only add a session-expiry footgun).
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
    public function captchaImageAction(): Response
    {
        $path = \OSS_Captcha_Image::path((string) $this->param('id', ''));
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

        if ((int) $this->em()->getRepository('\\Entities\\Admin')->getCount() === 0) {
            return $this->redirect('auth/setup');
        }

        $options = $this->container->options();
        $bf      = $this->bruteForce($options);

        // Refuse a locked-out source (sends 429 + exits), then count this POST.
        $bf->assertNotLocked(null);

        $form = $this->buildLoginForm();

        if ($this->isPost()) {
            $post = $this->postData();
            $bf->record((string) ($post['username'] ?? ''), null);

            if ($form->isValid($post)) {
                $values   = $form->values();
                $username = (string) $values['username'];
                $admin    = $this->em()->getRepository('\\Entities\\Admin')->findOneBy(['username' => $username]);
                $authOpts = $options['resources']['auth']['oss'];

                if ($admin !== null && \OSS_Auth_Password::verify((string) $values['password'], $admin->getPassword(), $authOpts)) {
                    return $this->completeLogin($admin, $bf, $options);
                }

                // Credential miss: mirror the ZF1 adapter's failed-login count.
                if ($admin !== null && method_exists($admin, 'setFailedLogins')) {
                    $admin->setFailedLogins($admin->getFailedLogins() + 1);
                    $this->em()->flush();
                }

                $this->flash('Invalid username or password. Please try again.', FlashMessages::ERROR);
            }
        }

        return $this->view('auth/native-login.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/login', 'Log In'),
        ]);
    }

    /**
     * GET|POST /auth/setup — first-run: create the initial super administrator.
     *
     * Faithful port of the ZF1 `setupAction` for the common case (a configured
     * 64-char `securitysalt`). Guards: it only runs when there are zero admins and
     * nobody is logged in (else it flashes and bounces, as ZF1 did). The
     * security-salt-not-yet-configured screen (`saltSet=false`, which presents
     * generated salts to paste into `application.ini`) is a rare brand-new-install
     * path with a bespoke view — this returns null for it so the ZF1 action still
     * renders it (the dispatcher fallback).
     *
     * With the salt configured, the submitted `salt` must match the configured
     * `securitysalt` (the first-run gate, exactly as ZF1) before the first admin is
     * created super + active, the Doctrine migration row is seeded, and the user is
     * sent to the login page. There is no logged-in actor on a first run, so —
     * unlike the authenticated add path — this writes no Log row and does not go
     * through `Service_Admin::create`. The welcome email is dropped (no mailer in
     * the native kernel, consistent with the native login).
     */
    public function setupAction(): ?Response
    {
        if ((int) $this->em()->getRepository('\\Entities\\Admin')->getCount() !== 0) {
            $this->flash('Admins already exist in the system.', FlashMessages::INFO);
            return $this->redirect('auth/login');
        }

        if ($this->admin() !== null) {
            $this->flash('You are already logged in.', FlashMessages::INFO);
            return $this->redirect('');
        }

        $options = $this->container->options();
        $salt    = (string) ($options['securitysalt'] ?? '');

        // Salt not configured yet (fresh install before the [user] section is
        // filled in): render the first-run "set your security salts" screen
        // natively, generating the same three salts the ZF1 screen offered.
        if (strlen($salt) !== 64) {
            return $this->view('auth/native-setup-salt.phtml', [
                'randomSalt'   => \OSS_String::salt(64),
                'rememberSalt' => \OSS_String::salt(64),
                'passwordSalt' => \OSS_String::salt(64),
            ]);
        }

        $form = $this->buildSetupForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $values = $form->values();

            if (!hash_equals($salt, (string) $values['salt'])) {
                $this->flash('Incorrect security salt provided. Please copy and paste it from the application.ini file.', FlashMessages::INFO);
                return $this->redirect('auth/login');
            }

            $admin = new \Entities\Admin();
            $admin->setUsername((string) $values['username']);
            $admin->setPassword(
                \OSS_Auth_Password::hash((string) $values['password'], $options['resources']['auth']['oss'])
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
     * (robthree/twofactorauth + libsodium), so there is no ZF1 dependency. No CSRF
     * (pre-auth, gated by the unforgeable pending-session id — same rationale as
     * the login form). A wrong code is counted against the brute-force gate.
     */
    public function totpAction(): Response
    {
        if ($this->admin() !== null) {
            return $this->redirect('');
        }

        $session   = $this->session();
        $pendingId = $session->totp_pending_admin_id ?? null;
        if (!$pendingId) {
            return $this->redirect('auth/login');
        }

        $admin = $this->em()->getRepository('\\Entities\\Admin')->find((int) $pendingId);
        if (!$admin) {
            unset($session->totp_pending_admin_id);
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();

        if ($this->isPost()) {
            $tfa  = new \ViMbAdmin_TwoFactor('ViMbAdmin', (string) ($options['securitysalt'] ?? ''));
            $code = trim((string) ($this->postData()['code'] ?? ''));
            $bf   = $this->bruteForce($options);

            if ($tfa->verifyForAdmin($admin, $code) || $tfa->consumeBackupCode($admin, $code)) {
                $bf->clear($admin->getUsername(), null);
                return $this->grantPendingLogin($admin, $session);
            }

            $bf->record($admin->getUsername(), null);
            $this->em()->flush();
            $this->flash('Invalid authentication code. Please try again.', FlashMessages::ERROR);
        }

        return $this->view('auth/native-totp.phtml', [
            'formHtml' => (new FormRenderer())->render($this->buildTotpForm(), '/auth/totp', 'Verify'),
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

        $session   = $this->session();
        $pendingId = $session->totp_pending_admin_id ?? null;
        if (!$pendingId) {
            return $this->redirect('auth/login');
        }

        $admin = $this->em()->getRepository('\\Entities\\Admin')->find((int) $pendingId);
        if (!$admin) {
            unset($session->totp_pending_admin_id);
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();

        if (\ViMbAdmin_Demo::isLocked($options, $admin->getUsername())) {
            unset($session->totp_pending_admin_id);
            $this->flash('Two-factor enrolment is disabled for the demo account.', FlashMessages::INFO);
            return $this->redirect('auth/login');
        }

        $tfa = new \ViMbAdmin_TwoFactor('ViMbAdmin', (string) ($options['securitysalt'] ?? ''));

        $secret = $session->totp_setup_secret ?? null;
        if (!$secret) {
            $secret = $tfa->createSecret();
            $session->totp_setup_secret = $secret;
        }

        if ($this->isPost() && trim((string) ($this->postData()['code'] ?? '')) !== '') {
            if ($tfa->verifyCode($secret, trim((string) $this->postData()['code']))) {
                $backup = $tfa->enable($admin, $secret);
                $tfa->clearForce($admin);
                $this->em()->flush();
                unset($session->totp_setup_secret);

                $this->bruteForce($options)->clear($admin->getUsername(), null);
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
            'qrDataUri' => $tfa->getQrDataUri($admin->getUsername(), $secret),
        ]);
    }

    /**
     * GET|POST /auth/change-password — mailbox-owner self-service password change.
     *
     * Faithful port of the ZF1 `changePasswordAction`: a public (pre-auth) form
     * where a mailbox owner supplies their address + current password + a new one.
     * The current password is verified against the MAILBOX password (not an admin)
     * with the configured mailbox scheme, and on success the new password is hashed
     * + stored — all via the already-framework-free {@see \OSS_Auth_Password}
     * (PHP-native dovecot hashing), so no ZF1. The demo account is refused. No CSRF
     * (pre-auth, credential-gated, like the login form). A wrong username or
     * current password gives the same generic "Invalid username or password" as
     * ZF1 (no user enumeration).
     */
    public function changePasswordAction(): Response
    {
        $options = $this->container->options();

        if ($this->isPost() && \ViMbAdmin_Demo::isLocked($options, (string) ($this->postData()['username'] ?? ''))) {
            $this->flash('Password changes are disabled for the demo account.', FlashMessages::ERROR);
            return $this->redirect('auth/change-password');
        }

        $minPw = (int) ($options['defaults']['mailbox']['min_password_length'] ?? 8);
        $form  = $this->buildChangePasswordForm($minPw);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v       = $form->values();
            $mailbox = $this->em()->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $v['username']]);

            $pwOpts = [
                'pwhash'   => $options['defaults']['mailbox']['password_scheme'] ?? null,
                'pwsalt'   => $options['defaults']['mailbox']['password_salt'] ?? null,
                'username' => (string) $v['username'],
            ];

            if ($mailbox !== null
                && \OSS_Auth_Password::verify((string) $v['current_password'], $mailbox->getPassword(), $pwOpts)) {
                $mailbox->setPassword(\OSS_Auth_Password::hash((string) $v['new_password'], $pwOpts));
                $this->em()->flush();
                $this->flash('You have successfully changed your password.');
                return $this->redirect('auth/change-password');
            }

            // Generic message — do not reveal whether the username exists.
            $this->flash('Invalid username or password.', FlashMessages::ERROR);
        }

        return $this->view('auth/native-change-password.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/auth/change-password', 'Change Password'),
        ]);
    }

    /**
     * GET /auth/logout — drop the identity and the session, then back to login.
     */
    public function logoutAction(): Response
    {
        $this->container->auth()->clear();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
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
        $useCaptcha  = !empty($options['resources']['auth']['oss']['lost_password']['use_captcha']);
        $entityClass = $options['resources']['auth']['oss']['entity'] ?? '\\Entities\\Admin';

        $form = $this->buildLostPasswordForm($useCaptcha);
        $form->field('username')?->setValue((string) $this->param('username', ''));

        // A fresh captcha for THIS render. Validation (below) checks the captcha
        // id the user actually SAW (submitted), not this freshly minted one —
        // mirroring ZF1, which also re-generates on every action invocation.
        $captchaId = $useCaptcha ? (new \OSS_Captcha_Image(0, 0))->generate() : null;

        if ($this->isPost()) {
            $post = $this->postData();

            // "click image for a new one": re-render with a fresh captcha, keep
            // the typed username, do NOT validate yet.
            if ($useCaptcha && !empty($post['requestnewimage'])) {
                $form->field('username')?->setValue((string) ($post['username'] ?? ''));
                return $this->renderLostPassword($form, $useCaptcha, $captchaId);
            }

            if ($form->isValid($post)) {
                $username = (string) $form->values()['username'];
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
                    ($options['identity']['sitename'] ?? '') . ' - Password Reset Information',
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

        return $this->renderLostPassword($form, $useCaptcha, $captchaId);
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
        $entityClass = $options['resources']['auth']['oss']['entity'] ?? '\\Entities\\Admin';
        $form        = $this->buildResetPasswordForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v    = $form->values();
            $user = $this->em()->getRepository($entityClass)->findOneBy(['username' => $v['username']]);

            if ($user === null) {
                $this->flash('Invalid username / token combination. Please check your details and try again.', FlashMessages::ERROR);
            } else {
                if ($user->cleanExpiredPreferences()) {
                    $this->em()->flush();
                }

                $tokens = $user->getIndexedPreference('tokens.password_reset');

                if (!is_array($tokens) || !in_array($v['token'], $tokens)) {
                    $this->flash('Invalid username / token combination. Please check your details and try again.', FlashMessages::ERROR);
                } else {
                    $user->setPassword(\OSS_Auth_Password::hash((string) $v['password'], $options['resources']['auth']['oss']));
                    $user->deletePreference('tokens.password_reset');

                    if (method_exists($user, 'setFailedLogins')) {
                        $user->setFailedLogins(0);
                    }

                    $this->em()->flush();

                    $this->sendAuthEmail(
                        'reset-password',
                        ($options['identity']['sitename'] ?? '') . ' - Your Password Has Been Reset',
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
            $form->field('username')?->setValue((string) $this->param('username', ''));
            $form->field('token')?->setValue((string) $this->param('token', ''));
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
    private function grantPendingLogin(object $admin, object $session): Response
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $session->totp_verified = true;
        $session->logged_in_via = $session->totp_pending_via ?? 'auth';
        unset($session->totp_pending_admin_id);
        unset($session->totp_pending_via);

        $this->container->auth()->establish($admin);

        $session->timeOfLastAction = time();
        $admin->setLastLogin(new \DateTime());
        $this->em()->flush();

        $target = $session->postAuthRedirect ?? '';
        if ($target !== '') {
            unset($session->postAuthRedirect);
        }

        return $this->redirect($target);
    }

    /** The TOTP code form. CSRF-guarded (also gated by the pending-session id). */
    private function buildTotpForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('code', 'Authentication code', 'text', [Validators::required()]));

        return $form;
    }

    /**
     * The mailbox self-service change-password form. CSRF-guarded (also gated by
     * the current password). Username + current + new + confirm (must match new).
     */
    private function buildChangePasswordForm(int $minPw): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Email address', 'text', [Validators::required(), Validators::email()]))
             ->add(new Field('current_password', 'Current password', 'password', [Validators::required()]))
             ->add(new Field('new_password', 'New password', 'password', [Validators::required(), Validators::minLength($minPw)]))
             ->add(new Field('confirm_new_password', 'Confirm new password', 'password', [
                 Validators::required(),
                 Validators::matches(static fn() => $_POST['new_password'] ?? null, 'The passwords do not match.'),
             ]));

        return $form;
    }

    /**
     * Complete a verified login, enforcing the 2FA gate first.
     */
    private function completeLogin(object $admin, object $bf, array $options): Response
    {
        $tfa     = new \ViMbAdmin_TwoFactor('ViMbAdmin', (string) ($options['securitysalt'] ?? ''));
        $session = $this->session();

        // 2FA gate: an enabled (or force-enrolled) admin is parked and sent to
        // the native TOTP flow (totpAction/totpSetupAction) — the identity is NOT
        // granted here.
        if ($tfa->isEnabled($admin) && !$session->totp_verified) {
            $session->totp_pending_admin_id = $admin->getId();
            $session->totp_pending_via      = 'auth';
            return $this->redirect('auth/totp');
        }

        if ($tfa->isForced($admin) && !$tfa->isEnabled($admin) && !$session->totp_verified) {
            $session->totp_pending_admin_id = $admin->getId();
            $session->totp_pending_via      = 'auth';
            return $this->redirect('auth/totp-setup');
        }

        // Session-fixation defence: fresh id on successful authentication.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Grant the identity. The Auth service writes it to the same legacy
        // identity slot the framework auth layer used, so any remaining ZF1 page
        // and the native kernel both read it.
        $this->container->auth()->establish($admin);

        $session->logged_in_via = 'auth';

        $bf->clear($admin->getUsername(), null);
        $admin->setLastLogin(new \DateTime());
        $this->em()->flush();

        return $this->redirect('');
    }

    /**
     * The brute-force gate, built exactly as AuthController::_bruteForce() does.
     */
    private function bruteForce(array $options): object
    {
        $opts = $options['bruteforce'] ?? [];
        if (empty($opts['statedir'])) {
            $opts['statedir'] = APPLICATION_PATH . '/../var/bruteforce';
        }
        if (isset($options['trustedproxy'])) {
            $opts['trustedproxy'] = $options['trustedproxy'];
        }

        return new \ViMbAdmin_BruteForce($this->em(), $opts);
    }

    /** The login form. CSRF-guarded (login-CSRF defence; the GET mints the token). */
    private function buildLoginForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add(new Field('username', 'Username', 'text', [Validators::required()]))
             ->add(new Field('password', 'Password', 'password', [Validators::required()]));

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

        $form->add(new Field('salt', 'Security salt', 'text', [Validators::required()]))
             ->add(new Field('username', 'Username (email)', 'text', [Validators::required(), Validators::email()]))
             ->add(new Field('password', 'Password', 'password', [Validators::required(), Validators::minLength(6)]));

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
        $form->add(new Field('username', 'Username', 'text', [Validators::required()]));

        if ($useCaptcha) {
            $form->add(new Field('captchatext', 'Verification', 'text', [
                Validators::required(),
                static fn(mixed $v): ?string =>
                    \OSS_Captcha_Image::_isValid((string) ($_POST['captchaid'] ?? ''), (string) $v)
                        ? null
                        : 'The entered text does not match that of the image.',
            ]))
                 ->add(new Field('captchaid', '', 'hidden'))
                 ->add(new Field('requestnewimage', '', 'hidden'));
        }

        return $form;
    }

    /**
     * Stamp the current captcha id onto the hidden fields and render the
     * lost-password page (captcha image + refresh wiring live in the view).
     */
    private function renderLostPassword(Form $form, bool $useCaptcha, ?string $captchaId): Response
    {
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
        $form->add(new Field('username', 'Email address', 'text', [Validators::required()]))
             ->add(new Field('token', 'Token', 'text', [
                 Validators::required(),
                 Validators::regex('/^[A-Za-z0-9]{40}$/', 'Invalid token.'),
             ]))
             ->add(new Field('password', 'New password', 'password', [Validators::required()]))
             ->add(new Field('password_confirm', 'Confirm new password', 'password', [
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
    private function sendAuthEmail(string $template, string $subject, object $user, array $vars): void
    {
        $options = $this->container->options();

        $email = (new Email())
            ->from(new Address(
                (string) ($options['identity']['mailer']['email'] ?? 'do-not-reply@localhost'),
                (string) ($options['identity']['mailer']['name'] ?? '')
            ))
            ->to(new Address((string) $user->getEmail(), (string) $user->getFormattedName()))
            ->subject($subject);

        $vars += ['user' => $user, 'options' => $options];
        $format = $options['resources']['auth']['oss']['email_format'] ?? 'both';

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
