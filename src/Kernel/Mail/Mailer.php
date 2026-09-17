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
     * @param bool $demo when true the mailer is a no-op: send() silently
     *                   succeeds (returns OK) without ever opening a transport.
     *                   Used by the public demo so visitor actions that would
     *                   email (welcome, lost-password, …) cannot send real mail.
     */
    public function __construct(
        private readonly array $transportOptions,
        private readonly bool $demo = false
    ) {
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
        // Demo mode: swallow outgoing mail. No transport is opened; the call
        // returns OK so callers (and their flash "email sent" messages) behave
        // exactly as if delivery succeeded, but nothing leaves the box.
        if ($this->demo) {
            return;
        }

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
        $typeValue = array_key_exists('type', $o) ? $o['type'] : 'smtp';
        if (!is_string($typeValue)) {
            throw new \TypeError('mail transport type must be a string');
        }
        $type = strtolower(trim($typeValue));
        if (!in_array($type, ['smtp', 'sendmail'], true)) {
            throw new \InvalidArgumentException('Unsupported mail transport type');
        }

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

        $sslValue = array_key_exists('ssl', $o) ? $o['ssl'] : '';
        if (!is_string($sslValue)) {
            throw new \TypeError('mail transport ssl must be a string');
        }
        $ssl = strtolower(trim($sslValue));
        if (!in_array($ssl, ['', 'none', 'ssl', 'tls', 'starttls'], true)) {
            throw new \InvalidArgumentException('Unsupported mail transport SSL mode');
        }

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

        $username = null;
        if (array_key_exists('username', $o) && $o['username'] !== null) {
            if (!is_string($o['username'])) {
                throw new \TypeError('mail username must be a string');
            }
            $username = $o['username'] !== '' ? $o['username'] : null;
        }
        $host = array_key_exists('host', $o) ? $o['host'] : 'localhost';
        if (!is_string($host) || $host === '') {
            throw new \TypeError('mail host must be a non-empty string');
        }
        $port = array_key_exists('port', $o) ? self::port($o['port']) : $defaultPort;
        $password = array_key_exists('password', $o) ? $o['password'] : '';
        if (!is_string($password)) {
            throw new \TypeError('mail password must be a string');
        }

        return [
            'type'           => 'smtp',
            'host'           => $host,
            'port'           => $port,
            'tls'            => $tls,
            'username'       => $username,
            'password'       => $password,
            'verifyPeer'     => array_key_exists('verify_peer', $o) ? self::boolOpt($o['verify_peer']) : true,
            'verifyPeerName' => array_key_exists('verify_peer_name', $o) ? self::boolOpt($o['verify_peer_name']) : true,
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

        $parsed = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \TypeError('mail TLS verification option must be boolean');
        }
        return $parsed;
    }

    private static function port(mixed $value): int
    {
        if (is_int($value) && $value > 0 && $value <= 65535) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $port = filter_var($value, FILTER_VALIDATE_INT);
            if ($port !== false && $port > 0 && $port <= 65535) {
                return $port;
            }
        }
        throw new \TypeError('mail port must be an integer from 1 to 65535');
    }
}
