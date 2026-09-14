<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Security;

use LogicException;
use ViMbAdmin\Kernel\Session\SessionStorage;

/**
 * Framework-free authentication / authorisation service.
 *
 * Owns authentication state for the native kernel. The identity is an array
 * carrying the admin's `id`, and "super" is a flag on that admin.
 *
 * Dependencies are a {@see SessionStorage} (where the identity array lives) and
 * an admin-loader callable `fn(int $id): ?object` (so the service stays free of
 * Doctrine and is unit-testable). In production the loader is
 * `fn($id) => $em->getRepository('\Entities\Admin')->find($id)` and the session
 * uses the kernel's native session storage; both are supplied at wiring time.
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

        if (!is_array($value)) {
            return null;
        }

        $identity = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $identity[$key] = $item;
        }

        return $identity;
    }

    /**
     * Whether the request carries a usable identity (an `id` to load an admin).
     */
    public function isAuthenticated(): bool
    {
        $identity = $this->identity();

        return $identity !== null && $this->identityId($identity) !== null;
    }

    /**
     * The logged-in active admin entity, loaded once via the loader, or null if
     * there is no identity, the loader finds nothing, or the admin has been
     * deactivated. A deactivated admin's identity is removed immediately.
     */
    public function admin(): ?object
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $identity = $this->identity();
            $id = $identity === null ? null : $this->identityId($identity);
            if ($id !== null) {
                $admin = ($this->adminLoader)($id);
                if (
                    $admin !== null
                    && (
                        !method_exists($admin, 'getUsername')
                        || !method_exists($admin, 'getId')
                        || !method_exists($admin, 'getSuper')
                        || !method_exists($admin, 'getActive')
                    )
                ) {
                    throw new LogicException('Authenticated admin has an invalid type');
                }

                if ($admin !== null && !self::adminIsActive($admin)) {
                    $this->clear();
                    return null;
                }
                $this->admin = $admin;
            }
        }

        return $this->admin;
    }

    /** @param array<string,mixed> $identity */
    private function identityId(array $identity): ?int
    {
        $value = $identity['id'] ?? null;
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($parsed) ? $parsed : null;
    }

    /**
     * Establish a session for an active authenticated admin: write the identity array
     * (the same `['username','user','id']` shape the legacy auth layer stored)
     * into the session storage this service reads, and reset the per-request
     * cache so a subsequent {@see admin()} reflects the new identity.
     *
     * The caller is responsible for any session-id regeneration BEFORE calling
     * this (fixation defence). The service performs no HTTP.
     */
    public function establish(object $admin): void
    {
        if (
            !method_exists($admin, 'getUsername')
            || !method_exists($admin, 'getId')
            || !method_exists($admin, 'getSuper')
            || !method_exists($admin, 'getActive')
        ) {
            throw new LogicException('Authenticated admin has an invalid type');
        }

        if (!self::adminIsActive($admin)) {
            throw new LogicException('Authenticated admin must be active');
        }

        $username = $admin->getUsername();
        if (!is_string($username) || $username === '') {
            throw new LogicException('Authenticated admin username is required');
        }

        $this->session->set($this->identityKey, [
            'username' => $username,
            'user'     => $admin,
            'id'       => $admin->getId(),
        ]);

        $this->loaded = false;
        $this->admin  = null;
    }

    private static function adminIsActive(object $admin): bool
    {
        if (!method_exists($admin, 'getActive')) {
            throw new LogicException('Authenticated admin has an invalid type');
        }

        return $admin->getActive() === true;
    }

    /**
     * Drop the identity (log out) and reset the cache.
     */
    public function clear(): void
    {
        $this->session->remove($this->identityKey);

        $this->loaded = false;
        $this->admin  = null;
    }

    /**
     * Whether the logged-in admin is a super admin.
     */
    public function isSuper(): bool
    {
        $admin = $this->admin();
        if ($admin === null) {
            return false;
        }
        if (!method_exists($admin, 'getSuper')) {
            throw new LogicException('Authenticated admin has an invalid type');
        }

        return (bool) $admin->getSuper();
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
