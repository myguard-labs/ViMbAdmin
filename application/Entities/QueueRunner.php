<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * QueueRunner
 *
 * A short-lived lease row representing one ACTIVE queue runner. The runner
 * concurrency cap (queue.runner.max_concurrent) is enforced by counting the
 * non-stale rows in this table before starting a new drain. Each running drain
 * inserts a row, heartbeats it, and deletes it on exit; a row whose heartbeat
 * has gone stale (the process died) is reaped so a slot is never lost forever.
 */
class QueueRunner
{
    /** @var integer */
    private $id;

    /** @var string */
    private $host;

    /** @var integer */
    private $pid;

    /** @var \DateTime */
    private $started_at;

    /** @var \DateTime */
    private $heartbeat_at;

    public function getId()              { return $this->id; }

    public function getHost()            { return $this->host; }
    public function setHost( $v )        { $this->host = $v; return $this; }

    public function getPid()             { return $this->pid; }
    public function setPid( $v )         { $this->pid = (int) $v; return $this; }

    public function getStartedAt()       { return $this->started_at; }
    public function setStartedAt( $v )   { $this->started_at = $v; return $this; }

    public function getHeartbeatAt()     { return $this->heartbeat_at; }
    public function setHeartbeatAt( $v ) { $this->heartbeat_at = $v; return $this; }
}
