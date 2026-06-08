var oDataTable;


$(document).ready(function()
{
    {if isset($options.defaults.server_side.pagination.log.enable) && $options.defaults.server_side.pagination.log.enable }
    /* Server-side processing: the (unbounded) log table is paged/sorted/searched
       through /log/list-data, fetching only the visible page. Cells are escaped
       (DataTables inserts cell data as raw HTML; Smarty escaped the inline rows). */
    oDataTable = $('#list_table').dataTable({
        'bServerProcessing': true,
        'bServerSide': true,
        'sServerMethod': 'GET',
        'sAjaxSource': "{genUrl controller='log' action='list-data'}",
        "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
        "sPaginationType": "bootstrap",
        'iDisplayLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'aaSorting': [[ {if !isset( $domain ) || !$domain}4{else}3{/if}, 'desc' ]],
        'oLanguage': { 'sProcessing': 'Loading…', 'sEmptyTable': 'No log entries.' },
        'fnDrawCallback': function() {
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            $.jsonCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'aoColumns': [
            { 'mData': 'action',    'mRender': $.fn.dataTable.render.text() },
            { 'mData': 'data',      'bSortable': false, 'mRender': $.fn.dataTable.render.text() },
            { 'mData': 'admin',     'mRender': $.fn.dataTable.render.text() },
            {if !isset( $domain ) || !$domain}
            { 'mData': 'domain',    'mRender': $.fn.dataTable.render.text() },
            {/if}
            { 'mData': 'timestamp', 'mRender': $.fn.dataTable.render.text() }
        ]
    });
    {else}
    oDataTable = $('#list_table').dataTable({
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
        'aaSorting': [[4, 'desc']]
    });
    {/if}
}); // document onready
