<?php
/**
 * Uninstall (plan 5.9). Standalone: the plugin's own files are not loaded here.
 *
 * Setting delete_on_uninstall off (default): scheduled events, role, caps and the status card
 * dismissal go; data stays.
 * On: also items taken offline and comments held by a request are deleted for good, then the
 * quarantine folder, the four tables, every tkdesk_ option and transient, and plugin meta.
 * Except while a request is flagged as involving a person under 18 (the 1-year hold in
 * includes/minors.php): then all data stays, as if the setting were off.
 *
 * @package TakedownDesk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Uninstall the current site.
 */
function tkdesk_uninstall_site() {
	global $wpdb;
	foreach ( array( 'tkdesk_tick', 'tkdesk_daily', 'tkdesk_index_batch', 'tkdesk_reach_recheck' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}
	remove_role( 'tkdesk_moderator' );
	$admin = get_role( 'administrator' );
	foreach ( $admin ? array( 'tkdesk_manage_requests', 'tkdesk_export', 'tkdesk_manage_settings' ) : array() as $cap ) {
		$admin->remove_cap( $cap );
	}
	// A screen preference, not data: removed either way. Stored per site as a user option.
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'tkdesk_status_dismissed', '', true );

	$settings = get_option( 'tkdesk_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests, read once while uninstalling.
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE minor = 1', $wpdb->prefix . 'tkdesk_requests' ) ) ) {
		return;
	}

	// Our post status is not registered during uninstall, so WP_Query would ignore it: select it directly.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- core posts table, read once while uninstalling.
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_status = %s', $wpdb->posts, 'tkdesk_removed' ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- core commentmeta table, read once while uninstalling.
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT comment_id FROM %i WHERE meta_key = %s', $wpdb->commentmeta, '_tkdesk_removed' ) ) as $id ) {
		wp_delete_comment( (int) $id, true );
	}

	$name = (string) get_option( 'tkdesk_quarantine_dir', '' );
	$dir  = wp_get_upload_dir()['basedir'] . '/' . $name;
	if ( preg_match( '/^tkdesk-quarantine-[0-9a-f]{16}$/', $name ) && is_dir( $dir ) ) {
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( is_file( $dir . '/' . $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own folder, now empty; WP_Filesystem may need credentials.
		rmdir( $dir );
	}

	foreach ( array( 'requests', 'items', 'log', 'hashes' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table tkdesk_{name}, dropped on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tkdesk_' . $table ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- every tkdesk_ option and transient at once.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'tkdesk_' ) . '%', $wpdb->esc_like( '_transient_tkdesk_' ) . '%', $wpdb->esc_like( '_transient_timeout_tkdesk_' ) . '%' ) );
	foreach ( array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->commentmeta ) as $meta ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin meta keys tkdesk_ and _tkdesk_ only.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s', $meta, $wpdb->esc_like( 'tkdesk_' ) . '%', $wpdb->esc_like( '_tkdesk_' ) . '%' ) );
	}
	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $tkdesk_site ) {
		switch_to_blog( $tkdesk_site );
		tkdesk_uninstall_site();
		restore_current_blog();
	}
} else {
	tkdesk_uninstall_site();
}
