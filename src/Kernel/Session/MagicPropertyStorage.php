<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * SessionStorage backed by any object that exposes data through magic property
 * access (`__get` / `__set` / `__isset` / `__unset`).
 *
 * Adapts the native {@see SessionNamespace} and other magic-property objects to
 * the framework-free {@see SessionStorage} contract used by auth, CSRF, and
 * flash-message services. The adapter depends only on a plain object and its
 * magic accessors, so it is independently unit-testable.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class MagicPropertyStorage implements SessionStorage
{
    public function __construct(private readonly object $store)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->store->$key);
    }

    public function get(string $key): mixed
    {
        return $this->store->$key ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store->$key = $value;
    }

    public function remove(string $key): void
    {
        unset($this->store->$key);
    }
}
