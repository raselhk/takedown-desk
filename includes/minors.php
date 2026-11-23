<?php
/**
 * Requests that involve a person under 18, and safe image previews for moderators.
 *
 * A request is flagged (`minor` = 1) when the requester says the person in the image was under 18, or when a
 * moderator marks it. 18 U.S.C. 2258A asks online services that learn of apparent child sexual abuse material
 * to report it to the NCMEC CyberTipline and to preserve it for 1 year with limited access. While flagged:
 * - items can only be taken offline: permanent deletion and restore are refused (tkdesk_remove_items(),
 *   tkdesk_restore_items() in objects.php);
 * - quarantined files are never purged automatically, whatever the purge_removed_days setting
 *   (tkdesk_quarantine_purge_due() in quarantine.php);
 * - the retention_days purge and the spam cleanup (tkdesk_daily() in cron.php), the privacy eraser
 *   (privacy.php) and uninstall with delete_on_uninstall (uninstall.php) leave the request and its files alone;
 * - the admin app gets no image previews or thumbnails, and shows the CyberTipline panel.
 * Once a CyberTipline report is saved, the flag can be cleared only 1 year after the reported date. Before a
 * report, clearing corrects a mistake. Clearing (logged) releases the hold: the normal rules apply again.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tkdesk_register_blur_meta' );
add_action( 'rest_api_init', 'tkdesk_minor_routes' );

/**
 * Per-user preference "Always blur previews" (default on), saved through core's /wp/v2/users/me.
 */
function tkdesk_register_blur_meta(): void {
	register_meta(
		'user',
		'_tkdesk_blur_previews',
		array(
			'type'          => 'boolean',
			'single'        => true,
			'default'       => true,
			'show_in_rest'  => true,
			'auth_callback' => static function ( $allowed, $key, $object_id, $user_id ) {
				return (int) $object_id === (int) $user_id && user_can( (int) $user_id, 'tkdesk_manage_requests' );
			},
		)
	);
}

/**
 * Whether a user's previews start blurred.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function tkdesk_blur_previews( int $user_id ): bool {
	return (bool) get_user_meta( $user_id, '_tkdesk_blur_previews', true );
}

/**
 * Last day of the 1-year hold that follows a saved CyberTipline report.
 *
 * @param array $request Request row.
 * @return string Y-m-d, or '' when the request is not flagged or no report is saved.
 */
function tkdesk_minor_hold_until( array $request ): string {
	if ( empty( $request['minor'] ) || empty( $request['cybertip_on'] ) ) {
		return '';
	}
	return ( new DateTimeImmutable( (string) $request['cybertip_on'] ) )->modify( '+1 year' )->format( 'Y-m-d' );
}

/**
 * Set or clear the flag (`minor`), or save the CyberTipline report (`report`, `reported_on` Y-m-d, default
 * today). Every change is logged.
 *
 * @param int   $id Request ID.
 * @param array $in minor (bool), or report and reported_on.
 * @return array|WP_Error Updated row, or tkdesk_not_ncii (409) for a copyright notice.
 */
function tkdesk_update_minor( int $id, array $in ) {
	global $wpdb;
	$request = tkdesk_get_request( $id );
	if ( ! $request ) {
		return new WP_Error( 'tkdesk_not_found', __( 'This request does not exist.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	// The minors path belongs to intimate image requests; a copyright notice never carries the flag.
	if ( 'ncii' !== $request['type'] ) {
		return new WP_Error( 'tkdesk_not_ncii', __( 'This applies to intimate image requests only.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	$table = tkdesk_table( 'requests' );
	$today = wp_date( 'Y-m-d', tkdesk_now() );

	if ( array_key_exists( 'minor', $in ) ) {
		$flag = rest_sanitize_boolean( $in['minor'] );
		if ( (bool) $request['minor'] === $flag ) {
			return $request;
		}
		$until = tkdesk_minor_hold_until( $request );
		if ( ! $flag && '' !== $until && $today < $until ) {
			/* translators: %s: date. */
			return new WP_Error( 'tkdesk_minor_hold', sprintf( __( 'A CyberTipline report is saved for this request, so the flag stays until %s.', 'takedown-desk' ), mysql2date( get_option( 'date_format' ), $until ) ), array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$wpdb->update( $table, array( 'minor' => $flag ? 1 : 0 ), array( 'id' => $id ) );
		tkdesk_log( $id, $flag ? 'minor_flagged' : 'minor_cleared' );
		return tkdesk_get_request( $id );
	}

	if ( empty( $request['minor'] ) ) {
		return new WP_Error( 'tkdesk_not_minor', __( 'Mark the request as involving a person under 18 first.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	$report = trim( sanitize_text_field( is_scalar( $in['report'] ?? '' ) ? (string) ( $in['report'] ?? '' ) : '' ) );
	$date   = trim( is_scalar( $in['reported_on'] ?? '' ) ? (string) ( $in['reported_on'] ?? '' ) : '' );
	$date   = '' === $date ? $today : $date;
	$parsed = DateTime::createFromFormat( '!Y-m-d', $date );
	$errors = array();
	if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]{0,63}$/', $report ) ) {
		$errors['report'] = __( 'Enter the report number from NCMEC, using letters, digits and hyphens.', 'takedown-desk' );
	}
	if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date > $today ) {
		$errors['reported_on'] = __( 'Enter the date you filed the report. It cannot be in the future.', 'takedown-desk' );
	}
	if ( $errors ) {
		return new WP_Error(
			'tkdesk_invalid_report',
			reset( $errors ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
	$set = array(
		'cybertip_report' => $report,
		'cybertip_on'     => $date,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$wpdb->update( $table, $set, array( 'id' => $id ) );
	tkdesk_log(
		$id,
		'cybertip_reported',
		array(
			'label' => $report,
			'on'    => $date,
		)
	);
	return tkdesk_get_request( $id );
}

/**
 * Up to four safe previews of the reported item (medium size): the image itself, or the images in a post.
 * Files in quarantine have no URL, so they drop out.
 *
 * @param array $request Request row.
 * @return array[] array( url, label ).
 */
function tkdesk_previews( array $request ): array {
	$ids = array();
	if ( 'attachment' === $request['object_type'] && wp_attachment_is_image( (int) $request['object_id'] ) ) {
		$ids = array( (int) $request['object_id'] );
	} elseif ( 'post' === $request['object_type'] && function_exists( 'tkdesk_post_images' ) ) {
		$ids = tkdesk_post_images( (int) $request['object_id'] );
	}
	$out = array();
	foreach ( array_slice( $ids, 0, 4 ) as $aid ) {
		$url = (string) wp_get_attachment_image_url( (int) $aid, 'medium' );
		if ( '' !== $url ) {
			$out[] = array(
				'url'   => $url,
				'label' => (string) get_the_title( (int) $aid ),
			);
		}
	}
	return $out;
}

/**
 * Adds the minors path and preview data to the detail JSON of tkdesk_rest_request(). A flagged request
 * carries no image URL at all, and its removed items show no restore option or purge date.
 *
 * @param array $out Detail JSON.
 * @param array $row Request row.
 * @return array
 */
function tkdesk_minor_detail( array $out, array $row ): array {
	$minor = ! empty( $row['minor'] );
	$until = tkdesk_minor_hold_until( $row );
	$today = wp_date( 'Y-m-d', tkdesk_now() );
	if ( $minor ) {
		foreach ( $out['images'] as $i => $image ) {
			$out['images'][ $i ]['thumb_url'] = '';
		}
		foreach ( $out['items'] as $i => $item ) {
			$out['items'][ $i ]['can_restore'] = false;
			$out['items'][ $i ]['purge_after'] = 0;
		}
	}
	$out['cybertip']      = array(
		'report'           => (string) $row['cybertip_report'],
		'reported_on'      => (string) $row['cybertip_on'],
		'reported_on_text' => $row['cybertip_on'] ? mysql2date( get_option( 'date_format' ), $row['cybertip_on'] ) : '',
		'hold_until_text'  => '' !== $until ? mysql2date( get_option( 'date_format' ), $until ) : '',
		'can_clear'        => '' === $until || $today >= $until,
		'today'            => $today,
	);
	$out['previews']      = $minor ? array() : tkdesk_previews( $row );
	$out['blur_previews'] = tkdesk_blur_previews( get_current_user_id() );
	return $out;
}

/**
 * Admin routes: POST /admin/requests/{id}/minor and /preview (moderators).
 */
function tkdesk_minor_routes(): void {
	$can = static function () {
		return tkdesk_can( 'tkdesk_manage_requests' );
	};
	foreach ( array(
		'minor'   => 'tkdesk_rest_admin_minor',
		'preview' => 'tkdesk_rest_admin_preview',
	) as $path => $callback ) {
		register_rest_route(
			'takedown-desk/v1',
			'/admin/requests/(?P<id>[0-9]+)/' . $path,
			array(
				'methods'             => 'POST',
				'callback'            => $callback,
				'permission_callback' => $can,
			)
		);
	}
}

/**
 * POST /admin/requests/{id}/minor { minor } or { report, reported_on }: detail JSON.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_minor( WP_REST_Request $request ) {
	$in  = $request->has_param( 'minor' ) ? array( 'minor' => $request->get_param( 'minor' ) ) : array(
		'report'      => $request->get_param( 'report' ),
		'reported_on' => $request->get_param( 'reported_on' ),
	);
	$row = tkdesk_update_minor( (int) $request['id'], $in );
	return is_wp_error( $row ) ? $row : tkdesk_rest_request( $row, true );
}

/**
 * POST /admin/requests/{id}/preview: logs that a moderator chose to see the previews.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { ok }
 */
function tkdesk_rest_admin_preview( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	if ( ! empty( $row['minor'] ) ) {
		return new WP_Error( 'tkdesk_minor_hold', __( 'Previews are off because the person in the image was under 18.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	tkdesk_log( $row['id'], 'preview_shown' );
	return array( 'ok' => true );
}
