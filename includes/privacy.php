<?php
/**
 * WordPress privacy tools: exporter, eraser and the suggested privacy policy text.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_privacy_personal_data_exporters',
	static function ( $exporters ) {
		$exporters['takedown-desk'] = array(
			'exporter_friendly_name' => __( 'Takedown Desk', 'takedown-desk' ),
			'callback'               => 'tkdesk_privacy_export',
		);
		return $exporters;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	static function ( $erasers ) {
		$erasers['takedown-desk'] = array(
			'eraser_friendly_name' => __( 'Takedown Desk', 'takedown-desk' ),
			'callback'             => 'tkdesk_privacy_erase',
		);
		return $erasers;
	}
);
add_action(
	'admin_init',
	static function () {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		/* translators: %d: number of days. */
		$text = sprintf( __( 'When you send a removal request, this site stores what you enter in the form: the link you report, your description and statement, your name as a signature, your email address and, if you give it, your phone number. These details are encrypted. Your IP address is not stored; a one-way code made from it is kept to limit abuse of the form. The people who handle removal requests on this site can read your request to act on it. Your contact details and the request text are deleted %d days after the request is closed. A record of what was done, such as when the request arrived and what was removed, is kept without your contact details. When a request says the person in the image was under 18, the request and the removed files are kept for at least 1 year.', 'takedown-desk' ), (int) tkdesk_setting( 'retention_days' ) );
		// Poster notices and review requests (poster.php).
		$text .= "\n\n" . __( 'If we remove content you posted, we may email you the reason and a link to ask for a review. We do not store your email address for this. If you ask for a review, we store what you write and the name you sign with, encrypted, and delete them together with the removal request.', 'takedown-desk' );
		if ( tkdesk_setting( 'dmca' ) ) {
			$text .= "\n\n" . __( 'When you send a copyright notice, this site stores the same kinds of details, plus your description of the copyrighted work, the link to the original if you give it, and your mailing address if you give it. They are encrypted, handled and deleted in the same way as a removal request.', 'takedown-desk' );
		}
		if ( 'recaptcha' === tkdesk_bot_mode() ) {
			$text .= PHP_EOL . PHP_EOL . __( 'The removal request form and the review form use Google reCAPTCHA to check that a person is sending them. Google reCAPTCHA collects information about your device and how you use the page and sends it to Google. Google\'s Privacy Policy (https://policies.google.com/privacy) and Terms of Service (https://policies.google.com/terms) apply.', 'takedown-desk' );
		}
		wp_add_privacy_policy_content( __( 'Takedown Desk', 'takedown-desk' ), wp_kses_post( wpautop( $text ) ) );
	}
);

/**
 * Requests sent from an email address, matched by its keyed hash (same normalization as intake).
 *
 * @param string $email Email address.
 * @return array[] Request rows.
 */
function tkdesk_privacy_requests( string $email ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email_hash = %s ORDER BY id ASC', tkdesk_table( 'requests' ), tkdesk_hash( strtolower( trim( $email ) ) ) ), ARRAY_A );
	return array_map( 'tkdesk_request_row', (array) $rows );
}

/**
 * Exporter: every request sent from the address, with the decrypted details the person gave.
 *
 * @param string $email Email address.
 * @return array data, done.
 */
function tkdesk_privacy_export( $email ): array {
	$items = array();
	foreach ( tkdesk_privacy_requests( (string) $email ) as $row ) {
		$text    = tkdesk_request_text( $row );
		$contact = tkdesk_request_contact( $row );
		$text    = is_wp_error( $text ) ? array() : $text;
		$contact = is_wp_error( $contact ) ? array() : $contact;
		$replies = array();
		foreach ( (array) ( $text['info_thread'] ?? array() ) as $entry ) {
			if ( 'requester' === ( $entry['from'] ?? '' ) ) {
				$replies[] = (string) $entry['text'];
			}
		}
		$fields = array(
			__( 'Request ID', 'takedown-desk' )            => $row['public_id'],
			__( 'Received', 'takedown-desk' )              => tkdesk_format_deadline( tkdesk_ts( $row['received_at'] ) ),
			__( 'Status', 'takedown-desk' )                => tkdesk_rest_request( $row )['status_label'],
			__( 'Link reported', 'takedown-desk' )         => $text['url'] ?? '',
			__( 'Details', 'takedown-desk' )               => $text['details'] ?? '',
			__( 'Statement', 'takedown-desk' )             => $text['statement'] ?? '',
			// Copyright notices only; empty fields are left out below.
			__( 'Copyrighted work', 'takedown-desk' )      => $text['work'] ?? '',
			__( 'Where the original is', 'takedown-desk' ) => $text['work_url'] ?? '',
			__( 'Details added later', 'takedown-desk' )   => implode( "\n\n", $replies ),
			__( 'Signature', 'takedown-desk' )             => $contact['signature'] ?? '',
			__( 'Email', 'takedown-desk' )                 => $contact['email'] ?? '',
			__( 'Phone', 'takedown-desk' )                 => $contact['phone'] ?? '',
			__( 'Mailing address', 'takedown-desk' )       => $contact['address'] ?? '',
		);
		$data   = array();
		foreach ( array_filter( $fields, 'strlen' ) as $name => $value ) {
			$data[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		$items[] = array(
			'group_id'    => 'takedown-desk',
			'group_label' => __( 'Removal requests', 'takedown-desk' ),
			'item_id'     => 'tkdesk-request-' . $row['id'],
			'data'        => $data,
		);
	}
	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Eraser: closed requests lose their personal data at once; open ones are kept until closed. Requests
 * flagged as involving a person under 18 are kept for the hold described in minors.php.
 *
 * @param string $email Email address.
 * @return array items_removed, items_retained, messages, done.
 */
function tkdesk_privacy_erase( $email ): array {
	$removed  = false;
	$messages = array();
	foreach ( tkdesk_privacy_requests( (string) $email ) as $row ) {
		if ( ! empty( $row['minor'] ) ) {
			$messages['minor'] = __( 'Kept for at least 1 year because the request involves a person under 18.', 'takedown-desk' );
		} elseif ( tkdesk_is_open( $row['status'] ) ) {
			$messages['open'] = __( 'Kept until the request is closed, then deleted.', 'takedown-desk' );
		} else {
			$removed = tkdesk_purge_request_data( $row['id'] ) || $removed;
		}
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => (bool) $messages,
		'messages'       => array_values( $messages ),
		'done'           => true,
	);
}
