<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$bootstrap = file_get_contents($root . '/bin/vimbtool.php');
$queueCommand = file_get_contents($root . '/src/Kernel/Cli/Command/QueueRunCommand.php');
$check('bootstrap and queue command sources are readable', is_string($bootstrap) && is_string($queueCommand));

$getoptCall = null;
if (is_string($bootstrap)
    && preg_match('/\\$opts\\s*=\\s*(getopt\\(.*?\\));/s', $bootstrap, $matches) === 1) {
    $getoptCall = $matches[1];
}
$check('bootstrap getopt declaration is discoverable', is_string($getoptCall));

/**
 * Execute the getopt declaration extracted verbatim from the shipped bootstrap.
 *
 * @param list<string> $arguments
 * @return array<string,mixed>|null
 */
$parse = static function (array $arguments) use ($getoptCall): ?array {
    if (!is_string($getoptCall)) {
        return null;
    }

    $probe = tempnam(sys_get_temp_dir(), 'vimbadmin-once-');
    if ($probe === false) {
        return null;
    }

    try {
        $source = '<?php $opts = ' . $getoptCall . '; echo json_encode($opts, JSON_THROW_ON_ERROR);';
        if (file_put_contents($probe, $source) === false) {
            return null;
        }

        $command = [PHP_BINARY, $probe];
        foreach ($arguments as $argument) {
            if (!is_string($argument)) {
                return null;
            }
            $command[] = $argument;
        }

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            return null;
        }

        try {
            $parsed = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($parsed) && $stderr === '' ? $parsed : null;
    } finally {
        unlink($probe);
    }
};

$documented = $parse(['--action=queue.cli-run', '--once']);
$check(
    'positive: documented --once is parsed as a present boolean option',
    $documented === ['action' => 'queue.cli-run', 'once' => false]
);

$default = $parse(['--action=queue.cli-run']);
$check(
    'boundary: default invocation does not synthesize one-batch mode',
    $default === ['action' => 'queue.cli-run']
);

$malformed = $parse(['--action=queue.cli-run', '--once-extra']);
$check(
    'malformed: an unknown once-like option cannot activate one-batch mode',
    is_array($malformed) && !array_key_exists('once', $malformed)
);

$check(
    'forwarding: bootstrap gives the parsed option array to the selected command',
    is_string($bootstrap)
        && preg_match('/->run\\(\\s*\\$action\\s*,\\s*\\$opts\\s*,\\s*\\$container\\s*\\)/', $bootstrap) === 1
);
$check(
    'forwarding: queue one-batch behavior depends on presence of the parsed once option',
    is_string($queueCommand)
        && str_contains($queueCommand, "\$once    = array_key_exists('once', \$args);")
        && str_contains($queueCommand, '} while (!$once && $n > 0);')
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
