var removeDialog;
var addDialog;
var oDataTable;


$(document).ready( function()
{
    oDataTable = $( '#list_table' ).dataTable({
        'drawCallback': function() {
            if( vm_prefs['iLength'] !=  $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();

            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': vm_prefs['iLength']? vm_prefs['iLength']: {$options.defaults.table.entries},
        'columns': [
            null,
            { 'orderable': false, "searchable": false }
        ]
    });
    
    $( "button[id|='remove-admin']" ).on( 'click', removeAdmin );

}); // document onready

function removeAdmin( event ) {

    event.preventDefault();

    if( $( event.target ).is( "i" ) )
        element = $( event.target ).parent();
    else
        element = $( event.target );

    $( "#purge_admin_name" ).text( element.attr( "ref" ) );

    delDialog = ossModal( '#purge_dialog' );

    // The control is a submit button inside a CSRF-bearing POST form; the
    // dialog's confirm button submits that form so the token stays in the body.
    var targetForm = element.closest( 'form' );
    $( '#purge_dialog_delete' ).off( 'click' ).on( 'click', function( ev ){
        ev.preventDefault();
        targetForm.get( 0 ).submit();
    });

    $( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.hide();
    });
 };
