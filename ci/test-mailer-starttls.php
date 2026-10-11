<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use ViMbAdmin\Kernel\Mail\Mailer;
use ViMbAdmin\Kernel\Mail\RequiredTlsEsmtpTransport;

final class MailerStarttlsTestState
{
    public static int $failures = 0;
}

function check(string $label, bool $condition): void
{
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        ++MailerStarttlsTestState::$failures;
    }
}

/** @param array<string,mixed> $options */
function smtpTransport(array $options): EsmtpTransport
{
    $transport = Mailer::buildTransport($options);
    if (!$transport instanceof EsmtpTransport) {
        throw new RuntimeException('Expected an ESMTP transport');
    }
    return $transport;
}

/**
 * Run the child side of the portable SMTP fixture.
 */
function runSmtpFixture(bool $rejectEhlo, bool $offerStarttls, bool $implicitTls): never
{
    $errno = 0;
    $error = '';
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException("Unable to create SMTP fixture: $error");
    }
    $certificateFile = null;
    if ($offerStarttls || $implicitTls) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to create STARTTLS fixture key');
        }
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
        if (!$request instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Unable to create STARTTLS fixture request');
        }
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('STARTTLS fixture request lost its key');
        }
        $certificate = openssl_csr_sign($request, null, $key, 1);
        $certificatePem = null;
        $keyPem = null;
        if (
            $certificate === false
            || !openssl_x509_export($certificate, $certificatePem)
            || !is_string($certificatePem)
            || !openssl_pkey_export($key, $keyPem)
            || !is_string($keyPem)
            || ($certificateFile = tmpfile()) === false
            || fwrite($certificateFile, $certificatePem . $keyPem) === false
        ) {
            throw new RuntimeException('Unable to create STARTTLS fixture certificate');
        }
        $certificateMetadata = stream_get_meta_data($certificateFile);
        if (!isset($certificateMetadata['uri']) || !is_string($certificateMetadata['uri'])) {
            throw new RuntimeException('Unable to locate STARTTLS fixture certificate');
        }
        $certificatePath = $certificateMetadata['uri'];
        stream_context_set_option($server, 'ssl', 'local_cert', $certificatePath);
    }
    $address = stream_socket_get_name($server, false);
    if ($address === false || !preg_match('/:(\d+)$/D', $address, $match)) {
        throw new RuntimeException('Unable to determine SMTP fixture port');
    }
    echo 'READY:' . $match[1] . "\n";
    flush();

    $connection = stream_socket_accept($server, 5);
    fclose($server);
    if ($connection === false) {
        throw new RuntimeException('SMTP fixture did not receive a connection');
    }
    stream_set_timeout($connection, 1);
    if ($implicitTls && stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        throw new RuntimeException('SMTP fixture implicit TLS failed');
    }
    fwrite($connection, "220 plaintext.test ESMTP\r\n");
    $transcript = '';
    $inData = false;
    $tlsActive = $implicitTls;
    while (($line = fgets($connection)) !== false) {
        $transcript .= $line;
        $command = strtoupper($line);
        if ($inData) {
            if ($line === ".\r\n") {
                fwrite($connection, "250 queued\r\n");
                break;
            }
        } elseif (str_starts_with($command, 'EHLO ')) {
            if ($rejectEhlo) {
                fwrite($connection, "500 ESMTP unavailable\r\n");
            } elseif ($offerStarttls && !$tlsActive) {
                fwrite($connection, "250-plaintext.test\r\n250-STARTTLS\r\n250 SIZE 100000\r\n");
            } else {
                fwrite($connection, "250-plaintext.test\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 100000\r\n");
            }
        } elseif (str_starts_with($command, 'HELO ')) {
            fwrite($connection, "250 plaintext.test\r\n");
        } elseif ($command === "STARTTLS\r\n") {
            fwrite($connection, "220 begin TLS\r\n");
            $tlsActive = stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($tlsActive !== true) {
                break;
            }
        } elseif (str_starts_with($command, 'AUTH ')) {
            fwrite($connection, "235 authenticated\r\n");
        } elseif (str_starts_with($command, 'MAIL FROM:') || str_starts_with($command, 'RCPT TO:')) {
            fwrite($connection, "250 accepted\r\n");
        } elseif ($command === "DATA\r\n") {
            fwrite($connection, "354 send data\r\n");
            $inData = true;
        } elseif ($command === "QUIT\r\n") {
            fwrite($connection, "221 bye\r\n");
            break;
        } else {
            fwrite($connection, "250 ok\r\n");
        }
    }
    fclose($connection);
    if (is_resource($certificateFile)) {
        fclose($certificateFile);
    }
    echo 'TRANSCRIPT:' . base64_encode($transcript) . "\n";
    exit(0);
}

/**
 * Run a minimal SMTP peer in a portable PHP subprocess and return its transcript.
 *
 * @param array<string,mixed> $options
 * @return array{error:?Throwable,transcript:string}
 */
function sendToPlaintextPeer(
    array $options,
    bool $rejectEhlo = false,
    bool $offerStarttls = false,
    bool $primePriorEhloSuccess = false,
    bool $implicitTls = false
): array {
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--smtp-fixture', (string) (int) $rejectEhlo, (string) (int) $offerStarttls, (string) (int) $implicitTls],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start SMTP fixture subprocess');
    }
    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    if ($ready === false || !preg_match('/^READY:(\d+)$/D', trim($ready), $match)) {
        throw new RuntimeException('SMTP fixture did not report readiness');
    }

    $message = (new Email())
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('CI-022 transport check')
        ->text('CI022-PLAINTEXT-MESSAGE-MARKER');
    $error = null;
    try {
        $transportOptions = $options + [
            'host' => '127.0.0.1',
            'port' => (int) $match[1],
            'username' => 'ci022-user',
            'password' => 'CI022-PASSWORD-MARKER',
        ];
        if ($primePriorEhloSuccess) {
            $transport = smtpTransport($transportOptions);
            (new ReflectionProperty(RequiredTlsEsmtpTransport::class, 'ehloSucceeded'))->setValue($transport, true);
            (new SymfonyMailer($transport))->send($message);
        } else {
            (new Mailer($transportOptions))->send($message);
        }
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $fixtureOutput = stream_get_contents($pipes[1]);
    $fixtureError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $fixtureOutput === false || $fixtureError === false) {
        throw new RuntimeException('SMTP fixture subprocess failed: ' . trim((string) $fixtureError));
    }
    if (!preg_match('/^TRANSCRIPT:(.*)$/mD', $fixtureOutput, $transcriptMatch)) {
        throw new RuntimeException('SMTP fixture returned no transcript');
    }
    $transcript = base64_decode($transcriptMatch[1], true);
    if ($transcript === false) {
        throw new RuntimeException('SMTP fixture returned an invalid transcript');
    }

    return ['error' => $error, 'transcript' => $transcript];
}

if (($argv[1] ?? '') === '--smtp-fixture') {
    runSmtpFixture(($argv[2] ?? '') === '1', ($argv[3] ?? '') === '1', ($argv[4] ?? '') === '1');
}

echo "== SMTP STARTTLS boundary ==\n";

$strict = sendToPlaintextPeer(['ssl' => 'tls']);
check('required STARTTLS rejects a peer without STARTTLS', $strict['error'] instanceof TransportExceptionInterface);
check(
    'required STARTTLS reports the missing encryption boundary',
    $strict['error']?->getMessage() === 'TLS required but neither TLS or STARTTLS are in use.'
);
check('required STARTTLS sends no authentication command', !str_contains($strict['transcript'], 'AUTH '));
check('required STARTTLS sends no envelope', !str_contains($strict['transcript'], 'MAIL FROM:'));
check('required STARTTLS sends no message data', !str_contains($strict['transcript'], 'CI022-PLAINTEXT-MESSAGE-MARKER'));
check('required STARTTLS sends no password marker', !str_contains($strict['transcript'], 'CI022-PASSWORD-MARKER'));

$stripped = sendToPlaintextPeer(['ssl' => 'tls'], true);
check('required STARTTLS rejects an EHLO-to-HELO downgrade', $stripped['error'] instanceof TransportExceptionInterface);
check('downgraded HELO sends no envelope', !str_contains($stripped['transcript'], 'MAIL FROM:'));
check('downgraded HELO sends no message data', !str_contains($stripped['transcript'], 'CI022-PLAINTEXT-MESSAGE-MARKER'));

$retryDowngrade = sendToPlaintextPeer(['ssl' => 'tls'], true, false, true);
check('a prior EHLO success cannot prime a retry downgrade', $retryDowngrade['error'] instanceof TransportExceptionInterface);
check('retry downgrade sends no envelope', !str_contains($retryDowngrade['transcript'], 'MAIL FROM:'));

$encrypted = sendToPlaintextPeer([
    'ssl' => 'tls',
    'verify_peer' => false,
    'verify_peer_name' => false,
], false, true);
check('required STARTTLS accepts a completed TLS upgrade', $encrypted['error'] === null);
check('completed STARTTLS delivers the message', str_contains($encrypted['transcript'], 'CI022-PLAINTEXT-MESSAGE-MARKER'));

$implicitFallback = sendToPlaintextPeer([
    'ssl' => 'ssl',
    'verify_peer' => false,
    'verify_peer_name' => false,
], true, false, false, true);
check('implicit TLS accepts an encrypted HELO fallback', $implicitFallback['error'] === null);
check('encrypted HELO fallback delivers the message', str_contains($implicitFallback['transcript'], 'CI022-PLAINTEXT-MESSAGE-MARKER'));

$default = smtpTransport([]);
check('omitted ssl mode has the secure default', $default->isTlsRequired() && $default->isAutoTls());

$plaintext = sendToPlaintextPeer(['ssl' => 'none']);
check('explicit plaintext mode can deliver intentionally', $plaintext['error'] === null);
check('explicit plaintext mode authenticates', str_contains($plaintext['transcript'], 'AUTH PLAIN '));
check('explicit plaintext mode sends the envelope', str_contains($plaintext['transcript'], 'MAIL FROM:'));
check('explicit plaintext mode sends the message', str_contains($plaintext['transcript'], 'CI022-PLAINTEXT-MESSAGE-MARKER'));

$implicit = smtpTransport(['ssl' => 'ssl']);
$implicitStream = $implicit->getStream();
check('implicit TLS uses a TLS socket', $implicitStream instanceof SocketStream && $implicitStream->isTLS());
check('implicit TLS remains TLS-required', $implicit->isTlsRequired());
check('implicit TLS does not use STARTTLS', !$implicit->isAutoTls());

foreach ([['ssl' => 'opportunistic'], ['ssl' => false]] as $malformed) {
    try {
        Mailer::resolveConfig($malformed);
        check('malformed TLS mode fails closed', false);
    } catch (TypeError|InvalidArgumentException) {
        check('malformed TLS mode fails closed', true);
    }
}

$failures = MailerStarttlsTestState::$failures;
echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
