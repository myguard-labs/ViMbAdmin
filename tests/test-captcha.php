<?php

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

$root = sys_get_temp_dir() . '/vimbadmin-captcha-test-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);

OSS_Runtime::configure(['temporary_directory' => $root], '', new stdClass());
$_SESSION = [];

echo "== standalone captcha ==\n";

$captcha = new OSS_Captcha_Image(0, 0, 6, 60);
$id = $captcha->generate();
$path = OSS_Captcha_Image::path($id);
$word = $_SESSION['OSS_Captcha_' . $id]['word'] ?? '';

check('generated id is a 32-character hex value', preg_match('/^[a-f0-9]{32}$/', $id) === 1);
check('generated image exists', $path !== null && is_file($path));
check('generated image is a PNG', $path !== null && str_starts_with((string) mime_content_type($path), 'image/png'));
check('wrong answer fails', OSS_Captcha_Image::_isValid($id, 'WRONG') === false);
check('validation consumes the session value', !isset($_SESSION['OSS_Captcha_' . $id]));
check('validation removes the image', OSS_Captcha_Image::path($id) === null);

$expired = $root . '/captchas/' . str_repeat('a', 32) . '.png';
file_put_contents($expired, 'expired');
touch($expired, time() - 120);
(new OSS_Captcha_Image(0, 0, 6, 60))->generate();
check('generation removes expired captcha files', !file_exists($expired));

foreach (glob($root . '/captchas/*.png') ?: [] as $file) {
    @unlink($file);
}
@rmdir($root . '/captchas');
@rmdir($root);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
