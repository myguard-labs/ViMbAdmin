<?php

declare(strict_types=1);

require __DIR__ . '/../src/Kernel/Form/Field.php';
require __DIR__ . '/../src/Kernel/Form/Validators.php';
require __DIR__ . '/../library/OSS/Filter/FileSize.php';

use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Form\Validators;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return Field */
$quotaField = static fn (string $name): Field => new Field(
    $name,
    'Quota',
    'text',
    [Validators::string(), Validators::nonNegativeNumber()],
);

echo "== quota form validation ==\n";

$affectedFields = [
    'mailbox edit quota' => 'quota',
    'mailbox add quota' => 'quota',
    'domain edit quota' => 'quota',
    'domain edit max_quota' => 'max_quota',
    'domain add quota' => 'quota',
    'domain add max_quota' => 'max_quota',
];
$renderedValues = [20, 10240, 1024000, 1073741824, 0];
$filter = new OSS_Filter_FileSize(OSS_Filter_FileSize::SIZE_BYTES);

foreach ($affectedFields as $context => $name) {
    foreach ($renderedValues as $bytes) {
        $rendered = (string) OSS_Filter_FileSize::unfilter($bytes);
        $field = $quotaField($name);
        $field->setValue($rendered);
        $parsed = $filter->filter($rendered);
        $check(
            "{$context} accepts rendered value {$rendered}",
            $field->validate() === null && (int) $parsed === $bytes,
        );
    }
}

$validBoundaries = [
    'empty optional value' => '',
    'zero bytes' => '0B',
    'fractional kilobytes' => '.5KB',
    'trailing decimal point' => '1.',
    'lower-case short unit' => '2m',
    'spaces accepted by file-size filter' => '1 K B',
    'largest practical integer-safe byte size' => '9223372036854774784B',
];
foreach ($validBoundaries as $label => $value) {
    $field = $quotaField('quota');
    $field->setValue($value);
    $check($label, $field->validate() === null);
}
$nearLimit = $filter->filter('9223372036854774784B');
$check(
    'largest practical integer-safe byte size converts without wrapping',
    is_float($nearLimit) && is_finite($nearLimit) && (int) $nearLimit === 9223372036854774784,
);

$invalidValues = [
    'negative number' => '-1',
    'negative formatted size' => '-1KB',
    'positive sign' => '+1KB',
    'infinity' => 'INF',
    'not-a-number token' => 'NAN',
    'non-finite exponent' => '1e309',
    'unsupported unit' => '1TB',
    'multiple decimal points' => '1.2.3MB',
    'missing number' => 'KB',
    'embedded tab' => "1\tKB",
    'PHP integer overflow boundary' => '8589934592GB',
    'overflowing explicit size' => str_repeat('9', 400) . 'GB',
];
foreach ($invalidValues as $label => $value) {
    $field = $quotaField('quota');
    $field->setValue($value);
    $check("rejects {$label}", $field->validate() !== null);
}

$typedField = $quotaField('quota');
$typedField->setValue([]);
$check('rejects non-scalar input', $typedField->validate() !== null);

$mailboxSource = file_get_contents(__DIR__ . '/../src/Kernel/Controller/MailboxController.php');
$domainSource = file_get_contents(__DIR__ . '/../src/Kernel/Controller/DomainController.php');
$check(
    'both mailbox quota forms use the shared validator',
    is_string($mailboxSource) && substr_count($mailboxSource, 'Validators::nonNegativeNumber()') === 2,
);
$check(
    'all four domain quota fields use the shared validator',
    is_string($domainSource) && substr_count($domainSource, 'Validators::nonNegativeNumber()') === 4,
);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all quota form validation assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} quota form validation assertion(s) failed\n";
exit($exitCode);
