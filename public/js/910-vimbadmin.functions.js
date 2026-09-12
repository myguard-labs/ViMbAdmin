/*
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
 * @package ViMbAdmin
 */


    /**
    * does what PHP htmlentities() does
    * this is the string object method version (prototyped)
    *
    * @param string (note, this is not a real function parameter, it is a prototype method, so method chaining is happening here)
    * @return string
    */
    String.prototype.htmlEntity = function()
    {
        return htmlEntity( this.toString() );
    }


    /**
    * does what PHP html_entity_decode() does
    * this is the string object method version (prototyped)
    *
    * @param string (note, this is not a real function parameter, it is a prototype method, so method chaining is happening here)
    * @return string
    */
    String.prototype.htmlEntityDecode = function()
    {
        return htmlEntityDecode( this.toString() );
    }


    /**
    * Converts a string to Camel Case
    *
    * @author: Paul Visco
    */
    String.prototype.ucwords = function()
    {
        var arr = this.split(' ');
        var str ='';

        arr.forEach( function(v) { str += v.charAt(0).toUpperCase() + v.slice(1, v.length) + ' ' } );

        return str;
    }


    /**
    * does what PHP htmlentities() does
    * this is the standalone function version
    *
    * @param string str
    * @return string
    */
    function htmlEntity(str)
    {
        var element = document.createElement('div');
        element.textContent = str;
        return element.innerHTML;
    }

   /**
    * Escapes a value for use inside a quoted HTML attribute.
    *
    * htmlEntity() is not enough here: a text node's innerHTML escapes only
    * & < > and U+00A0, leaving " and ' intact, so a value carrying a quote
    * breaks out of the attribute it was interpolated into. Validators
    * currently reject quotes in the names that reach these sinks, but that
    * guarantee lives far from the sink -- escape at the sink instead.
    *
    * @param string str
    * @return string
    */
    function htmlAttr(str)
    {
        return htmlEntity( String( str ) )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }


    /**
    * does what PHP html_entity_decode() does
    * this is the standalone function version
    *
    * @param string str
    * @return string
    */
    function htmlEntityDecode(str)
    {
        // RCDATA decodes entities as text; it never creates active image/script
        // nodes from markup supplied to this string helper.
        var element = document.createElement('textarea');
        element.innerHTML = str;
        return element.value;
    }




    /**
     * Generates a random password of the given length from [a-zA-Z0-9].
     * It makes sure that the generated password contains digits, lowercase and uppercase characters.
     *
     * @author Roland Huszti <roland _at_ opensolutions.ie>
     */
    function randomPassword( pwdLength )
    {
        var charSet = "0123456789abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        var password = '';

        while( true )
        {
            for( var x = 0; x < pwdLength; x++ )
                password += charSet.charAt( Math.floor( Math.random() * charSet.length ) );

            // not the same as search('[a-zA-z0-9]') !!!!
            if ( (password.search('[a-z]') != -1) && (password.search('[A-Z]') != -1) && (password.search('[0-9]') != -1) )
                return password;
        }
    }


    /**
     * Checks if the value is a valid email address or not.
     *
     * @param string str
     * @return boolean
     */
    function isValidEmail( str )
    {
        return /^([A-Za-z0-9_\-\+\.])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,})$/.test( str );
    }


    /**
     * Checks if the value is a valid domain for email addresses or not.
     *
     * @param string str
     * @return boolean
     */
    function isValidEmailDomain( str )
    {
        return /^([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,})$/.test( str );
    }
