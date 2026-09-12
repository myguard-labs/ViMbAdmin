<?php
/**
 * OSS Framework
 *
 * This file is part of the "OSS Framework" - a library of tools, utilities and
 * extensions to the Zend Framework V1.x used for PHP application development.
 *
 * Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Open Source Solutions Limited is a company registered in Dublin,
 * Ireland with the Companies Registration Office (#438231). We
 * trade as Open Solutions with registered business name (#329120).
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * It is also available through the world-wide-web at this URL:
 *     http://www.opensolutions.ie/licenses/new-bsd
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@opensolutions.ie so we can send you a copy immediately.
 *
 * @category   OSS
 * @package    OSS_Smarty
 * @subpackage Functions
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

/**
 * @category   OSS
 * @package    OSS_Smarty
 * @subpackage Functions
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */


    /**
     * Function to display OSS_Message for user
     *
     * @category   OSS
     * @package    OSS_Smarty
     * @subpackage Functions
     *
     * @param array{randomid?: mixed} $params
     * @param \Smarty\Smarty $smarty A reference to the Smarty template object
     * @return string
     */
    function smarty_function_OSS_Message( $params, &$smarty )
    {
        if( !is_array( $params ) )
            throw new \InvalidArgumentException( 'OSS_Message parameters must be an array' );
        foreach( $params as $key => $_value )
            if( !is_string( $key ) )
                throw new \InvalidArgumentException( 'OSS_Message parameter names must be strings' );
        if( !( $smarty instanceof \Smarty\Smarty ) && !( $smarty instanceof \Smarty\Template ) )
            throw new \InvalidArgumentException( 'OSS_Message requires a Smarty engine or template' );

        $ossms = $smarty->getTemplateVars( 'OSS_Messages' );
        if( $ossms === null )
            $ossms = [];
        if( !is_array( $ossms ) )
            throw new \InvalidArgumentException( 'OSS_Messages must be an array' );

        $session = $_SESSION ?? [];
        $application = $session['Application'] ?? [];
        if( !is_array( $application ) )
            throw new \InvalidArgumentException( 'session Application namespace must be an array' );
        $sessionMessages = $application['OSS_Messages'] ?? null;
        if( $sessionMessages !== null && !is_array( $sessionMessages ) )
            throw new \InvalidArgumentException( 'session OSS_Messages must be an array' );
        if( !is_array( $sessionMessages ) )
            $sessionMessages = [];

        $classValue = static function( mixed $value ): string {
            if( !is_string( $value ) || preg_match( '/^[A-Za-z0-9_-]*$/D', $value ) !== 1 )
                throw new \InvalidArgumentException( 'OSS message class must be a safe CSS class token' );
            return $value;
        };
        $messageItems = static function( mixed $value ): array {
            $items = is_array( $value ) ? $value : [ $value ];
            $result = [];
            foreach( $items as $item ) {
                if( !is_string( $item ) )
                    throw new \InvalidArgumentException( 'OSS message text must contain strings' );
                $result[] = $item;
            }
            return $result;
        };
        $validateMessage = static function( mixed $ossm ) use ( $classValue, $messageItems ): void {
            if( !( $ossm instanceof OSS_Message ) )
                throw new \InvalidArgumentException( 'OSS_Messages must contain OSS_Message objects' );
            $classValue( $ossm->getClass() );
            $messageItems( $ossm->getMessage() );
            if( $ossm instanceof OSS_Message_Block ) {
                $actions = $ossm->getActions();
                if( $actions !== null )
                    foreach( $actions as $action )
                        if( !is_string( $action ) )
                            throw new \InvalidArgumentException( 'OSS message actions must contain strings' );
            }
        };
        foreach( $ossms as $ossm )
            $validateMessage( $ossm );
        foreach( $sessionMessages as $ossm )
            $validateMessage( $ossm );

        $flashMessages = $application['flashMessages'] ?? null;
        if( $flashMessages !== null && !is_array( $flashMessages ) )
            throw new \InvalidArgumentException( 'session flashMessages must be an array' );
        if( !is_array( $flashMessages ) )
            $flashMessages = [];
        foreach( $flashMessages as $flashMessage ) {
            if( !is_array( $flashMessage ) )
                throw new \InvalidArgumentException( 'session flash messages must be arrays' );
            $classValue( $flashMessage['level'] ?? 'success' );
            if( array_key_exists( 'text', $flashMessage ) && !is_string( $flashMessage['text'] ) )
                throw new \InvalidArgumentException( 'session flash message text must be a string' );
            if( array_key_exists( 'isHtml', $flashMessage ) && !is_bool( $flashMessage['isHtml'] ) )
                throw new \InvalidArgumentException( 'session flash message isHtml must be boolean' );
        }

        if( $sessionMessages !== [] ) {
            $ossms = array_merge( $ossms, $sessionMessages );
            unset( $application['OSS_Messages'] );
            $session['Application'] = $application;
            $_SESSION = $session;
        }

        // NB: no early return when $ossms is empty — a natively-dispatched
        // controller may have queued framework-free flash messages (drained at
        // the end of this function) with no legacy OSS_Messages present.

        /** @var int $nextId */
        static $nextId = 0;
        $message = '';
        
        foreach( $ossms as $ossm )
        {
            if( isset( $params['randomid'] ) && !is_scalar( $params['randomid'] ) )
                throw new \InvalidArgumentException( 'OSS_Message randomid must be scalar' );
            if( $ossm instanceof OSS_Message_Block )
            {
                $class = $classValue( $ossm->getClass() );
                $blockMessage = $ossm->getMessage();
                if( !is_string( $blockMessage ) )
                    throw new \UnexpectedValueException( 'OSS block message must be a string' );
                $actions = $ossm->getActions();
                if( $actions === null )
                    $actions = [];
                $count = $nextId;
                ++$nextId;
                $message .= <<<END_MESSAGE

    <div class="alert alert-block alert-{$class} fade in" id="oss-message-{$count}">
        <a class="close" href="#" data-bs-dismiss="alert">×</a>
        {$blockMessage}
END_MESSAGE;
                if( count( $actions ) )
                {
                    $message .= "        <div class=\"alert-actions\">\n";

                    foreach( $actions as $a ) {
                        if( !is_string( $a ) )
                            throw new \InvalidArgumentException( 'OSS message actions must contain strings' );
                        $message .= $a . "\n";
                    }

                    $message .= "        </div>\n";
                }

                $message .= <<<END_MESSAGE
    </div>

END_MESSAGE;
            }
            else if( $ossm instanceof OSS_Message_Pop_Up )
            {

                $items = $messageItems( $ossm->getMessage() );

                foreach( $items as $item )
                {
                        $message .= <<<END_MESSAGE

        <script type="text/javascript">
            $( document ).ready( function()
            {
                ossAlert( '{$item}' );
            })
        </script>

END_MESSAGE;
                }
            }
            else
            {
                if( !( $ossm instanceof OSS_Message ) )
                    throw new \InvalidArgumentException( 'OSS_Messages must contain OSS_Message objects' );
                $items = $messageItems( $ossm->getMessage() );
                $class = $classValue( $ossm->getClass() );
                
                foreach( $items as $item )
                {
                        $count = $nextId;
                        ++$nextId;
                        $message .= <<<END_MESSAGE

        <div class="alert alert-{$class} fade in" id="oss-message-{$count}">
            <a class="close" href="#" data-bs-dismiss="alert">×</a>
            {$item}
        </div>

END_MESSAGE;
                }
            } // end inner foreach

        } // end foreach()


        // Phase 3 (docs/ZF1-REMOVAL.md): also drain the framework-free flash
        // queue that natively-dispatched controllers write
        // (ViMbAdmin\Kernel\Flash\FlashMessages over the 'Application' session
        // namespace, key 'flashMessages'), rendering each entry as the same plain
        // alert the legacy OSS_Message path produces above. Append-only — the
        // legacy OSS_Messages handling is untouched, so existing flashes are
        // unaffected and a page can carry both.
        if( $flashMessages !== [] )
        {
            foreach( $flashMessages as $fm )
            {
                $count = $nextId;
                ++$nextId;
                $fmClass = isset( $fm['level'] ) ? $classValue( $fm['level'] ) : 'success';
                $fmText  = isset( $fm['text'] )  ? $fm['text']  : '';
                if( !is_string( $fmText ) )
                    throw new \InvalidArgumentException( 'session flash message text must be a string' );
                $fmIsHtml = isset( $fm['isHtml'] ) ? $fm['isHtml'] : false;
                if( !is_bool( $fmIsHtml ) )
                    throw new \InvalidArgumentException( 'session flash message isHtml must be boolean' );
                $fmOutput = $fmIsHtml ? $fmText : htmlspecialchars( $fmText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

                $message .= <<<END_MESSAGE

        <div class="alert alert-{$fmClass} fade in" id="oss-message-{$count}">
            <a class="close" href="#" data-bs-dismiss="alert">×</a>
            {$fmOutput}
        </div>

END_MESSAGE;
            }

            unset( $application['flashMessages'] );
            $session['Application'] = $application;
            $_SESSION = $session;
        }


        return $message;
    }
