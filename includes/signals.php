<?php
/**
 * Abuse guards: opt-in auto-hide on receipt, advisory abuse signals and the requester watch list.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'tkdesk_request_received', 'tkdesk_auto_hide' );
add_action( 'rest_api_init', 'tkdesk_signal_routes' );

/**
 * How many requests one requester (email hash) may send in 24 hours and still have the item hidden on arrival.
 *
 * @return int
 */
function tkdesk_auto_hide_limit(): int {
	/**
	 * Filters the daily auto-hide limit per requester.
	 *
	 * @param int $limit Requests per email hash per 24 hours. Default 3.
	 */
	return max( 0, (int) apply_filters( 'tkdesk_auto_hide_daily_limit', 3 ) );
}

/**
 * Hide reported items as soon as a request arrives (setting `auto_hide`, off by default). Only a new request
 * from the form or a content link whose item is matched; never a spam verdict, a test, a manual entry, content
 * written or uploaded by an administrator, or a requester past the daily limit. The item is hidden as with
 * "Hide while reviewing", by the System, so the request stays open for a moderator to decide.
 *
 * @param int $id Request ID.
 */
function tkdesk_auto_hide( $id ): void {
	global $wpdb;
	$request = tkdesk_get_request( (int) $id );
	if ( ! $request || ! tkdesk_setting( 'auto_hide' ) || 'new' !== $request['status'] || 'manual' === $request['source'] || $request['is_test'] || ! $request['object_type'] ) {
		return;
	}
	$id   = (int) $request['id'];
	$type = (string) $request['object_type'];
	$oid  = (int) $request['object_id'];
	$desc = tkdesk_describe( $type, $oid );
	// Already offline under another request: there is nothing to hide.
	$off = 'comment' === $type ? (bool) get_comment_meta( $oid, '_tkdesk_removed', true ) : 'tkdesk_removed' === get_post_status( $oid );
	if ( ! $desc || $off ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$sent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email_hash = %s AND id <> %d AND is_test = 0 AND received_at >= %s', tkdesk_table( 'requests' ), (string) $request['email_hash'], $id, tkdesk_mysql( tkdesk_now() - DAY_IN_SECONDS ) ) );
	$user = get_current_user_id();
	// The System hides it, never whoever is logged in: a signed-in requester must not appear in the record.
	wp_set_current_user( 0 );
	try {
		$skip = '';
		if ( $desc['author_id'] && user_can( (int) $desc['author_id'], 'manage_options' ) ) {
			$skip = 'admin';
		} elseif ( $sent >= tkdesk_auto_hide_limit() ) {
			$skip = 'limit';
		} elseif ( is_wp_error(
			tkdesk_can_remove(
				array(
					'object_type' => $type,
					'object_id'   => $oid,
				)
			)
		) ) {
			$skip = 'rules';
		}
		if ( '' !== $skip ) {
			tkdesk_log( $id, 'auto_hide_skipped', array( 'reason' => $skip ) );
			return;
		}
		$result = tkdesk_hide_item( $id );
		if ( ! is_wp_error( $result ) && ! empty( $result['results'][0]['ok'] ) ) {
			tkdesk_log( $id, 'auto_hidden' );
			// The usual removal check, in the background so the requester is not kept waiting: now and an hour later.
			wp_schedule_single_event( tkdesk_now(), 'tkdesk_reach_recheck', array( $id ) );
			wp_schedule_single_event( tkdesk_now() + HOUR_IN_SECONDS, 'tkdesk_reach_recheck', array( $id ) );
		}
	} finally {
		wp_set_current_user( $user );
	}
}

/**
 * Advisory abuse signals for one request, computed when asked. They prompt a closer look and never decline
 * anything on their own.
 *
 * @param array $row Request row.
 * @return array { auto_hidden: bool, watched: bool, can_watch: bool, list: array( array( key, label, text ) ) }
 */
function tkdesk_signals( array $row ): array {
	global $wpdb;
	$table = tkdesk_table( 'requests' );
	$hash  = (string) $row['email_hash'];
	$now   = tkdesk_now();
	$list  = array();
	// ponytail: two indexed COUNTs per request; batch them per inbox page if pages ever grow past 100 rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$same = $row['object_type'] ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %d AND is_test = 0 AND received_at >= %s', $table, $row['object_type'], $row['object_id'], tkdesk_mysql( $now - 30 * DAY_IN_SECONDS ) ) ) : 0;
	if ( $same >= 3 ) {
		$list[] = array(
			'key'   => 'same_item',
			/* translators: %d: number of requests. */
			'label' => sprintf( _n( 'Same item reported %d time', 'Same item reported %d times', $same, 'takedown-desk' ), $same ),
			/* translators: %d: number of requests. */
			'text'  => sprintf( _n( 'This item was reported %d time in the last 30 days.', 'This item was reported %d times in the last 30 days.', $same, 'takedown-desk' ), $same ),
		);
	}
	// Distinct items: a matched object counts once, each unmatched request on its own.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$items = '' !== $hash ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT COALESCE( CONCAT( object_type, ':', object_id ), CONCAT( 'request:', id ) ) ) FROM %i WHERE email_hash = %s AND is_test = 0 AND received_at >= %s", $table, $hash, tkdesk_mysql( $now - 7 * DAY_IN_SECONDS ) ) ) : 0;
	if ( $items >= 5 ) {
		$list[] = array(
			'key'   => 'many_items',
			'label' => __( 'One requester, many items', 'takedown-desk' ),
			/* translators: %d: number of items. */
			'text'  => sprintf( _n( 'This requester reported %d different item in the last 7 days.', 'This requester reported %d different items in the last 7 days.', $items, 'takedown-desk' ), $items ),
		);
	}
	$watched = '' !== $hash && isset( tkdesk_watch_list()[ $hash ] );
	if ( $watched ) {
		$list[] = array(
			'key'   => 'watched',
			'label' => __( 'On your watch list', 'takedown-desk' ),
			'text'  => __( 'This requester is on your watch list.', 'takedown-desk' ),
		);
	}
	// Hidden by the System (tkdesk_auto_hide()) and still hidden: a moderator's own hide carries their user ID.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$item = $wpdb->get_row( $wpdb->prepare( 'SELECT action, action_by FROM %i WHERE request_id = %d AND role = %s', tkdesk_table( 'items' ), $row['id'], 'reported' ), ARRAY_A );
	return array(
		'auto_hidden' => $item && 'hidden' === $item['action'] && 0 === (int) $item['action_by'],
		'watched'     => $watched,
		'can_watch'   => '' !== $hash,
		'list'        => $list,
	);
}

/**
 * The watch list: requester email hash => GMT time it was added. Never holds an email address.
 *
 * @return array
 */
function tkdesk_watch_list(): array {
	return (array) get_option( 'tkdesk_watch_list', array() );
}

/**
 * Admin route: POST /admin/requests/{id}/watch (moderators).
 */
function tkdesk_signal_routes(): void {
	register_rest_route(
		'takedown-desk/v1',
		'/admin/requests/(?P<id>[0-9]+)/watch',
		array(
			'methods'             => 'POST',
			'callback'            => 'tkdesk_rest_admin_watch',
			'permission_callback' => static function () {
				return tkdesk_can( 'tkdesk_manage_requests' );
			},
		)
	);
}

/**
 * POST /admin/requests/{id}/watch { watch }: put the request's requester on the watch list or take them off.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Detail JSON.
 */
function tkdesk_rest_admin_watch( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$hash = (string) $row['email_hash'];
	if ( '' === $hash ) {
		return new WP_Error( 'tkdesk_no_requester', __( 'Filed by the site team, so there is no requester to watch.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	$on   = rest_sanitize_boolean( $request->get_param( 'watch' ) );
	$list = tkdesk_watch_list();
	if ( isset( $list[ $hash ] ) !== $on ) {
		if ( $on ) {
			$list[ $hash ] = tkdesk_mysql( tkdesk_now() );
		} else {
			unset( $list[ $hash ] );
		}
		update_option( 'tkdesk_watch_list', $list, false );
		tkdesk_log( $row['id'], $on ? 'watch_added' : 'watch_removed' );
	}
	return tkdesk_rest_request( (array) tkdesk_get_request( $row['id'] ), true );
}
