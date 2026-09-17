<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';

final class MailboxPasswordState
{
    public static int $failures = 0;
}

function mailboxPasswordCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        MailboxPasswordState::$failures++;
    }
}

/** @return string|null */
function mailboxPasswordFailure(callable $operation): ?string
{
    try {
        $operation();
    } catch (Throwable $e) {
        return $e->getPrevious()?->getMessage() ?? $e->getMessage();
    }
    return null;
}

final class MailboxPasswordQueueRepository
{
    public function __construct(private \Entities\Mailbox $mailbox)
    {
    }

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria): \Entities\Mailbox
    {
        return $this->mailbox;
    }
}

final class MailboxPasswordQueueEntityManager
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private MailboxPasswordQueueRepository $repository)
    {
    }

    public function getRepository(string $className): MailboxPasswordQueueRepository
    {
        $this->calls[] = 'repository:' . $className;
        return $this->repository;
    }

    public function getConnection(): never
    {
        $this->calls[] = 'connection';
        throw new RuntimeException('unexpected queue I/O');
    }

    public function persist(object $object): never
    {
        $this->calls[] = 'persist:' . $object::class;
        throw new RuntimeException('unexpected queue persistence');
    }
}

echo "== required mailbox password ==\n";

$newMailbox = new \Entities\Mailbox();
mailboxPasswordCheck('pre-hydration getter preserves null', $newMailbox->getPassword() === null);
mailboxPasswordCheck(
    'required password rejects pre-hydration null',
    mailboxPasswordFailure($newMailbox->requiredPassword(...)) === 'Mailbox password cannot be null.'
);

$initialized = (new \Entities\Mailbox())->setPassword('{PLAIN}stored-secret');
mailboxPasswordCheck(
    'required password preserves the initialized credential',
    $initialized->requiredPassword() === '{PLAIN}stored-secret'
);

$matcher = new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AuthController::class, 'mailboxPasswordMatches');
$options = ['pwhash' => 'crypt:sha512'];
$hashed = (new \Entities\Mailbox())->setPassword(\OSS_Auth_Password::hash('correct horse', $options));
mailboxPasswordCheck(
    'authentication accepts a matching initialized credential',
    $matcher->invoke(null, $hashed, 'correct horse', $options) === true
);
mailboxPasswordCheck(
    'authentication rejects an incorrect credential',
    $matcher->invoke(null, $hashed, 'wrong horse', $options) === false
);
mailboxPasswordCheck(
    'authentication rejects an uninitialized credential without an exception',
    $matcher->invoke(null, $newMailbox, 'correct horse', $options) === false
);

$queueMailbox = (new \Entities\Mailbox())->setUsername('user@example.test');
$queueEm = new MailboxPasswordQueueEntityManager(new MailboxPasswordQueueRepository($queueMailbox));
$runnerReflection = new ReflectionClass(ViMbAdmin_Service_QueueRunner::class);
$runner = $runnerReflection->newInstanceWithoutConstructor();
$runnerReflection->getProperty('em')->setValue($runner, $queueEm);
$runnerReflection->getProperty('options')->setValue($runner, []);
$recordArchive = $runnerReflection->getMethod('recordArchive');
$task = (new \Entities\MailboxTask())->setUsername('user@example.test');
mailboxPasswordCheck(
    'archive snapshot rejects an uninitialized credential',
    mailboxPasswordFailure(
        static fn (): mixed => $recordArchive->invoke($runner, $task, '/unused', false),
    ) === 'Mailbox password cannot be null.'
);
mailboxPasswordCheck(
    'archive password failure precedes quota I/O and persistence',
    $queueEm->calls === ['repository:\\Entities\\Mailbox']
);
echo MailboxPasswordState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . MailboxPasswordState::$failures . " FAILED\n";
exit(MailboxPasswordState::$failures === 0 ? 0 : 1);
