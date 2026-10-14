<?php

declare(strict_types=1);
define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';
$em = maintenanceTaskManager();
$conn = $em->getConnection();
$schema = new \ViMbAdmin_Schema($em);
$schema->apply($schema->pendingSql());
$beforeArchives = $conn->fetchAllAssociative('SELECT id,username,status,archived_at,status_changed_at,maildir_file,data,autoprune FROM archive ORDER BY id');
$beforeAliases = $conn->fetchAllAssociative('SELECT id,address,goto,active,Domain_id FROM alias ORDER BY id');
maintenanceTaskCheck('migration fixture has no live resource owners', maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM resource_claim')) === 0);
$conn->executeStatement('DROP TABLE resource_claim');
$conn->executeStatement('ALTER TABLE archive DROP COLUMN archive_generation, DROP COLUMN archive_version');
$conn->executeStatement('ALTER TABLE alias DROP COLUMN alias_version');
$replay = static function (): void {
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Migration environment unavailable');
    }
    $environment['MYSQL_PWD'] = getenv('DB_PASS') ?: '';
    $process = proc_open(
        ['mysql','--ssl=0','--host=' . (getenv('DB_HOST') ?: '127.0.0.1'),
        '--port=' . (getenv('DB_PORT') ?: '3306'),'--user=' . (getenv('DB_USER') ?: 'root'),getenv('DB_NAME') ?: 'vimbadmin'],
        [0 => ['file',__DIR__ . '/../contrib/migrations/2026-06-fork-schema.sql','r'],1 => ['pipe','w'],2 => ['pipe','w']],
        $pipes,
        null,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Migration client did not start');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Migration failed: ' . $error . $output);
    }
};
$replay();
maintenanceTaskCheck('upgrade preserves archive ids, data, status, destinations and timestamps', $beforeArchives === $conn->fetchAllAssociative('SELECT id,username,status,archived_at,status_changed_at,maildir_file,data,autoprune FROM archive ORDER BY id'));
maintenanceTaskCheck('upgrade preserves every recipient row and count', $beforeAliases === $conn->fetchAllAssociative('SELECT id,address,goto,active,Domain_id FROM alias ORDER BY id'));
maintenanceTaskCheck('upgrade installs alias default generation one', maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM alias WHERE alias_version != 1')) === 0);
maintenanceTaskCheck('historical archives receive distinct opaque generations', maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM archive WHERE CHAR_LENGTH(archive_generation) != 64')) === 0 && maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(*) FROM archive')) === maintenanceTaskInteger($conn->fetchOne('SELECT COUNT(DISTINCT archive_generation) FROM archive')));
$generations = $conn->fetchAllAssociative('SELECT id,archive_generation,archive_version FROM archive ORDER BY id');
$replay();
maintenanceTaskCheck('migration replay does not replace archive generations', $generations === $conn->fetchAllAssociative('SELECT id,archive_generation,archive_version FROM archive ORDER BY id'));
maintenanceTaskCheck('migration schema matches ORM with no pending SQL', $schema->pendingSql() === []);
echo "ALL PASSED\n";
