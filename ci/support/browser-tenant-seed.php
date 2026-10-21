<?php

declare(strict_types=1);

use ViMbAdmin\Tests\BrowserFixture\SuperAdmin;

// Included only by the private browser fixture after its session writer boots.
// Each browser run resets flags and role sessions; no deployed state is used.
$tenantAdmins = ['super' => SuperAdmin::requireAdmin($admin)];
foreach (['assigned', 'unassigned', 'target'] as $role) {
    $username = $role . '@browser.example.test';
    $actor = $em->getRepository(Entities\Admin::class)->findOneBy(['username' => $username]);
    if (!$actor instanceof Entities\Admin) {
        $actor = (new Entities\Admin())->setUsername($username)
            ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT))
            ->setSuper(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($actor);
    }
    $actor->setActive(true);
    $tenantAdmins[$role] = $actor;
}
$tenantDomains = [];
$tenantMailboxes = [];
foreach (['owned', 'foreign'] as $tenant) {
    $name = $tenant . '.browser.example.test';
    $domain = $em->getRepository(Entities\Domain::class)->findOneBy(['domain' => $name]);
    if (!$domain instanceof Entities\Domain) {
        $domain = (new Entities\Domain())->setDomain($name)->setQuota(0)->setMaxQuota(0)
            ->setMaxMailboxes(0)->setMaxAliases(0)->setMailboxCount(1)->setAliasCount(0)
            ->setTransport('virtual')->setBackupmx(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($domain);
    }
    $domain->setActive(true);
    $tenantDomains[$tenant] = $domain;
    $username = 'user@' . $name;
    $mailbox = $em->getRepository(Entities\Mailbox::class)->findOneBy(['username' => $username]);
    if (!$mailbox instanceof Entities\Mailbox) {
        $mailbox = (new Entities\Mailbox())->setUsername($username)->setLocalPart('user')
            ->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT))
            ->setDomain($domain)->setQuota(0)->setDeletePending(false)->setCreated(new DateTime('2020-01-01'));
        $em->persist($mailbox);
    }
    $mailbox->setActive(true)->setName('Tenant fixture mailbox');
    $tenantMailboxes[$tenant] = $mailbox;
}
if (!$tenantAdmins['assigned']->canManageDomain($tenantDomains['owned'])) {
    $tenantAdmins['assigned']->addDomain($tenantDomains['owned']);
}
$em->flush();
foreach (['super', 'assigned', 'unassigned'] as $role) {
    $actor = $tenantAdmins[$role];
    $csrfToken = bin2hex(random_bytes(32));
    $writeSession('tenant_' . $role, static function () use ($actor, $csrfToken): void {
        $_SESSION['Application'] = ['timeOfLastAction' => time(), 'csrfToken' => $csrfToken];
        $_SESSION['ViMbAdmin_Auth'] = ['storage' => [
            'username' => $actor->requiredUsername(), 'user' => $actor, 'id' => $actor->getId(),
        ]];
    });
    $statePath = $stateDir . '/tenant_' . $role . '.json';
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $state['csrfToken'] = $csrfToken;
    if (file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('private tenant CSRF state could not be written');
    }
}
$tenantIds = [
    'admin' => array_map(static fn (Entities\Admin $actor): int => $actor->getId(), $tenantAdmins),
    'domain' => array_map(static fn (Entities\Domain $domain): int => $domain->requiredId(), $tenantDomains),
    'mailbox' => array_map(static fn (Entities\Mailbox $mailbox): int => $mailbox->getId(), $tenantMailboxes),
];
$tenantIdsPath = $stateDir . '/tenant-ids.json';
if (file_put_contents($tenantIdsPath, json_encode($tenantIds, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('tenant fixture ids could not be written');
}
chmod($tenantIdsPath, 0600);
