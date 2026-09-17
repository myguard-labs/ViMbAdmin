<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
require_once __DIR__ . '/../library/ViMbAdmin/MailboxQueue.php';

function mailboxUsernameEntityManager(): \Doctrine\ORM\EntityManager
{
    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([]);
    $configuration->enableNativeLazyObjects(true);
    $connection = \Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    return new \Doctrine\ORM\EntityManager($connection, $configuration);
}

final class MailboxUsernameState
{
    public static int $failures = 0;
}

function mailboxUsernameCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        MailboxUsernameState::$failures++;
    }
}

/** @return string|null */
function mailboxUsernameFailure(callable $operation): ?string
{
    try {
        $operation();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

echo "== required mailbox username ==\n";

$newMailbox = new \Entities\Mailbox();
mailboxUsernameCheck('pre-hydration getter preserves null', $newMailbox->getUsername() === null);
mailboxUsernameCheck(
    'required username rejects pre-hydration null',
    mailboxUsernameFailure($newMailbox->requiredUsername(...)) === 'Mailbox username cannot be null.'
);

$initialized = (new \Entities\Mailbox())->setUsername('user@example.test');
mailboxUsernameCheck(
    'required username preserves initialized address',
    $initialized->requiredUsername() === 'user@example.test'
);
mailboxUsernameCheck(
    'nullable alternative email preserves the entity storage contract',
    $initialized->setAltEmail(null)->getAltEmail() === null
);

$controllerReflection = new ReflectionClass(\ViMbAdmin\Kernel\Controller\MailboxController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$pageTitle = $controllerReflection->getMethod('mailboxPageTitle');
mailboxUsernameCheck(
    'add form preserves the legitimate pre-hydration branch',
    $pageTitle->invoke($controller, $newMailbox) === 'Add Mailbox'
);
mailboxUsernameCheck(
    'edit form renders the initialized mailbox identity',
    $pageTitle->invoke($controller, $initialized) === 'Edit Mailbox: user@example.test'
);
$persistedMalformed = new \Entities\Mailbox();
(new ReflectionMethod($persistedMalformed, 'assignGeneratedId'))->invoke($persistedMalformed, 17);
mailboxUsernameCheck(
    'edit form rejects a persisted mailbox without an identity',
    mailboxUsernameFailure(
        static fn (): mixed => $pageTitle->invoke($controller, $persistedMalformed),
    ) === 'Mailbox username cannot be null.'
);

$invalidQueue = mailboxUsernameEntityManager();
mailboxUsernameCheck('queue rejects null username', mailboxUsernameFailure(
    static fn (): ?\Entities\MailboxTask => \ViMbAdmin_MailboxQueue::enqueue(
        $invalidQueue,
        $newMailbox,
        \Entities\MailboxTask::TYPE_REPAIR,
    ),
) === 'Mailbox username cannot be null.');
mailboxUsernameCheck(
    'queue null failure precedes query and persistence',
    !$invalidQueue->getConnection()->isConnected()
        && $invalidQueue->getUnitOfWork()->getScheduledEntityInsertions() === []
);

echo MailboxUsernameState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . MailboxUsernameState::$failures . " FAILED\n";
exit(MailboxUsernameState::$failures === 0 ? 0 : 1);
