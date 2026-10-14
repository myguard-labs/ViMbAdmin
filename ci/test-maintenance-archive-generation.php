<?php

declare(strict_types=1);
define('MAINTENANCE_TASK_HELPERS_ONLY', true);
require __DIR__ . '/test-maintenance-task-ownership.php';
final class MaintenancePruneBackend extends \ViMbAdmin_Doveadm
{
    /** @var list<string> */
    public array $deleted = [];
    public function __construct()
    {
    }
    #[\Override]
    public function fsDelete($path, $filter = 'posix')
    {
        $this->deleted[] = $path;
        return [];
    }
}
final class MaintenancePruneRunner extends \ViMbAdmin_Service_QueueRunner
{
    public MaintenancePruneBackend $backend;
    #[\Override]
    protected function newDoveadm($progress)
    {
        return $this->backend;
    }
    #[\Override]
    protected function now()
    {
        return new DateTime('2030-01-01 12:00:00');
    }
}
$em = maintenanceTaskManager();
$schema = new \ViMbAdmin_Schema($em);
$schema->apply($schema->pendingSql());
$conn = $em->getConnection();
$cases = ['replacement age','identical metadata ABA','replacement row','newly disabled','status changed','already removed','legacy task','malformed task','noninteger cutoff','rejected null archived time','longer current retention','eligible generation','exact retention boundary'];
foreach ($cases as $mode) {
    $em->clear();
    $conn->executeStatement("DELETE FROM mailbox_task WHERE username='prune-generation@example.test'");
    $conn->executeStatement('DELETE FROM queue_runner');
    $conn->executeStatement("DELETE FROM archive WHERE username='prune-generation@example.test'");
    $age = match ($mode) {
        'exact retention boundary' => new DateTime('2029-10-03 12:00:00'),
        'longer current retention' => new DateTime('2029-09-23 12:00:00'),
        default => new DateTime('2000-01-01'),
    };
    $archive = (new \Entities\Archive())->setUsername('prune-generation@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)
        ->setAutoprune(true)->setArchivedAt($age)->setStatusChangedAt(clone $age)->setMaildirFile('/backup/original-generation')->setData('{}');
    $em->persist($archive);
    $em->flush();
    $runner = new MaintenancePruneRunner($em, ['queue' => ['runner' => ['max_concurrent' => 1],'autoprune' => ['days' => 90]]]);
    $runner->backend = new MaintenancePruneBackend();
    \ViMbAdmin_Setting::set($em, \ViMbAdmin_Setting::LAST_PRUNE_SWEEP, '0');
    (new ReflectionMethod(\ViMbAdmin_Service_QueueRunner::class, 'autopruneSweep'))->invoke($runner);
    $task = $em->getRepository(\Entities\MailboxTask::class)->findOneBy(['username' => 'prune-generation@example.test','type' => \Entities\MailboxTask::TYPE_PRUNE]);
    if (!$task instanceof \Entities\MailboxTask || $task->getStatus() !== 'PENDING') {
        throw new RuntimeException('production prune enqueue did not create current fixture task: ' . $mode);
    }
    switch ($mode) {
        case 'replacement age': $archive->setArchivedAt(new DateTime('2030-01-01'))->setStatusChangedAt(new DateTime('2030-01-01'))->setMaildirFile('/backup/replacement-generation');
            break;
        case 'identical metadata ABA': $archive->renewGeneration();
            break;
        case 'replacement row':
            $em->remove($archive);
            $em->flush();
            $archive = (new \Entities\Archive())->setUsername('prune-generation@example.test')->setStatus(\Entities\Archive::STATUS_ARCHIVED)
                ->setAutoprune(true)->setArchivedAt($age)->setStatusChangedAt(clone $age)->setMaildirFile('/backup/original-generation')->setData('{}');
            $em->persist($archive);
            break;
        case 'newly disabled': $archive->setAutoprune(false);
            break;
        case 'status changed': $archive->setStatus(\Entities\Archive::STATUS_RESTORING);
            break;
        case 'already removed': $em->remove($archive);
            break;
        case 'legacy task': $task->setData('{"dest":"/backup/original-generation"}');
            break;
        case 'malformed task': $task->setData('{broken');
            break;
        case 'noninteger cutoff':
            $payload = json_decode($task->getData() ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('production prune payload must be an object');
            }
            $payload['cutoff'] = 'invalid cutoff';
            $task->setData(json_encode($payload, JSON_THROW_ON_ERROR));
            break;
        case 'rejected null archived time':
            $persistedTime = $conn->fetchOne('SELECT archived_at FROM archive WHERE id=?', [$archive->getId()]);
            try {
                $conn->executeStatement('UPDATE archive SET archived_at=NULL WHERE id=?', [$archive->getId()]);
                throw new RuntimeException('null archive timestamp unexpectedly persisted');
            } catch (\Doctrine\DBAL\Exception\NotNullConstraintViolationException) {
                maintenanceTaskCheck('mapped schema rejects null archived time without changing eligibility', $conn->fetchOne('SELECT archived_at FROM archive WHERE id=?', [$archive->getId()]) === $persistedTime);
            }
            break;
        case 'longer current retention':
            $runner = new MaintenancePruneRunner($em, ['queue' => ['runner' => ['max_concurrent' => 1],'autoprune' => ['days' => 180]]]);
            $runner->backend = new MaintenancePruneBackend();
            break;
    }
    $em->flush();
    $completed = $runner->runOne($task, static function (?Throwable $error) use ($task): void {
        if ($error !== null) {
            throw $error;
        }
        $task->setStatus('DONE')->setRunner(null)->setFinishedAt(new DateTime());
    });
    maintenanceTaskCheck('current prune task executes: ' . $mode, $completed === \ViMbAdmin_Service_QueueRunner::RUN_ONE_COMPLETED);
    $valid = in_array($mode, ['eligible generation','exact retention boundary','rejected null archived time'], true);
    maintenanceTaskCheck('prune storage precondition: ' . $mode, $runner->backend->deleted === ($valid ? ['/backup/original-generation'] : []));
    maintenanceTaskCheck('prune row precondition: ' . $mode, maintenanceTaskInteger($conn->fetchOne("SELECT COUNT(*) FROM archive WHERE username='prune-generation@example.test'")) === ($valid || $mode === 'already removed' ? 0 : 1));
}
echo "ALL PASSED\n";
