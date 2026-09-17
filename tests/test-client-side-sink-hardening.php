<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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
    $check(
        $path . ' inserts the confirmation label as text',
        is_string($source) && str_contains($source, $safeCall)
    );
}

$mailboxListPath = getenv('VIMBADMIN_MAILBOX_LIST_SOURCE')
    ?: __DIR__ . '/../application/views/mailbox/js/list.js';
$mailboxList = file_get_contents($mailboxListPath);
$check(
    'mailbox size dialog escapes every dynamic table value',
    is_string($mailboxList)
        && str_contains($mailboxList, 'htmlEntity( mdirsize.toFixed( 5 ) )')
        && str_contains($mailboxList, 'htmlEntity( data[2] )')
        && str_contains($mailboxList, 'htmlEntity( prc.toFixed(0) )')
        && str_contains($mailboxList, 'htmlEntity( data[4] )')
);

$emailSettingsPath = __DIR__ . '/../application/views/mailbox/native-email-settings.phtml';
$emailSettings = file_get_contents($emailSettingsPath);
$checkoutCompileDir = __DIR__ . '/../var/templates_c';
$snapshotCompileDir = static function (string $directory): array {
    $snapshot = [];
    foreach (glob($directory . '/*') ?: [] as $path) {
        $isFile = is_file($path);
        $snapshot[basename($path)] = [
            $isFile,
            filemtime($path),
            $isFile ? filesize($path) : null,
            $isFile ? hash_file('sha256', $path) : null,
        ];
    }
    return $snapshot;
};
$checkoutCompileSnapshot = $snapshotCompileDir($checkoutCompileDir);
$emailSettingsCompileDir = sys_get_temp_dir() . '/vimbadmin-email-settings-' . bin2hex(random_bytes(16));
if (!mkdir($emailSettingsCompileDir, 0700)) {
    throw new RuntimeException("Unable to create temporary compile directory: {$emailSettingsCompileDir}");
}
$removeEmailSettingsCompileDir = static function () use ($emailSettingsCompileDir): void {
    if (!is_dir($emailSettingsCompileDir)) {
        return;
    }
    $smarty = new Smarty\Smarty();
    $smarty->setCompileDir($emailSettingsCompileDir);
    $smarty->clearCompiledTemplate();
    rmdir($emailSettingsCompileDir);
};
register_shutdown_function($removeEmailSettingsCompileDir);
$renderEmailSettings = static function (string $selectedType) use (
    $emailSettingsPath,
    $emailSettingsCompileDir
): ?string {
    try {
        $smarty = new Smarty\Smarty();
        $smarty->setTemplateDir(dirname($emailSettingsPath));
        $smarty->setCompileDir($emailSettingsCompileDir);
        $smarty->setForceCompile(true);
        // Constant-output test stub: rendering only needs the URL tag to compile.
        $smarty->registerPlugin('function', 'genUrl', static fn (array $params): string => '/fixture');
        $smarty->assign([
            'mailbox' => new class () {
                public function requiredUsername(): string
                {
                    return 'fixture@example.test';
                }
                public function getId(): int
                {
                    return 1;
                }
            },
            'esError' => false,
            'typeOptions' => [
                'username' => 'fixture@example.test',
                'alt_email' => 'alternate@example.test',
                'other' => 'Other',
            ],
            'selectedType' => $selectedType,
            'csrfToken' => 'fixture-token',
            'emailValue' => '',
        ]);
        return $smarty->fetch(basename($emailSettingsPath));
    } catch (Throwable $exception) {
        $message = preg_replace('/\\s+/', ' ', $exception->getMessage()) ?? 'unknown error';
        error_log(sprintf(
            'email-settings Smarty render failed for %s (%s): %.240s',
            $selectedType,
            get_debug_type($exception),
            $message
        ));
        return null;
    }
};
$renderFailedOrHasRequiredClass = static function (?string $html, string $selectedType): bool {
    if ($html === null) {
        return true;
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $parseErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $parseErrors !== []) {
        if ($parseErrors === []) {
            error_log("email-settings HTML parse failed for {$selectedType} without a libxml diagnostic");
        }
        foreach (array_slice($parseErrors, 0, 3) as $parseError) {
            $message = preg_replace('/\\s+/', ' ', trim($parseError->message)) ?? 'unknown error';
            error_log(sprintf(
                'email-settings HTML parse failed for %s (code %d, line %d): %.160s',
                $selectedType,
                $parseError->code,
                $parseError->line,
                $message
            ));
        }
        return true;
    }
    foreach ((new DOMXPath($document))->query('//*[@class]') ?: [] as $element) {
        if (!$element instanceof DOMElement) {
            continue;
        }
        $tokens = preg_split('/\\s+/', trim($element->getAttribute('class')));
        if (is_array($tokens) && in_array('required', $tokens, true)) {
            return true;
        }
    }
    return false;
};
$check(
    'email-settings modal emits native required constraints',
    is_string($emailSettings)
        && str_contains($emailSettings, '<select name="type" id="type" class="form-select" required')
        && str_contains($emailSettings, 'class="form-control"')
        && str_contains($emailSettings, "{if \$selectedType == 'other'} required{/if}")
);
foreach (['username', 'alt_email', 'other'] as $selectedType) {
    $check(
        "email-settings {$selectedType} render omits legacy required class",
        !$renderFailedOrHasRequiredClass($renderEmailSettings($selectedType), $selectedType)
    );
}
$removeEmailSettingsCompileDir();
clearstatcache();
$check(
    'email-settings rendering leaves checkout compile output unchanged',
    $snapshotCompileDir($checkoutCompileDir) === $checkoutCompileSnapshot
);
$check(
    'email-settings rendering removes temporary compile output',
    !file_exists($emailSettingsCompileDir)
);
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
$check(
    'email-settings modal validates before AJAX in the same save handler',
    preg_match(
        '/if\( !form\[0\]\.reportValidity\(\) \)\s*return;[\s\S]*?ossAjax\(\{/',
        $emailSettingsSaveHandler
    ) === 1
);
$check(
    'email-settings modal tracks conditional email requirement',
    is_string($mailboxList)
        && str_contains($mailboxList, "DataTable.Dom.select( '#email' ).prop( 'required', other );")
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
