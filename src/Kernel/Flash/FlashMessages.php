<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Flash;

use ViMbAdmin\Kernel\Session\SessionStorage;

/**
 * Framework-free flash-message queue over the {@see SessionStorage} port.
 *
 * Phase 5 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md). Replaces the
 * controller `addMessage()` trait, which pushes OSS_Message objects onto a ZF1
 * session namespace and drains them on the next render. The AbstractController
 * shim (Phase 3) needs this so a migrated controller can still flash a
 * success/error notice across a redirect without the framework.
 *
 * Messages survive exactly one read: {@see drain()} returns and clears them, the
 * standard post-redirect-get flash pattern. Levels match the OSS_Message
 * constants so the existing message templates render unchanged.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class FlashMessages
{
    public const SUCCESS = 'success';
    public const ERROR   = 'error';
    public const INFO    = 'info';
    public const WARNING = 'warning'; // OSS_Message::ALERT is also 'warning'

    public function __construct(
        private readonly SessionStorage $session,
        private readonly string $key = 'flashMessages',
    ) {
    }

    public function add(string $text, string $level = self::SUCCESS, bool $isHtml = true): void
    {
        $queue   = $this->rawQueue();
        $queue[] = (new FlashMessage($text, $level, $isHtml))->toArray();
        $this->session->set($this->key, $queue);
    }

    public function success(string $text, bool $isHtml = true): void { $this->add($text, self::SUCCESS, $isHtml); }
    public function error(string $text, bool $isHtml = true): void   { $this->add($text, self::ERROR, $isHtml); }
    public function info(string $text, bool $isHtml = true): void    { $this->add($text, self::INFO, $isHtml); }
    public function warning(string $text, bool $isHtml = true): void { $this->add($text, self::WARNING, $isHtml); }

    public function isEmpty(): bool
    {
        return $this->rawQueue() === [];
    }

    /**
     * Return the queued messages WITHOUT clearing them.
     *
     * @return FlashMessage[]
     */
    public function peek(): array
    {
        return array_map(
            static fn(array $m): FlashMessage => FlashMessage::fromArray($m),
            $this->rawQueue(),
        );
    }

    /**
     * Return the queued messages and clear the queue (one-shot, post-redirect).
     *
     * @return FlashMessage[]
     */
    public function drain(): array
    {
        $messages = $this->peek();
        $this->session->remove($this->key);

        return $messages;
    }

    /**
     * @return list<array{text?:string,level?:string,isHtml?:bool}>
     */
    private function rawQueue(): array
    {
        $stored = $this->session->get($this->key);

        return is_array($stored) ? array_values($stored) : [];
    }
}
