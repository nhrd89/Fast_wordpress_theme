<?php
/**
 * Template part for displaying a post card.
 *
 * Pinterest-style card: 2:3 image, category overlay, title, excerpt.
 *
 * @package PinLightning
 * @since 1.0.0
 */

$dominant_color = '';
if ( function_exists( 'pinlightning_get_dominant_color' ) ) {
	$dominant_color = pinlightning_get_dominant_color( get_the_ID() );
}
$categories = get_the_category();
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'card' ); ?>>
	<a href="<?php the_permalink(); ?>" class="card-image-link">
		<div class="card-image" <?php echo $dominant_color ? 'style="background-color:' . esc_attr( $dominant_color ) . '"' : ''; ?>>
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'card-thumb', array( 'class' => 'card-img' ) ); ?>
			<?php endif; ?>
		</div>
		<?php if ( $categories ) : ?>
			<span class="card-cat"><?php echo esc_html( $categories[0]->name ); ?></span>
		<?php endif; ?>
	</a>

	<div class="card-body">
		<h2 class="card-title">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		</h2>
		<?php if ( has_excerpt() || get_the_content() ) : ?>
			<p class="card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
		<?php endif; ?>
	</div>
</article>
