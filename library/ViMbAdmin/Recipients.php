<?php

/** Current aggregate transforms and caller-owned transaction lifetime. */
final class ViMbAdmin_Recipients
{
    /** Pure legacy transform: preserve order/duplicates, trim and remove empties. */
    public static function without(string $recipients, string $username, bool $strict = false): string
    {
        $remaining = [];
        foreach (explode(',', $recipients) as $recipient) {
            $recipient = trim($recipient);
            if ($recipient !== '' && ($strict ? $recipient !== $username : $recipient != $username)) {
                $remaining[] = $recipient;
            }
        }
        return implode(',', $remaining);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public static function atomic(\Doctrine\Persistence\ObjectManager $em, callable $operation): mixed
    {
        if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \LogicException('Recipient persistence requires Doctrine ORM EntityManager capabilities.');
        }
        $connection = $em->getConnection();
        $mariaDb = $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform;
        $connection->beginTransaction();
        $cleanupGuard = new class ($connection, $mariaDb) {
            public function __construct(private \Doctrine\DBAL\Connection $connection, private bool $mariaDb)
            {
            }
            public function onClear(\Doctrine\ORM\Event\OnClearEventArgs $event): void
            {
                if (!$this->connection->isTransactionActive()) {
                    return;
                }
                $native = $this->connection->getNativeConnection();
                $aborted = false;
                if ($native instanceof \PDO) {
                    // PDO's cached inTransaction flag remains true after some
                    // MariaDB implicit rollbacks; ask that server explicitly.
                    try {
                        if ($this->mariaDb) {
                            $probe = $native->query('SELECT @@in_transaction');
                            // DBAL's PDO wrapper enforces ERRMODE_EXCEPTION; a valid
                            // configured probe returns a statement or throws, never false.
                            $state = $probe === false ? null : $probe->fetchColumn();
                            $aborted = $state === 0 || $state === '0';
                        } else {
                            $aborted = !$native->inTransaction();
                        }
                    } catch (\PDOException) {
                        return; // Unknown server state never proves an abort.
                    }
                }
                if ($aborted) {
                    // MariaDB may abort the whole transaction after a concurrent
                    // check-read/deadlock. DBAL's nested savepoint is then gone.
                    // Reset only that confirmed aborted connection so ORM cleanup
                    // cannot replace the original error with savepoint 1305.
                    $this->connection->close();
                }
            }
        };
        $events = $em->getEventManager();
        $events->addEventListener(['onClear'], $cleanupGuard);
        try {
            $result = $operation();
            $connection->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (\Throwable $cleanupError) {
                    $connection->close();
                    error_log('Recipient rollback cleanup failed: ' . $cleanupError->getMessage());
                }
            }
            $em->clear();
            throw $error;
        } finally {
            $events->removeEventListener(['onClear'], $cleanupGuard);
        }
    }

    /**
     * Refresh current rows under lock without erasing same-version hook edits.
     * @return array<int,\Entities\Alias>
     */
    public static function currentAliases(\Doctrine\ORM\EntityManagerInterface $em, \Doctrine\ORM\Query $query): array
    {
        $metadata = $em->getClassMetadata(\Entities\Alias::class);
        $pending = [];
        foreach ($em->getUnitOfWork()->getIdentityMap()[\Entities\Alias::class] ?? [] as $alias) {
            if (!$alias instanceof \Entities\Alias) {
                // Real UnitOfWork indexes each object by its actual root class;
                // valid mapped queries cannot place another entity in this root.
                throw new \UnexpectedValueException('Alias identity map contains an invalid entity.');
            }
            $original = $em->getUnitOfWork()->getOriginalEntityData($alias);
            $fields = [];
            foreach (array_merge($metadata->getFieldNames(), $metadata->getAssociationNames()) as $field) {
                if ($field !== 'id' && $field !== 'alias_version' && array_key_exists($field, $original)
                    && $metadata->getFieldValue($alias, $field) !== $original[$field]) {
                    $fields[$field] = $metadata->getFieldValue($alias, $field);
                }
            }
            if ($fields !== []) {
                $pending[spl_object_id($alias)] = ['version' => $alias->getAliasVersion(), 'fields' => $fields];
            }
        }
        $current = \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList($query->getResult(), \Entities\Alias::class, 'Locked alias aggregate query');
        foreach ($current as $alias) {
            $changes = $pending[spl_object_id($alias)] ?? null;
            if ($changes !== null) {
                if ($changes['version'] !== $alias->getAliasVersion()) {
                    throw \Doctrine\ORM\OptimisticLockException::lockFailed($alias);
                }
                foreach ($changes['fields'] as $field => $value) {
                    $metadata->setFieldValue($alias, $field, $value);
                }
            }
        }
        return $current;
    }

    /** DELETE is not automatically guarded by Doctrine's optimistic version. */
    public static function guardDelete(\Doctrine\Persistence\ObjectManager $em, \Entities\Alias $alias): void
    {
        if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \LogicException('Recipient deletion requires Doctrine ORM EntityManager capabilities.');
        }
        $version = $alias->getAliasVersion();
        $em->refresh($alias, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        if ($alias->getAliasVersion() !== $version) {
            throw \Doctrine\ORM\OptimisticLockException::lockFailed($alias);
        }
    }
}
