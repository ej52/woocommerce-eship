jQuery( function($) {
  	jQuery( '#billing_address_1, #billing_city, #billing_postcode, #billing_state').change(function(){
		if ( ! $( '#ship-to-different-address-checkbox' )[0].checked )
			$( document.body ).trigger( 'update_checkout', [{update_shipping_method:true}] );
	} );

	jQuery( '#shipping_address_1, #shipping_city, #shipping_postcode, #shipping_state').change(function(){
		if ( $( '#ship-to-different-address-checkbox' )[0].checked )
			$( document.body ).trigger( 'update_checkout', [{update_shipping_method:true}] );
	} );
} );
