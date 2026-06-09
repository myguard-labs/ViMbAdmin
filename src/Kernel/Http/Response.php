<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Http;

/**
 * Minimal HTTP response value object for the framework-free kernel.
 *
 * Phase 2b of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). The kernel returns
 * one of these instead of echoing, so the dispatch logic stays pure and
 * unit-testable; the entry point ({@see emit()} from public/index.php) is the
 * only place that touches `header()`/output. A full PSR-7 response + emitter can
 * replace this once a real (Doctrine/Smarty) route is migrated; for the skeleton
 * a status + content-type + body is enough.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Response
{
    /**
     * @param array<string,string>  $headers   extra headers beyond Content-Type
     * @param (callable():void)|null $afterSend work to run AFTER the response is
     *        flushed to the client and the connection closed
     *        (`fastcgi_finish_request()`), so the caller gets its OK and
     *        disconnects while this runs detached. The entry point
     *        (public/index.php) is the only place that invokes it — used by the
     *        queue trigger to drain autonomously without blocking the caller and
     *        without forking a process.
     */
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly string $contentType = 'text/html; charset=utf-8',
        public readonly array $headers = [],
        public readonly mixed $afterSend = null,
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=utf-8');
    }
}
