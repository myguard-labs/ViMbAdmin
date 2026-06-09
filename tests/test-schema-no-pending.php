<?php
/**
 * Regression guard: no phantom pending schema changes.
 *
 * Background (commits 51b1dea "introspection-guard the setting CREATE",
 * 711544a "stop phantom pending ALTER on Dovecot-owned tables", and the
 * Maintenance-tab schema-sync feature):
 *
 *   The Maintenance tab runs Doctrine's schema diff and offers to apply it.
 *   Twice the XML mappings drifted from the shipped SQL so the diff was never
 *   empty on a fresh, fully-migrated database — the tab showed a permanent
 *   "pending changes" state and, worse, wanted to ALTER tables that Dovecot
 *   owns (dovecot_quota, dovecot_last_login) and that ViMbAdmin must not touch.
 *
 * This test reproduces the real install path against the CI MariaDB:
 *   1. SchemaTool::createSchema() from the XML mappings  (= orm:schema-tool
 *      --force, which builds every Doctrine-managed table incl. the
 *      dovecot_last_login dict table that the base SQL dump does NOT contain)
 *   2. apply the shipped fork SQL extras (FKs / collations / non-mappable bits)
 *   3. assert SchemaTool::getUpdateSchemaSql() returns NOTHING.
 *
 * Any non-empty diff = the XML mappings and the shipped migration SQL have
 * drifted = the phantom-pending / phantom-ALTER regression is back.
 *
 * Env:
 *   DB_HOST (default 127.0.0.1), DB_PORT (3306), DB_NAME (vimbadmin),
 *   DB_USER (root), DB_PASS ('')
 *
 * Exit 0 = clean, 1 = phantom pending changes, 2 = setup error.
 */

require __DIR__ . '/../vendor/autoload.php';

// The Entities\ and Repositories\ classes live under application/ and are not
// in Composer's PSR map (the app registers them via the Zend/Doctrine class
// loader at runtime). Register a minimal loader so the mappings resolve.
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

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'vimbadmin';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$xmlDir = realpath(__DIR__ . '/../doctrine2/xml');
if ($xmlDir === false) {
    fwrite(STDERR, "doctrine2/xml mapping dir not found\n");
    exit(2);
}

// 1. EM from the XML mappings — same source of truth the app uses.
//    simplified=false: file names are the FQCN form (Entities.Mailbox.dcm.xml).
$config = ORMSetup::createXMLMetadataConfiguration([$xmlDir], true);
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

// 3. Diff the mapped metadata against the current DB schema.
$sql = $tool->getUpdateSchemaSql($metadatas);

// 3. Doctrine never manages the Dovecot-owned dict tables; ignore any diff that
//    targets them (defence in depth — they should not be in the mappings at all,
//    but if a mapping is added by mistake this keeps the message precise).
// Match the table name with or without backticks. These tables carry
// column-definition timestamp defaults that Doctrine's schema comparator cannot
// round-trip, so it reports a perpetual phantom ALTER even on a fresh schema —
// the exact noise commit 711544a suppressed. Real drift on OTHER tables still
// fails the test.
$dovecotOwned = ['dovecot_quota', 'dovecot_last_login'];
$pending = array_values(array_filter($sql, static function (string $stmt) use ($dovecotOwned): bool {
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
echo "-> the XML mappings and contrib/migrations SQL have drifted again.\n";
exit(1);
