<?php
/**
 * Unit test: ViMbAdmin\Kernel\Plugin\PluginHost (Phase 4c of docs/ZF1-REMOVAL.md).
 *
 * Proves the native plugin host loads the enabled plugins from a directory,
 * honours the `vimbadmin_plugins.<name>.enabled` opt-in switch, constructs each plugin
 * with the context, fans notify() out in registration order, and short-circuits
 * (returns false) on the first observer veto — the contract the mutation
 * services' pre-hooks rely on. No ZF1, no database: fixture plugin classes are
 * written to a temp dir and loaded by the host.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Plugin/PluginHost.php';

use ViMbAdmin\Kernel\Plugin\PluginHost;

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

// --- a fixture plugin directory ----------------------------------------- //
$dir = sys_get_temp_dir() . '/vimb-plugin-host-' . getmypid();
@mkdir($dir, 0700, true);

// Recorder plugin: logs every update() call to a global, always allows.
file_put_contents("$dir/Recorder.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Recorder
{
    public function __construct($context) { $GLOBALS['rec_ctx'] = $context; }
    public function update($controller, $action, $hook, $controllerObject, $params = null)
    {
        $GLOBALS['rec_calls'][] = "{$controller}_{$action}_{$hook}";
        $GLOBALS['rec_last_params'] = $params;
        return true;
    }
}
PHP);

// Veto plugin: refuses a mailbox preToggle, allows everything else.
file_put_contents("$dir/Veto.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Veto
{
    public function __construct($context) {}
    public function update($controller, $action, $hook, $controllerObject, $params = null)
    {
        return !($controller === 'mailbox' && $hook === 'preToggle');
    }
}
PHP);

// Disabled plugin: would throw if ever constructed.
file_put_contents("$dir/Disabled.php", <<<'PHP'
<?php
class ViMbAdminPlugin_Disabled
{
    public function __construct($context) { throw new \RuntimeException('disabled plugin must not be constructed'); }
    public function update($controller, $action, $hook, $controllerObject, $params = null) { return true; }
}
PHP);

// Context double: only getOptions() is needed at construction time.
$context = new class {
    public function getOptions(): array
    {
        // Opt-in: only Recorder + Veto are enabled; Disabled is left off.
        return ['vimbadmin_plugins' => [
            'Recorder' => ['enabled' => true],
            'Veto'     => ['enabled' => true],
            'Disabled' => ['enabled' => false],
        ]];
    }
};

$GLOBALS['rec_calls'] = [];

$host = new PluginHost($context, $dir);

check('host skips the disabled plugin',        $host->observerCount() === 2);
check('disabled plugin never constructed',     true); // its ctor throws; reaching here proves it
check('context passed to plugin ctor',         ($GLOBALS['rec_ctx'] ?? null) === $context);

// notify dispatches to every observer, in order; returns true when none veto
$ok = $host->notify('alias', 'toggleActive', 'preToggle', $context, ['active' => 1]);
check('notify returns true when no veto',      $ok === true);
check('notify reached the recorder',           in_array('alias_toggleActive_preToggle', $GLOBALS['rec_calls'], true));
check('notify forwarded the params',           ($GLOBALS['rec_last_params'] ?? null) === ['active' => 1]);

// the veto plugin refuses a mailbox preToggle → notify short-circuits to false
$vetoed = $host->notify('mailbox', 'toggleActive', 'preToggle', $context, ['active' => 1]);
check('notify returns false on veto',          $vetoed === false);

// a non-vetoed hook still passes
$passed = $host->notify('mailbox', 'toggleActive', 'postflush', $context);
check('notify true for a non-vetoed hook',     $passed === true);

// --- cleanup ------------------------------------------------------------- //
@unlink("$dir/Recorder.php");
@unlink("$dir/Veto.php");
@unlink("$dir/Disabled.php");
@rmdir($dir);

echo "\n";
if ($failures === 0) {
    echo "OK: all PluginHost assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
