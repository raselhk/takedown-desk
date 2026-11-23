<?php
/**
 * Blocks and shortcodes: the request form router and the notice link.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tkdesk_register_blocks' );

/**
 * Register front-end assets, both dynamic blocks and both shortcodes.
 */
function tkdesk_register_blocks() {
	// The admin's design tokens and bundled font, so the public views look like the rest of Takedown Desk.
	wp_register_style( 'tkdesk-tokens', TKDESK_URL . 'assets/css/tokens.css', array(), tkdesk_asset_ver( 'assets/css/tokens.css' ) );
	wp_register_style( 'tkdesk-form', TKDESK_URL . 'assets/css/form.css', array( 'tkdesk-tokens' ), tkdesk_asset_ver( 'assets/css/form.css' ) );
	wp_register_script(
		'tkdesk-form',
		TKDESK_URL . 'assets/js/form.js',
		array(),
		tkdesk_asset_ver( 'assets/js/form.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_register_script( 'tkdesk-block', TKDESK_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), tkdesk_asset_ver( 'assets/js/block.js' ), true );
	wp_set_script_translations( 'tkdesk-block', 'takedown-desk' );
	register_block_type( TKDESK_PATH . 'blocks/request-form', array( 'render_callback' => 'tkdesk_render_form_block' ) );
	register_block_type( TKDESK_PATH . 'blocks/notice-link', array( 'render_callback' => 'tkdesk_notice_link_html' ) );
	register_block_type( TKDESK_PATH . 'blocks/summary', array( 'render_callback' => 'tkdesk_render_summary_block' ) );
	add_shortcode( 'takedown_desk_form', 'tkdesk_render_form_block' );
	add_shortcode( 'takedown_desk_link', 'tkdesk_notice_link_html' );
	add_shortcode( 'takedown_desk_summary', 'tkdesk_render_summary_block' );
}

/**
 * Router: review page for the person who posted removed content (tkdesk_review + token, poster.php),
 * status view (tkdesk_request + token), confirmation (tkdesk_done + t) or the form.
 *
 * @return string
 */
function tkdesk_render_form_block(): string {
	tkdesk_enqueue_form_assets();

	$review = tkdesk_query_arg( 'tkdesk_review' );
	if ( '' !== $review ) {
		return '<div class="tkdesk">' . tkdesk_quick_exit_html() . tkdesk_view_review( $review, tkdesk_query_arg( 'token' ), tkdesk_form_state() ) . '</div>';
	}

	$public_id = tkdesk_query_arg( 'tkdesk_request' );
	if ( '' !== $public_id ) {
		$token   = tkdesk_query_arg( 'token' );
		$request = tkdesk_get_request_by_public_id( $public_id );
		if ( ! $request || '' === $token || ! tkdesk_verify_token( $request, $token ) ) {
			return '<div class="tkdesk">' . tkdesk_quick_exit_html() . tkdesk_view_bad_token() . '</div>';
		}
		$switched = ! empty( $request['locale'] ) && switch_to_locale( (string) $request['locale'] );
		$html     = tkdesk_quick_exit_html() . tkdesk_view_status( $request, $token, tkdesk_form_state() );
		if ( $switched ) {
			restore_previous_locale();
		}
		return '<div class="tkdesk">' . $html . '</div>';
	}

	$done = tkdesk_query_arg( 'tkdesk_done' );
	if ( '' !== $done && hash_equals( substr( tkdesk_hash( $done . '|done' ), 0, 12 ), tkdesk_query_arg( 't' ) ) ) {
		// The masked email and status link come from the no-JS submit (5-minute encrypted state).
		$state = tkdesk_form_state();
		$row   = tkdesk_get_request_by_public_id( $done );
		return '<div class="tkdesk">' . tkdesk_quick_exit_html() . tkdesk_view_confirmation( $done, (string) ( $state['email'] ?? '' ), $row ? tkdesk_ts( $row['received_at'] ) : tkdesk_now(), (string) ( $state['status_url'] ?? '' ) ) . '</div>';
	}

	$state = tkdesk_form_state();
	if ( empty( $state['item'] ) ) {
		$state['item'] = tkdesk_query_arg( 'tkdesk_item' );
	}
	return '<div class="tkdesk">' . tkdesk_quick_exit_html() . tkdesk_view_form( $state ) . '</div>';
}

/**
 * Public summary: block takedown-desk/summary and shortcode [takedown_desk_summary months="12"]. Aggregate
 * numbers for the last 3, 6, 12 or 24 months from tkdesk_summary_stats(), cached for an hour. Counts under
 * 5 read "Fewer than 5"; when removed or not removed is under 5, the other one and the 48-hour share read
 * "Not shown", and the median needs 5 removals, so no one can be picked out.
 *
 * @param array|string $atts Block attributes or shortcode attributes: months.
 * @return string
 */
function tkdesk_render_summary_block( $atts = array() ): string {
	$months = (int) shortcode_atts( array( 'months' => 12 ), (array) $atts )['months'];
	$months = in_array( $months, array( 3, 6, 12, 24 ), true ) ? $months : 12;
	$cache  = get_transient( 'tkdesk_summary_' . $months );
	if ( ! is_array( $cache ) || ! isset( $cache['at'], $cache['stats'] ) ) {
		$cache = array(
			'at'    => tkdesk_now(),
			'stats' => tkdesk_summary_stats( tkdesk_mysql( (int) strtotime( "-$months months", tkdesk_now() ) ), tkdesk_mysql( tkdesk_now() ) ),
		);
		set_transient( 'tkdesk_summary_' . $months, $cache, HOUR_IN_SECONDS );
	}
	wp_enqueue_style( 'takedown-desk-summary-style' );

	$s      = $cache['stats'];
	$closed = $s['removed'] + $s['declined'];
	// With either outcome under 5, the other outcome and the 48-hour share would let a reader work it out.
	$small = $s['removed'] < 5 || $s['declined'] < 5;
	$count = static function ( int $n, bool $hide = false ): array {
		if ( $n < 5 ) {
			return array( __( 'Fewer than 5', 'takedown-desk' ), true );
		}
		return $hide ? array( __( 'Not shown', 'takedown-desk' ), true ) : array( number_format_i18n( $n ), false );
	};
	$rows  = array(
		array( __( 'Requests received', 'takedown-desk' ), $count( $s['received'] ) ),
		array( __( 'Removed', 'takedown-desk' ), $count( $s['removed'], $small ) ),
		array( __( 'Not removed', 'takedown-desk' ), $count( $s['declined'], $small ) ),
		array( __( 'Median time to removal', 'takedown-desk' ), $s['removed'] < 5 ? array( __( 'Needs at least 5 removals', 'takedown-desk' ), true ) : array( tkdesk_duration_text( (int) $s['median_seconds'] ), false ) ),
		/* translators: %s: a whole-number percentage, for example 92. */
		array( __( 'Closed within 48 hours', 'takedown-desk' ), $small ? array( __( 'Not shown', 'takedown-desk' ), true ) : array( sprintf( __( '%s%%', 'takedown-desk' ), number_format_i18n( round( 100 * $s['within_48h'] / $closed ) ) ), false ) ),
	);
	$items = '';
	foreach ( $rows as $row ) {
		$items .= '<div class="tkd-stats__item"><dt>' . esc_html( $row[0] ) . '</dt><dd' . ( $row[1][1] ? ' class="is-masked"' : '' ) . '>' . esc_html( $row[1][0] ) . '</dd></div>';
	}
	$id = wp_unique_id( 'tkd-stats-' );
	/* translators: %d: number of months. */
	$title = sprintf( _n( 'Removal requests in the last %d month', 'Removal requests in the last %d months', $months, 'takedown-desk' ), $months );
	/* translators: %s: date and time the numbers were updated, with timezone. */
	$note = sprintf( __( 'Counts under 5, and numbers that would reveal them, are not shown exactly, so that no one can be identified. Test and spam requests are not counted. Updated %s.', 'takedown-desk' ), tkdesk_format_deadline( (int) $cache['at'] ) );

	return '<section class="tkdesk tkd-stats" aria-labelledby="' . esc_attr( $id ) . '">'
		. '<h2 class="tkd-stats__title" id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>'
		. '<dl class="tkd-stats__list">' . $items . '</dl>'
		. '<p class="tkd-stats__note">' . esc_html( $note ) . '</p>'
		. '</section>';
}
