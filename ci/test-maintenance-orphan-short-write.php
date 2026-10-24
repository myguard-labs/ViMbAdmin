<?php

declare(strict_types=1);

namespace MaintenanceOrphanShortWriteProbe;

// Exercise the exact generated responder loop without sockets, timing or a DB.
$source = file_get_contents(__DIR__ . '/test-maintenance-orphan-workflow.php');
if ($source === false || preg_match("/<<<'PHP'\\R(.*?)\\RPHP\\R/s", $source, $match) !== 1) {
    throw new \RuntimeException('Cannot locate generated orphan responder source');
}
$server = $match[1];
$start = strpos($server, '    $written = 0;');
$end = strpos($server, "    if (\$response === 'oversized')", $start === false ? 0 : $start);
if ($start === false || $end === false || substr_count($server, '    $written = 0;') !== 1) {
    throw new \RuntimeException('Cannot locate exact orphan responder write loop');
}
$block = substr($server, $start, $end - $start);
$send = eval('namespace ' . __NAMESPACE__ . '; return static function (RecordingWriter $client, string $body): int {'
    . $block . 'return $written;};');
if (!$send instanceof \Closure) {
    throw new \RuntimeException('Cannot load exact orphan responder write loop');
}

final class RecordingWriter
{
    public string $output = '';
    public int $calls = 0;

    /** @param non-empty-list<int|false> $limits */
    public function __construct(private array $limits)
    {
    }

    public function write(string $data): int|false
    {
        $limit = $this->limits[$this->calls % count($this->limits)];
        $this->calls++;
        if ($limit === false) {
            return false;
        }
        $bytes = min($limit, strlen($data));
        $this->output .= substr($data, 0, $bytes);
        return $bytes;
    }
}

// Namespace-local controls preserve fwrite's positive-count/zero/false contract.
function fwrite(RecordingWriter $client, string $data): int|false
{
    return $client->write($data);
}

function usleep(int $microseconds): void
{
}

$failures = 0;
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'ok ' : 'FAIL ') . $label . "\n";
    $failures += $passed ? 0 : 1;
};
$payload = str_repeat(implode('', array_map(chr(...), range(0, 255))), 65) . 'tail-marker';
foreach ([1, 8192, 8193, strlen($payload)] as $length) {
    $body = substr($payload, 0, $length);
    foreach ([[8192], [3], [7, 1, 4096, 17]] as $limits) {
        $writer = new RecordingWriter($limits);
        $written = $send($writer, $body);
        $check(
            'generated responder preserves every body byte length=' . $length . ' writes=' . implode(',', $limits),
            $writer->output === $body && $written === $length
        );
    }
}
foreach ([['zero', [0], 0, 1], ['false', [false], 0, 1], ['short then zero', [3, 0], 3, 2], ['short then false', [5, false], 5, 2]] as [$label, $limits, $bytes, $calls]) {
    $writer = new RecordingWriter($limits);
    $written = $send($writer, $payload);
    $check(
        'generated responder terminates on ' . $label,
        $written === $bytes && $writer->output === substr($payload, 0, $bytes) && $writer->calls === $calls
    );
}
$writer = new RecordingWriter([3]);
$check('empty response performs no writes', $send($writer, '') === 0 && $writer->calls === 0);
if ($failures !== 0) {
    throw new \RuntimeException($failures . ' orphan responder short-write assertions failed');
}
echo 'OK: all orphan responder short-write assertions passed (PHP ' . PHP_VERSION . ")\n";
