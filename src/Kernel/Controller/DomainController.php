<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Admin;
use LogicException;
use Repositories\Admin as AdminRepository;
use Repositories\Domain as DomainRepository;
use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;
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
 * The list/toggle actions, the native add/edit forms, and the domain-admin
 * assignment trio (`admins`/`assign-admin`/`remove-admin`) are migrated — the
 * symmetric counterpart of the AdminController domain-assignment trio (#40/#41),
 * over the same already-extracted `ViMbAdmin_Service_Domain` (assignAdmin/
 * removeAdmin), and `purge` (over `Service_Domain::purge`). The remaining actions
 * (index/list-search) stay on ZF1 via the dispatcher fallback. The legacy
 * controller is untouched.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DomainController extends AbstractController
{
    private static function requiredString(mixed $value, string $name): string
    {
        return \ViMbAdmin\Kernel\Input\Reader::requiredString($value, $name);
    }

    private static function stringOrDefault(mixed $value, string $default, string $name): string
    {
        return $value === null ? $default : self::requiredString($value, $name);
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

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private static function requestArray(array $value): array
    {
        $scalarKeys = ['draw', 'start', 'length'];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $scalarKeys, true) && !is_string($item)) {
                throw new LogicException("DataTables parameter {$key} must be a string");
            }
            $result[$key] = $item;
        }

        return $result;
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

    /** @param array<string,mixed> $options */
    /**
     * Column index -> sortable field for the domain list.
     *
     * The rendered column set is NOT fixed: `domain/list.phtml` and its view JS
     * emit the "Used / Max" column only when `defaults.list_size.disabled` is
     * false, so a static index map is off by one for every column after it
     * under the shipped default (`list_size.disabled = true`) -- clicking
     * "Transport" would sort by `active`, and "Created" would fall through to
     * the default. Build the map from the same condition the view uses.
     * Columns that are rendered but not sortable map to the default.
     *
     * @param int  $index      The DataTables `order[0][column]` index.
     * @param bool $sizeColumn Whether the "Used / Max" column is rendered.
     */
    private static function listSortField(int $index, bool $sizeColumn): string
    {
        $columns = array_values(array_filter([
            'domain',                    // Domain
            'mailboxes',                 // Mailboxes
            'aliases',                   // Aliases
            $sizeColumn ? '' : null,     // Used / Max (rendered, not sortable)
            'quota',                     // Default quota
            'active',                    // Active
            'transport',                 // Transport
            '',                          // Backup MX (not sortable)
            'created',                   // Created
            '',                          // controls (not sortable)
        ], static fn(?string $c): bool => $c !== null));

        return ($columns[$index] ?? '') ?: 'domain';
    }

    /** @param array<string,mixed> $options */
    private static function optionBoolean(array $options, bool $default, string ...$path): bool
    {
        [$found, $value] = self::option($options, ...$path);
        if (!$found) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }

        throw new LogicException('Configuration ' . implode('.', $path) . ' must be boolean');
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    private static function optionArray(array $options, array $default, string ...$path): array
    {
        [$found, $value] = self::option($options, ...$path);
        return $found ? self::stringKeyedArray($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    /** @param array<string,mixed> $options */
    private static function quotaMultiplier(array $options): string
    {
        $multiplier = strtoupper(self::optionString(
            $options,
            \OSS_Filter_FileSize::SIZE_KILOBYTES,
            'defaults',
            'quota',
            'multiplier',
        ));
        if (!array_key_exists($multiplier, \OSS_Filter_FileSize::$SIZE_MULTIPLIERS)) {
            throw new LogicException('Configuration defaults.quota.multiplier is unsupported');
        }

        return $multiplier;
    }

    private static function checkboxBoolean(mixed $value, string $name): bool
    {
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        throw new LogicException("{$name} must be boolean");
    }

    private static function nonNegativeInteger(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new LogicException("{$name} must be a non-negative integer");
    }

    private static function nonNegativeIntegerOrDefault(mixed $value, int $default, string $name): int
    {
        return $value === null || $value === ''
            ? $default
            : self::nonNegativeInteger($value, $name);
    }

    private static function quotaBytes(mixed $value, \OSS_Filter_FileSize $filter, string $name): int
    {
        $filtered = $filter->filter(self::requiredString($value, $name));
        if ((!is_int($filtered) && !is_float($filtered))
            || !is_finite((float) $filtered)
            || $filtered < 0
            || $filtered > PHP_INT_MAX) {
            throw new LogicException("{$name} produced an invalid byte value");
        }

        return (int) $filtered;
    }

    private static function nonNegativeIntegerDefault(mixed $value, string $name): string
    {
        return (string) self::nonNegativeInteger($value, $name);
    }

    private static function nonNegativeNumberDefault(mixed $value, string $name): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value) === 1) {
            return $value;
        }

        throw new LogicException("{$name} must be a non-negative number");
    }

    private static function requiredField(Form $form, string $name): Field
    {
        $field = $form->field($name);
        if ($field === null) {
            throw new LogicException("Domain form field {$name} is missing");
        }

        return $field;
    }

    /**
     * @param array<int|string,string|null> $values
     * @return array<int|string,string>
     */
    private static function requiredStringMap(array $values, string $name): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = self::requiredString($value, "{$name} label");
        }

        return $result;
    }

    /**
     * GET /domain and /domain/index — the auth-gated landing forwards to the list
     * (the native equivalent of the ZF1 indexAction `_forward('list')`).
     */
    public function indexAction(): Response
    {
        return $this->admin() !== null
            ? $this->redirect('domain/list')
            : $this->redirect('auth/login');
    }
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

        $paginate = self::optionBoolean($opts, true, 'defaults', 'server_side', 'pagination', 'domain', 'enable');

        $vars = [
            'domains' => $paginate
                ? []
                : $this->domainRepository()->loadForDomainList($admin),
        ];

        // The size column is shown unless explicitly disabled; the template
        // divides by $multiplier, so always expose it when the column is on.
        if (!self::optionBoolean($opts, false, 'defaults', 'list_size', 'disabled')) {
            $configured = self::optionString(
                $opts,
                \OSS_Filter_FileSize::SIZE_KILOBYTES,
                'defaults',
                'list_size',
                'multiplier',
            );
            $key = array_key_exists($configured, \OSS_Filter_FileSize::$SIZE_MULTIPLIERS)
                ? $configured
                : \OSS_Filter_FileSize::SIZE_KILOBYTES;

            $vars['size_multiplier'] = $key;
            $vars['multiplier']      = \OSS_Filter_FileSize::$SIZE_MULTIPLIERS[$key];
        }

        return $this->view('domain/list.phtml', $vars);
    }

    /**
     * GET /domain/list-data — DataTables server-side processing source.
     *
     * One page of the admin's domain list as the DataTables JSON envelope.
     * Active when domain server-side pagination is enabled.
     */
    public function listDataAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return new Response('ko');
        }

        $minimum = $this->dataTableMinimumSearchLength('domain');
        try {
            $request = self::requestArray($_GET);
        } catch (LogicException $e) {
            foreach (['draw', 'start', 'length'] as $key) {
                if (array_key_exists($key, $_GET) && is_array($_GET[$key])) {
                    return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
                }
            }
            throw $e;
        }
        try {
            $q = DataTableQuery::fromArray(
                $request,
                $minimum,
            );
        } catch (\LengthException $e) {
            return new Response($e->getMessage(), 400, 'text/plain; charset=utf-8');
        } catch (\TypeError) {
            return new Response('Invalid DataTables request', 400, 'text/plain; charset=utf-8');
        }
        $sizeColumn = !self::optionBoolean($this->container->options(), false, 'defaults', 'list_size', 'disabled');
        $sortField  = self::listSortField($q->sortColumn, $sizeColumn);

        $r = $this->domainRepository()
            ->pagedForDomainList($admin, $q->searchTerm, $q->contains, $sortField, $q->sortDir, $q->start, $q->length);

        foreach ($r['rows'] as &$row) {
            if (($row['created'] ?? null) instanceof \DateTimeInterface) {
                $row['created'] = $row['created']->format('Y-m-d H:i:s');
            }
        }
        unset($row);

        return new Response(
            DataTableResult::json($q, $r['total'], $r['filtered'], array_values($r['rows'])),
            200,
            'application/json; charset=utf-8'
        );
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
    public function addAction(): Response
    {
        $didValue = $this->param('did');
        if ($didValue !== null && $didValue !== '') {
            $did = self::positiveIntegerOrNull($didValue);
            if ($did === null) {
                $this->flash('Invalid domain id.', FlashMessages::ERROR);
                return $this->redirect('domain/list');
            }
            // The edit form is served natively by editAction; redirect the
            // legacy add-with-did alias there rather than punting to ZF1.
            return $this->redirect('domain/edit/did/' . $did);
        }

        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $options = $this->container->options();
        $mult    = self::quotaMultiplier($options);
        $form    = $this->buildDomainAddForm($options);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $filter = new \OSS_Filter_FileSize($mult);

            $domain = new \Entities\Domain();
            $domain->setAliasCount(0);
            $domain->setMailboxCount(0);
            $domain->setCreated(new \DateTime());
            $domain->setDomain(\ViMbAdmin_Identity::canonical(
                self::requiredString($v['domain'] ?? null, 'Domain name'),
            ));
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
    public function editAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }
        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or non-existent domain.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }

        $options = $this->container->options();
        $mult    = self::quotaMultiplier($options);
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
            'pageTitle' => 'Edit Domain: ' . $domain->requiredDomainName(),
            'formHtml'  => (new FormRenderer())->render(
                $form,
                '/domain/edit/did/' . $domain->requiredId(),
                'Save'
            ),
        ]);
    }

    /**
     * GET /domain/admins/did/<id> — list a domain's (non-super) administrators.
     *
     * Super-only (the ZF1 `authorise(true)`). Reuses the existing
     * `domain/admins.phtml` view byte-for-byte (the template reads
     * `$domain->getAdmins()` directly), so this only resolves + authorises the
     * domain and seeds the `domain` view variable — the symmetric counterpart of
     * the AdminController `domainsAction` (#40, which reused `admin/domains.phtml`).
     */
    public function adminsAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or non-existent domain.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }

        return $this->view('domain/admins.phtml', ['domain' => $domain]);
    }

    /**
     * POST /domain/remove-admin — detach an admin from a domain.
     *
     * Super-only and CSRF-guarded over the POST body, matching the
     * AdminController `removeDomainAction`: a bare GET cannot reach the detach.
     * Detaches via the framework-free
     * `ViMbAdmin_Service_Domain::removeAdmin` (detach + log + flush), then flashes
     * and bounces back to the domain's admins page.
     */
    public function removeAdminAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }
        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the administrators page.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }
        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or missing domain id.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }

        $target = ($aid = $this->positiveIdParam('aid'))
            ? $this->adminRepository()->find($aid)
            : null;

        if ($target === null) {
            $this->flash('Invalid or missing admin id.', FlashMessages::ERROR);
            return $this->redirect('domain/admins/did/' . $domain->requiredId());
        }

        (new \ViMbAdmin_Service_Domain($this->em()))->removeAdmin($domain, $target, $admin);

        $this->flash('You have successfully removed the domain from admin ' . $target->getUsername());
        return $this->redirect('domain/admins/did/' . $domain->requiredId());
    }

    /**
     * GET|POST /domain/assign-admin/did/<id> — assign an admin to a domain.
     *
     * Super-only. The select offers the admins NOT already assigned
     * (`Repositories\Admin::getNotAssignedForDomain`, an id→username map). A valid
     * POST assigns via `ViMbAdmin_Service_Domain::assignAdmin` (which throws a
     * `ViMbAdmin_Service_Exception` on a duplicate, flashed as an error) and
     * redirects to the admins page; an empty remaining set flashes an info notice
     * on the empty form. The symmetric counterpart of the AdminController
     * `assignDomainAction` (#41).
     */
    public function assignAdminAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            $this->flash('Invalid or missing domain id.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }

        $remaining = self::requiredStringMap(
            $this->adminRepository()->getNotAssignedForDomain($domain),
            'Assignable administrator',
        );
        $form      = $this->buildAssignAdminForm($remaining);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $targetId = self::positiveIntegerOrNull($form->values()['admin'] ?? null);
            if ($targetId === null) {
                throw new LogicException('Selected administrator id must be a positive integer');
            }
            $target = $this->adminRepository()->find($targetId);

            if ($target !== null) {
                try {
                    (new \ViMbAdmin_Service_Domain($this->em()))->assignAdmin($domain, $target, $admin);
                    $this->flash('You have successfully assigned a admin to the domain.');
                } catch (\ViMbAdmin_Service_Exception $e) {
                    $this->flash($e->getMessage(), FlashMessages::ERROR);
                }
            }

            return $this->redirect('domain/admins/did/' . $domain->requiredId());
        }

        if (count($remaining) === 0) {
            $this->flash('There are no administrators to assign to this domain.', FlashMessages::INFO);
        }

        return $this->view('domain/native-assign-admin.phtml', [
            'domain'   => $domain,
            'formHtml' => (new FormRenderer())->render(
                $form,
                '/domain/assign-admin/did/' . $domain->requiredId(),
                'Save'
            ),
        ]);
    }

    /**
     * POST /domain/purge — purge a domain and everything in it.
     *
     * Faithful port of the ZF1 `purgeAction`, hardened to a POST: super-only, the
     * CSRF token must arrive in the POST body — an invalid/missing token, or a
     * non-POST, flashes + bounces to the list. The mutation runs through the already-extracted
     * `ViMbAdmin_Service_Domain::purge` (which delegates to the repository purge
     * that cascades the domain's mailboxes/aliases/archives). No plugin listens to
     * the `domain_purge_*` hooks the ZF1 action fires, so they are a no-op and are
     * not replicated here; like the ZF1 action it shows no success flash (the
     * domain simply disappears from the list). Redirects to the domain list.
     *
     * NOTE: this also fixes a latent deployment bug — the `domain/js/list.js` purge
     * link was built without a `csrf` segment, so the ZF1 `_assertCsrf()` always
     * failed and domain purge silently bounced; the purge control is now a POST
     * form carrying the token as a hidden field.
     */
    public function purgeAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null || !$admin->isSuper()) {
            return $this->redirect('auth/login');
        }

        // _assertCsrf(): the token must travel in the POST body, never the URL.
        if (!$this->postBodyCsrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('domain/list');
        }

        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        if ($domain === null) {
            return $this->redirect('domain/list');
        }

        (new \ViMbAdmin_Service_Domain($this->em()))->purge($domain);

        return $this->redirect('domain/list');
    }

    /**
     * The native assign-admin form: a select of the admins not already on the
     * domain (id → username), guarded by `inArray` so a forged id is rejected.
     *
     * @param array<int|string,string> $remaining admin id → username
     */
    private function buildAssignAdminForm(array $remaining): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add((new Field('admin', 'Administrator', 'select', [
            Validators::string(),
            Validators::required(),
            Validators::inArray($remaining),
        ]))->setOptions($remaining));

        return $form;
    }

    /**
     * Map the validated form values onto a domain entity (the fields common to
     * add and edit; the domain name and add-only counters are set by the caller).
     */
    /**
     * @param array<string,mixed> $v
     */
    private function applyFormFields(\Entities\Domain $domain, array $v, \OSS_Filter_FileSize $filter): void
    {
        // Validate the complete request before mutating the managed entity. A
        // malformed late field must not leave an in-memory partial update.
        $description = self::stringOrDefault($v['description'] ?? null, '', 'Domain description');
        $transport = self::requiredString($v['transport'] ?? null, 'Domain transport');
        $backupmx = self::checkboxBoolean($v['backupmx'] ?? false, 'Backup MX');
        $active = self::checkboxBoolean($v['active'] ?? false, 'Active');
        $maxAliases = self::nonNegativeIntegerOrDefault($v['max_aliases'] ?? null, 0, 'Maximum aliases');
        $maxMailboxes = self::nonNegativeIntegerOrDefault($v['max_mailboxes'] ?? null, 0, 'Maximum mailboxes');
        $quota = self::quotaBytes($v['quota'] ?? '', $filter, 'Domain quota');
        $maxQuota = self::quotaBytes($v['max_quota'] ?? '', $filter, 'Maximum mailbox quota');

        $domain->setDescription($description);
        $domain->setTransport($transport);
        $domain->setBackupmx($backupmx);
        $domain->setActive($active);
        $domain->setMaxAliases($maxAliases);
        $domain->setMaxMailboxes($maxMailboxes);
        $domain->setQuota($quota);
        $domain->setMaxQuota($maxQuota);
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
        $form->add(new Field('description', 'Description', 'textarea', [Validators::string(), Validators::noControlChars()]));
        $form->add(new Field('transport', 'Transport', 'text', [Validators::string(), Validators::required(), Validators::noControlChars()]));
        $form->add(new Field('backupmx', 'Backup MX', 'checkbox'));
        $form->add(new Field('active', 'Active', 'checkbox'));
        $form->add(new Field('max_aliases', 'Max aliases', 'text', [Validators::string(), Validators::regex('/^\d+$/', 'Must be a number.')]));
        $form->add(new Field('max_mailboxes', 'Max mailboxes', 'text', [Validators::string(), Validators::regex('/^\d+$/', 'Must be a number.')]));
        $form->add(new Field('max_quota', 'Max quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]));
        $form->add(new Field('quota', 'Quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]));

        return $form;
    }

    /**
     * Seed the edit form from the entity. Quota fields are unfiltered to human
     * strings ("512MB"), matching what the ZF1 render path displayed.
     */
    private function populateDomainForm(Form $form, \Entities\Domain $domain): void
    {
        self::requiredField($form, 'domain')->setValue($domain->requiredDomainName());
        self::requiredField($form, 'description')->setValue($domain->getDescription());
        self::requiredField($form, 'transport')->setValue($domain->getTransport());
        self::requiredField($form, 'backupmx')->setValue($domain->getBackupmx() === true);
        self::requiredField($form, 'active')->setValue($domain->getActive() === true);
        self::requiredField($form, 'max_aliases')->setValue((string) self::nonNegativeInteger($domain->getMaxAliases(), 'Maximum aliases'));
        self::requiredField($form, 'max_mailboxes')->setValue((string) self::nonNegativeInteger($domain->getMaxMailboxes(), 'Maximum mailboxes'));
        self::requiredField($form, 'max_quota')->setValue((string) \OSS_Filter_FileSize::unfilter(
            self::nonNegativeInteger($domain->getMaxQuota(), 'Maximum mailbox quota'),
        ));
        self::requiredField($form, 'quota')->setValue((string) \OSS_Filter_FileSize::unfilter($domain->requiredQuota()));
    }

    /**
     * The native add-domain form. Numeric/transport defaults come from
     * `defaults.domain.*`; the domain name is required, format-checked and
     * uniqueness-checked against the database (the rule closes over the EM).
     */
    /**
     * @param array<string,mixed> $options
     */
    private function buildDomainAddForm(array $options): Form
    {
        $unique = function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $existing = $this->domainRepository()->findOneBy([
                'domain' => \ViMbAdmin_Identity::canonical(
                    self::requiredString($value, 'Domain name'),
                ),
            ]);
            return $existing !== null ? 'A domain with that name already exists.' : null;
        };

        $d = self::optionArray($options, [], 'defaults', 'domain');

        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));
        $form->add((new Field('domain', 'Domain', 'text', [
                Validators::required(),
                Validators::string(),
                Validators::hostname(),
                $unique,
            ])));
        $form->add(new Field('description', 'Description', 'textarea', [Validators::string(), Validators::noControlChars()]));
        $form->add($this->defaulted(
            new Field('transport', 'Transport', 'text', [Validators::string(), Validators::required(), Validators::noControlChars()]),
            self::requiredString(
                array_key_exists('transport', $d) ? $d['transport'] : 'virtual',
                'Configuration defaults.domain.transport',
            ),
        ));
        $form->add(new Field('backupmx', 'Backup MX', 'checkbox'));
        $form->add($this->checkedByDefault(new Field('active', 'Active', 'checkbox')));
        $form->add($this->defaulted(new Field('max_aliases', 'Max aliases', 'text', [Validators::string(), Validators::regex('/^\d+$/', 'Must be a number.')]), self::nonNegativeIntegerDefault(array_key_exists('aliases', $d) ? $d['aliases'] : 0, 'Configuration defaults.domain.aliases')));
        $form->add($this->defaulted(new Field('max_mailboxes', 'Max mailboxes', 'text', [Validators::string(), Validators::regex('/^\d+$/', 'Must be a number.')]), self::nonNegativeIntegerDefault(array_key_exists('mailboxes', $d) ? $d['mailboxes'] : 0, 'Configuration defaults.domain.mailboxes')));
        $form->add($this->defaulted(new Field('max_quota', 'Max quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]), self::nonNegativeNumberDefault(array_key_exists('maxquota', $d) ? $d['maxquota'] : 0, 'Configuration defaults.domain.maxquota')));
        $form->add($this->defaulted(new Field('quota', 'Quota', 'text', [Validators::string(), Validators::nonNegativeNumber()]), self::nonNegativeNumberDefault(array_key_exists('quota', $d) ? $d['quota'] : 0, 'Configuration defaults.domain.quota')));

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
     * POST /domain/ajax-toggle-active — flip a domain's active flag.
     *
     * The token is read from the POST body (`ossToggle` submits it alongside
     * `did`), so the flip is unreachable by a bare GET.
     */
    public function ajaxToggleActiveAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }
        if (!$this->postBodyCsrfValid()) {
            return new Response('ko');
        }

        $domain = ($did = $this->positiveIdParam('did'))
            ? $this->domainRepository()->find($did)
            : null;

        // loadDomain() authorises a non-super admin against the domain.
        if (!$domain || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
            return new Response('ko');
        }

        (new \ViMbAdmin_Service_Domain($this->em()))->toggleActive($domain, $admin);

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
