( function () {
	document.querySelectorAll( '[data-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var node = document.getElementById( button.getAttribute( 'data-copy' ) );
			if ( ! node ) {
				return;
			}
			var text = node.textContent || '';
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text );
			}
			button.textContent = 'コピーしました';
		} );
	} );
}() );
