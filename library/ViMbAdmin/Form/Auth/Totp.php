<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2026 Open Source Solutions Limited + contributors
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * The second-factor (TOTP / backup code) challenge form.
 *
 * @package ViMbAdmin
 * @subpackage Form
 */
class ViMbAdmin_Form_Auth_Totp extends ViMbAdmin_Form
{
    public function init()
    {
        $this->setAttrib( 'id', 'totp_form' )
            ->setAttrib( 'name', 'totp_form' );

        $code = $this->createElement( 'text', 'code' )
            ->setLabel( _( 'Authentication code' ) )
            ->setAttrib( 'class', 'span3' )
            ->setAttrib( 'autocomplete', 'one-time-code' )
            ->setAttrib( 'inputmode', 'numeric' )
            ->setAttrib( 'autofocus', 'autofocus' )
            ->setAttrib( 'placeholder', _( '6-digit code or backup code' ) )
            ->setRequired( true )
            ->addFilter( 'StringTrim' )
            ->addValidator( 'StringLength', false, [ 6, 32 ] );
        $this->addElement( $code );

        $submit = $this->createElement( 'submit', 'verify' )
            ->setLabel( _( 'Verify' ) );
        $this->addElement( $submit );
    }
}
