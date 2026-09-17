<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Repositories/Alias.php';
require_once __DIR__ . '/../application/Repositories/Archive.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';
require_once __DIR__ . '/../application/Repositories/Log.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

final class RepositoryResultShapeState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function repositoryResultShapeCheck(string $label, bool $condition): void
{
    RepositoryResultShapeState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        RepositoryResultShapeState::$failures++;
    }
}

/** @return string|null */
function repositoryResultShapeFailure(ReflectionMethod $method, mixed $rows): ?string
{
    try {
        $method->invoke(null, $rows);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
}

echo "== Doctrine repository result shapes ==\n";

$alias = new ReflectionMethod(\Repositories\Alias::class, 'requiredAliasListRows');
$archive = new ReflectionMethod(\Repositories\Archive::class, 'requiredArchiveListRows');
$domain = new ReflectionMethod(\Repositories\Domain::class, 'requiredDomainListRows');
$log = new ReflectionMethod(\Repositories\Log::class, 'requiredLogListRows');
$mailbox = new ReflectionMethod(\Repositories\Mailbox::class, 'requiredMailboxHydrationRows');
$username = new ReflectionMethod(\Repositories\Mailbox::class, 'requiredUsernameRows');

$aliasRows = [[
    'id' => '17', 'address' => 'alias@example.test', 'goto' => 'user@example.test',
    'active' => false, 'domain' => 'example.test',
]];
repositoryResultShapeCheck('Alias valid row is preserved exactly', $alias->invoke(null, $aliasRows) === $aliasRows);
repositoryResultShapeCheck(
    'Alias rejects a scalar result',
    repositoryResultShapeFailure($alias, 'invalid') === 'Alias query result must be an array.'
);
repositoryResultShapeCheck(
    'Alias rejects a missing field',
    repositoryResultShapeFailure($alias, [['id' => 1]]) === 'Alias query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Alias rejects a wrong field type',
    repositoryResultShapeFailure($alias, [array_replace($aliasRows[0], ['active' => 1])]) === 'Alias query row has an invalid shape.'
);

$archiveRows = [[
    'id' => null, 'username' => false, 'status' => 4, 'archived_at' => new stdClass(),
    'autoprune' => [], 'maildir_size' => '0', 'domain' => null, 'user_exists' => true,
]];
repositoryResultShapeCheck('Archive declared mixed values are preserved exactly', $archive->invoke(null, $archiveRows) === $archiveRows);
repositoryResultShapeCheck(
    'Archive rejects a scalar row',
    repositoryResultShapeFailure($archive, ['invalid']) === 'Archive query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Archive rejects a missing field',
    repositoryResultShapeFailure($archive, [['id' => 1]]) === 'Archive query row has an invalid shape.'
);

$domainRows = [['name' => 'example.test', 'active' => true]];
repositoryResultShapeCheck('Domain string-keyed row is preserved exactly', $domain->invoke(null, $domainRows) === $domainRows);
repositoryResultShapeCheck(
    'Domain rejects a scalar result',
    repositoryResultShapeFailure($domain, null) === 'Domain query result must be an array.'
);
repositoryResultShapeCheck(
    'Domain rejects a non-string row field',
    repositoryResultShapeFailure($domain, [[0 => 'invalid']]) === 'Domain query row has an invalid field.'
);

$logRow = [
    'id' => null, 'action' => false, 'data' => ['preserved'], 'timestamp' => new stdClass(),
    'admin' => 7, 'domain' => null,
];
$logRows = [3 => $logRow];
repositoryResultShapeCheck(
    'Log declared mixed values and integer key are preserved exactly',
    $log->invoke(null, $logRows) === $logRows
);
repositoryResultShapeCheck(
    'Log rejects a scalar result',
    repositoryResultShapeFailure($log, 'invalid') === 'Log query result must be an array.'
);
repositoryResultShapeCheck(
    'Log rejects a scalar row',
    repositoryResultShapeFailure($log, ['invalid']) === 'Log query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Log rejects a string-keyed row',
    repositoryResultShapeFailure($log, ['row' => $logRow]) === 'Log query row has an invalid shape.'
);
foreach (array_keys($logRow) as $field) {
    $missing = $logRow;
    unset($missing[$field]);
    repositoryResultShapeCheck(
        'Log rejects a missing ' . $field . ' field',
        repositoryResultShapeFailure($log, [$missing]) === 'Log query row has an invalid shape.'
    );
}

$mailboxRows = [[
    'id' => 7, 'username' => 'user@example.test', 'name' => null, 'active' => true,
    'quota' => '1024', 'domain' => 'example.test', 'delete_pending' => null,
]];
repositoryResultShapeCheck('Mailbox valid hydration row is preserved exactly', $mailbox->invoke(null, $mailboxRows) === $mailboxRows);
repositoryResultShapeCheck(
    'Mailbox rejects a scalar row',
    repositoryResultShapeFailure($mailbox, ['invalid']) === 'Mailbox query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox rejects a missing field',
    repositoryResultShapeFailure($mailbox, [['id' => 7]]) === 'Mailbox query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox rejects a wrong field type',
    repositoryResultShapeFailure($mailbox, [array_replace($mailboxRows[0], ['quota' => false])]) === 'Mailbox query row has an invalid shape.'
);

$usernameRows = [4 => ['id' => 7, 'username' => 'user@example.test']];
repositoryResultShapeCheck(
    'Mailbox username row preserves its integer key and values',
    $username->invoke(null, $usernameRows) === $usernameRows
);
$bigIdRows = [['id' => '9223372036854775808', 'username' => 'big@example.test']];
repositoryResultShapeCheck(
    'Mailbox username preserves an oversized DBAL bigint string',
    $username->invoke(null, $bigIdRows) === $bigIdRows
);
repositoryResultShapeCheck(
    'Mailbox username rejects a scalar result',
    repositoryResultShapeFailure($username, 'invalid') === 'Mailbox username query result must be an array.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a scalar row',
    repositoryResultShapeFailure($username, ['invalid']) === 'Mailbox username query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a string-keyed row',
    repositoryResultShapeFailure($username, ['row' => $usernameRows[4]]) === 'Mailbox username query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a missing id',
    repositoryResultShapeFailure($username, [['username' => 'user@example.test']]) === 'Mailbox username query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a missing username',
    repositoryResultShapeFailure($username, [['id' => 7]]) === 'Mailbox username query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a wrong id type',
    repositoryResultShapeFailure($username, [['id' => 'not-an-id', 'username' => 'user@example.test']]) === 'Mailbox username query row has an invalid shape.'
);
repositoryResultShapeCheck(
    'Mailbox username rejects a wrong username type',
    repositoryResultShapeFailure($username, [['id' => 7, 'username' => null]]) === 'Mailbox username query row has an invalid shape.'
);

repositoryResultShapeCheck('fixed assertion count', RepositoryResultShapeState::$checks === 33);

echo RepositoryResultShapeState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . RepositoryResultShapeState::$failures . " assertion(s) failed\n";
exit(RepositoryResultShapeState::$failures === 0 ? 0 : 1);
