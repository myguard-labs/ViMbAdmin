<?php

declare(strict_types=1);

use Doctrine\ORM\Tools\SchemaTool;

if ($argc < 4 || $argc > 5 || ($argc === 5 && $argv[4] !== '--sessions-only')) {
    fwrite(STDERR, "usage: browser-application-setup.php APP_ROOT SESSION_DIR STATE_DIR [--sessions-only]\n");
    exit(64);
}

[$script, $appRoot, $sessionDir, $stateDir] = $argv;
unset($script);
$sessionsOnly = $argc === 5;
foreach ([$appRoot, $sessionDir, $stateDir] as $directory) {
    if (!is_dir($directory) || is_link($directory)) {
        fwrite(STDERR, "fixture directory is unavailable\n");
        exit(1);
    }
}

$applicationPath = realpath($appRoot . '/application');
if ($applicationPath === false) {
    fwrite(STDERR, "fixture application path is unavailable\n");
    exit(1);
}
define('APPLICATION_PATH', $applicationPath);
define('APPLICATION_ENV', 'browser-fixture');
require $appRoot . '/vendor/autoload.php';
set_include_path(implode(PATH_SEPARATOR, [realpath(APPLICATION_PATH . '/../library'), get_include_path()]));

$container = ViMbAdmin\Kernel\Bootstrap::boot(APPLICATION_PATH, APPLICATION_ENV, 'ViMbAdmin_Auth');
$em = $container->entityManager();
if (!$em instanceof Doctrine\ORM\EntityManagerInterface) {
    throw new RuntimeException('fixture entity manager is unavailable');
}
$metadata = $em->getMetadataFactory()->getAllMetadata();
if ($sessionsOnly) {
    $admin = $em->getRepository(Entities\Admin::class)->findOneBy([]);
    if (!$admin instanceof Entities\Admin) {
        throw new RuntimeException('fixture admin is unavailable for session reset');
    }
    foreach (glob($sessionDir . '/sess_*') ?: [] as $sessionFile) {
        if (is_file($sessionFile) && !is_link($sessionFile)) {
            unlink($sessionFile);
        }
    }
} else {
    (new SchemaTool($em))->createSchema($metadata);
    $admin = new Entities\Admin();
    $admin->setUsername('browser-fixture-admin.invalid');
    $admin->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT));
    $admin->setSuper(true);
    $admin->setActive(true);
    $admin->setCreated(new DateTime('2020-01-01T00:00:00+00:00'));
    $admin->setModified(new DateTime('2020-01-01T00:00:00+00:00'));
    $em->persist($admin);
    $em->flush();
}
$adminId = $admin->getId();
if (!is_int($adminId) || $adminId < 1) {
    throw new RuntimeException('fixture admin id was not generated');
}

$origin = getenv('VIMBADMIN_FIXTURE_ORIGIN');
if (!is_string($origin) || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]*$#D', $origin) !== 1) {
    throw new RuntimeException('fixture origin is invalid');
}

ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '0');
ini_set('session.cookie_samesite', 'Lax');
session_name('VIMBADMIN3');
session_save_path($sessionDir);

$aliases = [];
$writeSession = static function (string $alias, callable $seed) use (
    $sessionDir,
    $stateDir,
    $origin,
    &$aliases,
): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $id = bin2hex(random_bytes(24));
    session_id($id);
    if (!session_start()) {
        throw new RuntimeException('fixture session could not start');
    }
    $_SESSION = [];
    $seed();
    session_write_close();
    $sessionFile = $sessionDir . '/sess_' . $id;
    if (!is_file($sessionFile)) {
        throw new RuntimeException('fixture session file was not created');
    }
    $state = [
        'cookies' => [[
            'name' => 'VIMBADMIN3',
            'value' => $id,
            'url' => $origin . '/',
            'httpOnly' => true,
            'secure' => false,
            'sameSite' => 'Lax',
        ]],
    ];
    $statePath = $stateDir . '/' . $alias . '.json';
    if (file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('fixture browser state could not be written');
    }
    chmod($statePath, 0600);
    $aliases[$alias] = $id;
};

$anonymous = static function (): void {
    $_SESSION['Application'] = ['csrfToken' => bin2hex(random_bytes(32))];
};
foreach (['anonymous', 'anonymous_logout', 'error'] as $alias) {
    $writeSession($alias, $anonymous);
}
$writeSession('pending_totp', static function () use ($adminId): void {
    $_SESSION['Application'] = [
        'totp_pending_admin_id' => $adminId,
        'totp_pending_via' => 'auth',
        'csrfToken' => bin2hex(random_bytes(32)),
    ];
});
$writeSession('pending_setup', static function () use ($adminId): void {
    $_SESSION['Application'] = [
        'totp_pending_admin_id' => $adminId,
        'totp_pending_via' => 'auth',
        'csrfToken' => bin2hex(random_bytes(32)),
    ];
});
foreach (['csrf_missing', 'csrf_invalid'] as $alias) {
    $writeSession($alias, static function () use ($adminId): void {
        $_SESSION['Application'] = [
            'totp_pending_admin_id' => $adminId,
            'totp_pending_via' => 'auth',
            'csrfToken' => bin2hex(random_bytes(32)),
        ];
    });
}
$writeSession('expired', static function () use ($admin): void {
    $_SESSION['Application'] = ['timeOfLastAction' => 1, 'csrfToken' => bin2hex(random_bytes(32))];
    $_SESSION['ViMbAdmin_Auth'] = [
        'storage' => [
            'username' => $admin->requiredUsername(),
            'user' => $admin,
            'id' => $admin->getId(),
        ],
    ];
});

if (getenv('VIMBADMIN_FIXTURE_TENANT') === '1') {
    require dirname(__DIR__, 2) . '/ci/support/browser-tenant-seed.php';
}

$aliasPath = $stateDir . '/session-aliases.json';
if (file_put_contents($aliasPath, json_encode($aliases, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('fixture session aliases could not be written');
}
chmod($aliasPath, 0600);

printf(
    "fixture %s complete: %d mapped tables, %d private sessions\n",
    $sessionsOnly ? 'session reset' : 'setup',
    count($metadata),
    count($aliases),
);
