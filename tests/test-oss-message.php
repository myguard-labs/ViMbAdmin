<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Message.php';
require __DIR__ . '/../library/OSS/Message/Block.php';
require __DIR__ . '/../library/OSS/Message/Pop/Up.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.OSS_Message.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};

final class MessageSmartyDouble extends \Smarty\Smarty
{
    /** @var array<string,mixed> */
    private array $vars;

    /** @param array<string,mixed> $vars */
    public function __construct(array $vars)
    {
        parent::__construct();
        $this->vars = $vars;
    }

    public function getTemplateVars($varName = null, $searchParents = true): mixed
    {
        if (!is_string($varName)) {
            return null;
        }

        return $this->vars[$varName] ?? null;
    }
}

$_SESSION = [];

echo "== OSS message ==\n";

$plain = new OSS_Message('<b>Saved</b>', OSS_Message::ALERT, true);
$check('ALERT normalizes to warning', $plain->getClass() === OSS_Message::WARNING);
$check('plaintext strips tags for HTML messages', $plain->getPlaintext() === 'Saved');
$plaintextShapeRejected = false;
try { (new OSS_Message(['not-text']))->getPlaintext(); } catch (\UnexpectedValueException) { $plaintextShapeRejected = true; }
$check('plaintext rejects non-string message content', $plaintextShapeRejected);

$block = new OSS_Message_Block('Block body', OSS_Message::SUCCESS, false);
$check('block type is set', $block->getType() === OSS_Message::TYPE_BLOCK);
$check('block actions default null', $block->getActions() === null);
$block->addAction('<a href="/undo">Undo</a>');
$block->addAction('<a href="/retry">Retry</a>');
$check('block actions preserve order', $block->getActions() === ['<a href="/undo">Undo</a>', '<a href="/retry">Retry</a>']);

$popup = new OSS_Message_Pop_Up(['One', 'Two'], OSS_Message::INFO, false);
$check('popup type is set', $popup->getType() === OSS_Message::TYPE_POP_UP);
$check('popup actions default null', $popup->getActions() === null);

$smarty = new MessageSmartyDouble([
    'cspNonce' => 'test-popup-nonce',
    'OSS_Messages' => [
        $block,
        $popup,
        new OSS_Message(['Alpha', 'Beta'], OSS_Message::ERROR, false),
    ],
]);

$rendered = smarty_function_OSS_Message([], $smarty);
$check('block render includes actions container', str_contains($rendered, 'alert-actions') && str_contains($rendered, 'Undo') && str_contains($rendered, 'Retry'));
$check('popup render emits each native modal item', str_contains($rendered, 'ossAlert( "One" )') && str_contains($rendered, 'ossAlert( "Two" )'));
$check('popup scripts carry the response CSP nonce', substr_count($rendered, 'nonce="test-popup-nonce"') === 2);
$check('rendered alerts use Bootstrap 5 dismissal attributes', str_contains($rendered, 'data-bs-dismiss="alert"') && !str_contains($rendered, 'data-dismiss="alert"'));
$check('plain render emits each message item', str_contains($rendered, 'Alpha') && str_contains($rendered, 'Beta') && str_contains($rendered, 'alert-error'));
$check('default ids remain sequential', str_contains($rendered, 'id="oss-message-0"') && str_contains($rendered, 'id="oss-message-2"'));
preg_match_all('/id="oss-message-([0-9]+)"/', $rendered, $renderedIdMatches);
$renderedIds = $renderedIdMatches[1];
$check('array messages receive unique alert ids', count($renderedIds) === count(array_unique($renderedIds)));

$hostilePopupText = 'Quotes "double" and \'single\' &amp; entity </script><b>tail</b>';
$hostilePopupSmarty = new MessageSmartyDouble([
    'cspNonce' => 'test-popup-nonce',
    'OSS_Messages' => [new OSS_Message_Pop_Up($hostilePopupText, OSS_Message::INFO, false)],
]);
$hostilePopup = smarty_function_OSS_Message([], $hostilePopupSmarty);
$check('popup JSON encoding cannot terminate its script element',
    substr_count($hostilePopup, '</script>') === 1
    && str_contains($hostilePopup, '\\u003C\\/script\\u003E')
    && str_contains($hostilePopup, '\\u0022double\\u0022')
    && str_contains($hostilePopup, '\\u0026amp;'));

$missingPopupNonceRejected = false;
try {
    $missingNonceSmarty = new MessageSmartyDouble([
        'OSS_Messages' => [new OSS_Message_Pop_Up('blocked', OSS_Message::INFO, false)],
    ]);
    smarty_function_OSS_Message([], $missingNonceSmarty);
} catch (InvalidArgumentException $exception) {
    $missingPopupNonceRejected = $exception->getMessage() === 'OSS popup messages require a valid CSP nonce';
}
$check('popup render fails closed without a valid CSP nonce', $missingPopupNonceRejected);

$randomSmarty = new MessageSmartyDouble([
    'OSS_Messages' => [new OSS_Message('Random', OSS_Message::SUCCESS, false)],
]);
$randomRendered = smarty_function_OSS_Message(['randomid' => true], $randomSmarty);
$check('truthy randomid uses a collision-free process counter',
    preg_match('/id="oss-message-([0-9]+)"/', $randomRendered, $randomMatch) === 1
    && (int) $randomMatch[1] > 2);

$emptySmarty = new MessageSmartyDouble([]);
$check('empty message collection renders empty output', smarty_function_OSS_Message(['randomid' => false], $emptySmarty) === '');

$unsafeClassRejected = false;
try {
    $unsafeClassSmarty = new MessageSmartyDouble([
        'OSS_Messages' => [new OSS_Message('unsafe', 'bad" onmouseover="alert(1)', false)],
    ]);
    smarty_function_OSS_Message([], $unsafeClassSmarty);
} catch (InvalidArgumentException $exception) {
    $unsafeClassRejected = $exception->getMessage() === 'OSS message class must be a safe CSS class token';
}
$check('message class injection is rejected before rendering', $unsafeClassRejected);

$flashSession = ['Application' => ['flashMessages' => [['text' => ['not-text'], 'level' => 'error']]]];
$_SESSION = $flashSession;
$unsafeFlashRejected = false;
try {
    $unsafeFlashSmarty = new MessageSmartyDouble([]);
    smarty_function_OSS_Message([], $unsafeFlashSmarty);
} catch (InvalidArgumentException $exception) {
    $unsafeFlashRejected = $exception->getMessage() === 'session flash message text must be a string';
}
$sessionSnapshot = $_SESSION;
$flashRetained = json_encode($sessionSnapshot) === json_encode($flashSession);
$check('malformed session flash is rejected before it is drained', $unsafeFlashRejected && $flashRetained);
unset($_SESSION['Application']);

$legacyAndNative = new MessageSmartyDouble([
    'OSS_Messages' => [new OSS_Message('legacy', OSS_Message::INFO, false)],
]);
$_SESSION = [
    'Application' => [
        'OSS_Messages' => [new OSS_Message('session', OSS_Message::SUCCESS, false)],
        'flashMessages' => [['text' => 'native', 'level' => 'warning', 'isHtml' => true]],
    ],
];
$combined = smarty_function_OSS_Message([], $legacyAndNative);
$check('combined legacy and native queues render once', substr_count($combined, 'legacy') === 1 && substr_count($combined, 'session') === 1 && substr_count($combined, 'native') === 1);
$check('combined queues drain from one shared session snapshot', serialize($_SESSION) === serialize(['Application' => []]));

$_SESSION = ['Application' => ['flashMessages' => [
    ['text' => '<b>raw</b>', 'level' => 'success', 'isHtml' => true],
    ['text' => '<b>escaped</b>', 'level' => 'success', 'isHtml' => false],
    ['text' => '<img src=x onerror=alert(1)>', 'level' => 'error'],
]]];
$escapedFlashSmarty = new MessageSmartyDouble([]);
$escapedFlash = smarty_function_OSS_Message([], $escapedFlashSmarty);
$check('native flash preserves raw HTML only when isHtml is true',
    str_contains($escapedFlash, '<b>raw</b>')
    && str_contains($escapedFlash, '&lt;b&gt;escaped&lt;/b&gt;')
    && !str_contains($escapedFlash, '<b>escaped</b>')
    && str_contains($escapedFlash, '&lt;img src=x onerror=alert(1)&gt;')
    && !str_contains($escapedFlash, '<img src=x onerror=alert(1)>'));
$_SESSION = [];

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Message assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
