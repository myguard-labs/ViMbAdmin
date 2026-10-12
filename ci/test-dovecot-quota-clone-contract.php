<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array<string,string> */
$quotaMapTables = static function (string $config): array {
    preg_match_all(
        '/dict_map\s+priv\/quota\/(storage|messages)\s*\{(.*?)^\s*\}/ms',
        $config,
        $maps,
        PREG_SET_ORDER,
    );

    $tables = [];
    foreach ($maps as $map) {
        $name = $map[1];
        if (array_key_exists($name, $tables)) {
            throw new RuntimeException("duplicate quota map: {$name}");
        }
        if (preg_match('/^\s*sql_table\s*=\s*([a-zA-Z0-9_]+)\s*$/m', $map[2], $table) !== 1) {
            throw new RuntimeException("quota map has no valid sql_table: {$name}");
        }
        $tables[$name] = $table[1];
    }

    if (array_keys($tables) !== ['storage', 'messages']) {
        throw new RuntimeException('quota maps must define storage and messages exactly once');
    }

    return $tables;
};

$root = dirname(__DIR__);
$paths = [
    'config' => $root . '/contrib/dovecot/conf.d/99-zzz-quotaclone.conf',
    'guide' => $root . '/contrib/dovecot/README.md',
    'migration_guide' => $root . '/docs/MIGRATION.md',
    'schema_migration' => $root . '/contrib/migrations/2026-06-fork-schema.sql',
];
$contents = [];
foreach ($paths as $name => $path) {
    $value = file_get_contents($path);
    $check("error: shipped {$name} is readable", is_string($value));
    $contents[$name] = is_string($value) ? $value : '';
}

$tables = null;
try {
    $tables = $quotaMapTables($contents['config']);
} catch (RuntimeException $exception) {
    echo '  INFO quota-map parse error: ' . $exception->getMessage() . "\n";
}
$check(
    'positive: storage and message usage write only to dovecot_quota',
    $tables === ['storage' => 'dovecot_quota', 'messages' => 'dovecot_quota'],
);

$check(
    'boundary: the schema migration retires both mirror triggers before dropping legacy size columns',
    preg_match(
        '/DROP TRIGGER IF EXISTS `trg_dq_ins`.*DROP TRIGGER IF EXISTS `trg_dq_upd`'
        . '.*DROP COLUMN `maildir_size`.*DROP COLUMN `homedir_size`.*DROP COLUMN `size_at`/s',
        $contents['schema_migration']
    ) === 1,
);

$malformedRejected = false;
try {
    $quotaMapTables($contents['config'] . "\n" . 'dict_map priv/quota/storage {'
        . "\n  sql_table = mailbox\n}\n");
} catch (RuntimeException) {
    $malformedRejected = true;
}
$check('malformed: a duplicate quota map is rejected by the contract parser', $malformedRejected);

$currentMaterials = $contents['config'] . "\n" . $contents['guide'];
preg_match('/^\| \[`99-zzz-quotaclone\.conf`\].*$/m', $contents['migration_guide'], $migrationRow);
$currentMaterials .= "\n" . ($migrationRow[0] ?? '');
$check(
    'negative: shipped quota-clone materials do not require the retired mailbox size mirror',
    preg_match('/mailbox\.maildir_size|mailbox\.size_at|trigger\s+mirrors?\s+it/i', $currentMaterials) === 0,
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
