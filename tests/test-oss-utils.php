<?php

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== OSS utilities ==\n";

OSS_Runtime::configure([], '/vimbadmin', new stdClass());

$check(
    'URL parameters retain associative array order and scalar rendering',
    OSS_Utils::genUrl('mailbox', 'list', false, ['page' => 2, 'active' => true], 'https://mail.example.test')
        === 'https://mail.example.test/vimbadmin/mailbox/list/page/2/active/1'
);
$check(
    'integer-keyed URL parameters remain supported',
    OSS_Utils::genUrl(false, false, false, [10, 'next'], 'https://mail.example.test')
        === 'https://mail.example.test/vimbadmin/0/10/1/next'
);
$check(
    'already encoded URL values are not double encoded',
    OSS_Utils::genUrl('auth', 'reset', false, ['token' => 'a%2Fb%20c'], 'https://mail.example.test')
        === 'https://mail.example.test/vimbadmin/auth/reset/token/a%2Fb%20c'
);
$check(
    'raw URL parameter characters retain legacy unencoded semantics',
    OSS_Utils::genUrl('log', 'list', false, ['query' => 'first last/active'], 'https://mail.example.test')
        === 'https://mail.example.test/vimbadmin/log/list/query/first last/active'
);

OSS_Runtime::configure(['utils' => ['genurl' => ['host_mode' => 'REPLACE', 'host_replace' => null]]], '/vimbadmin', new stdClass());
$badReplacementRejected = false;
try {
    OSS_Utils::genUrl('mailbox');
} catch (\TypeError) {
    $badReplacementRejected = true;
}
$check('malformed host replacement fails closed', $badReplacementRejected);
OSS_Runtime::configure([], '/vimbadmin', new stdClass());
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'javascript';
$badProtocolRejected = false;
try {
    OSS_Utils::genUrl('mailbox');
} catch (\TypeError) {
    $badProtocolRejected = true;
}
$check('malformed forwarded protocol fails closed', $badProtocolRejected);
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$badParameterRejected = false;
try {
    OSS_Utils::genUrl('mailbox', false, false, ['bad' => ['nested']]);
} catch (\TypeError) {
    $badParameterRejected = true;
}
$check('container URL parameter fails closed', $badParameterRejected);

$previousInternalErrors = libxml_use_internal_errors(true);
libxml_clear_errors();

$nested = OSS_Utils::parseXML(
    '<mailboxes><mailbox id="7"><address>user@example.test</address></mailbox>'
    . '<mailbox id="8"><address>other@example.test</address></mailbox></mailboxes>'
);
$check('valid nested XML returns a SimpleXMLElement', $nested instanceof SimpleXMLElement);
$check(
    'nested XML child values retain their SimpleXML shape',
    $nested instanceof SimpleXMLElement && (string) $nested->mailbox[0]->address === 'user@example.test'
);
$check(
    'nested XML attributes retain their SimpleXML shape',
    $nested instanceof SimpleXMLElement && (string) $nested->mailbox[1]['id'] === '8'
);
$check(
    'repeated nested XML children remain iterable',
    $nested instanceof SimpleXMLElement && count($nested->mailbox) === 2
);

libxml_clear_errors();
$check('empty XML input remains an error', OSS_Utils::parseXML('') === false);
$check('empty XML input does not invent a parser diagnostic', libxml_get_errors() === []);
$check('a valid but empty XML element retains legacy false behavior', OSS_Utils::parseXML('<mailboxes/>') === false);

libxml_clear_errors();
$check('malformed XML remains rejected', OSS_Utils::parseXML('<mailboxes><mailbox></mailboxes>') === false);
$check('malformed XML retains libxml parser diagnostics', libxml_get_errors() !== []);

libxml_clear_errors();
libxml_use_internal_errors($previousInternalErrors);

$check('uniform hash pads a one-digit hexadecimal ID', OSS_Utils::uniformDistHash(7) === '7/0/0/7/');
$check('uniform hash preserves the documented multi-digit path', OSS_Utils::uniformDistHash(216) === '8/d/0/216/');
$check('uniform hash retains the zero boundary path', OSS_Utils::uniformDistHash(0, 1) === '0/0/');

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
