<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};

final class TaggedDoveadmFixture extends ViMbAdmin_Doveadm
{
    public string $mode = 'matching';

    public function __construct()
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'fixture-key', 1);
    }

    /** @return array{int,string} */
    protected function _post($payload)
    {
        $request = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
        $tag = $request[0][2];
        $rows = [['name' => 'INBOX']];
        $body = match ($this->mode) {
            'matching' => [['doveadmResponse', $rows, $tag]],
            'wrong' => [['doveadmResponse', $rows, $tag . '-wrong']],
            'interleaved' => [
                ['doveadmResponse', [['name' => 'other']], 'other-tag'],
                ['doveadmResponse', $rows, $tag],
            ],
            'truncated' => [['doveadmResponse', $rows]],
            'malformed' => [['doveadmResponse', $rows, ['not-a-tag']]],
            default => throw new LogicException('unknown fixture mode'),
        };
        return [200, json_encode($body, JSON_THROW_ON_ERROR)];
    }
}

$client = new TaggedDoveadmFixture();
$check('positive: a response carrying the request tag succeeds', $client->run('mailbox list') === [['name' => 'INBOX']]);

foreach (['wrong', 'interleaved', 'truncated', 'malformed'] as $mode) {
    $client->mode = $mode;
    try {
        $client->run('mailbox list');
        $rejected = false;
    } catch (ViMbAdmin_Exception) {
        $rejected = true;
    }
    $check("negative: {$mode} response cannot satisfy the command", $rejected);
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
