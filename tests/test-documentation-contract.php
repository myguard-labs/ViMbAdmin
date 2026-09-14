<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$versions = [];
foreach ($lock['packages'] ?? [] as $package) {
    if (isset($package['name'], $package['version'])) {
        $versions[$package['name']] = ltrim((string) $package['version'], 'v');
    }
}

$guide = (string) file_get_contents($root . '/docs/ORM3-UPGRADE.md');
$changelog = (string) file_get_contents($root . '/CHANGELOG');
$phpstan = (string) file_get_contents($root . '/phpstan.neon');

foreach (['doctrine/orm' => 'ORM', 'doctrine/dbal' => 'DBAL'] as $package => $label) {
    $version = $versions[$package] ?? null;
    if ($version === null
        || !str_contains($guide, "{$label} {$version}")
        || !str_contains($changelog, $label)
        || !str_contains($changelog, $version)) {
        fwrite(STDERR, "Documentation does not match locked {$package} version.\n");
        exit(1);
    }
}
if (preg_match('/^\s*level:\s*10\s*$/m', $phpstan) !== 1 || !str_contains($guide, 'level 10') || !str_contains($changelog, 'PHPStan level 10')) {
    fwrite(STDERR, "Documentation does not match the PHPStan level.\n");
    exit(1);
}

$stale = [
    'src/Kernel/Cli/CliKernel.php' => 'falls back to the ZF1',
    'src/Kernel/Router.php' => 'caller falls back to the ZF1',
    'src/Kernel/Controller/MaintenanceController.php' => 'stays on ZF1',
    'src/Kernel/Controller/ArchiveController.php' => 'restore` stays on ZF1',
    'src/Kernel/Controller/MailboxController.php' => 'not yet adapted to the native contract',
    'src/Kernel/Session/MagicPropertyStorage.php' => 'legacy ZF1 session namespace object',
];
foreach ($stale as $file => $claim) {
    if (str_contains((string) file_get_contents($root . '/' . $file), $claim)) {
        fwrite(STDERR, "Stale ownership claim remains in {$file}.\n");
        exit(1);
    }
}

echo "OK: documentation matches shipped ownership and configuration.\n";
