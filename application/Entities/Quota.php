<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Quota
 *
 * Live mailbox quota usage as written by Dovecot's quota-clone plugin.
 *
 * Dovecot 2.4's quota-clone plugin mirrors each user's current usage into an
 * SQL dictionary. We point its dict_map at a DEDICATED `dovecot_quota` table
 * (keyed by `username` = full email address) holding two values:
 *
 *   priv/quota/storage   -> bytes    (storage usage in bytes)
 *   priv/quota/messages  -> messages (message count)
 *
 * A dedicated table (not the `mailbox` table) is required because quota-clone
 * writes with INSERT .. ON DUPLICATE KEY UPDATE, which fails against mailbox's
 * NOT NULL columns (password / quota / local_part have no default).
 *
 * The mail database is the authority; ViMbAdmin only ever READS this table to
 * display live usage in the GUI. Dovecot replaces (never increments) the row
 * on every change, so ViMbAdmin must not write to it.
 *
 * @see https://doc.dovecot.org/2.4.4/core/plugins/quota_clone.html
 */
class Quota
{
    /**
     * @var string $username
     */
    private ?int $username = null;

    /**
     * @var integer $bytes
     */
    private int $bytes = 0;

    /**
     * @var integer $messages
     */
    private int $messages = 0;

    /**
     * @var \DateTime $updated_at
     */
    private ?\DateTime $updated_at = null;

    /**
     * Set username
     *
     * @param string $username
     * @return Quota
     */
    public function setUsername( $username )
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get username
     *
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set bytes (storage usage in bytes)
     *
     * @param integer $bytes
     * @return Quota
     */
    public function setBytes( $bytes )
    {
        $this->bytes = $bytes;

        return $this;
    }

    /**
     * Get bytes (storage usage in bytes)
     *
     * @return integer
     */
    public function getBytes()
    {
        return $this->bytes;
    }

    /**
     * Set messages (message count)
     *
     * @param integer $messages
     * @return Quota
     */
    public function setMessages( $messages )
    {
        $this->messages = $messages;

        return $this;
    }

    /**
     * Get messages (message count)
     *
     * @return integer
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Get updated_at (when Dovecot last wrote this row)
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updated_at;
    }
}
