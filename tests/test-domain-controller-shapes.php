<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}

use ViMbAdmin\Kernel\Controller\DomainController;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class DomainShapeSession implements SessionStorage
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values) {}
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __set(string $key, mixed $value): void { $this->set($key, $value); }
    public function __isset(string $key): bool { return $this->has($key); }
    public function __unset(string $key): void { $this->remove($key); }
}

final class DomainShapeBootstrap
{
    public int $doctrineReads = 0;
    /** @param array<string,mixed> $options */
    public function __construct(private DomainShapeSession $session, private array $options = []) {}
    public function getResource(string $name): mixed
    {
        if ($name === 'namespace') {
            return $this->session;
        }
        if ($name === 'doctrine2') {
            $this->doctrineReads++;
        }
        throw new LogicException('Unexpected resource read: ' . $name);
    }
    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }
}

/**
 * @param array<string,?string> $params
 * @param array<string,mixed> $options
 * @return array{0:DomainController,1:DomainShapeBootstrap}
 */
function domainShapeController(string $action, array $params, array $options = []): array
{
    $session = new DomainShapeSession(['identity' => ['id' => 1], 'csrfToken' => 'csrf-sentinel']);
    $bootstrap = new DomainShapeBootstrap($session, $options);
    $admin = (new Entities\Admin())
        ->setUsername('admin@example.test')
        ->setSuper(true)
        ->setActive(true);
    $container = new Container($bootstrap, new Auth($session, static fn(int $id): Entities\Admin => $admin));
    $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action)))) . 'Action';

    return [new DomainController(
        $container,
        new RouteMatch('domain', $action, DomainController::class, $method, $params),
    ), $bootstrap];
}

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};
$invoke = static function (string $method, mixed ...$arguments): mixed {
    return (new ReflectionMethod(DomainController::class, $method))->invoke(null, ...$arguments);
};
$fails = static function (callable $operation, string $message): bool {
    try {
        $operation();
    } catch (LogicException $exception) {
        return $exception->getMessage() === $message;
    }
    return false;
};

echo "== domain controller input and configuration shapes ==\n";

$check('canonical positive ids preserve integer identity',
    $invoke('positiveIntegerOrNull', 7) === 7
        && $invoke('positiveIntegerOrNull', '12') === 12);
$check('direct-cast mutant is killed by ambiguous route ids',
    $invoke('positiveIntegerOrNull', '01') === null
        && $invoke('positiveIntegerOrNull', '12junk') === null
        && $invoke('positiveIntegerOrNull', 0) === null
        && $invoke('positiveIntegerOrNull', true) === null
        && $invoke('positiveIntegerOrNull', ['12']) === null);

$request = $invoke('requestArray', ['draw' => '2', 'search' => ['value' => 'mail'], 0 => 'ignored']);
$check('DataTables boundary retains modern scalar and nested parameters',
    $request === ['draw' => '2', 'search' => ['value' => 'mail']]);
$check('DataTables container values fail before query parsing', $fails(
    static fn(): mixed => $invoke('requestArray', ['length' => ['500']]),
    'DataTables parameter length must be a string',
));

$check('absent and canonical INI booleans preserve pagination semantics',
    $invoke('optionBoolean', [], false, 'defaults', 'server_side', 'pagination', 'domain', 'enable') === false
        && $invoke('optionBoolean', ['defaults' => ['server_side' => ['pagination' => ['domain' => ['enable' => '1']]]]], false, 'defaults', 'server_side', 'pagination', 'domain', 'enable') === true);
$check('malformed present booleans cannot silently disable pagination', $fails(
    static fn(): mixed => $invoke('optionBoolean', ['defaults' => ['server_side' => ['pagination' => ['domain' => ['enable' => ['1']]]]]], false, 'defaults', 'server_side', 'pagination', 'domain', 'enable'),
    'Configuration defaults.server_side.pagination.domain.enable must be boolean',
));
$check('nested numeric configuration keys fail closed', $fails(
    static fn(): mixed => $invoke('optionArray', ['defaults' => ['domain' => [0 => 'bad']]], [], 'defaults', 'domain'),
    'Configuration defaults.domain must use string keys',
));

$oldGet = $_GET;
$_GET = ['search' => ['value' => 'abc']];
foreach ([
    'list-specific override' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 2,
        'domain' => ['min_search_str' => 4],
    ]]]],
    'shared fallback' => ['defaults' => ['server_side' => ['pagination' => [
        'min_search_str' => 4,
        'domain' => [],
    ]]]],
] as $case => $options) {
    [$searchController, $searchBootstrap] = domainShapeController('list-data', [], $options);
    $response = $searchController->listDataAction();
    $check("domain list-data enforces {$case} search minimum argument",
        $response->status === 400
            && $response->body === 'Search must be empty or at least 4 characters'
            && $searchBootstrap->doctrineReads === 0);
}
$_GET = $oldGet;

$check('quota multiplier accepts the complete shipped allowlist case-insensitively',
    $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'b']]]) === 'B'
        && $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'kb']]]) === 'KB'
        && $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'mb']]]) === 'MB'
        && $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'gb']]]) === 'GB');
$check('unsupported quota unit mutant is rejected before filter construction', $fails(
    static fn(): mixed => $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => 'TB']]]),
    'Configuration defaults.quota.multiplier is unsupported',
));
$check('container quota configuration never stringifies', $fails(
    static fn(): mixed => $invoke('quotaMultiplier', ['defaults' => ['quota' => ['multiplier' => ['MB']]]]),
    'Configuration defaults.quota.multiplier must be a string',
));

$check('checkbox values preserve native and HTML form booleans',
    $invoke('checkboxBoolean', false, 'Active') === false
        && $invoke('checkboxBoolean', '0', 'Active') === false
        && $invoke('checkboxBoolean', '1', 'Active') === true
        && $invoke('checkboxBoolean', true, 'Active') === true);
$check('truthy containers cannot enable a domain flag', $fails(
    static fn(): mixed => $invoke('checkboxBoolean', ['1'], 'Active'),
    'Active must be boolean',
));
$check('non-negative integer parsing rejects lossy coercion',
    $invoke('nonNegativeInteger', '0', 'Maximum aliases') === 0
        && $invoke('nonNegativeInteger', 9, 'Maximum aliases') === 9
        && $fails(
            static fn(): mixed => $invoke('nonNegativeInteger', '9junk', 'Maximum aliases'),
            'Maximum aliases must be a non-negative integer',
        ));

$filter = new OSS_Filter_FileSize('KB');
$check('quota conversion preserves validated finite byte values',
    $invoke('quotaBytes', '1.5', $filter, 'Domain quota') === 1536);
$check('quota containers fail before the legacy filter', $fails(
    static fn(): mixed => $invoke('quotaBytes', ['1'], $filter, 'Domain quota'),
    'Domain quota must be a string',
));
$check('default rendering accepts documented non-negative INI numbers',
    $invoke('nonNegativeIntegerDefault', 12, 'Aliases') === '12'
        && $invoke('nonNegativeIntegerDefault', '12', 'Aliases') === '12'
        && $invoke('nonNegativeNumberDefault', 1.5, 'Quota') === '1.5');
$check('default rendering rejects containers, booleans and negative values',
    $fails(static fn(): mixed => $invoke('nonNegativeIntegerDefault', ['12'], 'Aliases'), 'Aliases must be a non-negative integer')
        && $fails(static fn(): mixed => $invoke('nonNegativeIntegerDefault', true, 'Aliases'), 'Aliases must be a non-negative integer')
        && $fails(static fn(): mixed => $invoke('nonNegativeNumberDefault', '-1', 'Quota'), 'Quota must be a non-negative number'));
$check('explicit null domain defaults cannot masquerade as absent',
    $fails(static fn(): mixed => $invoke('requiredString', null, 'Configuration defaults.domain.transport'), 'Configuration defaults.domain.transport must be a string')
        && $fails(static fn(): mixed => $invoke('nonNegativeIntegerDefault', null, 'Configuration defaults.domain.aliases'), 'Configuration defaults.domain.aliases must be a non-negative integer')
        && $fails(static fn(): mixed => $invoke('nonNegativeNumberDefault', null, 'Configuration defaults.domain.quota'), 'Configuration defaults.domain.quota must be a non-negative number'));
$check('nullable repository labels fail before select rendering', $fails(
    static fn(): mixed => $invoke('requiredStringMap', [4 => null], 'Assignable administrator'),
    'Assignable administrator label must be a string',
));
$check('missing form field invariant is a controlled error', $fails(
    static fn(): mixed => $invoke('requiredField', new Form(), 'domain'),
    'Domain form field domain is missing',
));

$_GET = $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
[$invalidAdd, $invalidAddBootstrap] = domainShapeController('add', ['did' => '12junk']);
$invalidAddResponse = $invalidAdd->addAction();
$check('invalid present add alias id fails closed before repository access',
    $invalidAddResponse->status === 302
        && ($invalidAddResponse->headers['Location'] ?? null) === '/domain/list'
        && $invalidAddBootstrap->doctrineReads === 0);

[$removeWithoutCsrf, $removeBootstrap] = domainShapeController('remove-admin', ['did' => '1', 'aid' => '2']);
$removeResponse = $removeWithoutCsrf->removeAdminAction();
$check('administrator removal rejects missing CSRF before lookup or mutation',
    $removeResponse->status === 302
        && ($removeResponse->headers['Location'] ?? null) === '/domain/list'
        && $removeBootstrap->doctrineReads === 0);

[$toggleWithoutCsrf, $toggleBootstrap] = domainShapeController('ajax-toggle-active', ['did' => '1']);
$toggleResponse = $toggleWithoutCsrf->ajaxToggleActiveAction();
$check('active toggle rejects missing CSRF before lookup or mutation',
    $toggleResponse->body === 'ko' && $toggleBootstrap->doctrineReads === 0);

$adminsTemplate = file_get_contents(__DIR__ . '/../application/views/domain/admins.phtml');
$listScript = file_get_contents(__DIR__ . '/../application/views/domain/js/list.js');
$check('administrator removal posts the CSRF token in the request body (VIM-D05)',
    is_string($adminsTemplate)
        // The token must live INSIDE the removal form: asserting the class and
        // the hidden field independently would pass with the field in some
        // other form on the page and the removal request unprotected.
        && preg_match(
            '/<form\b[^>]*class="remove-admin-form"[^>]*>(?:(?!<\/form>).)*'
            . 'name="csrf" value="\{\$csrfToken\|escape\}"/s',
            $adminsTemplate,
        ) === 1
        && !str_contains($adminsTemplate, 'csrf=$csrfToken'));
$check('active-toggle request carries the rendered CSRF token',
    is_string($listScript) && str_contains($listScript, '"csrf": "{$csrfToken}"'));

$controller = (new ReflectionClass(DomainController::class))->newInstanceWithoutConstructor();
$apply = new ReflectionMethod(DomainController::class, 'applyFormFields');
$domain = (new Entities\Domain())
    ->setDescription('old description')
    ->setTransport('old transport')
    ->setBackupmx(false)
    ->setActive(false)
    ->setMaxAliases(1)
    ->setMaxMailboxes(2)
    ->setQuota(3)
    ->setMaxQuota(4);
$apply->invoke($controller, $domain, [
    'description' => '',
    'transport' => 'virtual',
    'backupmx' => '1',
    'active' => true,
    'max_aliases' => '12',
    'max_mailboxes' => 13,
    'quota' => '2',
    'max_quota' => '3',
], $filter);
$check('validated form mapping preserves exact entity values',
    $domain->getDescription() === ''
        && $domain->getTransport() === 'virtual'
        && $domain->getBackupmx() === true
        && $domain->getActive() === true
        && $domain->getMaxAliases() === 12
        && $domain->getMaxMailboxes() === 13
        && $domain->getQuota() === 2048
        && $domain->getMaxQuota() === 3072);

$domain->setDescription('sentinel')->setTransport('sentinel')->setMaxQuota(77);
$lateFailure = $fails(
    static fn(): mixed => $apply->invoke($controller, $domain, [
        'description' => 'mutated', 'transport' => 'virtual', 'backupmx' => '1', 'active' => '1',
        'max_aliases' => '12', 'max_mailboxes' => '13', 'quota' => '2', 'max_quota' => ['3'],
    ], $filter),
    'Maximum mailbox quota must be a string',
);
$check('late malformed form value causes zero partial entity mutation',
    $lateFailure
        && $domain->getDescription() === 'sentinel'
        && $domain->getTransport() === 'sentinel'
        && $domain->getMaxQuota() === 77);

$check('fixed assertion count', $checks === 29);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
