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
 */

/**
 * The AccessPermissions plugin
 *
 * AccessPermissions were part of the main ViMbAdmin code but I have shunted them to a
 * plugin to demonstrate and prove the arcitecture. It's a slight cheat as AccessPermissions
 * rely on a specific column in the Mailbox database table which plugins should typically
 * avoid.
 *
 * See https://github.com/opensolutions/ViMbAdmin/wiki/Plugin-Access-Permissions
 *
 * @package ViMbAdmin
 * @subpackage Plugins
 */
class ViMbAdminPlugin_AccessPermissions extends ViMbAdmin_Plugin implements OSS_Plugin_Observer, ViMbAdmin_Plugin_MailboxFormExtension
{

    public function __construct( object $controller )
    {
        parent::__construct( $controller, get_class( $this ) );
        
        // no setup tasks are required
        //
        // typically you might load an config file for example, but as this is a system
        // plugin, we can use the main application.ini for that.
    }
    // -- Native form extension ------------------------------------------------

    /**
     * The configured permission types (e.g. SMTP/IMAP/POP3/SIEVE) as a name=>label
     * map, or an empty array when the plugin is not configured.
     */
    private function _types( array $options ): array
    {
        return $options['vimbadmin_plugins']['AccessPermissions']['type'] ?? [];
    }

    public function nativeMailboxFields( ?\Entities\Mailbox $mailbox, array $options ): array
    {
        $types      = $this->_types( $options );
        $restricted = $mailbox !== null && $mailbox->getAccessRestriction() !== null
            && $mailbox->getAccessRestriction() !== 'ALL';
        $selected   = $restricted ? explode( ',', (string) $mailbox->getAccessRestriction() ) : [];

        $fields = [];

        $master = new \ViMbAdmin\Kernel\Form\Field(
            'plugin_accessPermissions',
            _( 'Set specific access permissions for this mailbox' ),
            'checkbox'
        );
        $master->setValue( $restricted );
        $fields[] = $master;

        foreach( $types as $name => $label )
        {
            $field = new \ViMbAdmin\Kernel\Form\Field(
                "plugin_accessPermission_{$name}",
                _( $label ),
                'checkbox'
            );
            $field->setValue( in_array( (string) $name, $selected, true ) );
            $fields[] = $field;
        }

        return $fields;
    }

    public function nativeMailboxValidate( array $values, array $options ): ?string
    {
        if( empty( $values['plugin_accessPermissions'] ) )
            return null;

        foreach( array_keys( $this->_types( $options ) ) as $name )
            if( !empty( $values["plugin_accessPermission_{$name}"] ) )
                return null; // at least one service selected

        return _( 'You must select which services the user can access if you are choosing to apply specific access permissions' );
    }

    public function nativeMailboxApply( \Entities\Mailbox $mailbox, array $values, array $options, ?object $em = null ): void
    {
        if( empty( $values['plugin_accessPermissions'] ) )
        {
            $mailbox->setAccessRestriction( 'ALL' );
            return;
        }

        $selected = [];
        foreach( array_keys( $this->_types( $options ) ) as $name )
            if( !empty( $values["plugin_accessPermission_{$name}"] ) )
                $selected[] = $name;

        $mailbox->setAccessRestriction( $selected === [] ? 'ALL' : implode( ',', $selected ) );
    }
}
