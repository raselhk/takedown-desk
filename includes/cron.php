<?php
/**
 * Scheduled work: deadline reminders every 15 minutes, retention and cleanup once a day, and the
 * removal recheck an hour after a removal.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'cron_schedules', 'tkdesk_cron_schedules' );
add_action( 'init', 'tkdesk_schedule_events' );
add_action( 'tkdesk_tick', 'tkdesk_tick' );
add_action( 'admin_init', 'tkdesk_tick' );
add_action( 'tkdesk_daily', 'tkdesk_daily' );
add_action( 'tkdesk_reach_recheck', 'tkdesk_reach_recheck' );

/**
 * The single recheck an hour after a removal or hide (booked by tkdesk_reach_check()).
 *
 * @param int $request_id Request ID.
 */
function tkdesk_reach_recheck( $request_id ): void {
	tkdesk_reach_check( (int) $request_id, true );
}

/**
 * Adds the 15-minute schedule.
 *
 * @param array $schedules Schedules.
 * @return array
 */
function tkdesk_cron_schedules( $schedules ) {
	$schedules['tkdesk_15min'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes (Takedown Desk)', 'takedown-desk' ),
	);
	return $schedules;
}

/**
 * Schedules the tick and daily events; also runs on init to heal a missing event.
 */
function tkdesk_schedule_events(): void {
	if ( ! wp_next_scheduled( 'tkdesk_tick' ) ) {
		wp_schedule_event( tkdesk_now(), 'tkdesk_15min', 'tkdesk_tick' );
	}
	if ( ! wp_next_scheduled( 'tkdesk_daily' ) ) {
		wp_schedule_event( tkdesk_now(), 'daily', 'tkdesk_daily' );
	}
}

/**
 * Sends the 24 h, 40 h and overdue reminders, each at most once per request.
 * Also the admin_init backstop, throttled to once per 5 minutes unless forced.
 *
 * @param bool $force Skip the throttle.
 */
function tkdesk_tick( bool $force = false ): void {
	global $wpdb;
	if ( ! $force && get_transient( 'tkdesk_tick_throttle' ) ) {
		return;
	}
	set_transient( 'tkdesk_tick_throttle', 1, 5 * MINUTE_IN_SECONDS );
	$now = tkdesk_now();
	update_option( 'tkdesk_last_tick', $now, false );

	$table  = tkdesk_table( 'requests' );
	$stages = array(
		// Stage => hours elapsed, guard column, email key, log event.
		'24h'     => array( 24, 'reminder_24_sent_at', 'mod_24h', 'reminder_24' ),
		'40h'     => array( 40, 'reminder_40_sent_at', 'mod_40h', 'reminder_40' ),
		'overdue' => array( 48, 'overdue_sent_at', 'mod_overdue', 'overdue' ),
	);
	// The overdue guard is set last, so open requests without it are the only ones with work left.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status IN (%s, %s, %s) AND received_at <= %s AND overdue_sent_at IS NULL', $table, 'new', 'in_review', 'needs_info', tkdesk_mysql( $now - 24 * HOUR_IN_SECONDS ) ), ARRAY_A );
	foreach ( $rows as $row ) {
		$row     = tkdesk_request_row( $row );
		$elapsed = $now - tkdesk_ts( $row['received_at'] );
		$due     = array();
		foreach ( $stages as $stage => $s ) {
			if ( $elapsed >= $s[0] * HOUR_IN_SECONDS && null === $row[ $s[1] ] ) {
				$due[ $stage ] = $s;
			}
		}
		$top = array_key_last( $due );
		foreach ( $due as $stage => $s ) {
			// The guard is claimed before sending, so two ticks never send the same reminder.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
			if ( ! $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %s WHERE id = %d AND %i IS NULL', $table, $s[1], tkdesk_mysql( $now ), $row['id'], $s[1] ) ) ) {
				continue;
			}
			if ( $stage !== $top ) {
				tkdesk_log( $row['id'], $s[3], array( 'skipped' => true ), 0 );
				continue;
			}
			tkdesk_send_email( $s[2], $row );
			tkdesk_log( $row['id'], $s[3], array(), 0 );
			/**
			 * Fires when a reminder stage is reached.
			 *
			 * @param int    $id    Request ID.
			 * @param string $stage 24h | 40h | overdue.
			 */
			do_action( 'tkdesk_reminder_due', $row['id'], $stage );
		}
	}
	// One status update to requesters whose request is still open after 6 days (poster.php).
	tkdesk_status_updates();
}

/**
 * Daily: retention purge, quarantine purge, old spam, log pruning.
 */
function tkdesk_daily(): void {
	global $wpdb;
	$now      = tkdesk_now();
	$requests = tkdesk_table( 'requests' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status IN (%s, %s) AND resolved_at < %s AND purged_at IS NULL', $requests, 'removed', 'declined', tkdesk_mysql( $now - (int) tkdesk_setting( 'retention_days' ) * DAY_IN_SECONDS ) ) );
	foreach ( $ids as $id ) {
		tkdesk_purge_request_data( (int) $id );
	}

	if ( function_exists( 'tkdesk_quarantine_purge_due' ) ) {
		tkdesk_quarantine_purge_due();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$spam = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND received_at < %s AND minor = 0', $requests, 'spam', tkdesk_mysql( $now - 30 * DAY_IN_SECONDS ) ) ) );
	foreach ( $spam as $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$wpdb->delete( tkdesk_table( 'items' ), array( 'request_id' => $id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$wpdb->delete( $requests, array( 'id' => $id ) );
	}

	$years = (int) tkdesk_setting( 'log_retention_years' );
	if ( $years > 0 ) {
		$log = tkdesk_table( 'log' );
		// The pruner is the only code that deletes log rows; the anchor keeps the chain verifiable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT id, row_hash FROM %i WHERE created_at < %s ORDER BY id DESC LIMIT 1', $log, tkdesk_mysql( $now - $years * YEAR_IN_SECONDS ) ), ARRAY_A );
		if ( $last ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $log, $last['id'] ) );
			update_option( 'tkdesk_log_anchor', $last['row_hash'], false );
		}
	}
}

/**
 * Deletes a request's encrypted data and hashes; ID, times, item references, decision and log stay.
 * A request flagged as involving a person under 18 is kept (minors.php): this returns false for it, so
 * neither the retention_days purge nor the privacy eraser touches it.
 *
 * @param int $id Request ID.
 * @return bool
 */
function tkdesk_purge_request_data( int $id ): bool {
	global $wpdb;
	$set   = array(
		'contact_enc' => null,
		'text_enc'    => null,
		'email_hash'  => null,
		'ip_hash'     => null,
		'token_hash'  => null,
		'purged_at'   => tkdesk_mysql( tkdesk_now() ),
	);
	$where = array(
		'id'    => $id,
		'minor' => 0,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( ! $wpdb->update( tkdesk_table( 'requests' ), $set, $where ) ) {
		return false;
	}
	tkdesk_log( $id, 'purged_request_data' );
	return true;
}
