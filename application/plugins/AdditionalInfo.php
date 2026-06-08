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
 * The AdditionalInfo plugin
 *
 * @package ViMbAdmin
 * @subpackage Plugins
 */
class ViMbAdminPlugin_AdditionalInfo extends ViMbAdmin_Plugin implements OSS_Plugin_Observer, ViMbAdmin_Plugin_MailboxFormExtension
{

    public function __construct( object $controller )
    {
        parent::__construct( $controller, get_class( $this ) );
        
        // no setup tasks are required
        //
        // typically you might load an config file for example, but as this is a system
        // plugin, we can use the main application.ini for that.
    }
    // -- Native mailbox-form extension ---------------------------------------

    /**
     * The configured additional-info elements, or [] when none are defined.
     *
     * @return array<string,array<string,mixed>>
     */
    private function _elements( array $options ): array
    {
        return $options['vimbadmin_plugins']['AdditionalInfo']['elements'] ?? [];
    }

    public function nativeMailboxFields( ?\Entities\Mailbox $mailbox, array $options ): array
    {
        $fields = [];

        foreach( $this->_elements( $options ) as $name => $element )
        {
            $opts  = $element['options'] ?? [];
            $label = $opts['label'] ?? $name;

            $rules = [];
            if( !empty( $opts['required'] ) )
                $rules[] = \ViMbAdmin\Kernel\Form\Validators::required();

            // Map the handful of Zend validators these elements use in practice
            // (Digits, StringLength range) to framework-free field rules; unknown
            // validators are skipped (the value still saves — best-effort parity).
            foreach( (array) ( $opts['validators'] ?? [] ) as $validator )
            {
                $vname = is_array( $validator ) ? ( $validator[0] ?? null ) : $validator;
                if( $vname === 'Digits' )
                    $rules[] = \ViMbAdmin\Kernel\Form\Validators::regex( '/^\d+$/', _( 'Please enter digits only.' ) );
                elseif( $vname === 'StringLength' && isset( $validator['range'][0] ) )
                    $rules[] = \ViMbAdmin\Kernel\Form\Validators::minLength( (int) $validator['range'][0] );
            }

            $field = new \ViMbAdmin\Kernel\Form\Field( "plugin_additionalInfo_{$name}", _( (string) $label ), 'text', $rules );

            if( $mailbox !== null && $mailbox->getPreference( 'xpiInfo.' . $name ) )
                $field->setValue( (string) $mailbox->getPreference( 'xpiInfo.' . $name ) );

            $fields[] = $field;
        }

        return $fields;
    }

    public function nativeMailboxValidate( array $values, array $options ): ?string
    {
        // All AdditionalInfo constraints are per-field (handled by the Field rules
        // returned above); there is no cross-field rule.
        return null;
    }

    public function nativeMailboxApply( \Entities\Mailbox $mailbox, array $values, array $options, ?object $em = null ): void
    {
        foreach( array_keys( $this->_elements( $options ) ) as $name )
        {
            $key = "plugin_additionalInfo_{$name}";
            if( array_key_exists( $key, $values ) )
                $mailbox->setPreference( 'xpiInfo.' . $name, (string) $values[ $key ] );
        }
    }
}
