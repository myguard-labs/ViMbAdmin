<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Open Source Solutions Limited T/A Open Solutions
 *   147 Stepaside Park, Stepaside, Dublin 18, Ireland.
 *   Barry O'Donovan <barry _at_ opensolutions.ie>
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Open Source Solutions Limited <info _at_ opensolutions.ie>
 * @author Barry O'Donovan <barry _at_ opensolutions.ie>
 * @author Roland Huszti <roland _at_ opensolutions.ie>
 */

/**
 * Class to store and retrieve the version of ViMbAdmin.
 *
 * @package    ViMbAdmin
 * @subpackage Library
 * @copyright  Copyright (c) 2011 Open Source Solutions Limited
 * @license    http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */
final class ViMbAdmin_Version
{
    /**
     * Version identification - see compareVersion().
     *
     * This is the single source of truth for the application version: the
     * footer and the Maintenance tab both read VERSION directly. Release
     * workflow:
     *   1. bump VERSION here (e.g. 4.0.0-rc1 -> 4.0.0-rc2 -> 4.0.0),
     *      and MILESTONE on a minor/major change,
     *   2. tag the commit: `git tag v4.0.0-rc1 && git push --tags`.
     * DBVERSION is independent — bump it ONLY when the schema (entity mappings)
     * changes, not on every release.
     */
    const VERSION = '4.0.0-rc1';

    /**
     * Upstream GitHub repository (owner/repo) for the Maintenance update checks.
     */
    const GITHUB_REPO = 'eilandert/ViMbAdmin';

    /** Default branch the running code is built from (commits-behind check). */
    const GITHUB_BRANCH = 'master';

    /**
     * Version milestone
     *
     * The version milestone is used to publicly identify the running version
     * and should therefore not include the patch level.
     */
    const MILESTONE = '4.0';

    /**
     * Database schema version
     */
    const DBVERSION = 4;

    /**
     * Database schema version name
     *
     * v3 — single CONSOLIDATED fork schema step above upstream's v2 ("Earth").
     * The fork tracks one schema version, not a per-feature migration chain:
     * Doctrine SchemaTool + ViMbAdmin_Schema::extraSql() apply the whole diff in
     * one pass on a fresh DB, so intermediate version numbers buy nothing. This
     * v3 covers everything the fork adds on top of upstream:
     *   - mailbox-task queue (mailbox_task) + admin last_login;
     *   - MCP token table (mcp_token) incl. allowed_domains;
     *   - Dovecot quota-clone (dovecot_quota) + last-login (dovecot_last_login)
     *     dict tables;
     *   - ON DELETE CASCADE FKs username → mailbox(username) on those two
     *     Dovecot-owned tables (+ dovecot_quota.username collation alignment),
     *     added by extraSql() because they can't be expressed as a Doctrine
     *     association (Mailbox PK is `id`, not `username`);
     *   - archive.autoprune column (queue ARCHIVE/DELETE write archive rows;
     *     DELETE flags autoprune so the backup is pruned after
     *     queue.autoprune.days — application.ini).
     * Standalone SQL mirror: contrib/migrations/2026-06-fork-schema.sql.
     */
    const DBVERSION_NAME = 'ViMbAdmin fork schema (queue, MCP, Dovecot dicts, cascade FKs, archive autoprune, setting KV)';

    /**
     * The latest stable version Zend Framework available
     *
     * @var string
     */
    protected static $_lastestVersion = null;

    /**
     * Compare the specified version string $version
     * with the current ViMbAdmin_Version::VERSION.
     *
     * @param  string  $version  A version string (e.g. "0.7.1").
     * @return int           -1 if the $version is older,
     *                           0 if they are the same,
     *                           and +1 if $version is newer.
     *
     */
    public static function compareVersion( $version )
    {
        return version_compare( $version, self::VERSION );
    }

    /**
     * The git commit the running image was built from.
     *
     * The Docker build writes the short+long SHA to var/GIT_COMMIT just before
     * stripping .git (the image ships no .git, so this is the only source of
     * truth at runtime). Returns the 40-char SHA, or null if the marker is
     * absent (e.g. running straight from a working tree in dev).
     *
     * @return string|null
     */
    public static function gitCommit()
    {
        // Dev fallback: a real .git in the tree (the image strips it).
        $root = dirname( dirname( __DIR__ ) );          // .../ (app root)
        // NOTE: the marker lives at the app ROOT, NOT under var/ -- var/ is a
        // writable volume at runtime and would shadow a baked-in file.
        $marker = $root . '/GIT_COMMIT';
        if( is_readable( $marker ) )
        {
            $sha = trim( (string) file_get_contents( $marker ) );
            if( preg_match( '/^[0-9a-f]{7,40}$/i', $sha ) )
                return $sha;
        }
        $head = $root . '/.git/HEAD';
        if( is_readable( $head ) )
        {
            $ref = trim( (string) file_get_contents( $head ) );
            if( strpos( $ref, 'ref:' ) === 0 )
            {
                $path = $root . '/.git/' . trim( substr( $ref, 4 ) );
                if( is_readable( $path ) )
                    return trim( (string) file_get_contents( $path ) );
            }
            elseif( preg_match( '/^[0-9a-f]{40}$/i', $ref ) )
                return $ref;
        }
        return null;
    }

    /** Short (12-char) form of the build commit, or null. */
    public static function gitCommitShort()
    {
        $c = self::gitCommit();
        return $c ? substr( $c, 0, 12 ) : null;
    }

    /**
     * GitHub REST helper. Returns the decoded JSON body, or null on any error
     * (no network, rate limit, bad status). Fail-soft: the update check is a
     * convenience, never load-bearing.
     *
     * @param string $path  e.g. "releases/latest"
     * @return array|null
     */
    private static function _github( $path )
    {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/' . $path;
        $ctx = stream_context_create( [ 'http' => [
            'method'        => 'GET',
            'timeout'       => 6,
            'ignore_errors' => true,
            'header'        => "Accept: application/vnd.github+json\r\n"
                             . 'User-Agent: ViMbAdmin/' . self::VERSION . "\r\n",
        ] ] );
        $body = @file_get_contents( $url, false, $ctx );
        if( $body === false )
            return null;
        $json = json_decode( $body, true );
        return is_array( $json ) ? $json : null;
    }

    /**
     * Latest release tag on GitHub (e.g. "v4.0.0"), or null. Falls back to the
     * newest tag if the repo has no formal "release".
     *
     * @return string|null
     */
    public static function latestRelease()
    {
        $rel = self::_github( 'releases/latest' );
        if( is_array( $rel ) && !empty( $rel['tag_name'] ) )
            return (string) $rel['tag_name'];

        $tags = self::_github( 'tags' );
        if( is_array( $tags ) && isset( $tags[0]['name'] ) )
            return (string) $tags[0]['name'];

        return null;
    }

    /**
     * HEAD commit SHA of the default branch on GitHub, or null.
     *
     * @return string|null
     */
    public static function latestCommit()
    {
        $c = self::_github( 'commits/' . self::GITHUB_BRANCH );
        if( is_array( $c ) && !empty( $c['sha'] ) )
            return (string) $c['sha'];
        return null;
    }

    /**
     * Is a newer RELEASE available than VERSION? Returns the newer tag string,
     * false if up to date, or null if the check couldn't run.
     *
     * @return string|false|null
     */
    public static function releaseUpdateAvailable()
    {
        $tag = self::latestRelease();
        if( $tag === null )
            return null;
        $remote = ltrim( $tag, 'vV' );
        return version_compare( $remote, self::VERSION, '>' ) ? $tag : false;
    }

    /**
     * Are there newer COMMITS than the one this image was built from? "If
     * there is another commit it must be a higher version." Returns the remote
     * short SHA if it differs from ours, false if identical, or null if the
     * check couldn't run (no network, or no baked commit to compare).
     *
     * @return string|false|null
     */
    public static function commitUpdateAvailable()
    {
        $local = self::gitCommit();
        if( $local === null )
            return null;
        $remote = self::latestCommit();
        if( $remote === null )
            return null;
        return ( strcasecmp( $local, $remote ) !== 0 ) ? substr( $remote, 0, 12 ) : false;
    }
}
