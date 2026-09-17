<?php

/** Focused contract test for the framework-free mutation contexts. */

namespace ViMbAdmin\Tests;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Doctrine2/WithPreferences.php';
require __DIR__ . '/../application/Entities/Admin.php';
require __DIR__ . '/../application/Entities/Alias.php';
require __DIR__ . '/../application/Entities/Domain.php';
require __DIR__ . '/../application/Entities/Mailbox.php';

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessages.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MutationContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/AliasContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MailboxContext.php';
require __DIR__ . '/../src/Kernel/Plugin/AbstractContext.php';
require __DIR__ . '/../src/Kernel/Plugin/AliasContext.php';
require __DIR__ . '/../src/Kernel/Plugin/MailboxContext.php';

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Plugin\AbstractContext;
use ViMbAdmin\Kernel\Plugin\AliasContext;
use ViMbAdmin\Kernel\Plugin\MailboxContext;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class MutationContextAssertions
{
    public static int $failures = 0;
}

function checkMutationContext(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        MutationContextAssertions::$failures++;
    }
}

final class MutationContextEntityManager extends EntityManagerDecorator
{
    public function __construct()
    {
        $entityManager = (new ReflectionClass(EntityManager::class))->newInstanceWithoutConstructor();
        parent::__construct($entityManager);
    }
}

$session = new stdClass();
$flash = new FlashMessages(new MagicPropertyStorage($session));
$em = new MutationContextEntityManager();
$admin = new \Entities\Admin();
$domain = new \Entities\Domain();
$alias = new \Entities\Alias();
$mailbox = new \Entities\Mailbox();
$callback = static fn (string $value): string => strtoupper($value);
$options = ['feature' => ['enabled' => true], 'limit' => 0, 'callback' => $callback];
$context = new class ($em, $admin, $domain, $options, $flash) extends AbstractContext {};
$aliasContext = new AliasContext($em, $admin, $domain, $alias, $options, $flash);
$mailboxContext = new MailboxContext($em, $admin, $domain, $mailbox, $options, $flash);

checkMutationContext('returns the complete typed options map', $context->getOptions() === $options);
checkMutationContext('preserves configured callbacks by identity', $context->getOptions()['callback'] === $callback);
checkMutationContext(
    'returns each mutation dependency unchanged',
    $context->getD2EM() === $em && $context->getAdmin() === $admin && $context->getDomain() === $domain
);
checkMutationContext(
    'returns each specialized plugin entity unchanged',
    $aliasContext->getAlias() === $alias && $mailboxContext->getMailbox() === $mailbox
);

// The public legacy surface intentionally remains object-based. Plugin test
// doubles and compatibility adapters must not be rejected or copied eagerly.
$legacyEm = new stdClass();
$legacyAdmin = new stdClass();
$legacyDomain = new stdClass();
$legacyAlias = new stdClass();
$legacyMailbox = new stdClass();
$legacyAliasContext = new AliasContext(
    $legacyEm,
    $legacyAdmin,
    $legacyDomain,
    $legacyAlias,
    [],
    $flash,
);
$legacyMailboxContext = new MailboxContext(
    $legacyEm,
    $legacyAdmin,
    $legacyDomain,
    $legacyMailbox,
    [],
    $flash,
);
checkMutationContext(
    'accepts wrong-object legacy adapters without eager validation',
    $legacyAliasContext->getD2EM() === $legacyEm
        && $legacyAliasContext->getAdmin() === $legacyAdmin
        && $legacyAliasContext->getDomain() === $legacyDomain
        && $legacyAliasContext->getAlias() === $legacyAlias
        && $legacyMailboxContext->getMailbox() === $legacyMailbox
);
checkMutationContext(
    'keeps public legacy accessors free of native return narrowing',
    !(new ReflectionMethod(AbstractContext::class, 'getD2EM'))->hasReturnType()
        && !(new ReflectionMethod(AbstractContext::class, 'getAdmin'))->hasReturnType()
        && !(new ReflectionMethod(AbstractContext::class, 'getDomain'))->hasReturnType()
        && !(new ReflectionMethod(AliasContext::class, 'getAlias'))->hasReturnType()
        && !(new ReflectionMethod(MailboxContext::class, 'getMailbox'))->hasReturnType()
);

$context->addMessage('created');
$message = $flash->peek()[0] ?? null;
checkMutationContext('queues the legacy default class and type as success', $message !== null && $message->text === 'created' && $message->level === FlashMessages::SUCCESS);

$context->addMessage('failed', FlashMessages::ERROR, 1);
$message = $flash->peek()[1] ?? null;
checkMutationContext('queues the legacy error class while ignoring the block type', $message !== null && $message->text === 'failed' && $message->level === FlashMessages::ERROR);

$context->addMessage('empty', '');
$message = $flash->peek()[2] ?? null;
checkMutationContext('queues success for an empty class at the boundary', $message !== null && $message->level === FlashMessages::SUCCESS);
$messageRejected = false;
try {
    $context->addMessage(['not', 'text']);
} catch (\TypeError) {
    $messageRejected = true;
}
checkMutationContext(
    'rejects container plugin messages without queueing',
    $messageRejected && count($flash->peek()) === 3
);

$failures = MutationContextAssertions::$failures;
echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
