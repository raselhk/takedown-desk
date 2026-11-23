<?php
/**
 * REST API, namespace takedown-desk/v1. Public routes, then the admin routes of the request desk.
 * Public routes use __return_true as permission callback: every check runs inside the callback,
 * because WordPress calls permission callbacks twice and would double-count the rate limit.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'tkdesk_rest_routes' );
add_filter( 'rest_post_dispatch', 'tkdesk_rest_no_store', 10, 3 );

/**
 * Shared submit path for REST and admin-post: validation, spam check, duplicate guard, create.
 * Fields come first, so an invalid form always gets its errors and never uses up the rate limit.
 *
 * @param array  $in Raw input.
 * @param string $ip Client IP.
 * @return array|WP_Error See tkdesk_submit_result().
 */
function tkdesk_handle_submit( array $in, string $ip ) {
	global $wpdb;
	$clean = tkdesk_validate_request( $in );
	if ( is_wp_error( $clean ) ) {
		// The no-JS question spends nothing, so its error is reported with the field errors in one round.
		$human = empty( $in['tkdesk_pow'] ) && ! empty( $in['tkdesk_q'] ) && tkdesk_setting( 'bot_check' ) ? tkdesk_bot_check( $in ) : true;
		if ( is_wp_error( $human ) ) {
			$data            = (array) $clean->get_error_data();
			$data['fields'] += (array) ( $human->get_error_data()['fields'] ?? array() );
			$clean->add_data( $data );
		}
		return $clean;
	}
	$verdict = tkdesk_spam_check( $in, $ip );
	if ( is_wp_error( $verdict ) ) {
		return $verdict;
	}
	if ( 'silent' === $verdict ) {
		// Fake success with a plausible, not yet used ID and a link that leads nowhere; nothing is stored.
		$yymm = wp_date( 'ym', tkdesk_now() );
		$fake = sprintf( 'TD-%s-%04d', $yymm, (int) get_option( 'tkdesk_seq_' . $yymm, 0 ) + wp_rand( 1, 40 ) );
		return tkdesk_submit_result( $fake, $clean['email'], tkdesk_now(), bin2hex( random_bytes( 32 ) ), $clean['type'] );
	}
	$target = '' !== $clean['item'] ? tkdesk_parse_item_ref( $clean['item'] ) : null;
	$target = $target ? $target : tkdesk_resolve_url( $clean['url'] );
	if ( $target && '' !== $ip ) {
		// Duplicate guard: same email, type, object and IP within 10 minutes returns the first request. The IP
		// keeps it from telling a stranger whether someone else reported that object with that email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$first = $wpdb->get_row( $wpdb->prepare( 'SELECT public_id, received_at FROM %i WHERE email_hash = %s AND ip_hash = %s AND type = %s AND object_type = %s AND object_id = %d AND received_at >= %s ORDER BY id ASC LIMIT 1', tkdesk_table( 'requests' ), tkdesk_hash( strtolower( $clean['email'] ) ), tkdesk_hash( $ip ), $clean['type'], $target['object_type'], $target['object_id'], tkdesk_mysql( tkdesk_now() - 10 * MINUTE_IN_SECONDS ) ), ARRAY_A );
		if ( $first ) {
			// The first request's link is not kept in clear, so the copy sent then is the way back to it.
			return tkdesk_submit_result( $first['public_id'], $clean['email'], tkdesk_ts( $first['received_at'] ), '', $clean['type'] );
		}
	}
	$locale  = isset( $in['locale'] ) && is_string( $in['locale'] ) && preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]+)*$/', $in['locale'] ) ? $in['locale'] : determine_locale();
	$ctx     = array(
		'source' => '' !== $clean['item'] ? 'content_link' : 'form',
		'ip'     => $ip,
		'locale' => $locale,
		'status' => 'spam' === $verdict ? 'spam' : 'new',
	);
	$created = tkdesk_create_request( $clean, $ctx );
	if ( is_wp_error( $created ) ) {
		return $created;
	}
	tkdesk_rate_count( $ip );
	// Spam gets no confirmation email, so the page must not say a copy was sent.
	$sent = 'spam' !== $verdict && tkdesk_email_saved( tkdesk_email_key( 'requester_received', $created['request'] ) )['active'];
	return tkdesk_submit_result( $created['request']['public_id'], $sent ? $clean['email'] : '', tkdesk_ts( $created['request']['received_at'] ), $created['token'], $clean['type'] );
}

/**
 * Success payload for a submission.
 *
 * @param string $public_id Public ID.
 * @param string $email     Address the confirmation went to, '' when none was sent. Only a masked form is returned.
 * @param int    $received  Time the request arrived.
 * @param string $token     Raw requester token for the status link, '' for none.
 * @param string $type      Request type (ncii | dmca): sets the deadline and the status page.
 * @return array ok, public_id, email (masked), received and due (site time), status_url, message, done_token.
 */
function tkdesk_submit_result( string $public_id, string $email, int $received, string $token, string $type = 'ncii' ): array {
	$request = array(
		'public_id' => $public_id,
		'type'      => $type,
	);
	return array(
		'ok'         => true,
		'public_id'  => $public_id,
		'email'      => tkdesk_mask_email( $email ),
		'received'   => tkdesk_format_deadline( $received ),
		'due'        => tkdesk_format_deadline( tkdesk_due( $received, $type ) ),
		'status_url' => '' === $token ? '' : tkdesk_status_url( $request, $token ),
		/* translators: %s: request ID. */
		'message'    => sprintf( 'dmca' === $type ? __( 'We received your copyright notice. Its ID is %s.', 'takedown-desk' ) : __( 'We received your request. Your request ID is %s.', 'takedown-desk' ), $public_id ),
		'done_token' => substr( tkdesk_hash( $public_id . '|done' ), 0, 12 ),
	);
}

/**
 * Register the public routes.
 */
function tkdesk_rest_routes() {
	$one = '/requests/(?P<public_id>TD-[0-9]{4}-[0-9]{4,})';
	register_rest_route(
		'takedown-desk/v1',
		'/requests',
		array(
			'methods'             => 'POST',
			'callback'            => 'tkdesk_rest_submit',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'takedown-desk/v1',
		$one,
		array(
			'methods'             => 'GET',
			'callback'            => 'tkdesk_rest_status',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'takedown-desk/v1',
		$one . '/details',
		array(
			'methods'             => 'POST',
			'callback'            => 'tkdesk_rest_details',
			'permission_callback' => '__return_true',
		)
	);
	// Proof-of-work challenge for the form's bot check; fetched by form.js, never embedded in a cacheable page.
	register_rest_route(
		'takedown-desk/v1',
		'/challenge',
		array(
			'methods'             => 'GET',
			'callback'            => 'tkdesk_pow_challenge',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * POST /requests. Uploaded file parts are never read.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tkdesk_rest_submit( WP_REST_Request $request ) {
	$result = tkdesk_handle_submit( $request->get_params(), tkdesk_client_ip() );
	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
}

/**
 * The request named in the route, when the token matches.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Request row or tkdesk_bad_token (403).
 */
function tkdesk_rest_find( WP_REST_Request $request ) {
	$row   = tkdesk_get_request_by_public_id( (string) $request['public_id'] );
	$token = $request->get_param( 'token' );
	if ( ! $row || ! is_string( $token ) || ! tkdesk_verify_token( $row, $token ) ) {
		return new WP_Error( 'tkdesk_bad_token', __( 'This link does not work. Use the link in the most recent email about your request.', 'takedown-desk' ), array( 'status' => 403 ) );
	}
	return $row;
}

/**
 * GET /requests/{public_id}?token=. Spam shows as received, so senders learn nothing.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_status( WP_REST_Request $request ) {
	$row = tkdesk_rest_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$labels   = array(
		'new'        => __( 'Received', 'takedown-desk' ),
		'in_review'  => __( 'In review', 'takedown-desk' ),
		'needs_info' => __( 'Waiting for your details', 'takedown-desk' ),
		'removed'    => __( 'Removed', 'takedown-desk' ),
		'declined'   => __( 'Not removed', 'takedown-desk' ),
	);
	$status   = isset( $labels[ $row['status'] ] ) ? $row['status'] : 'new';
	$question = '';
	if ( 'needs_info' === $status ) {
		$text = tkdesk_request_text( $row );
		// The latest moderator entry in the thread is the open question.
		foreach ( is_wp_error( $text ) ? array() : (array) $text['info_thread'] as $entry ) {
			if ( 'moderator' === ( $entry['from'] ?? '' ) ) {
				$question = (string) $entry['text'];
			}
		}
	}
	return array(
		'public_id'    => $row['public_id'],
		'status'       => $status,
		'status_label' => $labels[ $status ],
		'needs_info'   => 'needs_info' === $status,
		'question'     => $question,
		'resolved_at'  => $row['resolved_at'] ? gmdate( 'c', tkdesk_ts( $row['resolved_at'] ) ) : null,
	);
}

/**
 * POST /requests/{public_id}/details with { token, message }.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_details( WP_REST_Request $request ) {
	$row = tkdesk_rest_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$message = $request->get_param( 'message' );
	$result  = tkdesk_add_requester_reply( (int) $row['id'], is_string( $message ) ? $message : '' );
	return is_wp_error( $result ) ? $result : array( 'ok' => true );
}

/**
 * Cache-Control: no-store on every response of our namespace, errors included.
 *
 * @param WP_HTTP_Response $response Response.
 * @param WP_REST_Server   $server   Server.
 * @param WP_REST_Request  $request  Request.
 * @return WP_HTTP_Response
 */
function tkdesk_rest_no_store( $response, $server, $request ) {
	if ( $response instanceof WP_HTTP_Response && 0 === strpos( $request->get_route(), '/takedown-desk/v1/' ) ) {
		$response->header( 'Cache-Control', 'no-store' );
	}
	return $response;
}

add_action( 'rest_api_init', 'tkdesk_rest_admin_routes' );

/**
 * Register the admin routes. Each entry: method, callback, capability (default tkdesk_manage_requests).
 */
function tkdesk_rest_admin_routes() {
	$one    = '/admin/requests/(?P<id>[0-9]+)';
	$routes = array(
		'/admin/requests'   => array( array( 'GET', 'tkdesk_rest_admin_list' ), array( 'POST', 'tkdesk_rest_admin_create' ) ),
		$one                => array( array( 'GET', 'tkdesk_rest_admin_detail' ) ),
		$one . '/contact'   => array( array( 'GET', 'tkdesk_rest_admin_contact' ) ),
		$one . '/open'      => array( array( 'POST', 'tkdesk_rest_admin_open' ) ),
		$one . '/assign'    => array( array( 'POST', 'tkdesk_rest_admin_assign' ) ),
		$one . '/match'     => array( array( 'POST', 'tkdesk_rest_admin_match' ) ),
		$one . '/copies'    => array( array( 'POST', 'tkdesk_rest_admin_copies' ) ),
		$one . '/remove'    => array( array( 'POST', 'tkdesk_rest_admin_remove' ) ),
		$one . '/restore'   => array( array( 'POST', 'tkdesk_rest_admin_remove', 'tkdesk_manage_settings' ) ),
		$one . '/hide'      => array( array( 'POST', 'tkdesk_rest_admin_remove' ) ),
		$one . '/unhide'    => array( array( 'POST', 'tkdesk_rest_admin_remove' ) ),
		$one . '/check'     => array( array( 'POST', 'tkdesk_rest_admin_remove' ) ),
		$one . '/resume'    => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/ask'       => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/continue'  => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/decline'   => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/spam'      => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/reopen'    => array( array( 'POST', 'tkdesk_rest_admin_transition' ) ),
		$one . '/note'      => array( array( 'POST', 'tkdesk_rest_admin_note' ) ),
		'/admin/log'        => array( array( 'GET', 'tkdesk_rest_admin_log' ) ),
		'/admin/log/verify' => array( array( 'POST', 'tkdesk_log_verify' ) ),
	) + array(
		// Day 3: status, settings, setup and test data.
		'/admin/status'            => array( array( 'GET', 'tkdesk_status' ) ),
		'/admin/status/dismiss'    => array( array( 'POST', 'tkdesk_status_dismiss' ) ),
		'/admin/settings'          => array( array( 'GET', 'tkdesk_rest_admin_settings', 'tkdesk_manage_settings' ), array( 'POST', 'tkdesk_rest_admin_settings', 'tkdesk_manage_settings' ) ),
		'/admin/setup/notice-page' => array( array( 'POST', 'tkdesk_rest_admin_notice_page', 'tkdesk_manage_settings' ) ),
		'/admin/setup-done'        => array( array( 'POST', 'tkdesk_rest_admin_setup_done', 'tkdesk_manage_settings' ) ),
		'/admin/test-request'      => array( array( 'POST', 'tkdesk_rest_admin_test_request', 'tkdesk_manage_settings' ) ),
		'/admin/test-requests'     => array( array( 'DELETE', 'tkdesk_rest_admin_test_delete', 'tkdesk_manage_settings' ) ),
		'/admin/test-email'        => array( array( 'POST', 'tkdesk_rest_admin_test_email', 'tkdesk_manage_settings' ) ),
		'/admin/email-preview'     => array( array( 'GET', 'tkdesk_rest_admin_test_email', 'tkdesk_manage_settings' ) ),
		'/admin/policy-page'       => array( array( 'POST', 'tkdesk_create_policy_page', 'tkdesk_manage_settings' ) ),
		'/admin/recaptcha/test'    => array( array( 'POST', 'tkdesk_rest_recaptcha_test', 'tkdesk_manage_settings' ) ),
		'/admin/dmca-page'         => array( array( 'POST', 'tkdesk_rest_admin_dmca_page', 'tkdesk_manage_settings' ) ),
	);
	foreach ( $routes as $route => $endpoints ) {
		$args = array();
		foreach ( $endpoints as $endpoint ) {
			$cap    = $endpoint[2] ?? 'tkdesk_manage_requests';
			$args[] = array(
				'methods'             => $endpoint[0],
				'callback'            => $endpoint[1],
				'permission_callback' => static function () use ( $cap ) {
					return tkdesk_can( $cap );
				},
			);
		}
		register_rest_route( 'takedown-desk/v1', $route, $args );
	}
}

/**
 * A request parameter as a string ('' for arrays and missing values).
 *
 * @param WP_REST_Request $request Request.
 * @param string          $key     Parameter.
 * @return string
 */
function tkdesk_rest_str( WP_REST_Request $request, string $key ): string {
	$value = $request->get_param( $key );
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * The request named by the route ID.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Row or tkdesk_not_found (404).
 */
function tkdesk_rest_admin_find( WP_REST_Request $request ) {
	$row = tkdesk_get_request( (int) $request['id'] );
	return $row ? $row : new WP_Error( 'tkdesk_not_found', __( 'This request does not exist or was deleted.', 'takedown-desk' ), array( 'status' => 404 ) );
}

/**
 * Request JSON for the admin app. No contact data ever; the statement only in detail `text`.
 *
 * @param array $row    Request row.
 * @param bool  $detail Add text, items, images, log, index, cdn, restore_days, moderators.
 * @return array
 */
function tkdesk_rest_request( array $row, bool $detail = false ): array {
	global $wpdb;
	$received = tkdesk_ts( $row['received_at'] );
	$due      = tkdesk_ts( $row['due_at'] );
	$resolved = tkdesk_ts( $row['resolved_at'] );
	$labels   = array(
		'new'        => __( 'New', 'takedown-desk' ),
		'in_review'  => __( 'In review', 'takedown-desk' ),
		'needs_info' => __( 'Waiting for details', 'takedown-desk' ),
		'removed'    => __( 'Removed', 'takedown-desk' ),
		'declined'   => __( 'Not removed', 'takedown-desk' ),
		'spam'       => __( 'Spam', 'takedown-desk' ),
	);
	$reason   = (string) $row['decline_reason'];
	$closed   = '';
	if ( $resolved && 'removed' === $row['status'] ) {
		/* translators: %s: duration, e.g. 17 h 42 min. */
		$closed = sprintf( __( 'Removed %s after the request', 'takedown-desk' ), tkdesk_duration_text( $resolved - $received ) );
	} elseif ( $resolved && ! tkdesk_is_open( $row['status'] ) ) {
		/* translators: %s: duration, e.g. 3 h 5 min. */
		$closed = sprintf( __( 'Closed %s after the request', 'takedown-desk' ), tkdesk_duration_text( $resolved - $received ) );
	}

	$object = null;
	if ( $row['object_type'] ) {
		$type   = (string) $row['object_type'];
		$id     = (int) $row['object_id'];
		$desc   = tkdesk_describe( $type, $id );
		$author = $desc && $desc['author_id'] ? get_userdata( (int) $desc['author_id'] ) : false;
		$object = array(
			'type'      => $type,
			'id'        => $id,
			'label'     => $desc ? $desc['label'] : (string) $row['item_label'],
			'url'       => $desc ? (string) $desc['url'] : '',
			// Post edit links are already empty without the capability; comment links are not.
			'edit_url'  => ! $desc || ( 'comment' === $type && ! current_user_can( 'edit_comment', $id ) ) ? '' : (string) $desc['edit_url'],
			'author'    => $author ? array(
				'id'   => $author->ID,
				'name' => $author->display_name,
				'url'  => current_user_can( 'edit_user', $author->ID ) ? get_edit_user_link( $author->ID ) : get_author_posts_url( $author->ID ),
			) : null,
			'post_type' => 'post' === $type ? (string) get_post_type( $id ) : '',
		);
	}
	$assignee = $row['assigned_to'] ? get_userdata( (int) $row['assigned_to'] ) : false;
	$type     = (string) $row['type'];
	$rels     = tkdesk_form_fields( $type )['relationship']['options'] + array( 'site' => __( 'The site (filed by an administrator)', 'takedown-desk' ) );

	$out = array(
		'id'                   => (int) $row['id'],
		'public_id'            => (string) $row['public_id'],
		'type'                 => $type,
		'type_label'           => tkdesk_request_types()[ $type ] ?? '',
		'status'               => (string) $row['status'],
		'status_label'         => $labels[ $row['status'] ] ?? (string) $row['status'],
		'source'               => (string) $row['source'],
		'is_test'              => (bool) $row['is_test'],
		'relationship'         => (string) $row['relationship'],
		'relationship_label'   => $rels[ $row['relationship'] ] ?? '',
		'url_on_site'          => (bool) $row['url_on_site'],
		'decline_reason'       => $reason,
		'decline_reason_label' => tkdesk_decline_reason_labels()[ $reason ] ?? '',
		'decision_note'        => (string) $row['decision_note'],
		'purged_at'            => tkdesk_ts( $row['purged_at'] ),
		'requester_notified'   => ! empty( $row['requester_notified_at'] ),
		'received_at'          => $received,
		'due_at'               => $due,
		'resolved_at'          => $resolved,
		'first_opened_at'      => tkdesk_ts( $row['first_opened_at'] ),
		'signed_at'            => tkdesk_ts( $row['signed_at'] ),
		'clock'                => array(
			'state'         => tkdesk_clock_state( $row ),
			'remaining'     => $due - tkdesk_now(),
			'deadline_text' => tkdesk_format_deadline( $due ),
			'closed_text'   => $closed,
			'late'          => $resolved > $due,
		),
		'object'               => $object,
		'assigned_to'          => $assignee ? tkdesk_rest_person( $assignee ) : null,
		'minor'                => ! empty( $row['minor'] ),
		// Auto-hidden state, advisory abuse signals and the watch list (signals.php).
		'signals'              => function_exists( 'tkdesk_signals' ) ? tkdesk_signals( $row ) : null,
		// The person who posted removed content asked for a review (poster.php); a closed request stays closed.
		'review_requested'     => ( 'removed' === $row['status'] || tkdesk_is_open( (string) $row['status'] ) ) && tkdesk_review_pending( (int) $row['id'] ),
	);
	if ( ! $detail ) {
		return $out;
	}
	// The decline dialog's choices for this type; restored_after_review is only ever set by a restore.
	$reasons = array();
	foreach ( array_keys( tkdesk_decline_reasons( '', $type ) ) as $key ) {
		if ( 'restored_after_review' !== $key ) {
			$reasons[] = array(
				'key'   => $key,
				'label' => tkdesk_decline_reason_labels()[ $key ] ?? $key,
			);
		}
	}

	$text = tkdesk_request_text( $row );
	if ( ! is_wp_error( $text ) ) {
		$text['info_thread'] = array_map(
			static function ( $entry ) {
				$entry['at'] = tkdesk_ts( $entry['at'] ?? null );
				return $entry;
			},
			(array) $text['info_thread']
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$items  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC', tkdesk_table( 'items' ), $row['id'] ), ARRAY_A );
	$images = array();
	if ( 'post' === $row['object_type'] && function_exists( 'tkdesk_post_images' ) ) {
		foreach ( tkdesk_post_images( (int) $row['object_id'] ) as $attachment_id ) {
			$desc     = tkdesk_describe( 'attachment', (int) $attachment_id );
			$images[] = array(
				'attachment_id' => (int) $attachment_id,
				'label'         => $desc ? $desc['label'] : '',
				'thumb_url'     => (string) wp_get_attachment_image_url( (int) $attachment_id, 'thumbnail' ),
			);
		}
	}
	// File and page links of everything acted on, for the "Check your CDN" card.
	$types = tkdesk_object_types();
	$acted = false;
	$urls  = array();
	foreach ( $items as $item ) {
		if ( ! in_array( $item['action'], array( 'offline', 'image_removed', 'deleted' ), true ) ) {
			continue;
		}
		$acted  = true;
		$urls[] = (string) $item['url'];
		$purge  = $types[ $item['object_type'] ]['purge_urls'] ?? null;
		if ( is_callable( $purge ) ) {
			$urls = array_merge( $urls, (array) call_user_func( $purge, (int) $item['object_id'] ) );
		}
	}
	$plugin = function_exists( 'tkdesk_caching_plugin' ) ? tkdesk_caching_plugin() : '';
	// The latest removal check, for the proof line or the still-reachable banner.
	$log   = tkdesk_log_for_request( (int) $row['id'] );
	$reach = null;
	foreach ( $log as $entry ) {
		if ( in_array( $entry['event'], array( 'verified_unreachable', 'still_reachable', 'reach_unchecked' ), true ) ) {
			$reach = array(
				'event'      => (string) $entry['event'],
				'at'         => tkdesk_ts( $entry['created_at'] ),
				'recheck_at' => (int) wp_next_scheduled( 'tkdesk_reach_recheck', array( (int) $row['id'] ) ),
			) + (array) json_decode( (string) $entry['detail'], true );
		}
	}

	// Minors path and safe previews: minors.php.
	return tkdesk_minor_detail(
		$out + array(
			'text'            => is_wp_error( $text ) ? null : $text,
			'text_error'      => is_wp_error( $text ) ? str_replace( 'tkdesk_', '', $text->get_error_code() ) : '',
			'items'           => array_map( 'tkdesk_rest_item', $items ),
			'images'          => $images,
			'log'             => array_map( 'tkdesk_rest_log_row', $log ),
			'reach'           => $reach,
			'index'           => tkdesk_rest_index(),
			'cdn'             => array(
				'show'   => $acted && ( tkdesk_setting( 'cdn_note' ) || '' !== $plugin ),
				'plugin' => $plugin,
				'urls'   => array_values( array_unique( array_filter( $urls ) ) ),
			),
			'restore_days'    => (int) tkdesk_setting( 'purge_removed_days' ),
			'moderators'      => tkdesk_rest_moderators(),
			'poster'          => tkdesk_poster_detail( $row ),
			'decline_reasons' => $reasons,
		),
		$row
	);
}

/**
 * People who can act on requests, for the assign menus: [ Person ] (see tkdesk_rest_person()).
 *
 * @return array
 */
function tkdesk_rest_moderators(): array {
	// Full user objects: get_users() primes the user and meta caches the initial reads.
	return array_map( 'tkdesk_rest_person', get_users( array( 'capability' => 'tkdesk_manage_requests' ) ) );
}

/**
 * A person as the admin app shows them: { id, name, initial }. No profile pictures: those load from
 * Gravatar by default, which would share moderator data with a third party.
 *
 * @param WP_User $user User.
 * @return array
 */
function tkdesk_rest_person( WP_User $user ): array {
	$first = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
	$name  = '' !== $first ? $first : trim( (string) $user->display_name );
	$char  = '' === $name ? '?' : mb_substr( $name, 0, 1, 'UTF-8' );
	return array(
		'id'      => (int) $user->ID,
		'name'    => (string) $user->display_name,
		'initial' => function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $char, 'UTF-8' ) : strtoupper( $char ),
	);
}

/**
 * Moderator-facing decline reason labels (the requester sentences live in tkdesk_decline_reasons()).
 *
 * @return array key => label.
 */
function tkdesk_decline_reason_labels(): array {
	return array(
		'not_found'             => __( 'Could not locate the item', 'takedown-desk' ),
		'not_on_site'           => __( 'Item is not hosted here', 'takedown-desk' ),
		'incomplete'            => __( 'Missing a required part after we asked', 'takedown-desk' ),
		'not_intimate'          => __( 'Item does not show what the request describes', 'takedown-desk' ),
		'duplicate'             => __( 'Already handled in another request', 'takedown-desk' ),
		'bad_faith'             => __( 'Not made in good faith', 'takedown-desk' ),
		'restored_after_review' => __( 'Restored after review', 'takedown-desk' ),
		// Copyright notices.
		'dmca_incomplete'       => __( 'A required part of the notice is missing', 'takedown-desk' ),
		'dmca_not_found'        => __( 'Material not on this site or not found', 'takedown-desk' ),
		'dmca_not_infringing'   => __( 'Not removed after review', 'takedown-desk' ),
	);
}

/**
 * Item JSON.
 *
 * @param array $item Row from the items table.
 * @return array
 */
function tkdesk_rest_item( array $item ): array {
	$purge = tkdesk_ts( $item['purge_after'] );
	return array(
		'id'          => (int) $item['id'],
		'role'        => (string) $item['role'],
		'object_type' => (string) $item['object_type'],
		'object_id'   => (int) $item['object_id'],
		'label'       => (string) $item['label'],
		'url'         => (string) $item['url'],
		'action'      => (string) $item['action'],
		'action_at'   => tkdesk_ts( $item['action_at'] ),
		'can_restore' => in_array( $item['action'], array( 'offline', 'image_removed' ), true ) && $purge > tkdesk_now() && tkdesk_can( 'tkdesk_manage_settings' ),
		'purge_after' => $purge,
	);
}

/**
 * Media index progress.
 *
 * @return array { done, total }
 */
function tkdesk_rest_index(): array {
	$index = function_exists( 'tkdesk_index_status' ) ? tkdesk_index_status() : array();
	return array(
		'done'  => (int) ( $index['done'] ?? 0 ),
		'total' => (int) ( $index['total'] ?? 0 ),
	);
}

/**
 * LogRow JSON with a plain-English sentence per event. Never requester data.
 *
 * @param array $row Row from the log table.
 * @return array
 */
function tkdesk_rest_log_row( array $row ): array {
	$detail = json_decode( (string) $row['detail'], true );
	$detail = is_array( $detail ) ? $detail : array();
	$event  = (string) $row['event'];
	$actor  = (string) $row['actor_label'];
	$key    = $event . ( empty( $detail['skipped'] ) ? '' : '_skipped' );
	$a      = (string) ( $detail['label'] ?? '' );
	$b      = '';
	if ( ! empty( $detail['dmca'] ) ) {
		// Reminders of a copyright notice carry dmca: its target is business days, not 48 hours (cron.php).
		$key = strtr(
			$key,
			array(
				'reminder_24' => 'dmca_half',
				'reminder_40' => 'dmca_near',
				'overdue'     => 'dmca_passed',
			)
		);
	}
	if ( '' === $a && ! empty( $detail['item_id'] ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$a = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM %i WHERE id = %d', tkdesk_table( 'items' ), $detail['item_id'] ) );
	}
	$emails = array(
		'requester_received'   => __( 'Confirmation to the requester', 'takedown-desk' ),
		'requester_minor'      => __( 'Confirmation to the requester (person under 18)', 'takedown-desk' ),
		'requester_needs_info' => __( 'Question to the requester', 'takedown-desk' ),
		'requester_removed'    => __( 'Outcome to the requester', 'takedown-desk' ),
		'requester_declined'   => __( 'Outcome to the requester', 'takedown-desk' ),
		'mod_new'              => __( 'New request alert to moderators', 'takedown-desk' ),
		'mod_24h'              => __( '24-hour reminder to moderators', 'takedown-desk' ),
		'mod_40h'              => __( '8-hours-left reminder to moderators', 'takedown-desk' ),
		'mod_overdue'          => __( 'Overdue alert to moderators', 'takedown-desk' ),
		'mod_reply'            => __( 'Reply alert to moderators', 'takedown-desk' ),
		'mod_auto_offline'     => __( 'Automatic removal alert to moderators', 'takedown-desk' ),
		'requester_update'     => __( 'Status update to the requester', 'takedown-desk' ),
		'poster_removed'       => __( 'Removal notice to the person who posted it', 'takedown-desk' ),
		'mod_review'           => __( 'Review request alert to moderators', 'takedown-desk' ),
		'dmca_received'        => __( 'Confirmation to the sender of the copyright notice', 'takedown-desk' ),
		'dmca_needs_info'      => __( 'Question to the sender of the copyright notice', 'takedown-desk' ),
		'dmca_removed'         => __( 'Outcome to the sender of the copyright notice', 'takedown-desk' ),
		'dmca_declined'        => __( 'Outcome to the sender of the copyright notice', 'takedown-desk' ),
		'dmca_poster_removed'  => __( 'Removal notice to the person who posted it (copyright notice)', 'takedown-desk' ),
		'mod_dmca_new'         => __( 'New copyright notice alert to moderators', 'takedown-desk' ),
		'mod_dmca_reminder'    => __( 'Copyright notice reminder to moderators', 'takedown-desk' ),
		'mod_dmca_overdue'     => __( 'Past-the-target alert to moderators', 'takedown-desk' ),
	);
	$causes = array(
		'tkdesk_purged'       => __( 'The contact details were deleted.', 'takedown-desk' ),
		'tkdesk_key_changed'  => __( 'The contact details cannot be read after a key change.', 'takedown-desk' ),
		'tkdesk_no_requester' => __( 'Filed by the site team, so there is no requester to email.', 'takedown-desk' ),
		'email_off'           => __( 'This email is turned off in Settings.', 'takedown-desk' ),
	);
	if ( 'received' === $event && 'manual' === ( $detail['source'] ?? '' ) ) {
		$key = 'received_' . ( $detail['channel'] ?? 'manual' );
		$a   = $actor;
	} elseif ( 'opened' === $event ) {
		$a = $actor;
	} elseif ( 'assigned' === $event ) {
		$key = empty( $detail['user_id'] ) ? 'unassigned' : $key;
		$a   = (string) ( $detail['name'] ?? '' );
	} elseif ( 'copies_found' === $event ) {
		$exact = (int) ( $detail['exact'] ?? 0 );
		$refs  = (int) ( $detail['references'] ?? 0 );
		/* translators: %d: number of exact copies. */
		$a = sprintf( _n( '%d exact copy', '%d exact copies', $exact, 'takedown-desk' ), $exact );
		/* translators: %d: number of other places that show the image. */
		$b = sprintf( _n( '%d other place that shows it', '%d other places that show it', $refs, 'takedown-desk' ), $refs );
	} elseif ( 'declined' === $event ) {
		$a = tkdesk_decline_reason_labels()[ $detail['reason'] ?? '' ] ?? '';
	} elseif ( in_array( $event, array( 'email_sent', 'email_failed', 'email_off' ), true ) ) {
		$a = $emails[ $detail['key'] ?? '' ] ?? (string) ( $detail['key'] ?? '' );
	} elseif ( 'requester_not_notified' === $event ) {
		$a = $causes[ $detail['cause'] ?? '' ] ?? __( 'The email could not be sent.', 'takedown-desk' );
	} elseif ( in_array( $event, array( 'poster_notified', 'poster_not_notified' ), true ) ) {
		// Poster notice (poster.php): the labels of the items it covered, and why it was not sent.
		global $wpdb;
		$ids = array_map( 'intval', (array) ( $detail['items'] ?? array() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$a = $ids ? implode( ', ', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT label FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( tkdesk_table( 'items' ) ), $ids ) ) ) ) : '';
		$b = tkdesk_poster_causes()[ $detail['cause'] ?? '' ] ?? '';
	} elseif ( 'review_kept' === $event ) {
		$a = (string) ( $detail['note'] ?? '' );
	} elseif ( 'status_update' === $event && empty( $detail['sent'] ) ) {
		$key = 'status_update_failed';
	} elseif ( 'note_added' === $event ) {
		$a = (string) ( $detail['note'] ?? '' );
	} elseif ( 'settings_changed' === $event ) {
		$a = implode( ', ', array_map( 'strval', (array) ( $detail['keys'] ?? array() ) ) );
	} elseif ( in_array( $event, array( 'verified_unreachable', 'still_reachable', 'reach_unchecked' ), true ) ) {
		$live    = array_map( 'strval', (array) ( $detail['live'] ?? array() ) );
		$gone    = (int) ( $detail['gone'] ?? 0 );
		$unknown = count( (array) ( $detail['unknown'] ?? array() ) );
		/* translators: %d: number of links. */
		$a = sprintf( _n( '%d link', '%d links', $gone, 'takedown-desk' ), $gone );
		/* translators: %d: number of links. */
		$b = sprintf( _n( '%d link', '%d links', $unknown, 'takedown-desk' ), $unknown );
		if ( 'still_reachable' === $event ) {
			$a   = implode( ', ', array_slice( $live, 0, 3 ) );
			$b   = (string) ( count( $live ) - 3 );
			$key = count( $live ) > 3 ? 'still_reachable_more' : $key;
		} elseif ( 'reach_unchecked' === $event ) {
			$a = $b;
		} elseif ( $unknown ) {
			$key = 'unreachable_some';
		}
	} elseif ( 'cybertip_reported' === $event ) {
		$b = mysql2date( get_option( 'date_format' ), (string) ( $detail['on'] ?? '' ) );
	} elseif ( 'auto_hide_skipped' === $event ) {
		$key = 'auto_skip_' . ( $detail['reason'] ?? '' );
	} elseif ( 'cloudflare_purged' === $event ) {
		$links = (int) ( $detail['links'] ?? 0 );
		/* translators: %d: number of links. */
		$a = sprintf( _n( '%d link', '%d links', $links, 'takedown-desk' ), $links );
	} elseif ( 'cloudflare_failed' === $event ) {
		$a = (string) ( $detail['error'] ?? '' );
	}
	$texts  = array(
		'received'               => __( 'Request received', 'takedown-desk' ),
		// Auto-hide on receipt and the watch list (signals.php).
		'auto_hidden'            => __( 'Hidden automatically when the request arrived', 'takedown-desk' ),
		'auto_hide_skipped'      => __( 'Not hidden automatically', 'takedown-desk' ),
		'auto_skip_admin'        => __( 'Not hidden automatically: an administrator wrote or uploaded the item', 'takedown-desk' ),
		'auto_skip_limit'        => __( 'Not hidden automatically: this requester reached the daily limit', 'takedown-desk' ),
		'auto_skip_rules'        => __( 'Not hidden automatically: the removal rules do not allow it for this item', 'takedown-desk' ),
		'watch_added'            => __( 'Requester added to the watch list', 'takedown-desk' ),
		'watch_removed'          => __( 'Requester removed from the watch list', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'received_manual'        => __( 'Request logged by %s', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'received_email'         => __( 'Request logged by %s; it arrived by email', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'received_phone'         => __( 'Request logged by %s; it arrived by phone', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'received_mail'          => __( 'Request logged by %s; it arrived by mail', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'received_other'         => __( 'Request logged by %s; it arrived another way', 'takedown-desk' ),
		/* translators: %s: name of the person who filed it. */
		'received_admin'         => __( 'Request filed by %s for the site', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'opened'                 => __( 'Opened by %s', 'takedown-desk' ),
		/* translators: %s: moderator name. */
		'assigned'               => __( 'Assigned to %s', 'takedown-desk' ),
		'unassigned'             => __( 'Assignment removed', 'takedown-desk' ),
		/* translators: %s: item label. */
		'matched_item'           => __( 'Item matched: %s', 'takedown-desk' ),
		/* translators: 1: e.g. "2 exact copies", 2: e.g. "1 other place that shows it". */
		'copies_found'           => __( 'Copy search: %1$s, %2$s', 'takedown-desk' ),
		'info_requested'         => __( 'Asked the requester for details', 'takedown-desk' ),
		'info_received'          => __( 'Requester sent details', 'takedown-desk' ),
		'info_skipped'           => __( 'Continued without waiting for details', 'takedown-desk' ),
		'removed'                => __( 'Request closed: removed', 'takedown-desk' ),
		/* translators: %s: item label. */
		'item_offline'           => __( 'Item taken offline: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'item_image_removed'     => __( 'Images removed from the item: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'item_deleted'           => __( 'Item deleted permanently: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'item_failed'            => __( 'Could not remove the item: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'auto_offline'           => __( 'Item taken offline automatically: %s', 'takedown-desk' ),
		/* translators: %s: reason. */
		'declined'               => __( 'Request closed: not removed (%s)', 'takedown-desk' ),
		'marked_spam'            => __( 'Marked as spam', 'takedown-desk' ),
		'reopened'               => __( 'Request reopened', 'takedown-desk' ),
		/* translators: %s: item label. */
		'restored'               => __( 'Item restored: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'item_hidden'            => __( 'Item hidden while reviewing: %s', 'takedown-desk' ),
		/* translators: %s: item label. */
		'unhidden'               => __( 'Hidden item put back: %s', 'takedown-desk' ),
		'info_resumed'           => __( 'Undo: waiting for details again', 'takedown-desk' ),
		/* translators: %s: number of links, e.g. "9 links". */
		'verified_unreachable'   => __( 'Verified unreachable: %s', 'takedown-desk' ),
		/* translators: 1: number of links that no longer answer, 2: number of links that could not be checked. */
		'unreachable_some'       => __( 'Verified unreachable: %1$s; could not check %2$s', 'takedown-desk' ),
		/* translators: %s: links, comma separated. */
		'still_reachable'        => __( 'Still reachable: %s', 'takedown-desk' ),
		/* translators: 1: links, comma separated, 2: number of further links. */
		'still_reachable_more'   => __( 'Still reachable: %1$s and %2$s more', 'takedown-desk' ),
		/* translators: %s: number of links, e.g. "3 links". */
		'reach_unchecked'        => __( 'Removal check incomplete: could not check %s', 'takedown-desk' ),
		/* translators: %s: the note. */
		'note_added'             => __( 'Note: %s', 'takedown-desk' ),
		'contact_viewed'         => __( 'Contact details viewed', 'takedown-desk' ),
		/* translators: %s: which email, e.g. outcome to the requester. */
		'email_sent'             => __( 'Email sent: %s', 'takedown-desk' ),
		/* translators: %s: which email, e.g. outcome to the requester. */
		'email_failed'           => __( 'Email not sent: %s', 'takedown-desk' ),
		/* translators: %s: which email, e.g. outcome to the requester. */
		'email_off'              => __( 'Email turned off, not sent: %s', 'takedown-desk' ),
		'requester_notified'     => __( 'Requester emailed the outcome', 'takedown-desk' ),
		/* translators: %s: cause, e.g. the contact details were deleted. */
		'requester_not_notified' => __( 'Requester not emailed: %s', 'takedown-desk' ),
		// Poster notice, review requests and the 6-day status update (poster.php).
		/* translators: %s: item labels, comma separated. */
		'poster_notified'        => __( 'Person who posted %s emailed the reason and a review link', 'takedown-desk' ),
		/* translators: 1: item labels, comma separated, 2: reason, e.g. "The person in the image was under 18." */
		'poster_not_notified'    => __( 'Person who posted %1$s not emailed: %2$s', 'takedown-desk' ),
		'review_requested'       => __( 'Review requested by the person who posted it', 'takedown-desk' ),
		/* translators: %s: the decision note. */
		'review_kept'            => __( 'Review decided: kept offline. Note: %s', 'takedown-desk' ),
		'status_update'          => __( 'Status update emailed to the requester: still open after 6 days', 'takedown-desk' ),
		'status_update_failed'   => __( 'Status update to the requester not sent', 'takedown-desk' ),
		'reminder_24'            => __( 'Reminder sent: 24 hours left', 'takedown-desk' ),
		'reminder_24_skipped'    => __( 'Reminder skipped: 24 hours left (a later reminder went out)', 'takedown-desk' ),
		'reminder_40'            => __( 'Reminder sent: 8 hours left', 'takedown-desk' ),
		'reminder_40_skipped'    => __( 'Reminder skipped: 8 hours left (a later reminder went out)', 'takedown-desk' ),
		'overdue'                => __( 'Overdue alert sent: the 48-hour deadline passed', 'takedown-desk' ),
		'dmca_half'              => __( 'Reminder sent: halfway to the target', 'takedown-desk' ),
		'dmca_half_skipped'      => __( 'Reminder skipped: halfway to the target (a later reminder went out)', 'takedown-desk' ),
		'dmca_near'              => __( 'Reminder sent: the target is near', 'takedown-desk' ),
		'dmca_near_skipped'      => __( 'Reminder skipped: the target is near (a later reminder went out)', 'takedown-desk' ),
		'dmca_passed'            => __( 'Overdue alert sent: the target passed', 'takedown-desk' ),
		/* translators: %s: item label. */
		'purged_file'            => __( 'Removed file deleted for good: %s', 'takedown-desk' ),
		'purged_request_data'    => __( 'Contact details and request text deleted under the retention setting', 'takedown-desk' ),
		'exported'               => __( 'Record exported as CSV', 'takedown-desk' ),
		/* translators: %s: setting keys. */
		'settings_changed'       => __( 'Settings changed: %s', 'takedown-desk' ),
		'key_changed'            => __( 'Encryption key changed', 'takedown-desk' ),
		// Minors path (minors.php) and safe previews.
		'minor_flagged'          => __( 'Marked as involving a person under 18', 'takedown-desk' ),
		'minor_cleared'          => __( 'Under-18 flag cleared', 'takedown-desk' ),
		/* translators: 1: CyberTipline report number, 2: date. */
		'cybertip_reported'      => __( 'CyberTipline report %1$s saved, filed on %2$s', 'takedown-desk' ),
		'delete_refused'         => __( 'Permanent deletion refused: the person in the image was under 18', 'takedown-desk' ),
		'restore_refused'        => __( 'Restore refused: the person in the image was under 18', 'takedown-desk' ),
		'preview_shown'          => __( 'Image preview shown', 'takedown-desk' ),
		// Re-upload block (hashes.php) and Cloudflare purge (cache.php).
		'upload_blocked'         => __( 'Blocked a re-upload of a removed file', 'takedown-desk' ),
		/* translators: %s: number of links, e.g. "3 links". */
		'cloudflare_purged'      => __( 'Cloudflare cache purged: %s', 'takedown-desk' ),
		/* translators: %s: error message. */
		'cloudflare_failed'      => __( 'Cloudflare purge failed: %s', 'takedown-desk' ),
	);
	$format = $texts[ $key ] ?? $texts[ $event ] ?? ucfirst( str_replace( '_', ' ', $event ) );
	return array(
		'id'    => (int) $row['id'],
		'time'  => tkdesk_ts( $row['created_at'] ),
		'actor' => $actor,
		'event' => $event,
		'text'  => sprintf( $format, $a, $b ),
	);
}

/**
 * GET /admin/requests: inbox list. `orderby` is due|id|status|assigned|received (anything else: received);
 * `order` is asc|desc, defaulting to desc for received and asc for the rest. No orderby: newest received first.
 * `latest` is the newest request ID (tests and spam left out); with `since` (an earlier `latest`), `newer` counts
 * the requests that arrived after it, so the inbox can offer them instead of reshuffling rows.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function tkdesk_rest_admin_list( WP_REST_Request $request ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$new     = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE( MAX(id), 0 ) AS latest, COALESCE( SUM( id > %d ), 0 ) AS newer FROM %i WHERE is_test = 0 AND status <> %s', absint( tkdesk_rest_str( $request, 'since' ) ), tkdesk_table( 'requests' ), 'spam' ), ARRAY_A );
	$status  = tkdesk_rest_str( $request, 'status' );
	$status  = '' !== $status ? $status : 'open';
	$orderby = tkdesk_rest_str( $request, 'orderby' );
	$orderby = in_array( $orderby, array( 'due', 'id', 'status', 'assigned', 'received' ), true ) ? $orderby : 'received';
	$order   = strtolower( tkdesk_rest_str( $request, 'order' ) );
	$list    = tkdesk_list_requests(
		array(
			'status'   => $status,
			'search'   => tkdesk_rest_str( $request, 'search' ),
			'page'     => max( 1, absint( tkdesk_rest_str( $request, 'page' ) ) ),
			'per_page' => min( 100, max( 1, absint( tkdesk_rest_str( $request, 'per_page' ) ) ) ),
			'orderby'  => $orderby,
			'order'    => in_array( $order, array( 'asc', 'desc' ), true ) ? $order : ( 'received' === $orderby ? 'desc' : 'asc' ),
			'type'     => tkdesk_rest_str( $request, 'type' ),
		)
	);
	return array(
		'items'      => array_map( 'tkdesk_rest_request', $list['items'] ),
		'total'      => $list['total'],
		'counts'     => tkdesk_request_counts(),
		'moderators' => tkdesk_rest_moderators(),
		'latest'     => (int) $new['latest'],
		'newer'      => (int) $new['newer'],
	);
}

/**
 * POST /admin/requests: manual entry. `filer` is required: `requester` logs a request that arrived by
 * email, phone, mail or another way; `admin` files one for the site itself (received now, no requester).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function tkdesk_rest_admin_create( WP_REST_Request $request ) {
	$filer = tkdesk_rest_str( $request, 'filer' );
	if ( ! in_array( $filer, array( 'admin', 'requester' ), true ) ) {
		$message = __( 'Choose who is filing this request.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_invalid_filer',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'filer' => $message ),
			)
		);
	}
	$admin = 'admin' === $filer;
	$now   = tkdesk_now();
	$raw   = $admin ? '' : tkdesk_rest_str( $request, 'received_at' );
	$date  = '' === $raw || is_numeric( $raw ) ? null : date_create( $raw, wp_timezone() );
	$when  = '' === $raw ? $now : ( $date ? $date->getTimestamp() : (int) $raw );
	if ( $when <= 0 || false === $date ) {
		$message = __( 'Enter when the request arrived.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_invalid_time',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'received_at' => $message ),
			)
		);
	}
	// One minute of grace for clock skew between browser and server.
	if ( $when > $now + MINUTE_IN_SECONDS ) {
		$message = __( 'The arrival time cannot be in the future.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_future_time',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'received_at' => $message ),
			)
		);
	}
	$clean = tkdesk_validate_request( $request->get_params(), true, $admin );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$channel = $admin ? 'admin' : tkdesk_rest_str( $request, 'channel' );
	$created = tkdesk_create_request(
		$clean,
		array(
			'source'      => 'manual',
			'received_at' => min( $when, $now ),
			'locale'      => get_locale(),
			'channel'     => in_array( $channel, array( 'email', 'phone', 'mail', 'other', 'admin' ), true ) ? $channel : 'other',
		)
	);
	return is_wp_error( $created ) ? $created : new WP_REST_Response( tkdesk_rest_request( $created['request'], true ), 201 );
}

/**
 * GET /admin/requests/{id}: detail without contact.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_detail( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	return is_wp_error( $row ) ? $row : tkdesk_rest_request( $row, true );
}

/**
 * GET /admin/requests/{id}/contact: decrypted contact; logs contact_viewed.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_contact( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$contact = tkdesk_request_contact( $row );
	if ( is_wp_error( $contact ) ) {
		$contact->add_data( array( 'status' => 'tkdesk_purged' === $contact->get_error_code() ? 410 : 409 ) );
		return $contact;
	}
	tkdesk_log( $row['id'], 'contact_viewed' );
	return array(
		'signature' => (string) $contact['signature'],
		'email'     => (string) $contact['email'],
		'phone'     => (string) $contact['phone'],
		'address'   => (string) $contact['address'],
	);
}

/**
 * POST /admin/requests/{id}/open: new to in_review; any other status is returned unchanged.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_open( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	if ( 'new' === $row['status'] ) {
		// A failure here means another moderator opened it first; the fresh row shows that.
		tkdesk_transition( $row['id'], 'in_review' );
		$row = tkdesk_get_request( $row['id'] );
	}
	return tkdesk_rest_request( $row, true );
}

/**
 * POST /admin/requests/{id}/assign { user_id }, 0 unassigns.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_assign( WP_REST_Request $request ) {
	global $wpdb;
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$user_id = absint( tkdesk_rest_str( $request, 'user_id' ) );
	if ( $user_id && ! user_can( $user_id, 'tkdesk_manage_requests' ) ) {
		return new WP_Error( 'tkdesk_bad_user', __( 'Choose someone who can act on requests.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( $user_id !== $row['assigned_to'] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$wpdb->update( tkdesk_table( 'requests' ), array( 'assigned_to' => $user_id ), array( 'id' => $row['id'] ) );
		$user = $user_id ? get_userdata( $user_id ) : false;
		tkdesk_log(
			$row['id'],
			'assigned',
			array(
				'user_id' => $user_id,
				'name'    => $user ? $user->display_name : '',
			)
		);
		$row = tkdesk_get_request( $row['id'] );
	}
	return tkdesk_rest_request( $row, true );
}

/**
 * POST /admin/requests/{id}/match { url } or { object_type, object_id }.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_match( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$url    = tkdesk_rest_str( $request, 'url' );
	$target = '' !== $url ? tkdesk_resolve_url( $url ) : tkdesk_parse_item_ref( tkdesk_rest_str( $request, 'object_type' ) . ':' . absint( tkdesk_rest_str( $request, 'object_id' ) ) );
	if ( ! $target ) {
		return new WP_Error( 'tkdesk_item_not_found', __( 'No item found at that link.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	$row = tkdesk_match_item( $row['id'], $target );
	return is_wp_error( $row ) ? $row : tkdesk_rest_request( $row, true );
}

/**
 * POST /admin/requests/{id}/copies: run the copy finder.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_copies( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$items = tkdesk_find_copies( $row['id'] );
	if ( is_wp_error( $items ) ) {
		return $items;
	}
	return array(
		'items' => array_map( 'tkdesk_rest_item', $items ),
		'index' => tkdesk_rest_index(),
	);
}

/**
 * POST /admin/requests/{id}/remove, /restore, /hide (hide while reviewing), /unhide (put hidden items
 * back) and /check (check again that removed links no longer answer). Removing or hiding runs that
 * check right away.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { request, results }
 */
function tkdesk_rest_admin_remove( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$items  = array_map( 'absint', (array) $request->get_param( 'items' ) );
	$note   = tkdesk_rest_str( $request, 'note' );
	$action = basename( $request->get_route() );
	$result = array(
		'request' => $row,
		'results' => array(),
	);
	if ( 'restore' === $action ) {
		$result = tkdesk_restore_items( $row['id'], $items, $note );
	} elseif ( 'hide' === $action ) {
		$result = tkdesk_hide_item( $row['id'] );
	} elseif ( 'unhide' === $action ) {
		$result['results'] = tkdesk_unhide_items( $row['id'] );
	} elseif ( 'remove' === $action ) {
		$result = tkdesk_remove_items(
			$row['id'],
			array(
				'items'  => $items,
				'scope'  => tkdesk_rest_str( $request, 'scope' ),
				'images' => array_map( 'absint', (array) $request->get_param( 'images' ) ),
				'mode'   => tkdesk_rest_str( $request, 'mode' ),
				'note'   => $note,
			)
		);
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( 'check' === $action || ( in_array( $action, array( 'remove', 'hide' ), true ) && in_array( true, wp_list_pluck( $result['results'], 'ok' ), true ) ) ) {
		tkdesk_reach_check( $row['id'] );
	}
	return array(
		'request' => tkdesk_rest_request( $result['request'], true ),
		'results' => $result['results'],
	);
}

/**
 * POST /admin/requests/{id}/ask | continue | decline | spam | reopen, and /resume (undo of continue).
 * Decline takes `restore_hidden` (default true).
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_transition( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$action = basename( $request->get_route() );
	if ( 'resume' === $action ) {
		$row = tkdesk_resume_waiting( $row['id'] );
		return is_wp_error( $row ) ? $row : tkdesk_rest_request( $row, true );
	}
	$to = array(
		'ask'      => 'needs_info',
		'continue' => 'in_review',
		'decline'  => 'declined',
		'spam'     => 'spam',
		'reopen'   => 'in_review',
	);
	// Both move to in_review, so each only from its own starting statuses.
	$from = array(
		'continue' => array( 'needs_info' ),
		'reopen'   => array( 'removed', 'declined', 'spam' ),
	);
	if ( isset( $from[ $action ] ) && ! in_array( $row['status'], $from[ $action ], true ) ) {
		return new WP_Error( 'tkdesk_bad_transition', __( 'This request changed in the meantime. Reload it to see where it stands.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	$args = array(
		'note'           => tkdesk_rest_str( $request, 'note' ),
		'reason'         => tkdesk_rest_str( $request, 'reason' ),
		'message'        => tkdesk_rest_str( $request, 'message' ),
		'restore_hidden' => rest_sanitize_boolean( $request->get_param( 'restore_hidden' ) ?? true ),
	);
	if ( 'continue' === $action ) {
		$args['event'] = 'info_skipped';
	}
	$row = tkdesk_transition( $row['id'], $to[ $action ], $args );
	return is_wp_error( $row ) ? $row : tkdesk_rest_request( $row, true );
}

/**
 * POST /admin/requests/{id}/note { note }: adds a note to the record.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error LogRow.
 */
function tkdesk_rest_admin_note( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$note = trim( sanitize_textarea_field( tkdesk_rest_str( $request, 'note' ) ) );
	if ( '' === $note || mb_strlen( $note ) > 2000 ) {
		$message = __( 'Write a note of up to 2,000 characters.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_missing_note',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'note' => $message ),
			)
		);
	}
	tkdesk_log( $row['id'], 'note_added', array( 'note' => $note ) );
	$rows = tkdesk_log_for_request( $row['id'] );
	return tkdesk_rest_log_row( end( $rows ) );
}

/**
 * GET /admin/log: from, to (unix), request (ID or public ID), event, view (`key`: key events only), page.
 *
 * @param WP_REST_Request $request Request.
 * @return array { rows, total, summary }
 */
function tkdesk_rest_admin_log( WP_REST_Request $request ): array {
	$from   = absint( tkdesk_rest_str( $request, 'from' ) );
	$to     = absint( tkdesk_rest_str( $request, 'to' ) );
	$from   = $from ? tkdesk_mysql( $from ) : '';
	$to     = $to ? tkdesk_mysql( $to ) : '';
	$ref    = tkdesk_rest_str( $request, 'request' );
	$found  = '' !== $ref && ! ctype_digit( $ref ) ? tkdesk_get_request_by_public_id( $ref ) : null;
	$filter = $found ? $found['id'] : ( ctype_digit( $ref ) ? (int) $ref : ( '' === $ref ? 0 : -1 ) );
	// Key events: what happened to the request and the requester, without searches, emails and notes.
	$key    = 'received,opened,assigned,info_requested,info_received,info_resumed,item_hidden,unhidden,item_offline,item_image_removed,item_deleted,item_failed,verified_unreachable,still_reachable,reach_unchecked,minor_flagged,minor_cleared,cybertip_reported,delete_refused,restore_refused,removed,declined,marked_spam,restored,reopened,requester_notified,requester_not_notified,overdue,poster_notified,poster_not_notified,review_requested,review_kept,status_update';
	$result = tkdesk_log_query(
		array(
			'from'       => $from,
			'to'         => $to,
			'request_id' => $filter,
			'event'      => sanitize_key( tkdesk_rest_str( $request, 'event' ) ),
			'events'     => 'key' === tkdesk_rest_str( $request, 'view' ) ? $key : '',
			'page'       => max( 1, absint( tkdesk_rest_str( $request, 'page' ) ) ),
		)
	);
	$public = array( 0 => '' );
	$rows   = array();
	foreach ( $result['rows'] as $log ) {
		$rid = (int) $log['request_id'];
		if ( ! isset( $public[ $rid ] ) ) {
			$req            = tkdesk_get_request( $rid );
			$public[ $rid ] = $req ? $req['public_id'] : '';
		}
		$rows[] = tkdesk_rest_log_row( $log ) + array(
			'request_id' => $rid,
			'public_id'  => $public[ $rid ],
		);
	}

	return array(
		'rows'    => $rows,
		'total'   => $result['total'],
		'summary' => tkdesk_summary_stats( $from, $to ),
	);
}

/**
 * Summary numbers for a range (the Record tiles and the public summary block): requests received in
 * the range, removed and declined (resolved in the range), how many of those closed within 48 hours,
 * and the median time from receipt to removal. Test and spam requests are left out, and so are copyright
 * notices: these numbers are about intimate image requests.
 *
 * @param string $from GMT mysql datetime, '' for no lower bound.
 * @param string $to   GMT mysql datetime, '' for no upper bound.
 * @return array { received, removed, declined, median_seconds, within_48h }
 */
function tkdesk_summary_stats( string $from = '', string $to = '' ): array {
	global $wpdb;
	$lo = '' !== $from ? $from : '1970-01-01 00:00:00';
	$hi = '' !== $to ? $to : '9999-12-31 23:59:59';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$reqs    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, received_at, due_at, resolved_at FROM %i WHERE type = %s AND is_test = 0 AND status <> %s AND ( received_at BETWEEN %s AND %s OR resolved_at BETWEEN %s AND %s )', tkdesk_table( 'requests' ), 'ncii', 'spam', $lo, $hi, $lo, $hi ), ARRAY_A );
	$summary = array(
		'received'       => 0,
		'removed'        => 0,
		'declined'       => 0,
		'median_seconds' => 0,
		'within_48h'     => 0,
	);
	$times   = array();
	foreach ( $reqs as $r ) {
		$summary['received'] += $r['received_at'] >= $lo && $r['received_at'] <= $hi ? 1 : 0;
		if ( ! in_array( $r['status'], array( 'removed', 'declined' ), true ) || $r['resolved_at'] < $lo || $r['resolved_at'] > $hi ) {
			continue;
		}
		++$summary[ $r['status'] ];
		$summary['within_48h'] += $r['resolved_at'] <= $r['due_at'] ? 1 : 0;
		if ( 'removed' === $r['status'] ) {
			$times[] = tkdesk_ts( $r['resolved_at'] ) - tkdesk_ts( $r['received_at'] );
		}
	}
	if ( $times ) {
		sort( $times );
		$summary['median_seconds'] = $times[ intdiv( count( $times ) - 1, 2 ) ];
	}
	return $summary;
}

/**
 * GET and POST /admin/settings. POST body { settings }: an invalid value is rejected for its field,
 * never dropped or clamped silently.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { settings, post_types, object_types, moderators, key, pages }
 */
function tkdesk_rest_admin_settings( WP_REST_Request $request ) {
	if ( 'POST' === $request->get_method() ) {
		$in     = (array) $request->get_param( 'settings' );
		$schema = tkdesk_settings_schema();
		$errors = array();
		$lists  = array(
			'email' => __( 'Enter valid email addresses, one per line.', 'takedown-desk' ),
			'host'  => __( 'Enter site addresses such as cdn.example.com, one per line.', 'takedown-desk' ),
			'key'   => __( 'Choose from the listed options.', 'takedown-desk' ),
		);
		foreach ( array_intersect_key( $in, $schema ) as $key => $value ) {
			$def   = $schema[ $key ];
			$raw   = is_scalar( $value ) ? trim( (string) $value ) : '';
			$error = '';
			if ( 'emails' === $def['type'] ) {
				// Field keys emails.{key}.subject / emails.{key}.body, checked against the saved text they update.
				foreach ( is_array( $value ) ? $value : array() as $mail => $email ) {
					foreach ( tkdesk_email_errors( (string) $mail, array_merge( tkdesk_email_saved( (string) $mail ), is_array( $email ) ? $email : array() ) ) as $field => $message ) {
						$errors[ "emails.$mail.$field" ] = $message;
					}
				}
			} elseif ( 'array' === $def['type'] ) {
				$list = array_filter( array_map( 'trim', array_map( 'strval', array_filter( is_array( $value ) ? $value : (array) preg_split( '/[\s,]+/', $raw ), 'is_scalar' ) ) ), 'strlen' );
				foreach ( $list as $item ) {
					if ( ! tkdesk_sanitize_settings( array( $key => array( $item ) ) )[ $key ] ) {
						$error = $lists[ $def['items'] ] ?? $lists['key'];
					}
				}
				if ( '' === $error && isset( $def['max_items'] ) && count( array_unique( $list ) ) > $def['max_items'] ) {
					/* translators: %d: maximum number of entries. */
					$error = sprintf( __( 'Add up to %d entries.', 'takedown-desk' ), $def['max_items'] );
				}
			} elseif ( 'email' === $def['type'] && '' !== $raw && ! is_email( $raw ) ) {
				$error = __( 'Enter a valid email address.', 'takedown-desk' );
			} elseif ( 'date' === $def['type'] && tkdesk_sanitize_settings( array( $key => $raw ) )[ $key ] !== $raw ) {
				$error = __( 'Enter a date such as 2026-01-31, or leave this empty.', 'takedown-desk' );
			} elseif ( isset( $def['max'] ) && mb_strlen( $raw ) > $def['max'] ) {
				/* translators: %d: maximum number of characters. */
				$error = sprintf( __( 'Use %d characters or fewer.', 'takedown-desk' ), $def['max'] );
			} elseif ( isset( $def['enum'] ) && ! in_array( 'int' === $def['type'] && is_numeric( $raw ) ? (int) $raw : $raw, $def['enum'], true ) ) {
				$error = __( 'Choose one of the listed options.', 'takedown-desk' );
			} elseif ( in_array( $key, array( 'notice_page_id', 'dmca_page_id' ), true ) && $raw && 'page' !== get_post_type( (int) $raw ) ) {
				$error = __( 'Choose a page.', 'takedown-desk' );
			}
			if ( '' !== $error ) {
				$errors[ $key ] = $error;
			}
		}
		// The Cloudflare token is not a setting: cache.php keeps it encrypted and never sends it back.
		// Null or missing leaves it as it is, '' removes it.
		$token = isset( $in['cf_token'] ) && is_scalar( $in['cf_token'] ) ? trim( (string) $in['cf_token'] ) : null;
		unset( $in['cf_token'] );
		if ( null !== $token && '' !== $token && ! preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $token ) ) {
			$errors['cf_token'] = __( 'Paste the API token exactly as Cloudflare shows it.', 'takedown-desk' );
		}
		// The reCAPTCHA secret key works the same way (spam.php). Turning reCAPTCHA on needs both keys.
		$secret = isset( $in['recaptcha_secret'] ) && is_scalar( $in['recaptcha_secret'] ) ? trim( (string) $in['recaptcha_secret'] ) : null;
		unset( $in['recaptcha_secret'] );
		$site = array_key_exists( 'recaptcha_site_key', $in ) && is_scalar( $in['recaptcha_site_key'] ) ? trim( (string) $in['recaptcha_site_key'] ) : (string) tkdesk_setting( 'recaptcha_site_key' );
		foreach ( array_filter(
			array(
				'recaptcha_site_key' => $site,
				'recaptcha_secret'   => (string) $secret,
			)
		) as $key => $value ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $value ) ) {
				$errors[ $key ] = __( 'Paste the key exactly as the reCAPTCHA admin console shows it.', 'takedown-desk' );
			}
		}
		if ( rest_sanitize_boolean( $in['recaptcha'] ?? tkdesk_setting( 'recaptcha' ) ) && ! rest_sanitize_boolean( $in['bot_check'] ?? tkdesk_setting( 'bot_check' ) ) ) {
			if ( '' === $site ) {
				$errors['recaptcha_site_key'] = __( 'Add the site key to use reCAPTCHA.', 'takedown-desk' );
			}
			if ( '' === ( null === $secret ? tkdesk_recaptcha_secret() : $secret ) ) {
				$errors['recaptcha_secret'] = __( 'Add the secret key to use reCAPTCHA.', 'takedown-desk' );
			}
		}
		$zone = isset( $in['cf_zone_id'] ) && is_scalar( $in['cf_zone_id'] ) ? trim( (string) $in['cf_zone_id'] ) : '';
		if ( '' !== $zone && ! preg_match( '/^[a-f0-9]{32}$/i', $zone ) ) {
			$errors['cf_zone_id'] = __( 'Enter the 32-character zone ID from the Overview page of your Cloudflare dashboard.', 'takedown-desk' );
		}
		if ( $errors ) {
			return new WP_Error(
				'tkdesk_invalid_settings',
				__( 'Some fields need a fix.', 'takedown-desk' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}
		tkdesk_update_settings( $in );
		if ( null !== $token ) {
			tkdesk_cf_save_token( $token );
		}
		if ( null !== $secret ) {
			tkdesk_recaptcha_save_secret( $secret );
		}
	}

	// bbPress topics and replies are where members post; other plugins add their types here.
	$ugc   = (array) apply_filters( 'tkdesk_ugc_post_types', array( 'topic', 'reply' ) );
	$names = array(
		'topic' => __( 'Forum topics', 'takedown-desk' ),
		'reply' => __( 'Forum replies', 'takedown-desk' ),
	);
	$types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		if ( 'attachment' !== $type->name ) {
			$types[] = array(
				'name'  => $type->name,
				'label' => $names[ $type->name ] ?? $type->labels->name,
				'ugc'   => in_array( $type->name, $ugc, true ),
			);
		}
	}
	$objects = array();
	foreach ( tkdesk_object_types() as $key => $def ) {
		$objects[] = array(
			'key'   => $key,
			'label' => $def['label'],
		);
	}
	$roles = wp_roles()->role_names;
	$mods  = array();
	foreach ( get_users( array( 'capability' => 'tkdesk_manage_requests' ) ) as $user ) {
		$role   = (string) current( $user->roles );
		$mods[] = tkdesk_rest_person( $user ) + array(
			'role'  => isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ] ) : '',
			'email' => $user->user_email,
		);
	}
	$pages = array();
	// ponytail: first 100 pages by title; switch the select to a search when sites need more.
	foreach ( get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => 100,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	) as $page ) {
		$pages[] = array(
			'id'    => $page->ID,
			'title' => $page->post_title,
		);
	}
	// Template editor: each field's description and sample value (the same values Preview uses).
	$sample = tkdesk_email_sample();
	$values = tkdesk_email_values( $sample['request'], $sample['vars'] );
	$tags   = array();
	foreach ( tkdesk_email_tag_labels() as $tag => $label ) {
		$tags[ $tag ] = array( $label, $values[ $tag ] ?? '' );
	}
	$source = tkdesk_key_source();
	return array(
		'settings'     => tkdesk_settings(),
		'post_types'   => $types,
		'object_types' => $objects,
		'moderators'   => $mods,
		'key'          => array(
			'source' => $source,
			'weak'   => 'option' === $source,
			'line'   => tkdesk_key_line(),
		),
		'pages'        => $pages,
		'email_editor' => array(
			'templates' => tkdesk_email_templates(),
			'tags'      => $tags,
		),
		'admin_email'  => (string) get_option( 'admin_email' ), // Setup suggests it as the fallback contact email.
		'policy_text'  => tkdesk_policy_text(), // Settings card "Policy text".
		'cloudflare'   => array( 'token_saved' => (bool) get_option( 'tkdesk_cf_token' ) ), // Never the token itself.
		'recaptcha'    => array( 'secret_saved' => (bool) get_option( 'tkdesk_recaptcha_secret' ) ), // Never the key itself.
		'hide_limit'   => tkdesk_auto_hide_limit(), // Shown with the "Hide reported items" switch.
		'dmca'         => tkdesk_dmca_setup(), // Settings card "Copyright notices (DMCA)": the page and the agent renewal date.
	);
}

/**
 * POST /admin/dmca-page: create the copyright notice page, or return the one already made (frontend.php).
 *
 * @return array|WP_Error { page_id, url, edit_url }
 */
function tkdesk_rest_admin_dmca_page() {
	$id = function_exists( 'tkdesk_create_copyright_page' ) ? tkdesk_create_copyright_page() : new WP_Error( 'tkdesk_bad_action', __( 'This action is not available.', 'takedown-desk' ), array( 'status' => 400 ) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return array(
		'page_id'  => (int) $id,
		'url'      => (string) get_permalink( (int) $id ),
		'edit_url' => (string) get_edit_post_link( (int) $id, 'raw' ),
	);
}

/**
 * POST /admin/setup/notice-page { page_id?, action? }: create the notice page (default) or adopt an
 * existing page, then optionally publish it or add the form. The status fix_action names
 * create_notice_page and publish_notice_page work too.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { page_id, url }
 */
function tkdesk_rest_admin_notice_page( WP_REST_Request $request ) {
	$action  = str_replace( '_notice_page', '', tkdesk_rest_str( $request, 'action' ) );
	$page_id = absint( tkdesk_rest_str( $request, 'page_id' ) );
	if ( ! in_array( $action, array( '', 'create', 'publish', 'add_form' ), true ) ) {
		return new WP_Error( 'tkdesk_bad_action', __( 'This action is not available.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( $page_id && ( 'page' !== get_post_type( $page_id ) || 'trash' === get_post_status( $page_id ) ) ) {
		$message = __( 'Choose a page that is not in the trash.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_bad_page',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'page_id' => $message ),
			)
		);
	}
	if ( $page_id ) {
		tkdesk_update_settings( array( 'notice_page_id' => $page_id ) );
	} elseif ( in_array( $action, array( '', 'create' ), true ) ) {
		$created = tkdesk_create_notice_page();
		if ( is_wp_error( $created ) ) {
			return $created;
		}
	}
	$id   = (int) tkdesk_setting( 'notice_page_id' );
	$page = $id ? get_post( $id ) : null;
	if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
		return new WP_Error( 'tkdesk_no_notice_page', __( 'There is no notice page yet. Create it first.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	$change = array( 'ID' => $page->ID );
	if ( 'publish' === $action ) {
		$change['post_status'] = 'publish';
	}
	if ( 'add_form' === $action && ! has_block( 'takedown-desk/request-form', $page ) && ! has_shortcode( $page->post_content, 'takedown_desk_form' ) ) {
		$change['post_content'] = $page->post_content . "\n\n<!-- wp:takedown-desk/request-form /-->";
	}
	if ( count( $change ) > 1 ) {
		$updated = wp_update_post( wp_slash( $change ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}
	return array(
		'page_id' => $page->ID,
		'url'     => (string) get_permalink( $page->ID ),
	);
}

/**
 * POST /admin/setup-done { answers?, links_off? }. links_off turns the footer and content links off.
 *
 * @param WP_REST_Request $request Request.
 * @return array { ok }
 */
function tkdesk_rest_admin_setup_done( WP_REST_Request $request ): array {
	$answers = $request->get_param( 'answers' );
	if ( is_array( $answers ) ) {
		update_option( 'tkdesk_setup_answers', map_deep( $answers, 'sanitize_text_field' ), false );
	}
	if ( rest_sanitize_boolean( $request->get_param( 'links_off' ) ) ) {
		tkdesk_update_settings(
			array(
				'footer_link'      => 'manual',
				'link_post_types'  => array(),
				'link_comments'    => false,
				'link_attachments' => false,
			)
		);
	}
	update_option( 'tkdesk_setup_done', 1 );
	return array( 'ok' => true );
}

/**
 * POST /admin/test-request: a private test post by the current user and a request flagged is_test.
 *
 * @return array|WP_Error { request_id }
 */
function tkdesk_rest_admin_test_request() {
	$user    = wp_get_current_user();
	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'post',
				'post_status'  => 'private',
				'post_author'  => $user->ID,
				'post_title'   => __( 'Takedown Desk test item', 'takedown-desk' ),
				'post_content' => __( 'Takedown Desk created this private post so you can try a removal request. Only you and other editors can see it.', 'takedown-desk' ),
				'meta_input'   => array( '_tkdesk_test' => 1 ),
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$created = tkdesk_create_request(
		array(
			'url'          => (string) get_permalink( $post_id ),
			'details'      => __( 'This is a test request sent from the setup screen.', 'takedown-desk' ),
			'statement'    => __( 'This is a test. Nobody asked for this removal.', 'takedown-desk' ),
			'email'        => $user->user_email,
			'phone'        => '',
			'relationship' => 'self',
			'signature'    => $user->display_name,
			'good_faith'   => true,
			'item'         => 'post:' . $post_id,
		),
		array(
			'source'  => 'manual',
			'is_test' => true,
			'locale'  => get_user_locale(),
		)
	);
	if ( is_wp_error( $created ) ) {
		wp_delete_post( $post_id, true );
		return $created;
	}
	return array( 'request_id' => (int) $created['request']['id'] );
}

/**
 * DELETE /admin/test-requests: test requests, their item rows and their test posts (whatever their
 * status). Log rows stay.
 *
 * @return array { deleted }
 */
function tkdesk_rest_admin_test_delete(): array {
	global $wpdb;
	$items    = tkdesk_table( 'items' );
	$requests = tkdesk_table( 'requests' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_items, tkdesk_requests.
	$posts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT i.object_id FROM %i i JOIN %i r ON r.id = i.request_id WHERE r.is_test = 1 AND i.object_type = %s', $items, $requests, 'post' ) );
	foreach ( $posts as $post_id ) {
		// Only posts made by the test route: a test request may have been matched to real content.
		if ( get_post_meta( (int) $post_id, '_tkdesk_test', true ) ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_items, tkdesk_requests.
	$wpdb->query( $wpdb->prepare( 'DELETE i FROM %i i JOIN %i r ON r.id = i.request_id WHERE r.is_test = 1', $items, $requests ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	return array( 'deleted' => (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE is_test = 1', $requests ) ) );
}

/**
 * POST /admin/test-email { key } sends one template with sample data to the current user only, even
 * when that email is turned off; GET /admin/email-preview?key= returns it as { subject, body }. Both
 * use the saved subject and text.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error
 */
function tkdesk_rest_admin_test_email( WP_REST_Request $request ) {
	$key = tkdesk_rest_str( $request, 'key' );
	if ( ! isset( tkdesk_email_templates()[ $key ] ) ) {
		return new WP_Error( 'tkdesk_bad_email', __( 'Choose one of the listed emails.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	$sample = tkdesk_email_sample();
	if ( 'dmca' === ( tkdesk_email_templates()[ $key ]['type'] ?? '' ) ) {
		// A copyright notice email shows a sample notice: its own reason sentences and links.
		$sample['request']['type']           = 'dmca';
		$sample['request']['decline_reason'] = 'dmca_not_found';
	}
	if ( 'GET' === $request->get_method() ) {
		$email          = tkdesk_email_template( $key, $sample['request'], $sample['vars'] );
		$footer         = (string) tkdesk_setting( 'email_footer' );
		$email['body'] .= '' !== $footer ? "\n\n" . $footer : '';
		return $email;
	}
	// is_test sends it to the current user only; contact_enc lets requester templates through.
	$user                             = wp_get_current_user();
	$sample['request']['contact_enc'] = tkdesk_encrypt(
		(string) wp_json_encode(
			array(
				'signature' => $user->display_name,
				'email'     => $user->user_email,
				'phone'     => '',
			)
		)
	);
	return array( 'ok' => tkdesk_send_email( $key, $sample['request'], $sample['vars'] ) );
}
