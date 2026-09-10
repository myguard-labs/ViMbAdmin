var purgeDialog;
var oDataTable;

function vmMailboxServerData( source )
{
    var minimum = {if isset($options.defaults.server_side.pagination.min_search_str)}{$options.defaults.server_side.pagination.min_search_str}{else}3{/if};
    return vmDataTableServerData( source, minimum );
}

$(document).ready( function() {

    {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
        $( "a[id|='dir-size']" ).on( "click", showSizes );
    {/if}
    
    {if !isset($options.defaults.server_side.pagination.enable) || $options.defaults.server_side.pagination.enable }
    /* Server-side processing: the browser pages / sorts / searches the FULL list
       through /mailbox/list-data, fetching only the visible page — the initial
       HTML carries no rows. Cells are rendered client-side by the same format
       helpers used below. */
    oDataTable = $( '#list_table' ).dataTable({
        'processing': true,
        'serverSide': true,
        'serverMethod': 'GET',
        'ajax': vmMailboxServerData( "{genUrl controller='mailbox' action='list-data'}" ),
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'language': { 'processing': 'Loading…', 'emptyTable': 'No mailboxes.', 'search': 'Search (prefix * to match anywhere):' },
        'drawCallback': function() {
            {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
                $( "a[id|='dir-size']" ).off().on( "click", showSizes );
            {/if}
            $( "a[id|='modal-dialog']" ).off().on( 'click', tt_openModalDialog );
            $( '.have-tooltip' ).tooltip("destroy").tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover' } );
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'columns': [
            { 'data': 'username', 'render': $.fn.dataTable.render.text() },
            { 'data': 'name',     'render': $.fn.dataTable.render.text() },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatUsedQuota( row.id, row.quota_bytes, row.quota ); } },
            { 'data': 'last_login', 'orderable': false, 'render': function( d ){ return formatLastLogin( d ); } },
            {if !isset($options.defaults.list_domain.disabled) || !$options.defaults.list_domain.disabled}
            { 'data': 'domain', 'render': $.fn.dataTable.render.text() },
            {/if}
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatActive( row.id, row.active ); } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatControlls( row.id ); } }
        ]
    });
    {else}
    oDataTable = $( '#list_table' ).dataTable({
        'drawCallback': function() {
            if( vm_prefs['iLength'] !=  $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},


        'columns': [
            null,
            null,
            { 'type': 'num-html' },
            { "searchable": false },
            {if !isset($options.defaults.list_domain.disabled) || !$options.defaults.list_domain.disabled}
            null,
            {/if}
            { "searchable": false },
            { 'orderable': false, "searchable": false }
        ]
    });
    {/if}
    

}); // document onready

function toggleActive(elid, id) {
    ossToggle( $( '#' + elid ), "{genUrl controller='mailbox' action='ajax-toggle-active'}", { "mid": id, "csrf": "{$csrfToken}" } );
};

{if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
    function showSizes( event ) {
        event.preventDefault();
        // data-sizes layout (Dovecot quota-clone): bytes|multiplier|size_multiplier|quota_limit|messages
        data = $( event.target ).attr( 'data-sizes' ).split( '|' );
        mdirsize = data[0] / data[1];
        msg =  "<table class=\"table\"><thead>";
        msg += "<tr><th>Source:</th><td>Live (Dovecot quota-clone)</td></tr></thead>";
        msg += "<tr><th>Mailbox size:</th><td> " + htmlEntity( mdirsize.toFixed( 5 ) ) + htmlEntity( data[2] );
        if( data[3] != 0 )
        {
            prc = 100 / data[3] * data[0];
            msg += " (" + htmlEntity( prc.toFixed(0) ) + "%)";
        }
        msg += "</td></tr>";
        // data[4] = message count
        if( data[4] !== undefined && data[4] !== '' )
            msg += "<tr><th>Messages:</th><td> " + htmlEntity( data[4] ) + "</td></tr>";
        msg += "</table>";
        bootbox.alert( msg );
    }
{/if}

{if !isset($options.defaults.server_side.pagination.enable) || $options.defaults.server_side.pagination.enable }

    var timeOut = null;
    var ignore_keys = [ 13, 38, 40, 37, 39 ,27, 32, 17, 18, 9, 16, 20, 36, 35, 33, 34, 144 ];

    {if isset( $options.defaults.server_side.pagination.min_search_str ) }
        var str_len = {$options.defaults.server_side.pagination.min_search_str};
    {else}
        var str_len = 3;
    {/if}

    function getEntries( event ) {
        event.preventDefault();

        if( jQuery.inArray( event.which, ignore_keys ) != -1 )
            return;
         
        clearTimeout( timeOut );    
        
        if( String( $( event.target ).val() ).trim().length >= str_len )
        { 
            timeOut = setTimeout( function() { 
                $('body').css('cursor', 'wait');
                setTimeout( function() {
                    vmDataTableApi( oDataTable ).clear().draw();
                    $.ajax({
                      async: false,
                      url: "{genUrl controller='mailbox' action='list-search'}/search/" + String( $( event.target ).val() ).trim(),
                      success: function(data){
                        if( data !== "ko" && data.substr( 0, 1 ) == "[" )
                        {
                            data = JSON.parse( data );
                            $.each( data, function( index, row ){
                                   vmDataTableApi( oDataTable ).row.add([
                                        row.username,
                                        row.name,
                                        formatUsedQuota( row.id, row.quota_bytes, row.quota ),
                                        formatLastLogin( row.last_login ),
                                        row.domain,
                                        formatActive( row.id, row.active ),
                                        formatControlls( row.id )
                             ]);
                            });
                            vmDataTableApi( oDataTable ).draw();
                        }
                      }
                    });
                    $('body').css('cursor', 'default');
                }, 300);
            }, 500 );
        }
        else
        {
            vmDataTableApi( oDataTable ).clear().draw();
        }
    }

    function formatActive( id, active )
    {
        var active_class = active ? 'success': 'danger';
        var active_msg = active ? 'Yes': 'No';
        return '<div id="throb-toggle-active-' + id + '" style="float: right;"></div>\
        <span id="toggle-active-' +id + '" data-toggle-active="' + id + '" class="btn btn-sm btn-' + active_class + '">' + active_msg + '</span>';
    }

    function formatControlls( id )
    {
        var tmpstr = "";
        var item_id = "";
        var href = "";
        var str = '<div class="btn-group">\
                <a class="btn btn-sm have-tooltip" id="edit_mailbox_' + id + '" title="Edit" href="{genUrl controller="mailbox" action="edit"}/mid/' + id + '">\
                    <i class="bi-pencil"></i>\
                </a>';
                {if isset( $mailbox_actions ) }
                    {foreach $mailbox_actions as $action}
                        {if isset( $action.menu ) }
                            {assign var="action_list_menu" value=$action}
                        {else}
                            str += '<{$action.tagName} ';
                                {foreach $action as $attrib => $value}
                                    {if !in_array( $attrib, [ "tagName", "child"] )}
                                        tmpstr = "{$value}";
                                        str += '{$attrib}="' + tmpstr.replace( "%id%",id ) + '" ';
                                    {/if}
                             {/foreach}
                             str += '>';
                            {if !is_array( $action.child ) }
                                str += '{$action.child}';
                            {else}
                                str += '<{$action.child.tagName} {foreach $action.child as $attrib => $value}{if $attrib != "tagName"}{$attrib}="{$value}" {/if}{/foreach} {if $action.child.tagName != "img"}></{$action.child.tagName}>{else}/>{/if}';
                            {/if}
                            str += '</{$action.tagName}>';
                        {/if}
                    {/foreach}
                {/if}
                
        str += '<a class="btn btn-sm have-tooltip" id="password_' + id + '" title="Password" href="{genUrl controller="mailbox" action="password"}/mid/' + id + '">\
                    <i class="bi-lock"></i>\
                </a>\
                <a class="btn btn-sm have-tooltip" id="mailbox_aliases_' + id + '" title="List Aliases" href="{genUrl controller="mailbox" action="aliases"}/mid/' + id + '">\
                    <i class="bi-shuffle"></i>\
                </a>\
                <a class="btn btn-sm have-tooltip" id="modal-dialog-mailbox_settings_' + id + '" title="Send Settings" href="{genUrl controller="mailbox" action="email-settings"}/mid/' + id + '">\
                    <i class="bi-envelope"></i>\
                </a>\
                <form method="post" action="{genUrl controller="mailbox" action="queue-repair"}" class="queue-task-form btn-group" style="display: inline-block; margin: 0;">\
                    <input type="hidden" name="mid" value="' + id + '" />\
                    <input type="hidden" name="csrf" value="{$csrfToken}" />\
                    <button class="btn btn-sm have-tooltip" id="repair_' + id + '" title="Repair / optimize (queued)" type="submit">\
                        <i class="bi-wrench"></i>\
                    </button>\
                </form>\
                <form method="post" action="{genUrl controller="mailbox" action="queue-archive"}" class="queue-task-form btn-group" style="display: inline-block; margin: 0;" data-confirm="Archive this mailbox? Backs up + empties the mailbox, keeps the account.">\
                    <input type="hidden" name="mid" value="' + id + '" />\
                    <input type="hidden" name="csrf" value="{$csrfToken}" />\
                    <button class="btn btn-sm have-tooltip" id="archive_' + id + '" title="Archive (queued: backup + empty mailbox, keep account)" type="submit">\
                        <i class="bi-archive"></i>\
                    </button>\
                </form>\
                <form method="post" action="{genUrl controller="mailbox" action="queue-delete"}" class="queue-task-form btn-group" style="display: inline-block; margin: 0;" data-confirm="DELETE this mailbox? Backs up, then removes the mail AND the account. This cannot be undone from here.">\
                    <input type="hidden" name="mid" value="' + id + '" />\
                    <input type="hidden" name="csrf" value="{$csrfToken}" />\
                    <button class="btn btn-sm have-tooltip btn-danger" id="delete_' + id + '" title="Delete (queued: backup, then remove mailbox + account)" type="submit">\
                        <i class="bi-trash"></i>\
                    </button>\
                </form>';
                
                {if isset( $action_list_menu)}
                    {assign var="action" value=$action_list_menu}
                    str += '<{$action.tagName} ';
                        {foreach $action as $attrib => $value}
                            {if !in_array( $attrib, [ "tagName", "child", "menu" ] )}
                                tmpstr = "{$value}";
                                str += '{$attrib}="' + tmpstr.replace( "%id%",id ) + '" ';
                           {/if}
                        {/foreach}
                    str += '>';
                    {if !is_array( $action.child ) }
                        str += '{$action.child}';
                    {else}
                        str += '<{$action.child.tagName} {foreach $action.child as $attrib => $value}{if $attrib != "tagName"}{$attrib}="{$value}" {/if}{/foreach} {if $action.child.tagName != "img"}></{$action.child.tagName}>{else}/>{/if}';
                    {/if}
                    str += '<span class="caret"></span>\
                    </{$action.tagName}>\
                    <ul class="dropdown-menu dropdown-menu-end">';
                    {foreach $action.menu as $item}
                        str += '<li><a ';
                        {if isset( $item.id)}
                            item_id = "{$item.id}";
                            str += 'id="' + item_id.replace( '%id%', id ) + '" ';
                        {/if}
                        href = '{$item.url}';
                        str += 'href="' + href.replace( '%id%', id ) + '" ';
                        str+= '>{$item.text}</a></li>';
                    {/foreach}
                    str+= '</ul>';
                {/if}
        str += '</div>';
        return str;
        
    }

    // bytes -> human-readable size (binary units); 0/null shown by caller.
    function fmtBytes( v )
    {
        var b = parseFloat( v );
        if( !b || b <= 0 ) return '0 B';
        var units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ], i = 0;
        while( b >= 1024 && i < units.length - 1 ) { b /= 1024; i++; }
        var r = Math.round( b * 10 ) / 10;
        return ( r === Math.floor( r ) ? r.toString() : r.toFixed( 1 ) ) + ' ' + units[ i ];
    }

    // "Used / Quota" cell, click -> mailbox edit. quota 0/null = unlimited.
    function formatUsedQuota( id, quota_bytes, quota )
    {
        var used  = ( quota_bytes !== undefined && quota_bytes !== null && parseFloat( quota_bytes ) > 0 )
                  ? fmtBytes( quota_bytes ) : '0 B';
        var limit = ( quota && parseFloat( quota ) > 0 )
                  ? fmtBytes( quota )
                  : '<span class="muted" title="Unlimited">&infin;</span>';
        return '<a href="{genUrl controller="mailbox" action="edit"}/mid/' + id
             + '" title="Edit mailbox / quota">' + used + ' / ' + limit + '</a>';
    }

    // Unix timestamp -> "YYYY-MM-DD HH:MM"; null/0 = never.
    function formatLastLogin( ts )
    {
        var t = parseInt( ts, 10 );
        if( !t || t <= 0 )
            return '<span class="muted">never</span>';
        var d = new Date( t * 1000 );
        function p( n ){ return ( n < 10 ? '0' : '' ) + n; }
        return d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() )
             + ' ' + p( d.getHours() ) + ':' + p( d.getMinutes() );
    }

{/if}

//
// Delegated event bindings (VIM-D07). These replace inline onclick attributes,
// which a CSP nonce does not whitelist -- only 'unsafe-inline' did. Delegation
// from `document` also covers the rows the DataTables renderers build after page
// load, which per-element binding at ready-time would miss.
//
jQuery( document ).on( 'click', '[data-toggle-active]', function() {
    var id = jQuery( this ).attr( 'data-toggle-active' );
    toggleActive( 'toggle-active-' + id, id );
} );


//
// Email-settings modal behaviour (VIM-D07). This used to be an inline <script>
// inside the ajax-loaded mailbox/native-email-settings.phtml fragment. A nonce
// cannot work there: the fragment is fetched by a separate request and injected
// into this already-loaded page, so it would have to carry THIS response's
// nonce, which it cannot know. The fragment now ships no script at all and the
// behaviour lives here, bound by delegation so it applies to the markup the
// moment it is injected. The POST target is read from the form's own action
// attribute rather than templated in, so no per-fragment data is needed.
//
jQuery( document ).on( 'change', '#type', function() {
    if( jQuery( this ).val() == "other" )
        jQuery( '#other_email' ).slideDown( "slow" );
    else
        jQuery( '#other_email' ).slideUp( "slow" );
} );

jQuery( document ).on( 'click', '#modal_dialog_save', function() {
    var form = jQuery( '#email_settings_form' );
    if( form.length === 0 )
        return;

    tt_throbber( 32, 14, 1.8 ).appendTo( jQuery( '#esfooter' ).get(0) ).start();

    jQuery('#modal_dialog_save').prop('disabled', true ).addClass( 'disabled' );
    jQuery('#modal_dialog_cancel').prop('disabled', true ).addClass( 'disabled' );

    jQuery.ajax({
        url: form.attr( 'action' ),
        data: form.serialize(),
        async: true,
        cache: false,
        type: 'POST',
        timeout: 10000,
        success: function(data) {
            if( data == "ok" ) {
                dialog.modal('hide');
                location.reload();
            }
            else if( data == "error" ) {
                dialog.modal('hide');
                location.reload();
            }
            else if( data.substring(0, 26) == '<div class="modal-header">' ){
                jQuery('#modal_dialog').html( data );
            }
            else {
                dialog.modal('hide');
                ossAddMessage( 'An unexpected error has occurred.', 'danger' );
            }
        },
        error: ossAjaxErrorHandler
    });
} );

// The fragment's Close button; previously bound inline on re-render only.
jQuery( document ).on( 'click', '#modal_dialog_cancel', function() {
    if( typeof dialog !== 'undefined' && dialog )
        dialog.modal('hide');
} );
