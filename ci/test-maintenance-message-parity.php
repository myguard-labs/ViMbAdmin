<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/library/OSS/Message.php';
require_once dirname(__DIR__) . '/library/OSS/Message/Block.php';
require_once dirname(__DIR__) . '/library/OSS/Message/Pop/Up.php';
require_once dirname(__DIR__) . '/library/OSS/Smarty/functions/function.OSS_Message.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};
$render = static function (array $messages, array $flash = [], array $params = []): string {
    $_SESSION = ['Application' => ['flashMessages' => $flash]];
    $smarty = new Smarty\Smarty();
    $smarty->assign('OSS_Messages', $messages);
    $smarty->assign('cspNonce', 'fixtureNonce');
    return smarty_function_OSS_Message($params, $smarty);
};

$check('empty: no messages render no bytes', $render([]) === '');
$single = $render([new OSS_Message('<b>one</b>', OSS_Message::INFO)]);
$check('single: legacy HTML remains byte-for-byte unescaped', str_contains($single, "            <b>one</b>\n") && !str_contains($single, '&lt;b&gt;'));
$multiple = $render([new OSS_Message(['one', 'two'], OSS_Message::SUCCESS)]);
$check('multiple: every item renders in order', strpos($multiple, 'one') < strpos($multiple, 'two') && substr_count($multiple, 'alert alert-success') === 2);

$block = new OSS_Message_Block('block', OSS_Message::WARNING);
$block->addAction('<button>go</button>');
$check('type: block markup and actions are preserved', str_contains($render([$block]), 'alert-block') && str_contains($render([$block]), '<button>go</button>'));
$popup = new OSS_Message_Pop_Up(['<tag>', 'second'], OSS_Message::INFO);
$popupOutput = $render([$popup]);
$check('type: popup JSON hex-escapes executable markup', str_contains($popupOutput, '\\u003Ctag\\u003E') && substr_count($popupOutput, 'ossAlert(') === 4);

$escaped = $render([], [['level' => 'error', 'text' => '<script>&"\'', 'isHtml' => false]]);
$check('escaping: plain flash text uses ENT_QUOTES and substitutes markup', str_contains($escaped, '&lt;script&gt;&amp;&quot;&#039;'));
$trusted = $render([], [['level' => 'info', 'text' => '<strong>ok</strong>', 'isHtml' => true]]);
$check('escaping boundary: explicitly trusted flash HTML remains exact', str_contains($trusted, '<strong>ok</strong>'));

foreach ([new stdClass(), new OSS_Message([['nested']], 'info'), new OSS_Message('x', 'bad class!')] as $bad) {
    try {
        $render([$bad]);
        $rejected = false;
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check('malformed/nested input is rejected', $rejected);
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
