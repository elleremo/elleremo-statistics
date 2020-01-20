(function( window ){

    'use strict';

    if ( ! window.XMLHttpRequest || ! window.elleremo_statistics ) {
        return;
    }

    var xhr = window.XMLHttpRequest ?
        new XMLHttpRequest() :
        new ActiveXObject('Microsoft.XMLHTTP');

	xhr.onreadystatechange = function() {
		if ( 4 === this.readyState && 200 === this.status ) {
			var json = JSON.parse( this.responseText );
		}
	};

	xhr.open( 'POST', window.elleremo_statistics.ajax_url, true );
	xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
	xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xhr.send( 'post_id=' + window.elleremo_statistics.post_id );

} )( window );