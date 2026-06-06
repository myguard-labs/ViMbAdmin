<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * Native, framework-free replacement for the ZF1 session namespace object
 * (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Throughout the migration the native side reached the per-session UI state
 * through the legacy ZF1 namespace object the bootstrap built — the Container
 * returned it from `session()` and the page templates read it as
 * `$session->domain`, while the Auth identity bridge wrapped the legacy auth
 * namespace in a {@see MagicPropertyStorage}. Both relied on the namespace's
 * magic-property access (`$ns->key`) over a slice of `$_SESSION`.
 *
 * This class provides that exact shape natively: a magic-property view of
 * `$_SESSION[$namespace]`, so `$ns->domain` reads/writes
 * `$_SESSION['Application']['domain']`. It drops straight into both call sites
 * once the ZF1 bootstrap is gone:
 *
 *   - `Container::session()` returns `new SessionNamespace('Application')`
 *     (templates keep reading `$session->domain`, FlashMessages keeps writing
 *     `$session->flashMessages` — same `$_SESSION['Application'][...]` keys);
 *   - the Auth bridge becomes
 *     `new MagicPropertyStorage(new SessionNamespace($authNs))` over the same
 *     legacy auth namespace name (its `storage` identity slot).
 *
 * Like {@see NativeSessionStorage}, starting the PHP session is the caller's
 * responsibility (the native bootstrap does it once); this class only reads and
 * writes the superglobal. The magic accessors mean it satisfies
 * {@see MagicPropertyStorage}'s `object` contract with no adapter, so the
 * security/auth services stay unchanged.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class SessionNamespace
{
    public function __construct(private readonly string $namespace = 'Application')
    {
    }

    public function __get(string $key): mixed
    {
        return $_SESSION[$this->namespace][$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        if (!isset($_SESSION[$this->namespace]) || !is_array($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }
        $_SESSION[$this->namespace][$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($_SESSION[$this->namespace][$key]);
    }

    public function __unset(string $key): void
    {
        unset($_SESSION[$this->namespace][$key]);
    }
}
