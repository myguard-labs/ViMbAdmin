var purgeDialog;
var oDataTable;

$(document).ready( function() {

    {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
        $( "a[id|='dir-size']" ).bind( "click", showSizes );
    {/if}
    
    {if isset($options.defaults.server_side.pagination.enable) && $options.defaults.server_side.pagination.enable }
    /* Server-side processing: the browser pages / sorts / searches the FULL list
       through /mailbox/list-data, fetching only the visible page — the initial
       HTML carries no rows. Cells are rendered client-side by the same format
       helpers used below. */
    oDataTable = $( '#list_table' ).dataTable({
        'bServerProcessing': true,
        'bServerSide': true,
        'sServerMethod': 'GET',
        'sAjaxSource': "{genUrl controller='mailbox' action='list-data'}",
        "sPaginationType": "bootstrap",
        "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
        'iDisplayLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'oLanguage': { 'sProcessing': 'Loading…', 'sEmptyTable': 'No mailboxes.' },
        'fnDrawCallback': function() {
            {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
                $( "a[id|='dir-size']" ).unbind().bind( "click", showSizes );
            {/if}
            $( "a[id|='modal-dialog']" ).unbind().bind( 'click', tt_openModalDialog );
            $( '.have-tooltip' ).tooltip("destroy").tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover' } );
            $( '.oss-dropdown' ).each( ossDropdown );
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            $.jsonCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'aoColumns': [
            { 'mData': 'username' },
            { 'mData': 'name' },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return formatUsedQuota( row.id, row.quota_bytes, row.quota ); } },
            { 'mData': 'last_login', 'bSortable': false, 'mRender': function( d ){ return formatLastLogin( d ); } },
            {if !isset($options.defaults.list_domain.disabled) || !$options.defaults.list_domain.disabled}
            { 'mData': 'domain' },
            {/if}
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return formatActive( row.id, row.active ); } },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return formatControlls( row.id ); } }
        ]
    });
    {else}
    oDataTable = $( '#list_table' ).dataTable({
        'fnDrawCallback': function() {
            if( vm_prefs['iLength'] !=  $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            $.jsonCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'iDisplayLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},

        "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
        "sPaginationType": "bootstrap",

        'aoColumns': [
            null,
            null,
            { 'sType': 'num-html' },
            { "bSearchable": false },
            {if !isset($options.defaults.list_domain.disabled) || !$options.defaults.list_domain.disabled}
            null,
            {/if}
            { "bSearchable": false },
            { 'bSortable': false, "bSearchable": false }
        ]
    });
    {/if}
    

}); // document onready

function toggleActive(elid, id) {
    ossToggle( $( '#' + elid ), "{genUrl controller='mailbox' action='ajax-toggle-active'}", { "mid": id } );
};

{if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
    function showSizes( event ) {
        event.preventDefault();
        // data-sizes layout (Dovecot quota-clone): bytes|multiplier|size_multiplier|quota_limit|messages
        data = $( event.target ).attr( 'data-sizes' ).split( '|' );
        mdirsize = data[0] / data[1];
        msg =  "<table class=\"table\"><thead>";
        msg += "<tr><th>Source:</th><td>Live (Dovecot quota-clone)</td></tr></thead>";
        msg += "<tr><th>Mailbox size:</th><td> " + mdirsize.toFixed( 5 ) + data[2];
        if( data[3] != 0 )
        {
            prc = 100 / data[3] * data[0];
            msg += " (" + prc.toFixed(0) + "%)";
        }
        msg += "</td></tr>";
        // data[4] = message count
        if( data[4] !== undefined && data[4] !== '' )
            msg += "<tr><th>Messages:</th><td> " + data[4] + "</td></tr>";
        msg += "</table>";
        bootbox.alert( msg );
    }
{/if}

{if isset($options.defaults.server_side.pagination.enable) && $options.defaults.server_side.pagination.enable }

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
        
        if( $.trim( $( event.target ).val() ).length >= str_len )
        { 
            timeOut = setTimeout( function() { 
                $('body').css('cursor', 'wait');
                setTimeout( function() {
                    oDataTable.fnClearTable();
                    $.ajax({
                      async: false,
                      url: "{genUrl controller='mailbox' action='list-search'}/search/" + $.trim( $( event.target ).val() ),
                      success: function(data){
                        if( data !== "ko" && data.substr( 0, 1 ) == "[" )
                        {
                            data = jQuery.parseJSON( data );
                            $.each( data, function( index, row ){
                                   oDataTable.fnAddData([
                                        row.username,
                                        row.name,
                                        formatUsedQuota( row.id, row.quota_bytes, row.quota ),
                                        formatLastLogin( row.last_login ),
                                        row.domain,
                                        formatActive( row.id, row.active ),
                                        formatControlls( row.id )
                             ]);
                            });
                        }
                      }
                    });
                    $('body').css('cursor', 'default');
                }, 300);
            }, 500 );
        }
        else
        {
            oDataTable.fnClearTable();
        }
    }

    function formatActive( id, active )
    {
        var active_class = active ? 'success': 'danger';
        var active_msg = active ? 'Yes': 'No';
        return '<div id="throb-toggle-active-' + id + '" style="float: right;"></div>\
        <span id="toggle-active-' +id + '" onclick="toggleActive( \'toggle-active-' + id +  '\', ' + id +  ' );" class="btn btn-mini btn-' + active_class + '">' + active_msg + '</span>';
    }

    function formatControlls( id )
    {
        var tmpstr = "";
        var item_id = "";
        var href = "";
        var str = '<div class="btn-group">\
                <a class="btn btn-mini have-tooltip" id="edit_mailbox_' + id + '" title="Edit" href="{genUrl controller="mailbox" action="edit"}/mid/' + id + '">\
                    <i class="icon-pencil"></i>\
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
                
        str += '<a class="btn btn-mini have-tooltip" id="password_' + id + '" title="Password" href="{genUrl controller="mailbox" action="password"}/mid/' + id + '">\
                    <i class="icon-lock"></i>\
                </a>\
                <a class="btn btn-mini have-tooltip" id="mailbox_aliases_' + id + '" title="List Aliases" href="{genUrl controller="mailbox" action="aliases"}/mid/' + id + '">\
                    <i class="icon-random"></i>\
                </a>\
                <a class="btn btn-mini have-tooltip" id="modal-dialog-mailbox_settings_' + id + '" title="Send Settings" href="{genUrl controller="mailbox" action="email-settings"}/mid/' + id + '">\
                    <i class="icon-envelope"></i>\
                </a>\
                <a class="btn btn-mini have-tooltip" id="repair_' + id + '" title="Repair / optimize (queued)" href="{genUrl controller="mailbox" action="queue-repair"}/mid/' + id + '/csrf/{$csrfToken}">\
                    <i class="icon-wrench"></i>\
                </a>\
                <a class="btn btn-mini have-tooltip" id="archive_' + id + '" title="Archive (queued: backup + empty mailbox, keep account)" href="{genUrl controller="mailbox" action="queue-archive"}/mid/' + id + '/csrf/{$csrfToken}">\
                    <i class="icon-inbox"></i>\
                </a>\
                <a class="btn btn-mini have-tooltip btn-danger" id="delete_' + id + '" title="Delete (queued: backup, then remove mailbox + account)" href="{genUrl controller="mailbox" action="queue-delete"}/mid/' + id + '/csrf/{$csrfToken}">\
                    <i class="icon-trash"></i>\
                </a>';
                
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
                    <ul class="dropdown-menu pull-right">';
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
