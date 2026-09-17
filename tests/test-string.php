<?php

require __DIR__ . '/../library/OSS/String.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== OSS string ==\n";

$check('multibyte ucfirst preserves the tail', OSS_String::mb_ucfirst('éCOLE', 'UTF-8') === 'ÉCOLE');
$check('multibyte words use the requested encoding', OSS_String::mb_ucwords('élan vital', 'UTF-8') === 'Élan Vital');
$check('multibyte replacement replaces every match', OSS_String::mb_str_replace('ø', 'oe', 'rødgrød') === 'roedgroed');

$random = OSS_String::randomFromSet('ø', 5);
$check('multibyte random selection preserves characters', $random === 'øøøøø');
$check('zero-length random selection is empty', OSS_String::randomFromSet('abc', 0) === '');
$check('configured random exclusions are respected', OSS_String::random(8, false, false, false, 'ab', 'b') === 'aaaaaaaa');

$invalidEncodingRejected = false;
try {
    OSS_String::randomFromSet("\xFF", 1);
} catch (\TypeError $e) {
    $invalidEncodingRejected = true;
}
$check('invalid UTF-8 character sets are rejected', $invalidEncodingRejected);

$check('field names retain the compatibility prefix', OSS_String::toValidFieldName(' Postal Code ') === 'cf_postal_code');
$check('empty field names remain safely prefixed', OSS_String::toValidFieldName('') === 'cf_');
$check('normalisation strips accents and spaces', OSS_String::normalise('Héllo World!') === 'helloworld');
$check('normalisation can retain spaces', OSS_String::normalise('Hello World!', true) === 'hello world');

$slashed = OSS_String::stripSlashes(['quoted' => "it\\'s", 'truthy' => true, 'nested' => [(object) ['value' => 'a\\"b']]]);
$check('slash removal preserves recursive compatibility', $slashed === ['quoted' => "it's", 'truthy' => '1', 'nested' => [['value' => 'a"b']]]);

$decoded = OSS_String::htmlEntityDecode(['quoted' => '&quot;ok&quot;', 'truthy' => true, 'nested' => [(object) ['value' => '&amp;']]]);
$check('entity decoding preserves recursive compatibility', $decoded === ['quoted' => '"ok"', 'truthy' => '1', 'nested' => [['value' => '&']]]);

$mac = OSS_String::randomMacAddress(true);
$check('MAC generation preserves uppercase format', preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) === 1);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_String assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
