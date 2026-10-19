<?php

declare(strict_types=1);

/**
 * VC-S02: characterize the two list consumers before/after extracting their
 * byte-identical, variable-free purge-modal header.
 *
 * The test renders the real consumer templates with Smarty 5. The "original"
 * side is reconstructed by putting the admitted ten-line fragment back at the
 * fixed include site; its SHA-256 must match the pre-refactor source identity.
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require __DIR__ . '/../vendor/autoload.php';

const PURGE_MODAL_HEADER = <<<'SMARTY'
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-labelledby="purge_dialog_label" aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <h3 class="modal-title" id="purge_dialog_label">Are you sure?</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
SMARTY;

const PURGE_MODAL_INCLUDE = "{tmplinclude file='list/_purge-modal-header.phtml'}";
const ORIGINAL_HASHES = [
    'alias/list.phtml' => '40f12bed8f55a988339a8095a6bf4f4d6d5b08c263bff430e5ca6e4b63125bb3',
    'admin/domains.phtml' => '6d8411701bec32ef89c57211f90bcc57533e8a7209bc9d032c8176314dd2b3c5',
];

final class ListTemplateAdmin
{
    /** @param list<ListTemplateDomain> $domains */
    public function __construct(
        private int $id,
        private string $username,
        private array $domains,
        private bool $super = true,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /** @return list<ListTemplateDomain> */
    public function getDomains(): array
    {
        return $this->domains;
    }

    public function isSuper(): bool
    {
        return $this->super;
    }
}

final class ListTemplateDomain
{
    public function __construct(
        private int $id,
        private string $name,
        private bool $active,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function requiredDomainName(): string
    {
        return $this->name;
    }

    public function getActive(): bool
    {
        return $this->active;
    }
}

final class ListTemplateTestState
{
    public static int $assertions = 0;
    public static int $failures = 0;
}

final class ListTemplateUrlGenerator
{
    public function __construct(private string $baseUrl)
    {
    }

    /** @param array<string, scalar|null> $params */
    public function __invoke(array $params): string
    {
        $segments = [];
        foreach (['module', 'controller', 'action'] as $key) {
            if (isset($params[$key]) && $params[$key] !== false && $params[$key] !== '') {
                $segments[] = rawurlencode((string) $params[$key]);
            }
            unset($params[$key]);
        }
        foreach ($params as $key => $value) {
            if ($value === null) {
                throw new InvalidArgumentException("URL parameter {$key} must be scalar");
            }
            $segments[] = rawurlencode($key);
            $segments[] = rawurlencode((string) $value);
        }
        return rtrim($this->baseUrl, '/') . ($segments === [] ? '' : '/' . implode('/', $segments));
    }
}

function check(bool $condition, string $message): void
{
    ListTemplateTestState::$assertions++;
    if (!$condition) {
        ListTemplateTestState::$failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function requireString(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false) {
        throw new RuntimeException("could not read {$path}");
    }
    return $value;
}

function putString(string $path, string $value): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create {$dir}");
    }
    if (file_put_contents($path, $value) === false) {
        throw new RuntimeException("could not write {$path}");
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            throw new RuntimeException('recursive directory iterator returned a non-file value');
        }
        $name = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            unlink($name);
        } else {
            rmdir($name);
        }
    }
    rmdir($path);
}

/**
 * Only successful exclusive creation transfers root ownership to the caller.
 * Children are created inside this private root; failed acquisition must never
 * clean an existing path. Root symlinks are never traversed during cleanup.
 */
function createTemplateRoot(?string $candidate = null): string
{
    $path = $candidate ?? sys_get_temp_dir() . '/vimbadmin-list-template-' . bin2hex(random_bytes(16));
    if (is_link($path) || file_exists($path)) {
        throw new RuntimeException("temporary root already exists: {$path}");
    }
    if (!mkdir($path, 0700)) {
        throw new RuntimeException("could not acquire temporary root: {$path}");
    }
    return $path;
}

function checkTemplateRootOwnership(string $ownedRoot): void
{
    check((fileperms($ownedRoot) & 0777) === 0700, 'temporary root is private to its owner');
    $existing = $ownedRoot . '/existing-root';
    putString($existing . '/sentinel', 'owned fixture survives');
    $rejected = false;
    try {
        createTemplateRoot($existing);
    } catch (RuntimeException $error) {
        $rejected = str_starts_with($error->getMessage(), 'temporary root already exists:');
    }
    check($rejected && requireString($existing . '/sentinel') === 'owned fixture survives', 'existing root is refused without changing its sentinel');

    $link = $ownedRoot . '/root-link';
    symlink($existing, $link);
    $rejected = false;
    try {
        createTemplateRoot($link);
    } catch (RuntimeException $error) {
        $rejected = str_starts_with($error->getMessage(), 'temporary root already exists:');
    }
    check($rejected, 'symlink root cannot be acquired');
    removeTree($link);
    check(is_link($link) && is_file($existing . '/sentinel') && requireString($existing . '/sentinel') === 'owned fixture survives', 'cleanup refuses a symlink root and preserves its target sentinel');
    unlink($link);
}

/** @return array<string,mixed> */
function aliasVars(string $case): array
{
    $row = [
        'id' => 41,
        'address' => 'owner@example.test',
        'domain' => 'example.test',
        'active' => true,
        'goto' => 'destination@example.test',
    ];
    if ($case === 'hostile') {
        $row['address'] = '"><script>bad()</script> Ω@example.test';
        $row['domain'] = "quo'ted.example";
        $row['goto'] = 'δοκιμή@example.test,"><img src=x onerror=bad()>';
    }
    $vars = [
        'session' => new stdClass(),
        'ima' => 0,
        'options' => ['defaults' => ['table' => ['entries' => 10]]],
        'aliases' => $case === 'empty' ? [] : [$row],
        'csrfToken' => $case === 'hostile' ? '"><csrf&Ω' : 'csrf-alias',
        'cspNonce' => $case === 'hostile' ? '"><nonce&Ω' : 'nonce-alias',
    ];
    if ($case === 'empty-actions') {
        $vars['alias_actions'] = [];
    }
    return $vars;
}

/** @return array<string,mixed> */
function adminVars(string $case): array
{
    $domain = new ListTemplateDomain(
        73,
        $case === 'hostile' ? '"><script>domain()</script> münich.example' : 'example.test',
        $case !== 'inactive',
    );
    $target = new ListTemplateAdmin(
        29,
        $case === 'hostile' ? '"><img src=x onerror=admin()> 管理者' : 'target-admin',
        $case === 'empty' ? [] : [$domain],
    );
    return [
        'user' => new ListTemplateAdmin(1, 'viewer', [], $case !== 'missing-action'),
        'targetAdmin' => $target,
        'options' => ['defaults' => ['table' => ['entries' => 10]]],
        'csrfToken' => $case === 'hostile' ? '"><csrf&Ω' : 'csrf-admin',
        'cspNonce' => $case === 'hostile' ? '"><nonce&Ω' : 'nonce-admin',
    ];
}

/** @return list<array{template:string,name:string,vars:array<string,mixed>}> */
function renderCases(): array
{
    return [
        ['template' => 'alias/list.phtml', 'name' => 'typical', 'vars' => aliasVars('typical')],
        ['template' => 'alias/list.phtml', 'name' => 'empty', 'vars' => aliasVars('empty')],
        ['template' => 'alias/list.phtml', 'name' => 'empty-actions', 'vars' => aliasVars('empty-actions')],
        ['template' => 'alias/list.phtml', 'name' => 'hostile', 'vars' => aliasVars('hostile')],
        ['template' => 'admin/domains.phtml', 'name' => 'typical', 'vars' => adminVars('typical')],
        ['template' => 'admin/domains.phtml', 'name' => 'empty', 'vars' => adminVars('empty')],
        ['template' => 'admin/domains.phtml', 'name' => 'missing-action', 'vars' => adminVars('missing-action')],
        ['template' => 'admin/domains.phtml', 'name' => 'hostile', 'vars' => adminVars('hostile')],
    ];
}

/**
 * @param array<string,mixed> $vars
 * @return array{string, Smarty\Smarty}
 */
function renderTemplate(
    string $root,
    string $compile,
    string $template,
    array $vars,
    string $baseUrl = '/control/panel',
    ?string $skin = null,
    bool $productionFallback = true,
): array {
    if (!is_dir($compile) && !mkdir($compile, 0770, true) && !is_dir($compile)) {
        throw new RuntimeException("could not create {$compile}");
    }
    $smarty = new Smarty\Smarty();
    $smarty->setEscapeHtml(true);
    $templateDirs = [$root];
    if ($productionFallback) {
        $templateDirs[] = dirname(__DIR__) . '/application/views';
    }
    $smarty->setTemplateDir($templateDirs);
    $smarty->setCompileDir($compile);
    $errorReporting = error_reporting();
    error_reporting($errorReporting & ~E_USER_DEPRECATED);
    $smarty->addPluginsDir([
        dirname(__DIR__) . '/library/ViMbAdmin/Smarty/functions',
        dirname(__DIR__) . '/library/OSS/Smarty/functions',
    ]);
    error_reporting($errorReporting);
    foreach (['strlen', 'count', 'in_array', 'is_array'] as $modifier) {
        $smarty->registerPlugin('modifier', $modifier, $modifier, true);
    }
    $smarty->unregisterPlugin('function', 'genUrl');
    $smarty->registerPlugin(
        'function',
        'genUrl',
        new ListTemplateUrlGenerator($baseUrl),
    );
    $smarty->unregisterPlugin('function', 'OSS_Message');
    $smarty->registerPlugin('function', 'OSS_Message', static fn (array $params): string => '');
    $smarty->assign($vars);
    if ($skin !== null) {
        $smarty->assign('___SKIN', $skin);
    }
    return [(string) $smarty->fetch($template), $smarty];
}

function domSignature(string $html): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    if (!$loaded) {
        throw new RuntimeException('rendered HTML did not parse');
    }
    $signature = $dom->C14N();
    if ($signature === false) {
        throw new RuntimeException('rendered DOM could not be canonicalized');
    }
    return $signature;
}

$tmp = null;
$capture = $argc === 3 && $argv[1] === '--capture-original' ? $argv[2] : null;
$evidenceDir = getenv('VIMBADMIN_LIST_TEMPLATE_EVIDENCE') ?: null;

try {
    $tmp = createTemplateRoot();
    $originalRoot = $tmp . '/original';
    $currentRoot = $tmp . '/current';
    foreach ([$originalRoot, $currentRoot, $tmp . '/compiled-original', $tmp . '/compiled-current'] as $dir) {
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException("could not create {$dir}");
        }
    }
    checkTemplateRootOwnership($tmp);
    foreach ([$originalRoot, $currentRoot] as $templateRoot) {
        putString($templateRoot . '/header.phtml', '<!doctype html><html><body>');
        putString($templateRoot . '/footer.phtml', '</body></html>');
    }

    $hasExtraction = is_file($root . '/application/views/list/_purge-modal-header.phtml');
    foreach (ORIGINAL_HASHES as $template => $expectedHash) {
        $source = requireString($root . '/application/views/' . $template);
        $original = $source;
        if ($hasExtraction) {
            $count = 0;
            $original = str_replace(PURGE_MODAL_INCLUDE, PURGE_MODAL_HEADER . "\n", $source, $count);
            check($count === 1, "{$template} has exactly one fixed partial include");
        } else {
            check(substr_count($source, PURGE_MODAL_HEADER) === 1, "{$template} has one admitted original fragment");
        }
        check(hash('sha256', $original) === $expectedHash, "{$template} reconstructs the admitted original source hash");
        putString($originalRoot . '/' . $template, $original);
    }

    if ($capture !== null) {
        if (!is_dir($capture) && !mkdir($capture, 0770, true) && !is_dir($capture)) {
            throw new RuntimeException("could not create capture directory {$capture}");
        }
        $cases = renderCases();
        foreach ($cases as ['template' => $template, 'name' => $name, 'vars' => $vars]) {
            [$html] = renderTemplate($originalRoot, $tmp . '/compiled-original', $template, $vars);
            putString($capture . '/' . str_replace('/', '-', $template) . '-' . $name . '.html', $html);
        }
        putString($capture . '/source-hashes.json', json_encode(ORIGINAL_HASHES, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        printf("OK: captured %d original renders with %d source assertions\n", count($cases), ListTemplateTestState::$assertions);
        removeTree($tmp);
        exit(ListTemplateTestState::$failures === 0 ? 0 : 1);
    }

    check($hasExtraction, 'the extracted partial exists');
    if (!$hasExtraction) {
        throw new RuntimeException('candidate partial is absent');
    }
    $partial = requireString($root . '/application/views/list/_purge-modal-header.phtml');
    check($partial === PURGE_MODAL_HEADER . "\n", 'partial is exactly the admitted static fragment');
    check(hash('sha256', rtrim($partial, "\n")) === hash('sha256', PURGE_MODAL_HEADER), 'partial content hash matches original fragment');

    $cases = renderCases();
    foreach ($cases as ['template' => $template, 'name' => $name, 'vars' => $vars]) {
        [$before] = renderTemplate($originalRoot, $tmp . '/compiled-original', $template, $vars);
        [$after] = renderTemplate($currentRoot, $tmp . '/compiled-current', $template, $vars);
        if ($evidenceDir !== null) {
            $stem = str_replace('/', '-', $template) . '-' . $name;
            putString($evidenceDir . '/' . $stem . '-original.html', $before);
            putString($evidenceDir . '/' . $stem . '-candidate.html', $after);
        }
        check($after === $before, "{$template} {$name} rendered bytes equal the original");
        check(domSignature($after) === domSignature($before), "{$template} {$name} DOM equals the original");
        check(substr_count($after, 'id="purge_dialog"') === 1, "{$template} {$name} has one purge modal");
    }

    [$aliasTypical] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'alias/list.phtml', aliasVars('typical'));
    [$aliasEmptyActions] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'alias/list.phtml', aliasVars('empty-actions'));
    check(!str_contains($aliasTypical, 'dropdown-menu'), 'missing alias actions render no plugin action menu');
    check(!str_contains($aliasEmptyActions, 'dropdown-menu'), 'empty alias actions render no plugin action menu');
    check(str_contains($aliasTypical, 'href="/control/panel/alias/edit/alid/41"'), 'alias edit row action keeps URL subpath and id');
    check(str_contains($aliasTypical, 'action="/control/panel/alias/delete"'), 'alias delete row action keeps URL subpath');
    check(str_contains($aliasTypical, 'name="csrf" value="csrf-alias"'), 'alias delete keeps CSRF field');

    [$adminTypical] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'admin/domains.phtml', adminVars('typical'));
    [$adminMissingAction] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'admin/domains.phtml', adminVars('missing-action'));
    check(!str_contains($adminMissingAction, 'id="asign_domain have-tooltip"'), 'non-super user has no assign-domain action');
    check(str_contains($adminTypical, 'href="/control/panel/admin/remove-domain/aid/29/did/73"'), 'domain row action keeps URL subpath and ids');
    check(str_contains($adminTypical, 'action="/control/panel/admin/remove-domain"'), 'domain modal form keeps URL subpath');
    check(str_contains($adminTypical, 'name="aid" value="29"'), 'domain modal form keeps admin id');
    check(str_contains($adminTypical, 'name="csrf" value="csrf-admin"'), 'domain modal form keeps CSRF field');

    [$aliasHostile] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'alias/list.phtml', aliasVars('hostile'));
    [$adminHostile] = renderTemplate($currentRoot, $tmp . '/compiled-current', 'admin/domains.phtml', adminVars('hostile'));
    check(!str_contains($aliasHostile, '<script>bad()</script>'), 'hostile alias value remains escaped');
    check(str_contains($aliasHostile, '&lt;script&gt;bad()&lt;/script&gt;'), 'hostile alias escape is visible');
    check(!str_contains($adminHostile, '<script>domain()</script>'), 'hostile domain value remains escaped');
    check(str_contains($adminHostile, '&lt;script&gt;domain()&lt;/script&gt;'), 'hostile domain escape is visible');

    putString(
        $currentRoot . '/_skins/control/list/_purge-modal-header.phtml',
        PURGE_MODAL_HEADER . "\n<!-- skin-override-reached -->",
    );
    [$skinOutput] = renderTemplate(
        $currentRoot,
        $tmp . '/compiled-current',
        'alias/list.phtml',
        aliasVars('typical'),
        '/control/panel',
        'control',
    );
    check(str_contains($skinOutput, '<!-- skin-override-reached -->'), 'custom-skin partial override is selected');
    check(str_replace('<!-- skin-override-reached -->', '', $skinOutput) === $aliasTypical, 'custom-skin override preserves rendered markup');

    [$fallbackOutput] = renderTemplate(
        $currentRoot,
        $tmp . '/compiled-current',
        'admin/domains.phtml',
        adminVars('typical'),
        '/control/panel',
        'missing',
    );
    check(!str_contains($fallbackOutput, 'skin-override-reached'), 'missing skin partial falls back without override marker');
    check($fallbackOutput === $adminTypical, 'missing skin partial falls back to identical default output');

    $missingRoot = $tmp . '/missing';
    putString($missingRoot . '/header.phtml', '');
    putString($missingRoot . '/footer.phtml', '');
    putString($missingRoot . '/missing.phtml', "{tmplinclude file='list/_missing-purge-modal-header.phtml'}\n");
    $missingRejected = false;
    $missingMessage = 'no exception';
    try {
        renderTemplate($missingRoot, $tmp . '/compiled-missing', 'missing.phtml', [], '/control/panel', null, false);
    } catch (Throwable $error) {
        $missingMessage = $error->getMessage();
        $missingRejected = $error->getMessage() === 'Template file does not exist - [list/_missing-purge-modal-header.phtml]';
    }
    check($missingRejected, "missing partial fails at the intended tmplinclude error (got: {$missingMessage})");

    $outside = $tmp . '/outside.phtml';
    putString($outside, PURGE_MODAL_HEADER . "\n");
    $linkRoot = $tmp . '/link';
    putString($linkRoot . '/header.phtml', '');
    putString($linkRoot . '/footer.phtml', '');
    putString($linkRoot . '/link.phtml', "{tmplinclude file='list/_outside-purge-modal-header.phtml'}\n");
    mkdir($linkRoot . '/list', 0770, true);
    symlink($outside, $linkRoot . '/list/_outside-purge-modal-header.phtml');
    $linkRejected = false;
    $linkMessage = 'no exception';
    try {
        renderTemplate($linkRoot, $tmp . '/compiled-link', 'link.phtml', [], '/control/panel', null, false);
    } catch (Throwable $error) {
        $linkMessage = $error->getMessage();
        $linkRejected = $error->getMessage() === 'Template file is outside configured template roots - [list/_outside-purge-modal-header.phtml]';
    }
    check($linkRejected, "out-of-root partial symlink fails at the intended containment error (got: {$linkMessage})");
} catch (Throwable $error) {
    ListTemplateTestState::$failures++;
    fwrite(STDERR, 'ERROR: ' . get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if ($tmp !== null) {
        removeTree($tmp);
    }
}

if (ListTemplateTestState::$failures !== 0) {
    printf("not ok - %d assertion(s), %d failure(s)\n", ListTemplateTestState::$assertions, ListTemplateTestState::$failures);
    exit(1);
}

printf("ok - %d assertions across original/candidate Smarty renders\n", ListTemplateTestState::$assertions);
