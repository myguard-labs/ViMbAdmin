<?php

/** Regression for PHP-07: native plugin fields retain valid string "0". */
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Entities\\')) {
        require __DIR__ . '/../application/Entities/' . substr($class, 9) . '.php';
    }
});

require __DIR__ . '/../application/plugins/AdditionalInfo.php';
require __DIR__ . '/../application/plugins/DirectoryEntry.php';

use ViMbAdmin\Kernel\Form\Field;

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @param array<int,Field> $fields */
function pluginZeroField(array $fields, string $name): Field
{
    foreach ($fields as $field) {
        if ($field->name === $name) {
            return $field;
        }
    }

    throw new RuntimeException('Missing native plugin field: ' . $name);
}

final class PluginZeroMailbox extends \Entities\Mailbox
{
    /** @return list<\Entities\MailboxPreference> */
    public function _getPreferences(): array
    {
        // Replace only the DQL read; retain the real preference accessors.
        return array_values($this->getPreferences()->toArray());
    }
}

function pluginZeroMailbox(): \Entities\Mailbox
{
    $mailbox = new PluginZeroMailbox();
    // An attached preference takes the inherited update/read path without a DB.
    $preference = (new \Entities\MailboxPreference())
        ->setAttribute('xpiInfo.sample')->setIx(0)->setOp('=')
        ->setValue('previous')->setExpire(0)->setMailbox($mailbox);
    $mailbox->addPreference($preference);

    return $mailbox;
}

$context = new stdClass();
$additional = new ViMbAdminPlugin_AdditionalInfo($context);
$options = ['vimbadmin_plugins' => ['AdditionalInfo' => ['elements' => [
    'sample' => ['options' => ['label' => 'Sample']],
]]]];

foreach (['ordinary' => 'hello', 'zero' => '0', 'blank' => ''] as $label => $value) {
    $mailbox = pluginZeroMailbox();
    $additional->nativeMailboxApply($mailbox, ['plugin_additionalInfo_sample' => $value], $options);
    $check('AdditionalInfo ' . $label . ' real preference retains input', $mailbox->getPreference('xpiInfo.sample') === $value);
    $field = pluginZeroField($additional->nativeMailboxFields($mailbox, $options), 'plugin_additionalInfo_sample');
    $expected = $value === '' ? null : $value;
    $check('AdditionalInfo ' . $label . ' prefill', $field->value() === $expected);
    $check('AdditionalInfo ' . $label . ' field accepts value', $field->validate() === null);
}

foreach (['null mailbox' => null, 'missing preference' => new PluginZeroMailbox()] as $label => $mailbox) {
    $field = pluginZeroField($additional->nativeMailboxFields($mailbox, $options), 'plugin_additionalInfo_sample');
    $check('AdditionalInfo ' . $label . ' stays unset', $field->value() === null);
}

foreach (['integer' => 0, 'false' => false, 'null' => null, 'array' => [], 'object' => new stdClass()] as $label => $value) {
    $mailbox = pluginZeroMailbox();
    $message = null;
    try {
        $additional->nativeMailboxApply($mailbox, ['plugin_additionalInfo_sample' => $value], $options);
    } catch (TypeError $exception) {
        $message = $exception->getMessage();
    }
    $check('AdditionalInfo rejects ' . $label, $message === 'Additional-info values must be strings.');
    $check('AdditionalInfo ' . $label . ' rejection preserves preference', $mailbox->getPreference('xpiInfo.sample') === 'previous');
}

$required = ['vimbadmin_plugins' => ['AdditionalInfo' => ['elements' => [
    'sample' => ['options' => ['required' => true, 'validators' => ['Digits']]],
]]]];
$mailbox = pluginZeroMailbox();
$additional->nativeMailboxApply($mailbox, ['plugin_additionalInfo_sample' => '0'], $required);
$field = pluginZeroField($additional->nativeMailboxFields($mailbox, $required), 'plugin_additionalInfo_sample');
$check('AdditionalInfo required digits retains zero', $field->value() === '0' && $field->validate() === null);

$directory = new ViMbAdminPlugin_DirectoryEntry($context);
foreach (['ordinary' => 'Acme', 'zero' => '0', 'blank' => '', 'absent' => null] as $label => $value) {
    $field = pluginZeroField($directory->nativeMailboxFields(null, ['identity' => ['orgname' => $value]]), 'plugin_directoryEntry_O');
    $expected = $value === '' ? null : $value;
    $check('DirectoryEntry ' . $label . ' organization default', $field->value() === $expected);
}
$field = pluginZeroField($directory->nativeMailboxFields(null, []), 'plugin_directoryEntry_O');
$check('DirectoryEntry missing identity stays unset', $field->value() === null);

foreach (['ordinary' => 'Kept', 'zero' => '0'] as $label => $value) {
    $mailbox = new \Entities\Mailbox();
    $mailbox->setDirectoryEntry((new \Entities\DirectoryEntry())->setO($value));
    $field = pluginZeroField($directory->nativeMailboxFields($mailbox, ['identity' => ['orgname' => 'Default']]), 'plugin_directoryEntry_O');
    $check('DirectoryEntry existing ' . $label . ' precedes default', $field->value() === $value);
}

foreach (['integer' => 0, 'false' => false, 'array' => [], 'object' => new stdClass()] as $label => $value) {
    $message = null;
    try {
        $directory->nativeMailboxFields(null, ['identity' => ['orgname' => $value]]);
    } catch (TypeError $exception) {
        $message = $exception->getMessage();
    }
    $check('DirectoryEntry rejects ' . $label . ' organization', $message === 'identity orgname must be a string');
}

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
