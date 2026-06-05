<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * Minimal session storage port for the framework-free kernel.
 *
 * Phase 5 (session/auth foundation) of the ZF1 removal roadmap
 * (docs/ZF1-REMOVAL.md). The kernel's security/auth services depend on this
 * narrow interface rather than on the legacy ZF1 session or a superglobal directly, so
 * they are unit-testable with an in-memory fake and can later be backed by
 * native PHP sessions or PSR-15 session middleware without changing callers.
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
