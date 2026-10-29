<?php

declare(strict_types=1);

// Runs inside tests/test-controller-alias-identity.php, using its actual controller
// and recording ORM fixture. SQL locking and persistence remain separate gates.
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\RouteMatch;

/** @return array{Entities\Domain, Entities\Alias, ControllerAliasIdentityRecordingEntityManager, MailboxController} */
function mailboxSelfAliasCounterFixture(
    int $count,
    ?string $address,
    ?string $goto,
    string $username = 'user@self.test',
    bool $authorized = true,
): array {
    $domain = (new \Entities\Domain())->setDomain('self.test')->setAliasCount($count);
    (new ReflectionProperty(\Entities\Domain::class, 'id'))->setValue($domain, 30);
    $mailbox = (new \Entities\Mailbox())->setUsername($username)->setDomain($domain);
    $alias = (new \Entities\Alias())->setDomain($domain);
    if ($address !== null) {
        $alias->setAddress($address);
    }
    if ($goto !== null) {
        $alias->setGoto($goto);
    }
    $entityManager = new ControllerAliasIdentityRecordingEntityManager([
        'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$alias]),
        'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
    ]);
    $controller = new MailboxController(
        controllerAliasIdentityContainerFor(
            $entityManager,
            new ControllerAliasIdentitySession(['identity' => ['id' => 2], 'csrfToken' => 'test-token']),
            new ControllerAliasIdentityView(),
            new ControllerAliasIdentityScopedAdmin($authorized ? [$domain] : []),
        ),
        new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
            'mid' => '3', 'alid' => '7', 'csrf' => 'test-token',
        ]),
    );
    return [$domain, $alias, $entityManager, $controller];
}

foreach ([0, 1, 4, PHP_INT_MAX] as $count) {
    [$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(
        $count,
        'user@self.test',
        'user@self.test',
    );
    $response = $controller->deleteAliasAction();
    controllerAliasIdentityCheck(
        'self-alias deletion preserves forwarding count ' . $count,
        $response->status === 302
            && str_starts_with((string) ($response->headers['Location'] ?? ''), '/mailbox/aliases/mid/')
            && $entityManager->removed === [$alias]
            && $domain->getAliasCount() === $count
            && $entityManager->flushes === 1
            && count($entityManager->persisted) === 1
            && $entityManager->persisted[0] instanceof \Entities\Log,
    );
}

[$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(
    0,
    '0@self.test',
    '0@self.test',
    '0@self.test',
);
$controller->deleteAliasAction();
controllerAliasIdentityCheck(
    'numeric-zero localpart self-alias leaves zero forwarding capacity unchanged',
    $domain->getAliasCount() === 0 && $entityManager->removed === [$alias] && $entityManager->flushes === 1,
);

foreach ([[1, 0], [4, 3]] as [$count, $expected]) {
    [$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(
        $count,
        'forward@self.test',
        'user@self.test',
    );
    $controller->deleteAliasAction();
    controllerAliasIdentityCheck(
        'ordinary forwarding-alias deletion retains exact decrement from ' . $count,
        $domain->getAliasCount() === $expected
            && $entityManager->removed === [$alias] && $entityManager->flushes === 1,
    );
}

[$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(
    4,
    'forward@self.test',
    'user@self.test,other@self.test',
);
$controller->deleteAliasAction();
controllerAliasIdentityCheck(
    'trimming one recipient preserves the forwarding count and remaining destination',
    $domain->getAliasCount() === 4 && $alias->getGoto() === 'other@self.test'
        && $entityManager->removed === [] && $entityManager->flushes === 1,
);

foreach ([[false, 'test-token'], [true, 'invalid-token']] as [$authorized, $csrf]) {
    [$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(
        4,
        'user@self.test',
        'user@self.test',
        'user@self.test',
        $authorized,
    );
    $savedPost = $_POST;
    $_POST['csrf'] = $csrf;
    try {
        $response = $controller->deleteAliasAction();
    } finally {
        $_POST = $savedPost;
    }
    controllerAliasIdentityCheck(
        $authorized ? 'invalid CSRF rejects self-alias deletion before mutation' : 'foreign-domain self-alias deletion stays unauthorized',
        $response->status === 302 && $domain->getAliasCount() === 4
            && $entityManager->removed === [] && $entityManager->persisted === [] && $entityManager->flushes === 0,
    );
}

foreach ([[null, 'user@self.test', 'Alias address cannot be null.'], ['user@self.test', null, 'Alias goto cannot be null.']] as [$address, $goto, $message]) {
    [$domain, $alias, $entityManager, $controller] = mailboxSelfAliasCounterFixture(4, $address, $goto);
    $observed = null;
    try {
        $controller->deleteAliasAction();
    } catch (LogicException $exception) {
        $observed = $exception->getMessage();
    }
    controllerAliasIdentityCheck(
        'malformed self-alias identity rejects before mutation: ' . $message,
        $observed === $message && $domain->getAliasCount() === 4
            && $entityManager->removed === [] && $entityManager->persisted === [] && $entityManager->flushes === 0,
    );
}
