<?php

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Entities\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Entities\\')));
    require __DIR__ . '/../application/Entities/' . $relative . '.php';
});

final class PreferenceContractTestState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

final class PreferenceContractAlias extends \Entities\Alias
{
    /** @var list<\Entities\AliasPreference> */
    private array $testPreferences = [];

    public function addTestPreference(\Entities\AliasPreference $preference): void
    {
        $this->testPreferences[] = $preference;
        parent::addPreference($preference);
    }

    /** @return list<\Entities\AliasPreference> */
    public function _getPreferences(): array
    {
        return $this->testPreferences;
    }
}

final class PreferenceContractAdmin extends \Entities\Admin
{
    /** @var list<\Entities\AdminPreference> */
    private array $testPreferences = [];

    public function addTestPreference(\Entities\AdminPreference $preference): void
    {
        $this->testPreferences[] = $preference;
        parent::addPreference($preference);
    }

    /** @return list<\Entities\AdminPreference> */
    public function _getPreferences(): array
    {
        return $this->testPreferences;
    }
}

final class PreferenceContractDomain extends \Entities\Domain
{
    /** @var list<\Entities\DomainPreference> */
    private array $testPreferences = [];

    public function addTestPreference(\Entities\DomainPreference $preference): void
    {
        $this->testPreferences[] = $preference;
        parent::addPreference($preference);
    }

    /** @return list<\Entities\DomainPreference> */
    public function _getPreferences(): array
    {
        return $this->testPreferences;
    }
}

final class PreferenceContractMailbox extends \Entities\Mailbox
{
    /** @var list<\Entities\MailboxPreference> */
    private array $testPreferences = [];

    public function addTestPreference(\Entities\MailboxPreference $preference): void
    {
        $this->testPreferences[] = $preference;
        parent::addPreference($preference);
    }

    /** @return list<\Entities\MailboxPreference> */
    public function _getPreferences(): array
    {
        return $this->testPreferences;
    }
}

function preferenceContractCheck(string $label, bool $condition): void
{
    PreferenceContractTestState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        PreferenceContractTestState::$failures++;
    }
}

function preferenceContractThrows(string $message, \Closure $operation): bool
{
    try {
        $operation();
    } catch (\LogicException $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
}

echo "== nullable preference contracts ==\n";

$configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([]);
if (PHP_VERSION_ID >= 80400) {
    $configuration->enableNativeLazyObjects(true);
} else {
    $configuration->setProxyDir($configuration->getProxyDir() ?? sys_get_temp_dir());
    $configuration->setProxyNamespace($configuration->getProxyNamespace() ?? 'Proxies');
}
$connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql'], $configuration);
\OSS_Runtime::configure([], '', new \Doctrine\ORM\EntityManager($connection, $configuration));

$freshAdminPreference = new \Entities\AdminPreference();
preferenceContractCheck(
    'fresh Admin preference preserves nullable persistence fields',
    $freshAdminPreference->getAttribute() === null
        && $freshAdminPreference->getIx() === null
        && $freshAdminPreference->getValue() === null,
);

$admin = new PreferenceContractAdmin();
$adminPlain = (new \Entities\AdminPreference())
    ->setAttribute('plain')->setIx(0)->setValue('')->setExpire(0);
$admin->addTestPreference($adminPlain);
preferenceContractCheck('Admin valid preference preserves an empty string value', $admin->getPreference('plain', 0, true) === '');
preferenceContractCheck('Admin indexed preference preserves zero index and empty value', $admin->getIndexedPreference('plain', true, true) === [0 => ['p_index' => 0, 'p_value' => '']]);
preferenceContractCheck('Admin associated preference preserves its value', $admin->getAssocPreference('plain') === [0 => '']);

$adminMissingAttribute = new PreferenceContractAdmin();
$adminMissingAttribute->addTestPreference(
    (new \Entities\AdminPreference())->setIx(0)->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'Admin rejects a null preference attribute',
    preferenceContractThrows(
        'Preference attribute cannot be null.',
        static fn (): mixed => $adminMissingAttribute->loadPreference('missing', 0, true),
    ),
);

$adminMissingIndex = new PreferenceContractAdmin();
$adminMissingIndex->addTestPreference(
    (new \Entities\AdminPreference())->setAttribute('missing')->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'Admin rejects a null preference index',
    preferenceContractThrows(
        'Preference index cannot be null.',
        static fn (): mixed => $adminMissingIndex->getPreference('missing', 0, true),
    ),
);

$adminMissingValue = new PreferenceContractAdmin();
$adminMissingValue->addTestPreference(
    (new \Entities\AdminPreference())->setAttribute('missing')->setIx(0)->setExpire(0),
);
preferenceContractCheck(
    'Admin rejects a null preference value',
    preferenceContractThrows(
        'Preference value cannot be null.',
        static fn (): mixed => $adminMissingValue->getPreference('missing', 0, true),
    ),
);

$alias = new PreferenceContractAlias();
$aliasPlain = (new \Entities\AliasPreference())
    ->setAttribute('plain')->setIx(2)->setValue('alias-value')->setExpire(0);
$aliasNested = (new \Entities\AliasPreference())
    ->setAttribute('tree.leaf')->setIx(3)->setValue('alias-nested')->setExpire(0);
$alias->addTestPreference($aliasPlain);
$alias->addTestPreference($aliasNested);
preferenceContractCheck('Alias valid preference returns its string value', $alias->getPreference('plain', 2, true) === 'alias-value');
preferenceContractCheck('Alias indexed preference preserves index and value', $alias->getIndexedPreference('plain', true, true) === [2 => ['p_index' => 2, 'p_value' => 'alias-value']]);
preferenceContractCheck('Alias associated preference preserves its nested key', $alias->getAssocPreference('tree') === [3 => ['leaf' => 'alias-nested']]);

$domain = new PreferenceContractDomain();
$domainPlain = (new \Entities\DomainPreference())
    ->setAttribute('plain')->setIx(2)->setValue('domain-value')->setExpire(0);
$domainNested = (new \Entities\DomainPreference())
    ->setAttribute('tree.leaf')->setIx(3)->setValue('domain-nested')->setExpire(0);
$domain->addTestPreference($domainPlain);
$domain->addTestPreference($domainNested);
preferenceContractCheck('Domain valid preference returns its string value', $domain->getPreference('plain', 2, true) === 'domain-value');
preferenceContractCheck('Domain indexed preference preserves index and value', $domain->getIndexedPreference('plain', true, true) === [2 => ['p_index' => 2, 'p_value' => 'domain-value']]);
preferenceContractCheck('Domain associated preference preserves its nested key', $domain->getAssocPreference('tree') === [3 => ['leaf' => 'domain-nested']]);

$mailbox = new PreferenceContractMailbox();
$mailboxPlain = (new \Entities\MailboxPreference())
    ->setAttribute('plain')->setIx(2)->setValue('mailbox-value')->setExpire(0);
$mailboxNested = (new \Entities\MailboxPreference())
    ->setAttribute('tree.leaf')->setIx(3)->setValue('mailbox-nested')->setExpire(0);
$mailbox->addTestPreference($mailboxPlain);
$mailbox->addTestPreference($mailboxNested);
preferenceContractCheck('Mailbox valid preference returns its string value', $mailbox->getPreference('plain', 2, true) === 'mailbox-value');
preferenceContractCheck('Mailbox indexed preference preserves index and value', $mailbox->getIndexedPreference('plain', true, true) === [2 => ['p_index' => 2, 'p_value' => 'mailbox-value']]);
preferenceContractCheck('Mailbox associated preference preserves its nested key', $mailbox->getAssocPreference('tree') === [3 => ['leaf' => 'mailbox-nested']]);

$loadMissingAttributeOwner = new PreferenceContractAlias();
$loadMissingAttributeOwner->addTestPreference(
    (new \Entities\AliasPreference())->setIx(0)->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'load rejects a null Alias preference attribute',
    preferenceContractThrows(
        'Preference attribute cannot be null.',
        static fn (): mixed => $loadMissingAttributeOwner->loadPreference('missing', 0, true),
    ),
);

$getMissingIndexOwner = new PreferenceContractDomain();
$getMissingIndexOwner->addTestPreference(
    (new \Entities\DomainPreference())->setAttribute('missing')->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'get rejects a null Domain preference index',
    preferenceContractThrows(
        'Preference index cannot be null.',
        static fn (): mixed => $getMissingIndexOwner->getPreference('missing', 0, true),
    ),
);

$addMissingAttributeOwner = new PreferenceContractMailbox();
$addMissingAttributeOwner->addTestPreference(
    (new \Entities\MailboxPreference())->setIx(0)->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'add indexed rejects a null Mailbox preference attribute',
    preferenceContractThrows(
        'Preference attribute cannot be null.',
        static fn (): mixed => $addMissingAttributeOwner->addIndexedPreference('missing', 'new-value'),
    ),
);

$indexedMissingIndexOwner = new PreferenceContractAlias();
$indexedMissingIndexOwner->addTestPreference(
    (new \Entities\AliasPreference())->setAttribute('missing')->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'indexed rejects a null Alias preference index',
    preferenceContractThrows(
        'Preference index cannot be null.',
        static fn (): mixed => $indexedMissingIndexOwner->getIndexedPreference('missing', true, true),
    ),
);

$assocMissingValueOwner = new PreferenceContractDomain();
$assocMissingValueOwner->addTestPreference(
    (new \Entities\DomainPreference())->setAttribute('missing.leaf')->setIx(0)->setExpire(0),
);
preferenceContractCheck(
    'associated rejects a null Domain preference value',
    preferenceContractThrows(
        'Preference value cannot be null.',
        static fn (): mixed => $assocMissingValueOwner->getAssocPreference('missing'),
    ),
);

$deleteMissingAttributeOwner = new PreferenceContractMailbox();
$deleteMissingAttributeOwner->addTestPreference(
    (new \Entities\MailboxPreference())->setIx(0)->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'delete rejects a null Mailbox preference attribute',
    preferenceContractThrows(
        'Preference attribute cannot be null.',
        static fn (): mixed => $deleteMissingAttributeOwner->deletePreference('missing'),
    ),
);

$deleteAssocMissingIndexOwner = new PreferenceContractAlias();
$deleteAssocMissingIndexOwner->addTestPreference(
    (new \Entities\AliasPreference())->setAttribute('missing')->setValue('value')->setExpire(0),
);
preferenceContractCheck(
    'associated delete rejects a null Alias preference index',
    preferenceContractThrows(
        'Preference index cannot be null.',
        static fn (): mixed => $deleteAssocMissingIndexOwner->deleteAssocPreference('missing', 0),
    ),
);

$cleanMissingAttributeOwner = new PreferenceContractDomain();
$cleanMissingAttributeOwner->addTestPreference(
    (new \Entities\DomainPreference())->setIx(0)->setValue('value')->setExpire(1),
);
preferenceContractCheck(
    'expired cleanup rejects a null Domain preference attribute',
    preferenceContractThrows(
        'Preference attribute cannot be null.',
        static fn (): mixed => $cleanMissingAttributeOwner->cleanExpiredPreferences(time(), 'missing'),
    ),
);

$preferenceResultValidator = new \ReflectionMethod(PreferenceContractAdmin::class, '_validatedPreferenceResults');
$preferenceResultValidator->setAccessible(true);
$wrongPreferenceRejected = false;
try {
    $preferenceResultValidator->invoke(new PreferenceContractAdmin(), [new \Entities\AliasPreference()]);
} catch (\UnexpectedValueException) {
    $wrongPreferenceRejected = true;
}
preferenceContractCheck('query results reject a preference from the wrong entity family', $wrongPreferenceRejected);
$nonObjectPreferenceRejected = false;
try {
    $preferenceResultValidator->invoke(new PreferenceContractAdmin(), [new stdClass()]);
} catch (\UnexpectedValueException) {
    $nonObjectPreferenceRejected = true;
}
preferenceContractCheck('query results reject an object outside the preference entity contract', $nonObjectPreferenceRejected);

preferenceContractCheck('fixed assertion count', PreferenceContractTestState::$checks === 26);

echo PreferenceContractTestState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . PreferenceContractTestState::$failures . " assertion(s) failed\n";
exit(PreferenceContractTestState::$failures === 0 ? 0 : 1);
