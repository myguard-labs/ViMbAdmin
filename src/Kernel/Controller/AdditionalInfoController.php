<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\Mvc\AbstractController;

/**
 * Native port of the legacy `AdditionalInfoController` (Phase 3,
 * docs/ZF1-REMOVAL.md) — the first real controller served by the framework-free
 * kernel.
 *
 * The single action, `typeahead`, backs the admin UI's type-ahead inputs: it
 * returns the distinct stored values for an `xpiInfo.<type>` mailbox-preference
 * attribute, scoped to the domains the logged-in admin manages (the repository
 * widens to all values for a super admin). The legacy action did
 * `removeHelper('viewRenderer'); echo json_encode(...)`; here it simply returns a
 * JSON {@see Response}, so no view is involved.
 *
 * Preserves the historical `/additionalinfo/typeahead` route and response.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class AdditionalInfoController extends AbstractController
{
    /**
     * GET /additionalinfo/typeahead/type/{type}
     *
     * @return Response JSON array of matching preference values
     */
    public function typeaheadAction(): Response
    {
        $admin = $this->admin();
        if ($admin === null) {
            // Unauthenticated: nothing to suggest. (The legacy action would
            // fatal dereferencing a missing identity; an empty list is the
            // safe, equivalent-for-an-authed-UI answer.)
            return $this->json([]);
        }

        $values = $this->em()
            ->getRepository('\\Entities\\MailboxPreference')
            ->loadPrefrenceValuesByAttribute('xpiInfo.' . (string) $this->param('type', ''), $admin);

        return $this->json($values);
    }
}
