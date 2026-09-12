var deleteDialog;
var oDataTable;

vmReady( function()
{
    oDataTable = new DataTable('#list_table', {
        'drawCallback': function() {
            if( vm_prefs['iLength'] !=  DataTable.Dom.select( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = DataTable.Dom.select( "select[name|='list_table_length']" ).val();

            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        'columns': [
            null,
            null,
            { 'orderable': false, "searchable": false }
        ]
    });

    DataTable.Dom.select( "button[id|='delete-alias']" ).on( 'click', deleteAlias );

}); // document onready

function deleteAlias( event ) {

    event.preventDefault();

    if( DataTable.Dom.select( event.target ).is( "i" ) )
        element = DataTable.Dom.select( event.target ).parent();
    else
        element = DataTable.Dom.select( event.target );

    DataTable.Dom.select( "#purge_alias_name" ).text( element.attr( 'ref' ) );

    delDialog = ossModal( '#purge_dialog' );

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
