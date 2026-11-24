<?php
/**
 * Plugin Name:       Takedown Desk: Take It Down Act Removal Requests and DMCA Takedown Notices
 * Plugin URI:        https://swiftwebdev.com/takedown-desk/
 * Description:       Receive Take It Down Act removal requests, track the 48-hour deadline, remove the item and its copies, and keep a record.
 * Version:           1.1.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            SwiftWebDev
 * Author URI:        https://swiftwebdev.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       takedown-desk
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

define( 'TKDESK_VERSION', '1.1.1' );
define( 'TKDESK_DB_VERSION', 2 );
define( 'TKDESK_FILE', __FILE__ );
define( 'TKDESK_PATH', plugin_dir_path( __FILE__ ) );
define( 'TKDESK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Asset version: the plugin version plus the file's change time, so browsers load a file again whenever it changes.
 *
 * @param string $rel Path relative to the plugin folder.
 * @return string
 */
function tkdesk_asset_ver( string $rel ): string {
	$file = TKDESK_PATH . $rel;
	return file_exists( $file ) ? TKDESK_VERSION . '.' . filemtime( $file ) : TKDESK_VERSION;
}

// Load order matters: later files may call earlier ones at include time, never the reverse.
foreach ( array(
	'install',
	'settings',
	'crypto',
	'clock',
	'log',
	'objects',
	'resolve',
	'requests',
	'spam',
	'emails',
	'quarantine',
	'hashes',
	'cache',
	'frontend',
	'view-form',
	'shortcode',
	'rest',
	'export',
	'status',
	'privacy',
	'cron',
	'minors',
	'signals',
	'poster',
	'admin',
) as $tkdesk_file ) {
	$tkdesk_path = TKDESK_PATH . 'includes/' . $tkdesk_file . '.php';
	if ( is_readable( $tkdesk_path ) ) {
		require_once $tkdesk_path;
	}
}
unset( $tkdesk_file, $tkdesk_path );

register_activation_hook( __FILE__, 'tkdesk_activate' );
register_deactivation_hook( __FILE__, 'tkdesk_deactivate' );

add_action(
	'plugins_loaded',
	static function () {
		/**
		 * Fires when Takedown Desk has loaded. Add-ons boot here.
		 */
		do_action( 'tkdesk_loaded' );
	}
);
