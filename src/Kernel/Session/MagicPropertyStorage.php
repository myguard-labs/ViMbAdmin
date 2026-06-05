<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * SessionStorage backed by any object that exposes data through magic property
 * access (`__get` / `__set` / `__isset` / `__unset`).
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). Its purpose during
 * the migration is to bridge the kernel's session services onto the legacy ZF1
 * session namespace object the controllers still use (`getSessionNamespace()`),
 * WITHOUT this class referencing the framework: it takes a plain `object` and
 * uses property syntax, which the ZF1 namespace implements via its magic
 * accessors. That keeps it inside the zero-framework `src/` tree and makes it
 * unit-testable with a tiny anonymous magic-property fake. Once the ZF1 session
 * is gone (end of Phase 5) callers swap this for {@see NativeSessionStorage} or
 * a PSR-15 session and nothing else changes.
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
