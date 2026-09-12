<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "usage: php tests/render-popup-csp-fixture.php OUTPUT\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Message.php';
require __DIR__ . '/../library/OSS/Message/Pop/Up.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.OSS_Message.php';

final class PopupCspSmartyDouble extends \Smarty\Smarty
{
    /** @param array<string,mixed> $vars */
    public function __construct(private readonly array $vars)
    {
        parent::__construct();
    }

    public function getTemplateVars($varName = null, $searchParents = true): mixed
    {
        return is_string($varName) ? ($this->vars[$varName] ?? null) : null;
    }
}

$nonce = 'popupNonce123+/=';
$popupText = 'Quotes "double" and \'single\' &amp; entity </script><b>tail</b>';
$smarty = new PopupCspSmartyDouble([
    'cspNonce' => $nonce,
    'OSS_Messages' => [new OSS_Message_Pop_Up($popupText, OSS_Message::INFO, false)],
]);
$popup = smarty_function_OSS_Message([], $smarty);
$expected = json_encode(
    $popupText,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
);

$html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="script-src 'nonce-{$nonce}'; object-src 'none'">
<body data-test-result="pending">
<script nonce="{$nonce}">
window.popupCalls = [];
window.ossAlert = function(message) { window.popupCalls.push(message); };
</script>
{$popup}
<script nonce="{$nonce}">
document.addEventListener('DOMContentLoaded', function() {
    var expected = {$expected};
    var passed = window.popupCalls.length === 1 && window.popupCalls[0] === expected;
    document.body.dataset.testResult = passed ? 'pass' : 'fail';
    document.body.dataset.testFailures = passed ? '' :
        'nonced popup did not execute once with its exact hostile payload: ' + JSON.stringify(window.popupCalls);
}, { once: true });
</script>
</body>
HTML;

if (file_put_contents($argv[1], $html) === false) {
    fwrite(STDERR, "could not write popup CSP fixture\n");
    exit(1);
}
