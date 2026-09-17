<?php

namespace Repositories;

use Doctrine\ORM\EntityRepository;

/**
 * McpToken repository.
 *
 * @extends EntityRepository<\Entities\McpToken>
 */
class McpToken extends EntityRepository
{
    /**
     * Look a token up by the SHA-256 hash of the presented bearer.
     *
     * @param string $hash hex sha256
     */
    public function findByHash(string $hash): ?\Entities\McpToken
    {
        return $this->findOneBy([ 'token_hash' => $hash ]);
    }

    public function findByName(string $name): ?\Entities\McpToken
    {
        return $this->findOneBy([ 'name' => $name ]);
    }
}
