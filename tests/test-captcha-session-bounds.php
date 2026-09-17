<?php

/**
 * VIM-D04: an unauthenticated flood of /auth/lost-password renders must not
 * grow the session record without bound, and must be throttled.
 *
 * Covers:
 *  - OSS_Captcha_Image::generate() prunes expired OSS_Captcha_* session entries;
 *  - it caps the number of live entries per session (session size stays bounded
 *    across many generate() calls);
 *  - lostPasswordAction() mints a captcha only for the render actually shown;
 *  - lostPasswordAction() is behind the per-source brute-force gate;
 *  - the supported flows still work: captcha validation on the happy path, the
 *    "click image for a new one" refresh, and use_captcha off.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/bruteforce-state-path.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once __DIR__ . '/../application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class CaptchaBoundsState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function captchaBoundsCheck(string $label, bool $condition): void
{
    CaptchaBoundsState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        CaptchaBoundsState::$failures++;
    }
}

/**
 * The live session, read opaquely.
 *
 * These helpers exist so the assertions below observe what production code
 * actually left in $_SESSION. Reading the superglobal directly would let
 * static analysis narrow it to the literal array each test block assigns and
 * then constant-fold the very assertions whose point is that generate() and
 * lostPasswordAction() mutate it behind that assignment.
 *
 * @return array<string,mixed>
 */
function captchaSession(): array
{
    /** @var mixed $session */
    $session = $GLOBALS['_SESSION'] ?? [];
    if (!is_array($session)) {
        return [];
    }

    $typed = [];
    foreach ($session as $key => $value) {
        if (is_string($key)) {
            $typed[$key] = $value;
        }
    }

    return $typed;
}

/** @return list<string> */
function captchaSessionKeys(): array
{
    return array_values(array_filter(
        array_keys(captchaSession()),
        static fn (string $key): bool => str_starts_with($key, 'OSS_Captcha_'),
    ));
}

/** Is there a live session entry for this captcha id? */
function captchaSessionHas(string $id): bool
{
    return array_key_exists('OSS_Captcha_' . $id, captchaSession());
}

/** The stored word for a captcha id, or '' when the entry is absent/malformed. */
function captchaSessionWord(string $id): string
{
    $entry = captchaSession()['OSS_Captcha_' . $id] ?? null;
    if (!is_array($entry)) {
        return '';
    }
    $word = $entry['word'] ?? null;

    return is_string($word) ? $word : '';
}

/** A value from the session by key path, as a plain string ('' when absent). */
function captchaSessionScalar(string $key): string
{
    $value = captchaSession()[$key] ?? null;

    return is_scalar($value) ? (string) $value : '';
}

final class CaptchaBoundsSession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = [])
    {
    }
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }
    public function __unset(string $key): void
    {
        $this->remove($key);
    }
}

#[AllowDynamicProperties]
final class CaptchaBoundsView
{
    /** @var array<string,mixed> */
    public array $values = [];
    public function __set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
    public function render(string $script): string
    {
        $form = $this->values['formHtml'] ?? null;

        $body = is_string($form) ? $form : $script;
        $captchaId = $this->values['captchaId'] ?? null;

        return is_string($captchaId) ? $body . '<!--captcha:' . $captchaId . '-->' : $body;
    }
}

final class CaptchaBoundsAdminRepository extends \Repositories\Admin
{
    public static ?\Entities\Admin $admin = null;

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return self::$admin;
    }

    public function getCount(): int
    {
        return 1;
    }
}

final class CaptchaBoundsBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EntityManager $entityManager,
        private CaptchaBoundsSession $session,
        private CaptchaBoundsView $view,
        private array $options,
    ) {
    }

    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->session,
            'smarty' => $this->view,
            default => throw new LogicException('Unexpected resource: ' . $name),
        };
    }

    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}

/**
 * @return array{controller:AuthController,view:CaptchaBoundsView,session:CaptchaBoundsSession}
 */
function captchaBoundsController(
    string $stateDirectory,
    bool $useCaptcha,
    int $maxAttempts = 5,
    ?int $lostPasswordMaxAttempts = null,
): array {
    CaptchaBoundsAdminRepository::$admin = null;

    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Admin::class)
        ->setCustomRepositoryClass('\\' . CaptchaBoundsAdminRepository::class);

    $session = new CaptchaBoundsSession(['csrfToken' => 'csrf-sentinel']);
    $view = new CaptchaBoundsView();
    $options = [
        'securitysalt' => str_repeat('s', 64),
        'identity' => ['sitename' => 'ViMbAdmin', 'mailer' => ['email' => 'do-not-reply@localhost', 'name' => 'ViMbAdmin']],
        'resources' => ['auth' => ['oss' => [
            'pwhash' => 'crypt:sha512',
            'lost_password' => ['use_captcha' => $useCaptcha ? '1' : '0'],
        ]]],
        'bruteforce' => array_merge([
            'enabled' => '1',
            'max_attempts' => (string) $maxAttempts,
            'statedir' => $stateDirectory,
        ], $lostPasswordMaxAttempts === null
            ? []
            : ['lost_password_max_attempts' => (string) $lostPasswordMaxAttempts]),
    ];
    $container = new Container(
        new CaptchaBoundsBootstrap($entityManager, $session, $view, $options),
        new Auth($session, static fn (int $id): ?\Entities\Admin => null),
    );

    return [
        'controller' => new AuthController(
            $container,
            new RouteMatch('auth', 'lost-password', AuthController::class, 'lostPasswordAction', []),
        ),
        'view' => $view,
        'session' => $session,
    ];
}

/** @param array<string,mixed>|null $post */
function captchaBoundsRequest(AuthController $controller, ?array $post = null): \ViMbAdmin\Kernel\Http\Response
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $savedPost = $_POST;
    $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
    $_POST = $post ?? [];

    try {
        return $controller->lostPasswordAction();
    } finally {
        if ($requestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $requestMethod;
        }
        $_POST = $savedPost;
    }
}

$root = sys_get_temp_dir() . '/vimbadmin-captcha-bounds-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
OSS_Runtime::configure(['temporary_directory' => $root], '', new stdClass());
$floodSource = '203.0.113.42';
$_SERVER['REMOTE_ADDR'] = $floodSource;

echo "== captcha session bounds ==\n";

// --- 1. session key count stays bounded across many generate() calls --------
$_SESSION = ['unrelated-marker' => 'untouched'];
for ($i = 0; $i < 200; $i++) {
    (new OSS_Captcha_Image(0, 0, 6, 1800))->generate();
}
$bounded = captchaSessionKeys();
captchaBoundsCheck(
    '200 generate() calls leave a bounded number of captcha session entries (got ' . count($bounded) . ')',
    count($bounded) > 0 && count($bounded) <= 8,
);
captchaBoundsCheck(
    'capping does not disturb unrelated session state',
    captchaSessionScalar('unrelated-marker') === 'untouched'
);

// Over-cap eviction takes the entries CLOSEST to expiry first, so a captcha
// with a long life outlives the short-lived ones it competes with, and the
// fresh mint is never the entry that gets dropped.
$_SESSION = [];
$longLived = (new OSS_Captcha_Image(0, 0, 6, 86400))->generate();
for ($i = 0; $i < 20; $i++) {
    $latest = (new OSS_Captcha_Image(0, 0, 6, 60))->generate();
}
captchaBoundsCheck(
    'eviction drops the soonest-expiring captcha, not the longest-lived one',
    captchaSessionHas($longLived)
);
captchaBoundsCheck(
    'the newest captcha survives the per-session cap',
    captchaSessionHas($latest)
);

// --- 2. expired entries are pruned -----------------------------------------
$_SESSION = [];
$_SESSION['OSS_Captcha_' . str_repeat('a', 32)] = ['word' => 'AAAAAA', 'expires' => time() - 1];
$_SESSION['OSS_Captcha_' . str_repeat('b', 32)] = ['word' => 'BBBBBB', 'expires' => time() + 600];
$_SESSION['OSS_Captcha_' . str_repeat('c', 32)] = 'not an array at all';
(new OSS_Captcha_Image(0, 0, 6, 1800))->generate();
captchaBoundsCheck(
    'an expired captcha session entry is pruned by generate()',
    !captchaSessionHas(str_repeat('a', 32))
);
captchaBoundsCheck(
    'a malformed captcha session entry is pruned by generate()',
    !captchaSessionHas(str_repeat('c', 32))
);
captchaBoundsCheck(
    'an unexpired captcha session entry survives the prune',
    captchaSessionHas(str_repeat('b', 32))
);

// --- 3. captcha validation still succeeds on the happy path ----------------
$_SESSION = [];
$happyId = (new OSS_Captcha_Image(0, 0, 6, 1800))->generate();
$happyWord = captchaSessionWord($happyId);
captchaBoundsCheck(
    'a freshly minted captcha validates against its own word',
    OSS_Captcha_Image::_isValid($happyId, $happyWord)
);
captchaBoundsCheck(
    'a wrong answer is still rejected',
    !OSS_Captcha_Image::_isValid((new OSS_Captcha_Image(0, 0, 6, 1800))->generate(), 'WRONG1')
);

// --- 4. the controller mints only for the render actually shown ------------
$_SESSION = [];
$stateA = $root . '/bf-a';
$withCaptcha = captchaBoundsController($stateA, true);
$response = captchaBoundsRequest($withCaptcha['controller']);
$renderedIdValue = $withCaptcha['view']->values['captchaId'] ?? null;
$renderedId = is_string($renderedIdValue) ? $renderedIdValue : '';
captchaBoundsCheck(
    'a rendered lost-password GET carries a captcha id',
    $renderedId !== '' && preg_match('/^[a-f0-9]{32}$/', $renderedId) === 1
        && $response->status === 200
);
captchaBoundsCheck(
    'one lost-password render mints exactly one captcha session entry',
    $renderedId !== '' && captchaSessionKeys() === ['OSS_Captcha_' . $renderedId]
);

// The redirect path (submitting a valid form for an unknown user) renders
// nothing, so it must mint nothing.
$_SESSION = [];
$stateB = $root . '/bf-b';
$noCaptcha = captchaBoundsController($stateB, false);
$redirect = captchaBoundsRequest($noCaptcha['controller'], [
    'csrf' => 'csrf-sentinel',
    'username' => 'nobody@example.test',
]);
captchaBoundsCheck(
    'a non-rendering lost-password POST mints no captcha at all',
    captchaSessionKeys() === [] && $redirect->status === 302
);

// The same non-rendering path WITH captchas on: the redirect leaves nothing
// behind, which is exactly what the eager per-action mint used to leak.
$_SESSION = [];
$stateB2 = $root . '/bf-b2';
$captchaRedirect = captchaBoundsController($stateB2, true);
$captchaRedirectId = (new OSS_Captcha_Image(0, 0, 6, 1800))->generate();
$captchaRedirectWord = captchaSessionWord($captchaRedirectId);
$redirect2 = captchaBoundsRequest($captchaRedirect['controller'], [
    'csrf' => 'csrf-sentinel',
    'username' => 'nobody@example.test',
    'captchaid' => $captchaRedirectId,
    'captchatext' => $captchaRedirectWord,
]);
captchaBoundsCheck(
    'a redirecting lost-password POST with captchas on leaks no session entry',
    $redirect2->status === 302 && captchaSessionKeys() === []
);
captchaBoundsCheck(
    'use_captcha off still serves the lost-password page',
    captchaBoundsRequest($noCaptcha['controller'])->status === 200
        && ($noCaptcha['view']->values['useCaptcha'] ?? null) === false
        && captchaSessionKeys() === []
);

// --- 5. the "click image for a new one" refresh still works ----------------
$_SESSION = [];
$stateC = $root . '/bf-c';
$refresh = captchaBoundsController($stateC, true);
// A captcha the user already holds: the refresh path must short-circuit BEFORE
// validation, so it neither consumes that entry nor reports a captcha error.
$heldId = (new OSS_Captcha_Image(0, 0, 6, 1800))->generate();
$refreshResponse = captchaBoundsRequest($refresh['controller'], [
    'csrf' => 'csrf-sentinel',
    'username' => 'someone@example.test',
    'captchaid' => $heldId,
    'captchatext' => 'WRONG1',
    'requestnewimage' => '1',
]);
$refreshId = $refresh['view']->values['captchaId'] ?? null;
captchaBoundsCheck(
    'requestnewimage re-renders with a fresh captcha and keeps the username',
    $refreshResponse->status === 200
        && is_string($refreshId)
        && str_contains($refreshResponse->body, 'someone@example.test')
);
captchaBoundsCheck(
    'requestnewimage short-circuits before validation (no error, held captcha not consumed)',
    !str_contains($refreshResponse->body, 'does not match that of the image')
        && captchaSessionHas($heldId)
);
captchaBoundsCheck(
    'requestnewimage leaves only the held and the freshly minted captcha',
    count(captchaSessionKeys()) === 2
        && is_string($refreshId)
        && captchaSessionHas($refreshId)
);

// --- 6. the lost-password flood throttles ITSELF and not login -------------
// The whole point of the split budget: a flood of unauthenticated
// /auth/lost-password renders must lock the source out of lost-password, and
// must NOT lock that same source out of /auth/login. assertNotLocked() answers
// a locked source with 429 + exit, so the refusal itself is probed in a
// subprocess; the lock state is read here with isLocked(), which reads exactly
// what assertNotLocked() would refuse on.
$_SESSION = [];
$stateE = $root . '/bf-e';
$lostPasswordBudget = 3;
$flood = captchaBoundsController($stateE, true, 5, $lostPasswordBudget);

// The login budget, built exactly as the login/change-password call sites build
// it: the configured statedir, untouched. If lostPasswordAction() ever spends
// this, an unauthenticated GET flood locks real admins out of /auth/login.
$loginBudget = static fn (): ViMbAdmin_BruteForce => new ViMbAdmin_BruteForce(null, [
    'enabled' => '1',
    'max_attempts' => '5',
    'statedir' => $stateE,
]);
$lostPasswordState = static fn (): ViMbAdmin_BruteForce => new ViMbAdmin_BruteForce(null, [
    'enabled' => '1',
    'max_attempts' => (string) $lostPasswordBudget,
    'statedir' => $stateE . '/lost-password',
]);

$floodStatuses = [];
$floodLocked = false;
$floodRequests = 0;
$loginLockedDuringFlood = false;
for ($i = 0; $i < 12; $i++) {
    // The action 429s and exits on ANY budget it consults, which would kill
    // this process before the assertions below. Stop on either lock and let
    // the assertions read which one actually fired: that is what makes the
    // "not login" check able to go red instead of taking the suite down.
    if ($lostPasswordState()->isLocked(null)) {
        $floodLocked = true;
        break;
    }
    if ($loginBudget()->isLocked(null)) {
        $loginLockedDuringFlood = true;
        break;
    }
    $floodStatuses[] = captchaBoundsRequest($flood['controller'])->status;
    $floodRequests++;
}
captchaBoundsCheck(
    'a sustained unauthenticated lost-password flood locks the source out of lost-password',
    $floodLocked && $floodStatuses === [200, 200, 200]
);
// Its own budget, and only its own: locking must not cost more than the
// configured lost-password ceiling.
captchaBoundsCheck(
    'the lost-password lockout needs no more than its own budget',
    $floodRequests === $lostPasswordBudget
);
// THE NEGATIVE CONTROL for the split: same source, same flood, login untouched.
captchaBoundsCheck(
    'the lost-password flood does NOT lock the source out of login',
    !$loginLockedDuringFlood && !$loginBudget()->isLocked(null)
);

// The login budget's state directory must stay byte-identical for its existing
// callers, so no live lockout state is orphaned or relocated by the split.
captchaBoundsCheck(
    'the lost-password budget lives in its own state subdirectory',
    is_dir($stateE . '/lost-password')
        && !file_exists(bruteForceStatePath($stateE, $floodSource))
        && file_exists(bruteForceStatePath($stateE . '/lost-password', $floodSource))
);

// A locked source is actually refused by the action: run it in a subprocess,
// because the 429 path terminates the request. The probe boots the same
// controller wiring standalone (it does not re-enter this test's checks).
$probeScript = __DIR__ . '/support/captcha-lost-password-probe.php';
$probeEnvironment = [
    'CAPTCHA_BOUNDS_PROBE_ROOT' => $root,
    'CAPTCHA_BOUNDS_PROBE_STATEDIR' => $stateE,
    'CAPTCHA_BOUNDS_PROBE_MAX_ATTEMPTS' => (string) $lostPasswordBudget,
    'CAPTCHA_BOUNDS_PROBE_REMOTE_ADDR' => $floodSource,
];
$probeCommand = '';
foreach ($probeEnvironment as $probeName => $probeValue) {
    $probeCommand .= $probeName . '=' . escapeshellarg($probeValue) . ' ';
}
$probeCommand .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeScript) . ' 2>&1';
$probeOutput = [];
$probeStatus = 0;
exec($probeCommand, $probeOutput, $probeStatus);
$probeText = implode("\n", $probeOutput);
// Guard against a vacuous pass: a probe that died before reaching the action
// (missing env, boot error) also prints no NOT-REFUSED, so require the 429 body
// AND that the probe did not exit with its own setup failure status.
captchaBoundsCheck(
    'the locked-source probe reached the action (no setup failure)',
    $probeStatus !== 2 && !str_contains($probeText, 'is not set')
);
captchaBoundsCheck(
    'a locked source is refused by lostPasswordAction with 429',
    str_contains($probeText, 'Too many failed login attempts')
        && !str_contains($probeText, 'NOT-REFUSED')
);

echo "\n";
if (CaptchaBoundsState::$failures === 0) {
    echo 'ALL PASSED (' . CaptchaBoundsState::$checks . " checks)\n";
    exit(0);
}
echo CaptchaBoundsState::$failures . ' of ' . CaptchaBoundsState::$checks . " checks FAILED\n";
exit(1);
