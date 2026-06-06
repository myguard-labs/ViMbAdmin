<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

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
 * SECURITY: this re-uses the SAME vetted primitives the ZF1 path used rather
 * than re-implementing any of them — password verification
 * ({@see \OSS_Auth_Password::verify}), the brute-force gate
 * ({@see \ViMbAdmin_BruteForce}), and the two-factor gate
 * ({@see \ViMbAdmin_TwoFactor}). The order and semantics mirror
 * `OSS_Controller_Trait_Auth::loginAction` + `AuthController::_postLoginChecks`:
 *
 *   1. already authenticated → bounce home;
 *   2. zero admins → first-run setup (still ZF1);
 *   3. brute-force: refuse a locked source (429 + exit), count this attempt;
 *   4. verify the credentials; a miss increments the admin's failed-login
 *      counter exactly as the ZF1 adapter did;
 *   5. on success, BEFORE granting a session: the 2FA gate — an enabled or
 *      force-enrolled admin is parked (`totp_pending_admin_id`) and redirected to
 *      the ZF1 `auth/totp` / `auth/totp-setup` flow, so 2FA is never bypassed;
 *   6. otherwise regenerate the session id (fixation defence), grant the identity
 *      via {@see \ViMbAdmin\Kernel\Security\Auth::establish()} (which writes the
 *      same legacy identity slot, so any remaining ZF1 page reads it too), clear
 *      the brute-force counter and stamp last-login.
 *
 * Like the ZF1 login this form carries NO CSRF token (it is credential- and
 * brute-force-gated; a CSRF requirement would only add a session-expiry footgun).
 * Remember-me cookies and login-history are intentionally NOT carried over in
 * this first cut (dropping remember-me is a safe reduction); the rest of the auth
 * surface (totp, setup, lost/reset password, change password) stays on ZF1 via
 * the dispatcher fallback.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AuthController extends AbstractController
{
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

        // The salt-not-configured first-run screen has a bespoke view — let ZF1
        // serve it.
        if (strlen($salt) !== 64) {
            return null;
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
     * Complete a verified login, enforcing the 2FA gate first.
     */
    private function completeLogin(object $admin, object $bf, array $options): Response
    {
        $tfa     = new \ViMbAdmin_TwoFactor('ViMbAdmin', (string) ($options['securitysalt'] ?? ''));
        $session = $this->session();

        // 2FA gate: an enabled (or force-enrolled) admin is parked and sent to
        // the ZF1 TOTP flow — the identity is NOT granted here.
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

    /**
     * The login form: username + password (required) + an (ignored) rememberme
     * checkbox. No CSRF, matching the ZF1 login form.
     */
    private function buildLoginForm(): Form
    {
        $form = new Form();
        $form->add(new Field('username', 'Username', 'text', [Validators::required()]))
             ->add(new Field('password', 'Password', 'password', [Validators::required()]))
             ->add(new Field('rememberme', 'Remember me', 'checkbox'));

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
}
