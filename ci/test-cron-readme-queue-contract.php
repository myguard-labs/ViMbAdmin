<?php

declare(strict_types=1);

/** @return string */
function queueContractSource(string $path): string
{
    $contents = file_get_contents(__DIR__ . '/../' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException("Cannot read {$path}");
    }
    return $contents;
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$howto = queueContractSource('contrib/cron/README.md');
$cli = queueContractSource('src/Kernel/Cli/Command/QueueRunCommand.php');
$http = queueContractSource('src/Kernel/Controller/QueueController.php');
$howtoFlat = preg_replace('/\s+/', ' ', $howto);
if (!is_string($howtoFlat)) {
    throw new RuntimeException('Cannot normalize the cron HOWTO');
}

echo "== cron HOWTO queue contract ==\n";

$check(
    'positive: every queue entry point and execution context is documented',
    str_contains($howto, '| `queue.cli-run` | exit `0`; no output unless `--verbose` |')
        && str_contains($howto, '| `POST /queue/trigger` | HTTP `200` JSON `{"triggered":true}`')
        && str_contains($howto, '| Queue page **Run now** | Redirect + flash status |')
        && str_contains($howto, 'Same FPM worker, after response send; no fork/spawn')
        && str_contains($http, "json_encode(['triggered' => true])")
);

$check(
    'boundary: CLI full-drain and --once batch limits match the command',
    str_contains($cli, 'while (!$once && $n > 0)')
        && str_contains($howto, 'Repeats `max_per_run` batches until empty/throttled; `--once` stops after one batch')
        && str_contains($howto, '| One `max_per_run` batch |')
);

$deadline = strpos($http, '$deadline = microtime(true) + 60.0;');
$drain = strpos($http, '$n = $runner->drain($max);', $deadline === false ? 0 : $deadline);
$batchLimit = strpos($http, '$batches < 100', $drain === false ? 0 : $drain);
$deadlineLimit = strpos($http, 'microtime(true) < $deadline', $drain === false ? 0 : $drain);
$check(
    'boundary: HTTP limits are checked between batches and permit in-flight completion',
    is_int($deadline)
        && is_int($drain)
        && is_int($batchLimit)
        && is_int($deadlineLimit)
        && $drain < $batchLimit
        && $drain < $deadlineLimit
        && str_contains($howto, 'after 100 batches or once 60 seconds has elapsed (an in-flight batch completes)')
);

$check(
    'error/malformed: authentication failures and the disabled endpoint are documented',
    str_contains($http, "['error' => 'queue trigger disabled'], 404")
        && str_contains($http, "['error' => 'missing bearer'], 401")
        && str_contains($http, "['error' => 'bad key'], 403")
        && str_contains($http, 'not allowed"], 403')
        && str_contains($howtoFlat, 'wrong/missing key is 401/403')
        && str_contains($howtoFlat, 'empty allowlist denies everything')
        && str_contains($howtoFlat, 'empty disables `/queue/trigger` entirely (returns 404)')
);

$check(
    'negative: obsolete single-batch, processed-response, and host-cron claims are absent',
    !str_contains($howto, 'tasks per invocation and executes them')
        && !str_contains($howto, '{"processed": N}')
        && !str_contains($howto, 'exactly how the reference deployment runs it')
        && str_contains($howtoFlat, 'No host cron is needed.')
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
