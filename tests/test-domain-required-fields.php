<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';

function domainRequiredFieldsWithId(int $id): \Entities\Domain
{
    $domain = new \Entities\Domain();
    $method = new ReflectionMethod($domain, 'assignGeneratedId');
    $method->invoke($domain, $id);
    return $domain;
}

/** @return string|null */
function domainRequiredFieldsError(callable $operation): ?string
{
    try {
        $operation();
    } catch (LogicException $e) {
        return $e->getMessage();
    }
    return null;
}

final class DomainRequiredFieldsState
{
    public static int $failures = 0;
}

function domainRequiredFieldsCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        DomainRequiredFieldsState::$failures++;
    }
}

echo "== required domain persistence fields ==\n";

$newDomain = new \Entities\Domain();
domainRequiredFieldsCheck(
    'pre-hydration getters preserve null',
    $newDomain->getId() === null && $newDomain->getQuota() === null
        && $newDomain->getDomain() === null
);
domainRequiredFieldsCheck(
    'required id rejects a pre-hydration domain',
    domainRequiredFieldsError($newDomain->requiredId(...)) === 'Domain id cannot be null.'
);
domainRequiredFieldsCheck(
    'required quota rejects an unset domain quota',
    domainRequiredFieldsError($newDomain->requiredQuota(...)) === 'Domain quota cannot be null.'
);
domainRequiredFieldsCheck(
    'required name rejects an unset domain name',
    domainRequiredFieldsError($newDomain->requiredDomainName(...)) === 'Domain name cannot be null.'
);

$initialized = domainRequiredFieldsWithId(17)->setQuota(2048)->setDomain('example.test');
domainRequiredFieldsCheck(
    'required fields preserve initialized values',
    $initialized->requiredId() === 17 && $initialized->requiredQuota() === 2048
        && $initialized->requiredDomainName() === 'example.test'
);

$admin = new \Entities\Admin();
$assigned = domainRequiredFieldsWithId(17);
$admin->addDomain($assigned);
domainRequiredFieldsCheck(
    'admin authorization matches persisted domain identity',
    $admin->canManageDomain(domainRequiredFieldsWithId(17))
);
domainRequiredFieldsCheck(
    'admin authorization rejects another persisted domain',
    !$admin->canManageDomain(domainRequiredFieldsWithId(18))
);

$unpersistedAdmin = new \Entities\Admin();
$unpersistedAdmin->addDomain(new \Entities\Domain());
domainRequiredFieldsCheck(
    'admin authorization rejects null ids instead of matching null to null',
    domainRequiredFieldsError(
        static fn (): bool => $unpersistedAdmin->canManageDomain(new \Entities\Domain()),
    ) === 'Domain id cannot be null.'
);

$associations = new \Entities\Domain();
$canonicalMailbox = new \Entities\Mailbox();
$compatibilityMailbox = new \Entities\Mailbox();
$canonicalAlias = new \Entities\Alias();
$compatibilityAlias = new \Entities\Alias();
domainRequiredFieldsCheck(
    'canonical association adders retain fluent collection behavior',
    $associations->addMailbox($canonicalMailbox) === $associations
        && $associations->addAlias($canonicalAlias) === $associations
        && $associations->getMailboxes()->contains($canonicalMailbox)
        && $associations->getAliases()->contains($canonicalAlias)
);
domainRequiredFieldsCheck(
    'deprecated typo aliases delegate to canonical association behavior',
    $associations->addMailboxe($compatibilityMailbox) === $associations
        && $associations->addAliase($compatibilityAlias) === $associations
        && $associations->getMailboxes()->contains($compatibilityMailbox)
        && $associations->getAliases()->contains($compatibilityAlias)
);
$associations->removeMailbox($canonicalMailbox);
$associations->removeAlias($canonicalAlias);
$associations->removeMailboxe($compatibilityMailbox);
$associations->removeAliase($compatibilityAlias);
domainRequiredFieldsCheck(
    'canonical and compatibility removers mutate the same ORM collections',
    $associations->getMailboxes()->isEmpty() && $associations->getAliases()->isEmpty()
);
$compatibilityMethodsDeprecated = true;
foreach (['addMailboxe', 'removeMailboxe', 'addAliase', 'removeAliase'] as $method) {
    $doc = (new ReflectionMethod(\Entities\Domain::class, $method))->getDocComment();
    $compatibilityMethodsDeprecated = $compatibilityMethodsDeprecated
        && is_string($doc) && str_contains($doc, '@deprecated');
}
domainRequiredFieldsCheck(
    'typo compatibility APIs remain present and explicitly deprecated',
    $compatibilityMethodsDeprecated
);

echo DomainRequiredFieldsState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . DomainRequiredFieldsState::$failures . " FAILED\n";
exit(DomainRequiredFieldsState::$failures === 0 ? 0 : 1);
