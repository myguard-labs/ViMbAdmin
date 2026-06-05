<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Flash;

/**
 * One flash message: a level + text, optionally HTML.
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). The level strings
 * deliberately match the existing OSS_Message class constants
 * (`success` / `error` / `info` / `warning`) so the message-rendering templates
 * keep working unchanged when the controller flash glue is moved onto the
 * framework-free {@see FlashMessages} service.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class FlashMessage
{
    public function __construct(
        public readonly string $text,
        public readonly string $level = FlashMessages::SUCCESS,
        public readonly bool $isHtml = true,
    ) {
    }

    /**
     * @return array{text:string,level:string,isHtml:bool} plain array for
     *         session storage (the SessionStorage port stores scalars/arrays)
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'level' => $this->level, 'isHtml' => $this->isHtml];
    }

    /**
     * @param array{text?:string,level?:string,isHtml?:bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['text'] ?? ''),
            (string) ($data['level'] ?? FlashMessages::SUCCESS),
            (bool) ($data['isHtml'] ?? true),
        );
    }
}
