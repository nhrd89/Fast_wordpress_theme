<?php
/**
 * The footer template.
 *
 * 3-column footer.
 *
 * @package PinLightning
 * @since 1.0.0
 */
?>
</main><!-- #primary -->

<footer class="site-footer" role="contentinfo">
	<div class="footer-inner">
		<div class="footer-columns">
			<div class="footer-col footer-about">
				<h3 class="footer-heading"><?php esc_html_e( 'About', 'pinlightning' ); ?></h3>
				<p><?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
			</div>

			<div class="footer-col footer-links">
				<h3 class="footer-heading"><?php esc_html_e( 'Quick Links', 'pinlightning' ); ?></h3>
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'menu_id'        => 'footer-menu',
					'container'      => false,
					'fallback_cb'    => false,
					'depth'          => 1,
				) );
				?>
			</div>

			<div class="footer-col footer-social">
				<h3 class="footer-heading"><?php esc_html_e( 'Follow Us', 'pinlightning' ); ?></h3>
				<div class="social-links">
					<?php
					$social_links = array(
						'pinlightning_pinterest_url'  => 'Pinterest',
						'pinlightning_instagram_url'  => 'Instagram',
						'pinlightning_facebook_url'   => 'Facebook',
						'pinlightning_twitter_url'    => 'X / Twitter',
					);
					foreach ( $social_links as $mod => $label ) :
						$url = get_theme_mod( $mod, '' );
						if ( $url ) :
							?>
							<a href="<?php echo esc_url( $url ); ?>" class="social-link" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="footer-bottom">
			<p class="copyright">&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Home', 'pinlightning' ); ?>"><?php bloginfo( 'name' ); ?></a></p>
		</div>
	</div>

</footer>

<?php wp_footer(); ?>

</body>
</html>
