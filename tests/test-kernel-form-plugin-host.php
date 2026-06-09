<?php
/**
 * Unit test: ViMbAdmin\Kernel\Plugin\FormPluginHost + the AccessPermissions
 * native mailbox-form extension (Phase 4f of docs/ZF1-REMOVAL.md).
 *
 * Part A drives the host with fixture plugins in a temp dir (loads only the
 * ones implementing ViMbAdmin_Plugin_MailboxFormExtension, honours the disabled
 * switch, aggregates fields/validate/apply). Part B exercises the real
 * AccessPermissions adapter directly (field set, edit pre-fill, the
 * master-checked-but-nothing-selected validation, and the writeback to the
 * mailbox's accessRestriction). No ZF1 MVC, no DB.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Entities\\')) {
        $file = __DIR__ . '/../application/Entities/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) { require $file; }
    }
});

require __DIR__ . '/../src/Kernel/Form/Field.php';
require __DIR__ . '/../src/Kernel/Plugin/FormPluginHost.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MailboxFormExtension.php';

use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Plugin\FormPluginHost;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

// ===================== Part A: FormPluginHost ========================== //
$dir = sys_get_temp_dir() . '/vimb-form-host-' . getmypid();
@mkdir($dir, 0700, true);

// An extension plugin: one checkbox field; errors when 'x' is empty; writes name.
file_put_contents("$dir/Ext.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Ext implements ViMbAdmin_Plugin_MailboxFormExtension
{
    public function __construct($ctx) { $GLOBALS['ext_opts'] = $ctx->getOptions(); }
    public function nativeMailboxFields(?\Entities\Mailbox $m, array $o): array {
        return [ new \ViMbAdmin\Kernel\Form\Field('x', 'X', 'checkbox') ];
    }
    public function nativeMailboxValidate(array $v, array $o): ?string {
        return empty($v['x']) ? 'x required' : null;
    }
    public function nativeMailboxApply(\Entities\Mailbox $m, array $v, array $o, ?object $em = null): void {
        $m->setName($v['x'] ? 'EXT' : 'plain');
    }
}
PHP);

// A plugin that is NOT a form extension (must be ignored by the host).
file_put_contents("$dir/Plain.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Plain { public function __construct($ctx) {} }
PHP);

// A disabled extension plugin: would add a field if loaded.
file_put_contents("$dir/Off.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Off implements ViMbAdmin_Plugin_MailboxFormExtension
{
    public function __construct($ctx) {}
    public function nativeMailboxFields(?\Entities\Mailbox $m, array $o): array { return [ new \ViMbAdmin\Kernel\Form\Field('off','Off') ]; }
    public function nativeMailboxValidate(array $v, array $o): ?string { return null; }
    public function nativeMailboxApply(\Entities\Mailbox $m, array $v, array $o): void {}
}
PHP);

// Opt-in: only Ext is enabled; Off is left off (no enabled flag).
$options = ['vimbadmin_plugins' => [
    'Ext' => ['enabled' => true],
    'Off' => ['enabled' => false],
]];
$host = new FormPluginHost($options, $dir);

check('host keeps only extension, enabled', $host->extensionCount() === 1);
check('host ctor passed options',           ($GLOBALS['ext_opts'] ?? null) === $options);

$fields = $host->fields(null, $options);
check('fields() collects extension field',  count($fields) === 1 && $fields[0]->name === 'x');
check('validate() surfaces the error',      $host->validate(['x' => 0], $options) === 'x required');
check('validate() passes when satisfied',   $host->validate(['x' => 1], $options) === null);

$mb = new \Entities\Mailbox();
$host->apply($mb, ['x' => 1], $options);
check('apply() ran the writeback',          $mb->getName() === 'EXT');

@unlink("$dir/Ext.php"); @unlink("$dir/Plain.php"); @unlink("$dir/Off.php"); @rmdir($dir);

// ============ Part B: AccessPermissions native adapter ================= //
require __DIR__ . '/../library/OSS/Plugin/Observer.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin.php';
require __DIR__ . '/../application/plugins/AccessPermissions.php';

$opts = ['vimbadmin_plugins' => ['AccessPermissions' => ['type' => ['SMTP' => 'SMTP', 'IMAP' => 'IMAP', 'POP3' => 'POP3', 'SIEVE' => 'SIEVE']]]];
$ap = new ViMbAdminPlugin_AccessPermissions((object) ['getOptions' => null]);

// add: master + 4 type checkboxes, all unchecked
$addFields = $ap->nativeMailboxFields(null, $opts);
check('AP add: master + 4 type fields',     count($addFields) === 5);
check('AP add: first is the master',        $addFields[0]->name === 'plugin_accessPermissions' && $addFields[0]->value() === false);
check('AP add: a type field exists',        $addFields[1]->name === 'plugin_accessPermission_SMTP');

// edit: a mailbox restricted to SMTP,IMAP pre-fills master + those two
$mbE = new \Entities\Mailbox();
$mbE->setAccessRestriction('SMTP,IMAP');
$ef = [];
foreach ($ap->nativeMailboxFields($mbE, $opts) as $f) { $ef[$f->name] = $f->value(); }
check('AP edit: master pre-checked',        $ef['plugin_accessPermissions'] === true);
check('AP edit: SMTP pre-checked',          $ef['plugin_accessPermission_SMTP'] === true);
check('AP edit: IMAP pre-checked',          $ef['plugin_accessPermission_IMAP'] === true);
check('AP edit: POP3 NOT checked',          $ef['plugin_accessPermission_POP3'] === false);

// edit: an 'ALL' mailbox leaves the master unchecked
$mbAll = new \Entities\Mailbox();
$mbAll->setAccessRestriction('ALL');
$af = [];
foreach ($ap->nativeMailboxFields($mbAll, $opts) as $f) { $af[$f->name] = $f->value(); }
check('AP edit ALL: master unchecked',      $af['plugin_accessPermissions'] === false);

// validate: master checked, nothing selected -> error; otherwise null
check('AP validate: master+none -> error',  $ap->nativeMailboxValidate(['plugin_accessPermissions' => 1], $opts) !== null);
check('AP validate: master+SMTP -> ok',     $ap->nativeMailboxValidate(['plugin_accessPermissions' => 1, 'plugin_accessPermission_SMTP' => 1], $opts) === null);
check('AP validate: master off -> ok',      $ap->nativeMailboxValidate([], $opts) === null);

// apply: writeback to accessRestriction
$m1 = new \Entities\Mailbox();
$ap->nativeMailboxApply($m1, ['plugin_accessPermissions' => 1, 'plugin_accessPermission_SMTP' => 1, 'plugin_accessPermission_POP3' => 1], $opts);
check('AP apply: restricted = "SMTP,POP3"', $m1->getAccessRestriction() === 'SMTP,POP3');

$m2 = new \Entities\Mailbox();
$ap->nativeMailboxApply($m2, [], $opts);
check('AP apply: unchecked -> ALL',         $m2->getAccessRestriction() === 'ALL');

$m3 = new \Entities\Mailbox();
$ap->nativeMailboxApply($m3, ['plugin_accessPermissions' => 1], $opts);
check('AP apply: checked+none -> ALL',      $m3->getAccessRestriction() === 'ALL');

// ============ Part C: AdditionalInfo native adapter =================== //
require __DIR__ . '/../src/Kernel/Form/Validators.php';
require __DIR__ . '/../application/plugins/AdditionalInfo.php';

$aiOpts = ['vimbadmin_plugins' => ['AdditionalInfo' => ['elements' => [
    'ext_no' => ['type' => 'Zend_Form_Element_Text', 'options' => [
        'label'      => 'Ext No.',
        'required'   => true,
        'validators' => ['digits' => ['Digits', true]],
    ]],
]]]];
$ai = new ViMbAdminPlugin_AdditionalInfo((object) ['getOptions' => null]);

// add: one text field built from the configured element
$aiFields = $ai->nativeMailboxFields(null, $aiOpts);
check('AI add: one field from config',       count($aiFields) === 1);
check('AI add: field name is prefixed',      $aiFields[0]->name === 'plugin_additionalInfo_ext_no');
check('AI add: label from config',           $aiFields[0]->label === 'Ext No.');

// the field's rules: required + Digits
$f = $aiFields[0];
$f->setValue('');     check('AI rule: empty fails (required)', $f->validate() !== null);
$f->setValue('abc');  check('AI rule: non-digits fail',        $f->validate() !== null);
$f->setValue('1234'); check('AI rule: digits pass',            $f->validate() === null);

// no cross-field validation
check('AI validate: always null',            $ai->nativeMailboxValidate(['plugin_additionalInfo_ext_no' => '1234'], $aiOpts) === null);

// no configured elements -> no fields
check('AI add: no elements -> empty',        $ai->nativeMailboxFields(null, ['vimbadmin_plugins' => ['AdditionalInfo' => []]]) === []);

// ============ Part D: DirectoryEntry native adapter ================== //
require __DIR__ . '/../application/plugins/DirectoryEntry.php';

$deOpts = [
    'identity'          => ['orgname' => 'Acme Corp'],
    'vimbadmin_plugins' => ['DirectoryEntry' => ['disabled_elements' => ['CarLicense' => true]]],
];
$de = new ViMbAdminPlugin_DirectoryEntry((object) ['getOptions' => null]);

$deFields = $de->nativeMailboxFields(null, $deOpts);
$byName   = [];
foreach ($deFields as $f) { $byName[$f->name] = $f; }
check('DE add: many fields built',           count($deFields) >= 20);
check('DE add: disabled CarLicense dropped',  !isset($byName['plugin_directoryEntry_CarLicense']));
check('DE add: O defaults to orgname',        $byName['plugin_directoryEntry_O']->value() === 'Acme Corp');
check('DE add: HomePostalAddress is textarea', $byName['plugin_directoryEntry_HomePostalAddress']->type === 'textarea');
check('DE validate: always null',            $de->nativeMailboxValidate([], $deOpts) === null);

// apply: creates + persists a DirectoryEntry, sets mail + the mapped attributes
$deEm = new class {
    public array $persisted = [];
    public function persist(object $o): void { $this->persisted[] = $o; }
};
$mbD = new \Entities\Mailbox();
$mbD->setUsername('dir@example.com');
$de->nativeMailboxApply($mbD, [
    'plugin_directoryEntry_GivenName' => 'Ada',
    'plugin_directoryEntry_Sn'        => 'Lovelace',
    'plugin_directoryEntry_O'         => 'Acme Corp',
], $deOpts, $deEm);

$dent = $mbD->getDirectoryEntry();
check('DE apply: entity created + linked',    $dent instanceof \Entities\DirectoryEntry && $dent->getMailbox() === $mbD);
check('DE apply: persisted via em',           in_array($dent, $deEm->persisted, true));
check('DE apply: mail = username',            $dent->getMail() === 'dir@example.com');
check('DE apply: GivenName written',          $dent->getGivenName() === 'Ada');
check('DE apply: Sn written',                 $dent->getSn() === 'Lovelace');

echo "\n";
if ($failures === 0) {
    echo "OK: all FormPluginHost + AccessPermissions + AdditionalInfo + DirectoryEntry assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
