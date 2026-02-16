<?php
/**
 * The footer template.
 *
 * 4-column footer with brand, categories, about, and legal links.
 *
 * @package PinLightning
 * @since 1.0.0
 */
?>
</main><!-- #primary -->

<footer class="pl-footer" role="contentinfo">
	<div class="pl-container">
		<div class="pl-footer-grid">
			<!-- Brand -->
			<div class="pl-footer-brand">
				<div class="pl-footer-logo">
					<span class="pl-footer-logo-icon">&#x26A1;</span>
					<span class="pl-footer-logo-text">cheerlives</span>
				</div>
				<p class="pl-footer-about">Your daily destination for curated style, home design, beauty, and lifestyle inspiration from Pinterest and beyond.</p>
				<div class="pl-footer-social">
					<?php
					$social_links = array(
						'pinlightning_pinterest_url' => 'Pinterest',
						'pinlightning_instagram_url' => 'Instagram',
						'pinlightning_facebook_url'  => 'Facebook',
						'pinlightning_twitter_url'   => 'X / Twitter',
					);
					foreach ( $social_links as $mod => $label ) :
						$url = get_theme_mod( $mod, '' );
						if ( $url ) :
					?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $label ); ?></a>
					<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Categories -->
			<div>
				<h4 class="pl-footer-heading">Categories</h4>
				<ul class="pl-footer-links">
					<?php
					$footer_cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 6, 'hide_empty' => true ) );
					foreach ( $footer_cats as $fc ) :
					?>
					<li><a href="<?php echo esc_url( get_category_link( $fc->term_id ) ); ?>"><?php echo esc_html( $fc->name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<!-- About -->
			<div>
				<h4 class="pl-footer-heading">About</h4>
				<ul class="pl-footer-links">
					<li><a href="<?php echo esc_url( home_url( '/about' ) ); ?>">About Us</a></li>
					<li><a href="<?php echo esc_url( home_url( '/contact' ) ); ?>">Contact</a></li>
				</ul>
			</div>

			<!-- Legal -->
			<div>
				<h4 class="pl-footer-heading">Legal</h4>
				<ul class="pl-footer-links">
					<li><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Privacy Policy</a></li>
					<li><a href="<?php echo esc_url( home_url( '/terms' ) ); ?>">Terms of Use</a></li>
					<li><a href="<?php echo esc_url( home_url( '/disclaimer' ) ); ?>">Disclaimer</a></li>
					<li><a href="<?php echo esc_url( home_url( '/affiliate-disclosure' ) ); ?>">Affiliate Disclosure</a></li>
				</ul>
			</div>
		</div>

		<div class="pl-footer-bottom">
			<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Cheerlives. All rights reserved.</span>
			<span>Made with &#x26A1; by PinLightning</span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>

</body>
</html>
