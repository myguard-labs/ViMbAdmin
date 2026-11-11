<?php

declare(strict_types=1);

use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__) . '/src/Kernel/Doctrine/EntityManagerFactory.php';

$original = spl_autoload_functions();
$directory = sys_get_temp_dir() . '/vimbadmin-autoload-registration-' . bin2hex(random_bytes(8));
$prefix = 'Registration' . bin2hex(random_bytes(8));
$files = [];
$directories = [];
$checks = 0;
$check = static function (bool $passed, string $label) use (&$checks): void {
    ++$checks;
    if (!$passed) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'ok: ' . $label . "\n";
};
$mkdir = static function (string $path) use (&$directories, &$mkdir): void {
    if (is_dir($path)) {
        return;
    }
    $parent = dirname($path);
    if (!is_dir($parent)) {
        $mkdir($parent);
    }
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('could not create owned autoloader fixture directory');
    }
    $directories[] = $path;
};
$write = static function (string $base, string $class, string $marker) use ($mkdir, &$files): string {
    $path = $base . '/' . str_replace('\\', '/', $class) . '.php';
    $mkdir(dirname($path));
    $separator = strrpos($class, '\\');
    if ($separator === false) {
        throw new LogicException('fixture class must have a namespace');
    }
    $source = '<?php namespace ' . substr($class, 0, $separator) . '; final class '
        . substr($class, $separator + 1) . ' { public const SOURCE = ' . var_export($marker, true) . '; }';
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException('could not write owned autoloader fixture class');
    }
    $files[] = $path;
    return $path;
};
$options = static fn (array $configuration): array => ['resources' => ['doctrine2' => $configuration]];
/** @param array<string,mixed> $configuration */
function registrationFixtureLoader(array $configuration): Closure
{
    $before = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders($configuration);
    $after = spl_autoload_functions();
    if (count($after) !== count($before) + 1 || !$after[array_key_last($after)] instanceof Closure) {
        throw new RuntimeException('FAIL: distinct map appends exactly one closure');
    }
    return $after[array_key_last($after)];
}

try {
    $mkdir($directory);
    $first = $directory . '/first';
    $second = $directory . '/second';
    $configuration = [
        'models_namespace' => $prefix . '\\Models',
        'repositories_namespace' => $prefix . '\\Repositories',
        'models_path' => $first,
        'repositories_path' => $first,
    ];
    // An unrelated callable must neither be removed nor mistaken for ours.
    $external = new class () {
        public function load(string $class): void
        {
        }
    };
    spl_autoload_register([$external, 'load']);
    $foreignMap = [$prefix . '\\Models' => $first, $prefix . '\\Repositories' => $first];
    $foreignCalls = [];
    $foreign = static function (string $class) use ($foreignMap, &$foreignCalls): void {
        $foreignCalls[] = $foreignMap;
    };
    spl_autoload_register($foreign);
    $firstLoader = registrationFixtureLoader($options($configuration));
    $baseline = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders($options($configuration));
    $check(spl_autoload_functions() === $baseline, 'identical ordered map registration does not grow the SPL stack');
    EntityManagerFactory::registerEntityAutoloaders($options($configuration + ['autogen_proxies' => 1]));
    $check(spl_autoload_functions() === $baseline, 'irrelevant valid options do not change the effective map');

    $secondLoader = registrationFixtureLoader($options(array_replace($configuration, ['models_path' => $second])));
    $ordered = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders($options($configuration));
    $check(spl_autoload_functions() === $ordered, 'duplicate registration preserves distinct map and external callback order');
    $priority = $prefix . '\\Models\\Priority';
    $fallback = $prefix . '\\Models\\SecondOnly';
    $repository = $prefix . '\\Repositories\\Stored';
    $write($first, $priority, 'first');
    $write($second, $priority, 'second');
    $write($second, $fallback, 'fallback');
    $write($first, $repository, 'repository');
    $check(class_exists($priority) && constant($priority . '::SOURCE') === 'first', 'earlier custom map keeps class precedence');
    $check(class_exists($fallback) && constant($fallback . '::SOURCE') === 'fallback', 'later distinct map can load a missing class');
    $check(class_exists($repository) && constant($repository . '::SOURCE') === 'repository', 'repository namespace loads from its configured path');
    $check($foreignCalls !== [] && $foreignCalls[0] === $foreignMap, 'external callback remains active with its own captured map');
    $boundary = $prefix . '\\ModelsExtra\\Excluded';
    $write($first, $boundary, 'must-not-load');
    $check(!class_exists($boundary), 'namespace matching requires the separator boundary');
    $escaped = $prefix . '\\Models\\Escaped';
    $outside = $write($directory . '/outside', $escaped, 'must-not-load');
    $link = $first . '/' . str_replace('\\', '/', $escaped) . '.php';
    $mkdir(dirname($link));
    if (!symlink($outside, $link)) {
        throw new RuntimeException('could not create owned autoloader symlink fixture');
    }
    $files[] = $link;
    $check(!class_exists($escaped), 'real path containment rejects a class symlink outside its configured base');

    $check(spl_autoload_unregister($firstLoader), 'the original loader can be explicitly unregistered');
    $reregistered = registrationFixtureLoader($options($configuration));
    $check($reregistered !== $firstLoader, 'an unregistered retained closure does not suppress re-registration');
    $reordered = spl_autoload_functions();
    $check(array_search($secondLoader, $reordered, true) < array_search($reregistered, $reordered, true), 're-registration appends after the remaining distinct map');
    $check(spl_autoload_unregister($reregistered) && spl_autoload_register($firstLoader), 'an external caller may restore its retained original closure');
    $restored = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders($options($configuration));
    $check(spl_autoload_functions() === $restored, 'an externally restored identical loader is recognized');

    $shared = $prefix . '\\Shared';
    $collision = array_replace($configuration, [
        'models_namespace' => $shared,
        'repositories_namespace' => $shared,
        'models_path' => $first,
        'repositories_path' => $second,
    ]);
    registrationFixtureLoader($options($collision));
    $collapsed = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders($options(array_replace($collision, ['models_path' => $directory . '/unused'])));
    $check(spl_autoload_functions() === $collapsed, 'duplicate namespace keys retain the original effective-map collapse');
    $collisionClass = $shared . '\\Owner';
    $write($first, $collisionClass, 'overwritten');
    $write($second, $collisionClass, 'repository-wins');
    $check(class_exists($collisionClass) && constant($collisionClass . '::SOURCE') === 'repository-wins', 'a repeated namespace retains the original last-path precedence');

    $overlap = $prefix . '\\Overlap';
    $specificFirst = array_replace($configuration, [
        'models_namespace' => $overlap . '\\Nested',
        'repositories_namespace' => $overlap,
        'models_path' => $first,
        'repositories_path' => $second,
    ]);
    $specificLoader = registrationFixtureLoader($options($specificFirst));
    registrationFixtureLoader($options(array_replace($specificFirst, [
        'models_namespace' => $overlap,
        'repositories_namespace' => $overlap . '\\Nested',
        'models_path' => $second,
        'repositories_path' => $first,
    ])));
    $orderedClass = $overlap . '\\Nested\\SpecificFirst';
    $write($first, $orderedClass, 'specific-first');
    $write($second, $orderedClass, 'broad-first');
    $check(class_exists($orderedClass) && constant($orderedClass . '::SOURCE') === 'specific-first', 'overlapping namespaces retain their configured entry order');
    spl_autoload_unregister($specificLoader);
    $reversedClass = $overlap . '\\Nested\\BroadFirst';
    $write($first, $reversedClass, 'specific-first');
    $write($second, $reversedClass, 'broad-first');
    $check(class_exists($reversedClass) && constant($reversedClass . '::SOURCE') === 'broad-first', 'reversing effective map order remains a distinct registration');

    registrationFixtureLoader([]);
    $defaults = spl_autoload_functions();
    EntityManagerFactory::registerEntityAutoloaders([]);
    $check(spl_autoload_functions() === $defaults, 'empty options retain defaults and register them only once');
    $invalid = [
        ['resources' => false],
        ['resources' => [0 => []]],
        ['resources' => ['doctrine2' => false]],
        $options([0 => 'invalid']),
        $options(array_replace($configuration, ['models_path' => ''])),
        $options(array_replace($configuration, ['repositories_path' => "bad\npath"])),
        $options(array_replace($configuration, ['models_namespace' => 'bad-namespace'])),
        $options(array_replace($configuration, ['proxies_path' => ''])),
        $options(array_replace($configuration, ['autogen_proxies' => -1])),
    ];
    foreach ($invalid as $index => $input) {
        $before = spl_autoload_functions();
        $rejected = false;
        try {
            EntityManagerFactory::registerEntityAutoloaders($input);
        } catch (LogicException | InvalidArgumentException $error) {
            $rejected = true;
        }
        $check($rejected && spl_autoload_functions() === $before, 'malformed options are rejected before registration or duplicate reuse: ' . $index);
    }

    $weakLoader = registrationFixtureLoader($options(array_replace($configuration, ['models_namespace' => $prefix . '\\Transient'])));
    $weak = WeakReference::create($weakLoader);
    spl_autoload_unregister($weakLoader);
    unset($weakLoader);
    $check($weak->get() === null, 'registration metadata does not strongly retain unregistered callbacks');
    echo 'ALL PASSED (' . $checks . " checks)\n";
} finally {
    foreach (spl_autoload_functions() as $loader) {
        if (!in_array($loader, $original, true)) {
            spl_autoload_unregister($loader);
        }
    }
    foreach (array_reverse($files) as $file) {
        unlink($file);
    }
    foreach (array_reverse($directories) as $path) {
        rmdir($path);
    }
}
