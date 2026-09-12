var removeDialog;
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
            { 'orderable': false, "searchable": false }
        ]
    });

     $( "a[id|='remove-domain']" ).on( 'click', removeDomain );

}); // document onready

function removeDomain( event ){

    event.preventDefault();

    if( $( event.target ).is( "i" ) )
        element = $( event.target ).parent();
    else
        element = $( event.target );
    
    $( "#purge_domain_name" ).text( element.attr( "ref" ) );

    delDialog = ossModal( '#purge_dialog' );

    var did = element.attr( 'id' ).replace( 'remove-domain-', '' );
    $( '#remove_domain_form input[name="did"]' ).val( did );

    $( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.hide();
    });
};
