var oDataTable;
var deleteDialog;

function vmAliasServerData( source )
{
    var minimum = {if isset($options.defaults.server_side.pagination.min_search_str)}{$options.defaults.server_side.pagination.min_search_str}{else}3{/if};
    return vmDataTableServerData( source, minimum );
}


vmReady( function()
{
    DataTable.Dom.select( document ).on( 'click', "button[id|='delete-alias']", deleteAlias );

    {if !isset($options.defaults.server_side.pagination.enable) || $options.defaults.server_side.pagination.enable }
    /* Server-side processing: the full alias list is paged/sorted/searched via
       /alias/list-data, fetching only the visible page. Text cells escaped. */
    oDataTable = new DataTable('#list_table', {
        'processing': true,
        'serverSide': true,
        'serverMethod': 'GET',
        'ajax': vmAliasServerData( "{genUrl controller='alias' action='list-data' ima=$ima}" ),
        'pageLength': vmDataTablePageLength( {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if} ),
        'language': { 'processing': 'Loading…', 'emptyTable': 'No aliases.', 'search': 'Search (prefix * to match anywhere):' },
        'drawCallback': function(settings) {
            vm_prefs['iLength'] = settings.api.page.len();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'columns': [
            { 'data': 'address', 'render': DataTable.render.text() },
            { 'data': 'domain',  'render': DataTable.render.text() },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatActive( row.id, row.active ); } },
            { 'data': 'goto', 'orderable': false, 'render': function( d, t, row ){ return formatGoto( row.id, row.goto ); } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatControlls( row.id ); } }
        ]
    });
    {else}
    oDataTable = new DataTable('#list_table', {
        'drawCallback': function(settings) {
            vm_prefs['iLength'] = settings.api.page.len();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': vmDataTablePageLength( {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if} ),
        'columns': [
            null,
            null,
            null,
            null,
            { 'orderable': false, "searchable": false }
        ]
    });
    {/if}

}); // document onready

function toggleActive( elid, id ){
    ossToggle( DataTable.Dom.select( '#' + elid ), "{genUrl controller='alias' action='ajax-toggle-active'}", { "alid": id, "csrf": "{$csrfToken}" } );
};



function deleteAlias( event ){
    event.preventDefault();

    delDialog = ossModal( '#purge_dialog' );

    if( DataTable.Dom.select( event.target ).is( "i" ) )
        element = DataTable.Dom.select( event.target ).parent();
    else
        element = DataTable.Dom.select( event.target );

    // The control is a submit button inside a CSRF-bearing POST form; the
    // dialog's confirm button submits that form so the token stays in the body.
    var targetForm = element.closest( 'form' );
    DataTable.Dom.select( '#purge_dialog_delete' ).off( 'click' ).on( 'click', function( ev ){
        ev.preventDefault();
        targetForm.get( 0 ).submit();
    });

    DataTable.Dom.select( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.hide();
    });
};

{if !isset($options.defaults.server_side.pagination.enable) || $options.defaults.server_side.pagination.enable }
var timeOut = null;
var ignore_keys = [ 13, 38, 40, 37, 39 ,27, 32, 17, 18, 9, 16, 20, 36, 35, 33, 34, 144 ];
{if isset( $options.defaults.server_side.pagination.min_search_str ) }
    var str_len = {$options.defaults.server_side.pagination.min_search_str};
{else}
    var str_len = 3;
{/if}

function getEntries( event ){
    event.preventDefault();
    if( ignore_keys.indexOf( event.which ) != -1 )
        return;

    clearTimeout( timeOut );
    if( String( DataTable.Dom.select( event.target ).val() ).trim().length >= str_len ){
        timeOut = setTimeout( function(){
            DataTable.Dom.select('body').css('cursor', 'wait');
            setTimeout( function(){
                vmDataTableApi( oDataTable ).clear().draw();
                ossAjax({
                  async: false,
                  url: "{genUrl controller='alias' action='list-search' ima=$ima}/search/" + String( DataTable.Dom.select( event.target ).val() ).trim(),
                  success: function(data){
                    if( data !== "ko" && data.substr( 0, 1 ) == "[" )
                    {
                        data = JSON.parse( data );
                        var tableApi = vmDataTableApi( oDataTable );
                        data.forEach( function( row ){
                               tableApi.row.add([
                                    row.address,
                                    row.domain,
                                    formatActive( row.id, row.active ),
                                    formatGoto( row.id, row.goto ),
                                    formatControlls( row.id )
                         ]);
                        });
                        tableApi.draw();
                    }
                  }
                });
                DataTable.Dom.select('body').css('cursor', 'default');
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

function formatGoto( id, goto )
{
    var element = document.createElement( 'div' );
    var visibleGoto = goto;

    element.id = 'alias-goto-' + id;
    if( goto.length  > 50 )
    {
        element.title = goto.replace( /[,]/g, ", " );
        visibleGoto = goto.substr( 0, 50 ) + '...';
    }
    element.textContent = visibleGoto;

    return element.outerHTML;
}

function formatControlls( id )
{
    var tmpstr = "";
    var item_id = "";
    var href = "";


    var str = '<div class="btn-group">\
            <a class="btn btn-sm have-tooltip" id="edit_alias_' + id + '" title="Edit" href="{genUrl controller="alias" action="edit"}/alid/' + id + '">\
                <i class="bi-pencil"></i>\
            </a>';
            {if isset( $alias_actions ) }
                {foreach $alias_actions as $action}
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

    str += '<form method="post" action="{genUrl controller="alias" action="delete"}" class="delete-alias-form" style="display: inline;">\
                <input type="hidden" name="alid" value="' + id + '" />\
                <input type="hidden" name="csrf" value="{$csrfToken}" />\
                <button class="btn btn-sm have-tooltip" id="delete-alias-' + id + '" title="Delete" type="submit">\
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
{/if}

//
// Delegated event bindings (VIM-D07). These replace inline onclick attributes,
// which a CSP nonce does not whitelist -- only 'unsafe-inline' did. Delegation
// from `document` also covers the rows the DataTables renderers build after page
// load, which per-element binding at ready-time would miss.
//
DataTable.Dom.select( document ).on( 'click', '[data-toggle-active]', function() {
    var id = DataTable.Dom.select( this ).attr( 'data-toggle-active' );
    toggleActive( 'toggle-active-' + id, id );
} );
