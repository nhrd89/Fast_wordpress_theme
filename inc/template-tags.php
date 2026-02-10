<?php
/**
 * Custom template tags for PinLightning.
 *
 * @package PinLightning
 * @since 1.0.0
 */

if ( ! function_exists( 'pinlightning_posted_on' ) ) :
	/**
	 * Print the posted date.
	 */
	function pinlightning_posted_on() {
		$time_string = '<time class="entry-date published updated" datetime="%1$s">%2$s</time>';
		if ( get_the_time( 'U' ) !== get_the_modified_time( 'U' ) ) {
			$time_string = '<time class="entry-date published" datetime="%1$s">%2$s</time><time class="updated" datetime="%3$s">%4$s</time>';
		}

		$time_string = sprintf(
			$time_string,
			esc_attr( get_the_date( DATE_W3C ) ),
			esc_html( get_the_date() ),
			esc_attr( get_the_modified_date( DATE_W3C ) ),
			esc_html( get_the_modified_date() )
		);

		printf(
			'<span class="posted-on">%s</span>',
			'<a href="' . esc_url( get_permalink() ) . '" rel="bookmark">' . $time_string . '</a>'
		);
	}
endif;

if ( ! function_exists( 'pinlightning_posted_by' ) ) :
	/**
	 * Print the post author.
	 */
	function pinlightning_posted_by() {
		printf(
			'<span class="byline"><span class="author vcard"><a class="url fn n" href="%s">%s</a></span></span>',
			esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
			esc_html( get_the_author() )
		);
	}
endif;

if ( ! function_exists( 'pinlightning_entry_footer' ) ) :
	/**
	 * Print entry footer meta (categories, tags, edit link).
	 */
	function pinlightning_entry_footer() {
		if ( 'post' === get_post_type() ) {
			$categories_list = get_the_category_list( esc_html__( ', ', 'pinlightning' ) );
			if ( $categories_list ) {
				printf( '<span class="cat-links">%s</span>', $categories_list ); // phpcs:ignore
			}

			$tags_list = get_the_tag_list( '', esc_html_x( ', ', 'list item separator', 'pinlightning' ) );
			if ( $tags_list ) {
				printf( '<span class="tags-links">%s</span>', $tags_list ); // phpcs:ignore
			}
		}

		edit_post_link(
			sprintf(
				wp_kses( __( 'Edit <span class="screen-reader-text">%s</span>', 'pinlightning' ), array( 'span' => array( 'class' => array() ) ) ),
				wp_kses_post( get_the_title() )
			),
			'<span class="edit-link">',
			'</span>'
		);
	}
endif;
