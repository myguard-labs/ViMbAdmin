<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ViMbAdmin\Kernel\Controller\MailboxController;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$endpoint = static function (array $options, mixed $https, mixed $port): array {
    /** @var array{https:bool,host:string,sni:string,port:int} */
    return (new ReflectionMethod(MailboxController::class, 'queueEndpoint'))->invoke(null, $options, $https, $port);
};
$fails = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (LogicException $e) {
        return $e->getMessage() === $message;
    }
    return false;
};

$previousHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'attacker.example';
$check(
    'positive: trusted configuration pins the Host header and TLS peer despite hostile HTTP_HOST',
    $endpoint(['queue' => ['runner' => ['host' => 'mail.example.test']]], 'on', '443') === [
        'https' => true,
        'host' => 'mail.example.test',
        'sni' => 'mail.example.test',
        'port' => 443,
    ]
);
$check(
    'boundary: IPv6 Host syntax and a non-default listener port are preserved safely',
    $endpoint(['queue' => ['runner' => ['host' => '[::1]']]], null, 8080) === [
        'https' => false,
        'host' => '[::1]:8080',
        'sni' => '::1',
        'port' => 8080,
    ]
);
$check(
    'error: missing trusted host fails closed instead of sending the bearer to a default vhost',
    $fails(
        static fn (): array => $endpoint([], null, null),
        'Configuration queue.runner.host must be a hostname or IP address',
    )
);
$check(
    'malformed: control characters, embedded ports, bad brackets, and invalid ports are rejected',
    $fails(
        static fn (): array => $endpoint(
            ['queue' => ['runner' => ['host' => "mail.example.test\r\nX: y"]]],
            null,
            '80',
        ),
        'Configuration queue.runner.host contains control characters',
    )
        && $fails(
            static fn (): array => $endpoint(
                ['queue' => ['runner' => ['host' => 'mail.example.test:443']]],
                null,
                '80',
            ),
            'Configuration queue.runner.host must be a hostname or IP address',
        )
        && $fails(
            static fn (): array => $endpoint(
                ['queue' => ['runner' => ['host' => '[::1']]],
                null,
                '80',
            ),
            'Configuration queue.runner.host must be a hostname or IP address',
        )
        && $fails(
            static fn (): array => $endpoint(
                ['queue' => ['runner' => ['host' => 'mail.example.test']]],
                null,
                '80junk',
            ),
            'Server port must be an integer from 1 through 65535',
        )
);
$check(
    'negative: endpoint construction has no HTTP_HOST data dependency',
    (static function () use ($endpoint): bool {
        $_SERVER['HTTP_HOST'] = 'second-attacker.example';
        $actual = $endpoint(['queue' => ['runner' => ['host' => 'mail.example.test']]], 'on', '443');
        return $actual['host'] === 'mail.example.test' && $actual['sni'] === 'mail.example.test';
    })()
);
if ($previousHost === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $previousHost;
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
