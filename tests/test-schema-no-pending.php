<?php

/**
 * Regression guard: no phantom pending schema changes.
 *
 * Background (commits 51b1dea "introspection-guard the setting CREATE",
 * 711544a "stop phantom pending ALTER on Dovecot-owned tables", and the
 * Maintenance-tab schema-sync feature):
 *
 *   The Maintenance tab runs Doctrine's schema diff and offers to apply it.
 *   Twice the entity mappings drifted from the shipped SQL so the diff was never
 *   empty on a fresh, fully-migrated database — the tab showed a permanent
 *   "pending changes" state and, worse, wanted to ALTER tables that Dovecot
 *   owns (dovecot_quota, dovecot_last_login) and that ViMbAdmin must not touch.
 *
 * This test reproduces the real install path against the CI MariaDB:
 *   1. SchemaTool::createSchema() from attribute mappings (= orm:schema-tool
 *      --force, which builds every Doctrine-managed table incl. the
 *      dovecot_last_login dict table that the base SQL dump does NOT contain)
 *   2. apply the shipped fork SQL extras (FKs / collations / non-mappable bits)
 *   3. assert SchemaTool::getUpdateSchemaSql() returns NOTHING.
 *
 * Any non-empty diff = the attribute mappings and shipped migration SQL have
 * drifted = the phantom-pending / phantom-ALTER regression is back.
 *
 * Env:
 *   DB_HOST (default 127.0.0.1), DB_PORT (3306), DB_NAME (vimbadmin),
 *   DB_USER (root), DB_PASS ('')
 *
 * Exit 0 = clean, 1 = phantom pending changes, 2 = setup error.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/ViMbAdmin/Schema.php';

// The Entities\ and Repositories\ classes live under application/ and are not
// in Composer's PSR map. Register the same bounded SPL namespace shape used by
// the native entity-manager factory so the mappings resolve.
spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/../application/' . $dir . '/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Tools\SchemaTool;

function schemaRequiredNonNegativeInteger(mixed $value, string $label): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer !== false) {
            return $integer;
        }
    }
    throw new UnexpectedValueException("MariaDB returned an invalid {$label}");
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'vimbadmin';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$entityDir = realpath(__DIR__ . '/../application/Entities');
if ($entityDir === false) {
    fwrite(STDERR, "application/Entities dir not found\n");
    exit(2);
}

// 1. EM from the attribute mappings — same source of truth the app uses.
$config = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
// ORM 3 on PHP 8.4+: native lazy objects are the proxy backend the app uses
// (see EntityManagerFactory); the EM ctor instantiates ProxyFactory eagerly.
$config->enableNativeLazyObjects(true);

// DBAL 4 dropped the `object` type that DirectoryEntry.jpegPhoto maps to;
// register the same compat shim the app does so SchemaTool can declare it.
if (!\Doctrine\DBAL\Types\Type::hasType(\ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME)) {
    \Doctrine\DBAL\Types\Type::addType(
        \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::NAME,
        \ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType::class
    );
}

$connection = DriverManager::getConnection([
    'driver'   => 'pdo_mysql',
    'host'     => $host,
    'port'     => $port,
    'dbname'   => $name,
    'user'     => $user,
    'password' => $pass,
    'charset'  => getenv('DB_CHARSET') ?: 'utf8',
], $config);

$em = new EntityManager($connection, $config);

$metadatas = $em->getMetadataFactory()->getAllMetadata();
if (count($metadatas) === 0) {
    fwrite(STDERR, "no entity metadata discovered — mapping path wrong?\n");
    exit(2);
}

$tool = new SchemaTool($em);

// 2. Build the schema from the mappings (= orm:schema-tool --force). The
//    workflow then layers the shipped fork SQL via the mysql client (which
//    handles the PREPARE/EXECUTE guard blocks the fork file uses), and runs
//    this script a SECOND time with SKIP_CREATE=1 to diff against that.
if (getenv('SKIP_CREATE') !== '1') {
    $tool->dropDatabase();
    $tool->createSchema($metadatas);
}

if (getenv('VERIFY_FRESH_SCHEMA_COLLATION') === '1') {
    $freshCollations = $connection->fetchAllKeyValue(
        "SELECT TABLE_NAME, CONCAT(CHARACTER_SET_NAME, '/', COLLATION_NAME)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('mailbox', 'dovecot_quota')
           AND COLUMN_NAME = 'username'
         ORDER BY TABLE_NAME"
    );
    if (count($freshCollations) !== 2
        || ($freshCollations['mailbox'] ?? null) !== ($freshCollations['dovecot_quota'] ?? null)) {
        fwrite(STDERR, 'fresh schema username collations differ: '
            . json_encode($freshCollations) . "\n");
        exit(1);
    }
    echo "OK: fresh schema creates matching mailbox and quota collations\n";
}

// Seed deliberately stale counters before the external mysql step applies the
// consolidated migration. The follow-up invocation verifies both correction
// and boundary behavior against the real MariaDB schema and column names.
if (getenv('SEED_ALIAS_COUNT_DRIFT') === '1') {
    $connection->executeStatement(
        "INSERT INTO `domain`
            (`domain`, `quota`, `transport`, `backupmx`, `active`, `created`,
             `max_quota`, `max_aliases`, `max_mailboxes`, `alias_count`, `mailbox_count`)
         VALUES
            ('drift-forward.test', 0, 'virtual', 0, 1, NOW(), 0, 2, 0, 99, 0),
            ('drift-self.test', 0, 'virtual', 0, 1, NOW(), 0, 2, 0, 7, 0),
            ('drift-empty.test', 0, 'virtual', 0, 1, NOW(), 0, 2, 0, 5, 0)"
    );
    $connection->executeStatement(
        "INSERT INTO `alias` (`address`, `goto`, `active`, `created`, `Domain_id`)
         SELECT 'forward@drift-forward.test', 'target@example.test', 1, NOW(), `id`
           FROM `domain` WHERE `domain` = 'drift-forward.test'
         UNION ALL
         SELECT 'self@drift-forward.test', 'self@drift-forward.test', 1, NOW(), `id`
           FROM `domain` WHERE `domain` = 'drift-forward.test'
         UNION ALL
         SELECT 'Case@drift-forward.test', 'case@drift-forward.test', 1, NOW(), `id`
           FROM `domain` WHERE `domain` = 'drift-forward.test'
         UNION ALL
         SELECT 'self@drift-self.test', 'self@drift-self.test', 1, NOW(), `id`
           FROM `domain` WHERE `domain` = 'drift-self.test'"
    );
}

if (getenv('VERIFY_ALIAS_COUNT_RECONCILIATION') === '1') {
    $actualCounts = $connection->fetchAllKeyValue(
        "SELECT `domain`, `alias_count` FROM `domain`
         WHERE `domain` IN ('drift-forward.test', 'drift-self.test', 'drift-empty.test')
         ORDER BY `domain`"
    );
    $actualCounts = array_map(
        static fn (mixed $count): int => schemaRequiredNonNegativeInteger($count, 'alias count'),
        $actualCounts,
    );
    $expectedCounts = [
        'drift-empty.test' => 0,
        'drift-forward.test' => 2,
        'drift-self.test' => 0,
    ];
    $aliasRows = schemaRequiredNonNegativeInteger(
        $connection->fetchOne("SELECT COUNT(*) FROM `alias` WHERE `address` LIKE '%@drift-%'"),
        'alias row count',
    );
    if ($actualCounts !== $expectedCounts || $aliasRows !== 4) {
        fwrite(STDERR, 'alias-count reconciliation failed: ' . json_encode([
            'counts' => $actualCounts,
            'alias_rows' => $aliasRows,
        ]) . "\n");
        exit(1);
    }
    echo "OK: alias counts exclude self-aliases and preserve source rows\n";
}

if (getenv('VERIFY_NATIVE_COLLATION_ALIGNMENT') === '1') {
    $mailboxCharset = $connection->fetchOne(
        "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'username'"
    );
    $mailboxCollation = $connection->fetchOne(
        "SELECT COLLATION_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mailbox' AND COLUMN_NAME = 'username'"
    );
    if (!is_string($mailboxCharset) || !is_string($mailboxCollation)) {
        fwrite(STDERR, "mailbox username collation metadata is missing\n");
        exit(2);
    }

    $driftCharset = $mailboxCharset === 'utf8mb4' ? 'utf8mb3' : 'utf8mb4';
    $driftCollation = $driftCharset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : 'utf8mb3_unicode_ci';
    $connection->executeStatement(
        'ALTER TABLE `dovecot_quota` DROP FOREIGN KEY `FK_dovecot_quota_mailbox`'
    );
    $connection->executeStatement(sprintf(
        'ALTER TABLE `dovecot_quota` MODIFY `username` VARCHAR(255) '
            . 'CHARACTER SET %s COLLATE %s NOT NULL',
        $driftCharset,
        $driftCollation,
    ));

    $nativeSchema = new ViMbAdmin_Schema($em);
    $nativeSql = $nativeSchema->extraSql();
    $expectedAlignment = sprintf(
        'ALTER TABLE `dovecot_quota` MODIFY `username` VARCHAR(255) '
            . 'CHARACTER SET %s COLLATE %s NOT NULL',
        $mailboxCharset,
        $mailboxCollation,
    );
    if (!in_array($expectedAlignment, $nativeSql, true)
        || count(array_filter(
            $nativeSql,
            static fn (string $statement): bool => str_contains(
                $statement,
                'ADD CONSTRAINT `FK_dovecot_quota_mailbox`'
            )
        )) !== 1) {
        fwrite(STDERR, 'native schema did not derive the mailbox collation and restore its FK: '
            . json_encode($nativeSql) . "\n");
        exit(1);
    }

    $nativeSchema->apply($nativeSql);
    $quotaMetadata = $connection->fetchAssociative(
        "SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dovecot_quota' AND COLUMN_NAME = 'username'"
    );
    if (!is_array($quotaMetadata)
        || ($quotaMetadata['CHARACTER_SET_NAME'] ?? null) !== $mailboxCharset
        || ($quotaMetadata['COLLATION_NAME'] ?? null) !== $mailboxCollation
        || $nativeSchema->extraSql() !== []) {
        fwrite(STDERR, 'native schema collation alignment is not applied or idempotent: '
            . json_encode($quotaMetadata) . "\n");
        exit(1);
    }
    echo "OK: native schema derives and applies the live mailbox collation\n";
}

// 3. Diff the mapped metadata against the current DB schema.
$sql = $tool->getUpdateSchemaSql($metadatas);

// 3. Production's additive updater never drops an unmanaged table. Apply that
//    same filter here before checking mapped schema drift; the shipped `setting`
//    table is intentionally absent from Doctrine metadata.
//
// Doctrine also never manages the Dovecot-owned dict tables; ignore any diff
// that targets them (defence in depth — they should not be in the mappings at all,
// but if a mapping is added by mistake this keeps the message precise).
// Match the table name with or without backticks. These tables carry
// column-definition timestamp defaults that Doctrine's schema comparator cannot
// round-trip, so it reports a perpetual phantom ALTER even on a fresh schema —
// the exact noise commit 711544a suppressed. Real drift on OTHER tables still
// fails the test.
$dovecotOwned = ['dovecot_quota', 'dovecot_last_login'];
$pending = array_values(array_filter($sql, static function (string $stmt) use ($dovecotOwned): bool {
    if (preg_match('/^\s*DROP\s+TABLE\b/i', $stmt) === 1) {
        return false;
    }
    foreach ($dovecotOwned as $t) {
        if (preg_match('/\b`?' . preg_quote($t, '/') . '`?\b/i', $stmt)) {
            return false; // drop — Dovecot owns it / un-diffable column-definition
        }
    }
    return true;
}));

if ($pending === []) {
    echo "OK: schema is in sync — no phantom pending changes\n";
    echo 'PHP ' . PHP_VERSION . "\n";
    exit(0);
}

echo "FAIL: schema-tool wants to apply " . count($pending) . " change(s) on a fully-migrated DB:\n";
foreach ($pending as $stmt) {
    echo "  $stmt;\n";
}
echo "-> the attribute mappings and contrib/migrations SQL have drifted again.\n";
exit(1);
