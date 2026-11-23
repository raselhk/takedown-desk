<?php
/**
 * Tables, roles, capabilities and the removed post status.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tkdesk_maybe_upgrade', 5 );

add_action(
	'init',
	static function () {
		register_post_status(
			'tkdesk_removed',
			array(
				'label'                     => _x( 'Removed by request', 'post status', 'takedown-desk' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of items. */
				'label_count'               => _n_noop( 'Removed by request <span class="count">(%s)</span>', 'Removed by request <span class="count">(%s)</span>', 'takedown-desk' ),
			)
		);
	}
);

/**
 * Full name of one of the plugin tables.
 *
 * @param string $name One of requests, items, log, hashes.
 * @return string
 */
function tkdesk_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'tkdesk_' . $name;
}

/**
 * Activation: tables, caps and scheduled events. Never creates pages.
 */
function tkdesk_activate(): void {
	tkdesk_install_tables();
	tkdesk_add_caps();
	update_option( 'tkdesk_db_version', TKDESK_DB_VERSION );
	if ( function_exists( 'tkdesk_schedule_events' ) ) {
		tkdesk_schedule_events();
	}
}

/**
 * Deactivation: clears the plugin's scheduled events.
 */
function tkdesk_deactivate(): void {
	foreach ( array( 'tkdesk_tick', 'tkdesk_daily', 'tkdesk_index_batch', 'tkdesk_reach_recheck' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
}

/**
 * Installs on sites where the stored schema version is older (covers multisite lazy install).
 */
function tkdesk_maybe_upgrade(): void {
	if ( (int) get_option( 'tkdesk_db_version', 0 ) < TKDESK_DB_VERSION ) {
		tkdesk_activate();
	}
}

/**
 * Creates or updates the four tables with dbDelta.
 */
function tkdesk_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset  = $wpdb->get_charset_collate();
	$requests = tkdesk_table( 'requests' );
	$items    = tkdesk_table( 'items' );
	$log      = tkdesk_table( 'log' );
	$hashes   = tkdesk_table( 'hashes' );

	dbDelta(
		array(
			"CREATE TABLE {$requests} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id VARCHAR(20) NOT NULL,
			type VARCHAR(16) NOT NULL DEFAULT 'ncii',
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			decline_reason VARCHAR(32) NULL,
			source VARCHAR(16) NOT NULL DEFAULT 'form',
			received_at DATETIME NOT NULL,
			due_at DATETIME NOT NULL,
			first_opened_at DATETIME NULL,
			resolved_at DATETIME NULL,
			requester_notified_at DATETIME NULL,
			reminder_24_sent_at DATETIME NULL,
			reminder_40_sent_at DATETIME NULL,
			overdue_sent_at DATETIME NULL,
			object_type VARCHAR(32) NULL,
			object_id BIGINT(20) UNSIGNED NULL,
			item_label VARCHAR(191) NOT NULL DEFAULT '',
			url_on_site TINYINT(1) NOT NULL DEFAULT 1,
			relationship VARCHAR(16) NOT NULL DEFAULT 'self',
			good_faith TINYINT(1) NOT NULL DEFAULT 0,
			signed_at DATETIME NULL,
			contact_enc TEXT NULL,
			text_enc MEDIUMTEXT NULL,
			email_hash CHAR(64) NULL,
			ip_hash CHAR(64) NULL,
			token_hash CHAR(64) NULL,
			submitter_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			locale VARCHAR(10) NOT NULL DEFAULT '',
			assigned_to BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			decision_note TEXT NULL,
			is_test TINYINT(1) NOT NULL DEFAULT 0,
			purged_at DATETIME NULL,
			minor TINYINT(1) NOT NULL DEFAULT 0,
			cybertip_report VARCHAR(64) NULL,
			cybertip_on DATE NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY received_at (received_at),
			KEY email_hash (email_hash),
			KEY status_due (status,due_at),
			KEY object (object_type,object_id)
			) {$charset};",
			"CREATE TABLE {$items} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(16) NOT NULL,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT(20) UNSIGNED NOT NULL,
			label VARCHAR(191) NOT NULL DEFAULT '',
			url TEXT NULL,
			file_sha256 CHAR(64) NULL,
			match_distance TINYINT(3) UNSIGNED NULL,
			action VARCHAR(20) NOT NULL DEFAULT 'none',
			action_at DATETIME NULL,
			action_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			restore_data LONGTEXT NULL,
			purge_after DATETIME NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY object (object_type,object_id),
			KEY file_sha256 (file_sha256),
			KEY purge_after (purge_after),
			UNIQUE KEY one_per_request (request_id,object_type,object_id)
			) {$charset};",
			"CREATE TABLE {$log} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			actor_id BIGINT(20) NOT NULL DEFAULT 0,
			actor_label VARCHAR(60) NOT NULL DEFAULT '',
			event VARCHAR(40) NOT NULL,
			detail TEXT NULL,
			prev_hash CHAR(64) NOT NULL DEFAULT '',
			row_hash CHAR(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY created_at (created_at),
			KEY event (event)
			) {$charset};",
			"CREATE TABLE {$hashes} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source_key VARCHAR(191) NOT NULL,
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			path VARCHAR(255) NOT NULL DEFAULT '',
			sha256 CHAR(64) NOT NULL,
			bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			blocked TINYINT(1) NOT NULL DEFAULT 0,
			request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			indexed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_key (source_key),
			KEY attachment_id (attachment_id),
			KEY sha256 (sha256),
			KEY blocked (blocked)
			) {$charset};",
		)
	);
}

/**
 * Adds the moderator role and gives administrators all three caps.
 */
function tkdesk_add_caps(): void {
	add_role(
		'tkdesk_moderator',
		__( 'Takedown moderator', 'takedown-desk' ),
		array(
			'read'                   => true,
			'tkdesk_manage_requests' => true,
			'tkdesk_export'          => true,
		)
	);
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array( 'tkdesk_manage_requests', 'tkdesk_export', 'tkdesk_manage_settings' ) as $cap ) {
			$admin->add_cap( $cap );
		}
	}
}

/**
 * Removes the moderator role and the administrator caps.
 */
function tkdesk_remove_caps(): void {
	remove_role( 'tkdesk_moderator' );
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( array( 'tkdesk_manage_requests', 'tkdesk_export', 'tkdesk_manage_settings' ) as $cap ) {
			$admin->remove_cap( $cap );
		}
	}
}

/**
 * Whether the current user holds a capability.
 *
 * @param string $cap Capability name.
 * @return bool
 */
function tkdesk_can( string $cap ): bool {
	return current_user_can( $cap );
}

/**
 * IDs of the users who can act on requests.
 *
 * @return int[]
 */
function tkdesk_moderator_ids(): array {
	return array_map(
		'intval',
		get_users(
			array(
				'capability' => 'tkdesk_manage_requests',
				'fields'     => 'ID',
			)
		)
	);
}
