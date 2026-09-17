<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ViMbAdmin\Kernel\Input\Reader;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$error = static function (callable $operation): ?string {
    try {
        $operation();
    } catch (LogicException $exception) {
        return $exception->getMessage();
    }
    return null;
};

echo "== shared controller input reader ==\n";

foreach ([
    'empty string' => ['', ''],
    'numeric-looking string' => ['0', '0'],
    'ordinary string' => ['value', 'value'],
] as $label => [$input, $expected]) {
    $check("required string preserves {$label}", Reader::requiredString($input, 'Field') === $expected);
}
foreach ([null, false, 0, 1.5, [], new stdClass()] as $input) {
    $check(
        'required string rejects ' . get_debug_type($input),
        $error(static fn (): string => Reader::requiredString($input, 'Field')) === 'Field must be a string'
    );
}

foreach ([
    'empty map' => [],
    'nested map' => ['child' => ['value' => 7]],
] as $label => $input) {
    $check("string-keyed array preserves {$label}", Reader::stringKeyedArray($input, 'Config') === $input);
}
foreach ([
    'scalar' => 7,
    'object' => new stdClass(),
    'list' => ['value'],
    'mixed keys' => ['valid' => 1, 0 => 2],
] as $label => $input) {
    $expected = is_array($input) ? 'Config must use string keys' : 'Config must be an array';
    $check(
        "string-keyed array rejects {$label}",
        $error(static fn (): array => Reader::stringKeyedArray($input, 'Config')) === $expected
    );
}

$options = ['outer' => ['inner' => 'value'], 'null' => null];
foreach ([
    'present nested value' => [['outer', 'inner'], [true, 'value']],
    'missing nested value' => [['outer', 'missing'], [false, null]],
    'present null value' => [['null'], [true, null]],
    'empty path' => [[], [true, $options]],
] as $label => [$path, $expected]) {
    $check("option preserves {$label}", Reader::option($options, ...$path) === $expected);
}
$check(
    'option rejects a scalar intermediate with the historical path error',
    $error(static fn (): array => Reader::option(['outer' => 'bad'], 'outer', 'inner'))
        === 'Configuration outer must be an array'
);
$check(
    'option rejects a list-shaped root with the historical root error',
    $error(static fn (): mixed => (new ReflectionMethod(Reader::class, 'option'))
        ->invoke(null, [0 => 'bad'], 'outer'))
        === 'Configuration root must use string keys'
);

$controllers = [
    'MaintenanceController.php', 'ArchiveController.php', 'DomainController.php', 'AdminController.php',
    'QueueController.php', 'AliasController.php', 'AuthController.php', 'MailboxController.php',
];
$delegates = true;
foreach ($controllers as $controller) {
    $source = file_get_contents(__DIR__ . '/../src/Kernel/Controller/' . $controller);
    $delegates = $delegates && is_string($source) && str_contains($source, 'Kernel\\Input\\Reader::option');
}
$check('all eight controllers delegate option traversal to the shared reader', $delegates);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
