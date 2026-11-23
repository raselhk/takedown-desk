<?php
/**
 * Admin shell: menu, the app mount point and its assets, admin-bar count.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'tkdesk_admin_menu' );
add_action( 'admin_enqueue_scripts', 'tkdesk_admin_assets' );
add_action( 'admin_enqueue_scripts', 'tkdesk_admin_menu_css' );
add_action( 'admin_bar_menu', 'tkdesk_admin_bar', 80 );

/**
 * Top-level menu with a count bubble of open requests.
 */
function tkdesk_admin_menu() {
	$open  = tkdesk_request_counts()['open'];
	$title = __( 'Takedown Desk', 'takedown-desk' );
	$menu  = $open ? sprintf( '%1$s <span class="awaiting-mod count-%2$d"><span class="pending-count">%2$d</span></span>', esc_html( $title ), $open ) : esc_html( $title );
	add_menu_page( $title, $menu, 'tkdesk_manage_requests', 'takedown-desk', 'tkdesk_admin_page', tkdesk_menu_icon(), 26 );
}

/**
 * Keeps the open-request count on one line with the menu icon and name on every admin screen: "Takedown Desk"
 * plus the count bubble is about as wide as the menu, so the default end padding made the count wrap.
 */
function tkdesk_admin_menu_css() {
	wp_register_style( 'tkdesk-menu', false, array(), TKDESK_VERSION );
	wp_enqueue_style( 'tkdesk-menu' );
	wp_add_inline_style( 'tkdesk-menu', '#adminmenu #toplevel_page_takedown-desk .wp-menu-name{padding-inline-end:0;white-space:nowrap}' );
}

/**
 * Menu icon: the one-color Takedown Desk mark (small-size cut). WordPress recolors it to match the admin color scheme.
 *
 * @return string SVG data URI.
 */
function tkdesk_menu_icon(): string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path fill="black" d="M60 28H117V88H60A30 30 0 0 1 60 28ZM139 28H196A30 30 0 0 1 196 88H139ZM82 110H117V223L40 146H82ZM139 110H174V146H216L139 223Z"/></svg>';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the readable SVG above, encoded for the data URI WordPress expects.
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * The page: only the app mount point.
 */
function tkdesk_admin_page() {
	echo '<div id="tkdesk-app" class="tkdesk tkd-app"><noscript>' . esc_html__( 'Takedown Desk needs JavaScript in the admin area.', 'takedown-desk' ) . '</noscript></div>';
}

/**
 * Admin app assets, on the plugin screen only.
 *
 * @param string $hook Screen hook suffix.
 */
function tkdesk_admin_assets( $hook ) {
	if ( 'toplevel_page_takedown-desk' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'tkdesk-tokens' ); // Registered on init (shortcode.php), shared with the public views.
	wp_enqueue_style( 'tkdesk-admin', TKDESK_URL . 'assets/css/admin.css', array( 'tkdesk-tokens' ), tkdesk_asset_ver( 'assets/css/admin.css' ) );
	wp_enqueue_script( 'tkdesk-admin', TKDESK_URL . 'assets/js/admin.js', array( 'wp-i18n', 'wp-api-fetch', 'wp-hooks', 'wp-a11y' ), tkdesk_asset_ver( 'assets/js/admin.js' ), true );
	wp_set_script_translations( 'tkdesk-admin', 'takedown-desk' );
	$data = array(
		'restNamespace' => 'takedown-desk/v1',
		'exportUrl'     => tkdesk_export_url(),
		'noticeUrl'     => tkdesk_notice_url(),
		'noticeText'    => tkdesk_notice_paragraphs(),
		'usersUrl'      => admin_url( 'users.php?role=tkdesk_moderator' ),
		'version'       => TKDESK_VERSION,
		'canManage'     => tkdesk_can( 'tkdesk_manage_settings' ),
		'now'           => tkdesk_now(),
		'tz'            => wp_timezone_string(),
		'tzAbbr'        => wp_date( 'T', tkdesk_now() ),
		'setupDone'     => (bool) get_option( 'tkdesk_setup_done' ),
		'siteUrl'       => home_url( '/' ),
		'userId'        => get_current_user_id(),
		// Where "I will place it myself" sends people: the footer in the Site Editor, or Widgets.
		'isBlockTheme'  => wp_is_block_theme(),
		'footerEditUrl' => tkdesk_footer_edit_url(),
	);
	wp_add_inline_script( 'tkdesk-admin', 'window.tkdeskAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
}

/**
 * Where to place the notice link by hand: the footer template part in the Site Editor (block themes; the
 * patterns screen when the theme has no footer part), else Appearance > Widgets.
 *
 * @return string
 */
function tkdesk_footer_edit_url(): string {
	if ( ! wp_is_block_theme() ) {
		return admin_url( 'widgets.php' );
	}
	$id = get_stylesheet() . '//footer';
	return get_block_template( $id, 'wp_template_part' ) ? admin_url( 'site-editor.php?postType=wp_template_part&postId=' . rawurlencode( $id ) . '&canvas=edit' ) : admin_url( 'site-editor.php?path=/patterns' );
}

/**
 * Admin-bar item "Takedown: {n} open", red when any request is overdue.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 */
function tkdesk_admin_bar( $bar ) {
	if ( ! tkdesk_can( 'tkdesk_manage_requests' ) ) {
		return;
	}
	$counts = tkdesk_request_counts();
	if ( ! $counts['open'] ) {
		return;
	}
	/* translators: %d: number of open requests. */
	$label = esc_html( sprintf( __( 'Takedown: %d open', 'takedown-desk' ), $counts['open'] ) );
	$bar->add_node(
		array(
			'id'    => 'tkdesk',
			'title' => $counts['overdue'] ? '<span style="background:#d63638;color:#fff;border-radius:2px;padding:2px 6px">' . $label . '</span>' : $label,
			'href'  => admin_url( 'admin.php?page=takedown-desk' ),
		)
	);
}

/**
 * Nonce URL of the CSV export (the handler arrives with the export feature).
 *
 * @return string
 */
function tkdesk_export_url(): string {
	// Not wp_nonce_url(): its &amp; would end up literally in the href the admin app builds.
	return add_query_arg( '_wpnonce', wp_create_nonce( 'tkdesk_export' ), admin_url( 'admin-post.php?action=tkdesk_export' ) );
}
