<?php

require __DIR__ . '/../library/ViMbAdmin/Plugin.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$plugin = new ViMbAdmin_Plugin((object) [], 'ViMbAdminPlugin_Example');
$check('constructor resolves the legacy plugin suffix', $plugin->getName() === 'example');

$config = ['enabled' => true, 'limit' => 3];
$returned = $plugin->setConfig($config);
$check('setConfig stores the complete configuration', $plugin->getConfig() === $config);
$check('setConfig retains fluent callers', $returned === $plugin);

$threw = false;
try {
    (new ReflectionClass(ViMbAdmin_Plugin::class))->newInstanceArgs([(object) [], (object) []]);
} catch (TypeError) {
    $threw = true;
}
$check('constructor rejects a non-string classname', $threw);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all ViMbAdmin_Plugin assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
