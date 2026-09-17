<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Filter/FileSize.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @param int|float|string $value */
function render(OSS_Filter_FileSize $filter, $value): int|float|string|false
{
    return $filter->filter($value);
}

echo "== OSS file-size filter ==\n";

$filter = new OSS_Filter_FileSize();
$check('default multiplier is bytes', $filter->getMultiplier() === OSS_Filter_FileSize::SIZE_BYTES);
$check('two-letter suffix is case-insensitive', $filter->filter('10Kb') === 10240.0);
$check('one-letter suffix implies bytes', $filter->filter('0.9m') === 943718.4);
$check('spaces in a value are accepted', $filter->filter('1000 KB') === 1024000.0);
$check('numeric integers use the default multiplier', $filter->filter(20) === 20.0);
$check('zero remains integer zero', $filter->filter('0') === 0);
$check('unknown suffix is rejected', $filter->filter('0.978GSM') === false);
$check('multiple decimal points are rejected', $filter->filter('1.2.3MB') === false);

$filter->setMultiplier('mb');
$check('configured multiplier is case-insensitive', $filter->getMultiplier() === OSS_Filter_FileSize::SIZE_MEGABYTES);
$check('configured multiplier applies without suffix', $filter->filter('2') === 2097152.0);

$threw = false;
try {
    $filter->setMultiplier('TB');
} catch (OSS_Exception) {
    $threw = true;
}
$check('unknown configured multiplier throws', $threw);

$check('bytes format with byte unit', OSS_Filter_FileSize::unfilter(20) === '20B');
$check('fractional kilobytes retain precision', OSS_Filter_FileSize::unfilter(102.4) === '0.1KB');
$check('numeric strings are formatted', OSS_Filter_FileSize::unfilter('10240') === '10KB');
$check('megabytes are formatted', OSS_Filter_FileSize::unfilter(1024000) === '0.9765625MB');
$check('gigabytes are formatted', OSS_Filter_FileSize::unfilter(1073741824) === '1GB');
$check('falsey values are returned unchanged', OSS_Filter_FileSize::unfilter(0) === 0);

$renderFilter = new OSS_Filter_FileSize();
$check('render path formats numeric input', render($renderFilter, '10240') === '10KB');
$check('render path preserves non-numeric input', render($renderFilter, 'unlimited') === 'unlimited');

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Filter_FileSize assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
