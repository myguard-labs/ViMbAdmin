<?php

namespace Repositories;

use Doctrine\ORM\EntityRepository;

/**
 * McpToken repository.
 */
class McpToken extends EntityRepository
{
    /**
     * Look a token up by the SHA-256 hash of the presented bearer.
     *
     * @param string $hash hex sha256
     * @return \Entities\McpToken|null
     */
    public function findByHash( $hash )
    {
        return $this->findOneBy( [ 'token_hash' => $hash ] );
    }

    /**
     * @return \Entities\McpToken|null
     */
    public function findByName( $name )
    {
        return $this->findOneBy( [ 'name' => $name ] );
    }
}
