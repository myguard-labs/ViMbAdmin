<?php

require __DIR__ . '/../vendor/autoload.php';

final class OSS_Utils_TestRecorder
{
    /** @var array{0: string|false, 1: string|false, 2: string|false, 3: array<string, mixed>}|null */
    public static ?array $genUrlArguments = null;
}

final class OSS_Utils_TestDouble
{
    /**
     * @param string|false $controller
     * @param string|false $action
     * @param string|false $module
     * @param array<string, mixed> $params
     */
    public static function genUrl($controller, $action, $module, array $params): string
    {
        OSS_Utils_TestRecorder::$genUrlArguments = [$controller, $action, $module, $params];

        return '/generated';
    }
}

class_alias(OSS_Utils_TestDouble::class, 'OSS_Utils');

require __DIR__ . '/../library/OSS/Smarty/functions/function.currency.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.customdate.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.genUrl.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$failures = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '         expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n";
    }
};

echo "== Smarty functions ==\n";

$smarty = new \Smarty\Smarty();

$check('currency defaults to zero euros', smarty_function_currency([], $smarty), '&euro;0.00');
$check('currency keeps sign before custom symbol', smarty_function_currency(['value' => -12.345, 'currency' => '$'], $smarty), '-$12.35');
$check('currency accepts numeric strings', smarty_function_currency(['value' => '2.5'], $smarty), '&euro;2.50');
$check('currency zero has no negative sign', smarty_function_currency(['value' => 0, 'currency' => 'GBP '], $smarty), 'GBP 0.00');
$nonNumericRejected = false;
try {
    $invalidCurrencyParams = ['value' => 'not numeric'];
    (new ReflectionFunction('smarty_function_currency'))->invokeArgs([$invalidCurrencyParams, &$smarty]);
} catch (TypeError) {
    $nonNumericRejected = true;
}
$check('currency rejects non-numeric strings', $nonNumericRejected, true);

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');
$check('customdate formats an explicit epoch', smarty_function_customdate(['format' => 'Y-m-d H:i:s', 'now' => 0], $smarty), '1970-01-01 00:00:00');
$check('customdate accepts a numeric-string timestamp', smarty_function_customdate(['format' => 'Y-m-d', 'offset' => '+1 day', 'now' => '0'], $smarty), '1970-01-02');
$check('customdate keeps strtotime failure coercion', smarty_function_customdate(['format' => 'Y-m-d', 'offset' => 'not a real offset', 'now' => 123], $smarty), '1970-01-01');
$check('customdate default format', preg_match('/^\d{4}-\d{2}-\d{2}$/D', smarty_function_customdate([], $smarty)), 1);
date_default_timezone_set($previousTimezone);

$check('genUrl default result', smarty_function_genUrl([], $smarty), '/generated');
$check('genUrl defaults route parts and params', OSS_Utils_TestRecorder::$genUrlArguments, [false, false, false, []]);
$check(
    'genUrl normalizes default module and preserves extra params',
    smarty_function_genUrl(['controller' => 'mailbox', 'action' => 'list', 'module' => 'default', 'mid' => 7], $smarty),
    '/generated'
);
$check('genUrl forwards normalized route and extra params', OSS_Utils_TestRecorder::$genUrlArguments, ['mailbox', 'list', false, ['mid' => 7]]);
$check(
    'genUrl preserves an explicit module',
    smarty_function_genUrl(['controller' => 'shared-calendar', 'module' => 'davical', 'flag' => false], $smarty),
    '/generated'
);
$check('genUrl forwards explicit module and false extra value', OSS_Utils_TestRecorder::$genUrlArguments, ['shared-calendar', false, 'davical', ['flag' => false]]);

echo $failures === 0
    ? "OK: all Smarty function assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
