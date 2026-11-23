<?php
/**
 * Requests: public form fields, validation, creation, reads and the status machine.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request types: key => label.
 *
 * @return array
 */
function tkdesk_request_types(): array {
	return array(
		'ncii' => __( 'Intimate image request', 'takedown-desk' ),
		'dmca' => __( 'Copyright notice', 'takedown-desk' ),
	);
}

/**
 * Public form fields: the single definition read by validation and the form view.
 * `max` 0 means no length limit.
 *
 * @param string $type Request type: ncii (default) or dmca (copyright notice, 17 U.S.C. 512(c)(3)(A)).
 * @return array Field name => array( type, label, help, required, min, max, options, autocomplete ).
 */
function tkdesk_form_fields( string $type = 'ncii' ): array {
	if ( 'dmca' === $type ) {
		// One field per element of 17 U.S.C. 512(c)(3)(A), in form order: where the material is, the work,
		// the signature, contact details and the two statements.
		$f      = static function ( string $type, string $label, string $help, bool $required, int $min, int $max, array $options = array(), string $autocomplete = 'off' ): array {
			return array(
				'type'         => $type,
				'label'        => $label,
				'help'         => $help,
				'required'     => $required,
				'min'          => $min,
				'max'          => $max,
				'options'      => $options,
				'autocomplete' => $autocomplete,
			);
		};
		$fields = array(
			'url'          => $f( 'url', __( 'Where is the material on this site?', 'takedown-desk' ), __( 'Paste the link to the page that shows it. You can add more links in the details box.', 'takedown-desk' ), true, 0, 2000 ),
			'work'         => $f( 'textarea', __( 'Describe the copyrighted work', 'takedown-desk' ), __( 'For example: my photo of the harbor at sunset, or chapter 3 of my novel. If there are several works, list them.', 'takedown-desk' ), true, 10, 2000 ),
			'work_url'     => $f( 'url', __( 'Where can we see the original? (optional)', 'takedown-desk' ), __( 'A link to the original work, if it is online.', 'takedown-desk' ), false, 0, 2000 ),
			'details'      => $f( 'textarea', __( 'Details that help us find it', 'takedown-desk' ), __( 'For example: the third photo in the post, or the second paragraph of the text.', 'takedown-desk' ), false, 0, 2000 ),
			'relationship' => $f(
				'select',
				__( 'Who are you?', 'takedown-desk' ),
				'',
				true,
				0,
				0,
				array(
					'owner' => __( 'I own the copyright', 'takedown-desk' ),
					'agent' => __( 'I am authorized to act for the copyright owner', 'takedown-desk' ),
				)
			),
			'signature'    => $f( 'text', __( 'Signature', 'takedown-desk' ), __( 'Type your full name. This counts as your signature.', 'takedown-desk' ), true, 2, 120, array(), 'name' ),
			'email'        => $f( 'email', __( 'Your email', 'takedown-desk' ), __( 'We use it to contact you about this notice and to tell you the outcome.', 'takedown-desk' ), true, 0, 254, array(), 'email' ),
			'phone'        => $f( 'tel', __( 'Phone number (optional)', 'takedown-desk' ), __( 'Only if you prefer a call or text.', 'takedown-desk' ), false, 0, 30, array(), 'tel' ),
			'address'      => $f( 'textarea', __( 'Mailing address (optional)', 'takedown-desk' ), __( 'Street, city, postal code and country.', 'takedown-desk' ), false, 0, 300, array(), 'street-address' ),
			'good_faith'   => $f( 'checkbox', __( 'I believe in good faith that the use of the material is not authorized by the copyright owner, its agent or the law.', 'takedown-desk' ), '', true, 0, 0 ),
			'accuracy'     => $f( 'checkbox', __( 'The information in this notice is accurate, and under penalty of perjury, I am the copyright owner or am authorized to act for the owner.', 'takedown-desk' ), '', true, 0, 0 ),
		);
		return apply_filters( 'tkdesk_form_fields', $fields, $type );
	}
	$fields = array(
		'url'          => array(
			'type'         => 'url',
			'label'        => __( 'Where is the image?', 'takedown-desk' ),
			'help'         => __( 'Paste the link to the page that shows it. You can add more links in the next box.', 'takedown-desk' ),
			'required'     => true,
			'min'          => 0,
			'max'          => 2000,
			'options'      => array(),
			'autocomplete' => 'off',
		),
		'details'      => array(
			'type'         => 'textarea',
			'label'        => __( 'Details that help us find it', 'takedown-desk' ),
			'help'         => __( 'For example: the third photo in the post, or the profile picture.', 'takedown-desk' ),
			'required'     => false,
			'min'          => 0,
			'max'          => 2000,
			'options'      => array(),
			'autocomplete' => 'off',
		),
		'statement'    => array(
			'type'         => 'textarea',
			'label'        => __( 'Why are you asking for removal?', 'takedown-desk' ),
			'help'         => __( 'A sentence or two is enough. For example: "This photo of me was posted without my consent."', 'takedown-desk' ),
			'required'     => true,
			'min'          => 10,
			'max'          => 2000,
			'options'      => array(),
			'autocomplete' => 'off',
		),
		'email'        => array(
			'type'         => 'email',
			'label'        => __( 'Your email', 'takedown-desk' ),
			'help'         => __( 'We only use it to tell you the outcome.', 'takedown-desk' ),
			'required'     => true,
			'min'          => 0,
			'max'          => 254,
			'options'      => array(),
			'autocomplete' => 'email',
		),
		'phone'        => array(
			'type'         => 'tel',
			'label'        => __( 'Phone number (optional)', 'takedown-desk' ),
			'help'         => __( 'Only if you prefer a call or text.', 'takedown-desk' ),
			'required'     => false,
			'min'          => 0,
			'max'          => 30,
			'options'      => array(),
			'autocomplete' => 'tel',
		),
		'relationship' => array(
			'type'         => 'select',
			'label'        => __( 'Who are you asking for?', 'takedown-desk' ),
			'help'         => '',
			'required'     => true,
			'min'          => 0,
			'max'          => 0,
			'options'      => array(
				'self'       => __( 'Myself', 'takedown-desk' ),
				'authorized' => __( 'Someone I am authorized to act for', 'takedown-desk' ),
				'guardian'   => __( 'My child, as parent or guardian', 'takedown-desk' ),
			),
			'autocomplete' => 'off',
		),
		// Under 18 in the image: flags the request (minors.php), which changes how it is handled.
		'minor'        => array(
			'type'         => 'checkbox',
			'label'        => __( 'The person in the image was under 18 when it was taken.', 'takedown-desk' ),
			'help'         => __( 'Check this if you know or think so. You do not need to be sure.', 'takedown-desk' ),
			'required'     => false,
			'min'          => 0,
			'max'          => 0,
			'options'      => array(),
			'autocomplete' => 'off',
		),
		'signature'    => array(
			'type'         => 'text',
			'label'        => __( 'Signature', 'takedown-desk' ),
			'help'         => __( 'Type your full name. This counts as your signature.', 'takedown-desk' ),
			'required'     => true,
			'min'          => 2,
			'max'          => 120,
			'options'      => array(),
			'autocomplete' => 'name',
		),
		'good_faith'   => array(
			'type'         => 'checkbox',
			'label'        => __( 'I believe in good faith that this image was published without consent, and the information in this request is accurate.', 'takedown-desk' ),
			'help'         => '',
			'required'     => true,
			'min'          => 0,
			'max'          => 0,
			'options'      => array(),
			'autocomplete' => 'off',
		),
	);
	return apply_filters( 'tkdesk_form_fields', $fields, $type );
}

/**
 * Validate and sanitize a submission. Over-long input is rejected, never truncated. `type` picks the field set:
 * `ncii` (default) or `dmca` (a copyright notice: only while setting dmca is on, never filed by the site itself).
 *
 * @param array $in       Raw input.
 * @param bool  $manual   Manual entry by a moderator: the link may point off this site.
 * @param bool  $by_admin Manual entry filed by the site itself: no requester contact is asked for, the
 *                        relationship is `site` and the signature is the current user's display name.
 * @return array|WP_Error Clean input, or the first failing code with every failing field in data.fields.
 */
function tkdesk_validate_request( array $in, bool $manual = false, bool $by_admin = false ) {
	$by_admin = $manual && $by_admin;
	$dmca     = isset( $in['type'] ) && 'dmca' === $in['type'];
	if ( $dmca && ! tkdesk_setting( 'dmca' ) ) {
		return new WP_Error(
			'tkdesk_dmca_off',
			__( 'This site does not take copyright notices through this form.', 'takedown-desk' ),
			array(
				'status' => 400,
				'fields' => array(),
			)
		);
	}
	if ( $dmca && $by_admin ) {
		$message = __( 'Log a copyright notice for the person who sent it.', 'takedown-desk' );
		return new WP_Error(
			'tkdesk_invalid_filer',
			$message,
			array(
				'status' => 400,
				'fields' => array( 'filer' => $message ),
			)
		);
	}
	$type   = $dmca ? 'dmca' : 'ncii';
	$fields = tkdesk_form_fields( $type );
	$names  = $dmca ? array( 'url', 'work', 'work_url', 'details', 'relationship', 'signature', 'email', 'phone', 'address', 'good_faith', 'accuracy' ) : array( 'url', 'details', 'statement', 'email', 'phone', 'relationship', 'signature', 'good_faith' );
	$v      = array();
	foreach ( $names as $name ) {
		$defaults        = array(
			'type'     => 'text',
			'required' => false,
			'min'      => 0,
			'max'      => 0,
			'options'  => array(),
		);
		$fields[ $name ] = wp_parse_args( $fields[ $name ] ?? array(), $defaults );
		$raw             = isset( $in[ $name ] ) && is_scalar( $in[ $name ] ) ? str_replace( "\r\n", "\n", (string) $in[ $name ] ) : '';
		if ( in_array( $name, array( 'url', 'work_url' ), true ) ) {
			$v[ $name ] = trim( $raw );
		} elseif ( 'textarea' === $fields[ $name ]['type'] ) {
			$v[ $name ] = sanitize_textarea_field( $raw );
		} else {
			$v[ $name ] = sanitize_text_field( $raw );
		}
	}
	$url = esc_url_raw( $v['url'], array( 'http', 'https' ) );
	/* translators: %s: site address. */
	$off_site = sprintf( __( 'That link is not on this site. Paste a link that starts with %s.', 'takedown-desk' ), home_url() );
	$missing  = $dmca ? __( 'Add the link to the page that shows the material.', 'takedown-desk' ) : __( 'Add the link to the page that shows the image.', 'takedown-desk' );
	// Field => array( field-specific check passed, error code, error message ).
	$rules = array(
		'url'          => array(
			'' !== $url && ( $manual || tkdesk_url_on_site( $url ) ),
			'' === $v['url'] ? 'tkdesk_missing_url' : 'tkdesk_link_not_on_site',
			'' === $v['url'] ? $missing : $off_site,
		),
		'work'         => array( true, 'tkdesk_missing_work', __( 'Describe the copyrighted work in 10 to 2,000 characters.', 'takedown-desk' ) ),
		// The original may be anywhere on the web, so only the form of the link is checked.
		'work_url'     => array( (bool) preg_match( '#^https?://[^\s/?\#]+\S*$#i', $v['work_url'] ?? '' ), 'tkdesk_invalid_work_url', __( 'Paste a link that starts with http:// or https://, or leave this empty.', 'takedown-desk' ) ),
		'details'      => array( true, 'tkdesk_details_too_long', __( 'Keep this under 2,000 characters.', 'takedown-desk' ) ),
		'statement'    => array( true, 'tkdesk_missing_statement', __( 'Write a short statement. One or two sentences is enough.', 'takedown-desk' ) ),
		'email'        => array( (bool) is_email( $v['email'] ), 'tkdesk_invalid_email', '' === $v['email'] ? __( 'Enter an email address so we can tell you the outcome.', 'takedown-desk' ) : __( 'Enter an email address like name@example.com.', 'takedown-desk' ) ),
		'phone'        => array( (bool) preg_match( '/^[0-9 +().-]*$/', $v['phone'] ), 'tkdesk_invalid_phone', __( 'Enter a phone number with digits only, or leave this empty.', 'takedown-desk' ) ),
		'address'      => array( true, 'tkdesk_address_too_long', __( 'Keep the address under 300 characters.', 'takedown-desk' ) ),
		'relationship' => array( isset( $fields['relationship']['options'][ $v['relationship'] ] ), 'tkdesk_invalid_relationship', $dmca ? __( 'Choose whether you own the copyright or act for the owner.', 'takedown-desk' ) : __( 'Choose who you are asking for.', 'takedown-desk' ) ),
		'signature'    => array( true, 'tkdesk_missing_signature', $dmca ? __( 'Type your full name to sign the notice.', 'takedown-desk' ) : __( 'Type your full name to sign the request.', 'takedown-desk' ) ),
		'good_faith'   => array( rest_sanitize_boolean( $v['good_faith'] ), 'tkdesk_missing_good_faith', __( 'Check this box to confirm the statement.', 'takedown-desk' ) ),
		'accuracy'     => array( rest_sanitize_boolean( $v['accuracy'] ?? '' ), 'tkdesk_missing_accuracy', __( 'Check this box to confirm the statement.', 'takedown-desk' ) ),
	);
	// This type's fields only, in its form order.
	$rules = array_replace( array_flip( $names ), array_intersect_key( $rules, array_flip( $names ) ) );
	if ( $by_admin ) {
		$rules = array_diff_key( $rules, array_flip( array( 'email', 'phone', 'relationship', 'signature' ) ) );
	}
	$code   = '';
	$errors = array();
	foreach ( $rules as $name => $rule ) {
		$field = $fields[ $name ];
		$len   = mb_strlen( $v[ $name ] );
		if ( '' === $v[ $name ] && ! $field['required'] ) {
			continue;
		}
		if ( '' === $v[ $name ] || ! $rule[0] || $len < (int) $field['min'] || ( $field['max'] && $len > (int) $field['max'] ) ) {
			$code            = '' === $code ? $rule[1] : $code;
			$errors[ $name ] = $rule[2];
		}
	}
	if ( $errors ) {
		return new WP_Error(
			$code,
			reset( $errors ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
	$clean = array(
		'type'         => $type,
		'url'          => $url,
		'details'      => $v['details'],
		'statement'    => $v['statement'] ?? '',
		'email'        => $by_admin ? '' : sanitize_email( $v['email'] ),
		'phone'        => $by_admin ? '' : $v['phone'],
		'relationship' => $by_admin ? 'site' : $v['relationship'],
		'signature'    => $by_admin ? wp_get_current_user()->display_name : $v['signature'],
		'good_faith'   => true,
		'minor'        => ! $dmca && ! $by_admin && isset( $in['minor'] ) && is_scalar( $in['minor'] ) && rest_sanitize_boolean( $in['minor'] ),
		'item'         => isset( $in['item'] ) && is_string( $in['item'] ) && preg_match( '/^[a-z0-9_-]+:[0-9]+$/', $in['item'] ) ? $in['item'] : '',
	);
	if ( ! $dmca ) {
		return $clean;
	}
	return $clean + array(
		'work'     => $v['work'],
		'work_url' => esc_url_raw( $v['work_url'], array( 'http', 'https' ) ),
		'address'  => $v['address'],
		'accuracy' => true,
	);
}

/**
 * Next public ID `TD-YYMM-NNNN` (site timezone), from a per-month counter under a named lock.
 *
 * @param int $received_ts Received time.
 * @return string
 */
function tkdesk_next_public_id( int $received_ts ): string {
	global $wpdb;
	$yymm   = wp_date( 'ym', $received_ts );
	$option = 'tkdesk_seq_' . $yymm;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- named lock tkdesk_seq, no table.
	$wpdb->get_var( "SELECT GET_LOCK( 'tkdesk_seq', 5 )" );
	// Read the counter from the database, not a cache another process may have outdated.
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$next = (int) get_option( $option, 0 ) + 1;
	update_option( $option, $next, false );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- named lock tkdesk_seq, no table.
	$wpdb->get_var( "SELECT RELEASE_LOCK( 'tkdesk_seq' )" );
	return sprintf( 'TD-%s-%04d', $yymm, $next );
}

/**
 * Create a request from clean input.
 *
 * @param array $clean Output of tkdesk_validate_request().
 * @param array $ctx   source, received_at, ip, user_id, locale, is_test, status (`new` or `spam`).
 * @return array|WP_Error array( 'request' => row, 'token' => raw token ).
 */
function tkdesk_create_request( array $clean, array $ctx = array() ) {
	global $wpdb;
	$ctx      = wp_parse_args(
		$ctx,
		array(
			'source'      => 'form',
			'received_at' => tkdesk_now(),
			'ip'          => '',
			'user_id'     => get_current_user_id(),
			'locale'      => determine_locale(),
			'is_test'     => false,
			'status'      => 'new',
		)
	);
	$received = (int) $ctx['received_at'];
	$type     = 'dmca' === ( $clean['type'] ?? '' ) ? 'dmca' : 'ncii';
	$status   = 'spam' === $ctx['status'] ? 'spam' : 'new';
	$source   = in_array( $ctx['source'], array( 'form', 'content_link', 'manual' ), true ) ? $ctx['source'] : 'form';
	$target   = '' !== $clean['item'] ? tkdesk_parse_item_ref( $clean['item'] ) : null;
	$target   = $target ? $target : tkdesk_resolve_url( $clean['url'] );
	$desc     = $target ? tkdesk_describe( $target['object_type'], $target['object_id'] ) : null;
	$token    = bin2hex( random_bytes( 32 ) );
	$table    = tkdesk_table( 'requests' );
	$row      = array(
		'public_id'         => tkdesk_next_public_id( $received ),
		'type'              => $type,
		'status'            => $status,
		'source'            => $source,
		'received_at'       => tkdesk_mysql( $received ),
		'due_at'            => tkdesk_mysql( tkdesk_due( $received, $type ) ),
		'object_type'       => $target ? $target['object_type'] : null,
		'object_id'         => $target ? $target['object_id'] : null,
		'item_label'        => $desc ? $desc['label'] : '',
		'url_on_site'       => tkdesk_url_on_site( $clean['url'] ) ? 1 : 0,
		'relationship'      => $clean['relationship'],
		'good_faith'        => 1,
		'signed_at'         => tkdesk_mysql( $received ),
		'contact_enc'       => tkdesk_encrypt(
			wp_json_encode(
				array(
					'signature' => $clean['signature'],
					'email'     => $clean['email'],
					'phone'     => $clean['phone'],
					'address'   => (string) ( $clean['address'] ?? '' ),
				)
			)
		),
		'text_enc'          => tkdesk_encrypt(
			wp_json_encode(
				array(
					'url'         => $clean['url'],
					'details'     => $clean['details'],
					'statement'   => $clean['statement'],
					'work'        => (string) ( $clean['work'] ?? '' ),
					'work_url'    => (string) ( $clean['work_url'] ?? '' ),
					'info_thread' => array(),
				)
			)
		),
		'email_hash'        => 'site' === $clean['relationship'] ? null : tkdesk_hash( strtolower( $clean['email'] ) ),
		'ip_hash'           => '' !== $ctx['ip'] ? tkdesk_hash( $ctx['ip'] ) : null,
		'token_hash'        => hash( 'sha256', $token ),
		'submitter_user_id' => (int) $ctx['user_id'],
		'locale'            => substr( (string) $ctx['locale'], 0, 10 ),
		'is_test'           => $ctx['is_test'] ? 1 : 0,
		'minor'             => empty( $clean['minor'] ) ? 0 : 1,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( ! $wpdb->insert( $table, $row ) ) {
		return new WP_Error( 'tkdesk_save_failed', __( 'We could not save the request.', 'takedown-desk' ), array( 'status' => 500 ) );
	}
	$id = (int) $wpdb->insert_id;
	if ( $desc ) {
		$item = array(
			'request_id'  => $id,
			'role'        => 'reported',
			'object_type' => $target['object_type'],
			'object_id'   => $target['object_id'],
			'label'       => $desc['label'],
			'url'         => $desc['url'],
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$wpdb->insert( tkdesk_table( 'items' ), $item );
	}
	$detail = array(
		'source' => $source,
		'status' => $status,
	);
	if ( 'manual' === $source && in_array( $ctx['channel'] ?? '', array( 'email', 'phone', 'mail', 'other', 'admin' ), true ) ) {
		$detail['channel'] = $ctx['channel'];
	}
	tkdesk_log( $id, 'received', $detail, 'manual' === $source ? null : -1 );
	if ( $row['minor'] ) {
		tkdesk_log( $id, 'minor_flagged', array(), 'manual' === $source ? null : -1 );
	}
	$request = tkdesk_get_request( $id );
	do_action( 'tkdesk_request_received', $id, $request );
	if ( 'spam' !== $status ) {
		// Abuse guard: at most 3 confirmations per email hash per 24 hours; later requests are still saved.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email_hash = %s AND status <> %s AND received_at >= %s AND id <> %d', $table, $row['email_hash'], 'spam', tkdesk_mysql( tkdesk_now() - DAY_IN_SECONDS ), $id ) );
		if ( $recent < 3 ) {
			tkdesk_send_email( $row['minor'] ? 'requester_minor' : 'requester_received', $request, array( 'token' => $token ) );
		}
		tkdesk_send_email( 'mod_new', $request );
	}
	return array(
		'request' => $request,
		'token'   => $token,
	);
}

/**
 * Cast a raw requests row: integer columns to int, NULLs kept.
 *
 * @param array $row Row from the requests table.
 * @return array
 */
function tkdesk_request_row( array $row ): array {
	foreach ( array( 'id', 'object_id', 'url_on_site', 'good_faith', 'submitter_user_id', 'assigned_to', 'is_test', 'minor' ) as $col ) {
		$row[ $col ] = isset( $row[ $col ] ) ? (int) $row[ $col ] : null;
	}
	return $row;
}

/**
 * One request row by ID. Never includes decrypted data.
 *
 * @param int $id Request ID.
 * @return array|null
 */
function tkdesk_get_request( int $id ): ?array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', tkdesk_table( 'requests' ), $id ), ARRAY_A );
	return $row ? tkdesk_request_row( $row ) : null;
}

/**
 * One request row by public ID.
 *
 * @param string $public_id Public ID, e.g. TD-2611-0047.
 * @return array|null
 */
function tkdesk_get_request_by_public_id( string $public_id ): ?array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', tkdesk_table( 'requests' ), $public_id ), ARRAY_A );
	return $row ? tkdesk_request_row( $row ) : null;
}

/**
 * List requests for the inbox. `all` lists everything except spam.
 *
 * @param array $args status (open|overdue|needs_info|removed|declined|spam|all), search, page, per_page,
 *                    orderby (received|due|id|status|assigned; anything else sorts by received), order (asc|desc),
 *                    type ('' for every type, ncii or dmca).
 * @return array array( 'items' => rows, 'total' => int ).
 */
function tkdesk_list_requests( array $args ): array {
	global $wpdb;
	$args   = wp_parse_args(
		$args,
		array(
			'status'   => 'open',
			'search'   => '',
			'page'     => 1,
			'per_page' => 20,
			'orderby'  => 'received',
			'order'    => 'desc',
			'type'     => '',
		)
	);
	$open   = tkdesk_open_statuses();
	$map    = array(
		'open'       => $open,
		'overdue'    => $open,
		'needs_info' => array( 'needs_info' ),
		'removed'    => array( 'removed' ),
		'declined'   => array( 'declined' ),
		'spam'       => array( 'spam' ),
		'all'        => array_merge( $open, array( 'removed', 'declined' ) ),
		'review'     => array( 'removed', 'declined' ),
	);
	$status = isset( $map[ $args['status'] ] ) ? $args['status'] : 'open';
	$where  = 'status IN (' . implode( ',', array_fill( 0, count( $map[ $status ] ), '%s' ) ) . ')';
	$params = array_merge( array( tkdesk_table( 'requests' ) ), $map[ $status ] );
	if ( 'overdue' === $status ) {
		$where   .= ' AND due_at < %s';
		$params[] = tkdesk_mysql( tkdesk_now() );
	}
	if ( 'review' === $status ) {
		$where   .= ' AND id IN (' . tkdesk_review_waiting_sql() . ')';
		$params[] = tkdesk_table( 'log' );
		$params[] = tkdesk_table( 'log' );
	}
	if ( isset( tkdesk_request_types()[ $args['type'] ] ) ) {
		$where   .= ' AND type = %s';
		$params[] = $args['type'];
	}
	if ( '' !== trim( (string) $args['search'] ) ) {
		$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
		$where   .= ' AND ( public_id LIKE %s OR item_label LIKE %s )';
		$params[] = $like;
		$params[] = $like;
	}
	$order    = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
	$open_in  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
	$assignee = '( SELECT display_name FROM %i WHERE ID = assigned_to )';
	// Sort keys, each fixed SQL with placeholders plus its values; only these can reach ORDER BY.
	$sorts = array(
		'received' => array( "received_at $order", array() ),
		// Open requests first, soonest deadline first; closed ones after them (all reversed for desc).
		'due'      => array( "status NOT IN ($open_in) $order, due_at $order", $open ),
		'id'       => array( "public_id $order", array() ),
		// Workflow order rather than alphabetical.
		'status'   => array( "FIELD( status, %s, %s, %s, %s, %s, %s ) $order", array( 'new', 'in_review', 'needs_info', 'removed', 'declined', 'spam' ) ),
		// Assignee display name; unassigned (and deleted users) last in both directions.
		'assigned' => array( "$assignee IS NULL, $assignee $order", array( $wpdb->users, $wpdb->users ) ),
	);
	list( $sort, $sort_params ) = $sorts[ (string) $args['orderby'] ] ?? $sorts['received'];
	$per_page                   = max( 1, (int) $args['per_page'] );
	$offset                     = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table tkdesk_requests; $where is fixed SQL with placeholders.
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $params ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- own table tkdesk_requests; $where and $sort are fixed SQL with placeholders from the whitelist above, $order is ASC or DESC.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY $sort, id $order LIMIT %d OFFSET %d", array_merge( $params, $sort_params, array( $per_page, $offset ) ) ), ARRAY_A );
	return array(
		'items' => array_map( 'tkdesk_request_row', (array) $rows ),
		'total' => $total,
	);
}

/**
 * SQL for the IDs of requests with a review request waiting for a moderator. Prepare with the log table twice.
 *
 * @return string
 */
function tkdesk_review_waiting_sql(): string {
	// Requests whose latest review event (review_requested, review_kept, restored) is a review request. Two %i: the log table.
	return "SELECT l.request_id FROM %i l WHERE l.event = 'review_requested' AND l.id = ( SELECT MAX( l2.id ) FROM %i l2 WHERE l2.request_id = l.request_id AND l2.event IN ( 'review_requested', 'review_kept', 'restored' ) )";
}

/**
 * Inbox counts, test requests excluded.
 *
 * @return array Keys open, overdue, needs_info, removed, declined, spam, review, copyright (copyright notices, spam left out).
 */
function tkdesk_request_counts(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, SUM( due_at < %s ) AS late, SUM( type = %s ) AS copyright FROM %i WHERE is_test = 0 GROUP BY status', tkdesk_mysql( tkdesk_now() ), 'dmca', tkdesk_table( 'requests' ) ), ARRAY_A );
	$counts = array_fill_keys( array( 'open', 'overdue', 'needs_info', 'removed', 'declined', 'spam', 'review', 'copyright' ), 0 );
	// Requests whose latest review event is a review request (same rule as tkdesk_review_waiting_sql()).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_requests and tkdesk_log.
	$counts['review'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r WHERE r.is_test = 0 AND r.id IN ( SELECT l.request_id FROM %i l WHERE l.event = 'review_requested' AND l.id = ( SELECT MAX( l2.id ) FROM %i l2 WHERE l2.request_id = l.request_id AND l2.event IN ( 'review_requested', 'review_kept', 'restored' ) ) )", tkdesk_table( 'requests' ), tkdesk_table( 'log' ), tkdesk_table( 'log' ) ) );
	foreach ( (array) $rows as $row ) {
		if ( tkdesk_is_open( $row['status'] ) ) {
			$counts['open']    += (int) $row['n'];
			$counts['overdue'] += (int) $row['late'];
		}
		if ( isset( $counts[ $row['status'] ] ) ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$counts['copyright'] += 'spam' === $row['status'] ? 0 : (int) $row['copyright'];
	}
	return $counts;
}

/**
 * Decrypt one encrypted column of a request into an array merged over defaults.
 *
 * @param array  $request  Request row.
 * @param string $column   contact_enc or text_enc.
 * @param array  $defaults Keys the result always has.
 * @return array|WP_Error WP_Error tkdesk_purged or tkdesk_key_changed.
 */
function tkdesk_request_secret( array $request, string $column, array $defaults ) {
	if ( empty( $request[ $column ] ) ) {
		return new WP_Error( 'tkdesk_purged', __( 'This information was deleted under the data retention setting.', 'takedown-desk' ) );
	}
	$plain = tkdesk_decrypt( $request[ $column ] );
	if ( is_wp_error( $plain ) ) {
		return new WP_Error( 'tkdesk_key_changed', __( 'This information cannot be read because the encryption key changed.', 'takedown-desk' ) );
	}
	$data = json_decode( $plain, true );
	return array_merge( $defaults, is_array( $data ) ? $data : array() );
}

/**
 * Decrypted request text.
 *
 * @param array $request Request row.
 * @return array|WP_Error array( url, details, statement, work, work_url, info_thread ).
 */
function tkdesk_request_text( array $request ) {
	$defaults = array(
		'url'         => '',
		'details'     => '',
		'statement'   => '',
		'work'        => '',
		'work_url'    => '',
		'info_thread' => array(),
	);
	return tkdesk_request_secret( $request, 'text_enc', $defaults );
}

/**
 * Decrypted requester contact.
 *
 * @param array $request Request row.
 * @return array|WP_Error array( signature, email, phone, address ) or WP_Error tkdesk_purged | tkdesk_key_changed.
 */
function tkdesk_request_contact( array $request ) {
	$defaults = array(
		'signature' => '',
		'email'     => '',
		'phone'     => '',
		'address'   => '',
	);
	return tkdesk_request_secret( $request, 'contact_enc', $defaults );
}

/**
 * Check a requester token against the stored hash.
 *
 * @param array  $request Request row.
 * @param string $token   Raw token.
 * @return bool
 */
function tkdesk_verify_token( array $request, string $token ): bool {
	return ! empty( $request['token_hash'] ) && '' !== $token && hash_equals( $request['token_hash'], hash( 'sha256', $token ) );
}

/**
 * Move a request to another status with every side effect of plan 3.3.
 *
 * Emails with a private link (ask for details, declined) issue a fresh token, because the raw
 * token is never stored; the earlier link stops working only when the new email was sent.
 *
 * @param int    $id   Request ID.
 * @param string $to   Target status.
 * @param array  $args note, reason, message, event, removed_count, duplicate_of (public ID for reason `duplicate`),
 *                     restore_hidden (declined: put hidden items back, default true; false keeps them offline).
 *                     Spam always puts hidden items back, except under 18 in the image.
 * @return array|WP_Error Updated row.
 */
function tkdesk_transition( int $id, string $to, array $args = array() ) {
	global $wpdb;
	$request = tkdesk_get_request( $id );
	if ( ! $request ) {
		return new WP_Error( 'tkdesk_not_found', __( 'This request does not exist.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	$from    = $request['status'];
	$args    = wp_parse_args(
		$args,
		array(
			'note'           => '',
			'reason'         => '',
			'message'        => '',
			'event'          => '',
			'removed_count'  => 0,
			'duplicate_of'   => '',
			'restore_hidden' => true,
		)
	);
	$note    = sanitize_textarea_field( (string) $args['note'] );
	$message = sanitize_textarea_field( (string) $args['message'] );
	$reason  = (string) $args['reason'];
	$closing = in_array( $to, array( 'removed', 'declined' ), true );
	$allowed = array(
		'new'        => array( 'in_review', 'removed', 'declined', 'spam' ),
		'in_review'  => array( 'needs_info', 'removed', 'declined', 'spam' ),
		'needs_info' => array( 'in_review', 'removed', 'declined' ),
		'removed'    => array( 'in_review', 'declined' ),
		'declined'   => array( 'in_review' ),
		'spam'       => array( 'in_review' ),
	);
	/* translators: 1: current status, 2: requested status. */
	$bad = new WP_Error( 'tkdesk_bad_transition', sprintf( __( 'This request cannot move from %1$s to %2$s.', 'takedown-desk' ), $from, $to ), array( 'status' => 409 ) );
	if ( ! isset( $allowed[ $from ] ) || ! in_array( $to, $allowed[ $from ], true ) ) {
		return $bad;
	}
	if ( $closing && mb_strlen( $note ) < 10 ) {
		return new WP_Error( 'tkdesk_missing_note', __( 'Write a decision note of at least 10 characters.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( 'declined' === $to && ! array_key_exists( $reason, tkdesk_decline_reasons( '', (string) $request['type'] ) ) ) {
		return new WP_Error( 'tkdesk_missing_reason', __( 'Choose a reason for not removing the item.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	// From removed, only the restore outcome may decline; that reason fits no other path.
	if ( 'declined' === $to && ( 'removed' === $from ) !== ( 'restored_after_review' === $reason ) ) {
		return $bad;
	}
	// Filed by the site itself (relationship `site`): there is no requester to ask or email.
	$no_requester = new WP_Error( 'tkdesk_no_requester', __( 'An administrator filed this request, so there is no requester to ask.', 'takedown-desk' ), array( 'status' => 409 ) );
	if ( 'needs_info' === $to && 'site' === $request['relationship'] ) {
		return $no_requester;
	}
	if ( 'needs_info' === $to && '' === $message ) {
		return new WP_Error( 'tkdesk_missing_message', __( 'Write the question for the requester.', 'takedown-desk' ), array( 'status' => 400 ) );
	}

	$now       = tkdesk_mysql( tkdesk_now() );
	$old_token = $request['token_hash'];
	$set       = array( 'status' => $to );
	$detail    = array( 'from' => $from );
	$emails    = array(
		'needs_info' => 'requester_needs_info',
		'removed'    => 'requester_removed',
		'declined'   => 'requester_declined',
	);
	$events    = array(
		'needs_info' => 'info_requested',
		'removed'    => 'removed',
		'declined'   => 'declined',
		'spam'       => 'marked_spam',
	);
	$vars      = array(
		'question'      => $message,
		'message'       => $message,
		'reason'        => $reason,
		'removed_count' => absint( $args['removed_count'] ),
		'duplicate_of'  => sanitize_text_field( (string) $args['duplicate_of'] ),
	);
	if ( 'in_review' !== $to ) {
		$event = $events[ $to ];
	} elseif ( 'new' === $from ) {
		$event = 'opened';
	} elseif ( 'needs_info' === $from ) {
		$event = 'info_skipped';
	} else {
		$event                 = 'reopened';
		$set['resolved_at']    = null;
		$set['decline_reason'] = null;
	}
	if ( '' !== $args['event'] ) {
		$event = sanitize_key( $args['event'] );
	}
	if ( 'in_review' === $to && empty( $request['first_opened_at'] ) ) {
		$set['first_opened_at'] = $now;
	}
	if ( $closing ) {
		$set['resolved_at']   = $now;
		$set['decision_note'] = $note;
		$detail['note']       = $note;
	}
	if ( 'declined' === $to ) {
		$set['decline_reason'] = $reason;
		$detail['reason']      = $reason;
	}
	$contact = isset( $emails[ $to ] ) ? tkdesk_request_contact( $request ) : null;
	if ( $contact && 'site' === $request['relationship'] ) {
		$contact = $no_requester;
	}
	if ( 'needs_info' === $to ) {
		$text = tkdesk_request_text( $request );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text['info_thread'][] = array(
			'at'   => $now,
			'from' => 'moderator',
			'text' => $message,
		);
		$set['text_enc']       = tkdesk_encrypt( wp_json_encode( $text ) );
	}
	if ( in_array( $to, array( 'needs_info', 'declined' ), true ) && ! is_wp_error( $contact ) ) {
		$vars['token']     = bin2hex( random_bytes( 32 ) );
		$set['token_hash'] = hash( 'sha256', $vars['token'] );
	}

	$table = tkdesk_table( 'requests' );
	// The status condition makes the move atomic when two moderators act at once.
	$where = array(
		'id'     => $id,
		'status' => $from,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( ! $wpdb->update( $table, $set, $where ) ) {
		return $bad;
	}
	tkdesk_log( $id, $event, $detail );
	if ( in_array( $to, array( 'declined', 'spam' ), true ) ) {
		// Under 18 in the image (minors.php), a hidden item stays offline whatever the moderator chose.
		tkdesk_unhide_items( $id, array(), ( 'declined' === $to && ! $args['restore_hidden'] ) || ! empty( $request['minor'] ) );
	}
	$request = tkdesk_get_request( $id );

	if ( isset( $emails[ $to ] ) ) {
		$sent = ! is_wp_error( $contact ) && tkdesk_send_email( $emails[ $to ], $request, $vars );
		if ( ! $sent && isset( $set['token_hash'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
			$wpdb->update( $table, array( 'token_hash' => $old_token ), array( 'id' => $id ) );
		}
		if ( $closing && $sent ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
			$wpdb->update( $table, array( 'requester_notified_at' => $now ), array( 'id' => $id ) );
			tkdesk_log( $id, 'requester_notified' );
		} elseif ( $closing ) {
			$cause = tkdesk_email_saved( tkdesk_email_key( $emails[ $to ], $request ) )['active'] ? 'email_failed' : 'email_off';
			tkdesk_log( $id, 'requester_not_notified', array( 'cause' => is_wp_error( $contact ) ? $contact->get_error_code() : $cause ) );
		}
		$request = tkdesk_get_request( $id );
	}
	do_action( 'tkdesk_request_status_changed', $id, $from, $to, $request );
	return $request;
}

/**
 * Undo of "Continue without waiting": back to waiting for details with the question already sent
 * and the same private link, so no new email. Only while the skip is the request's latest record entry.
 *
 * @param int $id Request ID.
 * @return array|WP_Error Updated row.
 */
function tkdesk_resume_waiting( int $id ) {
	global $wpdb;
	$rows    = tkdesk_log_for_request( $id );
	$last    = end( $rows );
	$changed = new WP_Error( 'tkdesk_bad_transition', __( 'This request changed in the meantime. Reload it to see where it stands.', 'takedown-desk' ), array( 'status' => 409 ) );
	$where   = array(
		'id'     => $id,
		'status' => 'in_review',
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( ! $last || 'info_skipped' !== $last['event'] || ! $wpdb->update( tkdesk_table( 'requests' ), array( 'status' => 'needs_info' ), $where ) ) {
		return $changed;
	}
	tkdesk_log( $id, 'info_resumed' );
	$request = tkdesk_get_request( $id );
	do_action( 'tkdesk_request_status_changed', $id, 'in_review', 'needs_info', $request );
	return $request;
}

/**
 * Requester adds details through the private link. Moves needs_info to in_review.
 *
 * @param int    $id      Request ID.
 * @param string $message Requester text, 10 to 2000 characters.
 * @return array|WP_Error Updated row.
 */
function tkdesk_add_requester_reply( int $id, string $message ) {
	global $wpdb;
	$request = tkdesk_get_request( $id );
	$closed  = new WP_Error( 'tkdesk_closed', __( 'This request is closed, so details can no longer be added.', 'takedown-desk' ), array( 'status' => 409 ) );
	if ( ! $request || ! tkdesk_is_open( $request['status'] ) ) {
		return $closed;
	}
	$message = sanitize_textarea_field( str_replace( "\r\n", "\n", $message ) );
	$len     = mb_strlen( $message );
	if ( $len < 10 || $len > 2000 ) {
		return new WP_Error( 'tkdesk_missing_reply', __( 'Write a message of 10 to 2,000 characters.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	// At most 5 replies per request per hour, counted from the record, so moderators are not flooded.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
	$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_id = %d AND event = %s AND created_at >= %s', tkdesk_table( 'log' ), $id, 'info_received', tkdesk_mysql( tkdesk_now() - HOUR_IN_SECONDS ) ) );
	if ( $recent >= 5 ) {
		return new WP_Error( 'tkdesk_reply_limited', __( 'You already sent 5 replies in the last hour. Please wait an hour, then send your reply again.', 'takedown-desk' ), array( 'status' => 429 ) );
	}
	$text = tkdesk_request_text( $request );
	if ( is_wp_error( $text ) ) {
		return $text;
	}
	$text['info_thread'][] = array(
		'at'   => tkdesk_mysql( tkdesk_now() ),
		'from' => 'requester',
		'text' => $message,
	);
	$set                   = array( 'text_enc' => tkdesk_encrypt( wp_json_encode( $text ) ) );
	if ( 'needs_info' === $request['status'] ) {
		$set['status'] = 'in_review';
	}
	$where = array(
		'id'     => $id,
		'status' => $request['status'],
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	if ( ! $wpdb->update( tkdesk_table( 'requests' ), $set, $where ) ) {
		return $closed;
	}
	tkdesk_log( $id, 'info_received', array(), -1 );
	$updated = tkdesk_get_request( $id );
	if ( isset( $set['status'] ) ) {
		do_action( 'tkdesk_request_status_changed', $id, 'needs_info', 'in_review', $updated );
	}
	tkdesk_send_email( 'mod_reply', $updated );
	return $updated;
}

/**
 * Decline reasons with the requester-facing sentence.
 *
 * @param string $duplicate_of Public ID of the earlier request, used by the `duplicate` sentence.
 * @param string $type         Request type: the reasons of a copyright notice differ.
 * @return array key => sentence.
 */
function tkdesk_decline_reasons( string $duplicate_of = '', string $type = 'ncii' ): array {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	/* translators: %s: ID of the earlier request. */
	$duplicate = '' !== $duplicate_of ? sprintf( __( 'We already handled this item under request %s.', 'takedown-desk' ), $duplicate_of ) : __( 'We already handled this item under another request.', 'takedown-desk' );
	if ( 'dmca' === $type ) {
		return array(
			'dmca_incomplete'       => __( 'The notice is missing information we need to act on it.', 'takedown-desk' ),
			'dmca_not_found'        => __( 'We could not find the material on this site from the information in the notice.', 'takedown-desk' ),
			'dmca_not_infringing'   => __( 'After reviewing the notice, we did not remove the material.', 'takedown-desk' ),
			'duplicate'             => $duplicate,
			// Restoring the last removed item closes the request this way, whatever its type (objects.php).
			'restored_after_review' => __( 'After a further review we put the item back. Reply if you have more information.', 'takedown-desk' ),
		);
	}
	return array(
		'not_found'             => __( 'We could not find the image from the information in the request.', 'takedown-desk' ),
		/* translators: %s: site name. */
		'not_on_site'           => sprintf( __( 'The link is not hosted on %s, so we cannot remove it. Contact the site that hosts it.', 'takedown-desk' ), $site ),
		'incomplete'            => __( 'The request was missing information we need and we did not hear back.', 'takedown-desk' ),
		'not_intimate'          => __( 'The item at that link does not show what the request describes.', 'takedown-desk' ),
		'duplicate'             => $duplicate,
		// Not made in good faith: the requester reads a neutral sentence, never an accusation.
		'bad_faith'             => __( 'We could not confirm the request with the information we have.', 'takedown-desk' ),
		'restored_after_review' => __( 'After a further review we put the item back. Reply if you have more information.', 'takedown-desk' ),
	);
}
