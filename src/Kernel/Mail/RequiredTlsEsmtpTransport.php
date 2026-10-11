<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Closes Symfony's EHLO-to-HELO fallback before SMTP commands can continue.
 */
final class RequiredTlsEsmtpTransport extends EsmtpTransport
{
    private bool $ehloSucceeded = false;

    /** @param int[] $codes */
    #[\Override]
    public function executeCommand(string $command, array $codes): string
    {
        $isEhlo = str_starts_with($command, 'EHLO ');
        if ($isEhlo) {
            $this->ehloSucceeded = false;
        }
        $response = parent::executeCommand($command, $codes);
        if ($isEhlo) {
            $this->ehloSucceeded = true;
        }
        return $response;
    }

    #[\Override]
    public function start(): void
    {
        parent::start();

        $stream = $this->getStream();
        $hasImplicitTls = $stream instanceof SocketStream && $stream->isTLS();
        if ($this->isTlsRequired() && !$hasImplicitTls && !$this->ehloSucceeded) {
            $this->stop();
            throw new TransportException('TLS required but neither TLS or STARTTLS are in use.');
        }
    }

    #[\Override]
    public function stop(): void
    {
        parent::stop();
        $this->ehloSucceeded = false;
    }
}
