<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\Form\FormRenderer;
use ViMbAdmin\Kernel\Form\Validators;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native port of `DomainController::list` + `ajaxToggleActive` (docs/ZF1-REMOVAL.md)
 * — the post-login landing page and its active toggle.
 *
 * `listAction` reproduces the legacy action: it clears any remembered domain
 * filter, loads the domains the admin manages via the existing
 * `Repositories\Domain::loadForDomainList()` (unless server-side pagination is
 * configured, in which case the table is populated by AJAX and the initial set
 * is empty), and exposes the size-column multiplier the template formats quotas
 * with. `ajaxToggleActive` flips a domain's active flag through the Phase-1
 * framework-free `ViMbAdmin_Service_Domain`, refusing a domain the admin cannot
 * manage — mirroring the ZF1 `loadDomain()` authorisation.
 *
 * The list/toggle actions plus the native add/edit forms are migrated; the
 * remaining CRUD actions (purge/admins/…) stay on ZF1 via the dispatcher
 * fallback. The legacy controller is untouched.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DomainController extends AbstractController
{
    /**
     * GET /domain/list — the domains overview (any authenticated admin).
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // Landing on the full list clears any per-session domain scope.
        unset($this->session()->domain);

        $opts = $this->container->options();

        $paginate = isset($opts['defaults']['server_side']['pagination']['domain']['enable'])
            && $opts['defaults']['server_side']['pagination']['domain']['enable'];

        $vars = [
            'domains' => $paginate
                ? []
                : $this->em()->getRepository('\\Entities\\Domain')->loadForDomainList($admin),
        ];

        // The size column is shown unless explicitly disabled; the template
        // divides by $multiplier, so always expose it when the column is on.
        if (empty($opts['defaults']['list_size']['disabled'])) {
            $key = (isset($opts['defaults']['list_size']['multiplier'])
                && isset(\OSS_Filter_FileSize::$SIZE_MULTIPLIERS[$opts['defaults']['list_size']['multiplier']]))
                ? $opts['defaults']['list_size']['multiplier']
                : \OSS_Filter_FileSize::SIZE_KILOBYTES;

            $vars['size_multiplier'] = $key;
            $vars['multiplier']      = \OSS_Filter_FileSize::$SIZE_MULTIPLIERS[$key];
        }

        return $this->view('domain/list.phtml', $vars);
    }

    /**
     * GET|POST /domain/add — create a new domain (super admins only).
     *
     * Add only: an edit via the `/domain/add/did/N` URL returns null so the ZF1
     * controller still serves that legacy alias; the linked edit URL
     * (`/domain/edit/did/N`) is served natively by {@see editAction}. The legacy
     * domain add fires no plugin hooks (no `domain_add_*` listeners exist), so
     * nothing is lost by serving it natively. Quota fields are converted to bytes
     * with the SAME OSS_Filter_FileSize the ZF1 form used.
     */
    public function addAction(): ?Response
    {
        if ($this->param('did')) {
            return null; // edit → ZF1 fallback
        }

        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();
        $mult    = $options['defaults']['quota']['multiplier'] ?? \OSS_Filter_FileSize::SIZE_KILOBYTES;
        $form    = $this->buildDomainAddForm($options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $filter = new \OSS_Filter_FileSize($mult);

            $domain = new \Entities\Domain();
            $domain->setAliasCount(0);
            $domain->setMailboxCount(0);
            $domain->setCreated(new \DateTime());
            $domain->setDomain((string) $v['domain']);
            $this->applyFormFields($domain, $v, $filter);

            (new \ViMbAdmin_Service_Domain($this->em()))->save($domain, $admin, false);

            $this->flash('You have successfully added the domain record.');
            return $this->redirect('domain/list');
        }

        return $this->view('domain/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/domain/add', 'Add Domain'),
        ]);
    }

    /**
     * GET|POST /domain/edit/did/<id> — edit an existing domain (super admins
     * only). This is the URL the domain-list edit button links to (the ZF1
     * `editAction` simply forwards to `add`).
     *
     * A missing/invalid `did` returns null so the ZF1 controller still serves it
     * (it flashes "Invalid or non-existent domain." and redirects). The domain
     * name is read-only on edit, so its value is never re-assigned. The quota
     * fields are PREPOPULATED as human strings via `OSS_Filter_FileSize::unfilter()`
     * — the same reverse conversion the ZF1 form did at render time (its FileSize
     * filter detects the `render()` call-stack and unfilters bytes → "512MB") —
     * and re-parsed to bytes on submit by the identical forward filter. domain/add
     * and domain/edit fire no plugin hooks, so nothing is lost serving natively.
     */
    public function editAction(): ?Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $domain = ($did = $this->param('did'))
            ? $this->em()->getRepository('\\Entities\\Domain')->find((int) $did)
            : null;

        if ($domain === null) {
            return null; // invalid/missing → ZF1 fallback (flash + redirect)
        }

        $options = $this->container->options();
        $mult    = $options['defaults']['quota']['multiplier'] ?? \OSS_Filter_FileSize::SIZE_KILOBYTES;
        $form    = $this->buildDomainEditForm();

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $filter = new \OSS_Filter_FileSize($mult);

            // The domain name is read-only on edit — keep the entity's value.
            $this->applyFormFields($domain, $v, $filter);
            $domain->setModified(new \DateTime());

            (new \ViMbAdmin_Service_Domain($this->em()))->save($domain, $admin, true);

            $this->flash('You have successfully edited the domain record.');
            return $this->redirect('domain/list');
        }

        // First render (GET) seeds the form from the entity; an invalid POST
        // re-renders with the submitted values + errors instead.
        if (!$this->isPost()) {
            $this->populateDomainForm($form, $domain);
        }

        return $this->view('domain/native-add.phtml', [
            'pageTitle' => 'Edit Domain: ' . $domain->getDomain(),
            'formHtml'  => (new FormRenderer())->render(
                $form,
                '/domain/edit/did/' . $domain->getId(),
                'Save'
            ),
        ]);
    }

    /**
     * Map the validated form values onto a domain entity (the fields common to
     * add and edit; the domain name and add-only counters are set by the caller).
     */
    private function applyFormFields(\Entities\Domain $domain, array $v, \OSS_Filter_FileSize $filter): void
    {
        $domain->setDescription((string) $v['description']);
        $domain->setTransport((string) $v['transport']);
        $domain->setBackupmx($v['backupmx'] ? 1 : 0);
        $domain->setActive($v['active'] ? 1 : 0);
        $domain->setMaxAliases((int) $v['max_aliases']);
        $domain->setMaxMailboxes((int) $v['max_mailboxes']);
        $domain->setQuota((int) $filter->filter((string) $v['quota']));
        $domain->setMaxQuota((int) $filter->filter((string) $v['max_quota']));
    }

    /**
     * The native edit-domain form: same fields as add, but the domain name is
     * read-only (cannot be renamed) and carries no uniqueness rule, mirroring the
     * ZF1 edit which sets `readonly`, `setRequired(false)` and drops the
     * uniqueness validator.
     */
    private function buildDomainEditForm(): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add((new Field('domain', 'Domain', 'text'))->setReadonly());
        $form->add(new Field('description', 'Description', 'textarea'));
        $form->add(new Field('transport', 'Transport', 'text', [Validators::required()]));
        $form->add(new Field('backupmx', 'Backup MX', 'checkbox'));
        $form->add(new Field('active', 'Active', 'checkbox'));
        $form->add(new Field('max_aliases', 'Max aliases', 'text', [Validators::regex('/^\d+$/', 'Must be a number.')]));
        $form->add(new Field('max_mailboxes', 'Max mailboxes', 'text', [Validators::regex('/^\d+$/', 'Must be a number.')]));
        $form->add(new Field('max_quota', 'Max quota', 'text'));
        $form->add(new Field('quota', 'Quota', 'text'));

        return $form;
    }

    /**
     * Seed the edit form from the entity. Quota fields are unfiltered to human
     * strings ("512MB"), matching what the ZF1 render path displayed.
     */
    private function populateDomainForm(Form $form, \Entities\Domain $domain): void
    {
        $form->field('domain')->setValue($domain->getDomain());
        $form->field('description')->setValue($domain->getDescription());
        $form->field('transport')->setValue($domain->getTransport());
        $form->field('backupmx')->setValue((bool) $domain->getBackupmx());
        $form->field('active')->setValue((bool) $domain->getActive());
        $form->field('max_aliases')->setValue((string) $domain->getMaxAliases());
        $form->field('max_mailboxes')->setValue((string) $domain->getMaxMailboxes());
        $form->field('max_quota')->setValue((string) \OSS_Filter_FileSize::unfilter((int) $domain->getMaxQuota()));
        $form->field('quota')->setValue((string) \OSS_Filter_FileSize::unfilter((int) $domain->getQuota()));
    }

    /**
     * The native add-domain form. Numeric/transport defaults come from
     * `defaults.domain.*`; the domain name is required, format-checked and
     * uniqueness-checked against the database (the rule closes over the EM).
     */
    private function buildDomainAddForm(array $options): Form
    {
        $em        = $this->em();
        $unique    = static function (mixed $value) use ($em): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $existing = $em->getRepository('\\Entities\\Domain')->findOneBy(['domain' => (string) $value]);
            return $existing !== null ? 'A domain with that name already exists.' : null;
        };

        $d = $options['defaults']['domain'] ?? [];

        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add((new Field('domain', 'Domain', 'text', [
                Validators::required(),
                Validators::regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', 'Please enter a valid domain name.'),
                $unique,
            ])));
        $form->add(new Field('description', 'Description', 'textarea'));
        $form->add($this->defaulted(new Field('transport', 'Transport', 'text', [Validators::required()]), $d['transport'] ?? 'virtual'));
        $form->add(new Field('backupmx', 'Backup MX', 'checkbox'));
        $form->add($this->checkedByDefault(new Field('active', 'Active', 'checkbox')));
        $form->add($this->defaulted(new Field('max_aliases', 'Max aliases', 'text', [Validators::regex('/^\d+$/', 'Must be a number.')]), (string) ($d['aliases'] ?? 0)));
        $form->add($this->defaulted(new Field('max_mailboxes', 'Max mailboxes', 'text', [Validators::regex('/^\d+$/', 'Must be a number.')]), (string) ($d['mailboxes'] ?? 0)));
        $form->add($this->defaulted(new Field('max_quota', 'Max quota', 'text'), (string) ($d['maxquota'] ?? 0)));
        $form->add($this->defaulted(new Field('quota', 'Quota', 'text'), (string) ($d['quota'] ?? 0)));

        return $form;
    }

    private function defaulted(Field $field, string $value): Field
    {
        $field->setValue($value);
        return $field;
    }

    private function checkedByDefault(Field $field): Field
    {
        $field->setValue(true);
        return $field;
    }

    /**
     * GET /domain/ajax-toggle-active/did/<id> — flip a domain's active flag.
     */
    public function ajaxToggleActiveAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $domain = ($did = $this->param('did'))
            ? $this->em()->getRepository('\\Entities\\Domain')->find((int) $did)
            : null;

        // loadDomain() authorises a non-super admin against the domain.
        if (!$domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return new Response('ko');
        }

        (new \ViMbAdmin_Service_Domain($this->em()))->toggleActive($domain, $admin);

        return new Response('ok');
    }
}
