var oDataTable;

function vmLogServerData( source )
{
    var minimum = {if isset($options.defaults.server_side.pagination.log.min_search_str)}{$options.defaults.server_side.pagination.log.min_search_str}{elseif isset($options.defaults.server_side.pagination.min_search_str)}{$options.defaults.server_side.pagination.min_search_str}{else}3{/if};
    return vmDataTableServerData( source, minimum );
}


$(document).ready(function()
{
    {if !isset($options.defaults.server_side.pagination.log.enable) || $options.defaults.server_side.pagination.log.enable }
    /* Server-side processing: the (unbounded) log table is paged/sorted/searched
       through /log/list-data, fetching only the visible page. Cells are escaped
       (DataTables inserts cell data as raw HTML; Smarty escaped the inline rows). */
    oDataTable = $('#list_table').dataTable({
        'processing': true,
        'serverSide': true,
        'serverMethod': 'GET',
        'ajax': vmLogServerData( "{genUrl controller='log' action='list-data'}" ),
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'order': [[ {if !isset( $domain ) || !$domain}4{else}3{/if}, 'desc' ]],
        'language': { 'processing': 'Loading…', 'emptyTable': 'No log entries.', 'search': 'Search (prefix * to match anywhere):' },
        'drawCallback': function() {
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'columns': [
            { 'data': 'action',    'render': $.fn.dataTable.render.text() },
            { 'data': 'data',      'orderable': false, 'render': $.fn.dataTable.render.text() },
            { 'data': 'admin',     'render': $.fn.dataTable.render.text() },
            {if !isset( $domain ) || !$domain}
            { 'data': 'domain',    'render': $.fn.dataTable.render.text() },
            {/if}
            { 'data': 'timestamp', 'render': $.fn.dataTable.render.text() }
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
        'order': [[4, 'desc']]
    });
    {/if}
}); // document onready
