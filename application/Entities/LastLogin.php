<?php

namespace Entities;

/**
 * Entities\LastLogin
 *
 * Per-mailbox last successful login timestamp, as written by Dovecot's
 * `last_login` mail plugin into an SQL dictionary.
 *
 * Dovecot's last_login plugin mirrors the time of each user's last login into
 * a dict. We point its dict map at a DEDICATED `dovecot_last_login` table
 * (keyed by `username` = full email address) holding one value:
 *
 *   priv/last-login  ->  last_login (unix timestamp, seconds)
 *
 * A dedicated table (not `mailbox`) is required because the dict writes with
 * INSERT .. ON DUPLICATE KEY UPDATE, which would fail against mailbox's
 * NOT NULL columns that have no default.
 *
 * To keep the write load trivial, Dovecot is configured with
 * `last_login_precision = h` so it updates at most once per hour per user
 * (not on every IMAP/POP/SMTP auth).
 *
 * The mail database is the authority; ViMbAdmin only ever READS this table to
 * display the last-login time in the GUI. Dovecot replaces the row, so
 * ViMbAdmin must not write to it.
 *
 * @see https://doc.dovecot.org/main/core/plugins/last_login.html
 */
class LastLogin
{
    /** @var string  full email address (mailbox username) */
    private $username;

    /** @var integer  unix timestamp (seconds) of the last login */
    private $last_login = 0;

    public function setUsername( $username )
    {
        $this->username = $username;
        return $this;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getLastLogin()
    {
        return $this->last_login;
    }
}
