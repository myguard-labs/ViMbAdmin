var oDataTable;

$(document).ready( function()
{
    {if isset($options.defaults.server_side.pagination.archive.enable) && $options.defaults.server_side.pagination.archive.enable }
    /* Server-side processing: the full archive list is paged/sorted/searched via
       /archive/list-data, fetching only the visible page. Text cells escaped;
       action links carry the CSRF token + an inline confirm(). */
    oDataTable = $( '#list_table' ).dataTable({
        'bServerProcessing': true,
        'bServerSide': true,
        'sServerMethod': 'GET',
        'sAjaxSource': "{genUrl controller='archive' action='list-data'}",
        "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
        "sPaginationType": "bootstrap",
        'iDisplayLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'aaSorting': [[ 4, 'desc' ]],
        'oLanguage': { 'sProcessing': 'Loading…', 'sEmptyTable': 'No archives.' },
        'fnDrawCallback': function() {
            $( '.have-tooltip' ).tooltip("destroy").tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover' } );
            if( vm_prefs['iLength'] != $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();
            $.jsonCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'aoColumns': [
            { 'mData': 'username', 'mRender': $.fn.dataTable.render.text() },
            { 'mData': null, 'mRender': function( d, t, row ){ return vmArchiveEsc( archiveStatuses[ row.status ] || row.status ); } },
            { 'mData': 'domain', 'mRender': $.fn.dataTable.render.text() },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return row.maildir_size ? vmArchiveBytes( row.maildir_size ) : '&mdash;'; } },
            { 'mData': 'archived_at', 'mRender': function( d ){ return d ? vmArchiveEsc( d ) : '&mdash;'; } },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return ( row.user_exists == 1 ) ? '<span class="label label-success">Yes</span>' : '<span class="label">No</span>'; } },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return row.autoprune ? '<span class="label label-warning">On</span>' : '<span class="label">Off</span>'; } },
            { 'mData': null, 'bSortable': false, 'mRender': function( d, t, row ){ return formatArchiveControls( row ); } }
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
        // Username, Status, Domain, Archived, User exists, Autoprune, Actions
        'aoColumns': [
            null,
            null,
            null,
            null,
            null,
            null,
            { 'bSortable': false, "bSearchable": false }
        ]
    });
    {/if}

    // Restore / delete / autoprune-toggle are plain links guarded by an inline
    // confirm() in the view — no modal wiring needed here.

}); // document onready

{if isset($options.defaults.server_side.pagination.archive.enable) && $options.defaults.server_side.pagination.archive.enable }
/* Server-side render helpers (the inline rows were rendered + escaped by Smarty;
   DataTables inserts cell HTML raw, so escape any value that reaches markup). */
var archiveStatuses = { {foreach $statuses as $k => $v}'{$k}': "{$v|escape:'javascript'}"{if !$v@last}, {/if}{/foreach} };
var archiveAllowRestore = [ {foreach $allowRestore as $s}'{$s|escape:'javascript'}'{if !$s@last}, {/if}{/foreach} ];
var archiveAllowDelete  = [ {foreach $allowDelete as $s}'{$s|escape:'javascript'}'{if !$s@last}, {/if}{/foreach} ];

function vmArchiveEsc( s ){ return $( '<div>' ).text( s == null ? '' : s ).html(); }

function vmArchiveBytes( v )
{
    var b = parseFloat( v );
    if( !b || b <= 0 ) return '&mdash;';
    var units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ], i = 0;
    while( b >= 1024 && i < units.length - 1 ) { b /= 1024; i++; }
    var r = Math.round( b * 10 ) / 10;
    return ( r === Math.floor( r ) ? r.toString() : r.toFixed( 1 ) ) + ' ' + units[ i ];
}

function formatArchiveControls( row )
{
    var id    = row.id;
    var jsName = String( row.username ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" );
    var str   = '<div class="btn-group">';

    if( $.inArray( row.status, archiveAllowRestore ) != -1 )
        str += '<a class="btn btn-mini have-tooltip" id="restore-archive-' + id + '" title="Restore mail back into the mailbox"'
             + ' href="{genUrl controller="archive" action="restore"}/arid/' + id + '/csrf/{$csrfToken}"'
             + ' onclick="return confirm(\'Restore ' + jsName + '? Recreates the mailbox if it was deleted, syncs the backed-up mail back, then removes the backup.\');">'
             + '<i class="icon-retweet"></i></a>';

    if( $.inArray( row.status, archiveAllowDelete ) != -1 )
        str += '<a class="btn btn-mini btn-danger have-tooltip" id="delete-archive-' + id + '" title="Delete backup permanently"'
             + ' href="{genUrl controller="archive" action="delete"}/arid/' + id + '/csrf/{$csrfToken}"'
             + ' onclick="return confirm(\'Permanently delete the backup for ' + jsName + '? This removes the /backups maildir and cannot be undone.\');">'
             + '<i class="icon-trash"></i></a>';

    if( row.autoprune )
        str += '<a class="btn btn-mini have-tooltip" id="autoprune-archive-' + id + '" title="Disable autoprune"'
             + ' href="{genUrl controller="archive" action="toggle-autoprune"}/arid/' + id + '/csrf/{$csrfToken}"'
             + ' onclick="return confirm(\'Disable autoprune for ' + jsName + '? Its backup will no longer expire automatically.\');">'
             + '<i class="icon-off"></i></a>';
    else
        str += '<a class="btn btn-mini btn-warning have-tooltip" id="autoprune-archive-' + id + '" title="Enable autoprune (resets archived date to now)"'
             + ' href="{genUrl controller="archive" action="toggle-autoprune"}/arid/' + id + '/csrf/{$csrfToken}"'
             + ' onclick="return confirm(\'Enable autoprune for ' + jsName + '? This resets its archived date to now, so the prune window restarts.\');">'
             + '<i class="icon-time"></i></a>';

    str += '</div>';
    return str;
}
{/if}
