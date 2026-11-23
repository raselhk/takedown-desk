<?php
/**
 * Settings: schema, defaults, sanitizing and saving.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings schema: key => array( 'type', 'default', optional 'enum', 'max', 'items', 'max_items' ).
 *
 * Types: int, bool, string, email, date (Y-m-d or ''), array (items: key, host or email), emails (tkdesk_sanitize_emails()).
 *
 * @return array
 */
function tkdesk_settings_schema(): array {
	/**
	 * Filters the settings schema. Add-ons add their own keys here.
	 *
	 * @param array $schema Settings schema.
	 */
	return apply_filters(
		'tkdesk_settings_schema',
		array(
			'notice_page_id'       => array(
				'type'    => 'int',
				'default' => 0,
			),
			'footer_link'          => array(
				'type'    => 'string',
				'default' => 'auto',
				'enum'    => array( 'auto', 'manual' ),
			),
			'footer_link_text'     => array(
				'type'    => 'string',
				'default' => __( 'Request removal of intimate images', 'takedown-desk' ),
				'max'     => 80,
			),
			'link_post_types'      => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => 'key',
			),
			'link_comments'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			'link_attachments'     => array(
				'type'    => 'bool',
				'default' => true,
			),
			'form_intro'           => array(
				'type'    => 'string',
				'default' => __( 'You do not need an account. Do not upload or attach the image.', 'takedown-desk' ),
				'max'     => 300,
			),
			'fallback_email'       => array(
				'type'    => 'email',
				'default' => '',
			),
			'bot_check'            => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Google reCAPTCHA instead of the built-in check, only while bot_check is off (spam.php). The secret
			// key is not a setting: spam.php keeps it encrypted in its own option and never sends it back.
			'recaptcha'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'recaptcha_version'    => array(
				'type'    => 'string',
				'default' => 'v3',
				'enum'    => array( 'v3', 'v2' ),
			),
			'recaptcha_site_key'   => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 100,
			),
			// v3 only: the lowest score (in tenths) that passes.
			'recaptcha_score'      => array(
				'type'    => 'int',
				'default' => 5,
				'enum'    => array( 3, 5, 7 ),
			),
			// Opt-in: the removal check also asks the Jetpack image CDN (i0-i3.wp.com).
			'jetpack_check'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			// "Leave this page" on the requester views (form, status, review).
			'quick_exit'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'allowed_hosts'        => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => 'host',
			),
			'extra_emails'         => array(
				'type'      => 'array',
				'default'   => array(),
				'items'     => 'email',
				'max_items' => 10,
			),
			// Hide reported items as soon as a request arrives (signals.php). Off: anyone can send a request.
			'auto_hide'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'auto_offline_overdue' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'removal_default'      => array(
				'type'    => 'string',
				'default' => 'offline',
				'enum'    => array( 'offline', 'delete' ),
			),
			'purge_removed_days'   => array(
				'type'    => 'int',
				'default' => 30,
				'enum'    => array( 7, 30, 90 ),
			),
			'removable_types'      => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => 'key',
			),
			'cdn_note'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Refuse uploads that are byte-identical to a removed file (hashes.php).
			'block_reuploads'      => array(
				'type'    => 'bool',
				'default' => true,
			),
			// Cloudflare purge after a removal (cache.php); the API token is kept encrypted in its own option.
			'cf_zone_id'           => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 32,
			),
			'retention_days'       => array(
				'type'    => 'int',
				'default' => 90,
				'enum'    => array( 30, 90, 180, 365, 730 ),
			),
			'log_retention_years'  => array(
				'type'    => 'int',
				'default' => 0,
				'enum'    => array( 0, 2, 5, 7 ),
			),
			'from_name'            => array(
				'type'    => 'string',
				'default' => '',
			),
			'reply_to'             => array(
				'type'    => 'email',
				'default' => '',
			),
			'email_footer'         => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 300,
			),
			// Outcome emails add how to contest the decision (DSA Art. 16, 17); also turns the poster notice on.
			'eu_visitors'          => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Email the person who posted removed content the reason and a review link (poster.php).
			'poster_notice'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Copyright (DMCA) notices: a second request type, off until the owner turns it on.
			'dmca'                 => array(
				'type'    => 'bool',
				'default' => false,
			),
			// Target time in business days (clock.php).
			'dmca_days'            => array(
				'type'    => 'int',
				'default' => 2,
				'enum'    => range( 1, 10 ),
			),
			'dmca_page_id'         => array(
				'type'    => 'int',
				'default' => 0,
			),
			'dmca_footer_link'     => array(
				'type'    => 'bool',
				'default' => true,
			),
			// When the designated agent was registered or last renewed with the US Copyright Office (status.php).
			'dmca_agent_date'      => array(
				'type'    => 'date',
				'default' => '',
			),
			// key => array( active, subject, body ); only emails changed from their defaults are stored.
			'emails'               => array(
				'type'    => 'emails',
				'default' => array(),
			),
			'delete_on_uninstall'  => array(
				'type'    => 'bool',
				'default' => false,
			),
		)
	);
}

/**
 * Default value of every setting.
 *
 * @return array
 */
function tkdesk_default_settings(): array {
	return wp_list_pluck( tkdesk_settings_schema(), 'default' );
}

/**
 * All settings: defaults merged with the saved option.
 *
 * @return array
 */
function tkdesk_settings(): array {
	$saved = get_option( 'tkdesk_settings', array() );
	return array_merge( tkdesk_default_settings(), is_array( $saved ) ? $saved : array() );
}

/**
 * One setting value, or null for an unknown key.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function tkdesk_setting( string $key ) {
	$settings = tkdesk_settings();
	return $settings[ $key ] ?? null;
}

/**
 * Sanitizes settings input. Unknown keys are dropped, enum values clamped to the nearest
 * allowed number (strings fall back to the default), invalid emails and hosts dropped,
 * strings cut to their max length.
 *
 * @param array $in Raw input.
 * @return array
 */
function tkdesk_sanitize_settings( array $in ): array {
	$schema = tkdesk_settings_schema();
	$out    = array();

	foreach ( $in as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}
		$def = $schema[ $key ];

		switch ( $def['type'] ) {
			case 'bool':
				$value = rest_sanitize_boolean( $value );
				break;
			case 'int':
				$value = max( 0, (int) $value );
				break;
			case 'email':
				$value = is_string( $value ) && is_email( trim( $value ) ) ? sanitize_email( $value ) : '';
				break;
			case 'emails':
				$value = tkdesk_sanitize_emails( $value );
				break;
			case 'date':
				$value = is_string( $value ) ? trim( $value ) : '';
				$date  = DateTime::createFromFormat( '!Y-m-d', $value );
				$value = $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
				break;
			case 'array':
				$list = array();
				foreach ( is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value ) as $item ) {
					$item = is_scalar( $item ) ? strtolower( trim( (string) $item ) ) : '';
					if ( 'email' === $def['items'] ) {
						$item = is_email( $item ) ? sanitize_email( $item ) : '';
					} elseif ( 'host' === $def['items'] ) {
						$item = strlen( $item ) <= 253 && preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $item ) ? $item : '';
					} else {
						$item = sanitize_key( $item );
					}
					if ( '' !== $item ) {
						$list[] = $item;
					}
				}
				$value = array_values( array_unique( $list ) );
				if ( isset( $def['max_items'] ) ) {
					$value = array_slice( $value, 0, $def['max_items'] );
				}
				break;
			default:
				$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				if ( isset( $def['max'] ) ) {
					$value = mb_substr( $value, 0, $def['max'] );
				}
		}

		if ( isset( $def['enum'] ) && ! in_array( $value, $def['enum'], true ) ) {
			$nearest = $def['default'];
			if ( is_int( $value ) ) {
				foreach ( $def['enum'] as $allowed ) {
					if ( abs( $allowed - $value ) < abs( $nearest - $value ) ) {
						$nearest = $allowed;
					}
				}
			}
			$value = $nearest;
		}

		$out[ $key ] = $value;
	}

	return $out;
}

/**
 * Merges input into the saved settings, sanitizes, saves and logs the changed keys.
 *
 * @param array $in Raw input (any subset of keys).
 * @return array The saved settings.
 */
function tkdesk_update_settings( array $in ): array {
	$old = tkdesk_settings();
	// emails updates per email and per field: { key: { active } } keeps that email's saved text.
	if ( isset( $in['emails'] ) && is_array( $in['emails'] ) ) {
		$merged = (array) $old['emails'];
		foreach ( $in['emails'] as $key => $email ) {
			$merged[ $key ] = array_merge( (array) ( $merged[ $key ] ?? array() ), is_array( $email ) ? $email : array() );
		}
		$in['emails'] = $merged;
	}
	$new     = tkdesk_sanitize_settings( array_merge( $old, $in ) );
	$changed = array();
	foreach ( $new as $key => $value ) {
		if ( ! array_key_exists( $key, $old ) || $old[ $key ] !== $value ) {
			$changed[] = $key;
		}
	}

	update_option( 'tkdesk_settings', $new, true );
	if ( $changed ) {
		tkdesk_log( 0, 'settings_changed', array( 'keys' => $changed ) );
	}

	return $new;
}
