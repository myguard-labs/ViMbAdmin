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
$check('single: frozen legacy bytes remain exact', base64_encode($single) === 'CiAgICAgICAgPGRpdiBjbGFzcz0iYWxlcnQgYWxlcnQtaW5mbyBmYWRlIGluIiBpZD0ib3NzLW1lc3NhZ2UtMCI+CiAgICAgICAgICAgIDxhIGNsYXNzPSJjbG9zZSIgaHJlZj0iIyIgZGF0YS1icy1kaXNtaXNzPSJhbGVydCI+w5c8L2E+CiAgICAgICAgICAgIDxiPm9uZTwvYj4KICAgICAgICA8L2Rpdj4K');
$multiple = $render([new OSS_Message(['one', 'two'], OSS_Message::SUCCESS)]);
$check('multiple: frozen IDs, ordering, and whitespace remain exact', base64_encode($multiple) === 'CiAgICAgICAgPGRpdiBjbGFzcz0iYWxlcnQgYWxlcnQtc3VjY2VzcyBmYWRlIGluIiBpZD0ib3NzLW1lc3NhZ2UtMSI+CiAgICAgICAgICAgIDxhIGNsYXNzPSJjbG9zZSIgaHJlZj0iIyIgZGF0YS1icy1kaXNtaXNzPSJhbGVydCI+w5c8L2E+CiAgICAgICAgICAgIG9uZQogICAgICAgIDwvZGl2PgoKICAgICAgICA8ZGl2IGNsYXNzPSJhbGVydCBhbGVydC1zdWNjZXNzIGZhZGUgaW4iIGlkPSJvc3MtbWVzc2FnZS0yIj4KICAgICAgICAgICAgPGEgY2xhc3M9ImNsb3NlIiBocmVmPSIjIiBkYXRhLWJzLWRpc21pc3M9ImFsZXJ0Ij7DlzwvYT4KICAgICAgICAgICAgdHdvCiAgICAgICAgPC9kaXY+Cg==');

$block = new OSS_Message_Block('block', OSS_Message::WARNING);
$block->addAction('<button>go</button>');
$check('type: frozen block bytes remain exact', base64_encode($render([$block])) === 'CiAgICA8ZGl2IGNsYXNzPSJhbGVydCBhbGVydC1ibG9jayBhbGVydC13YXJuaW5nIGZhZGUgaW4iIGlkPSJvc3MtbWVzc2FnZS0zIj4KICAgICAgICA8YSBjbGFzcz0iY2xvc2UiIGhyZWY9IiMiIGRhdGEtYnMtZGlzbWlzcz0iYWxlcnQiPsOXPC9hPgogICAgICAgIGJsb2NrICAgICAgICA8ZGl2IGNsYXNzPSJhbGVydC1hY3Rpb25zIj4KPGJ1dHRvbj5nbzwvYnV0dG9uPgogICAgICAgIDwvZGl2PgogICAgPC9kaXY+Cg==');
$popup = new OSS_Message_Pop_Up(['<tag>', 'second'], OSS_Message::INFO);
$popupOutput = $render([$popup]);
$check('type: frozen popup bytes remain exact', base64_encode($popupOutput) === 'CiAgICAgICAgPHNjcmlwdCB0eXBlPSJ0ZXh0L2phdmFzY3JpcHQiIG5vbmNlPSJmaXh0dXJlTm9uY2UiPgogICAgICAgICAgICBpZiggZG9jdW1lbnQucmVhZHlTdGF0ZSA9PT0gJ2xvYWRpbmcnICkgewogICAgICAgICAgICAgICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lciggJ0RPTUNvbnRlbnRMb2FkZWQnLCBmdW5jdGlvbigpIHsKICAgICAgICAgICAgICAgICAgICBvc3NBbGVydCggIlx1MDAzQ3RhZ1x1MDAzRSIgKTsKICAgICAgICAgICAgICAgIH0sIHsgb25jZTogdHJ1ZSB9ICk7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgZWxzZSB7CiAgICAgICAgICAgICAgICBvc3NBbGVydCggIlx1MDAzQ3RhZ1x1MDAzRSIgKTsKICAgICAgICAgICAgfQogICAgICAgIDwvc2NyaXB0PgoKICAgICAgICA8c2NyaXB0IHR5cGU9InRleHQvamF2YXNjcmlwdCIgbm9uY2U9ImZpeHR1cmVOb25jZSI+CiAgICAgICAgICAgIGlmKCBkb2N1bWVudC5yZWFkeVN0YXRlID09PSAnbG9hZGluZycgKSB7CiAgICAgICAgICAgICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCAnRE9NQ29udGVudExvYWRlZCcsIGZ1bmN0aW9uKCkgewogICAgICAgICAgICAgICAgICAgIG9zc0FsZXJ0KCAic2Vjb25kIiApOwogICAgICAgICAgICAgICAgfSwgeyBvbmNlOiB0cnVlIH0gKTsKICAgICAgICAgICAgfQogICAgICAgICAgICBlbHNlIHsKICAgICAgICAgICAgICAgIG9zc0FsZXJ0KCAic2Vjb25kIiApOwogICAgICAgICAgICB9CiAgICAgICAgPC9zY3JpcHQ+Cg==');

$escaped = $render([], [['level' => 'error', 'text' => '<script>&"\'', 'isHtml' => false]]);
$check('escaping: frozen escaped flash bytes remain exact', base64_encode($escaped) === 'CiAgICAgICAgPGRpdiBjbGFzcz0iYWxlcnQgYWxlcnQtZXJyb3IgZmFkZSBpbiIgaWQ9Im9zcy1tZXNzYWdlLTQiPgogICAgICAgICAgICA8YSBjbGFzcz0iY2xvc2UiIGhyZWY9IiMiIGRhdGEtYnMtZGlzbWlzcz0iYWxlcnQiPsOXPC9hPgogICAgICAgICAgICAmbHQ7c2NyaXB0Jmd0OyZhbXA7JnF1b3Q7JiMwMzk7CiAgICAgICAgPC9kaXY+Cg==');
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
