<?php

declare(strict_types=1);

require_once __DIR__ . '/../application/Entities/MailboxTask.php';

final class MailboxTaskLabelTestState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function mailboxTaskLabelCheck(string $label, bool $ok): void
{
    MailboxTaskLabelTestState::$checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        MailboxTaskLabelTestState::$failures++;
    }
}

function mailboxTaskLabelRejects(string $method, mixed $value): bool
{
    try {
        (new ReflectionMethod(\Entities\MailboxTask::class, $method))
            ->invoke(new \Entities\MailboxTask(), $value);
    } catch (TypeError) {
        return true;
    }
    return false;
}

echo "== mailbox task label contracts ==\n";

$fresh = new \Entities\MailboxTask();
mailboxTaskLabelCheck(
    'pre-hydration labels preserve null',
    $fresh->getTypeLabel() === null && $fresh->getStatusLabel() === null
);

$knownTypes = true;
foreach (\Entities\MailboxTask::$TYPES as $type => $label) {
    $knownTypes = $knownTypes
        && (new \Entities\MailboxTask())->setType($type)->getTypeLabel() === $label;
}
mailboxTaskLabelCheck('known task types retain human-readable labels', $knownTypes);

$knownStatuses = true;
foreach (\Entities\MailboxTask::$STATUSES as $status => $label) {
    $knownStatuses = $knownStatuses
        && (new \Entities\MailboxTask())->setStatus($status)->getStatusLabel() === $label;
}
mailboxTaskLabelCheck('known task statuses retain human-readable labels', $knownStatuses);

$unmappedTypes = [
    \Entities\MailboxTask::TYPE_MEASURE_SIZE,
    \Entities\MailboxTask::TYPE_PRUNE,
    \Entities\MailboxTask::TYPE_BACKUP_ORPHAN,
];
$unmappedPassthrough = true;
foreach ($unmappedTypes as $type) {
    $unmappedPassthrough = $unmappedPassthrough
        && (new \Entities\MailboxTask())->setType($type)->getTypeLabel() === $type;
}
mailboxTaskLabelCheck(
    'declared but unmapped task types retain passthrough behavior',
    $unmappedPassthrough
);
mailboxTaskLabelCheck(
    'unknown task type retains exact passthrough value',
    (new \Entities\MailboxTask())->setType('FUTURE_TASK')->getTypeLabel() === 'FUTURE_TASK'
);
mailboxTaskLabelCheck(
    'unknown task status retains exact passthrough value',
    (new \Entities\MailboxTask())->setStatus('PAUSED')->getStatusLabel() === 'PAUSED'
);

$explicitNull = new \Entities\MailboxTask();
(new ReflectionMethod(\Entities\MailboxTask::class, 'setType'))->invoke($explicitNull, null);
(new ReflectionMethod(\Entities\MailboxTask::class, 'setStatus'))->invoke($explicitNull, null);
mailboxTaskLabelCheck(
    'explicit runtime null retains the nullable label boundary',
    $explicitNull->getTypeLabel() === null && $explicitNull->getStatusLabel() === null
);

mailboxTaskLabelCheck(
    'non-string task type remains rejected by the typed property',
    mailboxTaskLabelRejects('setType', [])
);
mailboxTaskLabelCheck(
    'non-string task status remains rejected by the typed property',
    mailboxTaskLabelRejects('setStatus', [])
);
mailboxTaskLabelCheck('fixed assertion count', MailboxTaskLabelTestState::$checks === 9);

echo MailboxTaskLabelTestState::$failures === 0
    ? "ALL PASSED\n"
    : MailboxTaskLabelTestState::$failures . " FAILED\n";
exit(MailboxTaskLabelTestState::$failures === 0 ? 0 : 1);
