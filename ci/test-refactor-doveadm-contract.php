<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
$assertions = 0;
$check = static function (string $label, bool $ok) use (&$failures, &$assertions): void {
    $assertions++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};

/** @return \Throwable|null */
$caught = static function (callable $operation): ?\Throwable {
    try {
        $operation();
    } catch (\Throwable $error) {
        return $error;
    }
    return null;
};

final class RefactorDoveadmFixture extends ViMbAdmin_Doveadm
{
    public string $mode = 'success';
    /** @var list<mixed> */
    public array $rows = [['name' => 'INBOX']];
    /** @var array{0:mixed,1:mixed,2:mixed}|null */
    public ?array $lastCommand = null;
    public ?\Throwable $nestedFailure = null;

    public function __construct(?callable $progress = null)
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'fixture-key', 1, $progress);
    }

    public function responseLength(): int
    {
        return strlen(json_encode(
            [['doveadmResponse', $this->rows, 'vimb00000000']],
            JSON_THROW_ON_ERROR
        ));
    }

    /** @return array{0:int,1:string} */
    #[\Override]
    protected function _post($payload)
    {
        if (!is_string($payload)) {
            throw new \RuntimeException('contract fixture request must be JSON');
        }
        $request = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $envelope = is_array($request) && count($request) === 1 ? ($request[0] ?? null) : null;
        if (!is_array($envelope) || count($envelope) !== 3) {
            throw new \RuntimeException('contract fixture request must contain one tagged command');
        }
        $command = $envelope[0] ?? null;
        $params = $envelope[1] ?? null;
        $tag = $envelope[2] ?? null;
        if (!is_string($command) || !is_array($params) || !is_string($tag)) {
            throw new \RuntimeException('contract fixture request has an invalid shape');
        }
        $this->lastCommand = [$command, $params, $tag];

        if ($this->mode === 'transport-error') {
            throw new \RuntimeException('injected transport failure');
        }
        if ($this->mode === 'nested') {
            $this->nestedFailure = null;
            try {
                $this->fsListDirsBounded('/nested', 1, 8, 128);
            } catch (\Throwable $error) {
                $this->nestedFailure = $error;
            }
        }

        $response = match ($this->mode) {
            'auth' => [401, 'denied'],
            'server-error' => [503, 'backend unavailable'],
            'malformed-json' => [200, '{'],
            'multiple' => [200, json_encode([
                ['doveadmResponse', [['name' => 'other']], 'other-tag'],
                ['doveadmResponse', $this->rows, $tag],
            ], JSON_THROW_ON_ERROR)],
            'truncated' => [200, json_encode([['doveadmResponse', $this->rows]], JSON_THROW_ON_ERROR)],
            'wrong-tag' => [200, json_encode([['doveadmResponse', $this->rows, $tag . '-wrong']], JSON_THROW_ON_ERROR)],
            'malformed-tag' => [200, json_encode([['doveadmResponse', $this->rows, ['bad']]], JSON_THROW_ON_ERROR)],
            'unexpected-type' => [200, json_encode([['notice', $this->rows, $tag]], JSON_THROW_ON_ERROR)],
            'scalar-content' => [200, json_encode([['doveadmResponse', 'not-rows', $tag]], JSON_THROW_ON_ERROR)],
            'command-error' => [200, json_encode([[
                'error',
                ['type' => 'No such file or directory', 'exitCode' => 68],
                $tag,
            ]], JSON_THROW_ON_ERROR)],
            default => [200, json_encode([['doveadmResponse', $this->rows, $tag]], JSON_THROW_ON_ERROR)],
        };
        return $response;
    }
}

$progressCalls = 0;
$client = new RefactorDoveadmFixture(static function () use (&$progressCalls): void {
    $progressCalls++;
});

$check(
    'positive: run accepts one matching tagged response and returns its rows',
    $client->run('mailbox list') === [['name' => 'INBOX']]
);
$check(
    'positive: run reports progress before entering the transport',
    $progressCalls === 1
);

$client->mode = 'auth';
$error = $caught(static fn (): array => $client->run('mailbox list'));
$check(
    'error: authentication status retains its specific diagnostic',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP auth rejected (HTTP 401) — check doveadm.http.api_key'
);

$client->mode = 'server-error';
$error = $caught(static fn (): array => $client->run('mailbox list'));
$check(
    'error: non-success HTTP status remains visible with bounded response context',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP error (HTTP 503): backend unavailable'
);

$malformedCases = [
    'malformed-json' => 'doveadm HTTP: unparseable response:',
    'multiple' => 'doveadm HTTP: response must contain exactly one tagged result',
    'truncated' => 'doveadm HTTP: response must contain exactly one tagged result',
    'wrong-tag' => 'doveadm HTTP: response tag does not match request',
    'malformed-tag' => 'doveadm HTTP: response tag does not match request',
    'unexpected-type' => "doveadm 'mailbox list': unexpected response type 'notice'",
    'scalar-content' => 'doveadm HTTP: response payload is not an array',
];
foreach ($malformedCases as $mode => $message) {
    $client->mode = $mode;
    $error = $caught(static fn (): array => $client->run('mailbox list'));
    $check(
        "malformed: {$mode} response fails closed at the transport owner",
        $error instanceof ViMbAdmin_Exception && str_contains($error->getMessage(), $message)
    );
}

$client->mode = 'command-error';
$error = $caught(static fn (): array => $client->run('fsIterDirs'));
$check(
    'error: parsed command failure preserves command, type, and exit code',
    $error instanceof ViMbAdmin_Doveadm_CommandException
    && $error->getCommand() === 'fsIterDirs'
    && $error->getErrorType() === 'No such file or directory'
    && $error->getExitCode() === 68
);

$client->mode = 'success';
$client->rows = [['path' => 'abcd']];
$exactResponseBytes = $client->responseLength();
$check(
    'boundary: an exactly sized bounded response and name are accepted',
    $client->fsListDirsBounded('/mail', 1, 4, $exactResponseBytes) === ['abcd']
);

$client->rows = [['path' => 'alpha'], ['path' => 'beta']];
$check(
    'boundary: exactly the configured item limit is accepted',
    $client->fsListDirsBounded('/mail', 2, 8, 1024) === ['alpha', 'beta']
);
$client->rows[] = ['path' => 'gamma'];
$error = $caught(static fn (): array => $client->fsListDirsBounded('/mail', 2, 8, 1024));
$check(
    'error: entry after the configured item limit is rejected rather than truncated',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP: filesystem response exceeds 2 entries'
);

$client->rows = [['path' => 'abcde']];
$error = $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 4, 1024));
$check(
    'error: a filesystem name over the byte limit is rejected',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP: filesystem response name exceeds 4 bytes'
);

$client->rows = [['path' => 12]];
$error = $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 8, 1024));
$check(
    'malformed: a non-string filesystem name is rejected',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP: filesystem response name must be a string'
);

$invalidBounds = [
    $caught(static fn (): array => $client->fsListDirsBounded('/mail', -1, 8, 1024)),
    $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 0, 1024)),
    $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 8, 0)),
];
$check(
    'boundary: invalid item, name, and response bounds reject before transport',
    count(array_filter($invalidBounds, static fn (?\Throwable $item): bool => $item instanceof \LogicException)) === 3
);

$client->rows = [['path' => 'kept', 'padding' => str_repeat('x', 512)]];
$error = $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 8, 128));
$check(
    'error: oversized bounded response is rejected before row conversion',
    $error instanceof ViMbAdmin_Exception
    && $error->getMessage() === 'doveadm HTTP: response exceeds the bounded limit of 128 bytes'
);
$check(
    'boundary: the byte limit is restored after an oversized-response exception',
    $client->run('fsIterDirs') === $client->rows
);

$client->mode = 'transport-error';
$error = $caught(static fn (): array => $client->fsListDirsBounded('/mail', 1, 8, 1024));
$client->mode = 'success';
$client->rows = [['path' => 'after-error']];
$check(
    'error: the byte limit is restored when the transport itself throws',
    $error instanceof RuntimeException
    && $error->getMessage() === 'injected transport failure'
    && $client->run('fsIterDirs') === $client->rows
);

$client->mode = 'nested';
$client->rows = [['path' => 'outer']];
$outer = $client->fsListDirsBounded('/mail', 1, 8, 1024);
$check(
    'error: nested bounded calls are rejected while the outer scope still completes',
    $outer === ['outer']
    && $client->nestedFailure instanceof LogicException
    && $client->nestedFailure->getMessage() === 'Doveadm response bound must be a positive, non-nested limit.'
);

if (!function_exists('curl_init')) {
    throw new RuntimeException('The cURL extension is required for the timeout contract.');
}

$fixtureDirectory = sys_get_temp_dir() . '/vimbadmin-doveadm-contract-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDirectory, 0700)) {
    throw new RuntimeException('Could not create the timeout fixture directory.');
}
$serverScript = $fixtureDirectory . '/server.php';
file_put_contents($serverScript, <<<'PHP'
<?php
declare(strict_types=1);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    fwrite(STDERR, $error . "\n");
    exit(2);
}
echo 'http://' . stream_socket_get_name($server, false) . "/doveadm/v1\n";
flush();
$client = stream_socket_accept($server, 10);
if (!is_resource($client)) {
    exit(3);
}
while (($line = fgets($client)) !== false && trim($line) !== '') {
}
sleep(3);
fclose($client);
fclose($server);
PHP);

/** @var array<int,resource> $pipes */
$pipes = [];
$process = proc_open(
    [PHP_BINARY, $serverScript],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the timeout fixture.');
}

try {
    stream_set_blocking($pipes[1], false);
    $deadline = hrtime(true) + 5_000_000_000;
    $url = '';
    do {
        $line = fgets($pipes[1]);
        if ($line !== false) {
            $url = trim($line);
            break;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    if ($url === '') {
        throw new RuntimeException('Timeout fixture did not publish its address.');
    }

    $timeoutProgress = 0;
    $timeoutClient = new ViMbAdmin_Doveadm($url, 'fixture-key', 1, static function () use (&$timeoutProgress): void {
        $timeoutProgress++;
    });
    $error = $caught(static fn (): array => $timeoutClient->run('mailbox list'));
    $check(
        'error: an actual transfer timeout is visible instead of an empty success',
        $error instanceof ViMbAdmin_Exception
        && $error->getMessage() === 'doveadm HTTP request failed (curl): '
            . curl_strerror(CURLE_OPERATION_TIMEDOUT)
    );
    $check(
        'positive: the stalled transfer continues reporting liveness before timeout',
        $timeoutProgress >= 2
    );
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    $deadline = hrtime(true) + 5_000_000_000;
    while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
        usleep(10000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process, 9);
    }
    proc_close($process);
    if (is_file($serverScript)) {
        unlink($serverScript);
    }
    rmdir($fixtureDirectory);
}

$expectedAssertions = 24;
echo ($assertions === $expectedAssertions ? '  ok   ' : '  FAIL ')
    . "fixed assertion count ({$assertions} of {$expectedAssertions})\n";
if ($assertions !== $expectedAssertions) {
    $failures++;
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
