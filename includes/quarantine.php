<?php
/**
 * Quarantine: files taken offline move to a random, server-denied folder inside uploads.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

// Core reports any other attachment status as `publish`; keep ours so the attachment page is not public.
add_filter(
	'get_post_status',
	static function ( $status, $post ) {
		return isset( $post->post_status ) && 'tkdesk_removed' === $post->post_status ? 'tkdesk_removed' : $status;
	},
	10,
	2
);
// A quarantined attachment has no public URL.
add_filter(
	'wp_get_attachment_url',
	static function ( $url, $id ) {
		return 'tkdesk_removed' === get_post_status( (int) $id ) ? '' : $url;
	},
	99,
	2
);

/**
 * Absolute path of the quarantine folder, created on first use with deny rules for Apache and IIS.
 *
 * @return string|WP_Error
 */
function tkdesk_quarantine_dir() {
	$uploads = wp_get_upload_dir();
	$name    = (string) get_option( 'tkdesk_quarantine_dir', '' );
	if ( ! preg_match( '/^tkdesk-quarantine-[0-9a-f]{16}$/', $name ) ) {
		$name = 'tkdesk-quarantine-' . bin2hex( random_bytes( 8 ) );
		update_option( 'tkdesk_quarantine_dir', $name, false );
	}
	$dir   = $uploads['basedir'] . '/' . $name;
	$error = new WP_Error( 'tkdesk_quarantine_dir', __( 'The folder for files taken offline could not be created in the uploads folder.', 'takedown-desk' ), array( 'status' => 500 ) );
	if ( ! empty( $uploads['error'] ) || ! wp_mkdir_p( $dir ) ) {
		return $error;
	}
	$guards = array(
		'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted=\"false\" /></requestFiltering></security></system.webServer></configuration>\n",
		'index.php'  => "<?php\n// Silence is golden.\n",
	);
	foreach ( $guards as $file => $body ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny file in our own uploads folder; WP_Filesystem may need credentials during cron.
		if ( ! is_file( $dir . '/' . $file ) && false === file_put_contents( $dir . '/' . $file, $body ) ) {
			return $error;
		}
	}
	return $dir;
}

/**
 * Every file of an attachment, relative to the uploads folder: the attached file (the -scaled one
 * for big images), each size, `original_image` and the backups kept by the image editor.
 *
 * @param int $id Attachment ID.
 * @return string[]
 */
function tkdesk_attachment_files( int $id ): array {
	$file = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( '' === $file ) {
		return array();
	}
	$meta  = wp_get_attachment_metadata( $id );
	$meta  = is_array( $meta ) ? $meta : array();
	$names = array( wp_basename( $file ), $meta['original_image'] ?? '' );
	foreach ( array_merge( $meta['sizes'] ?? array(), (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ) as $size ) {
		$names[] = $size['file'] ?? '';
	}
	$dir   = dirname( $file );
	$dir   = '.' === $dir ? '' : $dir . '/';
	$files = array();
	foreach ( array_unique( array_filter( $names ) ) as $name ) {
		$files[] = $dir . $name;
	}
	return $files;
}

/**
 * Move every file of an attachment into quarantine under random names.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|WP_Error array( 'files' => array( array( 'from' => rel, 'to' => rel ) ) ), paths relative to uploads.
 */
function tkdesk_quarantine_attachment( int $attachment_id ) {
	$dir = tkdesk_quarantine_dir();
	if ( is_wp_error( $dir ) ) {
		return $dir;
	}
	$base  = (string) realpath( wp_get_upload_dir()['basedir'] );
	$moved = array( 'files' => array() );
	foreach ( tkdesk_attachment_files( $attachment_id ) as $rel ) {
		$from = realpath( $base . '/' . $rel );
		if ( ! $from || ! is_file( $from ) ) {
			continue; // A size that was never generated or was cleaned up.
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.bin';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- one atomic move inside uploads; WP_Filesystem may need credentials during cron.
		if ( '' === $base || 0 !== strpos( $from, $base . DIRECTORY_SEPARATOR ) || ! rename( $from, $dir . '/' . $name ) ) {
			tkdesk_quarantine_restore( $moved );
			return new WP_Error( 'tkdesk_quarantine_failed', __( 'A file could not be moved out of public view.', 'takedown-desk' ) );
		}
		$moved['files'][] = array(
			'from' => $rel,
			'to'   => basename( $dir ) . '/' . $name,
		);
	}
	if ( ! $moved['files'] ) {
		return new WP_Error( 'tkdesk_no_file', __( 'No file of this item was found on this server.', 'takedown-desk' ) );
	}
	do_action( 'tkdesk_file_quarantined', 'attachment:' . $attachment_id, $moved['files'] );
	return $moved;
}

/**
 * Move quarantined files back. Checks every file first, so it moves all of them or none.
 *
 * @param array $restore_data Holds `files` as returned by tkdesk_quarantine_attachment().
 * @return true|WP_Error
 */
function tkdesk_quarantine_restore( array $restore_data ) {
	$base  = (string) realpath( wp_get_upload_dir()['basedir'] );
	$files = (array) ( $restore_data['files'] ?? array() );
	$error = new WP_Error( 'tkdesk_restore_failed', __( 'A file could not be put back.', 'takedown-desk' ) );
	foreach ( $files as $file ) {
		$from = realpath( $base . '/' . $file['to'] );
		$into = realpath( dirname( $base . '/' . $file['from'] ) );
		if ( '' === $base || ! $from || ! $into || 0 !== strpos( $from, $base . DIRECTORY_SEPARATOR ) || 0 !== strpos( $into . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) || file_exists( $base . '/' . $file['from'] ) ) {
			return $error;
		}
	}
	$ok = true;
	foreach ( $files as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- one atomic move inside uploads; WP_Filesystem may need credentials during cron.
		$ok = rename( $base . '/' . $file['to'], $base . '/' . $file['from'] ) && $ok;
	}
	return $ok ? true : $error;
}

/**
 * Permanently delete quarantined files whose restore window passed. The item can no longer be restored.
 *
 * @return int Files deleted.
 */
function tkdesk_quarantine_purge_due(): int {
	global $wpdb;
	$table = tkdesk_table( 'items' );
	$base  = (string) realpath( wp_get_upload_dir()['basedir'] );
	$count = 0;
	// Files of a request flagged as involving a person under 18 are never purged automatically (minors.php).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own tables tkdesk_items and tkdesk_requests.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, request_id, restore_data FROM %i WHERE purge_after IS NOT NULL AND purge_after <= %s AND request_id NOT IN ( SELECT id FROM %i WHERE minor = 1 )', $table, tkdesk_mysql( tkdesk_now() ), tkdesk_table( 'requests' ) ), ARRAY_A );
	foreach ( $rows as $row ) {
		$restore = json_decode( (string) $row['restore_data'], true );
		$files   = array_merge( $restore['object']['files'] ?? array(), ...array_column( $restore['images'] ?? array(), 'files' ) );
		$gone    = 0;
		foreach ( $files as $file ) {
			$path = realpath( $base . '/' . $file['to'] );
			if ( '' !== $base && $path && 0 === strpos( $path, $base . DIRECTORY_SEPARATOR ) ) {
				wp_delete_file( $path );
				$gone += file_exists( $path ) ? 0 : 1;
			}
		}
		$set = array(
			'restore_data' => null,
			'purge_after'  => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$wpdb->update( $table, $set, array( 'id' => $row['id'] ) );
		if ( $gone ) {
			$detail = array(
				'item_id' => (int) $row['id'],
				'files'   => $gone,
			);
			tkdesk_log( (int) $row['request_id'], 'purged_file', $detail, 0 );
		}
		$count += $gone;
	}
	return $count;
}

/**
 * Whether the web server refuses requests into the quarantine folder (Nginx ignores .htaccess).
 * Probes this site's own uploads host only; cached 12 hours.
 *
 * @return bool|null True protected, false reachable (200), null unknown.
 */
function tkdesk_quarantine_protected(): ?bool {
	$cached = get_transient( 'tkdesk_quarantine_probe' );
	if ( false !== $cached ) {
		return 'unknown' === $cached ? null : 'protected' === $cached;
	}
	$result = null;
	$dir    = tkdesk_quarantine_dir();
	if ( ! is_wp_error( $dir ) ) {
		$url = wp_get_upload_dir()['baseurl'] . '/' . basename( $dir ) . '/probe.bin';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny probe file in our own uploads folder.
		$made = is_file( $dir . '/probe.bin' ) || false !== file_put_contents( $dir . '/probe.bin', 'tkdesk' );
		if ( $made && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$response = wp_remote_head( $url, array( 'timeout' => 5 ) );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $code && ( $code < 300 || $code >= 400 ) ) {
				$result = 200 !== $code;
			}
		}
	}
	set_transient( 'tkdesk_quarantine_probe', null === $result ? 'unknown' : ( $result ? 'protected' : 'open' ), 12 * HOUR_IN_SECONDS );
	return $result;
}

/**
 * Take selected images out of a post while the post stays up: each matching <img> loses its
 * sources, featured image and gallery IDs pointing at them are cleared.
 *
 * @param int   $post_id        Post ID.
 * @param array $attachment_ids Attachment IDs.
 * @return array|WP_Error Restore data: post_content (original), thumbnail_id (cleared one, or 0).
 */
function tkdesk_neutralize_images( int $post_id, array $attachment_ids ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'tkdesk_item_gone', __( 'This item no longer exists.', 'takedown-desk' ) );
	}
	$ids     = array_map( 'intval', $attachment_ids );
	$resolve = tkdesk_object_types()['attachment']['resolve_url'];
	$thumb   = (int) get_post_meta( $post_id, '_thumbnail_id', true );
	$thumb   = in_array( $thumb, $ids, true ) ? $thumb : 0;
	$tags    = new WP_HTML_Tag_Processor( $post->post_content );
	while ( $tags->next_tag( 'img' ) ) {
		preg_match_all( '/\bwp-image-([0-9]+)\b/', (string) $tags->get_attribute( 'class' ), $m );
		$src = $tags->get_attribute( 'src' );
		if ( ! array_intersect( $ids, array_map( 'intval', $m[1] ) ) && ! ( is_string( $src ) && in_array( (int) call_user_func( $resolve, $src ), $ids, true ) ) ) {
			continue;
		}
		foreach ( array_merge( array( 'src', 'srcset', 'sizes' ), (array) $tags->get_attribute_names_with_prefix( 'data-' ) ) as $name ) {
			$tags->remove_attribute( $name );
		}
		$tags->set_attribute( 'alt', __( 'Image removed', 'takedown-desk' ) );
		$tags->add_class( 'tkdesk-removed' );
	}
	$content = preg_replace_callback(
		'/(\[gallery\b[^\]]*?\bids=)(["\']?)([0-9,\s]*)\2/',
		static function ( array $m ) use ( $ids ): string {
			return $m[1] . $m[2] . implode( ',', array_diff( wp_parse_id_list( $m[3] ), $ids ) ) . $m[2];
		},
		$tags->get_updated_html()
	);
	$saved   = tkdesk_update_post( $post_id, array( 'post_content' => $content ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	if ( $thumb ) {
		delete_post_meta( $post_id, '_thumbnail_id' );
	}
	return array(
		'post_content' => $post->post_content,
		'thumbnail_id' => $thumb,
	);
}
