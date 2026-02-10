/**
 * PinLightning - Core JavaScript
 *
 * Minimal JS — layout is pure CSS. This only handles non-layout enhancements.
 *
 * @package PinLightning
 * @since 1.0.0
 */

( function() {
	'use strict';

	// Close mobile menu when clicking a nav link.
	var checkbox = document.getElementById( 'menu-toggle' );
	if ( checkbox ) {
		var links = document.querySelectorAll( '.main-navigation a' );
		for ( var i = 0; i < links.length; i++ ) {
			links[ i ].addEventListener( 'click', function() {
				checkbox.checked = false;
			} );
		}
	}
} )();
