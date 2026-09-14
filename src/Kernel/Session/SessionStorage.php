<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * Minimal session storage port for the framework-free kernel.
 *
 * Phase 5 (session/auth foundation) of the ZF1 removal roadmap
 * (docs/ZF1-REMOVAL.md). The kernel's security/auth services depend on this
 * narrow interface rather than a superglobal directly. Current native backings
 * include {@see SessionNamespace}, {@see MagicPropertyStorage}, and
 * {@see NativeSessionStorage}; tests can substitute an in-memory fake.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
interface SessionStorage
{
    public function has(string $key): bool;

    /**
     * @return mixed the stored value, or null if absent
     */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;
}
