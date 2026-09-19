<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Session;

/**
 * Native PHP `$_SESSION` implementation of the session storage port.
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). Keys are namespaced
 * under a single array entry so the kernel's session data does not collide with
 * other consumers of `$_SESSION`. Starting the session is the caller's
 * responsibility — this class only reads and writes the superglobal.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class NativeSessionStorage implements SessionStorage
{
    public function __construct(private readonly string $namespace = 'vimbadmin_kernel')
    {
    }

    public function has(string $key): bool
    {
        $namespace = $_SESSION[$this->namespace] ?? null;
        return is_array($namespace) && array_key_exists($key, $namespace);
    }

    public function get(string $key): mixed
    {
        $namespace = $_SESSION[$this->namespace] ?? null;
        return is_array($namespace) ? ($namespace[$key] ?? null) : null;
    }

    public function set(string $key, mixed $value): void
    {
        if (!isset($_SESSION[$this->namespace]) || !is_array($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }
        $_SESSION[$this->namespace][$key] = $value;
    }

    public function remove(string $key): void
    {
        $namespace = $_SESSION[$this->namespace] ?? null;
        if (is_array($namespace)) {
            unset($namespace[$key]);
            $_SESSION[$this->namespace] = $namespace;
        }
    }
}
