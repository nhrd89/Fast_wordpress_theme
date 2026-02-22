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
<?php
// LCP preload + preconnect FIRST — before meta, CSS, or any wp_head output.
// The browser's preload scanner discovers these at byte 0 of <head>,
// starting the hero image fetch immediately instead of after parsing CSS.
pinlightning_early_preconnect();
pinlightning_preload_lcp_image();
?>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
<style>
/* CMP popup — force position:fixed to prevent CLS (fixed elements don't shift layout) */
#qc-cmp2-container,#qc-cmp2-usp,.qc-cmp2-summary-section{position:fixed!important}
</style>
<script>
/* Defer InMobi CMP consent popup until after LCP fires.
 * The CMP is loaded by Ad.Plus network (not by theme) and steals LCP + causes CLS.
 * This intercepts CMP script injection and delays it post-LCP. */
(function(){
var cmpLoaded=false;
function loadCMP(){
if(cmpLoaded)return;
cmpLoaded=true;
var s1=document.createElement('script');
s1.src='https://cmp.inmobi.com/choice.js?tag_version=V3';
s1.async=true;
document.head.appendChild(s1);
}
if('PerformanceObserver' in window){
var lcpDone=false;
var po=new PerformanceObserver(function(){
lcpDone=true;
setTimeout(loadCMP,500);
po.disconnect();
});
try{po.observe({type:'largest-contentful-paint',buffered:true})}catch(e){setTimeout(loadCMP,3000)}
setTimeout(function(){if(!lcpDone){loadCMP();try{po.disconnect()}catch(e){}}},4000);
}else{
setTimeout(loadCMP,3000);
}
})();
</script>
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

		<div class="pl-header-stats">
			<span class="eb-collect-counter">
				<span class="eb-collect-num">0</span> / <span class="eb-collect-total">0</span> ideas seen
			</span>
			<span class="eb-live">
				<span class="eb-live-dot"></span>
				<span class="eb-counter-live">0</span> online
			</span>
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

<?php if ( function_exists( 'pl_ad_settings' ) && pl_ad_settings()['enabled'] ) : ?>
<div class="nav-ad-container">
	<div class="ad-anchor nav-ad-mobile" data-position="nav-below" data-item="0" data-location="nav"></div>
	<div class="ad-anchor nav-ad-tablet" data-position="nav-below-tablet" data-item="0" data-location="nav"></div>
	<div class="ad-anchor nav-ad-desktop" data-position="nav-below-desktop" data-item="0" data-location="nav"></div>
</div>
<?php endif; ?>

<main id="primary" role="main">
