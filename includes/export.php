<?php
/**
 * CSV export of the record of actions (plan 5.5): one row per log entry with its request's outcome.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_tkdesk_export', 'tkdesk_export' );

/**
 * Streams takedown-desk-record-YYYY-MM-DD.csv. Query: from, to (unix), request, event.
 */
function tkdesk_export() {
	check_admin_referer( 'tkdesk_export' );
	if ( ! tkdesk_can( 'tkdesk_export' ) ) {
		wp_die( esc_html__( 'You are not allowed to export the record.', 'takedown-desk' ), 403 );
	}
	$args = array();
	foreach ( array( 'from', 'to', 'request', 'event' ) as $key ) {
		$args[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}
	$rows = tkdesk_export_rows( $args );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="takedown-desk-record-' . wp_date( 'Y-m-d', tkdesk_now() ) . '.csv"' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the response body, not a file.
	$out = fopen( 'php://output', 'w' );
	foreach ( $rows as $row ) {
		fputcsv( $out, $row, ',', '"', '' );
	}
	exit;
}

/**
 * Export rows, header row first. Test requests and rows of deleted requests are left out; cells
 * that a spreadsheet would run as a formula get a leading single quote. Logs `exported`.
 *
 * @param array $args from, to (unix), request (ID or public ID), event.
 * @return array[]
 */
function tkdesk_export_rows( array $args ): array {
	global $wpdb;
	$from    = absint( $args['from'] ?? 0 );
	$to      = absint( $args['to'] ?? 0 );
	$ref     = trim( (string) ( $args['request'] ?? '' ) );
	$found   = '' !== $ref && ! ctype_digit( $ref ) ? tkdesk_get_request_by_public_id( $ref ) : null;
	$request = $found ? $found['id'] : ( ctype_digit( $ref ) ? (int) $ref : ( '' === $ref ? 0 : -1 ) );
	$event   = sanitize_key( (string) ( $args['event'] ?? '' ) );
	// The request type column (ncii | dmca) comes with copyright notices (setting dmca).
	$type = tkdesk_setting( 'dmca' ) ? array( 'type' ) : array();
	/**
	 * Filters the CSV columns; add a column here and fill it in tkdesk_csv_row.
	 *
	 * @param string[] $columns Column names.
	 */
	$columns = (array) apply_filters( 'tkdesk_csv_columns', array_merge( array( 'request_id' ), $type, array( 'received_at_utc', 'due_at_utc', 'resolved_at_utc', 'status', 'reason', 'hours_to_resolve', 'within_48h', 'item_type', 'item_id', 'item_label', 'copies_removed', 'event_time_utc', 'event', 'actor', 'detail' ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_log, tkdesk_requests, tkdesk_items.
	$logs = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT l.*, r.public_id, r.type, r.received_at, r.due_at, r.resolved_at, r.status, r.decline_reason, r.object_type, r.object_id, r.item_label, ( SELECT COUNT(*) FROM %i i WHERE i.request_id = l.request_id AND i.role <> %s AND i.action IN (%s, %s, %s) ) AS copies_removed FROM %i l LEFT JOIN %i r ON r.id = l.request_id WHERE ( l.request_id = 0 OR r.is_test = 0 ) AND l.created_at BETWEEN %s AND %s AND ( %d = 0 OR l.request_id = %d ) AND ( %s = %s OR l.event = %s ) ORDER BY l.id ASC', tkdesk_table( 'items' ), 'reported', 'offline', 'deleted', 'image_removed', tkdesk_table( 'log' ), tkdesk_table( 'requests' ), $from ? tkdesk_mysql( $from ) : '1970-01-01 00:00:00', $to ? tkdesk_mysql( $to ) : '9999-12-31 23:59:59', $request, $request, $event, '', $event ), ARRAY_A );
	$out  = array( $columns );
	foreach ( $logs as $log ) {
		$received = tkdesk_ts( $log['received_at'] );
		$resolved = tkdesk_ts( $log['resolved_at'] );
		$row      = array(
			'request_id'       => (string) $log['public_id'],
			'type'             => (string) $log['type'],
			'received_at_utc'  => (string) $log['received_at'],
			'due_at_utc'       => (string) $log['due_at'],
			'resolved_at_utc'  => (string) $log['resolved_at'],
			'status'           => (string) $log['status'],
			'reason'           => (string) $log['decline_reason'],
			'hours_to_resolve' => $resolved ? round( ( $resolved - $received ) / HOUR_IN_SECONDS, 2 ) : '',
			'within_48h'       => $resolved ? ( $resolved <= tkdesk_ts( $log['due_at'] ) ? 'yes' : 'no' ) : '',
			'item_type'        => (string) $log['object_type'],
			'item_id'          => (string) $log['object_id'],
			'item_label'       => (string) $log['item_label'],
			'copies_removed'   => $log['public_id'] ? (int) $log['copies_removed'] : '',
			'event_time_utc'   => (string) $log['created_at'],
			'event'            => (string) $log['event'],
			'actor'            => (string) $log['actor_label'],
			'detail'           => (string) $log['detail'],
		);
		/**
		 * Filters one CSV row (column => value) before escaping.
		 *
		 * @param array $row Row.
		 * @param array $log Log row joined with its request's columns.
		 */
		$row   = (array) apply_filters( 'tkdesk_csv_row', $row, $log );
		$cells = array();
		foreach ( $columns as $column ) {
			// OWASP list: = + - @ plus tab and carriage return, which spreadsheets also treat as a formula start.
			$cell    = (string) ( $row[ $column ] ?? '' );
			$cells[] = '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ? "'" . $cell : $cell;
		}
		$out[] = $cells;
	}
	tkdesk_log( 0, 'exported', array( 'rows' => count( $out ) - 1 ) );
	return $out;
}
