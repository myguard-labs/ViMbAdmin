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
    'application/views/admin/js/domains.js' => '$( "#purge_domain_name" ).text( element.attr( "ref" ) );',
    'application/views/admin/js/list.js' => "$( \"#purge_admin_name\" ).text( element.attr( 'ref' ) );",
    'application/views/domain/js/admins.js' => '$( "#purge_admin_name" ).text( element.attr( "ref" ) );',
    'application/views/domain/js/list.js' => '$( "#purge_domain_name" ).text( domain );',
    'application/views/mailbox/js/aliases.js' => "$( \"#purge_alias_name\" ).text( element.attr( 'ref' ) );",
];
foreach ($textSinks as $path => $safeCall) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    $check($path . ' inserts the confirmation label as text',
        is_string($source) && str_contains($source, $safeCall));
}

$mailboxList = file_get_contents(__DIR__ . '/../application/views/mailbox/js/list.js');
$check('mailbox size dialog escapes every dynamic table value',
    is_string($mailboxList)
        && str_contains($mailboxList, 'htmlEntity( mdirsize.toFixed( 5 ) )')
        && str_contains($mailboxList, 'htmlEntity( data[2] )')
        && str_contains($mailboxList, 'htmlEntity( prc.toFixed(0) )')
        && str_contains($mailboxList, 'htmlEntity( data[4] )'));

$emailSettings = file_get_contents(__DIR__ . '/../application/views/mailbox/native-email-settings.phtml');
$check('email-settings modal emits native required constraints',
    is_string($emailSettings)
        && str_contains($emailSettings, '<select name="type" id="type" class="form-select" required')
        && str_contains($emailSettings, 'class="form-control"')
        && str_contains($emailSettings, "{if \$selectedType == 'other'} required{/if}")
        && !str_contains($emailSettings, 'class="required"'));
$check('email-settings modal validates before AJAX and tracks conditional email requirement',
    is_string($mailboxList)
        && str_contains($mailboxList, "jQuery( '#email' ).prop( 'required', other );")
        && str_contains($mailboxList, 'if( !form[0].reportValidity() )')
        && strpos($mailboxList, 'if( !form[0].reportValidity() )') < strpos($mailboxList, 'jQuery.ajax({'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
