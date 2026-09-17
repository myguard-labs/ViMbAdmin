<?php

$doveadm = file_get_contents(__DIR__ . '/../library/ViMbAdmin/Doveadm.php');
$message = file_get_contents(__DIR__ . '/../library/OSS/Smarty/functions/function.OSS_Message.php');
$captcha = file_get_contents(__DIR__ . '/../library/OSS/Captcha/Image.php');
if (!is_string($doveadm) || !is_string($message) || !is_string($captcha)) {
    fwrite(STDERR, "unable to read identifier sources\n");
    exit(1);
}
$checks = [
    'doveadm tag uses random_bytes' => str_contains($doveadm, "bin2hex(random_bytes(4))"),
    'doveadm tag has no uniqid' => !str_contains($doveadm, 'uniqid('),
    'message id has no mt_rand' => !str_contains($message, 'mt_rand('),
    'captcha uses unambiguous alphabet' => str_contains($captcha, "23456789ABCDEFGHJKLMNPQRSTUVWXYZ"),
];
$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
}
if ($failed !== []) {
    exit(1);
}
echo 'ALL PASSED (' . count($checks) . " checks)\n";
exit(0);
