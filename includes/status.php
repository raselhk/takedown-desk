<?php
/**
 * Health of the request process for the status card: is it live, and what exactly to fix.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

// A test request is the monthly drill: the prompt for it comes back 30 days after the last one.
add_action(
	'tkdesk_request_received',
	static function ( $id, $request ) {
		if ( ! empty( $request['is_test'] ) ) {
			update_option( 'tkdesk_drill_at', tkdesk_now(), false );
		}
	},
	10,
	2
);

/**
 * Status of the request process (plan 5.5, GET /admin/status).
 *
 * @return array live, problems, notice_page, footer_link_seen, mail_ok, last_tick, index, key_source,
 *               quarantine_protected, moderators, caching_plugin, setup_done, test_requests,
 *               dismissed (the current user hid the all-clear card on this site).
 */
function tkdesk_status(): array {
	global $wpdb;
	$settings = admin_url( 'admin.php?page=takedown-desk#settings' );
	$problems = array();
	$add      = static function ( string $code, string $severity, string $message, string $fix_label, string $fix_url, string $fix_action = '' ) use ( &$problems ) {
		$problems[] = array(
			'code'       => $code,
			'severity'   => $severity,
			'message'    => $message,
			'fix_label'  => $fix_label,
			'fix_url'    => $fix_url,
			'fix_action' => $fix_action,
		);
	};

	$page_id = (int) tkdesk_setting( 'notice_page_id' );
	$page    = $page_id ? get_post( $page_id ) : null;
	$page    = $page && 'page' === $page->post_type ? $page : null;
	$notice  = array(
		'id'       => $page ? $page->ID : 0,
		'status'   => $page ? $page->post_status : '',
		'url'      => $page ? (string) get_permalink( $page ) : '',
		'has_form' => $page && ( has_block( 'takedown-desk/request-form', $page ) || has_shortcode( $page->post_content, 'takedown_desk_form' ) ),
	);
	if ( ! $page || 'trash' === $page->post_status ) {
		$add( 'tkdesk_no_notice_page', 'error', __( 'There is no notice page, so people have no place to send a removal request.', 'takedown-desk' ), __( 'Create the notice page', 'takedown-desk' ), $settings, 'create_notice_page' );
	} else {
		$edit = (string) get_edit_post_link( $page->ID, 'raw' );
		if ( 'publish' !== $page->post_status ) {
			$add( 'tkdesk_notice_draft', 'error', __( 'Your notice page is not published yet.', 'takedown-desk' ), __( 'Publish page', 'takedown-desk' ), $edit, 'publish_notice_page' );
		}
		if ( ! $notice['has_form'] ) {
			$add( 'tkdesk_no_form', 'error', __( 'Your notice page has no request form.', 'takedown-desk' ), __( 'Add the form', 'takedown-desk' ), $edit, 'add_form' );
		}
	}

	// Test requests count as open here: the tick sends their reminders too.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$counts    = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT SUM( is_test = 1 ) AS tests, SUM( status IN (%s, %s, %s) ) AS open_n FROM %i', 'new', 'in_review', 'needs_info', tkdesk_table( 'requests' ) ), ARRAY_A );
	$last_tick = (int) get_option( 'tkdesk_last_tick', 0 );
	if ( (int) ( $counts['open_n'] ?? 0 ) > 0 && tkdesk_now() - $last_tick > HOUR_IN_SECONDS ) {
		$add( 'tkdesk_cron_stalled', 'warning', __( 'Scheduled tasks are not running on time. Reminders may be late. Ask your host to run WP-Cron from a real cron job.', 'takedown-desk' ), __( 'Run WP-Cron from a real cron job', 'takedown-desk' ), 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/' );
	}

	$moderators = count( tkdesk_moderator_ids() );
	if ( $moderators < 2 ) {
		$add( 'tkdesk_single_moderator', 'warning', __( 'Only one person can act on requests. Add a second moderator so weekends and holidays are covered.', 'takedown-desk' ), __( 'Add a moderator', 'takedown-desk' ), admin_url( 'user-new.php' ) );
	}
	if ( count( tkdesk_moderator_emails() ) < 2 ) {
		$add( 'tkdesk_one_inbox', 'warning', __( 'All reminders go to one inbox. Add a backup recipient so no request is missed while that person is away.', 'takedown-desk' ), __( 'Add a backup recipient', 'takedown-desk' ), $settings );
	}
	if ( tkdesk_now() - (int) get_option( 'tkdesk_drill_at', 0 ) >= 30 * DAY_IN_SECONDS ) {
		$add( 'tkdesk_drill_due', 'warning', __( 'Send a test request once a month to make sure alerts and reminders still reach your team.', 'takedown-desk' ), __( 'Send a test request', 'takedown-desk' ), '', 'test_request' );
	}

	$key_source = tkdesk_key_source();
	if ( 'option' === $key_source ) {
		$add( 'tkdesk_weak_key', 'warning', __( 'Your encryption key is stored in the same database as the data it protects. Add a TKDESK_KEY line to wp-config.php.', 'takedown-desk' ), __( 'Show the wp-config.php line', 'takedown-desk' ), $settings );
	}
	if ( tkdesk_key_changed() ) {
		// Logged once per new key: tkdesk_key_logged holds a check text encrypted with the key last logged.
		if ( tkdesk_key_check_fails( 'tkdesk_key_logged' ) ) {
			tkdesk_log( 0, 'key_changed', array(), 0 );
			update_option( 'tkdesk_key_logged', tkdesk_encrypt( 'tkdesk-key-check' ), false );
		}
		$unreadable = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status IN (%s, %s, %s) AND contact_enc IS NOT NULL', tkdesk_table( 'requests' ), 'new', 'in_review', 'needs_info' ), ARRAY_A ) as $row ) {
			$unreadable += is_wp_error( tkdesk_request_contact( $row ) ) ? 1 : 0;
		}
		/* translators: %d: number of open requests. */
		$message = sprintf( _n( 'This site\'s encryption key changed. Contact details of %d open request cannot be read. Put the old key back, or set TKDESK_KEY_OLD to the previous value so they can be re-saved.', 'This site\'s encryption key changed. Contact details of %d open requests cannot be read. Put the old key back, or set TKDESK_KEY_OLD to the previous value so they can be re-saved.', $unreadable, 'takedown-desk' ), $unreadable );
		$add( 'tkdesk_key_changed', 'error', $message, __( 'Open the data settings', 'takedown-desk' ), $settings );
	}

	// spam.php lets a form through when reCAPTCHA cannot check it; the site owner should know.
	$captcha = 'recaptcha' === tkdesk_bot_mode() ? (string) get_option( 'tkdesk_recaptcha_problem', '' ) : '';
	if ( '' !== $captcha ) {
		$add(
			'tkdesk_recaptcha_problem',
			'warning',
			'secret' === $captcha
				? __( 'Google reCAPTCHA does not accept your secret key, so forms are not checked for bots. Requests still arrive. Check the keys in the spam protection settings.', 'takedown-desk' )
				: __( 'Google reCAPTCHA could not be reached when a form was sent, so it went through unchecked. Requests still arrive. If this keeps happening, check that your server can reach google.com.', 'takedown-desk' ),
			__( 'Open the spam protection settings', 'takedown-desk' ),
			$settings
		);
	}

	// Copyright notices (setting dmca): the form page and the designated agent registration, which runs 3 years.
	if ( tkdesk_setting( 'dmca' ) ) {
		$dmca      = tkdesk_dmca_setup();
		$directory = 'https://www.copyright.gov/dmca-directory/';
		if ( 'publish' !== $dmca['page']['status'] || ! $dmca['page']['has_form'] ) {
			$add( 'tkdesk_dmca_no_page', 'warning', __( 'Copyright notices are on, but no published page holds the copyright notice form.', 'takedown-desk' ), __( 'Create the copyright page', 'takedown-desk' ), $settings, 'create_copyright_page' );
		}
		if ( ! $dmca['agent_renew_by'] ) {
			$add( 'tkdesk_dmca_no_agent', 'warning', __( 'Copyright notices are on, but no designated agent date is saved. Register an agent with the US Copyright Office, then enter the date in the copyright settings.', 'takedown-desk' ), __( 'Open the Copyright Office agent directory', 'takedown-desk' ), $directory );
		} elseif ( tkdesk_now() >= $dmca['agent_renew_by'] - 60 * DAY_IN_SECONDS ) {
			/* translators: %s: date. */
			$message = tkdesk_now() >= $dmca['agent_renew_by'] ? __( 'Your designated agent registration with the US Copyright Office ran out on %s. Renew it, then update the date in the copyright settings.', 'takedown-desk' ) : __( 'Your designated agent registration with the US Copyright Office runs out on %s. Renew it, then update the date in the copyright settings.', 'takedown-desk' );
			$add( 'tkdesk_dmca_agent_renewal', 'warning', sprintf( $message, wp_date( get_option( 'date_format' ), $dmca['agent_renew_by'] ) ), __( 'Open the Copyright Office agent directory', 'takedown-desk' ), $directory );
		}
	}

	$protected = tkdesk_quarantine_protected();
	if ( false === $protected ) {
		$add( 'tkdesk_quarantine_open', 'warning', __( 'Files taken offline are hidden by a random folder name only. Add this rule to your server or switch the default to Delete permanently.', 'takedown-desk' ), __( 'Open the removal settings', 'takedown-desk' ), $settings );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
	$last_mail = $wpdb->get_var( $wpdb->prepare( 'SELECT event FROM %i WHERE event IN (%s, %s) ORDER BY id DESC LIMIT 1', tkdesk_table( 'log' ), 'email_sent', 'email_failed' ) );
	$mail_ok   = null === $last_mail ? null : 'email_sent' === $last_mail;
	if ( false === $mail_ok ) {
		$add( 'tkdesk_mail_failed', 'error', __( 'The last email from Takedown Desk could not be sent, so requesters and moderators may not hear about requests. Check how this site sends email, then send a test email.', 'takedown-desk' ), __( 'Open the email settings', 'takedown-desk' ), $settings );
	}

	$status = array(
		'live'                 => true,
		'problems'             => $problems,
		'notice_page'          => $notice,
		'footer_link_seen'     => tkdesk_footer_link_seen(),
		'mail_ok'              => $mail_ok,
		'last_tick'            => $last_tick,
		'index'                => tkdesk_rest_index(),
		'key_source'           => $key_source,
		'quarantine_protected' => $protected,
		'moderators'           => $moderators,
		'caching_plugin'       => tkdesk_caching_plugin(),
		'setup_done'           => (bool) get_option( 'tkdesk_setup_done' ),
		'test_requests'        => (int) ( $counts['tests'] ?? 0 ),
		'dismissed'            => (bool) get_user_option( 'tkdesk_status_dismissed' ),
	);
	/**
	 * Filters the status before `live` is worked out, so added problems count.
	 *
	 * @param array $status Status.
	 */
	$status         = apply_filters( 'tkdesk_status', $status );
	$status['live'] = ! in_array( 'error', array_column( (array) $status['problems'], 'severity' ), true );
	return $status;
}

/**
 * Copyright notice setup (the `dmca` block of GET /admin/settings): the page of setting dmca_page_id and when
 * the designated agent registration runs out, 3 years after the saved dmca_agent_date.
 *
 * @return array { page: { id, status, url, edit_url, has_form }, agent_renew_by (unix, 0 without a date) }
 */
function tkdesk_dmca_setup(): array {
	$id    = (int) tkdesk_setting( 'dmca_page_id' );
	$page  = $id ? get_post( $id ) : null;
	$page  = $page && 'page' === $page->post_type ? $page : null;
	$date  = (string) tkdesk_setting( 'dmca_agent_date' );
	$renew = '' !== $date ? date_create_immutable( $date . ' 00:00:00', wp_timezone() ) : false;
	return array(
		'page'           => array(
			'id'       => $page ? $page->ID : 0,
			'status'   => $page ? $page->post_status : '',
			'url'      => $page ? (string) get_permalink( $page ) : '',
			'edit_url' => $page ? (string) get_edit_post_link( $page->ID, 'raw' ) : '',
			'has_form' => $page && ( has_block( 'takedown-desk/copyright-form', $page ) || has_shortcode( $page->post_content, 'takedown_desk_copyright_form' ) ),
		),
		'agent_renew_by' => $renew ? $renew->modify( '+3 years' )->getTimestamp() : 0,
	);
}

/**
 * POST /admin/status/dismiss: hide the all-clear status card for the current user on this site.
 * The inbox still shows the card whenever there is a problem. With `what: drill` it instead puts off
 * the test request prompt for 30 days, for everyone on this site.
 *
 * @param WP_REST_Request|null $request Request.
 * @return array array( 'dismissed' => true ).
 */
function tkdesk_status_dismiss( $request = null ): array {
	if ( $request instanceof WP_REST_Request && 'drill' === $request->get_param( 'what' ) ) {
		update_option( 'tkdesk_drill_at', tkdesk_now(), false );
	} else {
		update_user_option( get_current_user_id(), 'tkdesk_status_dismissed', 1 );
	}
	return array( 'dismissed' => true );
}

/**
 * Whether the home page links to the notice page: a same-host loopback GET, cached one hour per
 * notice URL and footer setting.
 *
 * @return bool|null Null when the loopback fails.
 */
function tkdesk_footer_link_seen(): ?bool {
	$url    = tkdesk_notice_url();
	$key    = 'tkdesk_footer_' . substr( md5( $url . '|' . tkdesk_setting( 'footer_link' ) ), 0, 12 );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return 'unknown' === $cached ? null : 'yes' === $cached;
	}
	$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 5 ) );
	$seen     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : '' !== $url && false !== strpos( wp_remote_retrieve_body( $response ), $url );
	set_transient( $key, null === $seen ? 'unknown' : ( $seen ? 'yes' : 'no' ), HOUR_IN_SECONDS );
	return $seen;
}
