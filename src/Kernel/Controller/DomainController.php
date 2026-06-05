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
 * Only these two actions are migrated; the form/CRUD actions (add/edit/purge/…)
 * stay on ZF1 via the dispatcher fallback. The legacy controller is untouched.
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
     * Add only: an edit (a `did` in the URL) returns null so the ZF1 controller
     * still serves it (the edit path prepopulates + reverse-converts quotas, not
     * yet ported). The legacy domain add fires no plugin hooks (no `domain_add_*`
     * listeners exist), so nothing is lost by serving it natively. Quota fields
     * are converted to bytes with the SAME OSS_Filter_FileSize the ZF1 form used.
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
            $domain->setDescription((string) $v['description']);
            $domain->setTransport((string) $v['transport']);
            $domain->setBackupmx($v['backupmx'] ? 1 : 0);
            $domain->setActive($v['active'] ? 1 : 0);
            $domain->setMaxAliases((int) $v['max_aliases']);
            $domain->setMaxMailboxes((int) $v['max_mailboxes']);
            $domain->setQuota((int) $filter->filter((string) $v['quota']));
            $domain->setMaxQuota((int) $filter->filter((string) $v['max_quota']));

            (new \ViMbAdmin_Service_Domain($this->em()))->save($domain, $admin, false);

            $this->flash('You have successfully added the domain record.');
            return $this->redirect('domain/list');
        }

        return $this->view('domain/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/domain/add', 'Add Domain'),
        ]);
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
