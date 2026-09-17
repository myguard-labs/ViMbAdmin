<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.ossDate.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== date utility ==\n";

$check('date formats retain their numeric keys', OSS_Date::getDateFormatKeys() === [1, 2, 3, 4, 5]);
$check('unknown format falls back to the requested default', OSS_Date::getFormat(999, OSS_Date::DF_COMPUTER) === 'YYYY-MM-DD');
$check('datepicker format is retained', OSS_Date::getDatepickerFormat(OSS_Date::DF_REVERSE) === 'yy/mm/dd');
$check('PHP format is retained', OSS_Date::getPhpFormat(OSS_Date::DF_COMPACT) === 'Ymd');

$check('European timestamp parsing is retained', OSS_Date::getTimestamp('29/02/2024') === strtotime('29.02.2024'));
$check('compact timestamp parsing is retained', OSS_Date::getTimestamp('20240229', OSS_Date::DF_COMPACT) === strtotime('2024-02-29'));
$check('invalid timestamps remain false', OSS_Date::getTimestamp('not-a-date') === false);

$check('American dates split into day, month, year', OSS_Date::dateSplit('02/29/2024', OSS_Date::DF_AMERICAN) === ['29', '02', '2024']);
$check('compact dates split into day, month, year', OSS_Date::dateSplit('20240229', OSS_Date::DF_COMPACT) === ['29', '02', '2024']);

set_error_handler(static fn (): bool => true, E_WARNING);
$malformed = OSS_Date::dateSplit('02/29', OSS_Date::DF_AMERICAN);
restore_error_handler();
$check('malformed delimited dates retain null missing fields', $malformed === ['29', '02', null]);
$check('short compact dates retain substring fallback values', OSS_Date::dateSplit('2024', OSS_Date::DF_COMPACT) === ['', '', '2024']);

$date = '29 February 2024 15:04:05';
$check('Smarty modifier retains default format and DateTime parsing', smarty_modifier_ossDate($date) === '29/02/2024');
$check('Smarty modifier accepts integer format codes', smarty_modifier_ossDate($date, OSS_Date::DF_COMPUTER) === '2024-02-29');
$check('Smarty modifier accepts numeric-string format codes', smarty_modifier_ossDate($date, '5') === '20240229');
$check('Smarty modifier defaults invalid numeric codes', smarty_modifier_ossDate($date, 999) === '29/02/2024');
$check('Smarty modifier defaults non-canonical numeric strings', smarty_modifier_ossDate($date, '3.0') === '29/02/2024');
$check('Smarty modifier defaults empty formats', smarty_modifier_ossDate($date, '') === '29/02/2024');
$check('Smarty modifier retains custom DateTime formats', smarty_modifier_ossDate($date, 'Y-m-d H:i:s') === '2024-02-29 15:04:05');

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
