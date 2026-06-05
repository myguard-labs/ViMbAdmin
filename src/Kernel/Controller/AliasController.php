<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\Plugin\AliasContext;
use ViMbAdmin\Kernel\Plugin\PluginHost;
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
 * The remaining form/CRUD actions stay on ZF1 via the dispatcher fallback. The
 * legacy controller is untouched.
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
}
