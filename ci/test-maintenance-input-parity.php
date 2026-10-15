<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ViMbAdmin\Kernel\Input\Reader;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$rejects = static function (callable $operation): bool {
    try {
        $operation();
        return false;
    } catch (LogicException) {
        return true;
    }
};

$check('positive: integer and canonical decimal IDs are accepted', Reader::positiveInteger(7, 'id') === 7 && Reader::positiveInteger('7', 'id') === 7);
$check('negative: consumers reject leading-zero coercion', $rejects(fn () => Reader::positiveInteger('007', 'id')));
$check('caller parity: QueueRunner digit strings retain leading-zero semantics', Reader::positiveInteger('007', 'queue id', true) === 7);
$check('boundary: zero and negatives are rejected as positive IDs', $rejects(fn () => Reader::positiveInteger(0, 'id')) && $rejects(fn () => Reader::positiveInteger(-1, 'id')));
$check('overflow: decimal values above PHP_INT_MAX are rejected', $rejects(fn () => Reader::positiveInteger((string) PHP_INT_MAX . '0', 'id')));
$check('malformed: arrays, NULs, signs, and whitespace do not coerce', array_reduce(
    [[], "1\0", '+1', ' 1', '1 '],
    fn (bool $ok, mixed $value): bool => $ok && $rejects(fn () => Reader::positiveInteger($value, 'id')),
    true,
));

$options = ['doveadm' => ['maildir_root' => '/srv/mail'], 'queue' => ['runner' => ['max_concurrent' => '007']]];
$check('config: string reader returns the exact configured value', Reader::optionString($options, '/default', 'doveadm', 'maildir_root') === '/srv/mail');
$check('config: missing string reader returns its caller default', Reader::optionString($options, '/default', 'doveadm', 'missing') === '/default');
$check('config: QueueRunner numeric reader retains digit-only input', Reader::optionNonNegativeInteger($options, 1, true, 'queue', 'runner', 'max_concurrent') === 7);
$check(
    'config error: scalar intermediate paths and non-string leaves are rejected',
    $rejects(fn () => Reader::optionString(['doveadm' => 'bad'], '', 'doveadm', 'url'))
    && $rejects(fn () => Reader::optionString(['doveadm' => ['url' => []]], '', 'doveadm', 'url')),
);

$consumer = file_get_contents(dirname(__DIR__) . '/library/ViMbAdmin/Service/QueueRunner.php');
$check(
    'deduplication: service consumer has no copied positive/config parser',
    is_string($consumer)
    && !str_contains($consumer, 'private static function positiveInteger')
    && !str_contains($consumer, 'private function option(string ...$path)'),
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
