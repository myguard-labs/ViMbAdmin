<?php

declare(strict_types=1);

require __DIR__ . '/../tests/support/native-workflow-harness.php';
require_once __DIR__ . '/../application/Entities/McpToken.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\Controller\IndexController;
use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\Input\Reader;
use ViMbAdmin\Kernel\Mvc\AbstractController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;

/** @param array<string,?string> $params */
function inputContractIndexController(Container $container, array $params = []): IndexController
{
    return new IndexController($container, new RouteMatch('index', 'index', IndexController::class, 'indexAction', $params));
}

// This characterizes the original adapter owners and their shared Reader contract.
// Reflection is not action coverage; lifecycle suites own those boundaries.
$failures = 0;
$counts = [];
$check = static function (string $group, string $label, bool $ok) use (&$failures, &$counts): void {
    $counts[$group] = ($counts[$group] ?? 0) + 1;
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
};
$invoke = static function (string|object $owner, string $method, mixed ...$args): mixed {
    return (new ReflectionMethod($owner, $method))->invoke(is_object($owner) ? $owner : null, ...$args);
};
$error = static function (callable $operation, string $class, string $message): bool {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception::class === $class && $exception->getMessage() === $message;
    }
    return false;
};
$returns = static function (callable $operation, mixed $expected): bool {
    try {
        return $operation() === $expected;
    } catch (Throwable) {
        return false;
    }
};
$prefix = 'ViMbAdmin\\Kernel\\Controller\\';
$strictControllerString = static fn (string $owner, mixed $value, string $name): string => Reader::requiredString($value, $name);
$configControllerString = static function (string $owner, array $options, string $default, string ...$path) use ($invoke, $prefix): string {
    // Reflection deliberately crosses the documented string-key boundary for malformed-config controls.
    $value = $owner === 'Auth'
        ? $invoke($prefix . 'AuthController', 'optionString', $options, $default, ...$path)
        : $invoke(Reader::class, 'optionString', $options, $default, array_values($path));
    if (!is_string($value)) {
        throw new UnexpectedValueException('Reflected configuration string did not return a string');
    }
    return $value;
};
// Literal byte oracles, including PHP trim bytes and Unicode boundary spaces.
$strings = [
    'empty' => ['', ''],
    'ordinary' => ['Value', 'Value'],
    'zero' => ['0', '0'],
    'whitespace-only' => [" \t\r\n", " \t\r\n"],
    'ASCII/password boundary bytes' => [" \tpassword boundary\r\n", " \tpassword boundary\r\n"],
    'trim bytes' => ["\0\x0b\tValue\r\n\0", "\0\x0b\tValue\r\n\0"],
    'NBSP boundary' => ["\u{00a0}Value\u{00a0}", "\u{00a0}Value\u{00a0}"],
    'EM SPACE boundary' => ["\u{2003}Value\u{2003}", "\u{2003}Value\u{2003}"],
    'embedded NUL' => ["V\0alue", "V\0alue"],
    'invalid UTF-8' => ["\xff", "\xff"],
];
$nonstrings = [
    'null' => null, 'integer without coercion' => 42, 'zero integer' => 0,
    'float' => 1.25, 'true' => true, 'false' => false,
    'empty array' => [], 'list' => ['value'], 'map' => ['field' => 'value'], 'object' => new stdClass(),
];

echo "== controller string input contracts ==\n";
$strictOwners = ['Admin', 'Alias', 'Auth', 'Domain', 'Mailbox', 'Maintenance', 'Queue'];
foreach ($strictOwners as $owner) {
    foreach ($strings as $label => [$input, $expected]) {
        $check('strict', "C01 {$owner} preserves {$label}", $strictControllerString($owner, $input, 'Field') === $expected);
    }
    foreach ($nonstrings as $label => $input) {
        $check('strict', "C01 {$owner} rejects {$label}", $error(
            static fn () => $strictControllerString($owner, $input, 'Field'),
            LogicException::class,
            'Field must be a string',
        ));
    }
}

foreach (['Auth', 'Domain', 'Mailbox'] as $owner) {
    foreach ($strings as $label => [$input, $expected]) {
        $check('default', "C01 {$owner} null-default preserves {$label}", $invoke($prefix . $owner . 'Controller', 'stringOrDefault', $input, 'DEFAULT', 'Field') === $expected);
    }
    $check('default', "C01 {$owner} null-default accepts null", $invoke($prefix . $owner . 'Controller', 'stringOrDefault', null, 'DEFAULT', 'Field') === 'DEFAULT');
    foreach (array_diff_key($nonstrings, ['null' => null]) as $label => $input) {
        $check('default', "C01 {$owner} null-default rejects {$label}", $error(
            static fn () => $invoke($prefix . $owner . 'Controller', 'stringOrDefault', $input, 'DEFAULT', 'Field'),
            LogicException::class,
            'Field must be a string',
        ));
    }
}

foreach (['Admin', 'Auth', 'Domain', 'Mailbox', 'Queue'] as $owner) {
    foreach ($strings as $label => [$input, $expected]) {
        $check('config', "C01 {$owner} config preserves {$label}", $configControllerString($owner, ['outer' => ['leaf' => $input]], 'DEFAULT', 'outer', 'leaf') === $expected);
    }
    foreach ([[], ['outer' => []]] as $missing) {
        $check('config', "C01 {$owner} missing config uses default", $configControllerString($owner, $missing, 'DEFAULT', 'outer', 'leaf') === 'DEFAULT');
    }
    if ($owner === 'Auth') {
        $check('config', 'C01 Auth explicit null configuration uses its default', $returns(static fn () => $configControllerString($owner, ['outer' => ['leaf' => null]], 'DEFAULT', 'outer', 'leaf'), 'DEFAULT'));
    } else {
        $check('config', "C01 {$owner} explicit null configuration rejects", $error(
            static fn () => $configControllerString($owner, ['outer' => ['leaf' => null]], 'DEFAULT', 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer.leaf must be a string',
        ));
    }
    foreach (array_diff_key($nonstrings, ['null' => null]) as $label => $input) {
        $check('config', "C01 {$owner} malformed config leaf {$label}", $error(
            static fn () => $configControllerString($owner, ['outer' => ['leaf' => $input]], 'DEFAULT', 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer.leaf must be a string',
        ));
    }
    foreach ([
        [['outer' => null], 'Configuration outer must be an array'],
        [['outer' => 'value'], 'Configuration outer must be an array'],
        [['outer' => ['value']], 'Configuration outer must use string keys'],
        [['value'], 'Configuration root must use string keys'],
    ] as [$options, $message]) {
        $check('config', "C01 {$owner} config shape {$message}", $error(static fn () => $configControllerString($owner, $options, 'DEFAULT', 'outer', 'leaf'), LogicException::class, $message));
    }
    $check('config', "C01 {$owner} config empty path is not missing", $error(static fn () => $configControllerString($owner, [], 'DEFAULT'), LogicException::class, 'Configuration  must be a string'));
    $check('config', "C01 {$owner} literal arrayName config key", $configControllerString($owner, ['arrayName' => ['leaf' => 'exact']], 'DEFAULT', 'arrayName', 'leaf') === 'exact');
}

// C02 removes only these C01-approved delegates. The owner-labelled contract
// rows above still exercise their exact shared primitive, while action suites
// and the map assertion below exercise real controller calls.
foreach ($strictOwners as $owner) {
    $class = $prefix . $owner . 'Controller';
    $source = file_get_contents(dirname(__DIR__) . "/src/Kernel/Controller/{$owner}Controller.php");
    $check('consolidation', "C02 {$owner} strict delegate is removed and consumers use Reader", !method_exists($class, 'requiredString')
        && is_string($source) && str_contains($source, '\\ViMbAdmin\\Kernel\\Input\\Reader::requiredString('));
}
foreach (['Admin', 'Domain', 'Mailbox', 'Queue'] as $owner) {
    $class = $prefix . $owner . 'Controller';
    $source = file_get_contents(dirname(__DIR__) . "/src/Kernel/Controller/{$owner}Controller.php");
    $check('consolidation', "C02 {$owner} strict config delegate is removed and consumers use Reader", !method_exists($class, 'optionString')
        && is_string($source) && str_contains($source, '\\ViMbAdmin\\Kernel\\Input\\Reader::optionString('));
}

foreach (['Auth', 'Mailbox'] as $owner) {
    $class = $prefix . $owner . 'Controller';
    $check('nullable', "C01 {$owner} missing nullable config", $invoke($class, 'optionNullableString', [], 'outer', 'leaf') === null);
    if ($owner === 'Auth') {
        $check('nullable', 'C01 Auth nullable config retains explicit null', $invoke($class, 'optionNullableString', ['outer' => ['leaf' => null]], 'outer', 'leaf') === null);
    } else {
        $check('nullable', 'C01 Mailbox nullable config rejects explicit null', $error(static fn () => $invoke($class, 'optionNullableString', ['outer' => ['leaf' => null]], 'outer', 'leaf'), LogicException::class, 'Configuration outer.leaf must be a string'));
    }
    foreach ($strings as $label => [$input, $expected]) {
        $check('nullable', "C01 {$owner} nullable config preserves {$label}", $invoke($class, 'optionNullableString', ['outer' => ['leaf' => $input]], 'outer', 'leaf') === $expected);
    }
    $check('nullable', "C01 {$owner} nullable config rejects array", $error(static fn () => $invoke($class, 'optionNullableString', ['outer' => ['leaf' => []]], 'outer', 'leaf'), LogicException::class, 'Configuration outer.leaf must be a string'));
}

foreach (['Admin', 'Maintenance', 'Queue'] as $owner) {
    foreach ($strings as $label => [$input, $expected]) {
        $check('fallback', "C01 {$owner} POST fallback preserves {$label}", $invoke($prefix . $owner . 'Controller', 'postStringOrEmpty', $input) === $expected);
    }
    foreach ($nonstrings as $label => $input) {
        $check('fallback', "C01 {$owner} POST fallback empties {$label}", $invoke($prefix . $owner . 'Controller', 'postStringOrEmpty', $input) === '');
    }
}

$mailbox = $prefix . 'MailboxController';
$maintenance = $prefix . 'MaintenanceController';
foreach ([['optionalString', $mailbox], ['optionalPostString', $maintenance]] as [$method, $class]) {
    $check('optional', "C01 {$method} null stays null", $invoke($class, $method, null, 'Field') === null);
    foreach ($strings as $label => [$input, $expected]) {
        $emptyExpected = $method === 'optionalString' && $label === 'empty' ? null : $expected;
        $check('optional', "C01 {$method} preserves {$label} policy", $invoke($class, $method, $input, 'Field') === $emptyExpected);
    }
    foreach (array_diff_key($nonstrings, ['null' => null]) as $label => $input) {
        $check('optional', "C01 {$method} rejects {$label}", $error(static fn () => $invoke($class, $method, $input, 'Field'), LogicException::class, 'Field must be a string'));
    }
}
foreach ($strings as $label => [$input, $expected]) {
    $check('render', "C01 render preserves {$label}", $invoke($mailbox, 'renderStringOrDefault', $input, 'DEFAULT') === $expected);
}
foreach ($nonstrings as $label => $input) {
    $check('render', "C01 render defaults {$label}", $invoke($mailbox, 'renderStringOrDefault', $input, 'DEFAULT') === 'DEFAULT');
}
foreach ([['', ''], [42, '42'], [1.25, '1.25'], [true, '1'], [false, ''], [" \tvalue\r\n", " \tvalue\r\n"]] as [$input, $expected]) {
    $check('single', 'C01 display value retains literal scalar conversion', $invoke($mailbox, 'displaySettingString', $input, 'Field') === $expected);
}
foreach ([null, [], new stdClass()] as $input) {
    $check('single', 'C01 display rejects non-scalar', $error(static fn () => $invoke($mailbox, 'displaySettingString', $input, 'Field'), LogicException::class, 'Field must be a scalar display value'));
}
foreach ([[null, null], ['', null], [[], null], [42, null], ['secret', 'secret'], [' ', ' ']] as [$input, $expected]) {
    $check('single', 'C01 session secret absent/empty/exact policy', $invoke($prefix . 'AdminController', 'sessionSecret', $input) === $expected);
}
foreach ([[null, 'DEFAULT'], ['', ''], [[], 'DEFAULT'], [42, 'DEFAULT'], ['/admin/index', '/admin/index'], ['//outside.test', 'DEFAULT'], ['https://outside.test', 'DEFAULT'], ["/admin\n", 'DEFAULT']] as [$input, $expected]) {
    $check('single', 'C01 redirect path keeps local-path guard', $invoke(AuthController::class, 'applicationPathOrDefault', $input, 'DEFAULT') === $expected);
}
foreach (['ordinary', " with spaces ", "\u{00a0}value"] as $input) {
    $check('single', 'C01 control-safe strings preserve allowed bytes', $invoke($mailbox, 'controlSafeString', $input, 'Field') === $input);
}
foreach (["value\0", "value\r", "value\n", "value\x7f"] as $input) {
    $check('single', 'C01 control-safe string rejects controls', $error(static fn () => $invoke($mailbox, 'controlSafeString', $input, 'Field'), LogicException::class, 'Field contains control characters'));
}
foreach ([[], null, 'value'] as $row) {
    $check('single', 'C01 row username requires keyed row', $error(static fn () => $invoke($maintenance, 'rowUsername', $row, 'Row'), LogicException::class, 'Row must contain a username'));
}
$check('single', 'C01 row username keeps exact bytes', $invoke($maintenance, 'rowUsername', ['username' => ' User '], 'Row') === ' User ');
$check('single', 'C01 row username rejects null value', $error(static fn () => $invoke($maintenance, 'rowUsername', ['username' => null], 'Row'), LogicException::class, 'Row must be a string'));
$check('single', 'C01 labelled string map keeps labels and bytes', $invoke($prefix . 'DomainController', 'requiredStringMap', [7 => ' User ', 'key' => ''], 'Domain') === [7 => ' User ', 'key' => '']);
$check('single', 'C01 labelled string map rejects array values', $error(static fn () => $invoke($prefix . 'DomainController', 'requiredStringMap', ['key' => []], 'Domain'), LogicException::class, 'Domain label must be a string'));
foreach (['', '.', '..', '../name', 'name/child', 'name\\child', "name\0", []] as $input) {
    $check('single', 'C01 maildir name rejects unsafe input', $error(static fn () => $invoke($maintenance, 'safeMaildirName', $input), LogicException::class, 'Maildir name is not a safe path component'));
}
$check('single', 'C01 maildir name preserves valid identity bytes', $invoke($maintenance, 'safeMaildirName', 'User@example.test') === 'User@example.test');
foreach ([['pwhash' => 'crypt:sha512'], 'crypt:sha512'] as $input) {
    $check('single', 'C01 password options retain array-or-string contract', $invoke(AuthController::class, 'passwordOptions', ['resources' => ['auth' => ['oss' => $input]]]) === $input);
}
$check('single', 'C01 password options reject null leaf', $error(static fn () => $invoke(AuthController::class, 'passwordOptions', ['resources' => ['auth' => ['oss' => null]]]), LogicException::class, 'Configuration resources.auth.oss must be an array'));

// Existing WorkflowSession supplies both the SessionStorage and magic-property
// interfaces used by the real Container/Auth/CSRF path; no database is created.
$session = new WorkflowSession();
$bootstrap = new class ($session) {
    public function __construct(private WorkflowSession $session)
    {
    }
    /** @return array<string,mixed> */
    public function getOptions(): array
    {
        return [];
    }
    public function getResource(string $name): mixed
    {
        if ($name !== 'namespace') {
            throw new LogicException('Unexpected fixture resource: ' . $name);
        }
        return $this->session;
    }
};
$container = new Container($bootstrap, new Auth($session, static fn (int $id): ?object => null));
$saved = [$_GET, $_POST, $_SERVER];
try {
    foreach ([
        [['field' => 'route'], ['field' => 'post'], ['field' => 'query'], 'route'],
        [['field' => null], ['field' => 'post'], ['field' => 'query'], 'post'],
        [[], ['field' => null], ['field' => 'query'], 'query'],
        [[], [], [], 'DEFAULT'],
        [['field' => ''], ['field' => 'post'], [], ''],
        [[], ['field' => ['array']], [], ['array']],
        [[], ['field' => " \tvalue\r\n"], [], " \tvalue\r\n"],
    ] as [$route, $post, $query, $expected]) {
        $_POST = $post;
        $_GET = $query;
        $check('request', 'C01 inherited parameter preserves precedence/type/bytes', $invoke(inputContractIndexController($container, $route), 'param', 'field', 'DEFAULT') === $expected);
    }
    $_POST = ['field' => ['nested']];
    $check('request', 'C01 POST map preserves nested field for its consumer', $invoke(inputContractIndexController($container), 'postData') === ['field' => ['nested']]);
    $_POST = ['numeric key'];
    $check('request', 'C01 POST map retains TypeError', $error(static fn () => $invoke(inputContractIndexController($container), 'postData'), TypeError::class, 'POST data must use string keys'));
    foreach (['Alias', 'Archive', 'Domain', 'Mailbox'] as $owner) {
        $class = $prefix . $owner . 'Controller';
        $map = static function (array $request) use ($owner, $invoke, $class): array {
            $mapped = $owner === 'Alias'
                ? $invoke($class, 'requestArray', $request)
                : DataTableQuery::requestArray($request);
            if (!is_array($mapped)) {
                throw new UnexpectedValueException('Reflected request adapter did not return an array');
            }
            return $mapped;
        };
        $check('maps', "C01 {$owner} DataTable map rejects container draw", $error(static fn () => $map(['draw' => []]), LogicException::class, 'DataTables parameter draw must be a string'));
        $check('maps', "C01 {$owner} DataTable map preserves exact scalar and nested bytes", $map(['draw' => '0', 'search' => ['value' => ' exact '], 0 => 'discard']) === ['draw' => '0', 'search' => ['value' => ' exact ']]);
        if ($owner === 'Alias') {
            $check('maps', 'C01 Alias rejects non-string additional DataTable scalar', $error(static fn () => $map(['extra' => []]), LogicException::class, 'DataTables parameter extra must be a string'));
        } else {
            $check('maps', "C01 {$owner} retains unvalidated additional DataTable key", $map(['extra' => []]) === ['extra' => []]);
        }
    }
    $index = inputContractIndexController($container);
    foreach ([[], ['csrf' => null], ['csrf' => ''], ['csrf' => []], ['csrf' => 'wrong']] as $post) {
        $_POST = $post;
        $_GET = ['csrf' => 'workflow-csrf'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $check('csrf', 'C01 query token cannot satisfy body-only POST CSRF', $invoke($index, 'postBodyCsrfValid') === false);
    }
    $_POST = ['csrf' => 'workflow-csrf'];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $check('csrf', 'C01 exact valid POST body token passes', $invoke($index, 'postBodyCsrfValid') === true);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $check('csrf', 'C01 GET cannot satisfy body-only POST CSRF', $invoke($index, 'postBodyCsrfValid') === false);
    $_POST = [];
    $_GET = ['csrf' => 'workflow-csrf'];
    $check('csrf', 'C01 merged legacy CSRF accepts exact query token', $invoke($index, 'csrfValid') === true);
    $_GET = ['csrf' => []];
    $check('csrf', 'C01 merged CSRF rejects container token', $invoke($index, 'csrfValid') === false);
    $_SERVER = [];
    $check('request', 'C01 missing server header becomes empty', $invoke($prefix . 'QueueController', 'serverString', 'HTTP_AUTHORIZATION') === '');
    foreach ([[null, ''], ['', ''], [" Bearer token \t", " Bearer token \t"]] as [$input, $expected]) {
        $_SERVER = ['HTTP_AUTHORIZATION' => $input];
        $check('request', 'C01 server header keeps null/exact policy', $invoke($prefix . 'QueueController', 'serverString', 'HTTP_AUTHORIZATION') === $expected);
    }
    $_SERVER = ['HTTP_AUTHORIZATION' => []];
    $check('request', 'C01 malformed server header throws labelled error', $error(static fn () => $invoke($prefix . 'QueueController', 'serverString', 'HTTP_AUTHORIZATION'), LogicException::class, 'Server parameter HTTP_AUTHORIZATION must be a string'));

    $auth = new AuthController($container, new RouteMatch('auth', 'login', AuthController::class, 'loginAction', []));
    foreach (['buildLoginForm', 'buildSetupForm', 'buildResetPasswordForm'] as $builder) {
        $form = $invoke($auth, $builder);
        $check('form', "C01 {$builder} is a real form", $form instanceof Form);
        if (!$form instanceof Form) {
            throw new LogicException('Real Auth form builder did not return a form');
        }
        $form->bind(['username' => 'user@example.test', 'password' => " \tpassword boundary\r\n", 'csrf' => 'workflow-csrf']);
        $check('form', "C01 {$builder} preserves submitted password bytes", $form->values()['password'] === " \tpassword boundary\r\n");
        foreach ([[], ['password' => null], ['password' => ''], ['password' => " \t\r\n"], ['password' => []]] as $post) {
            $form->bind($post + ['csrf' => 'workflow-csrf']);
            $check('form', "C01 {$builder} rejects missing/malformed/blank password", !$form->isValid() && array_key_exists('password', $form->errors()));
        }
    }
} finally {
    [$_GET, $_POST, $_SERVER] = $saved;
}

// Map-only MCP adapters can execute without constructing an EntityManager.
$mutation = (new ReflectionClass(ViMbAdmin_Mcp_MutationOperations::class))->newInstanceWithoutConstructor();
foreach ([
    ['string', [], '', null],
    ['string', ['field' => null], null, 'param "field" must be a string'],
    ['string', ['field' => []], null, 'param "field" must be a string'],
    ['string', ['field' => " \tValue\r\n"], 'Value', null],
    ['string', ['field' => ''], '', null],
    ['requiredExactString', [], null, 'param "field" required'],
    ['requiredExactString', ['field' => null], null, 'param "field" must be a string'],
    ['requiredExactString', ['field' => []], null, 'param "field" must be a string'],
    ['requiredExactString', ['field' => ''], null, 'param "field" required'],
    ['requiredExactString', ['field' => " \tpassword boundary\r\n"], " \tpassword boundary\r\n", null],
    ['requiredExactString', ['field' => ' '], ' ', null],
    ['identity', [], null, 'param "field" required'],
    ['identity', ['field' => null], null, 'param "field" must be a string'],
    ['identity', ['field' => []], null, 'param "field" must be a string'],
    ['identity', ['field' => ''], null, 'param "field" required'],
    ['identity', ['field' => 'Alice@EXAMPLE.test'], 'alice@example.test', null],
    ['identity', ['field' => ' Alice@example.test '], null, 'param "field" must not contain surrounding whitespace'],
    ['identity', ['field' => "\u{00a0}alice@example.test"], null, 'param "field" must not contain surrounding whitespace'],
    ['identity', ['field' => "alice@example.test\u{2003}"], null, 'param "field" must not contain surrounding whitespace'],
    ['identity', ['field' => "alice\xff@example.test"], null, 'param "field" must be valid UTF-8'],
] as [$method, $params, $expected, $message]) {
    $operation = static fn () => $invoke($mutation, $method, $params, 'field');
    $check('mcp', "C01 MCP {$method} literal map contract", $message === null ? $operation() === $expected : $error($operation, ViMbAdmin_Mcp_Exception::class, $message));
}
foreach ($nonstrings as $label => $input) {
    foreach (['string', 'exactString', 'identity'] as $method) {
        $check('mcp', "C01 MCP {$method} rejects {$label}", $error(static fn () => ViMbAdmin_Mcp_Input::$method($input, 'Field', true), ViMbAdmin_Mcp_Exception::class, 'Field must be a string'));
    }
}
foreach ([
    ['string', "\0\x0b\t Value\r\n\0", false, 'Value', null],
    ['string', "\u{00a0}Value\u{00a0}", false, "\u{00a0}Value\u{00a0}", null],
    ['string', ' ', true, null, 'Field required'],
    ['exactString', ' ', true, ' ', null],
    ['exactString', '', false, '', null],
    ['exactString', '', true, null, 'Field required'],
] as [$method, $input, $required, $expected, $message]) {
    $operation = static fn () => ViMbAdmin_Mcp_Input::$method($input, 'Field', $required);
    $check('mcp', 'C01 MCP trimmed/exact required differences', $message === null ? $operation() === $expected : $error($operation, ViMbAdmin_Mcp_Exception::class, $message));
}
$check('mcp', 'C01 MCP nullable exact string keeps null', ViMbAdmin_Mcp_Input::exactNullableString(null, 'Field') === null);
$check('mcp', 'C01 MCP nullable exact string keeps empty', ViMbAdmin_Mcp_Input::exactNullableString('', 'Field') === '');
$check('mcp', 'C01 MCP nullable exact rejects array', $error(static fn () => ViMbAdmin_Mcp_Input::exactNullableString([], 'Field'), ViMbAdmin_Mcp_Exception::class, 'Field must be a string'));
$check('mcp', 'C01 MCP absent config keeps default', ViMbAdmin_Mcp_Input::optionString([], 'DEFAULT', 'outer', 'leaf') === 'DEFAULT');
$check('mcp', 'C01 MCP config string retains trimming', ViMbAdmin_Mcp_Input::optionString(['outer' => ['leaf' => ' Value ']], 'DEFAULT', 'outer', 'leaf') === 'Value');
$check('mcp', 'C01 MCP explicit null config is not absent', $error(static fn () => ViMbAdmin_Mcp_Input::optionString(['outer' => ['leaf' => null]], 'DEFAULT', 'outer', 'leaf'), ViMbAdmin_Mcp_Exception::class, 'configuration outer.leaf must be a string'));

/** @var ArrayObject<int,string> $events */
$events = new ArrayObject();
$dispatcher = new ViMbAdmin_Mcp_Dispatcher(
    ['string.contract' => ['scope' => 'write', 'destructive' => true, 'handler' => static function (array $params) use ($events): array {
        $events[] = 'handler';
        return ['value' => ViMbAdmin_Mcp_Input::string($params['field'] ?? null, 'Field', true)];
    }]],
    static function (array $server, string $scope) use ($events): Entities\McpToken {
        $events[] = 'auth:' . $scope;
        return new Entities\McpToken();
    },
    static function (Entities\McpToken $token) use ($events): void {
        $events[] = 'rate';
    },
);
foreach ([
    [[], ['code' => -32602, 'message' => 'Field must be a string']],
    [['field' => null], ['code' => -32602, 'message' => 'Field must be a string']],
    [['field' => []], ['code' => -32602, 'message' => 'Field must be a string']],
    [['field' => ''], ['code' => -32602, 'message' => 'Field required']],
] as [$params, $expected]) {
    $events->exchangeArray([]);
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 'literal-id', 'method' => 'string.contract', 'params' => $params], JSON_THROW_ON_ERROR);
    $response = $dispatcher->dispatch(true, 'POST', static fn (): string => $body, []);
    $check('transport', 'C01 MCP malformed scalar retains HTTP 200, exact id, -32602 and public invalid-param message', $response->status === 200 && json_decode($response->body, true, 512, JSON_THROW_ON_ERROR) === ['jsonrpc' => '2.0', 'id' => 'literal-id', 'error' => $expected]);
    $check('transport', 'C01 MCP validates scalar after auth and destructive rate limit', $events->getArrayCopy() === ['auth:write', 'rate', 'handler']);
}

$snapshot = ['username' => 'alice@example.test', 'local_part' => 'alice', 'name' => null, 'password' => '{CRYPT}fixture-hash', 'quota' => 1, 'active' => true];
foreach (['username', 'local_part', 'name', 'password'] as $field) {
    foreach ([
        ['missing', null], ['null', null], ['empty', ''], ['array', []],
        ['padded', " \tboundary\r\n"],
    ] as [$label, $value]) {
        $input = $snapshot;
        if ($label === 'missing') {
            unset($input[$field]);
        } else {
            $input[$field] = $value;
        }
        foreach (['HTTP', 'MCP'] as $transport) {
            $operation = $transport === 'HTTP'
                ? static fn () => $invoke($prefix . 'ArchiveController', 'mailboxSnapshot', $input)
                : static fn () => ViMbAdmin_Mcp_Input::mailboxSnapshot($input);
            $name = $transport === 'HTTP' ? 'Archive mailbox snapshot' : 'archive mailbox snapshot';
            $exception = $transport === 'HTTP' ? LogicException::class : ViMbAdmin_Mcp_Exception::class;
            if ($label === 'missing') {
                $ok = $error($operation, $exception, "{$name} missing {$field}");
            } elseif ($field === 'name' && ($label === 'null' || $label === 'empty' || $label === 'padded')) {
                $ok = $operation() === $input;
            } elseif ($label === 'padded') {
                $ok = $operation() === $input;
            } elseif ($transport === 'HTTP') {
                $message = $field === 'name' ? "{$name} name must be a string or null" : "{$name} {$field} must be a non-empty string";
                $ok = $error($operation, $exception, $message);
            } else {
                $message = $label === 'empty' ? "{$name} {$field} required" : "{$name} {$field} must be a string";
                $ok = $error($operation, $exception, $message);
            }
            $check('snapshot', "C01 {$transport} snapshot {$field} {$label}", $ok);
        }
    }
}
$malformedSnapshot = array_replace($snapshot, ['active' => '1']);
$check('snapshot', 'C01 HTTP snapshot retains its boolean type error', $error(static fn () => $invoke($prefix . 'ArchiveController', 'mailboxSnapshot', $malformedSnapshot), LogicException::class, 'Archive mailbox snapshot active must be boolean'));
$check('snapshot', 'C01 MCP snapshot retains its boolean type error', $error(static fn () => ViMbAdmin_Mcp_Input::mailboxSnapshot($malformedSnapshot), ViMbAdmin_Mcp_Exception::class, 'archive mailbox snapshot active must be a boolean'));

// Numeric adapters have different missing/null/error contracts even when they
// recognize the same integer values. Keep the original 703 string assertions.
$integerMaxString = (string) PHP_INT_MAX;
$aboveIntegerMax = PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648';
$belowIntegerMin = PHP_INT_SIZE === 8 ? '-9223372036854775809' : '-2147483649';
$integerValues = [
    'zero integer' => [0, 0], 'zero string' => ['0', 0],
    'ordinary integer' => [7, 7], 'ordinary string' => ['7', 7],
    'maximum integer' => [PHP_INT_MAX, PHP_INT_MAX], 'maximum string' => [$integerMaxString, PHP_INT_MAX],
];
$invalidIntegers = [
    'null' => null, 'empty' => '', 'leading zero' => '01', 'multiple zeroes' => '00',
    'negative zero string' => '-0', 'negative integer' => -1, 'minimum integer' => PHP_INT_MIN,
    'negative string' => '-1', 'positive sign' => '+1', 'leading space' => ' 1', 'trailing space' => '1 ',
    'decimal exponent' => '1e2', 'decimal string' => '1.0', 'integer float' => 1.0, 'fraction' => 1.25,
    'negative zero float' => -0.0, 'true' => true, 'false' => false,
    'array' => [], 'object' => new stdClass(), 'suffix' => '1junk', 'NUL suffix' => "1\0",
    'one-above-integer-max' => $aboveIntegerMax, 'below-integer-min' => $belowIntegerMin,
    'infinite' => INF, 'negative infinite' => -INF, 'not-a-number' => NAN,
];
$positiveValues = array_diff_key($integerValues, ['zero integer' => null, 'zero string' => null]);
$invalidIds = ['zero integer' => 0, 'zero string' => '0'] + $invalidIntegers;
foreach (['Admin', 'Alias', 'Archive', 'Auth', 'Domain', 'Mailbox', 'Queue'] as $owner) {
    $method = $owner === 'Auth' ? 'integerOrNull' : 'positiveIntegerOrNull';
    $class = $prefix . $owner . 'Controller';
    foreach ($positiveValues as $label => [$input, $expected]) {
        $check('numeric-ids', "C01b {$owner} accepts {$label} ID", $returns(static fn () => $invoke($class, $method, $input), $expected));
    }
    foreach ($invalidIds as $label => $input) {
        $check('numeric-ids', "C01b {$owner} rejects {$label} ID", $returns(static fn () => $invoke($class, $method, $input), null));
    }
}

$numericOwners = [
    ['Reader', \ViMbAdmin\Kernel\Input\Reader::class, 'nonNegativeInteger', LogicException::class],
    ['Domain', \ViMbAdmin\Kernel\Input\Reader::class, 'nonNegativeInteger', LogicException::class],
    ['Archive', \ViMbAdmin\Kernel\Input\Reader::class, 'nonNegativeInteger', LogicException::class],
    ['Inherited', AbstractController::class, 'nonNegativeInt', TypeError::class],
    ['MCP', ViMbAdmin_Mcp_Input::class, 'nonNegativeInteger', ViMbAdmin_Mcp_Exception::class],
];
foreach ($numericOwners as [$owner, $class, $method, $exception]) {
    foreach ($integerValues as $label => [$input, $expected]) {
        $check('numeric-strict', "C01b {$owner} accepts {$label}", $returns(static fn () => $invoke($class, $method, $input, 'Number'), $expected));
    }
    foreach ($invalidIntegers as $label => $input) {
        $check('numeric-strict', "C01b {$owner} rejects {$label}", $error(static fn () => $invoke($class, $method, $input, 'Number'), $exception, 'Number must be a non-negative integer'));
    }
}
foreach ($positiveValues as $label => [$input, $expected]) {
    $check('numeric-log', "C01b Log accepts {$label}", $returns(static fn () => $invoke($prefix . 'LogController', 'positiveId', $input, 'id'), $expected));
}
foreach ($invalidIds as $label => $input) {
    $check('numeric-log', "C01b Log retains TypeError for {$label}", $error(static fn () => $invoke($prefix . 'LogController', 'positiveId', $input, 'id'), TypeError::class, 'id must be a positive integer'));
}

foreach (['Auth', 'Mailbox', 'Maintenance', 'Queue'] as $owner) {
    $class = $prefix . $owner . 'Controller';
    $numericOption = static function (array $options, int $default, string ...$path) use ($owner, $invoke, $class): int {
        // Reflection deliberately crosses the documented string-key boundary for malformed-config controls.
        $value = $owner === 'Auth'
            ? $invoke($class, 'optionInt', $options, $default, ...$path)
            : $invoke(Reader::class, 'optionNonNegativeInteger', $options, $default, array_values($path));
        if (!is_int($value)) {
            throw new UnexpectedValueException('Reflected numeric configuration did not return an integer');
        }
        return $value;
    };
    foreach ($integerValues as $label => [$input, $expected]) {
        $check('numeric-config', "C01b {$owner} explicit {$label} config overrides default", $returns(static fn () => $numericOption(['numeric' => ['value' => $input]], 17, 'numeric', 'value'), $expected));
    }
    foreach ([[], ['numeric' => []]] as $missing) {
        $check('numeric-config', "C01b {$owner} missing config preserves caller default", $returns(static fn () => $numericOption($missing, 17, 'numeric', 'value'), 17));
    }
    if ($owner === 'Auth') {
        $check('numeric-config', 'C01b Auth explicit null numeric config uses default', $returns(static fn () => $numericOption(['numeric' => ['value' => null]], 17, 'numeric', 'value'), 17));
    } else {
        $check('numeric-config', "C01b {$owner} explicit null numeric config rejects", $error(static fn () => $numericOption(['numeric' => ['value' => null]], 17, 'numeric', 'value'), LogicException::class, 'Configuration numeric.value must be a non-negative integer'));
    }
    foreach (array_diff_key($invalidIntegers, ['null' => null]) as $label => $input) {
        $check('numeric-config', "C01b {$owner} rejects {$label} config", $error(static fn () => $numericOption(['numeric' => ['value' => $input]], 17, 'numeric', 'value'), LogicException::class, 'Configuration numeric.value must be a non-negative integer'));
    }
    foreach ([
        [['numeric' => null], 'Configuration numeric must be an array'],
        [['numeric' => 'value'], 'Configuration numeric must be an array'],
        [['numeric' => ['value']], 'Configuration numeric must use string keys'],
        [['value'], 'Configuration root must use string keys'],
    ] as [$options, $message]) {
        $check('numeric-config', "C01b {$owner} config shape error", $error(static fn () => $numericOption($options, 17, 'numeric', 'value'), LogicException::class, $message));
    }
}
foreach ($integerValues as $label => [$input, $expected]) {
    $check('numeric-reader-config', "C01b Reader option accepts {$label} without changing default", $returns(static fn () => \ViMbAdmin\Kernel\Input\Reader::optionNonNegativeInteger(['numeric' => ['value' => $input]], 17, ['numeric', 'value']), $expected));
}
$check('numeric-reader-config', 'C01b Reader missing option preserves caller default', \ViMbAdmin\Kernel\Input\Reader::optionNonNegativeInteger([], 17, ['numeric', 'value']) === 17);
foreach ($invalidIntegers as $label => $input) {
    $check('numeric-reader-config', "C01b Reader numeric option rejects {$label}", $error(static fn () => \ViMbAdmin\Kernel\Input\Reader::optionNonNegativeInteger(['numeric' => ['value' => $input]], 17, ['numeric', 'value']), LogicException::class, 'Configuration numeric.value must be a non-negative integer'));
}

foreach ([[null, 17], ['', 17], [0, 0], ['0', 0], ['7', 7], [PHP_INT_MAX, PHP_INT_MAX]] as [$input, $expected]) {
    $check('numeric-defaults', 'C01b Domain empty count/default policy', $returns(static fn () => $invoke($prefix . 'DomainController', 'nonNegativeIntegerOrDefault', $input, 17, 'Count'), $expected));
}
foreach ([[], '01', '-0', $aboveIntegerMax] as $input) {
    $check('numeric-defaults', 'C01b Domain default wrapper rejects malformed present count', $error(static fn () => $invoke($prefix . 'DomainController', 'nonNegativeIntegerOrDefault', $input, 17, 'Count'), LogicException::class, 'Count must be a non-negative integer'));
}
foreach ([[0, '0'], ['7', '7'], [PHP_INT_MAX, $integerMaxString]] as [$input, $expected]) {
    $check('numeric-defaults', 'C01b Domain integer default renders exact integer string', $returns(static fn () => $invoke($prefix . 'DomainController', 'nonNegativeIntegerDefault', $input, 'Count'), $expected));
}
foreach ([[0, '0'], [1.25, '1.25'], ['.5', '.5'], ['1.', '1.'], ['01.20', '01.20']] as [$input, $expected]) {
    $check('numeric-defaults', 'C01b Domain decimal default preserves accepted representation', $returns(static fn () => $invoke($prefix . 'DomainController', 'nonNegativeNumberDefault', $input, 'Quota default'), $expected));
}
foreach ([null, [], true, -1, '-0', '+1', '1e2', '1MB', INF, -INF, NAN] as $input) {
    $check('numeric-defaults', 'C01b Domain decimal default rejects malformed/nonfinite value', $error(static fn () => $invoke($prefix . 'DomainController', 'nonNegativeNumberDefault', $input, 'Quota default'), LogicException::class, 'Quota default must be a non-negative number'));
}
foreach ([[null, null], [1, 1], ['1', 1], [65535, 65535], ['65535', 65535]] as [$input, $expected]) {
    $check('numeric-port', 'C01b optional server port accepts exact valid boundary', $returns(static fn () => $invoke($mailbox, 'optionalServerPort', $input), $expected));
}
foreach ([0, '0', 65536, '65536', -1, '01', '1e2', '+1', ' 1', '1 ', 1.0, true, [], $aboveIntegerMax, INF, NAN] as $input) {
    $check('numeric-port', 'C01b optional server port rejects malformed/out-of-range input', $error(static fn () => $invoke($mailbox, 'optionalServerPort', $input), LogicException::class, 'Server port must be an integer from 1 through 65535'));
}
foreach ([[null, 1], [0, 0], ['0', 0], ['', 0], [false, 0], [1, 1], ['1', 1], [true, 1]] as [$input, $expected]) {
    $check('numeric-binary', 'C01b Alias binary flag retains default and accepted legacy forms', $returns(static fn () => $invoke($prefix . 'AliasController', 'binaryInteger', $input, 1, 'Flag'), $expected));
}
foreach (['00', '01', '-0', 2, '2', -1, 1.0, [], INF, NAN] as $input) {
    $check('numeric-binary', 'C01b Alias binary flag rejects other numeric shapes', $error(static fn () => $invoke($prefix . 'AliasController', 'binaryInteger', $input, 1, 'Flag'), LogicException::class, 'Flag must be zero or one'));
}

$rateLimit = (new ReflectionClass(ViMbAdmin_Mcp_RateLimit::class))->newInstanceWithoutConstructor();
foreach ([[0, 0], ['-0', 0], [-1, -1], ['-1', -1], [PHP_INT_MIN, PHP_INT_MIN], [(string) PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MAX]] as [$input, $expected]) {
    $check('numeric-mcp', 'C01b MCP rate-limit integer parser retains signed values', $returns(static fn () => $invoke($rateLimit, '_integerOption', ['max' => $input], 'max', 17), $expected));
}
$rateError = null;
try {
    $invoke($rateLimit, '_integerOption', ['max' => null], 'max', 17);
} catch (ViMbAdmin_Mcp_Exception $exception) {
    $rateError = [$exception::class, $exception->getMessage(), $exception->getCode()];
}
$check('numeric-mcp', 'C01b MCP malformed rate-limit config retains service-unavailable code', $rateError === [ViMbAdmin_Mcp_Exception::class, 'destructive rate-limit max must be an integer', 503]);
$check('numeric-mcp', 'C01b MCP absent rate-limit config preserves default', $returns(static fn () => $invoke($rateLimit, '_integerOption', [], 'max', 17), 17));
foreach (['01', '+1', '1e2', [], $aboveIntegerMax, $belowIntegerMin, INF, NAN] as $input) {
    $check('numeric-mcp', 'C01b MCP rate-limit integer parser rejects malformed/overflow values', $error(static fn () => $invoke($rateLimit, '_integerOption', ['max' => $input], 'max', 17), ViMbAdmin_Mcp_Exception::class, 'destructive rate-limit max must be an integer'));
}
foreach ([
    [[], 17], [['value' => 0], 0], [['value' => '7'], 7], [['value' => PHP_INT_MAX], PHP_INT_MAX],
] as [$params, $expected]) {
    $check('numeric-mcp', 'C01b MCP optional integer retains missing/explicit byte-value contract', $returns(static fn () => ViMbAdmin_Mcp_Input::optionalInteger($params, 'value', 17), $expected));
}
foreach ([null, '', '-0', '01', '1e2', $aboveIntegerMax, []] as $input) {
    $check('numeric-mcp', 'C01b MCP optional integer rejects explicit malformed values', $error(static fn () => ViMbAdmin_Mcp_Input::optionalInteger(['value' => $input], 'value', 17), ViMbAdmin_Mcp_Exception::class, 'param "value" must be a non-negative integer'));
}
$check('numeric-mcp', 'C01b MCP absent numeric option retains default', ViMbAdmin_Mcp_Input::optionInteger([], 17, 'numeric', 'value') === 17);
$check('numeric-mcp', 'C01b MCP numeric option retains explicit zero', ViMbAdmin_Mcp_Input::optionInteger(['numeric' => ['value' => '0']], 17, 'numeric', 'value') === 0);
$check('numeric-mcp', 'C01b MCP null numeric option does not become missing', $error(static fn () => ViMbAdmin_Mcp_Input::optionInteger(['numeric' => ['value' => null]], 17, 'numeric', 'value'), ViMbAdmin_Mcp_Exception::class, 'configuration numeric.value must be a non-negative integer'));
$read = new ViMbAdmin_Mcp_ReadOperations();
foreach ([[[], [100, 0]], [['limit' => 1, 'offset' => '7'], [1, 7]], [['limit' => 200, 'offset' => PHP_INT_MAX], [200, PHP_INT_MAX]]] as [$params, $expected]) {
    $check('numeric-mcp', 'C01b MCP list bounds preserve defaults and accepted boundaries', $returns(static fn () => $read->listBounds($params), $expected));
}
foreach ([0, 201] as $input) {
    $check('numeric-mcp', 'C01b MCP list limit keeps its transport-specific range', $error(static fn () => $read->listBounds(['limit' => $input]), ViMbAdmin_Mcp_Exception::class, 'param "limit" must be between 1 and 200'));
}
$check('numeric-mcp', 'C01b MCP list offset rejects overflowing input', $error(static fn () => $read->listBounds(['offset' => $aboveIntegerMax]), ViMbAdmin_Mcp_Exception::class, 'param "offset" must be a non-negative integer'));

$numericEvents = new ArrayObject(['initial']);
$numericHandler = static function (array $params) use ($numericEvents): array {
    $numericEvents[] = 'handler';
    $input = array_key_exists('value', $params) ? ['value' => $params['value']] : [];
    return ['value' => ViMbAdmin_Mcp_Input::optionalInteger($input, 'value', 17)];
};
$numericDispatcher = new ViMbAdmin_Mcp_Dispatcher(
    ['numeric.contract' => ['scope' => 'write', 'destructive' => true, 'handler' => $numericHandler]],
    static function (array $server, string $scope) use ($numericEvents): Entities\McpToken {
        $numericEvents[] = 'auth:' . $scope;
        return new Entities\McpToken();
    },
    static function (Entities\McpToken $token) use ($numericEvents): void {
        $numericEvents[] = 'rate';
    },
);
foreach ([
    [[], ['result' => ['value' => 17]]],
    [['value' => '0'], ['result' => ['value' => 0]]],
    [['value' => null], ['error' => ['code' => -32602, 'message' => 'param "value" must be a non-negative integer']]],
    [['value' => $aboveIntegerMax], ['error' => ['code' => -32602, 'message' => 'param "value" must be a non-negative integer']]],
] as [$params, $expected]) {
    $numericEvents->exchangeArray([]);
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 'numeric-id', 'method' => 'numeric.contract', 'params' => $params], JSON_THROW_ON_ERROR);
    $response = $numericDispatcher->dispatch(true, 'POST', static fn (): string => $body, []);
    $check('numeric-transport', 'C01b MCP numeric transport preserves exact id, default, value and invalid-param error', $response->status === 200 && json_decode($response->body, true, 512, JSON_THROW_ON_ERROR) === ['jsonrpc' => '2.0', 'id' => 'numeric-id'] + $expected);
    $check('numeric-transport', 'C01b MCP numeric handler runs after auth and rate limit', $numericEvents->getArrayCopy() === ['auth:write', 'rate', 'handler']);
}
foreach ([
    [0, 0], ['7', 7], [PHP_INT_MAX, PHP_INT_MAX],
] as [$input, $expected]) {
    $numericSnapshot = array_replace($snapshot, ['quota' => $input]);
    $check('numeric-snapshot', 'C01b HTTP archive snapshot retains accepted quota bytes', $returns(static fn () => $invoke($prefix . 'ArchiveController', 'mailboxSnapshot', $numericSnapshot), array_replace($snapshot, ['quota' => $expected])));
    $check('numeric-snapshot', 'C01b MCP archive snapshot retains accepted quota bytes', $returns(static fn () => ViMbAdmin_Mcp_Input::mailboxSnapshot($numericSnapshot), array_replace($snapshot, ['quota' => $expected])));
}
foreach ([null, '-1', '01', $aboveIntegerMax, []] as $input) {
    $numericSnapshot = array_replace($snapshot, ['quota' => $input]);
    $check('numeric-snapshot', 'C01b HTTP archive snapshot rejects malformed quota bytes', $error(static fn () => $invoke($prefix . 'ArchiveController', 'mailboxSnapshot', $numericSnapshot), LogicException::class, 'Archive mailbox snapshot quota must be a non-negative integer'));
    $check('numeric-snapshot', 'C01b MCP archive snapshot rejects malformed quota bytes', $error(static fn () => ViMbAdmin_Mcp_Input::mailboxSnapshot($numericSnapshot), ViMbAdmin_Mcp_Exception::class, 'archive mailbox snapshot quota must be a non-negative integer'));
}

// Real inherited callers, not a copy of parameter precedence/config traversal.
$numericHarness = new WorkflowHarness(plugins: false);
$savedNumericGlobals = [$_GET, $_POST, $_SERVER];
try {
    foreach (['Admin', 'Alias', 'Domain', 'Mailbox'] as $owner) {
        $class = $prefix . $owner . 'Controller';
        foreach ([
            [['id' => '7'], ['id' => '9'], 7],
            [['id' => null], ['id' => '7'], 7],
            [['id' => '0'], ['id' => '7'], null],
            [[], ['id' => '01'], null],
        ] as [$params, $post, $expected]) {
            $_POST = $post;
            $_GET = [];
            $instance = new $class($numericHarness->container, new RouteMatch('index', 'index', $class, 'indexAction', $params));
            $check('numeric-callers', "C01b {$owner} actual ID parameter wrapper preserves missing/precedence", $returns(static fn () => $invoke($instance, 'positiveIdParam', 'id'), $expected));
        }
    }
    foreach ([
        [[], 3],
        [['defaults' => ['server_side' => ['pagination' => ['min_search_str' => 0]]]], 0],
        [['defaults' => ['server_side' => ['pagination' => ['min_search_str' => 7, 'domain' => ['min_search_str' => null]]]]], 7],
        [['defaults' => ['server_side' => ['pagination' => ['min_search_str' => 7, 'domain' => ['min_search_str' => '2']]]]], 2],
    ] as [$options, $expected]) {
        $numericHarness->options = $options;
        $index = inputContractIndexController($numericHarness->container);
        $check('numeric-callers', 'C01b real search-floor caller preserves shared/list/null defaults', $returns(static fn () => $invoke($index, 'dataTableMinimumSearchLength', 'domain'), $expected));
    }
    $numericHarness->options = ['defaults' => ['server_side' => ['pagination' => ['min_search_str' => '01']]]];
    $check('numeric-callers', 'C01b real search-floor caller retains TypeError', $error(static fn () => $invoke(inputContractIndexController($numericHarness->container), 'dataTableMinimumSearchLength'), TypeError::class, 'min_search_str must be a non-negative integer'));
} finally {
    [$_GET, $_POST, $_SERVER] = $savedNumericGlobals;
}

// Converted quotas are not integer parsers: web units override the default,
// MCP explicit quotas are bytes, and configured MCP defaults use a multiplier.
foreach ([['0', 'KB', 0], ['1.5', 'KB', 1536], ['.5KB', 'MB', 512], ['2B', 'KB', 2], ['', 'KB', 0]] as [$input, $unit, $expected]) {
    $check('numeric-quota', 'C01b Mailbox quota retains units/default/fraction behavior', $returns(static fn () => $invoke($mailbox, 'quotaBytes', $input, $unit), $expected));
    $check('numeric-quota', 'C01b Domain quota retains units/default/fraction behavior', $returns(static fn () => $invoke($prefix . 'DomainController', 'quotaBytes', $input, new OSS_Filter_FileSize($unit), 'Quota'), $expected));
}
if (PHP_INT_SIZE === 8) {
    foreach ([
        ['9007199254740991', 'KB', 9223372036854774784],
        ['9007199254740992', 'B', 9007199254740992],
    ] as [$input, $unit, $expected]) {
        $check('numeric-quota', 'C01b Mailbox quota accepts the exact valid upper-bound neighbor', $returns(static fn () => $invoke($mailbox, 'quotaBytes', $input, $unit), $expected));
        $check('numeric-quota', 'C01b Domain quota accepts the exact valid upper-bound neighbor', $returns(static fn () => $invoke($prefix . 'DomainController', 'quotaBytes', $input, new OSS_Filter_FileSize($unit), 'Quota'), $expected));
    }
    foreach ([['9007199254740992', 'KB'], ['9223372036854775808', 'B']] as [$input, $unit]) {
        $check('numeric-quota', 'C01b Mailbox quota rejects rounded integer overflow', $error(static fn () => $invoke($mailbox, 'quotaBytes', $input, $unit), LogicException::class, 'Mailbox quota produced an invalid byte value'));
        $check('numeric-quota', 'C01b Domain quota rejects rounded integer overflow', $error(static fn () => $invoke($prefix . 'DomainController', 'quotaBytes', $input, new OSS_Filter_FileSize($unit), 'Quota'), LogicException::class, 'Quota produced an invalid byte value'));
    }
}
foreach (['-1', '1e2', '1..2', '1junk'] as $input) {
    $check('numeric-quota', 'C01b Mailbox quota rejects malformed size', $error(static fn () => $invoke($mailbox, 'quotaBytes', $input, 'KB'), LogicException::class, 'Mailbox quota produced an invalid byte value'));
    $check('numeric-quota', 'C01b Domain quota rejects malformed size', $error(static fn () => $invoke($prefix . 'DomainController', 'quotaBytes', $input, new OSS_Filter_FileSize('KB'), 'Quota'), LogicException::class, 'Quota produced an invalid byte value'));
}
if (PHP_INT_SIZE === 8) {
    $setQuotaMultiplier = static function (WorkflowHarness $harness): void {
        $defaults = $harness->options['defaults'];
        if (!is_array($defaults)) {
            throw new LogicException('Workflow fixture defaults missing');
        }
        $defaults['quota'] = ['multiplier' => 'KB'];
        $harness->options['defaults'] = $defaults;
    };
    $quotaPost = [
        'csrf' => 'workflow-csrf', 'description' => '', 'transport' => 'virtual', 'active' => '1',
        'max_aliases' => '0', 'max_mailboxes' => '0', 'quota' => '9007199254740992', 'max_quota' => '0',
    ];
    $domainQuotaHarness = new WorkflowHarness(plugins: false);
    $setQuotaMultiplier($domainQuotaHarness);
    $check('numeric-quota-action', 'C01b Domain actual edit rejects rounded quota overflow with exact error', $error(
        static fn () => $domainQuotaHarness->run('domain', 'edit', ['did' => '1'], $quotaPost),
        LogicException::class,
        'Domain quota produced an invalid byte value',
    ));
    $check('numeric-quota-action', 'C01b Domain rejected quota makes no entity write or flush', $domainQuotaHarness->domain->getQuota() === 0 && $domainQuotaHarness->persistence->flushes === 0 && $domainQuotaHarness->persistence->persisted === []);

    $mailboxQuotaHarness = new WorkflowHarness(plugins: false);
    $setQuotaMultiplier($mailboxQuotaHarness);
    $check('numeric-quota-action', 'C01b Mailbox actual edit rejects rounded quota overflow with exact error', $error(
        static fn () => $mailboxQuotaHarness->run('mailbox', 'edit', ['mid' => '1'], ['csrf' => 'workflow-csrf', 'name' => '', 'quota' => '9007199254740992', 'alt_email' => '']),
        LogicException::class,
        'Mailbox quota produced an invalid byte value',
    ));
    $check('numeric-quota-action', 'C01b Mailbox rejected quota makes no quota write or flush', $mailboxQuotaHarness->mailbox->getQuota() === 0 && $mailboxQuotaHarness->persistence->flushes === 0 && $mailboxQuotaHarness->persistence->persisted === []);
}
foreach ([
    [[], 0],
    [['defaults' => ['domain' => ['quota' => '1.5'], 'quota' => ['multiplier' => 'kb']]], 1536],
    [['defaults' => ['domain' => ['quota' => 2], 'quota' => ['multiplier' => 'MB']]], 2097152],
] as [$options, $expected]) {
    $check('numeric-quota', 'C01b MCP configured quota retains numeric-default unit conversion', $returns(static fn () => $invoke(ViMbAdmin_Mcp_MutationOperations::class, 'domainDefaultQuotaBytes', $options, 'quota'), $expected));
}
foreach (['1MB', '1e2', -1, INF, NAN, []] as $input) {
    $check('numeric-quota', 'C01b MCP configured quota rejects malformed/nonfinite defaults', $error(static fn () => $invoke(ViMbAdmin_Mcp_MutationOperations::class, 'domainDefaultQuotaBytes', ['defaults' => ['domain' => ['quota' => $input]]], 'quota'), ViMbAdmin_Mcp_Exception::class, 'configuration defaults.domain.quota must be a non-negative number'));
}

// C02b retains the original owner-labelled C01b tables above and checks that
// the approved controller consumers now call Reader without private copies.
foreach (['Archive', 'Domain'] as $owner) {
    $class = $prefix . $owner . 'Controller';
    $source = file_get_contents(dirname(__DIR__) . "/src/Kernel/Controller/{$owner}Controller.php");
    $check('numeric-consolidation', "C02b {$owner} strict numeric delegate is removed and consumers use Reader", !method_exists($class, 'nonNegativeInteger')
        && is_string($source) && str_contains($source, '\\ViMbAdmin\\Kernel\\Input\\Reader::nonNegativeInteger('));
}
foreach (['Mailbox', 'Maintenance', 'Queue'] as $owner) {
    $class = $prefix . $owner . 'Controller';
    $source = file_get_contents(dirname(__DIR__) . "/src/Kernel/Controller/{$owner}Controller.php");
    $check('numeric-consolidation', "C02b {$owner} strict numeric config delegate is removed and consumers use Reader", !method_exists($class, 'optionInt')
        && is_string($source) && str_contains($source, '\\ViMbAdmin\\Kernel\\Input\\Reader::optionNonNegativeInteger('));
}
$domain = $prefix . 'DomainController';
$archive = $prefix . 'ArchiveController';
$check('numeric-consolidation', 'C02b Domain replaced validator rejects array count', $error(static fn () => $invoke($domain, 'nonNegativeIntegerOrDefault', [], 17, 'Count'), LogicException::class, 'Count must be a non-negative integer'));
$check('numeric-consolidation', 'C02b Domain integer default rejects overflowing count', $error(static fn () => $invoke($domain, 'nonNegativeIntegerDefault', $aboveIntegerMax, 'Count'), LogicException::class, 'Count must be a non-negative integer'));
$check('numeric-consolidation', 'C02b Archive snapshot rejects invalid array quota', $error(static fn () => $invoke($archive, 'mailboxSnapshot', array_replace($snapshot, ['quota' => []])), LogicException::class, 'Archive mailbox snapshot quota must be a non-negative integer'));
$check('numeric-consolidation', 'C02b Archive snapshot rejects overflowing quota', $error(static fn () => $invoke($archive, 'mailboxSnapshot', array_replace($snapshot, ['quota' => $aboveIntegerMax])), LogicException::class, 'Archive mailbox snapshot quota must be a non-negative integer'));

// Fixed independent execution counts prevent a removed/empty table or owner
// silently narrowing this finite characterization. These are not line coverage.
$expectedCounts = [
    'strict' => 140, 'default' => 60, 'config' => 140, 'consolidation' => 11, 'nullable' => 26,
    'fallback' => 60, 'optional' => 40, 'render' => 20, 'single' => 49,
    'request' => 14, 'maps' => 12, 'csrf' => 9, 'form' => 21, 'mcp' => 62,
    'transport' => 8, 'snapshot' => 42,
];
$stringExpectedCounts = $expectedCounts;
$expectedCounts += [
    'numeric-ids' => 231, 'numeric-strict' => 165, 'numeric-log' => 33,
    'numeric-config' => 156, 'numeric-reader-config' => 34, 'numeric-defaults' => 29, 'numeric-port' => 21,
    'numeric-binary' => 18, 'numeric-mcp' => 37, 'numeric-transport' => 8,
    'numeric-snapshot' => 16, 'numeric-callers' => 21,
    'numeric-quota' => 35, 'numeric-quota-action' => 4,
    'numeric-consolidation' => 9,
];
foreach ($expectedCounts as $group => $expected) {
    if (($counts[$group] ?? 0) !== $expected) {
        $failures++;
        echo '  FAIL C01 execution count ' . $group . ': expected ' . $expected . ', observed ' . ($counts[$group] ?? 0) . "\n";
    }
}
if (array_keys($counts) !== array_keys($expectedCounts)) {
    $failures++;
    echo "  FAIL C01 execution group discovery\n";
}
echo 'C01 retained ' . array_sum(array_intersect_key($counts, $stringExpectedCounts)) . ' assertions across ' . count($stringExpectedCounts) . " string groups.\n";
echo 'C01 executed ' . array_sum($counts) . ' assertions across ' . count($counts) . " groups; adapter coverage only, independent lifecycle suites required.\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
