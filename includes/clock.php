<?php
/**
 * Clock: the single time source, the 48-hour deadline and its display.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current Unix time. The only place the plugin reads the system clock.
 *
 * @return int
 */
function tkdesk_now(): int {
	/**
	 * Filters the current time (tests move the clock with it).
	 *
	 * @param int $now Unix timestamp.
	 */
	return (int) apply_filters( 'tkdesk_now', time() ); // tkdesk-clock-source.
}

/**
 * Unix timestamp to a GMT MySQL datetime.
 *
 * @param int $ts Unix timestamp.
 * @return string
 */
function tkdesk_mysql( int $ts ): string {
	return gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * GMT MySQL datetime to a Unix timestamp (0 for null or invalid).
 *
 * @param string|null $mysql_gmt GMT datetime.
 * @return int
 */
function tkdesk_ts( ?string $mysql_gmt ): int {
	$date = null === $mysql_gmt ? false : date_create( $mysql_gmt, new DateTimeZone( 'UTC' ) );
	return $date ? $date->getTimestamp() : 0;
}

/**
 * Deadline for a request received at a given time.
 *
 * @param int $received_ts Unix timestamp.
 * @return int
 */
function tkdesk_due( int $received_ts ): int {
	return $received_ts + 48 * HOUR_IN_SECONDS;
}

/**
 * Statuses on which the clock runs.
 *
 * @return string[]
 */
function tkdesk_open_statuses(): array {
	return array( 'new', 'in_review', 'needs_info' );
}

/**
 * Whether a status is open.
 *
 * @param string $status Status.
 * @return bool
 */
function tkdesk_is_open( string $status ): bool {
	return in_array( $status, tkdesk_open_statuses(), true );
}

/**
 * Clock state of a request row.
 *
 * @param array $request Request row.
 * @return string closed | overdue | urgent | warn | ok
 */
function tkdesk_clock_state( array $request ): string {
	if ( ! tkdesk_is_open( (string) ( $request['status'] ?? '' ) ) ) {
		return 'closed';
	}
	$left = tkdesk_ts( $request['due_at'] ?? null ) - tkdesk_now();
	if ( $left < 0 ) {
		return 'overdue';
	}
	if ( $left <= 8 * HOUR_IN_SECONDS ) {
		return 'urgent';
	}
	return $left <= 24 * HOUR_IN_SECONDS ? 'warn' : 'ok';
}

/**
 * Duration as hours and minutes, e.g. "17 h 42 min".
 *
 * @param int $seconds Seconds (sign ignored).
 * @return string
 */
function tkdesk_duration_text( int $seconds ): string {
	$minutes = intdiv( abs( $seconds ), MINUTE_IN_SECONDS );
	/* translators: 1: hours, 2: minutes. */
	return sprintf( __( '%1$d h %2$d min', 'takedown-desk' ), intdiv( $minutes, 60 ), $minutes % 60 );
}

/**
 * Deadline in the site timezone with the site date and time formats and the timezone abbreviation.
 *
 * @param int $ts Unix timestamp.
 * @return string
 */
function tkdesk_format_deadline( int $ts ): string {
	return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $ts );
}
