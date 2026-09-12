var removeDialog;
var oDataTable;


vmReady( function()
{
    oDataTable = new DataTable('#list_table', {
        'drawCallback': function(settings) {
            vm_prefs['iLength'] = settings.api.page.len();

            vmPrefsCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'pageLength': vmDataTablePageLength( {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if} ),
        'columns': [
            null,
            { 'orderable': false, "searchable": false }
        ]
    });

     DataTable.Dom.select( "a[id|='remove-domain']" ).on( 'click', removeDomain );

}); // document onready

function removeDomain( event ){

    event.preventDefault();

    if( DataTable.Dom.select( event.target ).is( "i" ) )
        element = DataTable.Dom.select( event.target ).parent();
    else
        element = DataTable.Dom.select( event.target );
    
    DataTable.Dom.select( "#purge_domain_name" ).text( element.attr( "ref" ) );

    delDialog = ossModal( '#purge_dialog' );

    var did = element.attr( 'id' ).replace( 'remove-domain-', '' );
    DataTable.Dom.select( '#remove_domain_form input[name="did"]' ).val( did );

    DataTable.Dom.select( '#purge_dialog_cancel' ).on( 'click', function(){
        delDialog.hide();
    });
};
