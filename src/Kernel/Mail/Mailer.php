<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Framework-free mail sender for the native kernel (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * The legacy stack sent through the ZF1 mailer (`OSS_Resource_Mailer` /
 * `OSS_Controller_Action_Trait_Mailer`); the native kernel sends through
 * symfony/mailer. This class is the single place that turns the
 * `resources.mail.transport.*` block of `application.ini` into a configured
 * symfony transport and sends an {@see Email} through it.
 *
 * It names only symfony types (the purity guard only forbids the legacy
 * framework prefix, not a framework), so it lives in the `src/` tree and is
 * unit-testable: the transport-building decision is split into a pure,
 * side-effect-free {@see resolveConfig()} the test can assert against without
 * opening a socket, and {@see buildTransport()} turns that resolved config into
 * the real symfony transport.
 *
 * Recognised `resources.mail.transport.*` keys (all optional):
 *  - `type`            `smtp` (default) | `sendmail`
 *  - `host`            SMTP host (default `localhost`)
 *  - `port`            SMTP port (default 465 for implicit TLS, else 587)
 *  - `ssl`             `ssl` (implicit TLS) | `tls`/`starttls` (opportunistic
 *                      STARTTLS, the symfony default) | omitted/`none` (plaintext)
 *  - `username`        SMTP auth user (auth attempted only when non-empty)
 *  - `password`        SMTP auth password
 *  - `verify_peer`     `0` to accept an untrusted server certificate (default 1)
 *  - `verify_peer_name``0` to accept a hostname mismatch (default 1)
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Mailer
{
    private ?TransportInterface $transport = null;

    /**
     * @param array<string,mixed> $transportOptions the `resources.mail.transport`
     *                            sub-array of the application options
     */
    public function __construct(private readonly array $transportOptions)
    {
    }

    /**
     * The configured (lazily built, then cached) symfony transport.
     */
    public function transport(): TransportInterface
    {
        return $this->transport ??= self::buildTransport($this->transportOptions);
    }

    /**
     * Send a prepared message through the configured transport.
     *
     * Throws {@see \Symfony\Component\Mailer\Exception\TransportExceptionInterface}
     * on a transport failure, matching the legacy mail exception the callers
     * already catch around `send()`.
     */
    public function send(Email $message): void
    {
        (new SymfonyMailer($this->transport()))->send($message);
    }

    /**
     * Normalise the raw `resources.mail.transport.*` ini values into a resolved
     * config. Pure (no I/O, no symfony objects) so it is trivially testable.
     *
     * @param array<string,mixed> $o
     * @return array{type:string,host:string,port:int,tls:?bool,username:?string,password:string,verifyPeer:bool,verifyPeerName:bool}
     */
    public static function resolveConfig(array $o): array
    {
        $type = strtolower(trim((string) ($o['type'] ?? 'smtp')));

        if ($type === 'sendmail') {
            return [
                'type'           => 'sendmail',
                'host'           => '',
                'port'           => 0,
                'tls'            => null,
                'username'       => null,
                'password'       => '',
                'verifyPeer'     => true,
                'verifyPeerName' => true,
            ];
        }

        $ssl = strtolower(trim((string) ($o['ssl'] ?? '')));

        // implicit TLS (smtps) vs opportunistic STARTTLS vs plaintext
        if ($ssl === 'ssl') {
            $tls         = true;        // implicit TLS from connect
            $defaultPort = 465;
        } elseif ($ssl === 'tls' || $ssl === 'starttls') {
            $tls         = null;        // symfony auto-STARTTLS when offered
            $defaultPort = 587;
        } else {
            $tls         = false;       // disable TLS entirely
            $defaultPort = 587;
        }

        $username = isset($o['username']) && $o['username'] !== '' ? (string) $o['username'] : null;

        return [
            'type'           => 'smtp',
            'host'           => (string) ($o['host'] ?? 'localhost'),
            'port'           => (int) ($o['port'] ?? $defaultPort),
            'tls'            => $tls,
            'username'       => $username,
            'password'       => (string) ($o['password'] ?? ''),
            'verifyPeer'     => self::boolOpt($o['verify_peer'] ?? true),
            'verifyPeerName' => self::boolOpt($o['verify_peer_name'] ?? true),
        ];
    }

    /**
     * Build the real symfony transport from the raw ini options.
     *
     * @param array<string,mixed> $o
     */
    public static function buildTransport(array $o): TransportInterface
    {
        $c = self::resolveConfig($o);

        if ($c['type'] === 'sendmail') {
            return new SendmailTransport();
        }

        $transport = new EsmtpTransport($c['host'], $c['port'], $c['tls']);

        if ($c['username'] !== null) {
            $transport->setUsername($c['username']);
            $transport->setPassword($c['password']);
        }

        if (!$c['verifyPeer'] || !$c['verifyPeerName']) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setStreamOptions([
                    'ssl' => [
                        'verify_peer'      => $c['verifyPeer'],
                        'verify_peer_name' => $c['verifyPeerName'],
                    ],
                ]);
            }
        }

        return $transport;
    }

    /**
     * Interpret an ini value as a boolean. `parse_ini_*` yields the string `"0"`,
     * which a plain `(bool)` cast would (wrongly) read as true — so route
     * everything through {@see FILTER_VALIDATE_BOOLEAN}.
     */
    private static function boolOpt(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
