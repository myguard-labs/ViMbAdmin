<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\Form\Validators;

final class ShortWriteStream
{
    public static string $buffer = '';
    public static int $limit = 3;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $data): int
    {
        $written = min(self::$limit, strlen($data));
        self::$buffer .= substr($data, 0, $written);
        return $written;
    }
    public function stream_eof(): bool { return false; }
    /** @return array<string,mixed> */
    public function stream_stat(): array { return []; }
}
stream_wrapper_register('short-write', ShortWriteStream::class);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$invoke = static function (string $method, mixed ...$args): mixed {
    return (new ReflectionMethod(MailboxController::class, $method))->invoke(null, ...$args);
};
$fails = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (LogicException $e) {
        return $e->getMessage() === $message;
    }
    return false;
};

echo "== mailbox controller mixed-boundary shapes ==\n";

$stream = fopen('short-write://request', 'wb');
$request = "POST /queue/trigger HTTP/1.1\r\n\r\n";
$check('queue trigger retries short socket writes until the request is complete',
    is_resource($stream)
        && $invoke('writeRequest', $stream, $request) === true
        && ShortWriteStream::$buffer === $request);
if (is_resource($stream)) {
    fclose($stream);
}
ShortWriteStream::$limit = 0;
$stream = fopen('short-write://failure', 'wb');
$check('queue trigger fails closed when a socket write makes no progress',
    is_resource($stream) && $invoke('writeRequest', $stream, $request) === false);
if (is_resource($stream)) {
    fclose($stream);
}

$check('canonical positive integer accepts decimal strings and native ints',
    $invoke('positiveIntegerOrNull', '7') === 7 && $invoke('positiveIntegerOrNull', 9) === 9);
$check('integer-coercion mutant is killed by malformed and non-positive ids',
    $invoke('positiveIntegerOrNull', '7junk') === null
        && $invoke('positiveIntegerOrNull', '01') === null
        && $invoke('positiveIntegerOrNull', 0) === null
        && $invoke('positiveIntegerOrNull', ['7']) === null);

$request = $invoke('requestArray', ['draw' => '2', 7 => 'ignored']);
$check('request boundary retains only string-keyed DataTables values', $request === ['draw' => '2']);
$check('DataTables containers fail closed instead of reaching scalar casts', $fails(
    static fn(): mixed => $invoke('requestArray', ['draw' => ['2']]),
    'DataTables parameter draw must be a string',
));
$check('string-key assertion rejects nested numeric configuration keys', $fails(
    static fn(): mixed => $invoke('stringKeyedArray', [0 => 'bad'], 'Configuration test'),
    'Configuration test must use string keys',
));

$check('absent integer configuration alone receives its shipped default',
    $invoke('optionInt', [], 8, 'defaults', 'mailbox', 'min_password_length') === 8);
$check('explicit null cannot masquerade as absent configuration', $fails(
    static fn(): mixed => $invoke(
        'optionInt',
        ['defaults' => ['mailbox' => ['min_password_length' => null]]],
        8,
        'defaults',
        'mailbox',
        'min_password_length',
    ),
    'Configuration defaults.mailbox.min_password_length must be a non-negative integer',
));
$check('quota multiplier allowlist preserves configured units',
    $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'mb']]]) === 'MB'
        && $invoke('quotaBytes', '1.5', 'MB') === 1572864);
$check('quota-unit mutant is killed by an unsupported configured multiplier', $fails(
    static fn(): mixed => $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'TB']]]),
    'Configuration defaults.quota.multiplier must be B, KB, MB, or GB',
));

$stringRule = Validators::string();
$check('string validator rejects arrays before downstream field rules',
    $stringRule(['not-a-string']) === 'This field has an invalid type.' && $stringRule('value') === null);
$check('optional email storage preserves null rather than inventing an empty string',
    $invoke('optionalString', null, 'Alternative email') === null
        && $invoke('optionalString', '', 'Alternative email') === null
        && $invoke('optionalString', 'user@example.test', 'Alternative email') === 'user@example.test');
$check('queue bearer/header mutant is killed by CRLF rejection', $fails(
    static fn(): mixed => $invoke('controlSafeString', "safe\r\nX-Test: injected", 'Queue key'),
    'Queue key contains control characters',
));
$check('queue key is optional but present malformed configuration fails before mutation',
    $invoke('queueRunnerKey', []) === ''
        && $invoke('queueRunnerKey', ['queue' => ['runner' => ['key' => '']]]) === ''
        && $fails(
            static fn(): mixed => $invoke('queueRunnerKey', ['queue' => ['runner' => ['key' => ['bad']]]]),
            'Configuration queue.runner.key must be a string',
        ));
$check('queue endpoint parser accepts safe server scalars and rejects hostile runtime values',
    $invoke('queueEndpoint', 'on', 'mail.example.test:443', '443') === [
        'https' => true,
        'host' => 'mail.example.test:443',
        'sni' => 'mail.example.test',
        'port' => 443,
    ]
        && $fails(
            static fn(): mixed => $invoke('queueEndpoint', null, "mail.example.test\r\nX: y", '80'),
            'Server HTTP_HOST contains control characters',
        )
        && $fails(
            static fn(): mixed => $invoke('queueEndpoint', null, 'mail.example.test', '80junk'),
            'Server port must be an integer from 1 through 65535',
        ));
$check('invalid email form containers have safe non-echoing render values',
    $invoke('renderStringOrDefault', ['username'], 'username') === 'username'
        && $invoke('renderStringOrDefault', ['attacker@example.test'], '') === '');
$check('typed settings rebuild preserves supported scalar display values and rejects arrays',
    $invoke('displaySettingString', 587, 'SMTP port') === '587'
        && $invoke('displaySettingString', true, 'SMTP enabled') === '1'
        && $fails(
            static fn(): mixed => $invoke('displaySettingString', ['587'], 'SMTP port'),
            'SMTP port must be a scalar display value',
        ));
$check('server port accepts a valid scalar and rejects coercible junk',
    $invoke('optionalServerPort', '443') === 443
        && $fails(
            static fn(): mixed => $invoke('optionalServerPort', '443junk'),
            'Server port must be an integer from 1 through 65535',
        ));

$emailValidator = Validators::email();
$check('email callable contract returns only null or a string',
    $emailValidator('user@example.test') === null && is_string($emailValidator('broken')));

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
