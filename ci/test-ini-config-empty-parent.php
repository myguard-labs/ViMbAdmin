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

echo EmptyParentTestState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . EmptyParentTestState::$failures . " FAILED\n";
exit(EmptyParentTestState::$failures === 0 ? 0 : 1);
