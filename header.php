<?php
/**
 * The header template.
 *
 * Sticky slim header with CSS-only hamburger menu.
 *
 * @package PinLightning
 * @since 1.0.0
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="top"></div>

<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Skip to content', 'pinlightning' ); ?></a>

<header class="site-header" role="banner">
	<div class="site-header-inner">
		<div class="site-branding">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pl-nav-logo" aria-label="<?php bloginfo( 'name' ); ?> - Home">
				<?php
				$logo_img = get_theme_mod( 'pl_logo_image', '' );
				if ( $logo_img ) : ?>
					<img src="<?php echo esc_url( $logo_img ); ?>" alt="<?php bloginfo( 'name' ); ?>" class="pl-nav-logo-custom" height="32">
				<?php else : ?>
					<span class="pl-nav-logo-icon"><?php echo esc_html( get_theme_mod( 'pl_logo_icon', "\xE2\x9A\xA1" ) ); ?></span>
					<span class="pl-nav-logo-text"><?php echo esc_html( get_theme_mod( 'pl_brand_name', 'cheerlives' ) ); ?></span>
				<?php endif; ?>
			</a>
		</div>

		<input type="checkbox" id="menu-toggle" class="menu-toggle-checkbox" aria-hidden="true">
		<label for="menu-toggle" class="menu-toggle" aria-label="<?php esc_attr_e( 'Menu', 'pinlightning' ); ?>">
			<span class="hamburger"></span>
		</label>

		<nav class="main-navigation" role="navigation" aria-label="<?php esc_attr_e( 'Primary Menu', 'pinlightning' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'menu_id'        => 'primary-menu',
				'container'      => false,
				'fallback_cb'    => false,
				'depth'          => 2,
			) );
			?>
		</nav>
	</div>
</header>

<main id="primary" role="main">
