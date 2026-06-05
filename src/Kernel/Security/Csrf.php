<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Security;

use ViMbAdmin\Kernel\Session\SessionStorage;

/**
 * Framework-free CSRF token service.
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). Replaces the
 * session-backed CSRF that ViMbAdmin currently does two ways — the controller's
 * _getCsrfToken()/_assertCsrf() (link-based GET guard, an OSS_String::random
 * token compared with hash_equals) and the ZF1 form hash element (form guard) —
 * with one small service over the {@see SessionStorage} port. Because it depends
 * only on that port it is unit-testable with an in-memory session and needs no
 * framework; in production it is given a NativeSessionStorage.
 *
 * Semantics preserved from _assertCsrf(): a single stable per-session token,
 * generated on first use, validated with a constant-time comparison; an empty
 * or null submission is always invalid.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Csrf
{
    public function __construct(
        private readonly SessionStorage $session,
        private readonly string $key = 'csrfToken',
    ) {
    }

    /**
     * The per-session token, minting and storing one on first use. Stable for
     * the life of the session so it can be embedded in any number of forms /
     * links and still validate.
     */
    public function token(): string
    {
        $existing = $this->session->get($this->key);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set($this->key, $token);

        return $token;
    }

    /**
     * Constant-time check of a submitted token against the session token. An
     * empty or null submission, or a session with no token yet, is invalid.
     */
    public function isValid(?string $submitted): bool
    {
        if ($submitted === null || $submitted === '') {
            return false;
        }

        $stored = $this->session->get($this->key);
        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Discard the current token so the next {@see token()} mints a fresh one
     * (e.g. after a successful login, mirroring session-fixation hygiene).
     */
    public function rotate(): void
    {
        $this->session->remove($this->key);
    }
}
