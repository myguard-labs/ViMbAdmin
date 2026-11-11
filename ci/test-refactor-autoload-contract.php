<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use ViMbAdmin\Kernel\Cli\CliKernel;
use ViMbAdmin\Kernel\Config\IniConfig;
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\Http\Kernel;
use ViMbAdmin\Kernel\Input\Reader;
use ViMbAdmin\Kernel\Plugin\PluginHost;
use ViMbAdmin\Kernel\Router;
use ViMbAdmin\Kernel\View\SmartyView;

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';
if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Composer autoload did not return its class loader.');
}

final class AutoloadContractState
{
    public static int $checks = 0;
}

function autoloadContractCheck(string $label, bool $passed): void
{
    ++AutoloadContractState::$checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo '  ok   ' . $label . "\n";
}

function autoloadContractError(callable $operation, string $class, string $message): bool
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception::class === $class && $exception->getMessage() === $message;
    }
    return false;
}

function autoloadContractAnyError(callable $operation): bool
{
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }
    return false;
}

function autoloadContractInvoke(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(MailboxController::class, $method))->invoke(null, ...$arguments);
}

function autoloadContractRemoveTree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected recursive directory entry type.');
        }
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

$scratch = sys_get_temp_dir() . '/vimbadmin-autoload-contract-' . bin2hex(random_bytes(8));
try {
    foreach (['config', 'templates', 'compiled', 'cache', 'smarty-config', 'smarty-plugins'] as $directory) {
        if (!mkdir($scratch . '/' . $directory, 0770, true) && !is_dir($scratch . '/' . $directory)) {
            throw new RuntimeException('Unable to create autoload-contract fixture directory.');
        }
    }

    echo "== VC-S01/VC-S01b autoload and discovery contract ==\n";

    $mailboxFile = $loader->findFile(MailboxController::class);
    $readerFile = $loader->findFile(Reader::class);
    $mailboxReflection = new ReflectionClass(MailboxController::class);
    autoloadContractCheck(
        'autoload: Composer resolves the exact production Mailbox controller',
        is_string($mailboxFile)
        && realpath($mailboxFile) === realpath($root . '/src/Kernel/Controller/MailboxController.php')
        && $mailboxReflection->getFileName() === realpath($mailboxFile)
    );
    autoloadContractCheck(
        'autoload: Composer resolves the shared Reader owner',
        is_string($readerFile) && realpath($readerFile) === realpath($root . '/src/Kernel/Input/Reader.php')
    );
    $optionReflection = $mailboxReflection->hasMethod('option')
        ? $mailboxReflection->getMethod('option')
        : null;
    autoloadContractCheck(
        'reflection: the final controller retains its private static option adapter',
        $mailboxReflection->isFinal()
        && $optionReflection instanceof ReflectionMethod
        && $optionReflection->isPrivate()
        && $optionReflection->isStatic()
    );

    $mailboxSource = file_get_contents((string) $mailboxFile);
    autoloadContractCheck(
        'source row: the live adapter delegates once and retains three static consumers',
        is_string($mailboxSource)
        && substr_count($mailboxSource, 'Reader::option($options, ...$path)') === 1
        && substr_count($mailboxSource, 'self::option($options, ...$path)') === 3
        && substr_count($mailboxSource, 'private static function option(') === 1
    );

    autoloadContractCheck(
        'web bootstrap: the native router discovers MailboxController',
        ($mailboxRoute = (new Router(Kernel::nativeControllers()))->match('/mailbox/list')) !== null
        && $mailboxRoute->controllerClass === 'MailboxController'
        && $mailboxRoute->actionMethod === 'listAction'
        && (new Kernel(new Router(Kernel::nativeControllers())))->canHandle('/mailbox/list')
    );

    $cli = new CliKernel($root . '/application', 'development');
    autoloadContractCheck(
        'CLI bootstrap: command discovery loads the native command registry',
        $cli->canHandle('mcp.cli-token-list')
        && $cli->canHandle('maintenance.cli-precompile-templates')
        && !$cli->canHandle('mailbox.option')
    );
    autoloadContractCheck(
        'MCP bootstrap: Composer classmap and native route discover McpController',
        class_exists('McpController')
        && ($mcpRoute = (new Router(Kernel::nativeControllers()))->match('/mcp/index')) !== null
        && $mcpRoute->controllerClass === 'McpController'
        && (new Kernel(new Router(Kernel::nativeControllers())))->canHandle('/mcp/index')
    );

    autoloadContractCheck(
        'adapter positive: a present value returns the Reader found/value tuple',
        autoloadContractInvoke('option', ['outer' => ['leaf' => " value\0"]], 'outer', 'leaf') === [true, " value\0"]
    );
    autoloadContractCheck(
        'adapter boundary: a missing value returns the Reader missing tuple',
        autoloadContractInvoke('option', [], 'outer', 'leaf') === [false, null]
    );
    autoloadContractCheck(
        'adapter malformed: an invalid intermediate shape retains the Reader error',
        autoloadContractError(
            static fn (): mixed => autoloadContractInvoke('option', ['outer' => 'bad'], 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer must be an array',
        )
    );

    autoloadContractCheck(
        'boundary: missing nullable option retains null',
        autoloadContractInvoke('optionNullableString', [], 'outer', 'leaf') === null
    );
    autoloadContractCheck(
        'positive: present nullable option preserves exact bytes',
        autoloadContractInvoke('optionNullableString', ['outer' => ['leaf' => " value\0"]], 'outer', 'leaf') === " value\0"
    );
    autoloadContractCheck(
        'boundary: boolean option retains false default',
        autoloadContractInvoke('optionBool', [], false, 'outer', 'leaf') === false
    );
    autoloadContractCheck(
        'positive: boolean option accepts the exact enabled scalar',
        autoloadContractInvoke('optionBool', ['outer' => ['leaf' => '1']], false, 'outer', 'leaf') === true
    );
    autoloadContractCheck(
        'positive: array option retains string-keyed values',
        autoloadContractInvoke('optionArray', ['outer' => ['leaf' => ['name' => 'value']]], [], 'outer', 'leaf') === ['name' => 'value']
    );
    autoloadContractCheck(
        'boundary: missing array option retains the caller default',
        autoloadContractInvoke('optionArray', [], ['default' => 1], 'outer', 'leaf') === ['default' => 1]
    );
    autoloadContractCheck(
        'malformed: an invalid intermediate config shape keeps the Reader error',
        autoloadContractError(
            static fn (): mixed => autoloadContractInvoke('optionNullableString', ['outer' => 'bad'], 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer must be an array',
        )
    );
    autoloadContractCheck(
        'error: a malformed boolean leaf keeps the transport-specific error',
        autoloadContractError(
            static fn (): mixed => autoloadContractInvoke('optionBool', ['outer' => ['leaf' => null]], false, 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer.leaf must be boolean',
        )
    );
    autoloadContractCheck(
        'error: a list-valued array option keeps the string-key guard',
        autoloadContractError(
            static fn (): mixed => autoloadContractInvoke('optionArray', ['outer' => ['leaf' => ['value']]], [], 'outer', 'leaf'),
            LogicException::class,
            'Configuration outer.leaf must use string keys',
        )
    );

    $configFile = $scratch . '/config/application.ini';
    $config = <<<'INI'
[production]
vimbadmin_plugins.WorkflowProbe.enabled = 0
resources.smarty.skin = ""

[development : production]
vimbadmin_plugins.WorkflowProbe.enabled = 1
INI;
    if (file_put_contents($configFile, $config) === false) {
        throw new RuntimeException('Unable to write the INI discovery fixture.');
    }
    $options = IniConfig::load($configFile, 'development');
    $pluginOptions = $options['vimbadmin_plugins'] ?? null;
    $workflowProbeOptions = is_array($pluginOptions) ? ($pluginOptions['WorkflowProbe'] ?? null) : null;
    $resourceOptions = $options['resources'] ?? null;
    $smartyOptions = is_array($resourceOptions) ? ($resourceOptions['smarty'] ?? null) : null;
    autoloadContractCheck(
        'config discovery: INI inheritance supplies the enabled plugin entry',
        is_array($workflowProbeOptions)
        && ($workflowProbeOptions['enabled'] ?? null) === '1'
        && is_array($smartyOptions)
        && ($smartyOptions['skin'] ?? null) === ''
    );
    autoloadContractCheck(
        'config missing: an absent file fails with its exact path',
        autoloadContractError(
            static fn (): array => IniConfig::load($scratch . '/config/missing.ini', 'development'),
            RuntimeException::class,
            'Cannot read config file: ' . $scratch . '/config/missing.ini',
        )
    );
    $iniWarning = null;
    set_error_handler(static function (int $_severity, string $message) use (&$iniWarning): bool {
        $iniWarning = $message;
        return true;
    });
    try {
        $invalidIniRejected = autoloadContractError(
            static fn (): array => IniConfig::parse("[broken\nvalue = 1", 'broken'),
            RuntimeException::class,
            'Failed to parse INI contents',
        );
    } finally {
        restore_error_handler();
    }
    autoloadContractCheck(
        'config malformed: an invalid INI document is rejected by the native parser',
        $invalidIniRejected
        && is_string($iniWarning)
        && str_contains($iniWarning, "expecting ']'")
    );

    $pluginDirectory = $root . '/tests/support/native-workflow-app/plugins';
    $disabledContext = new class () {
        /** @return array<string,mixed> */
        public function getOptions(): array
        {
            return ['vimbadmin_plugins' => ['WorkflowProbe' => ['enabled' => false]]];
        }
    };
    autoloadContractCheck(
        'plugin disabled guard: a discovered but disabled plugin is not instantiated',
        (new PluginHost($disabledContext, $pluginDirectory))->observerCount() === 0
    );
    $enabledContext = new class ($options) {
        /** @param array<string,mixed> $options */
        public function __construct(private readonly array $options)
        {
        }

        /** @return array<string,mixed> */
        public function getOptions(): array
        {
            return $this->options;
        }
    };
    $pluginHost = new PluginHost($enabledContext, $pluginDirectory);
    $pluginClass = 'ViMbAdminPlugin_WorkflowProbe';
    $pluginFile = realpath($pluginDirectory . '/WorkflowProbe.php');
    $pluginReflection = class_exists($pluginClass, false) ? new ReflectionClass($pluginClass) : null;
    autoloadContractCheck(
        'plugin discovery: the enabled filename/class convention instantiates WorkflowProbe',
        $pluginHost->observerCount() === 1
        && $pluginReflection !== null
        && is_string($pluginFile)
        && $pluginReflection->getFileName() === $pluginFile
    );
    autoloadContractCheck(
        'plugin discovery: the dynamically loaded observer receives its hook',
        $pluginHost->notify('mailbox', 'edit', 'post', $enabledContext)
        && $pluginReflection !== null
        && $pluginReflection->getStaticPropertyValue('events') === ['mailbox_edit_post']
    );
    $malformedPluginContext = new class () {
        public function getOptions(): string
        {
            return 'invalid';
        }
    };
    autoloadContractCheck(
        'plugin error: malformed dynamic context options fail closed',
        autoloadContractError(
            static fn (): PluginHost => new PluginHost($malformedPluginContext, $pluginDirectory),
            TypeError::class,
            'Plugin context options must be an array.',
        )
    );

    $template = $scratch . '/templates/probe.phtml';
    $modifier = $scratch . '/smarty-plugins/modifier.autoloadprobe.php';
    if (file_put_contents($template, '{$value|autoloadprobe}') === false
        || file_put_contents($modifier, "<?php\nfunction smarty_modifier_autoloadprobe(string \$value): string { return 'discovered:' . \$value; }\n") === false) {
        throw new RuntimeException('Unable to write the Smarty discovery fixtures.');
    }
    $missingSmarty = new SmartyView([
        'templates' => $scratch . '/templates',
        'compiled' => $scratch . '/compiled/missing',
        'cache' => $scratch . '/cache/missing',
        'config' => $scratch . '/smarty-config',
    ]);
    $missingSmarty->assign('value', 'mailbox');
    autoloadContractCheck(
        'Smarty missing: an undiscoverable modifier fails compilation',
        autoloadContractAnyError(static fn (): string => $missingSmarty->render('probe.phtml'))
    );
    $smarty = new SmartyView([
        'templates' => $scratch . '/templates',
        'compiled' => $scratch . '/compiled/enabled',
        'cache' => $scratch . '/cache/enabled',
        'config' => $scratch . '/smarty-config',
        'plugins' => [$scratch . '/smarty-plugins'],
    ]);
    $smarty->assign('value', 'mailbox');
    autoloadContractCheck(
        'Smarty discovery: the configured plugin directory resolves a modifier by filename',
        $smarty->render('probe.phtml') === 'discovered:mailbox'
    );
    // Exercise the real pinned Smarty extension with handlers registered for
    // different masks; no temporary wrapper may steal the caller's handler.
    foreach ([E_USER_WARNING, E_NOTICE | E_USER_NOTICE | E_USER_DEPRECATED] as $mask) {
        $suffix = $mask === E_USER_WARNING ? 'warnmask' : 'noticemask';
        $dir = $scratch . '/diagnostic-' . $suffix;
        mkdir($dir);
        file_put_contents(
            $dir . '/modifier.' . $suffix . '.php',
            "<?php\ntrigger_error('plugin-warning', E_USER_WARNING);\n"
            . "trigger_error('plugin-notice', E_USER_NOTICE);\n"
            . "end(explode(',', 'a,b'));\n"
            . "trigger_error('plugin-deprecation', E_USER_DEPRECATED);\n"
            . 'function smarty_modifier_' . $suffix . '(string $value): string { return $value; }' . "\n"
        );
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            if (error_reporting() & $severity) {
                $seen[] = [$severity, $severity === E_NOTICE ? 'native-notice' : $message];
            }
            return true;
        }, $mask);
        $previousLevel = error_reporting($mask);
        try {
            $diagnosticView = new SmartyView(['plugins' => $dir]);
            trigger_error('after-registration', $mask === E_USER_WARNING ? E_USER_WARNING : E_USER_NOTICE);
        } finally {
            error_reporting($previousLevel);
            restore_error_handler();
        }
        $messages = array_column($seen, 1);
        autoloadContractCheck(
            'Smarty diagnostics: caller mask and handler preserved ' . $suffix,
            in_array('after-registration', $messages, true)
            && ($mask === E_USER_WARNING
                ? $messages === ['plugin-warning', 'after-registration']
                : $messages === ['plugin-notice', 'native-notice', 'plugin-deprecation', 'after-registration'])
        );
        autoloadContractCheck(
            'Smarty diagnostics: legacy API deprecation is absent ' . $suffix,
            !array_filter($messages, static fn (string $message): bool => str_contains($message, 'addPluginsDir'))
        );
    }
    $nativeWarningDir = $scratch . '/diagnostic-native-warning';
    mkdir($nativeWarningDir);
    file_put_contents(
        $nativeWarningDir . '/modifier.nativewarning.php',
        "<?php file_get_contents(__DIR__ . '/absent');\nfunction smarty_modifier_nativewarning(string \$value): string { return \$value; }\n"
    );
    $nativeWarnings = 0;
    set_error_handler(static function (int $severity) use (&$nativeWarnings): bool {
        if ((error_reporting() & $severity) !== 0) {
            ++$nativeWarnings;
        }
        return true;
    }, E_WARNING);
    $previousLevel = error_reporting(E_WARNING);
    try {
        new SmartyView(['plugins' => $nativeWarningDir]);
        file_get_contents($nativeWarningDir . '/absent-after');
    } finally {
        error_reporting($previousLevel);
        restore_error_handler();
    }
    autoloadContractCheck('Smarty diagnostics: native warning handler mask preserved', $nativeWarnings === 2);

    $throwDir = $scratch . '/diagnostic-throw';
    mkdir($throwDir);
    file_put_contents($throwDir . '/modifier.throwprobe.php', "<?php throw new RuntimeException('plugin-exception');\n");
    $afterException = false;
    set_error_handler(static function () use (&$afterException): bool {
        $afterException = true;
        return true;
    }, E_USER_WARNING);
    try {
        autoloadContractCheck(
            'Smarty diagnostics: plugin exception propagates',
            autoloadContractError(
                static fn (): SmartyView => new SmartyView(['plugins' => $throwDir]),
                RuntimeException::class,
                'plugin-exception'
            )
        );
        trigger_error('after-exception', E_USER_WARNING);
    } finally {
        restore_error_handler();
    }
    autoloadContractCheck('Smarty diagnostics: caller handler survives exception', $afterException);
    autoloadContractCheck(
        'Smarty diagnostics: absent optional directory remains accepted',
        !autoloadContractAnyError(static fn (): SmartyView => new SmartyView(['plugins' => $scratch . '/no-such-plugins']))
    );

    autoloadContractCheck(
        'Smarty boundary: traversal is rejected before template discovery',
        autoloadContractError(
            static fn (): string => $smarty->render('../probe.phtml'),
            InvalidArgumentException::class,
            'Smarty template name must be a safe relative path',
        )
    );
    autoloadContractCheck(
        'Smarty malformed: plugin directory entries must be strings',
        autoloadContractError(
            static fn (): SmartyView => new SmartyView([
                'templates' => $scratch . '/templates',
                'plugins' => [null],
            ]),
            InvalidArgumentException::class,
            'Smarty plugins must contain non-empty strings',
        )
    );
} finally {
    autoloadContractRemoveTree($scratch);
}

(static function (): void {
    require __DIR__ . '/test-entity-autoloader-registration.php';
})();

echo 'ALL PASSED (' . AutoloadContractState::$checks . " checks)\n";
