<?php

declare(strict_types=1);

/**
 * Reset the private operations database before each browser/control.
 * @param array<string,mixed> $options
 */
function seedBrowserOperations(
    Doctrine\ORM\EntityManagerInterface $em,
    Entities\Admin $admin,
    array $options,
    string $stateDir,
): void {
    $ownedDomain = $em->getRepository(Entities\Domain::class)->findOneBy(['domain' => 'owned.browser.example.test']);
    if (!$ownedDomain instanceof Entities\Domain) {
        throw new RuntimeException('operations fixture requires the seeded owned domain');
    }
    $conn = $em->getConnection();
    $conn->executeStatement('DROP TRIGGER IF EXISTS browser_operations_alias_failure');
    // The real mapping declares this index (Entities/Mailbox.php). Its absence
    // gives the schema preview/apply controls a deterministic additive change.
    $conn->executeStatement('DROP INDEX IF EXISTS IX_Mailbox_active ON mailbox');
    $conn->executeStatement('DELETE FROM mailbox_task');
    $conn->executeStatement('DELETE FROM queue_runner');
    $conn->executeStatement('DELETE FROM archive');
    $conn->executeStatement('DELETE FROM mcp_token');
    $conn->executeStatement('DELETE FROM resource_claim');
    $restoreMailbox = $em->getRepository(Entities\Mailbox::class)->findOneBy(['username' => 'restore@owned.browser.example.test']);
    if (!$restoreMailbox instanceof Entities\Mailbox) {
        $restoreMailbox = (new Entities\Mailbox())->setUsername('restore@owned.browser.example.test')->setLocalPart('restore')
            ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT))->setDomain($ownedDomain)
            ->setQuota(0)->setDeletePending(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($restoreMailbox);
        $ownedDomain->increaseMailboxCount();
    }
    // Keep this archive witness outside the active-mailbox bulk runner. A real
    // failed backend operation retains its resource fence, as it must.
    $restoreMailbox->setActive(false)->setName('Independent archive restore witness');
    $inactive = $em->getRepository(Entities\Domain::class)->findOneBy(['domain' => 'inactive.browser.example.test']);
    if (!$inactive instanceof Entities\Domain) {
        $inactive = (new Entities\Domain())->setDomain('inactive.browser.example.test')->setQuota(0)->setMaxQuota(0)
            ->setMaxMailboxes(0)->setMaxAliases(0)->setMailboxCount(1)->setAliasCount(1)
            ->setTransport('virtual')->setBackupmx(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($inactive);
    }
    $inactive->setActive(false);
    $mailbox = $em->getRepository(Entities\Mailbox::class)->findOneBy(['username' => 'user@inactive.browser.example.test']);
    if (!$mailbox instanceof Entities\Mailbox) {
        $mailbox = (new Entities\Mailbox())->setUsername('user@inactive.browser.example.test')->setLocalPart('user')
            ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT))
            ->setDomain($inactive)->setQuota(0)->setDeletePending(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($mailbox);
    }
    $mailbox->setActive(true)->setName('Inactive domain rollback canary');
    $alias = $em->getRepository(Entities\Alias::class)->findOneBy(['address' => 'alias@inactive.browser.example.test']);
    if (!$alias instanceof Entities\Alias) {
        $alias = (new Entities\Alias())->setAddress('alias@inactive.browser.example.test')
            ->setGoto('user@inactive.browser.example.test')->setDomain($inactive)->setCreated(new DateTime('2020-01-01'));
        $em->persist($alias);
    }
    $alias->setActive(true);
    $tasks = [];
    $scan = (new Entities\MailboxTask())->setType('SCAN_ORPHANS')->setUsername('__orphan_scan__')
        ->setStatus('DONE')->setCreatedAt(new DateTime('2020-01-01'))->setFinishedAt(new DateTime('2020-01-02'))
        ->setData(json_encode(['orphans' => ['orphan@owned.browser.example.test']], JSON_THROW_ON_ERROR));
    $em->persist($scan);
    foreach (['user', 'restore', 'delete', 'broken'] as $name) {
        $archive = (new Entities\Archive())->setUsername($name . '@owned.browser.example.test')
            ->setDomain($ownedDomain)->setStatus(Entities\Archive::STATUS_ARCHIVED)
            ->setArchivedAt(new DateTime('2020-01-01'))->setStatusChangedAt(new DateTime('2020-01-01'))
            ->setData('{}');
        $em->persist($archive);
    }
    foreach (['cancel' => 'PENDING', 'retry' => 'FAILED', 'delete' => 'DONE', 'run' => 'PENDING'] as $name => $status) {
        $task = (new Entities\MailboxTask())->setType('REPAIR')->setUsername($name . '@operations.browser.example.test')
            ->setStatus($status)->setCreatedAt(new DateTime('2020-01-01'))->setRequestedBy($admin);
        if ($status !== 'PENDING') {
            $task->setFinishedAt(new DateTime('2020-01-02'));
        }
        $em->persist($task);
        $tasks[$name] = $task;
    }
    $tokens = [];
    foreach (['operator' => 'read write', 'reader' => 'read', 'denied_ip' => 'read write',
        'revoked' => 'read write', 'expired' => 'read write'] as $role => $scope) {
        $raw = bin2hex(random_bytes(32));
        $token = (new Entities\McpToken())->setName('private browser ' . $role)->setTokenHash(hash('sha256', $raw))
            ->setScope($scope)->setAllowedIps($role === 'denied_ip' ? '192.0.2.0/24' : '127.0.0.1/32')
            ->setAllowedDomains('owned.browser.example.test operations.browser.example.test')
            ->setCreated(new DateTime('2020-01-01'));
        if ($role === 'revoked') {
            $token->setRevoked(true);
        } elseif ($role === 'expired') {
            $token->setExpiresAt(new DateTime('2020-01-02'));
        }
        $em->persist($token);
        $tokens[$role] = $raw;
    }
    $tokens['unknown'] = bin2hex(random_bytes(32));
    $em->flush();
    $state = ['password' => bin2hex(random_bytes(32)),
        'queueKey' => ViMbAdmin\Kernel\Input\Reader::optionString($options, '', ['queue', 'runner', 'key']),
        'tasks' => array_map(static function (Entities\MailboxTask $task): int {
            $id = $task->getId();
            if (!is_int($id) || $id < 1) {
                throw new RuntimeException('operations task id was not generated');
            }
            return $id;
        }, $tasks),
        'tokens' => $tokens];
    $path = $stateDir . '/operations-private.json';
    if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('private operations state could not be written');
    }
    chmod($path, 0600);
}
