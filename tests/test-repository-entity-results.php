<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/RememberMe.php';

use ViMbAdmin\Kernel\Doctrine\ResultValidator;

final class RepositoryEntityResultState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

final class RepositoryEntityResultAliasProxy extends \Entities\Alias
{
}

final class RepositoryEntityResultRememberMeProxy extends \Entities\RememberMe
{
}

function repositoryEntityResultCheck(string $label, bool $condition): void
{
    RepositoryEntityResultState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        RepositoryEntityResultState::$failures++;
    }
}

/** @return string|null */
function repositoryEntityResultFailure(callable $operation): ?string
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
}

echo "== Doctrine entity results ==\n";

$first = new \Entities\Alias();
$second = new \Entities\Alias();
$aliases = [4 => $first, 9 => $second];
repositoryEntityResultCheck(
    'valid entity list preserves keys, order and identity',
    ResultValidator::entityList($aliases, \Entities\Alias::class, 'Alias test') === $aliases
);
repositoryEntityResultCheck(
    'empty entity list is preserved',
    ResultValidator::entityList([], \Entities\Alias::class, 'Alias test') === []
);
$aliasProxy = new RepositoryEntityResultAliasProxy();
repositoryEntityResultCheck(
    'entity list accepts a proxy subclass',
    ResultValidator::entityList([$aliasProxy], \Entities\Alias::class, 'Alias test') === [$aliasProxy]
);
repositoryEntityResultCheck(
    'scalar outer result is rejected',
    repositoryEntityResultFailure(
        static fn (): array => ResultValidator::entityList('invalid', \Entities\Alias::class, 'Alias test'),
    ) === 'Alias test must return an entity array.'
);
repositoryEntityResultCheck(
    'wrong entity is rejected',
    repositoryEntityResultFailure(
        static fn (): array => ResultValidator::entityList([new stdClass()], \Entities\Alias::class, 'Alias test'),
    ) === 'Alias test returned an invalid entity.'
);
repositoryEntityResultCheck(
    'mixed entity list is rejected without returning a prefix',
    repositoryEntityResultFailure(
        static fn (): array => ResultValidator::entityList([$first, new stdClass()], \Entities\Alias::class, 'Alias test'),
    ) === 'Alias test returned an invalid entity.'
);
repositoryEntityResultCheck(
    'string-keyed entity list is rejected',
    repositoryEntityResultFailure(
        static fn (): array => ResultValidator::entityList(['first' => $first], \Entities\Alias::class, 'Alias test'),
    ) === 'Alias test returned an invalid entity.'
);

$rememberMe = new \Entities\RememberMe();
repositoryEntityResultCheck(
    'nullable lookup preserves an entity identity',
    ResultValidator::nullableEntity($rememberMe, \Entities\RememberMe::class, 'Remember test') === $rememberMe
);
$rememberMeProxy = new RepositoryEntityResultRememberMeProxy();
repositoryEntityResultCheck(
    'nullable lookup accepts a proxy subclass',
    ResultValidator::nullableEntity($rememberMeProxy, \Entities\RememberMe::class, 'Remember test') === $rememberMeProxy
);
repositoryEntityResultCheck(
    'nullable lookup preserves no-result null',
    ResultValidator::nullableEntity(null, \Entities\RememberMe::class, 'Remember test') === null
);
repositoryEntityResultCheck(
    'nullable lookup rejects a wrong entity',
    repositoryEntityResultFailure(
        static fn (): ?object => ResultValidator::nullableEntity(new stdClass(), \Entities\RememberMe::class, 'Remember test'),
    ) === 'Remember test returned an invalid entity.'
);
repositoryEntityResultCheck('fixed assertion count', RepositoryEntityResultState::$checks === 11);

echo RepositoryEntityResultState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . RepositoryEntityResultState::$failures . " assertion(s) failed\n";
exit(RepositoryEntityResultState::$failures === 0 ? 0 : 1);
