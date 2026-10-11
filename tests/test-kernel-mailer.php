<?php

/**
 * Unit test: the native mail sender — ViMbAdmin\Kernel\Mail\Mailer (WALL #2,
 * docs/ZF1-REMOVAL.md). Asserts the pure resolveConfig() turns raw
 * `resources.mail.transport.*` ini values into the right resolved config, and
 * that buildTransport() yields the matching symfony transport. No socket is
 * opened (we never call send()).
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Kernel/Mail/Mailer.php';

use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use ViMbAdmin\Kernel\Mail\Mailer;

final class TestKernelMailerHarnessState
{
    public static int $count = 0;
}

$failures = & TestKernelMailerHarnessState::$count;
function check(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        TestKernelMailerHarnessState::$count++;
    }
}

echo "== native mailer ==\n";

// --- resolveConfig: secure SMTP defaults (required STARTTLS) ---------------
$c = Mailer::resolveConfig([]);
check('default type is smtp', $c['type'] === 'smtp');
check('default host localhost', $c['host'] === 'localhost');
check('default port 587', $c['port'] === 587);
check('default starts without implicit TLS', $c['tls'] === false);
check('default requires TLS', $c['requireTls'] === true);
check('default enables STARTTLS negotiation', $c['autoTls'] === true);
check('default no auth (username null)', $c['username'] === null);
check('default verify_peer true', $c['verifyPeer'] === true);
check('default verify_peer_name true', $c['verifyPeerName'] === true);

// --- resolveConfig: required STARTTLS (ssl=tls) ----------------------------
$c = Mailer::resolveConfig(['ssl' => 'tls']);
check('ssl=tls -> no implicit TLS', $c['tls'] === false);
check('ssl=tls -> TLS required', $c['requireTls'] === true);
check('ssl=tls -> STARTTLS enabled', $c['autoTls'] === true);
check('ssl=tls -> default port 587', $c['port'] === 587);
$c = Mailer::resolveConfig(['ssl' => 'STARTTLS']);
check('ssl=STARTTLS (case-insensitive) -> TLS required', $c['requireTls'] === true);

// --- resolveConfig: plaintext requires an explicit mode -------------------
$c = Mailer::resolveConfig(['ssl' => 'none']);
check('ssl=none -> TLS not required', $c['requireTls'] === false);
check('ssl=none -> automatic STARTTLS disabled', $c['autoTls'] === false);

// --- resolveConfig: implicit TLS (ssl=ssl) ---------------------------------
$c = Mailer::resolveConfig(['ssl' => 'ssl']);
check('ssl=ssl -> tls true (implicit)', $c['tls'] === true);
check('ssl=ssl -> transport remains TLS-required', $c['requireTls'] === true);
check('ssl=ssl -> STARTTLS disabled', $c['autoTls'] === false);
check('ssl=ssl -> default port 465', $c['port'] === 465);

// --- resolveConfig: explicit port wins over the ssl default ----------------
$c = Mailer::resolveConfig(['ssl' => 'ssl', 'port' => 2525]);
check('explicit port overrides ssl default', $c['port'] === 2525);
$c = Mailer::resolveConfig(['host' => 'relay.example.net']);
check('explicit host honoured', $c['host'] === 'relay.example.net');

// --- resolveConfig: auth only when username non-empty ----------------------
$c = Mailer::resolveConfig(['username' => '', 'password' => 'x']);
check('empty username -> no auth', $c['username'] === null);
$c = Mailer::resolveConfig(['username' => 'bob', 'password' => 'secret']);
check('username set -> auth user', $c['username'] === 'bob');
check('username set -> auth password', $c['password'] === 'secret');

// --- resolveConfig: ini "0"/"1" string booleans (the (bool)"0" trap) -------
$c = Mailer::resolveConfig(['verify_peer' => '0', 'verify_peer_name' => '0']);
check('verify_peer "0" string -> false', $c['verifyPeer'] === false);
check('verify_peer_name "0" string -> false', $c['verifyPeerName'] === false);
$c = Mailer::resolveConfig(['verify_peer' => '1']);
check('verify_peer "1" string -> true', $c['verifyPeer'] === true);

// --- resolveConfig: sendmail -----------------------------------------------
$c = Mailer::resolveConfig(['type' => 'sendmail']);
check('type=sendmail recognised', $c['type'] === 'sendmail');
$c = Mailer::resolveConfig(['type' => 'SendMail']);
check('type case-insensitive', $c['type'] === 'sendmail');

// --- buildTransport: yields the matching symfony transport -----------------
check(
    'smtp -> EsmtpTransport',
    Mailer::buildTransport(['ssl' => 'tls', 'host' => 'mail.example.net', 'port' => 587]) instanceof EsmtpTransport
);
$t = Mailer::buildTransport(['ssl' => 'tls']);
check('required STARTTLS transport enforces TLS', $t instanceof EsmtpTransport && $t->isTlsRequired());
check('required STARTTLS transport negotiates STARTTLS', $t instanceof EsmtpTransport && $t->isAutoTls());
$t = Mailer::buildTransport(['ssl' => 'none']);
check('explicit plaintext transport does not require TLS', $t instanceof EsmtpTransport && !$t->isTlsRequired());
check('explicit plaintext transport cannot auto-upgrade', $t instanceof EsmtpTransport && !$t->isAutoTls());
check(
    'sendmail -> SendmailTransport',
    Mailer::buildTransport(['type' => 'sendmail']) instanceof SendmailTransport
);

// untrusted-cert build path must not blow up (verify flags off)
$t = Mailer::buildTransport(['ssl' => 'ssl', 'verify_peer' => '0', 'verify_peer_name' => '0', 'username' => 'u', 'password' => 'p']);
check('untrusted-cert + auth build -> EsmtpTransport', $t instanceof EsmtpTransport);

// --- the Mailer instance caches its transport ------------------------------
$m = new Mailer(['ssl' => 'tls', 'host' => 'mail.example.net']);
check('transport() caches (same instance)', $m->transport() === $m->transport());

foreach (
    [
        ['type' => null],
        ['ssl' => null],
        ['host' => ['mail.example.net']],
        ['port' => 0],
        ['verify_peer' => 'maybe'],
    ] as $invalidOptions
) {
    $rejected = false;
    try {
        Mailer::resolveConfig($invalidOptions);
    } catch (\TypeError|\InvalidArgumentException) {
        $rejected = true;
    }
    check('malformed mail transport option fails closed', $rejected);
}

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
