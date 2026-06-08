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
 * The AdditionalInfo plugin
 *
 * @package ViMbAdmin
 * @subpackage Plugins
 */
class ViMbAdminPlugin_DirectoryEntry extends ViMbAdmin_Plugin implements OSS_Plugin_Observer, ViMbAdmin_Plugin_MailboxFormExtension
{
    /**
     * The directory-entry attributes, in form order, with their input type. The
     * field name is `plugin_directoryEntry_<Attr>` and the entity accessor is
     * `get<Attr>`/`set<Attr>` (the same convention the ZF1 subform's
     * prepare()/formToEntity() use). Source of truth for the native form section.
     *
     * @var array<string,string> attribute => input type
     */
    private const DE_ATTRS = [
        'PersonalTitle' => 'text', 'GivenName' => 'text', 'Sn' => 'text',
        'DisplayName' => 'text', 'Initials' => 'text', 'BusinessCategory' => 'text',
        'EmployeeType' => 'text', 'Title' => 'text', 'DepartmentNumber' => 'text',
        'Ou' => 'text', 'RoomNumber' => 'text', 'O' => 'text', 'CarLicense' => 'text',
        'EmployeeNumber' => 'text', 'Manager' => 'text', 'Secretary' => 'text',
        'Mail' => 'text', 'HomePhone' => 'text', 'Mobile' => 'text', 'Pager' => 'text',
        'TelephoneNumber' => 'text', 'FacsimileTelephoneNumber' => 'text',
        'HomePostalAddress' => 'textarea', 'LabeledURI' => 'textarea',
        'JpegPhoto' => 'text', 'PreferredLanguage' => 'text',
    ];


    public function __construct( object $controller )
    {
        parent::__construct( $controller, get_class( $this ) );
        
        // no setup tasks are required
        //
        // typically you might load an config file for example, but as this is a system
        // plugin, we can use the main application.ini for that.
    }
    /**
     * Deletes the directory entry with its mailbox.
     *
     * @param object $controller an OSS_Controller_Action instance
     * @return void
     * @access public
     */
    public function mailbox_purge_preFlush( $controller, $params )
    {
        $mailbox = $controller->getMailbox();
        
        if( $de = $mailbox->getDirectoryEntry() )
        {
            $controller->getD2EM()->remove( $de );
            $controller->getD2EM()->flush();
        }
    }            

    // -- Native mailbox-form extension ---------------------------------------

    /**
     * Which attributes are disabled via vimbadmin_plugins.DirectoryEntry.disabled_elements.
     *
     * @return array<string,bool>
     */
    private function _disabled( array $options ): array
    {
        return $options['vimbadmin_plugins']['DirectoryEntry']['disabled_elements'] ?? [];
    }

    public function nativeMailboxFields( ?\Entities\Mailbox $mailbox, array $options ): array
    {
        $disabled = $this->_disabled( $options );
        $dentry   = $mailbox !== null ? $mailbox->getDirectoryEntry() : null;
        $orgname  = $options['identity']['orgname'] ?? null;

        $fields = [];
        foreach( self::DE_ATTRS as $attr => $type )
        {
            // DisplayName/Initials are hidden (not removed) when disabled in ZF1;
            // every other disabled attribute is dropped from the form entirely.
            if( !empty( $disabled[ $attr ] ) && !in_array( $attr, [ 'DisplayName', 'Initials' ], true ) )
                continue;

            $field = new \ViMbAdmin\Kernel\Form\Field( "plugin_directoryEntry_{$attr}", _( $attr ), $type );

            $getFn = 'get' . $attr;
            if( $dentry !== null && $dentry->$getFn() !== null )
                $field->setValue( (string) $dentry->$getFn() );
            elseif( $attr === 'O' && $orgname )
                $field->setValue( (string) $orgname );

            $fields[] = $field;
        }

        return $fields;
    }

    public function nativeMailboxValidate( array $values, array $options ): ?string
    {
        // The directory-entry attributes are free-form text; no cross-field rule.
        return null;
    }

    public function nativeMailboxApply( \Entities\Mailbox $mailbox, array $values, array $options, ?object $em = null ): void
    {
        // The DirectoryEntry is the inverse side of the relation, so a NEW one must
        // be persisted explicitly (it is not cascade-persisted via the mailbox).
        $dentry = $mailbox->getDirectoryEntry();
        $isNew  = $dentry === null;

        if( $isNew )
        {
            $dentry = new \Entities\DirectoryEntry();
            $dentry->setMailbox( $mailbox );
            $mailbox->setDirectoryEntry( $dentry );
            $dentry->setVimbCreated( new \DateTime() );
            if( $em !== null )
                $em->persist( $dentry );
        }

        $disabled = $this->_disabled( $options );
        foreach( array_keys( self::DE_ATTRS ) as $attr )
        {
            $key = "plugin_directoryEntry_{$attr}";
            if( !array_key_exists( $key, $values ) )
                continue;
            // A disabled-and-removed attribute has no field; skip its writeback so
            // we don't clobber an existing value with empty.
            if( !empty( $disabled[ $attr ] ) && !in_array( $attr, [ 'DisplayName', 'Initials' ], true ) )
                continue;

            $setFn = 'set' . $attr;
            $dentry->$setFn( (string) $values[ $key ] );
        }

        // `mail` always tracks the mailbox address — set it AFTER the attribute
        // loop so the (possibly empty) submitted Mail field never clobbers it.
        $dentry->setMail( $mailbox->getUsername() );
        $dentry->setVimbUpdate( new \DateTime() );
    }
}
