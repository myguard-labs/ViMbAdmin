<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

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
