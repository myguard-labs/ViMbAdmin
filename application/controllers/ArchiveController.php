<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 - 2014 Open Source Solutions Limited
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
 * @copyright Copyright (c) 2011 - 2014 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Open Source Solutions Limited <info _at_ opensolutions.ie>
 * @author Barry O'Donovan <barry _at_ opensolutions.ie>
 * @author Roland Huszti <roland _at_ opensolutions.ie>
 */

/**
 * The mailbox controller.
 *
 * @package ViMbAdmin
 * @subpackage Controllers
 */
class ArchiveController extends ViMbAdmin_Controller_PluginAction
{
    /**
     * Most actions in this object will require a domain object to edit / act on.
     *
     * This method will look for an 'id' parameter and, if set, will
     * try to load the domain model and authorise the user to edit / act on
     * it.
     *
     * @see Zend_Controller_Action::preDispatch()
     */
    public function preDispatch()
    {
        if( !$this->getDomain() )
            $this->authorise();

        if( $this->getRequest()->getActionName() == "list" || $this->getRequest()->getActionName() == "index" )
        {
            if( $this->getParam( 'unset', false ) )
                unset( $this->getSessionNamespace()->domain );
            else
            {
                if( isset( $this->getSessionNamespace()->domain ) && $this->getSessionNamespace()->domain )
                    $this->_domain = $this->getSessionNamespace()->domain;
                else if( $this->getDomain() )
                    $this->getSessionNamespace()->domain = $this->getDomain();
            }
        }
    }


    /**
     * Jumps to list action.
     */
    public function indexAction()
    {
        $this->forward( 'list' );
    }

    /**
     * Lists all archives available to the admin (superadmin sees all) or to the specified domain.
     */
    public function listAction()
    {
        $this->view->archives = $this->getD2EM()->getRepository( "\\Entities\\Archive" )->loadForArchiveList( $this->getAdmin(), $this->getDomain() );
        $this->view->statuses = \Entities\Archive::$ARCHIVE_STATUS;
        $this->view->allowDelete = [ \Entities\Archive::STATUS_ARCHIVED ];
        $this->view->allowRestore = [ \Entities\Archive::STATUS_ARCHIVED ];
    }

    public function deleteAction()
    {
        $this->_assertCsrf();
        $archive = $this->getArchive();
        $user    = $archive->getUsername();
        $dest    = $archive->getMaildirFile();   // maildir:/backups/dom/user

        try
        {
            if( $dest )
                ViMbAdmin_Doveadm::fromOptions( $this->_options )->fsDelete( $dest );
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( "ArchiveController::deleteAction fsDelete {$user}: " . $e->getMessage() );
            $this->addMessage( sprintf( "Could not remove the backup files for %s: %s", $user, $e->getMessage() ), OSS_Message::ERROR );
            $this->redirect( 'archive/list' );
        }

        $this->getD2EM()->remove( $archive );
        $this->getD2EM()->flush();

        $this->log( \Entities\Log::ACTION_ARCHIVE_REQUEST,
            "{$this->getAdmin()->getFormattedName()} deleted archive backup for {$user}" );
        $this->addMessage( sprintf( "Archive backup for %s deleted.", $user ), OSS_Message::SUCCESS );
        $this->redirect( 'archive/list' );
    }

    /**
     * Restore an archive back into a live mailbox:
     *   1. if the account no longer exists (it was DELETE'd, not just ARCHIVE'd),
     *      recreate the mailbox row from the snapshot in archive.data (incl the
     *      original password hash, so the user keeps their password);
     *   2. doveadm sync the backed-up mail from /backups back into the store;
     *   3. remove the /backups maildir + the archive row.
     * Immediate — no PENDING state, no cron.
     */
    public function restoreAction()
    {
        $this->_assertCsrf();
        $archive = $this->getArchive();
        $em      = $this->getD2EM();
        $user    = $archive->getUsername();
        $dest    = $archive->getMaildirFile();

        if( $archive->getStatus() != \Entities\Archive::STATUS_ARCHIVED )
        {
            $this->addMessage( "Restore can only be performed on an archived backup.", OSS_Message::INFO );
            $this->redirect( 'archive/list' );
        }

        // 1) recreate the mailbox if it's gone (DELETE'd account).
        $mailbox = $em->getRepository( '\\Entities\\Mailbox' )->findOneBy( [ 'username' => $user ] );
        if( !$mailbox )
        {
            $snap = json_decode( (string) $archive->getData(), true );
            $m    = ( is_array( $snap ) && isset( $snap['mailbox'] ) ) ? $snap['mailbox'] : null;
            if( !$m )
            {
                $this->addMessage( sprintf( "Cannot restore %s: no mailbox snapshot stored with the archive.", $user ), OSS_Message::ERROR );
                $this->redirect( 'archive/list' );
            }

            $mailbox = new \Entities\Mailbox();
            $mailbox->setUsername( $m['username'] )
                    ->setLocalPart( $m['local_part'] )
                    ->setName( $m['name'] )
                    ->setPassword( $m['password'] )   // original hash — password preserved
                    ->setQuota( $m['quota'] )
                    ->setHomedir( $m['homedir'] )
                    ->setMaildir( $m['maildir'] )
                    ->setUid( $m['uid'] )
                    ->setGid( $m['gid'] )
                    ->setActive( $m['active'] )
                    ->setDomain( $archive->getDomain() )
                    ->setCreated( new \DateTime() );
            $archive->getDomain()->increaseMailboxCount();
            $em->persist( $mailbox );
            $em->flush();   // userdb must see the account before doveadm sync
        }

        // 2) sync the backup back into the live store.
        try
        {
            if( $dest )
                ViMbAdmin_Doveadm::fromOptions( $this->_options )->restoreFrom( $user, $dest );
        }
        catch( \Throwable $e )
        {
            $this->getLogger()->err( "ArchiveController::restoreAction sync {$user}: " . $e->getMessage() );
            $this->addMessage( sprintf( "Mailbox %s was recreated, but restoring its mail failed: %s", $user, $e->getMessage() ), OSS_Message::ERROR );
            $this->redirect( 'archive/list' );
        }

        // 3) remove the backup files + the archive row (the mail now lives in the
        //    mailbox again).
        try
        {
            if( $dest )
                ViMbAdmin_Doveadm::fromOptions( $this->_options )->fsDelete( $dest );
        }
        catch( \Throwable $e )
        {
            // mail is restored; a leftover backup dir is non-fatal.
            $this->getLogger()->err( "ArchiveController::restoreAction fsDelete {$user}: " . $e->getMessage() );
        }

        $em->remove( $archive );
        $em->flush();

        $this->log( \Entities\Log::ACTION_ARCHIVE_RESTORE,
            "{$this->getAdmin()->getFormattedName()} restored archive for {$user}" );
        $this->addMessage( sprintf( "Archive for %s restored into the live mailbox.", $user ), OSS_Message::SUCCESS );
        $this->redirect( 'archive/list' );
    }

    /**
     * Toggle autoprune on/off for an archive. Turning it ON also (re)starts the
     * prune window by setting archived_at = now, so a freshly-enabled backup
     * gets the full queue.autoprune.days before it can expire. Turning it OFF
     * just clears the flag (the archived date is left as-is). The prune itself
     * runs from the Maintenance tab.
     */
    public function toggleAutopruneAction()
    {
        $this->_assertCsrf();
        $archive = $this->getArchive();
        $now     = new \DateTime();

        if( $archive->getAutoprune() )
        {
            // ON -> OFF
            $archive->setAutoprune( false )
                    ->setStatusChangedAt( $now );
            $this->getD2EM()->flush();

            $this->log(
                \Entities\Log::ACTION_ARCHIVE_REQUEST,
                "{$this->getAdmin()->getFormattedName()} disabled autoprune for archive {$archive->getUsername()}"
            );
            $this->addMessage(
                sprintf( "Autoprune disabled for %s.", $archive->getUsername() ),
                OSS_Message::SUCCESS );
        }
        else
        {
            // OFF -> ON (restart the prune window)
            $archive->setAutoprune( true )
                    ->setArchivedAt( $now )
                    ->setStatusChangedAt( $now );
            $this->getD2EM()->flush();

            $this->log(
                \Entities\Log::ACTION_ARCHIVE_REQUEST,
                "{$this->getAdmin()->getFormattedName()} enabled autoprune for archive {$archive->getUsername()} (window reset to now)"
            );
            $this->addMessage(
                sprintf( "Autoprune enabled for %s; the prune window restarts from now.", $archive->getUsername() ),
                OSS_Message::SUCCESS );
        }

        $this->redirect( 'archive/list' );
    }

}
