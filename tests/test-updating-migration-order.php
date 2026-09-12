<?php

$updating = file_get_contents(__DIR__ . '/../UPDATING');
if (!is_string($updating)) {
    fwrite(STDERR, "unable to read UPDATING\n");
    exit(1);
}
$seed = strpos($updating, 'mysql < contrib/migrations/2026-06-fork-schema.sql');
$schema = strpos($updating, './bin/vimbtool.php -a maintenance.cli-schema-update');
$migrationOrderOk = $seed !== false && $schema !== false && $seed < $schema
    && str_contains($updating, 'seeds `dovecot_quota` from the legacy columns before retiring them');
$migration = file_get_contents(__DIR__ . '/../contrib/migrations/2026-06-fork-schema.sql');
$migrationOrderOk = $migrationOrderOk && is_string($migration)
    && str_contains($migration, "@have_last_login_table = 1 AND @have = 0");
$migrationOrderOk = $migrationOrderOk && is_string($migration)
    && str_contains($migration, "@have_archive_table = 1 AND @have = 0");
$companionRolloutOk = str_contains($updating, '`myguard-labs/vimbadmin-crs-plugin#9` DataTables 2 allowlist')
    && str_contains($updating, 'All three components must be deployed')
    && str_contains($updating, 'simultaneously.');
$check = static function (string $label, bool $ok): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
};
$check('migration seeds legacy quota before schema update', $migrationOrderOk);
$check('DataTables allowlists require simultaneous companion rollout', $companionRolloutOk);
if (!$migrationOrderOk || !$companionRolloutOk) {
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
