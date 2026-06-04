<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entities\Quota
 *
 * Live mailbox quota usage as written by Dovecot's quota-clone plugin.
 *
 * Dovecot 2.4's quota-clone plugin mirrors each user's current usage into an
 * SQL dictionary. With the default dict_map this is a `quota` table keyed by
 * `username` holding two values:
 *
 *   priv/quota/storage   -> bytes    (storage usage in bytes)
 *   priv/quota/messages  -> messages (message count)
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
    private $username;

    /**
     * @var integer $bytes
     */
    private $bytes = 0;

    /**
     * @var integer $messages
     */
    private $messages = 0;

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
}
