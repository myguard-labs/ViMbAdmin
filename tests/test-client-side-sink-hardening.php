<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

echo "== Client-side sink hardening ==\n";

$textSinks = [
    'application/views/admin/js/domains.js' => 'DataTable.Dom.select( "#purge_domain_name" ).text( element.attr( "ref" ) );',
    'application/views/admin/js/list.js' => "DataTable.Dom.select( \"#purge_admin_name\" ).text( element.attr( 'ref' ) );",
    'application/views/domain/js/admins.js' => 'DataTable.Dom.select( "#purge_admin_name" ).text( element.attr( "ref" ) );',
    'application/views/domain/js/list.js' => 'DataTable.Dom.select( "#purge_domain_name" ).text( domain );',
    'application/views/mailbox/js/aliases.js' => "DataTable.Dom.select( \"#purge_alias_name\" ).text( element.attr( 'ref' ) );",
];
foreach ($textSinks as $path => $safeCall) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    $check($path . ' inserts the confirmation label as text',
        is_string($source) && str_contains($source, $safeCall));
}

$mailboxListPath = getenv('VIMBADMIN_MAILBOX_LIST_SOURCE')
    ?: __DIR__ . '/../application/views/mailbox/js/list.js';
$mailboxList = file_get_contents($mailboxListPath);
$check('mailbox size dialog escapes every dynamic table value',
    is_string($mailboxList)
        && str_contains($mailboxList, 'htmlEntity( mdirsize.toFixed( 5 ) )')
        && str_contains($mailboxList, 'htmlEntity( data[2] )')
        && str_contains($mailboxList, 'htmlEntity( prc.toFixed(0) )')
        && str_contains($mailboxList, 'htmlEntity( data[4] )'));

$emailSettings = file_get_contents(__DIR__ . '/../application/views/mailbox/native-email-settings.phtml');
$emailSettingsHasLegacyRequiredClass = false;
if (is_string($emailSettings)
    && preg_match_all('/\\bclass=(["\'])(.*?)\\1/', $emailSettings, $classAttributes)) {
    foreach ($classAttributes[2] as $classAttribute) {
        $classTokens = preg_split('/\\s+/', trim($classAttribute));
        if ($classTokens !== false && in_array('required', $classTokens, true)) {
            $emailSettingsHasLegacyRequiredClass = true;
            break;
        }
    }
}
$check('email-settings modal emits native required constraints',
    is_string($emailSettings)
        && str_contains($emailSettings, '<select name="type" id="type" class="form-select" required')
        && str_contains($emailSettings, 'class="form-control"')
        && str_contains($emailSettings, "{if \$selectedType == 'other'} required{/if}")
        && !$emailSettingsHasLegacyRequiredClass);
$emailSettingsSaveHandler = '';
if (is_string($mailboxList)
    && preg_match(
        "/DataTable\\.Dom\\.select\\( document \\)\\.on\\( 'click', '#modal_dialog_save', function\\(\\) \\{"
            . "(?<handler>.*?)^\\} \\);/ms",
        $mailboxList,
        $saveHandlerMatch
    ) === 1
) {
    $emailSettingsSaveHandler = $saveHandlerMatch['handler'];
}
$check('email-settings modal validates before AJAX in the same save handler',
    preg_match(
        '/if\( !form\[0\]\.reportValidity\(\) \)\s*return;[\s\S]*?ossAjax\(\{/',
        $emailSettingsSaveHandler
    ) === 1);
$check('email-settings modal tracks conditional email requirement',
    is_string($mailboxList)
        && str_contains($mailboxList, "DataTable.Dom.select( '#email' ).prop( 'required', other );"));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
