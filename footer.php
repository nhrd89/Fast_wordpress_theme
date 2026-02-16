<?php
/**
 * The footer template.
 *
 * 4-column footer with brand, categories, about, and legal links.
 * Customizable via Appearance > Customize > Homepage > Footer.
 *
 * @package PinLightning
 * @since 1.0.0
 */

$footer_brand_name = get_theme_mod( 'pl_brand_name', 'cheerlives' );
$footer_logo_icon  = get_theme_mod( 'pl_logo_icon', "\xE2\x9A\xA1" );
$footer_logo_image = get_theme_mod( 'pl_logo_image', '' );
$footer_desc       = get_theme_mod( 'pl_footer_desc', 'Your daily destination for curated style, home design, beauty, and lifestyle inspiration from Pinterest and beyond.' );
$footer_copyright  = str_replace( '{year}', gmdate( 'Y' ), get_theme_mod( 'pl_footer_copyright', '{year} Cheerlives. All rights reserved.' ) );
$footer_tagline    = get_theme_mod( 'pl_footer_tagline', "Made with \xE2\x9A\xA1 by PinLightning" );
$footer_bg         = get_theme_mod( 'pl_footer_bg', '#111111' );
$footer_accent     = get_theme_mod( 'pl_accent_color', '#e84393' );
$footer_accent2    = get_theme_mod( 'pl_accent_color_2', '#6c5ce7' );

$footer_cat_heading   = get_theme_mod( 'pl_footer_cat_heading', 'Categories' );
$footer_cat_count     = get_theme_mod( 'pl_footer_cat_count', 6 );
$footer_about_heading = get_theme_mod( 'pl_footer_about_heading', 'About' );
$footer_legal_heading = get_theme_mod( 'pl_footer_legal_heading', 'Legal' );

$footer_socials = array(
	'pinterest' => array( 'label' => 'Pinterest' ),
	'instagram' => array( 'label' => 'Instagram' ),
	'twitter'   => array( 'label' => 'Twitter' ),
	'facebook'  => array( 'label' => 'Facebook' ),
	'tiktok'    => array( 'label' => 'TikTok' ),
);
?>
</main><!-- #primary -->

<footer class="pl-footer" role="contentinfo" style="background:<?php echo esc_attr( $footer_bg ); ?>">
	<div class="pl-container">
		<div class="pl-footer-grid">
			<!-- Brand -->
			<div class="pl-footer-brand">
				<div class="pl-footer-logo">
					<?php if ( $footer_logo_image ) : ?>
						<img src="<?php echo esc_url( $footer_logo_image ); ?>" alt="<?php echo esc_attr( $footer_brand_name ); ?>" style="height:28px;width:auto">
					<?php else : ?>
						<span class="pl-footer-logo-icon" style="background:linear-gradient(135deg,<?php echo esc_attr( $footer_accent ); ?>,<?php echo esc_attr( $footer_accent2 ); ?>)"><?php echo esc_html( $footer_logo_icon ); ?></span>
						<span class="pl-footer-logo-text"><?php echo esc_html( $footer_brand_name ); ?></span>
					<?php endif; ?>
				</div>
				<p class="pl-footer-about"><?php echo esc_html( $footer_desc ); ?></p>
				<div class="pl-footer-social">
					<?php foreach ( $footer_socials as $key => $social ) :
						$url = get_theme_mod( "pl_social_{$key}", '' );
						if ( empty( $url ) ) :
							// Fall back to legacy theme_mod keys for pinterest/instagram/facebook/twitter.
							$legacy_map = array(
								'pinterest' => 'pinlightning_pinterest_url',
								'instagram' => 'pinlightning_instagram_url',
								'facebook'  => 'pinlightning_facebook_url',
								'twitter'   => 'pinlightning_twitter_url',
							);
							if ( isset( $legacy_map[ $key ] ) ) {
								$url = get_theme_mod( $legacy_map[ $key ], '' );
							}
						endif;
						if ( empty( $url ) ) continue;
					?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $social['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Categories -->
			<nav role="navigation" aria-label="<?php esc_attr_e( 'Footer categories', 'pinlightning' ); ?>">
				<h3 class="pl-footer-heading"><?php echo esc_html( $footer_cat_heading ); ?></h3>
				<ul class="pl-footer-links">
					<?php
					$footer_cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => $footer_cat_count, 'hide_empty' => true ) );
					foreach ( $footer_cats as $fc ) :
					?>
					<li><a href="<?php echo esc_url( get_category_link( $fc->term_id ) ); ?>"><?php echo esc_html( $fc->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<!-- About -->
			<nav role="navigation" aria-label="<?php esc_attr_e( 'About links', 'pinlightning' ); ?>">
				<h3 class="pl-footer-heading"><?php echo esc_html( $footer_about_heading ); ?></h3>
				<ul class="pl-footer-links">
					<?php for ( $i = 1; $i <= 4; $i++ ) :
						$about_label = get_theme_mod( "pl_footer_about_label_{$i}", '' );
						$about_url   = get_theme_mod( "pl_footer_about_url_{$i}", '' );
						if ( empty( $about_label ) ) continue;
					?>
					<li><a href="<?php echo esc_url( home_url( $about_url ) ); ?>"><?php echo esc_html( $about_label ); ?></a></li>
					<?php endfor; ?>
				</ul>
			</nav>

			<!-- Legal -->
			<nav role="navigation" aria-label="<?php esc_attr_e( 'Legal links', 'pinlightning' ); ?>">
				<h3 class="pl-footer-heading"><?php echo esc_html( $footer_legal_heading ); ?></h3>
				<ul class="pl-footer-links">
					<?php for ( $i = 1; $i <= 5; $i++ ) :
						$legal_label = get_theme_mod( "pl_footer_legal_label_{$i}", '' );
						$legal_url   = get_theme_mod( "pl_footer_legal_url_{$i}", '' );
						if ( empty( $legal_label ) ) continue;
					?>
					<li><a href="<?php echo esc_url( home_url( $legal_url ) ); ?>"><?php echo esc_html( $legal_label ); ?></a></li>
					<?php endfor; ?>
				</ul>
			</nav>
		</div>

		<div class="pl-footer-bottom">
			<span>&copy; <?php echo esc_html( $footer_copyright ); ?></span>
			<span><?php echo esc_html( $footer_tagline ); ?></span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>

</body>
</html>
