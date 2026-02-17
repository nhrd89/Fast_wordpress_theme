<?php
/**
 * Engagement break configurations per category.
 *
 * 'position' = inject after which H2 item number
 * 'type' = break template to render
 * 'data' = category-specific content for the break
 *
 * @package PinLightning
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pl_get_engagement_config( $category_slug ) {

	$configs = array(
		'hairstyle' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 4,  'type' => 'curiosity', 'text' => 'The next cut literally made {saves} people hit save this week...' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 6,  'type' => 'related' ),
				array( 'position' => 8,  'type' => 'curiosity', 'text' => 'Wait till you see #{next} — it\'s the one stylists are raving about' ),
				array( 'position' => 9,  'type' => 'fact', 'text' => 'Face-framing layers can make you look up to 5 years younger by drawing attention to your eyes and cheekbones.' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 12, 'type' => 'curiosity', 'text' => 'You\'re so close to the end... but #{next} might just steal the whole show' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'When showing your stylist a photo, point out the specific details you love — the layer length, the face-framing angle, or the volume at the crown.' ),
			),
			'poll_question' => 'Which style is calling your name?',
			'poll_options' => array(
				array( 'emoji' => "\u2728", 'label' => 'Sleek & Polished', 'pct' => 32 ),
				array( 'emoji' => "\xF0\x9F\x8C\x8A", 'label' => 'Soft & Wavy', 'pct' => 41 ),
				array( 'emoji' => "\xF0\x9F\x94\xA5", 'label' => 'Bold & Edgy', 'pct' => 18 ),
				array( 'emoji' => "\xF0\x9F\x91\x91", 'label' => 'Classic Elegance', 'pct' => 9 ),
			),
			'quiz_question' => 'What\'s Your Style Personality?',
			'quiz_styles' => array(
				array( 'slug' => 'boho', 'label' => 'Boho Chic', 'result' => "Boho Chic \u2728" ),
				array( 'slug' => 'glam', 'label' => 'Classic Glam', 'result' => "Classic Glam \xF0\x9F\x91\x91" ),
				array( 'slug' => 'edge', 'label' => 'Modern Edge', 'result' => "Modern Edge \xF0\x9F\x94\xA5" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'Ooh this one\'s gorgeous!' ),
				array( 'at' => 3,  'msg' => 'Getting better! Keep scrolling...' ),
				array( 'at' => 5,  'msg' => 'Wait till you see #9' ),
				array( 'at' => 7,  'msg' => 'You have great taste!' ),
				array( 'at' => 9,  'msg' => 'That one gets saved the most!' ),
				array( 'at' => 11, 'msg' => 'Almost there... the best is coming!' ),
				array( 'at' => 13, 'msg' => 'Save your faves before you go!' ),
				array( 'at' => 15, 'msg' => 'You made it! Style Expert!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Exclusive Bonus Cuts',
				'desc'  => 'Not on this list! Plus your free "What to Tell Your Stylist" cheat sheet.',
				'note'  => 'Join 12,400+ style-savvy readers. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\xA6\x8B", "\xF0\x9F\x92\xAB", "\xF0\x9F\x8C\xB8" ),
			'toc_preview_count'  => 5,
		),

		'hairstyles' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 4,  'type' => 'curiosity', 'text' => 'The next cut literally made {saves} people hit save this week...' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 6,  'type' => 'related' ),
				array( 'position' => 8,  'type' => 'curiosity', 'text' => 'Wait till you see #{next} — it\'s the one stylists are raving about' ),
				array( 'position' => 9,  'type' => 'fact', 'text' => 'Face-framing layers can make you look up to 5 years younger by drawing attention to your eyes and cheekbones.' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 12, 'type' => 'curiosity', 'text' => 'You\'re so close to the end... but #{next} might just steal the whole show' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'When showing your stylist a photo, point out the specific details you love — the layer length, the face-framing angle, or the volume at the crown.' ),
			),
			'poll_question' => 'Which style is calling your name?',
			'poll_options' => array(
				array( 'emoji' => "\u2728", 'label' => 'Sleek & Polished', 'pct' => 32 ),
				array( 'emoji' => "\xF0\x9F\x8C\x8A", 'label' => 'Soft & Wavy', 'pct' => 41 ),
				array( 'emoji' => "\xF0\x9F\x94\xA5", 'label' => 'Bold & Edgy', 'pct' => 18 ),
				array( 'emoji' => "\xF0\x9F\x91\x91", 'label' => 'Classic Elegance', 'pct' => 9 ),
			),
			'quiz_question' => 'What\'s Your Style Personality?',
			'quiz_styles' => array(
				array( 'slug' => 'boho', 'label' => 'Boho Chic', 'result' => "Boho Chic \u2728" ),
				array( 'slug' => 'glam', 'label' => 'Classic Glam', 'result' => "Classic Glam \xF0\x9F\x91\x91" ),
				array( 'slug' => 'edge', 'label' => 'Modern Edge', 'result' => "Modern Edge \xF0\x9F\x94\xA5" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'Ooh this one\'s gorgeous!' ),
				array( 'at' => 3,  'msg' => 'Getting better! Keep scrolling...' ),
				array( 'at' => 5,  'msg' => 'Wait till you see #9' ),
				array( 'at' => 7,  'msg' => 'You have great taste!' ),
				array( 'at' => 9,  'msg' => 'That one gets saved the most!' ),
				array( 'at' => 11, 'msg' => 'Almost there... the best is coming!' ),
				array( 'at' => 13, 'msg' => 'Save your faves before you go!' ),
				array( 'at' => 15, 'msg' => 'You made it! Style Expert!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Exclusive Bonus Cuts',
				'desc'  => 'Not on this list! Plus your free "What to Tell Your Stylist" cheat sheet.',
				'note'  => 'Join 12,400+ style-savvy readers. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\xA6\x8B", "\xF0\x9F\x92\xAB", "\xF0\x9F\x8C\xB8" ),
			'toc_preview_count'  => 5,
		),

		'nail-art' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 7,  'type' => 'fact', 'text' => 'Nail art has been around for over 5,000 years — ancient Babylonians used gold and silver to color their nails!' ),
				array( 'position' => 9,  'type' => 'related' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'Apply a base coat before any nail art to prevent staining and make your design last 2-3x longer.' ),
			),
			'poll_question' => 'What\'s your nail art vibe?',
			'poll_options' => array(
				array( 'emoji' => "\xF0\x9F\x8C\xB8", 'label' => 'Minimalist', 'pct' => 28 ),
				array( 'emoji' => "\xF0\x9F\x92\x85", 'label' => 'Full Glam', 'pct' => 35 ),
				array( 'emoji' => "\xF0\x9F\x8E\xA8", 'label' => 'Abstract Art', 'pct' => 22 ),
				array( 'emoji' => "\u2728", 'label' => 'Sparkle & Gems', 'pct' => 15 ),
			),
			'quiz_question' => 'What\'s Your Nail Personality?',
			'quiz_styles' => array(
				array( 'slug' => 'minimal', 'label' => 'Clean & Minimal', 'result' => "Clean & Minimal \xF0\x9F\x8C\xB8" ),
				array( 'slug' => 'bold', 'label' => 'Bold & Bright', 'result' => "Bold & Bright \xF0\x9F\x8E\xA8" ),
				array( 'slug' => 'glam', 'label' => 'Full Glam', 'result' => "Full Glam \xF0\x9F\x92\x85" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'These nails are everything!' ),
				array( 'at' => 3,  'msg' => 'Keep scrolling for inspo!' ),
				array( 'at' => 7,  'msg' => 'That design is so creative!' ),
				array( 'at' => 11, 'msg' => 'Save your faves!' ),
				array( 'at' => 15, 'msg' => 'Nail Art Expert!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Hidden Nail Art Designs',
				'desc'  => 'Exclusive designs + our step-by-step difficulty guide.',
				'note'  => 'Join 12,400+ nail art lovers. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\x92\x85", "\xF0\x9F\x8E\xA8", "\xF0\x9F\x8C\xB8" ),
			'toc_preview_count'  => 5,
		),

		'home-decor' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 7,  'type' => 'fact', 'text' => 'Adding plants to a room can reduce stress by up to 37% and boost creativity — the "biophilia effect" in action.' ),
				array( 'position' => 9,  'type' => 'related' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'When mixing patterns, keep the color palette consistent — two patterns that share at least one color always look intentional.' ),
			),
			'poll_question' => 'What\'s your decor personality?',
			'poll_options' => array(
				array( 'emoji' => "\xF0\x9F\x8C\xBF", 'label' => 'Natural & Organic', 'pct' => 30 ),
				array( 'emoji' => "\u2728", 'label' => 'Modern Minimal', 'pct' => 35 ),
				array( 'emoji' => "\xF0\x9F\x8E\xA8", 'label' => 'Eclectic Mix', 'pct' => 20 ),
				array( 'emoji' => "\xF0\x9F\x91\x91", 'label' => 'Classic Luxury', 'pct' => 15 ),
			),
			'quiz_question' => 'What\'s Your Interior Style?',
			'quiz_styles' => array(
				array( 'slug' => 'scandi', 'label' => 'Scandinavian', 'result' => "Scandinavian \xF0\x9F\x8C\xBF" ),
				array( 'slug' => 'boho', 'label' => 'Bohemian', 'result' => "Bohemian \xF0\x9F\x8E\xA8" ),
				array( 'slug' => 'glam', 'label' => 'Modern Glam', 'result' => "Modern Glam \u2728" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'Love this space!' ),
				array( 'at' => 3,  'msg' => 'Great taste in decor!' ),
				array( 'at' => 7,  'msg' => 'This room is goals!' ),
				array( 'at' => 11, 'msg' => 'Save these ideas!' ),
				array( 'at' => 15, 'msg' => 'Decor Expert unlocked!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Bonus Room Makeover Ideas',
				'desc'  => 'Exclusive designs + our room-by-room styling guide.',
				'note'  => 'Join 12,400+ decor enthusiasts. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\x8F\xA0", "\xF0\x9F\x8C\xBF", "\xF0\x9F\x8E\xA8" ),
			'toc_preview_count'  => 5,
		),

		'architecture' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 7,  'type' => 'fact', 'text' => 'The human brain processes architectural beauty using the same neural pathways as art appreciation — great buildings literally move us.' ),
				array( 'position' => 9,  'type' => 'related' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'When photographing architecture, shoot during golden hour for the most dramatic shadows and warmth.' ),
			),
			'poll_question' => 'Which architectural style speaks to you?',
			'poll_options' => array(
				array( 'emoji' => "\xF0\x9F\x8F\x9B\xEF\xB8\x8F", 'label' => 'Classical', 'pct' => 22 ),
				array( 'emoji' => "\xF0\x9F\x8F\x97\xEF\xB8\x8F", 'label' => 'Modern', 'pct' => 38 ),
				array( 'emoji' => "\xF0\x9F\x8C\xBF", 'label' => 'Organic', 'pct' => 25 ),
				array( 'emoji' => "\xF0\x9F\x94\xA5", 'label' => 'Brutalist', 'pct' => 15 ),
			),
			'quiz_question' => 'What\'s Your Architecture Personality?',
			'quiz_styles' => array(
				array( 'slug' => 'modern', 'label' => 'Modernist', 'result' => "Modernist \xF0\x9F\x8F\x97\xEF\xB8\x8F" ),
				array( 'slug' => 'classic', 'label' => 'Classicist', 'result' => "Classicist \xF0\x9F\x8F\x9B\xEF\xB8\x8F" ),
				array( 'slug' => 'organic', 'label' => 'Organic', 'result' => "Organic \xF0\x9F\x8C\xBF" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'Stunning design!' ),
				array( 'at' => 5,  'msg' => 'The details here are incredible' ),
				array( 'at' => 9,  'msg' => 'This is award-worthy!' ),
				array( 'at' => 13, 'msg' => 'Architecture Expert!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Hidden Architectural Gems',
				'desc'  => 'Exclusive buildings + our photographer\'s angle guide.',
				'note'  => 'Join 12,400+ architecture lovers. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\x8F\x9B\xEF\xB8\x8F", "\xF0\x9F\x8F\x97\xEF\xB8\x8F", "\xF0\x9F\x8C\xBF" ),
			'toc_preview_count'  => 5,
		),

		'fashion' => array(
			'breaks' => array(
				array( 'position' => 3,  'type' => 'poll' ),
				array( 'position' => 5,  'type' => 'quiz' ),
				array( 'position' => 7,  'type' => 'fact', 'text' => 'The average person makes a judgment about someone in just 7 seconds — and clothing accounts for 80% of that first impression.' ),
				array( 'position' => 9,  'type' => 'related' ),
				array( 'position' => 11, 'type' => 'email' ),
				array( 'position' => 13, 'type' => 'stylist_tip', 'text' => 'The rule of thirds works for outfits too — aim for a 2:1 ratio of top to bottom (or vice versa) for a flattering silhouette.' ),
			),
			'poll_question' => 'Which style era is your vibe?',
			'poll_options' => array(
				array( 'emoji' => "\u2728", 'label' => 'Y2K Revival', 'pct' => 28 ),
				array( 'emoji' => "\xF0\x9F\x91\x97", 'label' => 'Quiet Luxury', 'pct' => 35 ),
				array( 'emoji' => "\xF0\x9F\x94\xA5", 'label' => 'Streetwear', 'pct' => 22 ),
				array( 'emoji' => "\xF0\x9F\x8C\xB8", 'label' => 'Cottagecore', 'pct' => 15 ),
			),
			'quiz_question' => 'What\'s Your Fashion Personality?',
			'quiz_styles' => array(
				array( 'slug' => 'classic', 'label' => 'Timeless', 'result' => "Timeless \xF0\x9F\x91\x91" ),
				array( 'slug' => 'trendy', 'label' => 'Trendsetter', 'result' => "Trendsetter \xF0\x9F\x94\xA5" ),
				array( 'slug' => 'boho', 'label' => 'Free Spirit', 'result' => "Free Spirit \xF0\x9F\x8C\xB8" ),
			),
			'char_messages' => array(
				array( 'at' => 1,  'msg' => 'Obsessed with this look!' ),
				array( 'at' => 5,  'msg' => 'Your style is impeccable!' ),
				array( 'at' => 9,  'msg' => 'Save this one!' ),
				array( 'at' => 13, 'msg' => 'Fashion Expert!' ),
			),
			'email_hook' => array(
				'title' => 'Get 5 Exclusive Outfit Ideas',
				'desc'  => 'Not on this list! Plus your free capsule wardrobe guide.',
				'note'  => 'Join 12,400+ fashion lovers. Unsubscribe anytime.',
			),
			'blur_item'          => 8,
			'trending_count'     => 3,
			'collectible_count'  => 5,
			'collectible_emojis' => array( "\xF0\x9F\x92\x8E", "\u2728", "\xF0\x9F\x91\x97", "\xF0\x9F\x91\x91", "\xF0\x9F\x8C\xB8" ),
			'toc_preview_count'  => 5,
		),
	);

	return $configs[ $category_slug ] ?? $configs['hairstyle'];
}
