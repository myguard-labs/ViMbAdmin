<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Security;

use ViMbAdmin\Kernel\Session\SessionStorage;

/**
 * Framework-free authentication / authorisation service.
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). It is the
 * replacement for the controller `getAdmin()` / `authorise()` glue, which today
 * goes through the ZF1 auth resource: the identity is an array carrying the
 * admin's `id` (see OSS_Controller_Action_Trait_Doctrine2User::getUser(), which
 * loads `\Entities\Admin` by `getIdentity()['id']`), and "super" is a flag on
 * that admin.
 *
 * Dependencies are a {@see SessionStorage} (where the identity array lives) and
 * an admin-loader callable `fn(int $id): ?object` (so the service stays free of
 * Doctrine and is unit-testable). In production the loader is
 * `fn($id) => $em->getRepository('\Entities\Admin')->find($id)` and the session
 * is a storage keyed to wherever the ZF1 auth layer wrote the identity; both are
 * supplied at wiring time (Phase 3) and validated against a running instance.
 *
 * The service answers the questions, never performs HTTP: the controller keeps
 * the redirect-to-login that `authorise()` did on a negative answer.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Auth
{
    private bool $loaded = false;
    private ?object $admin = null;

    /**
     * @param callable(int):?object $adminLoader loads the admin entity by id
     */
    public function __construct(
        private readonly SessionStorage $session,
        private $adminLoader,
        private readonly string $identityKey = 'identity',
    ) {
    }

    /**
     * The raw identity array as stored by the auth layer, or null if absent.
     *
     * @return array<string,mixed>|null
     */
    public function identity(): ?array
    {
        $value = $this->session->get($this->identityKey);

        return is_array($value) ? $value : null;
    }

    /**
     * Whether the request carries a usable identity (an `id` to load an admin).
     */
    public function isAuthenticated(): bool
    {
        $identity = $this->identity();

        return $identity !== null && isset($identity['id']) && $identity['id'];
    }

    /**
     * The logged-in admin entity, loaded once via the loader, or null if there
     * is no identity or the loader finds nothing.
     */
    public function admin(): ?object
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $identity = $this->identity();
            if ($identity !== null && isset($identity['id']) && $identity['id']) {
                $this->admin = ($this->adminLoader)((int) $identity['id']);
            }
        }

        return $this->admin;
    }

    /**
     * Whether the logged-in admin is a super admin.
     */
    public function isSuper(): bool
    {
        $admin = $this->admin();

        return $admin !== null && (bool) $admin->getSuper();
    }

    /**
     * The authorisation decision behind `authorise()`: there must be a loaded
     * admin, and — when $superRequired — it must be a super admin. Returns a
     * bool; the caller decides what to do (redirect to login, 403, …).
     */
    public function isAuthorised(bool $superRequired = false): bool
    {
        if (!$this->isAuthenticated() || $this->admin() === null) {
            return false;
        }

        return !$superRequired || $this->isSuper();
    }
}
