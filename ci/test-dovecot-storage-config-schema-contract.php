<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return list<string> */
$mailboxQueries = static function (string $config): array {
    if (preg_match('/^#   passdb sql \{.*?^#   \}$/ms', $config, $passdb) !== 1
        || preg_match('/^#   userdb sql \{.*?^#   \}$/ms', $config, $userdb) !== 1
    ) {
        throw new RuntimeException('storage config must contain passdb and userdb SQL examples');
    }

    preg_match_all(
        '/^#\s+(?:iterate_)?query\s*=\s*(SELECT\b.*?\bFROM\s+mailbox\b.*?)\s*(?=^#\s+(?:iterate_)?query\s*=|^#\s*\})/msi',
        $passdb[0] . "\n" . $userdb[0],
        $matches,
    );
    if (count($matches[1]) !== 3) {
        throw new RuntimeException('storage config must contain exactly three mailbox SQL queries');
    }

    return $matches[1];
};

/** @return list<string> */
$droppedMailboxColumns = static function (string $migration): array {
    preg_match_all(
        '/ALTER TABLE `mailbox` DROP COLUMN `([a-z_]+)`/i',
        $migration,
        $matches,
    );

    return array_values(array_unique($matches[1]));
};

/** @return list<string> */
$mailboxEntityColumns = static function (string $entity): array {
    preg_match_all(
        '/#\[ORM\\\\Column\([^\r\n]*\)\]\R\s*private\s+[^$]*\$([a-z_]+)/m',
        $entity,
        $matches,
    );

    return array_values(array_unique($matches[1]));
};

$root = dirname(__DIR__);
$paths = [
    'config' => $root . '/contrib/dovecot/conf.d/00-mail-storage.conf',
    'migration' => $root . '/contrib/migrations/2026-06-fork-schema.sql',
    'entity' => $root . '/application/Entities/Mailbox.php',
];
$contents = [];
foreach ($paths as $name => $path) {
    $value = file_get_contents($path);
    $check("error: shipped {$name} is readable", is_string($value));
    $contents[$name] = is_string($value) ? $value : '';
}
$quotaMutation = getenv('VIMBADMIN_QUOTA_MUTATION');
if ($quotaMutation === 'passdb') {
    $contents['config'] = str_replace(' AS userdb_quota_rule', ' AS removed_passdb_quota_rule', $contents['config']);
} elseif ($quotaMutation === 'userdb') {
    $contents['config'] = str_replace(' AS quota_rule', ' AS removed_userdb_quota_rule', $contents['config']);
}

$queries = [];
try {
    $queries = $mailboxQueries($contents['config']);
} catch (RuntimeException $exception) {
    echo '  INFO query parse error: ' . $exception->getMessage() . "\n";
}
$check('boundary: passdb, userdb, and iterate examples are all parsed', count($queries) === 3);

$storageColumns = ['uid', 'gid', 'homedir', 'maildir'];
$droppedStorageColumns = array_values(array_intersect(
    $droppedMailboxColumns($contents['migration']),
    $storageColumns,
));
sort($droppedStorageColumns);
$check(
    'positive: the migration retires every config-owned mailbox storage column',
    $droppedStorageColumns === ['gid', 'homedir', 'maildir', 'uid'],
);

$entityColumns = $mailboxEntityColumns($contents['entity']);
$check(
    'boundary: the entity parser reads namespaced ORM column attributes',
    $mailboxEntityColumns("#[ORM\\Column(type: 'integer')]\nprivate ?int \$uid = null;") === ['uid'],
);
$check(
    'positive: the entity parser reads current Mailbox columns',
    in_array('username', $entityColumns, true) && in_array('quota', $entityColumns, true),
);
$check(
    'positive: the Mailbox entity exposes none of the retired storage columns',
    array_intersect($droppedStorageColumns, $entityColumns) === [],
);

$queryText = implode("\n", $queries);
$check(
    'positive: passdb exposes the quota rule through userdb_quota_rule',
    preg_match('/\bAS\s+userdb_quota_rule\b/i', $queries[0] ?? '') === 1,
);
$check(
    'positive: userdb exposes the quota rule through quota_rule',
    preg_match('/\bAS\s+quota_rule\b/i', $queries[1] ?? '') === 1,
);
$check(
    'boundary: iterate query remains valid without a quota alias',
    preg_match('/\bAS\s+(?:userdb_)?quota_rule\b/i', $queries[2] ?? '') !== 1,
);
$retiredReferences = array_values(array_filter(
    $droppedStorageColumns,
    static fn (string $column): bool => preg_match(
        '/(?<![a-z_])' . preg_quote($column, '/') . '(?![a-z_])/i',
        $queryText,
    ) === 1,
));
$check(
    'negative: Dovecot SQL examples select no columns retired by the migration',
    $retiredReferences === [],
);

$malformedRejected = false;
try {
    $mailboxQueries("#   passdb sql {\n#     query = SELECT quota\n#   }\n");
} catch (RuntimeException) {
    $malformedRejected = true;
}
$check('malformed: an incomplete SQL example is rejected', $malformedRejected);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
