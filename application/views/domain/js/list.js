var delDialog;
var oDataTable;

function vmDomainServerData( source )
{
    var minimum = {if isset($options.defaults.server_side.pagination.domain.min_search_str)}{$options.defaults.server_side.pagination.domain.min_search_str}{elseif isset($options.defaults.server_side.pagination.min_search_str)}{$options.defaults.server_side.pagination.min_search_str}{else}3{/if};
    return vmDataTableServerData( source, minimum );
}


$(document).ready(function()
{
    {if !isset($options.defaults.server_side.pagination.domain.enable) || $options.defaults.server_side.pagination.domain.enable }
    /* Server-side processing: the full domain list is paged/sorted/searched via
       /domain/list-data, fetching only the visible page. Text cells escaped. */
    oDataTable = $('#list_table').dataTable({
        'processing': true,
        'serverSide': true,
        'serverMethod': 'GET',
        'ajax': vmDomainServerData( "{genUrl controller='domain' action='list-data'}" ),
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'language': { 'processing': 'Loading…', 'emptyTable': 'No domains.', 'search': 'Search (prefix * to match anywhere):' },
        'drawCallback': function() {
            $( "a[id|='modal-dialog']" ).off().on( 'click', tt_openModalDialog );
            $( '.have-tooltip' ).tooltip("destroy").tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover' } );
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'columns': [
            { 'data': 'name', 'render': $.fn.dataTable.render.text() },
            { 'data': null, 'render': function( d, t, row ){ return formatMailboxes( row.id, row.mailboxes, row.maxmailboxes ); } },
            { 'data': null, 'render': function( d, t, row ){ return formatAliases( row.id, row.aliases, row.maxaliases ); } },
            {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ var u = ( row.mailboxes_size == null ? 0 : ( row.mailboxes_size / {$multiplier} ).toFixed(1) ); return u + ' / ' + formatQuotaLimit( row.maxquota ); } },
            {/if}
            { 'data': null, 'render': function( d, t, row ){ return formatQuotaLimit( row.quota ); } },
            { 'data': null, 'render': function( d, t, row ){ return formatActive( row.id, row.active ); } },
            { 'data': 'transport', 'render': $.fn.dataTable.render.text() },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return row.backupmx ? 'Yes' : 'No'; } },
            { 'data': 'created', 'render': function( d ){ return ( d || '' ).substr( 0, 10 ); } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatControlls( row.id, row.name ); } }
        ]
    });
    {else}
    oDataTable = $('#list_table').dataTable({
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
            { 'type': 'num-html' },
            { 'type': 'num-html' },
            {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
            { 'type': 'num-html' },
            {/if}
            null,
            null,
            null,
            null,
            null,
            { 'orderable': false, "searchable": false }
        ]
    });
    {/if}

}); // document onready

function toggleActive( elid, id) {
    ossToggle( $( '#' + elid ), "{genUrl controller='domain' action='ajax-toggle-active'}", { "did": id, "csrf": "{$csrfToken}" } );
};


function purgeDomain( id, domain )
{
    $( "#purge_domain_name" ).text( domain );

    delDialog = ossModal( '#purge_dialog' );

    $( '#purge_domain_form input[name="did"]' ).val( id );

    $( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.modal('hide');
    });
};

{if !isset($options.defaults.server_side.pagination.domain.enable) || $options.defaults.server_side.pagination.domain.enable }
var timeOut = null;
var ignore_keys = [ 13, 38, 40, 37, 39 ,27, 32, 17, 18, 9, 16, 20, 36, 35, 33, 34, 144 ];
{if isset( $options.defaults.server_side.pagination.min_search_str ) }
    var str_len = {$options.defaults.server_side.pagination.min_search_str};
{else}
    var str_len = 3;
{/if}

function getEntries( event ){
    event.preventDefault();
    if( jQuery.inArray( event.which, ignore_keys ) != -1 )
        return;
     
    clearTimeout( timeOut );    
    if( String( $( event.target ).val() ).trim().length >= str_len ){ 
        timeOut = setTimeout( function(){ 
            $('body').css('cursor', 'wait');
            setTimeout( function(){
                vmDataTableApi( oDataTable ).clear().draw();
                $.ajax({
                  async: false,
                  url: "{genUrl controller='domain' action='list-search'}/search/" + String( $( event.target ).val() ).trim(),
                  success: function(data){
                    if( data !== "ko" && data.substr( 0, 1 ) == "[" )
                    {
                        data = JSON.parse( data );
                        var tableApi = vmDataTableApi( oDataTable );
                        $.each( data, function( index, row ){
                               tableApi.row.add([
                                    row.name,
                                    formatMailboxes( row.id, row.mailboxes, row.maxmailboxes ),
                                    formatAliases( row.id, row.aliases, row.maxaliases ),
                                    {if !isset($options.defaults.list_size.disabled) || !$options.defaults.list_size.disabled}
                                    ( row.mailboxes_size == null ? 0 : (row.mailboxes_size / {$multiplier}).toFixed(1) ) + ' / ' + formatQuotaLimit( row.maxquota ),
                                    {/if}
                                    formatQuotaLimit( row.quota ),
                                    formatActive( row.id, row.active ),
                                    row.transport,
                                    row.backupmx ? "Yes": "No",
                                    row.created.date.substr( 0, 10 ),
                                    formatControlls( row.id, row.name )
                         ]);
                        });
                        tableApi.draw();
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

function formatQuotaLimit( q )
{
    // 0 / null = unlimited. Otherwise a byte count -> human-readable size.
    var b = parseFloat( q );
    if( !b || b <= 0 )
        return '<span class="muted" title="Unlimited">&infin;</span>';

    var units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ], i = 0;
    while( b >= 1024 && i < units.length - 1 ) { b /= 1024; i++; }
    var r = Math.round( b * 10 ) / 10;
    return ( r === Math.floor( r ) ? r.toString() : r.toFixed( 1 ) ) + ' ' + units[ i ];
}

function formatActive( id, active )
{
    var active_class = active ? 'success': 'danger';
    var active_msg = active ? 'Yes': 'No';
    return '<div id="throb-toggle-active-' + id + '" style="float: right;"></div>'
        + '<span id="toggle-active-' + id + '" '
        + 'data-toggle-active="' + id + '" class="btn btn-sm btn-' + active_class + '">' 
        + active_msg + '</span>';
}

function formatMailboxes( id, mailboxes, maxmailboxes )
{
    var str = '<a class="btn btn-sm have-tooltip" id="add_mailbox_' + id + '" title="Add Mailbox" href="{genUrl controller="mailbox" action="add"}/did/' + id + '">\
        <i class="bi-plus-lg"></i>\
    </a>&nbsp;&nbsp;\
    <a class="ul" href="{genUrl controller="mailbox" action="list"}/did/' + id + '">' + mailboxes;
    if( maxmailboxes != 0 )
       str += '/' +maxmailboxes
    str += '</a>';
    return str;
}

function formatAliases( id, aliases, maxaliases )
{
    var str = '<a class="btn btn-sm have-tooltip" id="add_alias_' + id + '" title="Add Alias" href="{genUrl controller="alias" action="add"}/did/' + id + '">\
        <i class="bi-plus-lg"></i>\
    </a>&nbsp;&nbsp;\
    <a class="ul" href="{genUrl controller="aliases" action="list"}/did/' + id + '">' + aliases;
    if( maxaliases != 0 )
       str += '/' + maxaliases;
    str += '</a>';
    return str;
}

function formatControlls( id, name )
{
    var tmpstr = "";
    var item_id = "";
    var href = "";       
                    
    var str = '<div class="btn-group">\
            <a class="btn btn-sm have-tooltip" id="edit_domain_' + id + '" title="Edit" href="{genUrl controller="domain" action="edit"}/did/' + id + '">\
                <i class="bi-pencil"></i>\
            </a>';
    {if isset( $domain_actions ) }
        {foreach $domain_actions as $action}
            {if isset( $action.menu ) }
                {assign var="action_list_menu" value=$action}
            {else}
                str += '<{$action.tagName} ';
                    {foreach $action as $attrib => $value}
                        {if !in_array( $attrib, [ "tagName", "child"] )}
                            tmpstr = "{$value}";
                            str += '{$attrib}="' + tmpstr.replace( "%id%", id ) + '" ';
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
     
    {if $user->isSuper()}
        str += '<a class="btn btn-sm have-tooltip" id="domain_admins_' + id + '" title="Administrators" href="{genUrl controller="domain" action="admins"}/did/' + id + '">\
            <i class="bi-person"></i>\
        </a>';
    {/if}
            
    str += '<a class="btn btn-sm have-tooltip" id="domain_logs_' + id + '" title="Logs" href="{genUrl controller="log" action="list"}/did/' + id + '">\
                <i class="bi-list-ul"></i>\
            </a>';
            
     {if $user->isSuper()}
        str += '<span  class="btn btn-sm have-tooltip"  id="purge-domain-' + id + '" title="Purge" data-purge-domain="' + id + '" data-domain-name="' + htmlAttr( name ) + '">\
            <i class="bi-trash"></i>\
        </span>';
    {/if}
            
    {if isset( $action_list_menu)}
        {assign var="action" value=$action_list_menu}
        str += '<{$action.tagName} ';
            {foreach $action as $attrib => $value}
                {if !in_array( $attrib, [ "tagName", "child", "menu" ] )}
                    tmpstr = "{$value}";
                    str += '{$attrib}="' + tmpstr.replace( "%id%", id ) + '" ';
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

jQuery( document ).on( 'click', '[data-purge-domain]', function() {
    var el = jQuery( this );
    purgeDomain( el.attr( 'data-purge-domain' ), el.attr( 'data-domain-name' ) );
} );
