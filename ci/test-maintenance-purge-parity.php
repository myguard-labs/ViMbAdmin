<?php

declare(strict_types=1);
define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';
$sqlLog = new MaintenanceTaskSqlLog();
$em = maintenanceTaskManager($sqlLog);
$conn = $em->getConnection();
$cases = [
    'single' => ['purge@purge-parity.test', null],
    'multiple' => ['purge@purge-parity.test,kept@example.test', 'kept@example.test'],
    'duplicate and whitespace' => ['purge@purge-parity.test, , purge@purge-parity.test,kept@example.test,', 'kept@example.test'],
    'middle recipient' => ['first@example.test,purge@purge-parity.test,last@example.test', 'first@example.test,last@example.test'],
    'empty unrelated list' => ['', ''],
    'unselected whitespace' => [' purge@purge-parity.test ', ' purge@purge-parity.test '],
    'unrelated malformed recipient' => ['purge@purge-parity.test,malformed', 'malformed'],
    'unrelated duplicates' => ['purge@purge-parity.test,kept@example.test,kept@example.test', 'kept@example.test,kept@example.test'],
];
foreach ($cases as $name => [$input, $expected]) {
    $em->clear();
    $conn->executeStatement("DELETE FROM alias WHERE address='list@purge-parity.test'");
    $conn->executeStatement("DELETE FROM mailbox WHERE username='purge@purge-parity.test'");
    $conn->executeStatement("DELETE FROM domain WHERE domain='purge-parity.test'");
    $conn->executeStatement("INSERT INTO domain (domain,created,alias_count,mailbox_count) VALUES ('purge-parity.test',NOW(),1,1)");
    $domain = maintenanceTaskInteger($conn->lastInsertId());
    $conn->executeStatement('INSERT INTO mailbox (username,password,local_part,active,created,Domain_id) VALUES (?,?,?,1,NOW(),?)', ['purge@purge-parity.test','!','purge',$domain]);
    $conn->executeStatement('INSERT INTO alias (address,goto,active,created,Domain_id) VALUES (?,?,1,NOW(),?)', ['list@purge-parity.test',$input,$domain]);
    $mailbox = $em->getRepository(\Entities\Mailbox::class)->findOneBy(['username' => 'purge@purge-parity.test']);
    if (!$mailbox instanceof \Entities\Mailbox) {
        throw new RuntimeException('purge parity mailbox fixture missing');
    }
    $operation = static function () use ($em, $mailbox): void {
        $repository = $em->getRepository(\Entities\Mailbox::class);
        if (!$repository instanceof \Repositories\Mailbox) {
            throw new RuntimeException('Mailbox repository mismatch');
        }
        $repository->purgeMailbox($mailbox, null, true);
        $em->flush();
    };
    $sqlLog->sql = [];
    $started = hrtime(true);
    \ViMbAdmin_Recipients::atomic($em, $operation);
    $elapsed = hrtime(true) - $started;
    echo 'purge-measurement '.json_encode(['case' => $name,'sql_queries' => count($sqlLog->sql),'backend_requests' => 0,'elapsed_ns' => $elapsed,'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n";
    $current = $conn->fetchOne("SELECT goto FROM alias WHERE address='list@purge-parity.test'");
    maintenanceTaskCheck('purge parity: ' . $name, $expected === null ? $current === false : $current === $expected);
    $counts = $conn->fetchAssociative('SELECT alias_count,mailbox_count FROM domain WHERE id=?', [$domain]);
    maintenanceTaskCheck('purge counts: ' . $name, is_array($counts) && maintenanceTaskInteger($counts['mailbox_count']) === 0 && maintenanceTaskInteger($counts['alias_count']) === ($expected === null ? 0 : 1));
}
echo "ALL PASSED\n";
