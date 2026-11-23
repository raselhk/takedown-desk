<?php
/**
 * Page cache purging after a removal, through each caching plugin's public API when it is active.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- plan 3.7: these are the caching plugins' own purge hooks.
add_action(
	'tkdesk_purge_urls',
	static function ( array $urls, int $request_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE request_id = %d AND object_type = %s', tkdesk_table( 'items' ), $request_id, 'post' ) );
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( has_action( 'litespeed_purge_post' ) ) {
				do_action( 'litespeed_purge_post', $id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
				wpfc_clear_post_cache_by_id( $id );
			}
			if ( has_action( 'cache_enabler_clear_page_cache_by_post' ) ) {
				do_action( 'cache_enabler_clear_page_cache_by_post', $id );
			}
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}
		foreach ( $urls as $url ) {
			if ( has_action( 'litespeed_purge_url' ) ) {
				do_action( 'litespeed_purge_url', $url );
			}
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url );
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache( $url );
			}
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}
		if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' );
		}
	},
	10,
	2
);
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
add_action( 'tkdesk_purge_urls', 'tkdesk_cf_purge_urls', 10, 2 );
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'takedown-desk/v1',
			'/admin/cloudflare/test',
			array(
				'methods'             => 'POST',
				'callback'            => 'tkdesk_cf_test',
				'permission_callback' => static function () {
					return tkdesk_can( 'tkdesk_manage_settings' );
				},
			)
		);
	}
);

/**
 * The saved Cloudflare API token, decrypted; '' when none is saved or it cannot be read.
 *
 * @return string
 */
function tkdesk_cf_token(): string {
	$stored = (string) get_option( 'tkdesk_cf_token', '' );
	$token  = '' === $stored ? '' : tkdesk_decrypt( $stored );
	return is_string( $token ) ? $token : '';
}

/**
 * Save the Cloudflare API token encrypted ('' removes it). It is never sent back to the browser.
 *
 * @param string $token Token.
 */
function tkdesk_cf_save_token( string $token ): void {
	if ( tkdesk_cf_token() === $token ) {
		return;
	}
	if ( '' === $token ) {
		delete_option( 'tkdesk_cf_token' );
	} else {
		update_option( 'tkdesk_cf_token', tkdesk_encrypt( $token ), false );
	}
	tkdesk_log( 0, 'settings_changed', array( 'keys' => array( 'cf_token' ) ) );
}

/**
 * Ask Cloudflare to drop links from its cache (purge_cache by files, 30 links per call).
 *
 * @param string[]    $urls  Links.
 * @param string|null $zone  Zone ID; null uses the saved one.
 * @param string|null $token API token; null uses the saved one.
 * @return true|WP_Error
 */
function tkdesk_cf_purge( array $urls, ?string $zone = null, ?string $token = null ) {
	$zone  = null === $zone ? (string) tkdesk_setting( 'cf_zone_id' ) : $zone;
	$token = null === $token ? tkdesk_cf_token() : $token;
	if ( ! preg_match( '/^[a-f0-9]{32}$/i', $zone ) || ! preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $token ) ) {
		return new WP_Error( 'tkdesk_cf_setup', __( 'Add your Cloudflare zone ID and API token first.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	foreach ( array_chunk( array_values( $urls ), 30 ) as $chunk ) {
		$res  = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . strtolower( $zone ) . '/purge_cache',
			array(
				'timeout' => 8,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'files' => $chunk ) ),
			)
		);
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$said = is_array( $body ) ? (string) ( $body['errors'][0]['message'] ?? '' ) : '';
		if ( is_wp_error( $res ) || ( empty( $body['success'] ) && '' === $said ) ) {
			return new WP_Error( 'tkdesk_cf_unreachable', __( 'Cloudflare could not be reached.', 'takedown-desk' ), array( 'status' => 400 ) );
		}
		if ( empty( $body['success'] ) ) {
			/* translators: %s: error message from Cloudflare. */
			return new WP_Error( 'tkdesk_cf_failed', sprintf( __( 'Cloudflare answered: %s', 'takedown-desk' ), $said ), array( 'status' => 400 ) );
		}
	}
	return true;
}

/**
 * Listener on tkdesk_purge_urls: purge the removed links from Cloudflare when it is set up, and log the
 * result. A failure never stops the removal; the removal check shows it and retries it.
 *
 * @param string[] $urls       Links.
 * @param int      $request_id Request ID.
 * @return array|null Logged detail plus `event`; null when Cloudflare is not set up or there is nothing to purge.
 */
function tkdesk_cf_purge_urls( array $urls, int $request_id ): ?array {
	$urls = array_values( array_unique( array_filter( $urls ) ) );
	if ( ! $urls || '' === (string) tkdesk_setting( 'cf_zone_id' ) || ! get_option( 'tkdesk_cf_token' ) ) {
		return null;
	}
	// After a network failure the next items of the same removal fail at once instead of waiting again.
	$done = wp_cache_get( 'cf_down', 'tkdesk' ) ? new WP_Error( 'tkdesk_cf_unreachable', __( 'Cloudflare could not be reached.', 'takedown-desk' ) ) : tkdesk_cf_purge( $urls );
	if ( is_wp_error( $done ) && 'tkdesk_cf_unreachable' === $done->get_error_code() ) {
		wp_cache_set( 'cf_down', 1, 'tkdesk', MINUTE_IN_SECONDS );
	}
	$event  = is_wp_error( $done ) ? 'cloudflare_failed' : 'cloudflare_purged';
	$detail = array(
		'links' => count( $urls ),
		'urls'  => $urls,
	) + ( is_wp_error( $done ) ? array( 'error' => $done->get_error_message() ) : array() );
	tkdesk_log( $request_id, $event, $detail );
	return $detail + array( 'event' => $event );
}

/**
 * POST /admin/cloudflare/test { zone_id?, token? }: one purge of a test link on this site, with the given
 * values or else the saved ones, so it proves the token may purge this zone.
 *
 * @param WP_REST_Request $request Request.
 * @return array|WP_Error { ok: true }
 */
function tkdesk_cf_test( WP_REST_Request $request ) {
	$zone  = trim( (string) $request->get_param( 'zone_id' ) );
	$token = trim( (string) $request->get_param( 'token' ) );
	$done  = tkdesk_cf_purge( array( home_url( '/?tkdesk-cloudflare-test=1' ) ), '' === $zone ? null : $zone, '' === $token ? null : $token );
	return is_wp_error( $done ) ? $done : array( 'ok' => true );
}

/**
 * Name of the first active caching plugin we can purge, or ''.
 *
 * @return string
 */
function tkdesk_caching_plugin(): string {
	$plugins = array(
		'WP Rocket'            => function_exists( 'rocket_clean_files' ),
		'LiteSpeed Cache'      => (bool) has_action( 'litespeed_purge_url' ),
		'W3 Total Cache'       => function_exists( 'w3tc_flush_url' ),
		'WP Super Cache'       => function_exists( 'wp_cache_post_change' ),
		'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
		'WP Fastest Cache'     => function_exists( 'wpfc_clear_post_cache_by_id' ),
		'Cache Enabler'        => (bool) has_action( 'cache_enabler_clear_page_cache_by_post' ),
		'Breeze'               => (bool) has_action( 'breeze_clear_all_cache' ),
		'Nginx Helper'         => (bool) has_action( 'rt_nginx_helper_purge_all' ),
	);
	return (string) array_search( true, $plugins, true );
}

/**
 * Removal proof: asks every link of the request's removed and hidden items whether it still answers,
 * plus the Jetpack image CDN copies when that CDN is on, and records the result in the log. Unless it
 * runs as that recheck, it books one more check an hour later.
 *
 * @param int  $request_id Request ID.
 * @param bool $scheduled  Running as the hourly recheck.
 * @return array|null Logged detail plus `event`; null when nothing of the request is offline.
 */
function tkdesk_reach_check( int $request_id, bool $scheduled = false ): ?array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE request_id = %d AND action IN (%s, %s, %s, %s)', tkdesk_table( 'items' ), $request_id, 'offline', 'image_removed', 'deleted', 'hidden' ) ) );
	$sets = array();
	$cf   = null; // The latest Cloudflare purge.
	$redo = array(); // Links whose latest Cloudflare purge failed.
	// The links were written down when each item went offline: a deleted item can no longer list its files.
	foreach ( tkdesk_log_for_request( $request_id ) as $row ) {
		$detail = json_decode( (string) $row['detail'], true );
		if ( isset( $detail['check'], $detail['item_id'] ) && in_array( (int) $detail['item_id'], $ids, true ) ) {
			$sets[ (int) $detail['item_id'] ] = (array) $detail['check'];
		}
		if ( in_array( $row['event'], array( 'cloudflare_purged', 'cloudflare_failed' ), true ) ) {
			$cf   = (array) $detail + array( 'event' => $row['event'] );
			$redo = 'cloudflare_failed' === $row['event'] ? array_merge( $redo, (array) ( $detail['urls'] ?? array() ) ) : array_diff( $redo, (array) ( $detail['urls'] ?? array() ) );
		}
	}
	$urls = array_values( array_unique( array_merge( array(), ...array_values( $sets ) ) ) );
	if ( ! $urls ) {
		return null;
	}
	// A failed Cloudflare purge is tried again first, so Check again also finishes it after a settings fix.
	$cf = ( $redo ? tkdesk_cf_purge_urls( $redo, $request_id ) : null ) ?? $cf;

	// Off by default: asking Jetpack's CDN sends the removed image links to an outside service.
	$jetpack = tkdesk_setting( 'jetpack_check' ) && class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && Jetpack::is_module_active( 'photon' );
	if ( $jetpack ) {
		$urls = array_merge( $urls, tkdesk_photon_urls( $urls ) );
	}
	$out  = array(
		'links'      => count( $urls ),
		'gone'       => 0,
		'live'       => array(),
		'unknown'    => array(),
		'cloudflare' => false,
		'jetpack'    => $jetpack,
		'cf_purge'   => $cf ? array(
			'ok'    => 'cloudflare_purged' === $cf['event'],
			'error' => (string) ( $cf['error'] ?? '' ),
		) : null,
	);
	$stop = microtime( true ) + 20; // Keeps the moderator's request well inside PHP's time limit.
	foreach ( $urls as $url ) {
		$probe             = microtime( true ) < $stop ? tkdesk_reach_probe( $url ) : array(
			'state'      => 'unknown',
			'cloudflare' => false,
		);
		$out['cloudflare'] = $out['cloudflare'] || $probe['cloudflare'];
		if ( 'gone' === $probe['state'] ) {
			++$out['gone'];
		} else {
			$out[ $probe['state'] ][] = $url;
		}
	}
	if ( $out['live'] ) {
		$event = 'still_reachable';
	} else {
		$event = $out['gone'] ? 'verified_unreachable' : 'reach_unchecked';
	}
	tkdesk_log( $request_id, $event, $out );
	if ( ! $scheduled && ! wp_next_scheduled( 'tkdesk_reach_recheck', array( $request_id ) ) ) {
		wp_schedule_single_event( tkdesk_now() + HOUR_IN_SECONDS, 'tkdesk_reach_recheck', array( $request_id ) );
	}
	return $out + array( 'event' => $event );
}

/**
 * Ask one link whether it still serves the content: HEAD, then GET when HEAD is refused. Redirects are
 * followed only when they change nothing but the scheme, `www.` or a trailing slash; any other redirect
 * means the link no longer serves the item. A `#comment-N` link is live only while the page still
 * carries that comment.
 *
 * @param string $url Link.
 * @return array { state: gone|live|unknown, cloudflare: bool }
 */
function tkdesk_reach_probe( string $url ): array {
	$frag = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
	$url  = (string) preg_replace( '/#.*$/s', '', $url );
	$key  = static function ( string $u ): string {
		$p = (array) wp_parse_url( $u );
		return preg_replace( '/^www\./', '', strtolower( (string) ( $p['host'] ?? '' ) ) ) . untrailingslashit( (string) ( $p['path'] ?? '' ) ) . '?' . ( $p['query'] ?? '' );
	};
	$args = array(
		'timeout'             => 5,
		'redirection'         => 0,
		'limit_response_size' => 1048576,
	);
	$cf   = false;
	for ( $hop = 0; $hop < 3; $hop++ ) {
		// Our own links may sit behind a local certificate, as in WordPress's own loopback requests.
		$args['sslverify'] = tkdesk_url_on_site( $url ) ? (bool) apply_filters( 'https_local_ssl_verify', false ) : true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$res               = '' === $frag ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
		$code              = (int) wp_remote_retrieve_response_code( $res );
		if ( '' === $frag && in_array( $code, array( 405, 501 ), true ) ) {
			$res  = wp_remote_get( $url, $args );
			$code = (int) wp_remote_retrieve_response_code( $res );
		}
		$cf   = $cf || '' !== wp_remote_retrieve_header( $res, 'cf-cache-status' ) || '' !== wp_remote_retrieve_header( $res, 'cf-ray' );
		$next = (string) wp_remote_retrieve_header( $res, 'location' );
		$next = $code >= 300 && $code < 400 && '' !== $next ? WP_Http::make_absolute_url( $next, $url ) : '';
		if ( '' === $next || $key( $next ) !== $key( $url ) ) {
			break;
		}
		$url = $next;
	}
	if ( $code >= 200 && $code < 300 ) {
		$state = '' !== $frag && ! preg_match( '/\bid=["\']' . preg_quote( $frag, '/' ) . '["\']/', wp_remote_retrieve_body( $res ) ) ? 'gone' : 'live';
	} else {
		$state = $code >= 300 && $code < 500 && 429 !== $code ? 'gone' : 'unknown';
	}
	return array(
		'state'      => $state,
		'cloudflare' => $cf,
	);
}

/**
 * Jetpack image CDN copies (i0.wp.com to i3.wp.com) of full-size image files from the uploads folder.
 * Jetpack builds its resized copies from the full-size file, so sized files (-300x200) are left out.
 *
 * @param string[] $urls Links.
 * @return string[]
 */
function tkdesk_photon_urls( array $urls ): array {
	$base = preg_replace( '#^https?:#i', '', wp_get_upload_dir()['baseurl'] ) . '/';
	$out  = array();
	foreach ( $urls as $url ) {
		$p    = (array) wp_parse_url( $url );
		$path = (string) ( $p['path'] ?? '' );
		if ( 0 !== stripos( preg_replace( '#^https?:#i', '', $url ), $base ) || ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $path ) || preg_match( '/-\d+x\d+\.\w+$/', $path ) ) {
			continue;
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$out[] = 'https://i' . $i . '.wp.com/' . $p['host'] . $path . ( 'https' === ( $p['scheme'] ?? '' ) ? '?ssl=1' : '' );
		}
	}
	return $out;
}
