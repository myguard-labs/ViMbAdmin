<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel;

/**
 * The native replacement for the ZF1 application bootstrap object the
 * {@see Container} reads its resources from (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * The Container is handed an `object` exposing `getResource(string): mixed` and
 * `getOptions(): array` — in the dual-run phase that was the live ZF1 bootstrap.
 * Once {@see Bootstrap} builds the entity manager, session namespace and view
 * itself, this holder presents them through the very same shape, so the
 * Container, Dispatcher and every native controller are reused byte-for-byte
 * with no ZF1 application present.
 *
 * Only the three resources the native kernel actually consumes are mapped
 * (`doctrine2`, `smarty`, `namespace`); any other key returns null, the same
 * "not wired yet" answer the Container's escape hatch documents.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class NativeResources
{
    /**
     * @param array<string,mixed> $options the merged application options
     * @param object              $entityManager the Doctrine EM (`doctrine2`)
     * @param object              $view          the Smarty view (`smarty`)
     * @param object              $session       the session namespace (`namespace`)
     */
    public function __construct(
        private readonly array $options,
        private readonly object $entityManager,
        private readonly object $view,
        private readonly object $session,
    ) {
    }

    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'smarty'    => $this->view,
            'namespace' => $this->session,
            default     => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
