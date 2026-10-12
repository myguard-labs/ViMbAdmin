<?php

/**
 * Regression test: empty INI sections remain sections during inheritance.
 *
 * Pure logic, no framework or database. Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Config/IniConfig.php';

use ViMbAdmin\Kernel\Config\IniConfig;

final class EmptyParentTestState
{
    public static int $failures = 0;
}

function emptyParentCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        EmptyParentTestState::$failures++;
    }
}

/** @return array<string, list<string>> */
function automaticAliasesExample(string $source): array
{
    if (preg_match(
        '/Add the following lines to configs\/application\.ini:\n(?<example>.*?)\n \*\n \* Automatic aliases/s',
        $source,
        $match
    ) !== 1) {
        throw new RuntimeException('MailboxAutomaticAliases configuration example is missing or malformed');
    }

    preg_match_all(
        '/^ \*   vimbadmin_plugins\.MailboxAutomaticAliases\.(?<key>[A-Za-z]+)(?:\[\])?\s*=\s*(?<value>.+)$/m',
        $match['example'],
        $assignments,
        PREG_SET_ORDER
    );
    if ($assignments === []) {
        throw new RuntimeException('MailboxAutomaticAliases configuration example has no assignments');
    }

    $settings = [];
    foreach ($assignments as $assignment) {
        $settings[$assignment['key']][] = trim($assignment['value']);
    }

    return $settings;
}

function pluginHostEnablementKey(string $source): string
{
    if (preg_match('/return in_array\(\$entry\[\'(?<key>[A-Za-z]+)\'\] \?\? false, /', $source, $match) !== 1) {
        throw new RuntimeException('Plugin host enablement predicate is missing or malformed');
    }

    return $match['key'];
}

echo "== empty INI parent inheritance ==\n";

$child = IniConfig::parse(
    "global = base\n[parent]\n[child : parent]\nchild = yes\n",
    'child'
);
emptyParentCheck(
    'an empty parent contributes no synthetic key or value',
    $child === ['global' => 'base', 'child' => '1']
);

$parent = IniConfig::parse("global = base\n[parent]\n", 'parent');
emptyParentCheck('an empty section can be selected directly', $parent === ['global' => 'base']);

$emptyChild = IniConfig::parse("global = base\n[parent]\n[child : parent]\n", 'child');
emptyParentCheck('an empty child can extend an empty parent', $emptyChild === ['global' => 'base']);

$transitive = IniConfig::parse(
    "[root]\nroot = kept\n[parent : root]\n[child : parent]\nchild = kept\n",
    'child'
);
emptyParentCheck(
    'an empty middle section preserves transitive inheritance',
    $transitive === ['root' => 'kept', 'child' => 'kept']
);

$unknownMessage = '';
try {
    IniConfig::parse("[child : missing]\nchild = yes\n", 'child');
} catch (RuntimeException $e) {
    $unknownMessage = $e->getMessage();
}
emptyParentCheck(
    'a genuinely unknown parent still fails closed',
    $unknownMessage === "Section 'missing' extends unknown section"
);

$nonEmptyParent = IniConfig::parse(
    "[parent]\nparent = kept\n[child : parent]\nchild = kept\n",
    'child'
);
emptyParentCheck(
    'non-empty parent inheritance is unchanged',
    $nonEmptyParent === ['parent' => 'kept', 'child' => 'kept']
);

$globals = IniConfig::parse("items[] = first\nitems[] = second\n", 'production');
emptyParentCheck(
    'section-less list values remain global configuration',
    $globals === ['items' => ['first', 'second']]
);

echo "\n== MailboxAutomaticAliases configuration contract ==\n";

$root = dirname(__DIR__);
$pluginSource = file_get_contents($root . '/application/plugins/MailboxAutomaticAliases.php');
$pluginHostSource = file_get_contents($root . '/src/Kernel/Plugin/PluginHost.php');
$formPluginHostSource = file_get_contents($root . '/src/Kernel/Plugin/FormPluginHost.php');
$distributionConfig = file_get_contents($root . '/application/configs/application.ini.dist');
emptyParentCheck(
    'error: plugin example, both hosts, and distribution configuration are readable',
    is_string($pluginSource)
        && is_string($pluginHostSource)
        && is_string($formPluginHostSource)
        && is_string($distributionConfig)
);

$example = [];
$hostKey = null;
$formHostKey = null;
try {
    $example = is_string($pluginSource) ? automaticAliasesExample($pluginSource) : [];
    $hostKey = is_string($pluginHostSource) ? pluginHostEnablementKey($pluginHostSource) : null;
    $formHostKey = is_string($formPluginHostSource) ? pluginHostEnablementKey($formPluginHostSource) : null;
} catch (RuntimeException $e) {
    echo '  INFO contract extraction error: ' . $e->getMessage() . "\n";
}

emptyParentCheck(
    'positive: the plugin example opts in with enabled=true',
    ($example['enabled'] ?? null) === ['true']
);
emptyParentCheck(
    'boundary: the example key matches both plugin hosts and the shipped opt-out default',
    $hostKey === 'enabled'
        && $formHostKey === 'enabled'
        && is_string($distributionConfig)
        && preg_match(
            '/^vimbadmin_plugins\.MailboxAutomaticAliases\.enabled\s*=\s*false\s*$/m',
            $distributionConfig
        ) === 1
);

$malformedRejected = false;
try {
    automaticAliasesExample('/** no configuration example */');
} catch (RuntimeException $e) {
    $malformedRejected = $e->getMessage()
        === 'MailboxAutomaticAliases configuration example is missing or malformed';
}
emptyParentCheck('error/malformed: a missing setup example is rejected', $malformedRejected);
emptyParentCheck(
    'negative: the retired disabled=false enablement key is absent from the plugin example',
    !array_key_exists('disabled', $example)
);

echo EmptyParentTestState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . EmptyParentTestState::$failures . " FAILED\n";
exit(EmptyParentTestState::$failures === 0 ? 0 : 1);
