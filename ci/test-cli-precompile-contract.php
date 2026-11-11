<?php

declare(strict_types=1);

use ViMbAdmin\Kernel\Cli\CliKernel;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;
use ViMbAdmin\Kernel\View\SmartyView;

$root = getenv('VIMBADMIN_TEST_ROOT') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';
$checks = 0;
$failures = 0;
$check = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . "\n";
};
/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $args
 * @return array{?int, string, ?Throwable}
 */
function precompileContractRun(CliKernel $kernel, array $options, array $args): array
{
    $resources = new class ($options) {
        /** @param array<string, mixed> $options */
        public function __construct(private array $options)
        {
        }
        /** @return array<string, mixed> */
        public function getOptions(): array
        {
            return $this->options;
        }
        public function getResource(string $name): never
        {
            throw new LogicException('No resource access allowed: ' . $name);
        }
    };
    $session = new class () implements SessionStorage {
        #[\Override]
        public function has(string $key): bool
        {
            throw new LogicException('No session access allowed');
        }
        #[\Override]
        public function get(string $key): mixed
        {
            throw new LogicException('No session access allowed');
        }
        #[\Override]
        public function set(string $key, mixed $value): void
        {
            throw new LogicException('No session access allowed');
        }
        #[\Override]
        public function remove(string $key): void
        {
            throw new LogicException('No session access allowed');
        }
    };
    $container = new Container($resources, new Auth($session, static function (int $id): never {
        throw new LogicException('No account access allowed');
    }));
    ob_start();
    try {
        $status = $kernel->run('maintenance.cli-precompile-templates', $args, $container);
        return [$status, (string) ob_get_clean(), null];
    } catch (Throwable $error) {
        return [null, (string) ob_get_clean(), $error];
    }
}
$base = rtrim(getenv('PHPTEST_TMPDIR') ?: sys_get_temp_dir(), '/');
$owned = $base . '/vimbadmin-precompile-' . bin2hex(random_bytes(12));
if (!mkdir($owned, 0700)) {
    throw new RuntimeException('Could not create private fixture');
}
$cleanup = static function (string $directory) use ($owned): void {
    if ($directory !== $owned || is_link($directory)) {
        throw new LogicException('Refuse cleanup outside owned fixture');
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if (!$entry instanceof SplFileInfo) {
            throw new LogicException('Fixture iterator must yield file information');
        }
        if ($entry->isLink() || !$entry->isDir()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($directory);
};
try {
    foreach (['templates', 'compiled', 'cache', 'config', 'bystander'] as $directory) {
        mkdir($owned . '/' . $directory, 0700);
    }
    file_put_contents($owned . '/bystander/keep', 'untouched');
    file_put_contents($owned . '/templates/page.phtml', 'Hello {"world"|escape}');
    file_put_contents($owned . '/templates/script.js', 'const value = {"safe"|escape};');
    file_put_contents($owned . '/templates/ignored.txt', '{invalid unclosed');
    $options = ['resources' => ['smarty' => [
        'templates' => $owned . '/templates',
        'compiled' => $owned . '/compiled',
        'cache' => $owned . '/cache',
        'config' => $owned . '/config',
        'plugins' => [],
    ]]];
    $kernel = new CliKernel($root . '/application', 'testing');
    $check('actual kernel registers precompile command', $kernel->canHandle('maintenance.cli-precompile-templates'));
    [$status, $output, $error] = precompileContractRun($kernel, $options, ['verbose' => true]);
    $check('valid page and JS templates return success', $status === 0 && $error === null);
    $check('verbose reports two newly compiled templates', str_contains($output, "Precompiled 2 template(s).\n"));
    $compiled = iterator_to_array(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($owned . '/compiled', FilesystemIterator::SKIP_DOTS)
    ));
    $check(
        'compiled PHP artifacts are created in private compile directory',
        count(array_filter(
            $compiled,
            static fn (mixed $file): bool => $file instanceof SplFileInfo
                && $file->isFile() && $file->getExtension() === 'php'
        )) === 2
    );
    [$status, $output, $error] = precompileContractRun($kernel, $options, []);
    $check(
        'warm repeat succeeds without verbose summary',
        $status === 0
        && $error === null
        && !str_contains($output, 'Precompiled ')
    );
    [$status, $output, $error] = precompileContractRun($kernel, $options, ['v' => true]);
    $check(
        'short verbose option reports zero newly compiled templates',
        $status === 0
        && $error === null
        && str_contains($output, "Precompiled 0 template(s).\n")
    );
    $malformed = $options;
    $malformed['resources']['smarty'] = 'invalid';
    [$status, $output, $error] = precompileContractRun($kernel, $malformed, []);
    $check(
        'malformed Smarty options return failure instead of escaping exception',
        $status === 1
        && $error === null
        && str_starts_with($output, 'ERROR: template precompile failed: ')
    );
    file_put_contents($owned . '/templates/broken.phtml', '{if true}');
    [$status, $output, $error] = precompileContractRun($kernel, $options, ['verbose' => true]);
    $check(
        'invalid template compilation returns failure with stable error prefix',
        $status === 1
        && $error === null
        && str_contains($output, 'ERROR: template precompile failed: ')
    );
    $check(
        'command preserves template sources and bystander file',
        file_get_contents($owned . '/bystander/keep') === 'untouched'
        && is_file($owned . '/templates/page.phtml')
        && is_file($owned . '/templates/broken.phtml')
    );
    // Boundary and resource-identity checks use only this fixture's files.
    unlink($owned . '/templates/broken.phtml');
    mkdir($owned . '/empty', 0700);
    $empty = $options;
    $empty['resources']['smarty']['templates'] = $owned . '/empty';
    [$status, $output, $error] = precompileContractRun($kernel, $empty, ['verbose' => true]);
    $check(
        'empty template directory succeeds with zero newly compiled templates',
        $status === 0 && $error === null && str_contains($output, "Precompiled 0 template(s).\n")
    );
    mkdir($owned . '/templates/nested', 0700);
    file_put_contents($owned . '/templates/nested/page.phtml', 'Nested {"template"|escape}');
    file_put_contents($owned . '/templates/.hidden.phtml', '{if true}');
    file_put_contents($owned . '/templates/ignored.svn.phtml', '{if true}');
    [$status, $output, $error] = precompileContractRun($kernel, $options, ['verbose' => true]);
    $check(
        'nested templates compile while hidden and svn filenames remain excluded',
        $status === 0 && $error === null && str_contains($output, "Precompiled 1 template(s).\n")
    );
    $view = SmartyView::fromOptions($options);
    $check(
        'precompiled resource is warm under the normal relative template name',
        !$view->getEngine()->createTemplate('nested/page.phtml')->mustCompile()
    );
    file_put_contents($owned . '/templates/broken.js', '{if true}');
    [$status, $output, $error] = precompileContractRun($kernel, $options, []);
    $check(
        'invalid JS template compilation returns failure with stable error prefix',
        $status === 1 && $error === null && str_starts_with($output, 'ERROR: template precompile failed: ')
    );
    unlink($owned . '/templates/broken.js');
    $renderCalls = 0;
    $view->getEngine()->registerPlugin('function', 'precompile_probe', static function () use (&$renderCalls): string {
        $renderCalls++;
        return 'probe executed';
    });
    file_put_contents($owned . '/templates/probe.phtml', '{precompile_probe}');
    $count = $view->compileAll();
    $check('precompilation does not execute template functions', $count === 1 && $renderCalls === 0);
    // Public Smarty directory keys must keep shadowed sources distinct.
    foreach (['multi-primary', 'multi-secondary', 'multi-compiled'] as $directory) {
        mkdir($owned . '/' . $directory, 0700);
    }
    file_put_contents($owned . '/multi-primary/shared.phtml', 'Primary template');
    file_put_contents($owned . '/multi-secondary/shared.phtml', '{if true}');
    $multiOptions = $options;
    $multiOptions['resources']['smarty']['templates'] = $owned . '/multi-primary';
    $multiOptions['resources']['smarty']['compiled'] = $owned . '/multi-compiled';
    $multi = SmartyView::fromOptions($multiOptions);
    $multi->getEngine()->setTemplateDir([
        'primary' => $owned . '/multi-primary',
        'secondary' => $owned . '/multi-secondary',
    ]);
    $shadowedError = false;
    try {
        $multi->compileAll();
    } catch (\Smarty\CompilerException) {
        $shadowedError = true;
    }
    $check('malformed shadowed template in a later directory is compiled and rejected', $shadowedError);
    file_put_contents($owned . '/multi-secondary/shared.phtml', 'Secondary template');
    file_put_contents($owned . '/multi-secondary/unique.phtml', 'Unique secondary template');
    $check('later duplicate and unique resources each compile once', $multi->compileAll() === 2);
    $check(
        'normal primary and unique secondary resource names stay warm',
        !$multi->getEngine()->createTemplate('shared.phtml')->mustCompile()
        && !$multi->getEngine()->createTemplate('unique.phtml')->mustCompile()
    );
    $check(
        'shadowed resource stays warm under its configured directory key',
        !$multi->getEngine()->createTemplate('[secondary]shared.phtml')->mustCompile()
    );
    $check('multiple template directories repeat with zero recompilation', $multi->compileAll() === 0);
} finally {
    $cleanup($owned);
}
$check('only owned fixture tree is removed', !file_exists($owned));
echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
