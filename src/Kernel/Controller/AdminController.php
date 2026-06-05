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
 * Native port of `AdminController::list` (docs/ZF1-REMOVAL.md) — a super-admin
 * read-only list page whose template carries CSRF-guarded action links.
 *
 * `listAction` is the super-only administrator overview: it reproduces the ZF1
 * `preDispatch` super-admin gate (`authorise(true)`) and renders all admins
 * through `admin/list.phtml`. The template's state-changing links (purge, …)
 * carry the per-session CSRF token, which {@see AbstractController::view()} now
 * seeds over the same session key the ZF1 `_assertCsrf()` reads — so those links
 * keep validating against the legacy actions that still serve them.
 *
 * Migrated: list, add, the ajax toggles, purge, password, domains,
 * remove-domain and assign-domain. The remaining actions (two-factor/cli-*)
 * stay on ZF1 via the dispatcher fallback. The legacy controller is untouched —
 * with VIMBADMIN_NATIVE_KERNEL off ZF1 serves the whole controller unchanged.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AdminController extends AbstractController
{
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

        $admins = $this->em()->getRepository('\\Entities\\Admin')->findAll();

        return $this->view('admin/list.phtml', ['admins' => $admins]);
    }

    /**
     * GET /admin/purge/aid/<id>/csrf/<token> — permanently delete an admin.
     *
     * The full state-changing path natively: CSRF-guarded (the link the native
     * admin/list mints carries the session token), super-only, refuses a missing
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

        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
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
                (string) $values['username'],
                (string) $values['password'],
                (bool) $values['super'],
                $admin,
                $this->container->options()['resources']['auth']['oss']
            );

            $this->flash('You have successfully added a new administrator to the system.');
            return $this->redirect('admin/list');
        }

        return $this->view('admin/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/admin/add', 'Add Administrator'),
        ]);
    }

    /**
     * The native add-admin form: username (email) + password + super flag,
     * CSRF-guarded over the session.
     */
    private function buildAddForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        $form->add(new Field('username', 'Username (email)', 'text', [Validators::required(), Validators::email()]))
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
    public function passwordAction(): ?Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $redirectUrl = $admin->isSuper() ? 'admin/list' : 'domain/list';

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
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

        $authOptions = $this->container->options()['resources']['auth']['oss'];
        $form        = $this->buildPasswordForm($self, $target, $authOptions);

        if ($this->isPost() && $form->isValid($this->postData())) {
            (new \ViMbAdmin_Service_Admin($this->em()))->changePassword(
                $target,
                (string) $form->values()['password'],
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
     * @param array $authOptions the `resources.auth.oss` config OSS_Auth_Password needs
     */
    private function buildPasswordForm(bool $self, \Entities\Admin $target, array $authOptions): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        if ($self) {
            $verify = static function (mixed $value) use ($target, $authOptions): ?string {
                if ($value === null || $value === '') {
                    return null; // required() reports the empty case
                }

                return \OSS_Auth_Password::verify((string) $value, $target->getPassword(), $authOptions)
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
                    static fn() => $form->field('password')?->value(),
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

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or non-existent admin.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        return $this->view('admin/domains.phtml', ['targetAdmin' => $target]);
    }

    /**
     * GET /admin/remove-domain/aid/<id>/did/<id> — unassign a domain from an admin
     * (super only). Faithful port: a missing admin or domain flashes + redirects
     * (to admin/list and the admin's domains page respectively); otherwise the
     * detach + log + flush run through the Phase-1 ViMbAdmin_Service_Admin::
     * removeDomain. Like ZF1 this carries no CSRF token (super-gated GET link).
     */
    public function removeDomainAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or missing admin id.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $domain = ($did = $this->param('did'))
            ? $this->em()->getRepository('\\Entities\\Domain')->find((int) $did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or missing domain id.', FlashMessages::ERROR);
            return $this->redirect('admin/domains/aid/' . $target->getId());
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->removeDomain($target, $domain, $admin);

        $this->flash('You have successfully removed the admin from domain ' . $domain->getDomain());
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

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or missing admin id.', FlashMessages::ERROR);
            return $this->redirect('admin/list');
        }

        $remaining = $this->em()->getRepository('\\Entities\\Domain')->getNotAssignedForAdmin($target);
        $form      = $this->buildAssignDomainForm($remaining);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $domain = $this->em()->getRepository('\\Entities\\Domain')->find((int) $form->values()['domain']);

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
     * GET /admin/ajax-toggle-active/aid/<id> — flip an admin's active flag.
     * Mirrors the ZF1 action: prints "ko" when the target is missing or is the
     * caller themselves, otherwise toggles via the framework-free
     * ViMbAdmin_Service_Admin and prints "ok". Like the ZF1 ajax toggles it
     * carries no CSRF token (it is super-gated and self-toggle is refused); the
     * JS reads the bare ok/ko body.
     */
    public function ajaxToggleActiveAction(): Response
    {
        return $this->toggle('toggleActive');
    }

    /**
     * GET /admin/ajax-toggle-super/aid/<id> — flip an admin's super flag.
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

        $target = ($aid = $this->param('aid'))
            ? $this->em()->getRepository('\\Entities\\Admin')->find((int) $aid)
            : null;

        if (!$target || $admin->getId() == $target->getId()) {
            return new Response('ko');
        }

        (new \ViMbAdmin_Service_Admin($this->em()))->{$method}($target, $admin);

        return new Response('ok');
    }
}
