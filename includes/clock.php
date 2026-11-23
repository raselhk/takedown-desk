<?php
/**
 * Clock: the single time source, the 48-hour deadline (business-day target for copyright notices) and its display.
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
 * Deadline for a request received at a given time: 48 hours, or for a copyright notice the target of
 * setting dmca_days business days.
 *
 * @param int    $received_ts Unix timestamp.
 * @param string $type        Request type (ncii | dmca).
 * @return int
 */
function tkdesk_due( int $received_ts, string $type = 'ncii' ): int {
	return 'dmca' === $type ? tkdesk_add_business_days( $received_ts, max( 1, (int) tkdesk_setting( 'dmca_days' ) ) ) : $received_ts + 48 * HOUR_IN_SECONDS;
}

/**
 * Adds business days in the site timezone: one calendar day at a time at the same local time of day; only
 * days that land on Monday to Friday count. No holiday calendar.
 *
 * @param int $ts   Unix timestamp.
 * @param int $days Business days.
 * @return int
 */
function tkdesk_add_business_days( int $ts, int $days ): int {
	$date = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
	while ( $days > 0 ) {
		$date  = $date->modify( '+1 day' );
		$days -= (int) $date->format( 'N' ) <= 5 ? 1 : 0;
	}
	return $date->getTimestamp();
}

/**
 * When the reminders of a request fall due: half of its window, five sixths of it, and the deadline.
 * For a 48-hour request that is 24 h, 40 h and 48 h after it arrived.
 *
 * @param array $request Request row.
 * @return array array( 'first' => ts, 'second' => ts, 'overdue' => ts ).
 */
function tkdesk_reminder_points( array $request ): array {
	$received = tkdesk_ts( $request['received_at'] ?? null );
	$due      = tkdesk_ts( $request['due_at'] ?? null );
	return array(
		'first'   => $received + intdiv( $due - $received, 2 ),
		'second'  => $received + intdiv( 5 * ( $due - $received ), 6 ),
		'overdue' => $due,
	);
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
