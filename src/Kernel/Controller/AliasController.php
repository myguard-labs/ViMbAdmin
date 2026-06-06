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
use ViMbAdmin\Kernel\Plugin\AliasContext;
use ViMbAdmin\Kernel\Plugin\PluginHost;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

/**
 * Native port of `AliasController::list` + `ajaxToggleActive`
 * (docs/ZF1-REMOVAL.md).
 *
 * Reproduces the legacy `preDispatch` (for the list actions) + `listAction`: the
 * remembered/`did` domain scope is resolved into the session (`unset` clears
 * it), the `ima` flag ("include mailbox aliases") is read from the request, and
 * the scoped aliases are loaded via the existing
 * `Repositories\Alias::loadForAliasList()` (empty initial set when server-side
 * pagination is configured). Unlike the mailbox list, the alias `listAction`
 * DOES expose the current `domain` view variable, so this port sets it too.
 *
 * `ajaxToggleActive` flips an alias's active flag through the framework-free
 * `ViMbAdmin_Service_Alias` (#31) while firing the same plugin hooks the ZF1
 * action did — via the native {@see PluginHost} + {@see AliasContext} (Phase
 * 4c), so the MailboxAutomaticAliases pre/post-toggle logic runs natively and a
 * pre-toggle veto still aborts the change.
 *
 * `add` ports the create path of the ZF1 `addAction`, and `delete` ports the
 * CSRF-guarded `deleteAction`, both persisting through the extracted
 * `ViMbAdmin_Service_Alias` (#50) with their plugin hooks threaded over the
 * native PluginHost. The `edit` action stays on ZF1 via the dispatcher fallback.
 *
 * The legacy controller is untouched.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AliasController extends AbstractController
{
    /**
     * GET /alias/list[/did/<id>][/ima/<0|1>][/unset/1] — the aliases overview.
     */
    public function listAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // preDispatch domain juggling: the selected domain is remembered in the
        // session and reused until `unset`.
        $session = $this->session();
        $domain  = null;

        if ($this->param('unset', false)) {
            unset($session->domain);
        } elseif (isset($session->domain) && $session->domain) {
            $domain = $session->domain;
        } elseif ($did = $this->param('did')) {
            $domain = $this->em()->getRepository('\\Entities\\Domain')->find((int) $did);
            if ($domain && !$admin->isSuper() && !$admin->canManageDomain($domain)) {
                return $this->redirect('auth/login');
            }
            if ($domain) {
                $session->domain = $domain;
            }
        }

        $ima = (int) $this->param('ima', 0);

        $opts = $this->container->options();

        $paginate = isset($opts['defaults']['server_side']['pagination']['enable'])
            && $opts['defaults']['server_side']['pagination']['enable'];

        $aliases = $paginate
            ? []
            : $this->em()->getRepository('\\Entities\\Alias')->loadForAliasList($admin, $domain, $ima);

        return $this->view('alias/list.phtml', [
            'ima'     => $ima,
            'domain'  => $domain,
            'aliases' => $aliases,
        ]);
    }

    /**
     * GET /alias/ajax-toggle-active/alid/<id> — flip an alias's active flag.
     *
     * Mirrors the ZF1 action: resolve the alias from `alid`, refuse a missing one
     * or a domain the admin cannot manage (`loadAlias` authorisation), then toggle
     * via `ViMbAdmin_Service_Alias` with the plugin pre/pre-flush/post-flush hooks
     * threaded in as callables over the native PluginHost. A pre-toggle veto (any
     * observer returning false) leaves the alias unchanged and yields "ko". Prints
     * the bare ok/ko body the JS reads.
     */
    public function ajaxToggleActiveAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $alias = ($alid = $this->param('alid'))
            ? $this->em()->getRepository('\\Entities\\Alias')->find((int) $alid)
            : null;

        // loadAlias() authorises a non-super admin against the alias's domain.
        if (!$alias || (!$admin->isSuper() && !$admin->canManageDomain($alias->getDomain()))) {
            return new Response('ko');
        }

        $context = new AliasContext(
            $this->em(),
            $admin,
            $alias->getDomain(),
            $alias,
            $this->container->options(),
            new FlashMessages(new MagicPropertyStorage($this->session())),
        );
        $host = new PluginHost($context);

        $result = (new \ViMbAdmin_Service_Alias($this->em()))->toggleActive(
            $alias,
            $admin,
            fn() => $host->notify('alias', 'toggleActive', 'preToggle', $context, ['active' => $alias->getActive()]) === true,
            fn() => $host->notify('alias', 'toggleActive', 'preflush', $context, ['active' => $alias->getActive()]),
            fn() => $host->notify('alias', 'toggleActive', 'postflush', $context, ['active' => $alias->getActive()]),
        );

        return new Response($result === null ? 'ko' : 'ok');
    }

    /**
     * GET|POST /alias/add[/did/<id>] — create an alias.
     *
     * Native port of the create path of the ZF1 `AliasController::addAction`. Edit
     * (`/alias/edit/alid/<id>`, a `forward('add')`) and any `add` URL carrying an
     * `alid` stay on ZF1 via the dispatcher fallback (the `alid` guard returns
     * null).
     *
     * The form is the framework-free {@see Form}: an optional `local_part` (blank
     * makes a catch-all `@domain` alias), a `domain` select scoped to the domains
     * the admin may manage (an `inArray` rule plus a server-side re-check), and a
     * `goto` textarea holding the destination address list (the native, JS-free
     * replacement for the ZF1 `goto[]` multi-input widget — one address per line
     * or comma-separated, each a valid email or a leading-`@` domain wildcard,
     * mirroring the ZF1 `_setGotos`). The ZF1 AdditionalInfo alias subform is
     * dropped here (no native alias FormExtension contract yet — a documented gap,
     * as with the mailbox add).
     *
     * On POST it validates the base form, resolves + authorises the chosen domain,
     * enforces the per-admin alias allowance, parses + validates the goto list,
     * assembles + validates + uniqueness-checks the address (the ZF1 `_setAddress`),
     * then persists through the extracted {@see \ViMbAdmin_Service_Alias::create} —
     * threading the `alias_add_addPostflush` plugin hook over the native
     * {@see PluginHost} so MailboxAutomaticAliases still runs. A failure flashes
     * the reason and re-renders the repopulated form, mirroring the ZF1
     * `addMessage(...) + return`.
     */
    public function addAction(): ?Response
    {
        // Edit (alid in URL) stays on ZF1 via the dispatcher fallback.
        if ($this->param('alid')) {
            return null;
        }

        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        $em      = $this->em();
        $options = $this->container->options();

        // The domains this admin may add an alias to (id => name); super sees all.
        $choices = $em->getRepository('\\Entities\\Domain')->loadForAdminAsArray($admin, true);
        if ($choices === []) {
            $this->flash('There are no domains to which you can add an alias.', FlashMessages::INFO);
            return $this->redirect('domain/list');
        }

        // A preferred domain from `did` preselects the dropdown.
        $preferred = null;
        if ($did = $this->param('did')) {
            $d = $em->getRepository('\\Entities\\Domain')->find((int) $did);
            if ($d !== null && ($admin->isSuper() || $admin->canManageDomain($d))) {
                $preferred = $d;
            }
        }

        $form = $this->buildAliasAddForm($choices, $preferred);

        if ($this->isPost() && $form->isValid($this->postData())) {
            $v      = $form->values();
            $domain = $em->getRepository('\\Entities\\Domain')->find((int) $v['domain']);

            // The inArray rule already rejected a domain not offered; re-check
            // management server-side so a non-super admin cannot widen scope.
            if ($domain === null || (!$admin->isSuper() && !$admin->canManageDomain($domain))) {
                $this->flash('Please select a valid domain.', FlashMessages::ERROR);
            } elseif (!$admin->isSuper() && $domain->getMaxAliases() != 0
                && $domain->getAliasCount() >= $domain->getMaxAliases()) {
                $this->flash('You have used all of your allocated aliases.', FlashMessages::ERROR);
            } else {
                [$gotos, $gErr] = $this->parseGotos((string) ($v['goto'] ?? ''));

                if ($gErr !== null) {
                    $this->flash($gErr, FlashMessages::ERROR);
                } else {
                    // _setAddress: assemble local_part@domain (blank local part is
                    // a catch-all @domain alias, which ZF1 does not email-validate).
                    $localPart = strtolower(trim((string) $v['local_part']));
                    $address   = sprintf('%s@%s', $localPart, $domain->getDomain());

                    if ($localPart !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $this->flash('Invalid email address.', FlashMessages::ERROR);
                    } elseif ($em->getRepository('\\Entities\\Alias')->findOneBy(['address' => $address]) !== null) {
                        $this->flash("Alias already exists for {$address}", FlashMessages::ERROR);
                    } else {
                        $alias = new \Entities\Alias();
                        $alias->setAddress($address);
                        $alias->setGoto(implode(',', $gotos));

                        // Fire the post-flush plugin hook natively (the native
                        // equivalent of the ZF1 `alias_add_addPostflush` notify).
                        $context = new AliasContext(
                            $em,
                            $admin,
                            $domain,
                            $alias,
                            $options,
                            new FlashMessages(new MagicPropertyStorage($this->session())),
                        );
                        $host = new PluginHost($context);

                        (new \ViMbAdmin_Service_Alias($em))->create(
                            $alias,
                            $domain,
                            $admin,
                            null,
                            fn() => $host->notify('alias', 'add', 'addPostflush', $context, ['options' => $options]),
                        );

                        if ($this->param('did')) {
                            $this->session()->domain = $domain;
                        }

                        $this->flash('You have successfully added the alias.');
                        return $this->redirect('alias/list');
                    }
                }
            }
        }

        return $this->view('alias/native-add.phtml', [
            'formHtml' => (new FormRenderer())->render($form, '/alias/add', 'Add Alias'),
        ]);
    }

    /**
     * GET /alias/delete/alid/<id>/csrf/<token> — delete an alias.
     *
     * Faithful port of the ZF1 CSRF-guarded `deleteAction`: the token (carried in
     * the URL, the same one the list's delete link mints) is asserted FIRST — an
     * invalid/missing token flashes + bounces to the list. A missing alias (or a
     * domain the admin cannot manage) redirects to the list. The mutation runs
     * through the extracted {@see \ViMbAdmin_Service_Alias::delete} with the plugin
     * pre-remove/pre-flush/post-flush hooks threaded over the native
     * {@see PluginHost}; a pre-remove veto leaves the alias untouched and
     * suppresses the success flash. Redirects to the alias list.
     */
    public function deleteAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            return $this->redirect('auth/login');
        }

        // _assertCsrf(): the token is carried in the URL on the delete link.
        if (!$this->csrfValid()) {
            $this->flash('Invalid or missing security token. Please retry from the list page.', FlashMessages::ERROR);
            return $this->redirect('alias/list');
        }

        $alias = ($alid = $this->param('alid'))
            ? $this->em()->getRepository('\\Entities\\Alias')->find((int) $alid)
            : null;

        // loadAlias() authorises a non-super admin against the alias's domain.
        if (!$alias || (!$admin->isSuper() && !$admin->canManageDomain($alias->getDomain()))) {
            return $this->redirect('alias/list');
        }

        $context = new AliasContext(
            $this->em(),
            $admin,
            $alias->getDomain(),
            $alias,
            $this->container->options(),
            new FlashMessages(new MagicPropertyStorage($this->session())),
        );
        $host = new PluginHost($context);

        $deleted = (new \ViMbAdmin_Service_Alias($this->em()))->delete(
            $alias,
            $admin,
            fn() => $host->notify('alias', 'delete', 'preRemove', $context) !== false,
            fn() => $host->notify('alias', 'delete', 'preFlush', $context),
            fn() => $host->notify('alias', 'delete', 'postFlush', $context),
        );

        if ($deleted) {
            $this->flash('Alias has been removed successfully');
        }

        return $this->redirect('alias/list');
    }

    /**
     * Build the native alias add form: optional local_part, a domain select scoped
     * to the admin's domains, and the goto textarea.
     *
     * @param array<int|string,string> $choices domain id → name for the dropdown
     */
    private function buildAliasAddForm(array $choices, ?object $preferred): Form
    {
        $form = new Form(new Csrf(new MagicPropertyStorage($this->container->session())));

        // local_part is optional: a blank value makes a catch-all @domain alias
        // (ZF1 `setRequired(false)`); the assembled address is validated on POST.
        $form->add(new Field('local_part', 'Local Part', 'text'));

        $domainKeys  = array_map('strval', array_keys($choices));
        $domainField = new Field('domain', 'Domain', 'select', [
            Validators::required(),
            Validators::inArray($domainKeys),
        ]);
        $domainField->setOptions(['' => ''] + $choices);
        if ($preferred !== null) {
            $domainField->setValue((string) $preferred->getId());
        }
        $form->add($domainField);

        // One destination per line (or comma-separated) — the JS-free replacement
        // for the ZF1 goto[] multi-input widget. Parsed/validated on POST.
        $form->add(new Field('goto', 'Goto (one address per line)', 'textarea'));

        return $form;
    }

    /**
     * Parse + validate the goto textarea into a deduplicated address list — the
     * native equivalent of the ZF1 `_setGotos`.
     *
     * Accepts newline- or comma-separated addresses; each must be a syntactically
     * valid email OR a leading-`@` domain wildcard (which ZF1 does not
     * email-validate). At least one address is required. Returns
     * `[addresses, null]` on success or `[[], errorMessage]` on failure.
     *
     * @return array{0:array<int,string>,1:?string}
     */
    private function parseGotos(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $gotos = [];

        foreach ($parts as $goto) {
            $goto = trim($goto);
            if ($goto === '') {
                continue;
            }

            if ($goto[0] !== '@' && filter_var($goto, FILTER_VALIDATE_EMAIL) === false) {
                return [[], 'Invalid email address(es).'];
            }

            $gotos[] = $goto;
        }

        $gotos = array_values(array_unique($gotos));

        if ($gotos === []) {
            return [[], 'You must have at least one goto address.'];
        }

        return [$gotos, null];
    }
}
