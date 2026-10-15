<?php

/** Caller-specific policy for the shared mailbox creation persistence core. */
final class ViMbAdmin_Service_MailboxCreationPolicy
{
    private function __construct(
        private readonly ?bool $mailboxActive,
        private readonly bool $selfAliasActive,
        private readonly ?\Entities\Admin $auditActor,
    ) {
    }

    public static function web(\Entities\Admin $actor): self
    {
        return new self(true, true, $actor);
    }

    public static function mcp(): self
    {
        return new self(null, true, null);
    }

    public function mailboxActive(): ?bool
    {
        return $this->mailboxActive;
    }

    public function selfAliasActive(): bool
    {
        return $this->selfAliasActive;
    }

    public function auditActor(): ?\Entities\Admin
    {
        return $this->auditActor;
    }

}
