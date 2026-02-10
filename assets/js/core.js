/**
 * PinLightning - Main JavaScript
 *
 * @package PinLightning
 * @since 1.0.0
 */

( function() {
	'use strict';

	// Mobile menu toggle.
	const menuToggle = document.querySelector( '.menu-toggle' );
	const navigation = document.querySelector( '.main-navigation' );

	if ( menuToggle && navigation ) {
		menuToggle.addEventListener( 'click', function() {
			navigation.classList.toggle( 'toggled' );
			const expanded = menuToggle.getAttribute( 'aria-expanded' ) === 'true';
			menuToggle.setAttribute( 'aria-expanded', ! expanded );
		} );
	}
} )();
