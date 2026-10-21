<?php

declare(strict_types=1);

use ViMbAdmin\Tests\BrowserFixture\SuperAdmin;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/support/browser-fixture-super-admin.php';

$failures = 0;
$check = static function (bool $condition, string $name) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $name . "\n";
    if (!$condition) {
        ++$failures;
    }
};
$canonical = (new Entities\Admin())->setUsername(SuperAdmin::USERNAME)->setSuper(true);
$check(SuperAdmin::requireAdmin($canonical) === $canonical, 'canonical super admin identity is retained');
foreach ([
    'non-super canonical identity is rejected' => (new Entities\Admin())->setUsername(SuperAdmin::USERNAME)->setSuper(false),
    'super with another identity is rejected' => (new Entities\Admin())->setUsername('other.browser.example.test')->setSuper(true),
    'missing identity is rejected' => (new Entities\Admin())->setSuper(true),
    'absent admin is rejected' => null,
    'wrong object type is rejected' => new stdClass(),
] as $name => $candidate) {
    $denied = false;
    try {
        SuperAdmin::requireAdmin($candidate);
    } catch (Throwable $error) {
        $denied = $error instanceof RuntimeException
            && $error->getMessage() === 'fixture requires its canonical super admin';
    }
    $check($denied, $name);
}
if ($failures !== 0) {
    exit(1);
}
echo "ALL PASSED\n";
