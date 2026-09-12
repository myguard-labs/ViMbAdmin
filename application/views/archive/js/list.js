var oDataTable;

function vmArchiveServerData( source )
{
    var minimum = {if isset($options.defaults.server_side.pagination.archive.min_search_str)}{$options.defaults.server_side.pagination.archive.min_search_str}{elseif isset($options.defaults.server_side.pagination.min_search_str)}{$options.defaults.server_side.pagination.min_search_str}{else}3{/if};
    return vmDataTableServerData( source, minimum );
}

vmReady( function()
{
    {if !isset($options.defaults.server_side.pagination.archive.enable) || $options.defaults.server_side.pagination.archive.enable }
    /* Server-side processing: the full archive list is paged/sorted/searched via
       /archive/list-data, fetching only the visible page. Text cells escaped;
       action links carry the CSRF token + an inline confirm(). */
    oDataTable = new DataTable('#list_table', {
        'processing': true,
        'serverSide': true,
        'serverMethod': 'GET',
        'ajax': vmArchiveServerData( "{genUrl controller='archive' action='list-data'}" ),
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'order': [[ 4, 'desc' ]],
        'language': { 'processing': 'Loading…', 'emptyTable': 'No archives.', 'search': 'Search (prefix * to match anywhere):' },
        'drawCallback': function() {
            if( vm_prefs['iLength'] != DataTable.Dom.select( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = DataTable.Dom.select( "select[name|='list_table_length']" ).val();
            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'columns': [
            { 'data': 'username', 'render': DataTable.render.text() },
            { 'data': null, 'render': function( d, t, row ){ return vmArchiveEsc( archiveStatuses[ row.status ] || row.status ); } },
            { 'data': 'domain', 'render': DataTable.render.text() },
            // Column 3 (maildir size) has no entry in ArchiveController's
            // sortField map ([0=>'username', 1=>'status', 2=>'domain',
            // 4=>'archived_at']; no key 3), so the server's `?? 'archived_at'`
            // fallback would sort by archived date while this column painted
            // its own sort indicator -- a client/server disagreement.
            // Maildir-size sorting remains unsupported, so keep it non-orderable.
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return row.maildir_size ? vmArchiveBytes( row.maildir_size ) : '&mdash;'; } },
            { 'data': 'archived_at', 'render': function( d ){ return d ? vmArchiveEsc( d ) : '&mdash;'; } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return ( row.user_exists == 1 ) ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>'; } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return row.autoprune ? '<span class="badge text-bg-warning">On</span>' : '<span class="badge text-bg-secondary">Off</span>'; } },
            { 'data': null, 'orderable': false, 'render': function( d, t, row ){ return formatArchiveControls( row ); } }
        ]
    });
    {else}
    oDataTable = new DataTable('#list_table', {
        'drawCallback': function() {
            if( vm_prefs['iLength'] !=  DataTable.Dom.select( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = DataTable.Dom.select( "select[name|='list_table_length']" ).val();

            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        // Username, Status, Domain, Archived, User exists, Autoprune, Actions
        'columns': [
            null,
            null,
            null,
            null,
            null,
            null,
            { 'orderable': false, "searchable": false }
        ]
    });
    {/if}

    // Restore / delete / autoprune-toggle are plain links guarded by an inline
    // confirm() in the view — no modal wiring needed here.

}); // document onready

{if !isset($options.defaults.server_side.pagination.archive.enable) || $options.defaults.server_side.pagination.archive.enable }
/* Server-side render helpers (the inline rows were rendered + escaped by Smarty;
   DataTables inserts cell HTML raw, so escape any value that reaches markup). */
var archiveStatuses = { {foreach $statuses as $k => $v}'{$k}': "{$v|escape:'javascript'}"{if !$v@last}, {/if}{/foreach} };
var archiveAllowRestore = [ {foreach $allowRestore as $s}'{$s|escape:'javascript'}'{if !$s@last}, {/if}{/foreach} ];
var archiveAllowDelete  = [ {foreach $allowDelete as $s}'{$s|escape:'javascript'}'{if !$s@last}, {/if}{/foreach} ];

function vmArchiveEsc( s ){ return htmlEntity( s == null ? '' : s ); }

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
    // The confirmations are carried as data-confirm attributes and enforced by the
    // delegated guard in 990-vimbadmin.js -- an inline onsubmit attribute is not permitted under
    // the nonce-only script-src (VIM-D07). The name therefore lands in an HTML
    // attribute, not a JS string literal, so it needs attribute escaping
    // (htmlAttr() from 910-vimbadmin.functions.js, which unlike htmlEntity()
    // also escapes quotes) rather than backslash escaping.
    var jsName = htmlAttr( row.username );
    var str   = '<div class="btn-group">';

    if( archiveAllowRestore.indexOf( row.status ) != -1 )
        str += '<form method="post" action="{genUrl controller="archive" action="restore"}" class="archive-action-form" style="display: inline;"'
             + ' data-confirm="Restore ' + jsName + '? Recreates the mailbox if it was deleted, syncs the backed-up mail back, then removes the backup.">'
             + '<input type="hidden" name="arid" value="' + id + '" />'
             + '<input type="hidden" name="csrf" value="{$csrfToken}" />'
             + '<button class="btn btn-sm have-tooltip" id="restore-archive-' + id + '" title="Restore mail back into the mailbox" type="submit">'
             + '<i class="bi-arrow-repeat"></i></button></form>';

    if( archiveAllowDelete.indexOf( row.status ) != -1 )
        str += '<form method="post" action="{genUrl controller="archive" action="delete"}" class="archive-action-form" style="display: inline;"'
             + ' data-confirm="Permanently delete the backup for ' + jsName + '? This removes the /backups maildir and cannot be undone.">'
             + '<input type="hidden" name="arid" value="' + id + '" />'
             + '<input type="hidden" name="csrf" value="{$csrfToken}" />'
             + '<button class="btn btn-sm btn-danger have-tooltip" id="delete-archive-' + id + '" title="Delete backup permanently" type="submit">'
             + '<i class="bi-trash"></i></button></form>';

    if( row.autoprune )
        str += '<form method="post" action="{genUrl controller="archive" action="toggle-autoprune"}" class="archive-action-form" style="display: inline;"'
             + ' data-confirm="Disable autoprune for ' + jsName + '? Its backup will no longer expire automatically.">'
             + '<input type="hidden" name="arid" value="' + id + '" />'
             + '<input type="hidden" name="csrf" value="{$csrfToken}" />'
             + '<button class="btn btn-sm have-tooltip" id="autoprune-archive-' + id + '" title="Disable autoprune" type="submit">'
             + '<i class="bi-power"></i></button></form>';
    else
        str += '<form method="post" action="{genUrl controller="archive" action="toggle-autoprune"}" class="archive-action-form" style="display: inline;"'
             + ' data-confirm="Enable autoprune for ' + jsName + '? This resets its archived date to now, so the prune window restarts.">'
             + '<input type="hidden" name="arid" value="' + id + '" />'
             + '<input type="hidden" name="csrf" value="{$csrfToken}" />'
             + '<button class="btn btn-sm btn-warning have-tooltip" id="autoprune-archive-' + id + '" title="Enable autoprune (resets archived date to now)" type="submit">'
             + '<i class="bi-clock-history"></i></button></form>';

    str += '</div>';
    return str;
}
{/if}
