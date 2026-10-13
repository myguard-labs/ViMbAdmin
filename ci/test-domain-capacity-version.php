<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;

EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => __DIR__ . '/../application',
    'repositories_path' => __DIR__ . '/../application',
]]]);
$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../application/Entities'], true);
$config->enableNativeLazyObjects(true);
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $config), $config);
$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks++;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
};
$metadata = $em->getClassMetadata(\Entities\Domain::class);
$check('every ORM domain update uses optimistic capacity versioning', $metadata->isVersioned && $metadata->versionField === 'capacity_version');
foreach (['Alias', 'Mailbox'] as $kind) {
    $domain = new \Entities\Domain();
    $set = 'set' . $kind . 'Count';
    $get = 'get' . $kind . 'Count';
    $increase = 'increase' . $kind . 'Count';
    $decrease = 'decrease' . $kind . 'Count';
    $domain->$set(1);
    $original = $domain->getModified();
    $domain->$set(1);
    $check($kind . ': assigning the same count is a no-op', $domain->getModified() === $original);
    $domain->$increase();
    $check($kind . ': increment changes count and ORM dirty marker', $domain->$get() === 2 && $domain->getModified() !== $original);
    $domain->$decrease();
    $check($kind . ': net-zero increment/decrement remains dirty', $domain->$get() === 1 && $domain->getModified() !== $original);
    $original = $domain->getModified();
    $domain->$set(0);
    $check($kind . ': setter writer changes count and ORM dirty marker', $domain->$get() === 0 && $domain->getModified() !== $original);
    $original = $domain->getModified();
    $domain->$decrease();
    $check($kind . ': zero cannot underflow or create a phantom update', $domain->$get() === 0 && $domain->getModified() === $original);
    try {
        (new ReflectionMethod($domain, $set))->invoke($domain, ['malformed']);
        $check($kind . ': malformed count rejected', false);
    } catch (TypeError) {
        $check($kind . ': malformed count has no mutation side effects', $domain->$get() === 0 && $domain->getModified() === $original);
    }
}
// Validate the real Doctrine change set for a restored count. Comparing PHP
// object identity alone would miss a mapping that stopped tracking modified.
$domain = (new \Entities\Domain())->setDomain('capacity.test')->setAliasCount(1)->setMailboxCount(0);
(new ReflectionProperty($domain, 'id'))->setValue($domain, 1);
$em->getUnitOfWork()->registerManaged($domain, ['id' => 1], [
    'domain' => 'capacity.test', 'alias_count' => 1, 'mailbox_count' => 0,
    'modified' => $domain->getModified(), 'capacity_version' => 1,
]);
$domain->decreaseAliasCount();
$domain->increaseAliasCount();
$em->getUnitOfWork()->computeChangeSet($metadata, $domain);
$changeSet = $em->getUnitOfWork()->getEntityChangeSet($domain);
$check('net-zero transition schedules an ORM UPDATE and hence the CAS', isset($changeSet['modified']) && !isset($changeSet['alias_count']));
echo 'ALL PASSED (' . $checks . " checks)\n";
