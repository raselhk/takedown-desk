<?php
/**
 * The person who posted removed content, and the extras for other laws.
 *
 * - Poster notice (setting poster_notice, always on while eu_visitors is on): after a removal, each person who
 *   posted a removed item gets one email with the reason and a private "ask for a review" link. It never carries
 *   requester data or requester text, and it is never sent for a request about a person under 18 (`minor`) or one
 *   filed by the site itself (relationship `site`). The address is read from the item just before it goes and is
 *   never stored; the record keeps the item IDs and a hash of the review token.
 * - Review request: the link opens a short form on the notice page (statement, signature, good faith) behind the
 *   form's bot check and rate limit, and works once per notice. The request stays closed as removed with its times
 *   untouched (no clock restarts or stops), and shows "Review requested" until a moderator keeps the content
 *   offline (with a note) or an administrator restores it. The statement and signature are stored encrypted with
 *   the request text, so the retention purge deletes them too.
 * - EU visitors (setting eu_visitors): outcome emails add how to contest the decision ({redress}, emails.php).
 * - Status update: once, when a request is still open 6 days after it arrived, the requester gets an update with a
 *   fresh status link (Cal. Bus. & Prof. Code 22671(b)(2) asks for one within 7 days).
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'tkdesk_before_item_removed', 'tkdesk_poster_capture' );
add_action( 'tkdesk_item_removed', 'tkdesk_poster_done' );
add_action( 'tkdesk_items_removed', 'tkdesk_poster_notify' );
add_action( 'admin_post_tkdesk_review', 'tkdesk_post_review' );
add_action( 'admin_post_nopriv_tkdesk_review', 'tkdesk_post_review' );
add_action( 'rest_api_init', 'tkdesk_review_routes' );

/**
 * Whether removals notify the person who posted the item.
 *
 * @return bool
 */
function tkdesk_poster_notice_on(): bool {
	return (bool) tkdesk_setting( 'poster_notice' ) || (bool) tkdesk_setting( 'eu_visitors' );
}

/**
 * Why a person who posted removed content was not emailed, as shown in the record and the detail view.
 *
 * @return array cause => sentence.
 */
function tkdesk_poster_causes(): array {
	return array(
		'minor'        => __( 'The person in the image was under 18.', 'takedown-desk' ),
		'site'         => __( 'Your team filed this request.', 'takedown-desk' ),
		'no_email'     => __( 'There is no email address for them.', 'takedown-desk' ),
		'requester'    => __( 'They sent the request themselves.', 'takedown-desk' ),
		'email_off'    => __( 'This email is turned off in Settings.', 'takedown-desk' ),
		'email_failed' => __( 'The email could not be sent.', 'takedown-desk' ),
	);
}

/**
 * Email address of the person who posted an item: the author's account, or a guest commenter's address.
 *
 * @param array $item Item row.
 * @return string '' when there is none.
 */
function tkdesk_item_poster_email( array $item ): string {
	$desc  = tkdesk_describe( (string) $item['object_type'], (int) $item['object_id'] );
	$user  = $desc && $desc['author_id'] ? get_userdata( (int) $desc['author_id'] ) : false;
	$email = $user ? $user->user_email : '';
	if ( ! $user && 'comment' === $item['object_type'] ) {
		$email = (string) get_comment_author_email( (int) $item['object_id'] );
	}
	return is_email( $email ) ? strtolower( $email ) : '';
}

/**
 * Who posted each item of the removal running in this PHP request: item ID => array( 'email', 'done' ).
 *
 * @param array|null $set Replace the list.
 * @return array
 */
function tkdesk_poster_batch( ?array $set = null ): array {
	static $batch = array();
	if ( null !== $set ) {
		$batch = $set;
	}
	return $batch;
}

/**
 * Before an item goes: note who posted it, because a deleted item can no longer tell.
 *
 * @param array $item Item row.
 */
function tkdesk_poster_capture( $item ): void {
	if ( ! tkdesk_poster_notice_on() ) {
		return;
	}
	$batch                      = tkdesk_poster_batch();
	$batch[ (int) $item['id'] ] = array(
		'email' => tkdesk_item_poster_email( (array) $item ),
		'done'  => false,
	);
	tkdesk_poster_batch( $batch );
}

/**
 * The item went offline or was deleted.
 *
 * @param array $item Item row.
 */
function tkdesk_poster_done( $item ): void {
	$batch = tkdesk_poster_batch();
	if ( isset( $batch[ (int) $item['id'] ] ) ) {
		$batch[ (int) $item['id'] ]['done'] = true;
		tkdesk_poster_batch( $batch );
	}
}

/**
 * After a removal: one email per person who posted removed items, with the reason and a review link.
 * Logs poster_notified { items, token (sha256 of the link token) } or poster_not_notified { items, cause }.
 *
 * @param int $request_id Request ID.
 */
function tkdesk_poster_notify( $request_id ): void {
	global $wpdb;
	$batch = tkdesk_poster_batch();
	tkdesk_poster_batch( array() );
	$request = tkdesk_get_request( (int) $request_id );
	$groups  = array();
	foreach ( $batch as $item_id => $poster ) {
		if ( $poster['done'] ) {
			$groups[ $poster['email'] ][] = (int) $item_id;
		}
	}
	if ( ! $request || ! $groups ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$labels = $wpdb->get_results( $wpdb->prepare( 'SELECT id, label FROM %i WHERE request_id = %d', tkdesk_table( 'items' ), $request['id'] ), OBJECT_K );
	foreach ( $groups as $email => $ids ) {
		$email = (string) $email;
		$cause = '';
		if ( ! empty( $request['minor'] ) ) {
			$cause = 'minor';
		} elseif ( 'site' === $request['relationship'] ) {
			$cause = 'site';
		} elseif ( '' === $email ) {
			$cause = 'no_email';
		} elseif ( ! empty( $request['email_hash'] ) && hash_equals( (string) $request['email_hash'], tkdesk_hash( $email ) ) ) {
			$cause = 'requester';
		}
		$detail = array( 'items' => $ids );
		if ( '' === $cause ) {
			$lines = array();
			foreach ( $ids as $id ) {
				$lines[] = '- ' . ( isset( $labels[ $id ] ) ? $labels[ $id ]->label : '' );
			}
			$token = bin2hex( random_bytes( 32 ) );
			$vars  = array(
				'poster_email' => $email,
				'poster_items' => implode( "\n", $lines ),
				'review_link'  => tkdesk_notice_url(
					array(
						'tkdesk_review' => $request['public_id'],
						'token'         => $token,
					)
				),
			);
			if ( tkdesk_send_email( 'poster_removed', $request, $vars ) ) {
				tkdesk_log( (int) $request['id'], 'poster_notified', $detail + array( 'token' => hash( 'sha256', $token ) ) );
				continue;
			}
			$cause = tkdesk_email_saved( 'poster_removed' )['active'] ? 'email_failed' : 'email_off';
		}
		tkdesk_log( (int) $request['id'], 'poster_not_notified', $detail + array( 'cause' => $cause ) );
	}
}

/**
 * The notice a review link belongs to.
 *
 * @param string $public_id Request ID from the link.
 * @param string $token     Raw token from the link.
 * @return array|WP_Error array( request, notice (log row ID), items (item rows), used (bool), offline (bool) ),
 *                        or tkdesk_bad_review_link (403).
 */
function tkdesk_review_notice( string $public_id, string $token ) {
	global $wpdb;
	$bad     = new WP_Error( 'tkdesk_bad_review_link', __( 'This link no longer works. Open the most recent email we sent you about the removal.', 'takedown-desk' ), array( 'status' => 403 ) );
	$request = '' !== $public_id && '' !== $token ? tkdesk_get_request_by_public_id( $public_id ) : null;
	if ( ! $request ) {
		return $bad;
	}
	$hash   = hash( 'sha256', $token );
	$notice = null;
	$used   = false;
	foreach ( tkdesk_log_for_request( (int) $request['id'] ) as $row ) {
		$data = json_decode( (string) $row['detail'], true );
		$data = is_array( $data ) ? $data : array();
		if ( 'poster_notified' === $row['event'] && hash_equals( (string) ( $data['token'] ?? '' ), $hash ) ) {
			$notice = array( (int) $row['id'], array_map( 'intval', (array) ( $data['items'] ?? array() ) ) );
		} elseif ( $notice && 'review_requested' === $row['event'] && (int) ( $data['notice'] ?? 0 ) === $notice[0] ) {
			$used = true;
		}
	}
	if ( ! $notice ) {
		return $bad;
	}
	$items = array();
	if ( $notice[1] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$items = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d AND id IN (' . implode( ',', array_fill( 0, count( $notice[1] ), '%d' ) ) . ')', array_merge( array( tkdesk_table( 'items' ), $request['id'] ), $notice[1] ) ), ARRAY_A );
	}
	return array(
		'request' => $request,
		'notice'  => $notice[0],
		'items'   => $items,
		'used'    => $used,
		'offline' => (bool) array_intersect( wp_list_pluck( $items, 'action' ), array( 'offline', 'image_removed', 'deleted' ) ),
	);
}

/**
 * Review request from the person who posted removed content. The request keeps its status and times;
 * the record gets review_requested and the moderators an email.
 *
 * @param array  $in Raw input: tkdesk_review (request ID), token, statement, signature, good_faith, plus the
 *                   form's stamp, honeypot and bot check fields.
 * @param string $ip Client IP.
 * @return array|WP_Error array( 'ok' => true ), or tkdesk_bad_review_link (403), tkdesk_review_used (409),
 *                        tkdesk_review_back (409), tkdesk_invalid_review (400, fields), a spam check error.
 */
function tkdesk_submit_review( array $in, string $ip ) {
	global $wpdb;
	$str   = static function ( string $key ) use ( $in ): string {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
	};
	$found = tkdesk_review_notice( $str( 'tkdesk_review' ), $str( 'token' ) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( $found['used'] ) {
		return new WP_Error( 'tkdesk_review_used', __( 'You already asked for a review of this removal.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	if ( ! $found['offline'] ) {
		return new WP_Error( 'tkdesk_review_back', __( 'This content is back on the site, so there is nothing to review.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	$statement = trim( sanitize_textarea_field( str_replace( "\r\n", "\n", $str( 'statement' ) ) ) );
	$signature = trim( sanitize_text_field( $str( 'signature' ) ) );
	$fields    = array();
	if ( mb_strlen( $statement ) < 10 || mb_strlen( $statement ) > 2000 ) {
		$fields['statement'] = __( 'Tell us why in 10 to 2,000 characters.', 'takedown-desk' );
	}
	if ( mb_strlen( $signature ) < 2 || mb_strlen( $signature ) > 120 ) {
		$fields['signature'] = __( 'Type your full name as your signature.', 'takedown-desk' );
	}
	if ( ! rest_sanitize_boolean( $str( 'good_faith' ) ) ) {
		$fields['good_faith'] = __( 'Check the box to confirm this statement.', 'takedown-desk' );
	}
	if ( $fields ) {
		return new WP_Error(
			'tkdesk_invalid_review',
			reset( $fields ),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}
	// Only someone with the emailed link gets here, so a fast or listed-word review is still kept for a person to read.
	$verdict = tkdesk_spam_check( $in, $ip );
	if ( is_wp_error( $verdict ) ) {
		return $verdict;
	}
	if ( 'silent' === $verdict ) {
		return array( 'ok' => true );
	}
	$request = $found['request'];
	$text    = tkdesk_request_text( $request );
	if ( is_wp_error( $text ) ) {
		return new WP_Error( 'tkdesk_bad_review_link', __( 'This link no longer works. Open the most recent email we sent you about the removal.', 'takedown-desk' ), array( 'status' => 403 ) );
	}
	$text['reviews']   = (array) ( $text['reviews'] ?? array() );
	$text['reviews'][] = array(
		'at'        => tkdesk_mysql( tkdesk_now() ),
		'notice'    => $found['notice'],
		'statement' => $statement,
		'signature' => $signature,
	);
	// ponytail: read-modify-write like requester replies; two sends in the same instant could drop one, add a lock if seen.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( false === $wpdb->update( tkdesk_table( 'requests' ), array( 'text_enc' => tkdesk_encrypt( (string) wp_json_encode( $text ) ) ), array( 'id' => $request['id'] ) ) ) {
		return new WP_Error( 'tkdesk_server', tkdesk_form_messages()['server'], array( 'status' => 500 ) );
	}
	tkdesk_rate_count( $ip );
	tkdesk_log( (int) $request['id'], 'review_requested', array( 'notice' => $found['notice'] ), -2 );
	tkdesk_send_email( 'mod_review', $request );
	return array( 'ok' => true );
}

/**
 * Whether a review request waits for a moderator: the latest review_requested comes after any decision.
 *
 * @param int $request_id Request ID.
 * @return bool
 */
function tkdesk_review_pending( int $request_id ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
	$last = $wpdb->get_var( $wpdb->prepare( 'SELECT event FROM %i WHERE request_id = %d AND event IN (%s, %s, %s) ORDER BY id DESC LIMIT 1', tkdesk_table( 'log' ), $request_id, 'review_requested', 'review_kept', 'restored' ) );
	return 'review_requested' === $last;
}

/**
 * Poster notices and review requests of one request, for the detail JSON (the review text is for moderators only).
 *
 * @param array $row Request row.
 * @return array { on, notices: [ { at, sent, items: string[], cause } ], reviews: [ { at, statement, signature,
 *               outcome (pending|kept|restored), decided_at, decided_by, note } ], pending }
 */
function tkdesk_poster_detail( array $row ): array {
	global $wpdb;
	$text   = tkdesk_request_text( $row );
	$saved  = array();
	$causes = tkdesk_poster_causes();
	foreach ( is_wp_error( $text ) ? array() : (array) ( $text['reviews'] ?? array() ) as $review ) {
		$saved[ (int) ( $review['notice'] ?? 0 ) ] = (array) $review;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$labels  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, label FROM %i WHERE request_id = %d', tkdesk_table( 'items' ), $row['id'] ), OBJECT_K );
	$names   = static function ( array $ids ) use ( $labels ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = isset( $labels[ $id ] ) ? (string) $labels[ $id ]->label : '';
		}
		return $out;
	};
	$notices = array();
	$reviews = array();
	foreach ( tkdesk_log_for_request( (int) $row['id'] ) as $log ) {
		$data  = json_decode( (string) $log['detail'], true );
		$data  = is_array( $data ) ? $data : array();
		$at    = tkdesk_ts( $log['created_at'] );
		$last  = count( $reviews ) - 1;
		$event = (string) $log['event'];
		if ( in_array( $event, array( 'poster_notified', 'poster_not_notified' ), true ) ) {
			$notices[] = array(
				'at'    => $at,
				'sent'  => 'poster_notified' === $event,
				'items' => $names( array_map( 'intval', (array) ( $data['items'] ?? array() ) ) ),
				'cause' => $causes[ $data['cause'] ?? '' ] ?? '',
			);
		} elseif ( 'review_requested' === $event ) {
			$review    = $saved[ (int) ( $data['notice'] ?? 0 ) ] ?? array();
			$reviews[] = array(
				'at'         => $at,
				'statement'  => (string) ( $review['statement'] ?? '' ),
				'signature'  => (string) ( $review['signature'] ?? '' ),
				'outcome'    => 'pending',
				'decided_at' => 0,
				'decided_by' => '',
				'note'       => '',
			);
		} elseif ( in_array( $event, array( 'review_kept', 'restored' ), true ) && $last >= 0 && 'pending' === $reviews[ $last ]['outcome'] ) {
			$reviews[ $last ] = array_merge(
				$reviews[ $last ],
				array(
					'outcome'    => 'review_kept' === $event ? 'kept' : 'restored',
					'decided_at' => $at,
					'decided_by' => (string) $log['actor_label'],
					'note'       => (string) ( $data['note'] ?? '' ),
				)
			);
		}
	}
	return array(
		'on'      => tkdesk_poster_notice_on(),
		'notices' => $notices,
		'reviews' => $reviews,
		'pending' => $reviews && 'pending' === end( $reviews )['outcome'],
	);
}

/**
 * Admin route: POST /admin/requests/{id}/review { note }: keep the content offline after a review request.
 */
function tkdesk_review_routes(): void {
	register_rest_route(
		'takedown-desk/v1',
		'/admin/requests/(?P<id>[0-9]+)/review',
		array(
			'methods'             => 'POST',
			'callback'            => 'tkdesk_rest_admin_review',
			'permission_callback' => static function () {
				return tkdesk_can( 'tkdesk_manage_requests' );
			},
		)
	);
}

/**
 * Keep the content offline after a review request: logs review_kept with the note. Putting it back is the
 * usual restore (administrators, inside the restore window).
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error Detail JSON, 400 tkdesk_missing_note or 409 tkdesk_no_review.
 */
function tkdesk_rest_admin_review( WP_REST_Request $request ) {
	$row = tkdesk_rest_admin_find( $request );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$note = trim( sanitize_textarea_field( tkdesk_rest_str( $request, 'note' ) ) );
	if ( mb_strlen( $note ) < 10 || mb_strlen( $note ) > 2000 ) {
		$message = __( 'Write a decision note of at least 10 characters.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_missing_note',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'note' => $message ),
			)
		);
	}
	if ( ! tkdesk_review_pending( (int) $row['id'] ) ) {
		return new WP_Error( 'tkdesk_no_review', __( 'No review is waiting for a decision. Reload the request to see where it stands.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	tkdesk_log( (int) $row['id'], 'review_kept', array( 'note' => $note ) );
	return tkdesk_rest_request( tkdesk_get_request( (int) $row['id'] ), true );
}

/**
 * Once per request: a status update to the requester while the request is still open 6 days after it arrived,
 * with a fresh status link (the raw token is never stored; the old link keeps working if the email is not sent).
 * Logs status_update { sent }. Test requests and requests filed by the site are skipped. Runs from tkdesk_tick().
 */
function tkdesk_status_updates(): void {
	global $wpdb;
	if ( ! tkdesk_email_saved( 'requester_update' )['active'] ) {
		return;
	}
	$table = tkdesk_table( 'requests' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_requests and tkdesk_log.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i r WHERE r.status IN (%s, %s, %s) AND r.is_test = 0 AND r.relationship <> %s AND r.contact_enc IS NOT NULL AND r.received_at <= %s AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.request_id = r.id AND l.event = %s )', $table, 'new', 'in_review', 'needs_info', 'site', tkdesk_mysql( tkdesk_now() - 6 * DAY_IN_SECONDS ), tkdesk_table( 'log' ), 'status_update' ), ARRAY_A );
	foreach ( $rows as $row ) {
		$request = tkdesk_request_row( $row );
		$token   = bin2hex( random_bytes( 32 ) );
		$hash    = hash( 'sha256', $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$wpdb->update( $table, array( 'token_hash' => $hash ), array( 'id' => $request['id'] ) );
		// ponytail: the record row is the once-only guard; WP-Cron runs one tick at a time, a lock would cover overlapping runs.
		$sent = tkdesk_send_email( 'requester_update', array_merge( $request, array( 'token_hash' => $hash ) ), array( 'token' => $token ) );
		if ( ! $sent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
			$wpdb->update( $table, array( 'token_hash' => $request['token_hash'] ), array( 'id' => $request['id'] ) );
		}
		tkdesk_log( (int) $request['id'], 'status_update', array( 'sent' => $sent ), 0 );
	}
}

/**
 * Review page on the notice page (router: tkdesk_review + token): the form, or where things stand.
 *
 * @param string $public_id Request ID from the link.
 * @param string $token     Raw token from the link.
 * @param array  $state     values, errors (field => message), notice.
 * @return string
 */
function tkdesk_view_review( string $public_id, string $token, array $state = array() ): string {
	$found = tkdesk_review_notice( $public_id, $token );
	$box   = static function ( string $icon, string $title, string $text ): string {
		return '<div class="tkd-status tkd-review">'
			. '<header class="tkd-head"><h2 class="tkd-title" tabindex="-1">' . tkdesk_icon( $icon ) . '<span>' . esc_html( $title ) . '</span></h2></header>'
			. '<div class="tkd-box"><p>' . esc_html( $text ) . '</p></div>'
			. '</div>';
	};
	if ( is_wp_error( $found ) ) {
		return $box( 'info', __( 'This link no longer works', 'takedown-desk' ), __( 'Open the most recent email we sent you about the removal and use the link in it. If you still need help, reply to that email.', 'takedown-desk' ) );
	}
	if ( $found['used'] ) {
		return $box( 'check', __( 'Review requested', 'takedown-desk' ), __( 'We received your request. A moderator will look at the removal again. The content stays offline while they review it.', 'takedown-desk' ) );
	}
	if ( ! $found['offline'] ) {
		return $box( 'info', __( 'Nothing to review', 'takedown-desk' ), __( 'This content is back on the site, so there is nothing to review.', 'takedown-desk' ) );
	}

	$values = isset( $state['values'] ) ? (array) $state['values'] : array();
	$errors = isset( $state['errors'] ) ? (array) $state['errors'] : array();
	$notice = isset( $state['notice'] ) ? (string) $state['notice'] : '';
	$defs   = array(
		'statement'  => array(
			'type'     => 'textarea',
			'label'    => __( 'Why should we put it back?', 'takedown-desk' ),
			'help'     => __( 'Tell us why you think the removal was a mistake. Do not include anyone else\'s private details.', 'takedown-desk' ),
			'required' => true,
		),
		'signature'  => array(
			'type'         => 'text',
			'label'        => __( 'Signature', 'takedown-desk' ),
			'help'         => __( 'Type your full name. This counts as your signature.', 'takedown-desk' ),
			'required'     => true,
			'autocomplete' => 'name',
		),
		'good_faith' => array(
			'type'     => 'checkbox',
			'label'    => __( 'I believe in good faith that this content was removed by mistake, and the information I gave is accurate.', 'takedown-desk' ),
			'required' => true,
		),
	);
	$fields = '';
	foreach ( $defs as $name => $def ) {
		$fields .= tkdesk_view_field( $name, $def, $values[ $name ] ?? '', (string) ( $errors[ $name ] ?? '' ) );
	}
	if ( tkdesk_setting( 'bot_check' ) ) {
		$q       = tkdesk_question();
		$fields .= '<div class="tkd-q">' . tkdesk_view_field(
			'tkdesk_answer',
			array(
				'type'         => 'text',
				/* translators: 1, 2: numbers from 1 to 9. */
				'label'        => sprintf( __( 'What is %1$d plus %2$d?', 'takedown-desk' ), $q['a'], $q['b'] ),
				'help'         => __( 'Type the answer in digits. This shows that a person is sending the form.', 'takedown-desk' ),
				'required'     => true,
				'autocomplete' => 'off',
			),
			'',
			(string) ( $errors['tkdesk_answer'] ?? '' )
		) . '<input type="hidden" name="tkdesk_q" value="' . esc_attr( $q['token'] ) . '"></div>';
	} elseif ( 'recaptcha' === tkdesk_bot_mode() ) {
		$fields .= tkdesk_view_captcha();
	}
	$list = '';
	foreach ( $errors as $name => $message ) {
		$list .= '<li><a href="#tkd-' . esc_attr( $name ) . '">' . esc_html( $message ) . '</a></li>';
	}
	$items = '';
	foreach ( $found['items'] as $item ) {
		$items .= '<li>' . esc_html( (string) $item['label'] ) . '</li>';
	}
	$stamp = empty( $values['tkdesk_ts'] ) || empty( $values['tkdesk_sig'] ) ? tkdesk_form_stamp() : array(
		'ts'  => $values['tkdesk_ts'],
		'sig' => $values['tkdesk_sig'],
	);

	return '<form class="tkd-form tkd-review" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate aria-labelledby="tkd-review-title">'
		. '<header class="tkd-head"><h2 class="tkd-title" id="tkd-review-title">' . esc_html__( 'Ask for a review', 'takedown-desk' ) . '</h2>'
		. '<p class="tkd-intro">' . esc_html__( 'We removed the content below after a request under the Take It Down Act. If you think this was a mistake, tell us why and a moderator will look at it again. The content stays offline while they review it.', 'takedown-desk' ) . '</p></header>'
		. '<div class="tkd-notice" role="alert" tabindex="-1"' . ( '' === $notice ? ' hidden' : ' autofocus' ) . '>' . tkdesk_icon( 'error' ) . '<span>' . esc_html( $notice ) . '</span></div>'
		. '<div class="tkd-summary" role="alert" tabindex="-1" aria-labelledby="tkd-summary-title"' . ( '' === $list ? ' hidden' : ' autofocus' ) . '>'
		. '<h3 id="tkd-summary-title">' . esc_html__( 'Check these fields', 'takedown-desk' ) . '</h3><ul>' . $list . '</ul></div>'
		. ( '' !== $items ? '<div class="tkd-box tkd-item"><div><p><strong>' . esc_html__( 'Removed content', 'takedown-desk' ) . '</strong></p><ul class="tkd-review__items">' . $items . '</ul></div></div>' : '' )
		. $fields
		. '<div class="tkd-hp" aria-hidden="true"><label for="tkd-website">' . esc_html__( 'Website', 'takedown-desk' ) . '</label><input type="text" id="tkd-website" name="tkdesk_website" value="" tabindex="-1" autocomplete="off"></div>'
		. '<input type="hidden" name="action" value="tkdesk_review">'
		. '<input type="hidden" name="tkdesk_review" value="' . esc_attr( $public_id ) . '">'
		. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">'
		. '<input type="hidden" name="tkdesk_ts" value="' . esc_attr( $stamp['ts'] ) . '">'
		. '<input type="hidden" name="tkdesk_sig" value="' . esc_attr( $stamp['sig'] ) . '">'
		. '<p class="tkd-box tkd-note">' . tkdesk_icon( 'lock' ) . '<span>' . esc_html__( 'Only the people who handle removals on this site read your answer. We do not share it with the person who sent the request.', 'takedown-desk' ) . '</span></p>'
		. '<button type="submit" class="tkd-button wp-element-button">' . esc_html__( 'Ask for a review', 'takedown-desk' ) . '</button>'
		. '</form>';
}

/**
 * No-JS review form submission (admin-post.php, action tkdesk_review): back to the review page.
 */
function tkdesk_post_review() {
	$in     = tkdesk_posted( array( 'tkdesk_review', 'token', 'statement', 'signature', 'good_faith', 'tkdesk_website', 'tkdesk_ts', 'tkdesk_sig', 'tkdesk_pow', 'tkdesk_q', 'tkdesk_answer', 'g-recaptcha-response' ) );
	$args   = array(
		'tkdesk_review' => $in['tkdesk_review'],
		'token'         => $in['token'],
	);
	$result = tkdesk_submit_review( $in, tkdesk_client_ip() );
	$state  = array();
	if ( is_wp_error( $result ) ) {
		$data  = (array) $result->get_error_data();
		$state = array(
			'values' => array_intersect_key( $in, array_flip( array( 'statement', 'signature', 'good_faith', 'tkdesk_ts', 'tkdesk_sig' ) ) ),
			'errors' => (array) ( $data['fields'] ?? array() ),
			'notice' => empty( $data['fields'] ) ? $result->get_error_message() : '',
		);
		if ( 'tkdesk_form_expired' === $result->get_error_code() ) {
			unset( $state['values']['tkdesk_ts'], $state['values']['tkdesk_sig'] );
		}
	}
	tkdesk_redirect_back( $args, $state );
}
