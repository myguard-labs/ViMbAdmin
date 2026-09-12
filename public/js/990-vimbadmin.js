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


//****************************************************************************
// ViMbAdmin cookies
//****************************************************************************

function vmReady(callback)
{
    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    else
        callback();
}

function vmDisposeTooltips(root)
{
    root.querySelectorAll('.have-tooltip, .have-tooltip-below, .have-tooltip-long').forEach(function(element) {
        var instance = bootstrap.Tooltip.getInstance(element);
        if (instance) instance.dispose();
    });
}

function vmTooltips(root)
{
    root = root || document;
    ['.have-tooltip', '.have-tooltip-below', '.have-tooltip-long'].forEach(function(selector) {
        root.querySelectorAll(selector).forEach(function(element) {
            var previous = bootstrap.Tooltip.getInstance(element);
            if (previous) previous.dispose();
            var options = { html: true, trigger: 'hover', placement: 'top' };
            if (selector !== '.have-tooltip-long') options.delay = { show: 500, hide: 2 };
            if (selector === '.have-tooltip-below') options.placement = 'bottom';
            new bootstrap.Tooltip(element, options);
        });
    });
}

/** Native transport with the existing timeout, text/JSON and form wire contract. */
function ossAjax(options)
{
    var xhr = new XMLHttpRequest();
    var method = (options.type || 'GET').toUpperCase();
    var url = new URL(options.url, document.baseURI);
    var data = typeof options.data === 'string' ? options.data
        : DataTable.ajax.serialize(options.data || {}).replace(/%20/g, '+');
    if (method === 'GET' || method === 'HEAD') {
        if (data) url.search += (url.search ? '&' : '') + data;
        if (options.cache === false) url.searchParams.set('_', String(Date.now()));
        data = null;
    }
    xhr.open(method, url.href, options.async !== false);
    if (data !== null) xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    if (url.origin === location.origin) xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', options.dataType === 'json'
        ? 'application/json, text/javascript, */*; q=0.01' : '*/*');
    if (options.async !== false) xhr.timeout = options.timeout || 0;
    var finished = false;
    function finish(status) {
        if (finished) return;
        finished = true;
        var value = xhr.responseText;
        // No-content and conditional responses succeed without a JSON body.
        if (status === 'success' && (xhr.status === 204 || method === 'HEAD' || xhr.status === 304)) {
            status = xhr.status === 304 ? 'notmodified' : 'nocontent';
            value = undefined;
        }
        if (status === 'success' && (options.dataType === 'json'
            || (!options.dataType && /\bjson\b/i.test(xhr.getResponseHeader('Content-Type') || '')))) {
            try { value = JSON.parse(value); }
            catch (error) { status = 'parsererror'; }
        }
        try {
            if (status === 'success' || status === 'nocontent' || status === 'notmodified') {
                if (options.success) options.success(value, status, xhr);
            }
            else if (options.error) options.error(xhr, status, xhr.statusText);
        }
        finally {
            if (options.complete) options.complete(xhr, status);
        }
    }
    xhr.onload = function() { finish(xhr.status >= 200 && xhr.status < 300 || xhr.status === 304 ? 'success' : 'error'); };
    xhr.onerror = function() { finish('error'); };
    xhr.ontimeout = function() { finish('timeout'); };
    xhr.onabort = function() { finish('abort'); };
    xhr.send(data || null);
    return xhr;
}

var vm_cookie_options = {
    'expires': 90,
    'path': "/",
    // The retired plugin set neither; a preferences cookie is same-site only,
    // and over TLS it has no reason to travel in the clear.
    'sameSite': 'Lax',
    'secure': window.location.protocol === 'https:'
};

var vm_prefs = {
};

/**
 * Read or write the JSON preferences cookie with native browser APIs.
 *
 * The cookie value and attributes deliberately match the retired helper so
 * existing installations retain their saved preferences across the upgrade.
 */
function vmPrefsCookie( key, value, options )
{
    if( arguments.length > 1 ) {
        options = Object.assign( {}, options );

        if( value === null || value === undefined )
            options.expires = -1;

        if( typeof options.expires === 'number' ) {
            var days = options.expires;
            options.expires = new Date();
            options.expires.setDate( options.expires.getDate() + days );
        }

        return document.cookie = [
            key, '=', JSON.stringify( value ),
            options.expires ? '; expires=' + options.expires.toUTCString() : '',
            options.path    ? '; path=' + options.path : '',
            options.domain  ? '; domain=' + options.domain : '',
            options.sameSite ? '; SameSite=' + options.sameSite : '',
            options.secure  ? '; secure' : ''
        ].join( '' );
    }

    // Scan every segment: an empty one (a trailing '; ' some clients emit) must
    // not terminate the search before a later entry is reached.
    var pairs = document.cookie.split( '; ' );
    for( var i = 0; i < pairs.length; i++ ) {
        var pair = pairs[i].split( '=' );

        if( pair[0] === key ) {
            try {
                var parsed = JSON.parse( pair.slice( 1 ).join( '=' ) );
                return parsed !== null && typeof parsed === 'object' ? parsed : null;
            } catch( error ) {
                return null;
            }
        }
    }

    return null;
}

var cprefs = vmPrefsCookie( 'vm_prefs' );

if( cprefs != null )
	vm_prefs = cprefs;


//****************************************************************************
//****************************************************************************



vmReady( function(){

	// Activate the modal dialog pop up
    DataTable.Dom.select( document ).on( 'click', "a[id|='modal-dialog']", tt_openModalDialog );

    document.querySelectorAll('[rel=popover]').forEach(function(el) {
        bootstrap.Popover.getOrCreateInstance(el, { html: true });
    });

    vmTooltips();

});




//****************************************************************************
// ViMbAdmin global js functions
//****************************************************************************


/**
 * This function creates throbber with some default parameters and return the throbber object.
 *
 * @param size  This is size of throbber in pixels.
 * @param lines This is lines count, defines how many lines per throbber.
 * @param strokewidth This is the widh of line.
 * @param fallback This is path to alternative throbber image if browser not compatible with this one.
 * @return Throbber The throbber object
 */

function tt_throbber( size, lines, strokewidth, fallback )
{
    // Bootstrap 5 ships exactly two spinner sizes: the default and -sm.
    var sizeClass = size >= 25 ? '' : 'spinner-border-sm';

    // vb-throbber marks the spinners this wrapper owns. The error handler tears
    // down throbbers by that class, never by the generic Bootstrap utility
    // class, so an unrelated spinner elsewhere on the page survives.
    var el = DataTable.Dom.create('div')
        .addClass('spinner-border')
        .addClass('vb-throbber')
        .addClass(sizeClass)
        .attr('role', 'status')
        .append(DataTable.Dom.create('span').addClass('visually-hidden').text('Loading...'));

    var controller = {
        el: el,
        appendTo: function(target) {
            this.el.appendTo(target);
            return this;
        },
        start: function() {
            return this;
        },
        stop: function() {
            var el = this.el;
            el.transition({ opacity: 0 }, 750, 'ease', function() {
                el.remove();
            });
            return this;
        }
    };

    return controller;
}

/**
 * This function is handling toggle elements.
 *
 * First function guards the pending element and removes its label type.
 * Then creates throbber and add it to div trobber with id throb-{toggle element id}.
 * div for throbber should be created manually. Function only assigns throbber to it. After
 * that it calls AJAX for passed URL and data. If response ok flag ok is set to true otherwise
 * error message is show. If we have AJAX error ten ossAjaxErrorHandler calls. After AJAX error
 * or success handlers function sets back label type and pointer by flags On and Ok , kills throbber
 * and releases the pending guard. List-view delegates own all click bindings.
 *
 * @param {DataTable.Dom} e Element which will be edited.
 * @param {string} Url URL for the AJAX request.
 * @param {Object} data Data to post.
 * @param {string|Element} [delElement] Element to remove after a successful request.
 * @param {function(boolean, boolean): void} [committed] Called after completion has
 * restored the toggle UI, cleared its throbber and released its pending guard.
 * The arguments are the request result and visible toggle state: success reports
 * `(true, newState)`, while failure reports `(false, originalState)`. A successful
 * `delElement` transition is scheduled before this callback but may still be running.
 */
var ossPendingToggles = new WeakSet();

function ossToggle( e, Url, data, delElement, committed )
{
    var element = e.get( 0 );
    if( !element || e.hasClass( 'disabled' ) || ossPendingToggles.has( element ) )
        return;
    // Active controls are also spans: their disabled property is visual state,
    // not a browser event guard. Never accept a second request while pending.
    ossPendingToggles.add( element );

    var on = true;
    if( e.hasClass( 'btn-danger' ) ) {
        e.removeClass( "btn-danger" ).prop( 'disabled', true );
    } else {
        on = false;
        e.removeClass( "btn-success" ).prop( 'disabled', true );
    }

    tt_throbber( 18, 10, 1, 'images/throbber_16px.gif' ).appendTo( DataTable.Dom.select( '#throb-' + e.attr( 'id' ) ).get(0) ).start();

    var ok = false;

    ossAjax({
        url: Url,
        data: data,
        async: true,
        cache: false,
        type: 'POST',
        timeout: 10000,
        success: function( data ){
            if( data == "ok" ) {
                ok = true;
            } else {
                ossAddMessage( data, 'danger' );
            }
        },
        error: ossAjaxErrorHandler,
        complete: function(){

            if( !ok ) on = !on;

            if( on ) {
                e.html( "Yes" ).addClass( "btn-success" ).prop( 'disabled', false );
            } else {
                e.html( "No" ).addClass( "btn-danger" ).prop( 'disabled', false );
            }

            DataTable.Dom.select( '#throb-' + e.attr( 'id' ) ).html( "" );

            ossPendingToggles.delete( element );

            if( delElement && ok ) {
                DataTable.Dom.select( delElement ).transition({ opacity: 0 }, 600, 'ease', function() {
                    DataTable.Dom.select( delElement ).remove();
                });
            }

            if( typeof committed === 'function' )
                committed( ok, on );

        }
    });

    return on;
}

/**
 * This function is opening modal dialog with contact us form.
 *
 * First it creates the throbber witch is shown while form is loading by ajax.
 * When function creates and opens modal dialog witch is showing throbber.
 * When form is load the throbber is replaced by it. If ajax gets en error the
 * ossAjaxErrorHandler is called.
 *
 * @param event event The browser event, needed to prevent element from default actions.
 */
function tt_openModalDialog(event) {

    event.preventDefault();

    if( DataTable.Dom.select( event.target ).is( "i" ) )
        element = DataTable.Dom.select( event.target ).parent();
    else
        element = DataTable.Dom.select( event.target );


    id = element.attr( 'id' ).substr( element.attr( 'id' ).lastIndexOf( '-' ) + 1 );

    if( id.substring( 0, 4 ) == "wide" )
    {
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).addClass( 'modal-wide' );
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-email' );
    }
    else if( id.substring( 0, 5 ) == "email" )
    {
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).addClass( 'modal-email' );
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-wide' );
    }
    else
    {
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-wide' );
        DataTable.Dom.select( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-email' );
    }

    var modalShell = DataTable.Dom.select( '#modal_dialog_shell' );
    var loadingLabel = element.attr( 'aria-label' ) || element.attr( 'title' )
        || element.attr( 'data-bs-original-title' );
    if( typeof loadingLabel !== 'string' || loadingLabel.trim() === '' )
        loadingLabel = 'Loading dialog';
    modalShell.attrRemove( 'aria-labelledby' ).attr( 'aria-label', loadingLabel );

    DataTable.Dom.select('#modal_dialog').html( '<div id="throb" style="padding-left:230px; padding-top:175px; height:275px;"></div>' );


    tt_throbber( 100, 20, 1.8 ).appendTo( DataTable.Dom.select( '#throb' ).get(0) ).start();

    dialog = ossModal( '#modal_dialog_shell' );

    ossAjax({
        url: element.attr( 'href' ) ,
        async: true,
        cache: false,
        type: 'POST',
        timeout: 10000,
        success:    function(data) {
                        DataTable.Dom.select('#modal_dialog').html( data );
                        var modalTitle = modalShell.find( '.modal-title' ).first();
                        var modalTitleId = modalTitle.attr( 'id' );
                        var modalTitleText = modalTitle.text();
                        var modalTitleIdIsToken = typeof modalTitleId === 'string'
                            && modalTitleId !== '' && !/[\t\n\f\r ]/.test( modalTitleId );
                        var matchingIds = DataTable.Dom.select( '[id]' ).filter( function(node) {
                            return node.id === modalTitleId;
                        } ).length;
                        if( modalTitle.length && modalTitleIdIsToken
                            && modalTitleText.trim() !== ''
                            && matchingIds === 1 )
                            modalShell.attr( 'aria-labelledby', modalTitleId ).attrRemove( 'aria-label' );
                        DataTable.Dom.select( '.modal-body' ).scrollTop( 0 );
                        DataTable.Dom.select( '#modal_dialog_cancel' ).on( 'click', function(){
                            dialog.hide();
                        });
                     },

        error:     ossAjaxErrorHandler
    });
};

/**
 * This function is handling ajax errors.
 *
 * First function is checking if ajax was called on modal window, if so when
 * it checks if buttons are shown that mean that ajax crashed then modal dialog was
 * submitting and enabling modal dialog buttons. If buttons not visible that means
 * that ajax crashed then the content was loading so it close modal dialog.
 * After that it checks if throbber (canvas) is showing and if so it closes that too.
 * And after that it calls ossAddMessage.
 *
 */
function ossAjaxErrorHandler( XMLHttpRequest, textStatus, errorThrown )
{
    if( DataTable.Dom.select('#modal_dialog_shell').isVisible() )
    {
        if( DataTable.Dom.select('#modal_dialog_save').length ){
            DataTable.Dom.select('#modal_dialog_save').prop( 'disabled', false ).removeClass( 'disabled' );
            DataTable.Dom.select('#modal_dialog_cancel').prop( 'disabled', false ).removeClass( 'disabled' );
        }
        else
        {
            if( dialog )
            {
                dialog.hide();
            }
        }
    }

    if( DataTable.Dom.select('canvas').length ){
        DataTable.Dom.select('canvas').remove();
    }

    if( DataTable.Dom.select('.vb-throbber').length ){
        DataTable.Dom.select('.vb-throbber').remove();
    }
    ossAddMessage( 'An unexpected error occurred.', 'danger', true );
}


/**
 * This function adding oss messages.
 *
 * Function defines message box. And when check where the message should be shown.
 * First it is looking for modal dialog to display oss message in it.
 * If modal dialog was not found it looks for class breadcrumb, witch is page header,
 * and insert oss message after it. And finally if no modal dialog or breadcrumb was found
 * it insert oss message at the top of main div.
 *
 * @param msg  This is main text of oss message.
 * @param type This is type of oss message(success, error, info, etc.).
 * @param handled This is means that it came from ossAjaxErrorHandler and message can be displayed on modal dialog
 */
function ossAddMessage( msg, type, handled )
{
    rand = Math.floor( Math.random() * 1000000 );

    msgbox = '<div id="oss-message-' + rand + '" class="alert alert-' + type + ' alert-dismissible fade show">\
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>\
                                    '+ msg + '</div>';

    var modalBodies = DataTable.Dom.select('.modal-body').filter(function(node) {
        return DataTable.Dom.select(node).isVisible();
    });
    if( modalBodies.length && handled )
    {
        modalBodies.prepend( msgbox );


    }
    else if( DataTable.Dom.select('.page-header').length )
    {
        DataTable.Dom.select('.page-header').each(function(el) { el.insertAdjacentHTML('afterend', msgbox); });

    }
    else if( DataTable.Dom.select('.page-content').length )
    {
        DataTable.Dom.select('.page-content').prepend( msgbox );

    }
    else if( DataTable.Dom.select( ".container" ).length )
    {
        DataTable.Dom.select('.container').each(function(el) { el.insertAdjacentHTML('beforebegin', msgbox); });
    }
    else if( DataTable.Dom.select('#main').length )
    {
        DataTable.Dom.select('#main').prepend( msgbox );
    }

    bootstrap.Alert.getOrCreateInstance(document.getElementById('oss-message-' + rand));
}

/**
 * This function is for validating input field.
 *
 * Function checks if the input field has the value if not set error,
 * and sets valid to false. If value not empty and email flag sets to
 * true then function calls validate email, and if email validate function
 * removes class error, if email not valid function add set error and sets
 * valid to false. and if email flag is false, and value is not empty, we remove
 * error from input field.
 *
 * @param string fieldName The field id, we need only id because we have to build other id from it.
 * @param bool email The email flag, witch means that input field is email and we need to validate it as email.
 */
function ossJscriptFieldValidator( fieldName, email )
{
    if( DataTable.Dom.select( '#' + fieldName ).val() != "" )
    {
        if( email )
        {
            if( ossValidateEmail( DataTable.Dom.select( '#' + fieldName ).val() ) )
            {
               DataTable.Dom.select( '#div-form-' + fieldName ).removeClass( 'error' );
               DataTable.Dom.select( '#help-' + fieldName ).html( "" );
            }
        }
        else
        {
            DataTable.Dom.select( '#div-form-' + fieldName ).removeClass( 'error' );
            DataTable.Dom.select( '#help-' + fieldName ).html( "" );
        }
    }
}


/**
 * Add tab for plugin tabs.
 * 
 * If there was no plugins it will not show tabas menu at all, until first tab will be added.
 *
 * @param string title Title of the tab.
 * @param string id Id of tab content to show.
 */
function addPluginTab( title, id )
{    
        if( id.substr( 0, 4 ) != "tab_" )
            id = "tab_" + id;

	    var tab = "<li><a data-bs-toggle=\"tab\"";
	    
	    if( DataTable.Dom.select( "#" + id ).find( '.error' ).length )
	        tab += " class=\"text-danger\"";
	    
	    tab += " href=\"#" + id + "\">" + title + "</a></li>\n";
	    DataTable.Dom.select( "#plugin_tabs" ).show().append( tab );
}


/**
 * This function is simply checks regular expression of given string, and return if it is email address, otherwise return false.
 *
 * @param string email The string witch is validating as email address.
 * @return bool
 */
function ossValidateEmail( email)
{
    var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;
    if( emailReg.test( email ) )
    {
        return true;
    }
    else
    {
        return false;
    }
}

/**
 * This function generates random password and set to field by given id.
 *
 * @param int len The wanted password length.
 * @param string email The field id to set the password.
 */
function randPasword( len, id )
{
    DataTable.Dom.select( '#' + id ).val( randomPassword( len ) );
    DataTable.Dom.select( '#' + id ).trigger( 'blur' );
}


//****************************************************************************
// DataTables http://datatables.net/blog/Twitter_Bootstrap_2
//****************************************************************************


/**
 * Emit the DataTables 3 native error event, preserving detached-table bubbling.
 * Consumers cancel xhr errors with preventDefault() (or return false via Api.on).
 */
function vmDataTableLogAjaxError( api, technicalNote, message )
{
    var settings = api.settings()[0];
    var mode = DataTable.ext.sErrMode || DataTable.ext.errMode;
    var full = 'DataTables warning: table id=' + api.table().node().id
        + ' - ' + message + '. For more information about this error, please see '
        + 'https://datatables.net/tn/' + technicalNote;
    var table = DataTable.Dom.select(api.table().node());
    var stopped = false;
    table.trigger('dt-error.dt', true,
        [settings, technicalNote, message], {
            dt: settings.api,
            stopPropagation: function() {
                stopped = true;
                Event.prototype.stopPropagation.call(this);
            },
            stopImmediatePropagation: function() {
                stopped = true;
                Event.prototype.stopImmediatePropagation.call(this);
            }
        }, true);
    if (!table.isAttached() && !stopped) {
        DataTable.Dom.select(document.body).trigger('dt-error.dt', true,
            [settings, technicalNote, message], { dt: settings.api });
    }
    if (typeof mode === 'function') mode(settings, technicalNote, full);
    else if (mode === 'throw') throw new Error(full);
    else if (mode === 'alert') alert(full);
}

/**
 * Decline short searches without sending an unfiltered request.
 * DataTables 3 copies language into settings.language using camelCase keys.
 */
function vmDataTableServerData( source, minimum )
{
    return function( data, callback, settings )
    {
        var api = new DataTable.Api(settings);
        var language = settings.language;
        var search = (data.search && data.search.value)
            ? String(data.search.value).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '')
            : '';
        var searchTerm = search.charAt(0) === '*'
            ? search.slice(1).replace(/^[ \t\n\r\0\x0B]+/, '') : search;
        var searchLength = searchTerm.replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_').length;
        if (searchLength > 0 && searchLength < minimum) {
            var originalZeroRecords = language.zeroRecords;
            var originalEmptyTable = language.emptyTable;
            var hint = 'Enter at least ' + minimum + ' characters to search.';
            language.zeroRecords = hint;
            language.emptyTable = hint;
            try {
                callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
            }
            finally {
                language.zeroRecords = originalZeroRecords;
                language.emptyTable = originalEmptyTable;
            }
            return;
        }
        return ossAjax({
            url: source, type: settings.serverMethod || 'GET',
            dataType: 'json', cache: false, data: data, success: callback,
            error: function(xhr, error) {
                var event = DataTable.Dom.select(api.table().node()).trigger(
                    'xhr.dt', true, [settings, null, xhr], { dt: settings.api }, true)[0];
                try {
                    if (!event.defaultPrevented) {
                        if (error === 'parsererror')
                            vmDataTableLogAjaxError(api, 1, 'Invalid JSON response');
                        else if (xhr.readyState === 4)
                            vmDataTableLogAjaxError(api, 7, 'Ajax error');
                    }
                }
                finally {
                    api.processing(false);
                }
            }
        });
    };
}

/** Restore only usable lengths; older cookies may contain null or bad JSON values. */
function vmDataTablePageLength( fallback )
{
    var length = typeof vm_prefs !== 'undefined' && vm_prefs ? vm_prefs.iLength : undefined;
    if( typeof length === 'string' && /^-?\d+$/.test(length) ) length = Number(length);
    return Number.isSafeInteger(length) && (length > 0 || length === -1) ? length : fallback;
}

function vmDataTableApi( table )
{
    return new DataTable.Api(table);
}

// Keep Bootstrap pagination, five numbers, arrow labels and single-column order.
DataTable.util.object.assignDeep(DataTable.defaults, {
    preDrawCallback: function(settings) { vmDisposeTooltips(settings.table); },
    pagingType: 'simple_numbers',
    language: { paginate: { previous: '&larr; Previous', next: 'Next &rarr;' } },
    orderMulti: false
});
DataTable.ext.pager.numbers_length = 5;

// Bootstrap owns a strong instance map. Release old rows before DataTables
// detaches them, then initialize only the newly drawn table. Destroy also needs
// cleanup when it occurs without another draw.
DataTable.Dom.select(document).on('draw.dt', function(event, settings) {
    vmTooltips(settings.table);
});
DataTable.Dom.select(document).on('destroy.dt', function(event, settings) {
    vmDisposeTooltips(settings.table);
});
DataTable.Dom.select(document).on('xhr.dt', function(event, settings, json) {
    // A failed server-side redraw retains the old rows after preDraw cleanup.
    if (json === null) vmTooltips(settings.table);
});

//****************************************************************************
// Delegated confirmation guard for destructive submits (VIM-D07)
//****************************************************************************
//
// These confirmations used to live in inline onsubmit="return confirm('...')"
// attributes. A CSP nonce does not whitelist inline event handlers -- only
// 'unsafe-inline' did -- so with script-src nonce-only they would silently stop
// firing and every destructive action would proceed without asking. The prompt
// now travels as a data-confirm attribute and one delegated handler enforces it,
// which also covers rows the DataTables renderers build after page load.
//
// The Bootstrap modal is asynchronous, so every guarded submit is stopped
// immediately and only explicitly re-submitted after the user confirms. One
// WeakSet lets that replay pass without recursively opening another modal; the
// other blocks rapid duplicate submits while a decision is still pending.
var ossConfirmedForms = new WeakSet();
var ossPendingConfirmForms = new WeakSet();
DataTable.Dom.select( document ).on( 'submit', 'form[data-confirm]', function( event ) {
    var message = DataTable.Dom.select( this ).attr( 'data-confirm' );

    if ( typeof message !== 'string' || message === '' ) {
        return;
    }

    if ( ossConfirmedForms.has( this ) ) {
        ossConfirmedForms.delete( this );
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    var form = this;
    if ( ossPendingConfirmForms.has( form ) ) {
        return;
    }
    ossPendingConfirmForms.add( form );

    var submitter = event.submitter;
    ossConfirm( message, function( accepted ) {
        ossPendingConfirmForms.delete( form );

        if ( !accepted ) {
            return;
        }

        ossConfirmedForms.add( form );
        try {
            if ( typeof form.requestSubmit === 'function' ) {
                if ( submitter ) {
                    form.requestSubmit( submitter );
                }
                else {
                    form.requestSubmit();
                }
            }
            else {
                HTMLFormElement.prototype.submit.call( form );
            }
        }
        finally {
            ossConfirmedForms.delete( form );
        }
    } );
} );
