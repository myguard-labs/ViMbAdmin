var purgeDialog;
var oDataTable;


$(document).ready( function()
{
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
            null,
            { 'orderable': false, "searchable": false }
        ]
    });

    $( "button[id|='purge-admin']" ).on( 'click', purgeAdmin );
}); // document onready


function toggleActive( elid, id ){
    ossToggle( $( '#' + elid ), "{genUrl controller='admin' action='ajax-toggle-active'}", { "aid": id, "csrf": "{$csrfToken}" } );
};

function toggleSuper( elid, id ){
    if( ossToggle( $( '#' + elid ), "{genUrl controller='admin' action='ajax-toggle-super'}", { "aid": id, "csrf": "{$csrfToken}" } ) )
        $( '#admin_domains_' + id ).hide();
    else
        $( '#admin_domains_' + id ).show();
};

function purgeAdmin( event ){
    event.preventDefault();

    if( $( event.target ).is( "i" ) )
        element = $( event.target ).parent();
    else
        element = $( event.target );

    $( "#purge_admin_name" ).text( element.attr( 'ref' ) );

    // The control is a submit button inside a CSRF-bearing POST form; the
    // dialog's confirm button submits that form so the token stays in the body.
    var targetForm = element.closest( 'form' );
    $( '#purge_dialog_delete' ).off( 'click' ).on( 'click', function( ev ){
        ev.preventDefault();
        targetForm.get( 0 ).submit();
    });

    delDialog = ossModal( '#purge_dialog' );
    
    $( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.hide();
    });
};

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

jQuery( document ).on( 'click', '[data-toggle-super]', function() {
    var id = jQuery( this ).attr( 'data-toggle-super' );
    toggleSuper( 'toggle-super-' + id, id );
} );

