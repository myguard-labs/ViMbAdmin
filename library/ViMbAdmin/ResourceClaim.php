<?php

/**
 * One physical mail store uses one canonical key, regardless of operation or
 * Domain_id. A unique durable row grants an opaque owner token. Connection
 * loss, PID death and elapsed time never transfer ownership. READY means no
 * remote mutation is in flight; REMOTE is conservatively uncertain until the
 * same owner observes command completion. Only that token may release READY.
 * Crash, ambiguous remote failure and release failure retain the row, requiring
 * explicit operator reconciliation of the exact token and backend state.
 * Nested calls on the same ObjectManager reuse its token; independent managers
 * are independent actors. Claim writes use a separate autocommit connection,
 * so an application rollback/reconnect cannot erase the remote-work marker.
 */
final class ViMbAdmin_ResourceClaim
{
    /** @var array<string,self> */
    private static array $owners = [];
    private string $phase = 'READY';
    private bool $remoteAttempted = false;

    private function __construct(
        private \Doctrine\DBAL\Connection $connection,
        private string $key,
        private string $token,
        private \Doctrine\DBAL\Connection $primary
    ) {
    }

    public static function key(string $username): string
    {
        $canonical = ViMbAdmin_Identity::canonical($username);
        if ($canonical === '' || strlen($canonical) > 255) {
            throw new \InvalidArgumentException('Mailbox resource identity must be nonempty and at most 255 bytes.');
        }
        return hash('sha256', 'mail-store:' . $canonical);
    }

    public static function acquire(\Doctrine\DBAL\Connection $primary, string $username): self
    {
        $params = $primary->getParams();
        $options = $params['driverOptions'] ?? [];
        $options[\PDO::ATTR_PERSISTENT] = false;
        $options[\PDO::ATTR_AUTOCOMMIT] = true;
        $params['driverOptions'] = $options;
        $configuration = clone $primary->getConfiguration();
        $configuration->setAutoCommit(true);
        $connection = \Doctrine\DBAL\DriverManager::getConnection($params, $configuration);
        $key = self::key($username);
        $token = bin2hex(random_bytes(32));
        try {
            $connection->insert('resource_claim', [
                'resource_key' => $key,
                'owner_token' => $token,
                'username' => ViMbAdmin_Identity::canonical($username),
                'phase' => 'READY',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
            $connection->close();
            throw new \ViMbAdmin_Exception('Mailbox resource is already claimed.', 0, $error);
        } catch (\Throwable $error) {
            $connection->close();
            throw $error;
        }
        return new self($connection, $key, $token, $primary);
    }

    /**
     * @template T
     * @param callable(self):T $operation
     * @return T
     */
    public static function withMailbox(\Doctrine\ORM\EntityManagerInterface $em, string $username, callable $operation): mixed
    {
        $scope = spl_object_id($em) . ':' . self::key($username);
        if (isset(self::$owners[$scope])) {
            return $operation(self::$owners[$scope]);
        }
        $claim = self::acquire($em->getConnection(), $username);
        self::$owners[$scope] = $claim;
        $operationError = null;
        try {
            return $operation($claim);
        } catch (\Throwable $error) {
            $operationError = $error;
            $claim->retainOnFailure($error);
            throw $error;
        } finally {
            unset(self::$owners[$scope]);
            try {
                $claim->finish();
            } catch (\Throwable $error) {
                if ($operationError === null) {
                    throw $error;
                }
                error_log('Mailbox resource cleanup failed: ' . $error->getMessage());
            }
        }
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function remote(callable $operation): mixed
    {
        if ($this->primary->isTransactionActive()) {
            // Remote phase writes use an independent connection. An older
            // MariaDB primary snapshot cannot safely finalize that generation.
            $this->phase = 'RETAINED';
            throw new \LogicException('Acquire mailbox resource ownership before starting the caller transaction.');
        }
        if ($this->phase !== 'READY') {
            throw new \RuntimeException('Mailbox backend completion is uncertain; reconcile its retained resource claim.');
        }
        $this->remoteAttempted = true;
        $this->setPhase('REMOTE');
        try {
            $result = $operation();
        } catch (ViMbAdmin_Doveadm_CommandException $error) {
            // A parsed command response confirms remote completion, including
            // a nonzero exit. Transport/parse failures cannot make that claim.
            $this->setPhase('READY');
            throw $error;
        }
        $this->setPhase('READY');
        return $result;
    }

    private function setPhase(string $phase): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE resource_claim SET phase = ? WHERE resource_key = ? AND owner_token = ?',
            [$phase, $this->key, $this->token]
        );
        if ($affected !== 1) {
            throw new \RuntimeException('Mailbox resource ownership was lost.');
        }
        $this->phase = $phase;
    }

    public function retainOnFailure(\Throwable $error): void
    {
        if ($this->remoteAttempted && !$error instanceof ViMbAdmin_Doveadm_CommandException) {
            $this->phase = 'RETAINED';
        }
    }

    /** The exact archive snapshot must still describe this claimed store. */
    public function guardArchive(\Entities\Archive $archive): void
    {
        if (self::key($archive->requiredUsername()) !== $this->key) {
            throw new \LogicException('Archive does not belong to the claimed mailbox resource.');
        }
        $row = $this->primary->fetchAssociative(
            'SELECT archive_generation, archive_version FROM archive WHERE id = ?',
            [$archive->getId()]
        );
        $version = $archive->getArchiveVersion();
        if ($row === false || $row['archive_generation'] !== $archive->getArchiveGeneration()
            || ($row['archive_version'] !== $version && $row['archive_version'] !== (string) $version)) {
            throw new ViMbAdmin_Exception('Archive changed before this operation; reload the archive list and retry.');
        }
    }

    public function assertReady(): void
    {
        if ($this->phase !== 'READY') {
            throw new \RuntimeException('Mailbox backend completion is uncertain; reconcile its retained resource claim.');
        }
    }

    /** The queue's final publication owns the surrounding transaction. */
    public function releaseInTransaction(\Doctrine\DBAL\Connection $connection): void
    {
        if ($this->phase !== 'READY') {
            return; // An ambiguous remote failure keeps its durable claim.
        }
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Resource release requires an active publication transaction.');
        }
        // Lock the current independent claim row before DML. An outer primary
        // snapshot can precede claim insertion/phase changes; a current read
        // prevents MariaDB check-read errors on that later committed generation.
        $this->phase = 'RELEASED'; // Rollback/failure must retain, never clean up.
        $row = $connection->fetchAssociative(
            "SELECT owner_token, phase FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY' FOR UPDATE",
            [$this->key, $this->token]
        );
        if ($row === false || $row['owner_token'] !== $this->token || $row['phase'] !== 'READY') {
            throw new \RuntimeException('Mailbox resource release requires its current ready token.');
        }
        if ($connection->executeStatement(
            "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
            [$this->key, $this->token]
        ) !== 1) {
            throw new \RuntimeException('Mailbox resource release requires its current ready token.');
        }
    }

    /** Confirmed completion/no-start releases; uncertain remote work is retained. */
    public function finish(): void
    {
        try {
            if ($this->phase === 'READY') {
                if ($this->primary->isTransactionActive()) {
                    $this->releaseInTransaction($this->primary);
                } else {
                    $this->release();
                }
            }
        } finally {
            $this->close();
        }
    }

    /** Close never releases: crash/rollback recovery is explicitly conservative. */
    public function close(): void
    {
        $this->connection->close();
    }

    public function release(): void
    {
        $this->phase = 'RELEASING'; // A failed release is never retried by finally.
        $affected = $this->connection->executeStatement(
            "DELETE FROM resource_claim WHERE resource_key = ? AND owner_token = ? AND phase = 'READY'",
            [$this->key, $this->token]
        );
        if ($affected !== 1) {
            throw new \RuntimeException('Mailbox resource release requires its current ready token.');
        }
        $this->phase = 'RELEASED';
    }
}
