<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Doctrine schema sync helper.
 *
 * Used by both the Maintenance web UI and the CLI auto-migrator (run from the
 * container bootstrap on every start). Centralises:
 *
 *   - computing the pending additive schema SQL,
 *   - applying it DDL-safely (NO surrounding transaction — MySQL/MariaDB
 *     implicitly commits on every DDL statement, so wrapping the loop in
 *     begin/commit throws "There is no active transaction" on the commit),
 *   - recording the applied DBVERSION in the database_version table so the
 *     deployed schema version is visible.
 */
class ViMbAdmin_Schema
{
    /** @var \Doctrine\ORM\EntityManager */
    private $_em;

    public function __construct( $em )
    {
        $this->_em = $em;
    }

    /**
     * Pending additive schema statements (saveMode=true → no DROP TABLE for
     * tables Doctrine doesn't manage).
     *
     * @return string[]
     */
    public function pendingSql()
    {
        $tool = new \Doctrine\ORM\Tools\SchemaTool( $this->_em );
        $meta = $this->_em->getMetadataFactory()->getAllMetadata();
        $sql  = $tool->getUpdateSchemaSql( $meta, true );

        // Drop no-op ALTERs against Dovecot-owned tables. These are read-only
        // entities (dovecot_quota / dovecot_last_login); their timestamp column
        // uses a `column-definition` override which Doctrine's schema comparator
        // can never reconcile with what MariaDB introspects (current_timestamp()
        // vs CURRENT_TIMESTAMP normalisation), so it emits an identical CHANGE
        // statement on every run -- a perpetual phantom "1 pending statement".
        // The ALTER is a no-op (column already in that exact shape), so filter
        // these tables out: ViMbAdmin must never rewrite tables Dovecot owns.
        $sql = array_values( array_filter( $sql, function( $stmt ) {
            return !preg_match( '/\bALTER\s+TABLE\s+`?dovecot_(quota|last_login)`?\b/i', $stmt );
        } ) );

        return $sql;
    }

    /**
     * Apply the given statements one by one. DDL auto-commits, so we do NOT
     * wrap this in a transaction; instead each statement runs on its own and
     * the first failure aborts with context.
     *
     * @param string[] $sql
     * @return int number of statements executed
     * @throws \Throwable on the first failing statement
     */
    public function apply( array $sql )
    {
        $conn = $this->_em->getConnection();
        $done = 0;
        foreach( $sql as $stmt )
        {
            try
            {
                $conn->executeStatement( $stmt );
                $done++;
            }
            catch( \Throwable $e )
            {
                throw new \RuntimeException(
                    sprintf( 'schema statement %d/%d failed: %s | SQL: %s',
                        $done + 1, count( $sql ), $e->getMessage(), $stmt ),
                    0, $e );
            }
        }
        return $done;
    }

    /**
     * Record the current code DBVERSION in database_version (idempotent: only
     * inserts a row when the highest recorded version is behind the code).
     *
     * @return bool true if a new version row was written
     */
    public function recordVersion()
    {
        if( !class_exists( 'ViMbAdmin_Version' ) || !defined( 'ViMbAdmin_Version::DBVERSION' ) )
            return false;

        $current = $this->currentVersion();
        if( $current !== null && (int) $current >= (int) ViMbAdmin_Version::DBVERSION )
            return false;

        $row = new \Entities\DatabaseVersion();
        $row->setVersion( ViMbAdmin_Version::DBVERSION );
        $row->setName( defined( 'ViMbAdmin_Version::DBVERSION_NAME' ) ? ViMbAdmin_Version::DBVERSION_NAME : '' );
        $row->setAppliedOn( new \DateTime() );
        $this->_em->persist( $row );
        $this->_em->flush();
        return true;
    }

    /**
     * Highest version recorded in database_version, or null if none/table absent.
     *
     * @return int|null
     */
    public function currentVersion()
    {
        try
        {
            $v = $this->_em->createQuery(
                'SELECT MAX(d.version) FROM \Entities\DatabaseVersion d' )->getSingleScalarResult();
            return $v === null ? null : (int) $v;
        }
        catch( \Throwable $e )
        {
            return null; // table may not exist yet on a fresh install
        }
    }

    /**
     * The DBVERSION the running code expects.
     *
     * @return int|null
     */
    public function codeVersion()
    {
        return ( class_exists( 'ViMbAdmin_Version' ) && defined( 'ViMbAdmin_Version::DBVERSION' ) )
            ? (int) ViMbAdmin_Version::DBVERSION : null;
    }

    /**
     * Bring the schema up to date and record the version. Returns a summary.
     *
     * @return array{applied:int,version:int|null,statements:string[]}
     */
    public function migrate()
    {
        $sql = $this->pendingSql();
        $applied = count( $sql ) ? $this->apply( $sql ) : 0;
        $this->recordVersion();
        return [
            'applied'    => $applied,
            'version'    => $this->codeVersion(),
            'statements' => $sql,
        ];
    }
}
