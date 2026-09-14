<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Admin;
use LogicException;
use Repositories\Admin as AdminRepository;
use Repositories\Domain as DomainRepository;
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
 * Native port of `AdminController::list` (docs/ZF1-REMOVAL.md) — a super-admin
 * read-only list page whose template carries CSRF-guarded action links.
 *
 * `listAction` is the super-only administrator overview: it reproduces the ZF1
 * `preDispatch` super-admin gate (`authorise(true)`) and renders all admins
 * through `admin/list.phtml`. The template's state-changing links (purge, …)
 * carry the per-session CSRF token, which {@see AbstractController::view()} now
 * seeds over the shared session key read by the native action handlers.
 *
 * Migrated: list, add, the ajax toggles, purge, password, domains,
 * remove-domain, assign-domain and two-factor management.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AdminController extends AbstractController
{
    private static function requiredString(mixed $value, string $name): string
    {
        return \ViMbAdmin\Kernel\Input\Reader::requiredString($value, $name);
    }

    private static function postStringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function sessionSecret(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function checkboxBoolean(mixed $value, string $name): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }
        throw new LogicException("{$name} must be boolean");
    }

    private static function positiveIntegerOrNull(mixed $value): ?int
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

    private function positiveIdParam(string $key): ?int
    {
        return self::positiveIntegerOrNull($this->param($key));
    }

    /** @return array<string,mixed> */
    private static function stringKeyedArray(mixed $value, string $name): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::stringKeyedArray($value, $name);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{bool,mixed}
     */
    private static function option(array $options, string ...$path): array
    {
        return \ViMbAdmin\Kernel\Input\Reader::option($options, ...$path);
    }

    /** @param array<string,mixed> $options */
    private static function optionString(array $options, string $default, string ...$path): string
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function requiredOptionArray(array $options, string ...$path): array
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            throw new LogicException('Configuration ' . implode('.', $path) . ' is required');
        }

        return self::stringKeyedArray($value, 'Configuration ' . implode('.', $path));
    }

    /**
     * GET /admin and /admin/index — the auth-gated landing forwards to the list
     * (the native equivalent of the ZF1 indexAction `_forward('list')`).
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('admin/list')
            : $this->redirect('auth/login');
    }
    /**
     * GET /admin/list — the administrator overview (super admins only).
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            // ZF1 preDispatch authorise(true) redirects a non-super to login.
            return $this->redirect('auth/login');
        }

        $admins = $this->adminRepository()->findAll();

        return $this->view('admin/list.phtml', ['admins' => $admins]);
    }

    /**
     * POST /admin/purge — permanently delete an admin.
     *
     * The full state-changing path natively: CSRF-guarded in the POST body (the
     * form the native admin/list mints carries the session token as a hidden
     * field, so the token never lands in a URL), super-only, refuses a missing
     * target or self-purge with a flashed error, otherwise purges via the
     * framework-free ViMbAdmin_Service_Admin and flashes success — each followed
     * by a redirect to admin/list, where the {OSS_Message} renderer shows the
     * flash. Mirrors the legacy AdminController::purgeAction.
     */
    public function purgeAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if (!$target) {
            $this->flash('Invalid or non-existent admin.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        if ($admin->getId() == $target->getId()) {
            $this->flash('You cannot purge yourself.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->purge($target, $admin);

        $this->flash('You have successfully purged the admin record.', FlashMessages::SUCCESS);
        return $this->redirect('admin/list');
    }

    /**
     * GET|POST /admin/add — create a new administrator (super admins only).
     *
     * The first native form (docs/ZF1-REMOVAL.md): GET renders the native form,
     * POST validates it (CSRF + fields) and, when valid, creates the admin via
     * the framework-free ViMbAdmin_Service_Admin, flashes success and redirects
     * to admin/list; an invalid POST re-renders with errors and repopulated
     * values. (The legacy welcome-email option is not carried over in this first
     * cut.)
     */
    public function addAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $form = $this->buildAddForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $values = $form->values();

            (new \ViMbAdmin_Service_Admin($this->em()))->create(
                self::requiredString($values['username'] ?? null, 'Admin username'),
                self::requiredString($values['password'] ?? null, 'Admin password'),
                self::checkboxBoolean($values['super'] ?? null, 'Super administrator flag'),
                $admin,
                self::requiredOptionArray($this->container->options(), 'resources', 'auth', 'oss')
            );

            $this->flash('You have successfully added a new administrator to the system.');
            return $this->redirect('admin/list');
        }

        return $this->view('admin/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/admin/add', 'Add Administrator'),
        ]);
    }

    /**
     * GET|POST /admin/two-factor — the logged-in admin's own 2FA settings.
     *
     * Self-service enrolment / management via the framework-free
     * {@see \ViMbAdmin_TwoFactor}: `enable` (verify a code against the stashed
     * enrolment secret → store secret + reveal one-time backup codes), `disable`
     * and `regen-backup` (each require a valid current code or backup code). The
     * enrolment QR + secret are shown while 2FA is off. CSRF is a hidden POST
     * field (read from the body, not the URL).
     */
    public function twoFactorAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $tfa     = new \ViMbAdmin_TwoFactor('ViMbAdmin', self::optionString($this->container->options(), '', 'securitysalt'));
        $session = new MagicPropertyStorage($this->session());

        if ($this->isPost() && $this->postCsrfValid()) {
            $op   = self::postStringOrEmpty($this->postData()['op'] ?? null);
            $code = trim(self::postStringOrEmpty($this->postData()['code'] ?? null));

            if ($op === 'enable' && !$tfa->isEnabled($admin)) {
                $secret = self::sessionSecret($session->get('totp_enrol_secret'));
                if ($secret && $tfa->verifyCode($secret, $code)) {
                    $backup = $tfa->enable($admin, $secret);
                    $this->em()->flush();
                    $session->remove('totp_enrol_secret');
                    return $this->view('admin/two-factor.phtml', [
                        'justEnabled'     => true,
                        'backupCodes'     => $backup,
                        'enabled'         => true,
                        'backupRemaining' => $tfa->backupCodesRemaining($admin),
                    ]);
                }
                $this->flash('That code did not verify. Scan the QR and try again.', FlashMessages::ERROR);
            } elseif ($op === 'disable' && $tfa->isEnabled($admin)) {
                if ($tfa->verifyForAdmin($admin, $code) || $tfa->consumeBackupCode($admin, $code)) {
                    $tfa->disable($admin);
                    $this->em()->flush();
                    $this->flash('Two-factor authentication has been disabled.');
                    return $this->redirect('admin/two-factor');
                }
                $this->flash('A valid current code is required to disable 2FA.', FlashMessages::ERROR);
            } elseif ($op === 'regen-backup' && $tfa->isEnabled($admin)) {
                if ($tfa->verifyForAdmin($admin, $code)) {
                    $backup = $tfa->regenerateBackupCodes($admin);
                    $this->em()->flush();
                    return $this->view('admin/two-factor.phtml', [
                        'backupCodes'     => $backup,
                        'enabled'         => true,
                        'backupRemaining' => $tfa->backupCodesRemaining($admin),
                    ]);
                }
                $this->flash('A valid current code is required to regenerate backup codes.', FlashMessages::ERROR);
            }
        }

        $enabled = $tfa->isEnabled($admin);
        $vars    = ['enabled' => $enabled, 'backupRemaining' => $tfa->backupCodesRemaining($admin)];

        if (!$enabled) {
            $secret = self::sessionSecret($session->get('totp_enrol_secret'));
            if ($secret === null) {
                $secret = $tfa->createSecret();
                $session->set('totp_enrol_secret', $secret);
            }
            $vars['secret']    = $secret;
            $vars['qrDataUri'] = $tfa->getQrDataUri($admin->requiredUsername(), $secret);
        }

        return $this->view('admin/two-factor.phtml', $vars);
    }

    /**
     * GET|POST /admin/manage-two-factor/aid/<id> — a super admin manages ANOTHER
     * admin's 2FA. `provision` / `regen-secret` mint a secret and reveal the QR +
     * one-time backup codes; `disable` clears it; `force-on` / `force-off` toggle
     * the next-login enrolment requirement. Super-only; managing oneself redirects
     * to the self-service page. CSRF is a hidden POST field.
     */
    public function manageTwoFactorAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;
        if (!$target) {
            return $this->redirect('admin/list');
        }
        if ($target->getId() === $admin->getId()) {
            return $this->redirect('admin/two-factor');
        }

        $tfa = new \ViMbAdmin_TwoFactor('ViMbAdmin', self::optionString($this->container->options(), '', 'securitysalt'));

        if ($this->isPost() && $this->postCsrfValid()) {
            $op = self::postStringOrEmpty($this->postData()['op'] ?? null);

            if (($op === 'provision') || ($op === 'regen-secret' && $tfa->isEnabled($target))) {
                $res = $tfa->provision($target);
                $this->em()->flush();
                return $this->view('admin/manage-two-factor.phtml', [
                    'target'          => $target,
                    'enabled'         => true,
                    'forced'          => $tfa->isForced($target),
                    'backupRemaining' => $tfa->backupCodesRemaining($target),
                    'revealSecret'    => $res['secret'],
                    'backupCodes'     => $res['backup'],
                    'qrDataUri'       => $tfa->getQrDataUri($target->requiredUsername(), $res['secret']),
                ]);
            }

            if ($op === 'disable') {
                $tfa->disable($target);
                $this->em()->flush();
                $this->flash(sprintf('2FA disabled for %s.', $target->getUsername()));
                return $this->redirect('admin/manage-two-factor/aid/' . $target->getId());
            }
            if ($op === 'force-on') {
                $tfa->setForce($target, true);
                $this->em()->flush();
                $this->flash(sprintf('%s will be required to set up 2FA at next login.', $target->getUsername()));
                return $this->redirect('admin/manage-two-factor/aid/' . $target->getId());
            }
            if ($op === 'force-off') {
                $tfa->setForce($target, false);
                $this->em()->flush();
                $this->flash(sprintf('Enrolment requirement cleared for %s.', $target->getUsername()));
                return $this->redirect('admin/manage-two-factor/aid/' . $target->getId());
            }
        }

        return $this->view('admin/manage-two-factor.phtml', [
            'target'          => $target,
            'enabled'         => $tfa->isEnabled($target),
            'forced'          => $tfa->isForced($target),
            'backupRemaining' => $tfa->backupCodesRemaining($target),
        ]);
    }

    /**
     * Whether the request carries a valid CSRF token in the POST body (`csrf`
     * field). The 2FA forms POST the token as a hidden input, so the base
     * {@see csrfValid()} (which reads the route params) does not see it.
     */
    private function postCsrfValid(): bool
    {
        return (new Csrf(new MagicPropertyStorage($this->container->session())))
            ->isValid(self::postStringOrEmpty($this->postData()['csrf'] ?? null));
    }

    /**
     * The native add-admin form: username (email) + password + super flag,
     * CSRF-guarded over the session.
     */
    private function buildAddForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $form->add(new Field('username', 'Username (email)', 'text', [Validators::required(), Validators::adminEmail()]))
             ->add(new Field('password', 'Password', 'password', [Validators::required(), Validators::minLength(6)]))
             ->add(new Field('super', 'Super administrator', 'checkbox'));

        return $form;
    }

    /**
     * GET|POST /admin/password/aid/<id> — change an administrator's password.
     *
     * Faithful port of the ZF1 `passwordAction`, preserving every gate in order:
     * the target admin must exist (else flash + redirect), the demo account is
     * locked, and the caller must be a super-admin OR the target themselves. A
     * SELF change uses the ChangePassword form (current-password verified before
     * the change); a super changing SOMEONE ELSE uses the Password form (no
     * current-password, the change is logged). The mutation + log + flush run
     * through the framework-free ViMbAdmin_Service_Admin::changePassword.
     *
     * Differences from ZF1, both deliberate: the optional "email the new password"
     * side-feature is dropped (the native kernel has no mailer, as with the
     * native login's remember-me), and the insufficient-privilege attempt is not
     * written to the logger (the security behaviour — refuse + redirect — is
     * preserved). An invalid/missing aid is handled natively here (flash +
     * redirect), so the action never falls through to ZF1.
     */
    public function passwordAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $redirectUrl = $admin->isSuper() ? 'admin/list' : 'domain/list';

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or non-existent admin.', FlashMessages::ERROR);
            return $this->redirect($redirectUrl);
        }

        // The demo account's password is fixed (advertised on the login page);
        // nobody — not even a super-admin — may change it.
        if (\ViMbAdmin_Demo::isLocked($this->container->options(), $target->getUsername())) {
            $this->flash('Password changes are disabled for the demo account.', FlashMessages::ERROR);
            return $this->redirect($redirectUrl);
        }

        $self = $target->getId() === $admin->getId();

        // Non-super admins may only change their own password.
        if (!$self && !$admin->isSuper()) {
            $this->flash('You have insufficient privileges for this task.', FlashMessages::ERROR);
            return $this->redirect($redirectUrl);
        }

        $authOptions = self::requiredOptionArray($this->container->options(), 'resources', 'auth', 'oss');
        $form        = $this->buildPasswordForm($self, $target, $authOptions);

        if ($this->isPost() && $form->isValid($this->postData())) {
            (new \ViMbAdmin_Service_Admin($this->em()))->changePassword(
                $target,
                self::requiredString($form->values()['password'] ?? null, 'Admin password'),
                $admin,
                $self,
                $authOptions
            );

            $this->flash($self
                ? 'You have successfully changed your password.'
                : "You have successfully changed the user's password.");

            return $this->redirect($redirectUrl);
        }

        return $this->view('admin/native-password.phtml', [
            'targetAdmin' => $target,
            'formHtml'    => (new FormRenderer())->render(
                $form,
                '/admin/password/aid/' . $target->getId(),
                'Change Password'
            ),
        ]);
    }

    /**
     * The native change-password form. A self-change requires the current
     * password (verified as a field rule against the stored hash, so a wrong one
     * re-renders with an inline error exactly like the ZF1 form) plus a new
     * password and a matching confirmation. A super changing another admin only
     * supplies the new password (shown as text, as the ZF1 Password form does).
     * Both enforce the 8-char minimum the ZF1 validators set.
     *
     * @param array<string,mixed> $authOptions the `resources.auth.oss` config OSS_Auth_Password needs
     */
    private function buildPasswordForm(bool $self, \Entities\Admin $target, array $authOptions): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        if ($self) {
            $verify = static function (mixed $value) use ($target, $authOptions): ?string {
                if ($value === null || $value === '') {
                    return null; // required() reports the empty case
                }

                return self::adminPasswordMatches($target, self::requiredString($value, 'Current password'), $authOptions)
                    ? null
                    : 'Invalid password.';
            };

            $form->add(new Field('current_password', 'Current password', 'password', [
                Validators::required(), Validators::minLength(8), $verify,
            ]));
            $form->add(new Field('password', 'New password', 'password', [
                Validators::required(), Validators::minLength(8),
            ]));
            $form->add(new Field('confirm_password', 'Confirm new password', 'password', [
                Validators::required(),
                Validators::matches(
                    static fn () => $form->field('password')?->value(),
                    'The confirmation password is required and must match the new password'
                ),
            ]));
        } else {
            $form->add(new Field('password', 'New password', 'text', [
                Validators::required(), Validators::minLength(8),
            ]));
        }

        return $form;
    }

    /** @param array<string, mixed> $options */
    private static function adminPasswordMatches(\Entities\Admin $admin, string $plain, array $options): bool
    {
        $hash = $admin->getPassword();
        return $hash !== null && \OSS_Auth_Password::verify($plain, $hash, $options);
    }

    /**
     * GET /admin/domains/aid/<id> — the domains assigned to an admin (super only).
     *
     * Reuses the existing `admin/domains.phtml` template byte-for-byte (it loops
     * `$targetAdmin->getDomains()` and renders the assign/remove links); only the
     * super gate + target lookup are reproduced natively. ZF1's `preDispatch`
     * requires super-admin for every AdminController action except password/
     * two-factor, so the gate is unconditional here.
     */
    public function domainsAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or non-existent admin.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        return $this->view('admin/domains.phtml', ['targetAdmin' => $target]);
    }

    /**
     * POST /admin/remove-domain — unassign a domain from an admin
     * (super only). Faithful port: a missing admin or domain flashes + redirects
     * (to admin/list and the admin's domains page respectively); otherwise the
     * detach + log + flush run through the Phase-1 ViMbAdmin_Service_Admin::
     * removeDomain. The confirmation form carries the ids and CSRF token in its
     * POST body, so a cross-site GET cannot reach either repository lookup.
     */
    public function removeDomainAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            return $this->redirect('admin/list');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or missing admin id.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or missing domain id.', FlashMessages::ERROR);
            return $this->redirect('admin/domains/aid/' . $target->getId());
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->removeDomain($target, $domain, $admin);

        $this->flash('You have successfully removed the admin from domain ' . $domain->requiredDomainName());
        return $this->redirect('admin/domains/aid/' . $target->getId());
    }

    /**
     * GET|POST /admin/assign-domain/aid/<id> — assign a domain to an admin (super
     * only). The select offers only the domains NOT already assigned
     * (`Repositories\Domain::getNotAssignedForAdmin`), and an in-array rule
     * rejects any value that was not offered — the framework-free equivalent of
     * the ZF1 form's register-in-array validator, so a forged domain id cannot be
     * assigned. On a valid POST the assignment runs through the Phase-1
     * ViMbAdmin_Service_Admin::assignDomain (whose duplicate guard is surfaced as
     * an error flash), then redirects to the admin's domains page. When there are
     * no domains left to assign, an info flash is shown on the (empty) form.
     */
    public function assignDomainAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or missing admin id.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $remaining = $this->domainRepository()->getNotAssignedForAdmin($target);
        $form      = $this->buildAssignDomainForm($remaining);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $domainId = self::positiveIntegerOrNull($form->values()['domain'] ?? null);
            $domain = $domainId === null ? null : $this->domainRepository()->find($domainId);

            if ($domain !== null) {
                try {
                    (new \ViMbAdmin_Service_Admin($this->em()))->assignDomain($target, $domain, $admin);
                    $this->flash('You have successfully assigned a domain to the admin.');
                } catch (\ViMbAdmin_Service_Exception $e) {
                    $this->flash($e->getMessage(), FlashMessages::ERROR);
                }
            }

            return $this->redirect('admin/domains/aid/' . $target->getId());
        }

        if (count($remaining) === 0) {
            $this->flash('There are no domains to assign to this administrator.', FlashMessages::INFO);
        }

        return $this->view('admin/native-assign-domain.phtml', [
            'targetAdmin' => $target,
            'formHtml'    => (new FormRenderer())->render(
                $form,
                '/admin/assign-domain/aid/' . $target->getId(),
                'Save'
            ),
        ]);
    }

    /**
     * The native assign-domain form: a single select of the domains not yet
     * assigned to the admin, required and in-array validated against that exact
     * list, CSRF-guarded over the session.
     *
     * @param array<int|string,string> $remaining domain id → name (incl. "(inactive)")
     */
    private function buildAssignDomainForm(array $remaining): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add((new Field('domain', 'Domain', 'select', [
            Validators::required(),
            Validators::inArray($remaining),
        ]))->setOptions($remaining));

        return $form;
    }

    /**
     * POST /admin/ajax-toggle-active — flip an admin's active flag.
     * Mirrors the ZF1 action: prints "ko" when the target is missing or is the
     * caller themselves, otherwise toggles via the framework-free
     * ViMbAdmin_Service_Admin and prints "ok". The JS sends the session CSRF
     * token in the POST body; failures retain the bare "ko" contract.
     */
    public function ajaxToggleActiveAction(): Response
    {
        return $this->toggle('toggleActive');
    }

    /**
     * POST /admin/ajax-toggle-super — flip an admin's super flag.
     */
    public function ajaxToggleSuperAction(): Response
    {
        return $this->toggle('toggleSuper');
    }

    /**
     * Shared body of the two ajax toggles: super gate, resolve the target admin
     * from `aid`, refuse a missing target or self-toggle, then call the named
     * ViMbAdmin_Service_Admin mutator (which owns its log write + flush).
     */
    private function toggle(string $method): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        if (!$this->postBodyCsrfValid()) {
            return new Response('ko');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if (!$target || $admin->getId() == $target->getId()) {
            return new Response('ko');
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->{$method}($target, $admin);

        return new Response('ok');
    }

    protected function em(): EntityManagerInterface
    {
        $em = parent::em();
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('Doctrine entity manager resource has an invalid type');
        }

        return $em;
    }

    protected function admin(): ?Admin
    {
        $admin = parent::admin();
        if ($admin !== null && !$admin instanceof Admin) {
            throw new LogicException('Authenticated admin has an invalid type');
        }

        return $admin;
    }

    private function adminRepository(): AdminRepository
    {
        $repo = $this->em()->getRepository('\\Entities\\Admin');
        if (!$repo instanceof AdminRepository) {
            throw new LogicException('Admin repository has an invalid type');
        }

        return $repo;
    }

    private function domainRepository(): DomainRepository
    {
        $repo = $this->em()->getRepository('\\Entities\\Domain');
        if (!$repo instanceof DomainRepository) {
            throw new LogicException('Domain repository has an invalid type');
        }

        return $repo;
    }
}
