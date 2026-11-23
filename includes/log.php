<?php
/**
 * Append-only, hash-chained activity log.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Appends a log row to the global chain.
 *
 * @param int      $request_id Request ID, 0 for a site-level event.
 * @param string   $event      Event key.
 * @param array    $detail     Detail, stored as JSON. Never requester personal data.
 * @param int|null $actor_id   User ID; null = current user (0 when none), 0 = system, -1 = requester,
 *                             -2 = the person who posted removed content (poster.php).
 * @return int Row ID, 0 when the insert failed.
 */
function tkdesk_log( int $request_id, string $event, array $detail = array(), ?int $actor_id = null ): int {
	global $wpdb;

	$table    = tkdesk_table( 'log' );
	$actor_id = $actor_id ?? get_current_user_id();
	if ( -1 === $actor_id ) {
		$label = __( 'Requester', 'takedown-desk' );
	} elseif ( -2 === $actor_id ) {
		$label = __( 'Person who posted it', 'takedown-desk' );
	} elseif ( 0 === $actor_id ) {
		$label = __( 'System', 'takedown-desk' );
	} else {
		$user  = get_userdata( $actor_id );
		$label = $user ? $user->display_name : '';
	}

	$row = array(
		'request_id'  => $request_id,
		'created_at'  => tkdesk_mysql( tkdesk_now() ),
		'actor_id'    => $actor_id,
		'actor_label' => mb_substr( $label, 0, 60 ),
		'event'       => $event,
		'detail'      => (string) wp_json_encode( $detail ),
	);

	// ponytail: best-effort lock; after a 5 s timeout the row is still written (kept, not lost; a fork shows in tkdesk_log_verify).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock guarding the tkdesk_log chain.
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', 'tkdesk_log' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log; the chain head must be read live.
	$prev             = $wpdb->get_var( $wpdb->prepare( 'SELECT row_hash FROM %i ORDER BY id DESC LIMIT 1', $table ) );
	$row['prev_hash'] = null === $prev ? (string) get_option( 'tkdesk_log_anchor', '' ) : $prev;
	$row['row_hash']  = hash( 'sha256', implode( '|', array( $row['prev_hash'], $request_id, $row['created_at'], $actor_id, $event, $row['detail'] ) ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log.
	$inserted  = $wpdb->insert( $table, $row, array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
	$row['id'] = $inserted ? (int) $wpdb->insert_id : 0;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock guarding the tkdesk_log chain.
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', 'tkdesk_log' ) );

	if ( $row['id'] ) {
		/**
		 * Fires after a log row is written.
		 *
		 * @param array $row The row, including id, prev_hash and row_hash.
		 */
		do_action( 'tkdesk_log_written', $row );
	}

	return $row['id'];
}

/**
 * Verifies the whole chain, starting from the anchor option when set.
 *
 * @return array { ok: bool, rows: int rows verified, broken_at: int first bad row ID or 0 }
 */
function tkdesk_log_verify(): array {
	global $wpdb;

	$prev  = (string) get_option( 'tkdesk_log_anchor', '' );
	$last  = 0;
	$count = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, request_id, created_at, actor_id, event, detail, prev_hash, row_hash FROM %i WHERE id > %d ORDER BY id ASC LIMIT 1000', tkdesk_table( 'log' ), $last ), ARRAY_A );
		$more = 1000 === count( $rows );
		foreach ( $rows as $row ) {
			$hash = hash( 'sha256', implode( '|', array( $prev, $row['request_id'], $row['created_at'], $row['actor_id'], $row['event'], (string) $row['detail'] ) ) );
			if ( $row['prev_hash'] !== $prev || ! hash_equals( $hash, $row['row_hash'] ) ) {
				return array(
					'ok'        => false,
					'rows'      => $count,
					'broken_at' => (int) $row['id'],
				);
			}
			$prev = $row['row_hash'];
			$last = (int) $row['id'];
			++$count;
		}
	} while ( $more );

	return array(
		'ok'        => true,
		'rows'      => $count,
		'broken_at' => 0,
	);
}

/**
 * Log rows, newest first.
 *
 * @param array $args from, to (GMT MySQL datetimes), request_id, event, events (comma-separated list; any of them),
 *                    page, per_page (default 50).
 * @return array { rows: array, total: int }
 */
function tkdesk_log_query( array $args ): array {
	global $wpdb;

	$args     = wp_parse_args(
		$args,
		array(
			'from'       => '',
			'to'         => '',
			'request_id' => 0,
			'event'      => '',
			'events'     => '',
			'page'       => 1,
			'per_page'   => 50,
		)
	);
	$table    = tkdesk_table( 'log' );
	$from     = (string) $args['from'];
	$to       = (string) $args['to'];
	$request  = (int) $args['request_id'];
	$event    = (string) $args['event'];
	$events   = (string) $args['events'];
	$per_page = max( 1, (int) $args['per_page'] );
	$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

	// Empty filters are no-ops through the "= ''" / "= 0" guards, so the SQL stays static.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log.
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM %i
			WHERE ( %s = '' OR created_at >= %s ) AND ( %s = '' OR created_at <= %s )
			AND ( %d = 0 OR request_id = %d ) AND ( %s = '' OR event = %s ) AND ( %s = '' OR FIND_IN_SET( event, %s ) )",
			$table,
			$from,
			$from,
			$to,
			$to,
			$request,
			$request,
			$event,
			$event,
			$events,
			$events
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM %i
			WHERE ( %s = '' OR created_at >= %s ) AND ( %s = '' OR created_at <= %s )
			AND ( %d = 0 OR request_id = %d ) AND ( %s = '' OR event = %s ) AND ( %s = '' OR FIND_IN_SET( event, %s ) )
			ORDER BY id DESC LIMIT %d OFFSET %d",
			$table,
			$from,
			$from,
			$to,
			$to,
			$request,
			$request,
			$event,
			$event,
			$events,
			$events,
			$per_page,
			$offset
		),
		ARRAY_A
	);

	return array(
		'rows'  => (array) $rows,
		'total' => $total,
	);
}

/**
 * All log rows of one request, oldest first.
 *
 * @param int $request_id Request ID.
 * @return array
 */
function tkdesk_log_for_request( int $request_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table tkdesk_log.
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC', tkdesk_table( 'log' ), $request_id ), ARRAY_A );
}
