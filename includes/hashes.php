<?php
/**
 * SHA-256 media index and the exact-copy finder.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_attachment', 'tkdesk_hash_attachment' );
add_action( 'tkdesk_index_batch', 'tkdesk_index_batch' );
// Every core upload path: media library, block editor (REST media), media_handle_upload() and sideloads.
add_filter( 'wp_handle_upload_prefilter', 'tkdesk_block_reupload' );
add_filter( 'wp_handle_sideload_prefilter', 'tkdesk_block_reupload' );
add_filter(
	'wp_update_attachment_metadata',
	static function ( $data, $id ) {
		tkdesk_hash_attachment( (int) $id );
		return $data;
	},
	10,
	2
);
add_action(
	'delete_attachment',
	static function ( $id ) {
		global $wpdb;
		// Blocked rows outlive the file: they are how a removed file is recognized later.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_hashes.
		$wpdb->delete(
			tkdesk_table( 'hashes' ),
			array(
				'source_key' => 'attachment:' . (int) $id,
				'blocked'    => 0,
			)
		);
	}
);
// Starts the backfill; tkdesk_index_batch() then reschedules itself until every file is hashed.
add_action(
	'init',
	static function () {
		// The raw option, not tkdesk_index_status(): no attachment count on every page load.
		if ( empty( get_option( 'tkdesk_index', array() )['finished_at'] ) && ! wp_next_scheduled( 'tkdesk_index_batch' ) ) {
			wp_schedule_single_event( tkdesk_now(), 'tkdesk_index_batch' );
		}
	}
);

/**
 * Hash an attachment's original file (and any source the filter adds) into the index.
 *
 * @param int $id Attachment ID.
 * @return string|null SHA-256 of `attachment:{id}`, null when there is no local file.
 */
function tkdesk_hash_attachment( int $id ): ?string {
	global $wpdb;
	$base = wp_normalize_path( wp_get_upload_dir()['basedir'] ) . '/';
	$hash = null;
	/**
	 * Files to index for an attachment, as media entries (source_key, path, url, attachment_id).
	 *
	 * @param array $sources Media entries.
	 * @param int   $id      Attachment ID.
	 */
	foreach ( (array) apply_filters( 'tkdesk_hash_sources', tkdesk_attachment_media( $id ), $id ) as $source ) {
		$sha = is_readable( $source['path'] ) ? hash_file( 'sha256', $source['path'] ) : false;
		if ( ! $sha ) {
			continue;
		}
		$path = substr( str_replace( $base, '', wp_normalize_path( $source['path'] ) ), 0, 255 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_hashes.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (source_key, attachment_id, path, sha256, bytes, indexed_at) VALUES (%s, %d, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE attachment_id = VALUES(attachment_id), path = VALUES(path), sha256 = VALUES(sha256), bytes = VALUES(bytes), indexed_at = VALUES(indexed_at)', tkdesk_table( 'hashes' ), $source['source_key'], (int) $source['attachment_id'], $path, $sha, (int) filesize( $source['path'] ), tkdesk_mysql( tkdesk_now() ) ) );
		$hash = 'attachment:' . $id === $source['source_key'] ? $sha : $hash;
	}
	return $hash;
}

/**
 * Refuse an upload that is byte-identical to a removed file (a blocked hash row) and log the attempt
 * against that request. The uploader only reads a neutral message: nothing points to the request.
 * The file is hashed only when its size matches a blocked row, so a normal upload costs one indexed query.
 *
 * @param array $file Upload: name, type, tmp_name, size, error.
 * @return array The file, with `error` set when it is refused.
 */
function tkdesk_block_reupload( $file ) {
	global $wpdb;
	if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) || ! tkdesk_setting( 'block_reuploads' ) ) {
		return $file;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_hashes.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT sha256, request_id FROM %i WHERE blocked = 1 AND bytes = %d', tkdesk_table( 'hashes' ), (int) filesize( $file['tmp_name'] ) ), ARRAY_A );
	$hit  = $rows ? array_column( $rows, 'request_id', 'sha256' )[ (string) hash_file( 'sha256', $file['tmp_name'] ) ] ?? null : null;
	if ( null !== $hit ) {
		tkdesk_log( (int) $hit, 'upload_blocked' );
		$file['error'] = __( 'This file cannot be uploaded to this site.', 'takedown-desk' );
	}
	return $file;
}

/**
 * Block an attachment's hash for a request (hashing it first while the file still exists), or
 * release the block that request set.
 *
 * @param int  $attachment_id Attachment ID.
 * @param int  $request_id    Request ID.
 * @param bool $block         False releases the block.
 */
function tkdesk_block_hash( int $attachment_id, int $request_id, bool $block = true ): void {
	global $wpdb;
	$where = array( 'source_key' => 'attachment:' . $attachment_id );
	if ( $block ) {
		tkdesk_hash_attachment( $attachment_id );
	} else {
		$where['request_id'] = $request_id;
	}
	$set = array(
		'blocked'    => (int) $block,
		'request_id' => $block ? $request_id : 0,
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_hashes.
	$wpdb->update( tkdesk_table( 'hashes' ), $set, $where );
}

/**
 * Backfill progress. Once the backfill finished, uploads are hashed as they arrive, so total and
 * done are the live attachment count rather than the count saved when the backfill ended.
 *
 * @return array total, done, last_id, finished_at (unix, 0 while running).
 */
function tkdesk_index_status(): array {
	$state = wp_parse_args(
		(array) get_option( 'tkdesk_index', array() ),
		array(
			'total'       => 0,
			'done'        => 0,
			'last_id'     => 0,
			'finished_at' => 0,
		)
	);
	if ( $state['finished_at'] ) {
		$state['total'] = (int) wp_count_posts( 'attachment' )->inherit;
		$state['done']  = $state['total'];
	}
	return $state;
}

/**
 * Hash the next 200 attachments (or 15 seconds' worth); reschedules itself in 60 s until done.
 *
 * @return array Status as tkdesk_index_status().
 */
function tkdesk_index_batch(): array {
	global $wpdb;
	$state = tkdesk_index_status();
	if ( $state['finished_at'] ) {
		return $state;
	}
	$batch = 200; // Plan 3.8: 200 files or 15 seconds per run, IDs only.
	$after = static function ( string $where ) use ( $wpdb, $state ): string {
		return $where . $wpdb->prepare( ' AND %i.ID > %d', $wpdb->posts, $state['last_id'] );
	};
	add_filter( 'posts_where', $after );
	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => $batch,
		)
	);
	remove_filter( 'posts_where', $after );
	$state['total'] = $state['done'] + (int) $query->found_posts;
	$stop           = tkdesk_now() + 15;
	foreach ( $query->posts as $id ) {
		if ( tkdesk_now() >= $stop ) {
			break;
		}
		tkdesk_hash_attachment( (int) $id );
		$state['last_id'] = (int) $id;
		++$state['done'];
	}
	if ( $state['done'] >= $state['total'] ) {
		$state['finished_at'] = tkdesk_now();
	} elseif ( ! wp_next_scheduled( 'tkdesk_index_batch' ) ) {
		wp_schedule_single_event( tkdesk_now() + 60, 'tkdesk_index_batch' );
	}
	update_option( 'tkdesk_index', $state );
	return $state;
}

/**
 * Attachment IDs used by a post: <img> sources, wp-image-N classes, featured image, galleries.
 *
 * @param int $post_id Post ID.
 * @return int[]
 */
function tkdesk_post_images( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$resolve = tkdesk_object_types()['attachment']['resolve_url'];
	preg_match_all( '/\bwp-image-([0-9]+)\b/', $post->post_content, $m );
	$ids  = array_merge( array( (int) get_post_thumbnail_id( $post ) ), $m[1] );
	$tags = new WP_HTML_Tag_Processor( $post->post_content );
	while ( $tags->next_tag( 'img' ) ) {
		$src   = $tags->get_attribute( 'src' );
		$ids[] = is_string( $src ) && '' !== $src ? (int) call_user_func( $resolve, $src ) : 0;
	}
	foreach ( get_post_galleries( $post, false ) as $gallery ) {
		$ids = array_merge( $ids, wp_parse_id_list( $gallery['ids'] ?? '' ) );
	}
	$ids = array_filter(
		array_unique( array_map( 'intval', $ids ) ),
		static function ( int $id ): bool {
			return 'attachment' === get_post_type( $id );
		}
	);
	return array_values( $ids );
}

/**
 * Plan 3.8: for the reported item's files (each image when it is a post), store exact copies
 * (same SHA-256) and references (posts and comments that show the file) as item rows.
 *
 * @param int $request_id Request ID.
 * @return array|WP_Error Every item row of the request.
 */
function tkdesk_find_copies( int $request_id ) {
	global $wpdb;
	$request = tkdesk_get_request( $request_id );
	$items   = tkdesk_table( 'items' );
	if ( ! $request ) {
		return new WP_Error( 'tkdesk_not_found', __( 'This request does not exist.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$reported = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d AND role = %s', $items, $request_id, 'reported' ), ARRAY_A );
	$types    = tkdesk_object_types();
	if ( ! $reported || empty( $types[ $reported['object_type'] ]['media'] ) ) {
		return new WP_Error( 'tkdesk_item_not_found', __( 'Match the request to an item on this site first.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	$post_types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	$found      = array();
	foreach ( call_user_func( $types[ $reported['object_type'] ]['media'], (int) $reported['object_id'] ) as $file ) {
		$sha = hash_file( 'sha256', $file['path'] );
		if ( ! $sha ) {
			continue;
		}
		$aids = array( (int) $file['attachment_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_hashes.
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE sha256 = %s AND source_key <> %s AND attachment_id > 0', tkdesk_table( 'hashes' ), $sha, $file['source_key'] ) ) as $copy ) {
			$found[ 'attachment:' . $copy ] = array( 'exact_copy', 'attachment', (int) $copy, $sha );
			$aids[]                         = (int) $copy;
		}
		// "Also appears in" for the file and for each exact copy's file.
		foreach ( array_filter( $aids ) as $aid ) {
			// The uploads-relative name without -scaled or extension; the pattern accepts the original,
			// -scaled and every -WxH size of it, and never a neighbor such as name-10.png.
			$stem = preg_replace( '/(-scaled)?\.\w+$/', '', (string) get_post_meta( $aid, '_wp_attached_file', true ) );
			if ( '' === $stem ) {
				continue;
			}
			$shows = '#/' . preg_quote( $stem, '#' ) . '(-scaled)?(-[0-9]+x[0-9]+)?\.[a-z0-9]+#i';
			$like  = '%' . $wpdb->esc_like( $stem ) . '%';
			// Not a search ('s'): searches drop exclude_from_search types, which include bbPress topics and replies.
			$where = static function ( string $where ) use ( $wpdb, $like ): string {
				return $where . $wpdb->prepare( ' AND %i.post_content LIKE %s', $wpdb->posts, $like );
			};
			add_filter( 'posts_where', $where );
			// ponytail: first 100 matches per file of each kind; page through them if sites need more.
			$posts = new WP_Query(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'no_found_rows'  => true,
				)
			);
			remove_filter( 'posts_where', $where );
			foreach ( $posts->posts as $post ) {
				if ( preg_match( $shows, $post->post_content ) ) {
					$found[ 'post:' . $post->ID ] = array( 'reference', 'post', (int) $post->ID, $sha );
				}
			}
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- plan 3.8: posts that use the file as featured image; runs only when a moderator looks for copies.
			$thumbs = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'meta_key'       => '_thumbnail_id',
					'meta_value'     => $aid,
				)
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			foreach ( $thumbs as $post_id ) {
				$found[ 'post:' . $post_id ] = array( 'reference', 'post', (int) $post_id, $sha );
			}
			$comments = get_comments(
				array(
					'search' => $stem,
					'status' => 'all',
					'number' => 100,
				)
			);
			foreach ( $comments as $comment ) {
				if ( preg_match( $shows, $comment->comment_content ) ) {
					$found[ 'comment:' . $comment->comment_ID ] = array( 'reference', 'comment', (int) $comment->comment_ID, $sha );
				}
			}
		}
	}
	unset( $found[ $reported['object_type'] . ':' . $reported['object_id'] ] );
	$rows = array();
	foreach ( $found as $row ) {
		$rows[] = array_combine( array( 'role', 'object_type', 'object_id', 'file_sha256' ), $row );
	}
	/**
	 * Copies found for a request, before they are stored. Pro appends near copies.
	 *
	 * @param array $rows    Rows: role, object_type, object_id, file_sha256.
	 * @param array $request Request row.
	 */
	$rows   = (array) apply_filters( 'tkdesk_find_copies', $rows, $request );
	$counts = array(
		'exact'      => 0,
		'references' => 0,
	);
	$added  = 0;
	foreach ( $rows as $row ) {
		$desc = tkdesk_describe( $row['object_type'], (int) $row['object_id'] );
		// An attachment taken offline earlier is not a live copy.
		if ( ! $desc || ( 'attachment' === $row['object_type'] && 'tkdesk_removed' === get_post_status( (int) $row['object_id'] ) ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (request_id, role, object_type, object_id, label, url, file_sha256) VALUES (%d, %s, %s, %d, %s, %s, %s)', $items, $request_id, $row['role'], $row['object_type'], (int) $row['object_id'], $desc['label'], $desc['url'], (string) ( $row['file_sha256'] ?? '' ) ) );
		$added                += (int) $wpdb->rows_affected;
		$counts['exact']      += 'exact_copy' === $row['role'] ? 1 : 0;
		$counts['references'] += 'reference' === $row['role'] ? 1 : 0;
	}
	// The finder reruns on every visit; record a search only when it found new items or its result differs from the
	// last one recorded. The lock keeps two first visits at the same moment from both recording it.
	$lock = 'tkdesk_copies_' . $request_id;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock per request.
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_log.
	$last = $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM %i WHERE request_id = %d AND event = %s ORDER BY id DESC LIMIT 1', tkdesk_table( 'log' ), $request_id, 'copies_found' ) );
	if ( $added > 0 || null === $last || json_decode( (string) $last, true ) !== $counts ) {
		tkdesk_log( $request_id, 'copies_found', $counts );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock per request.
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id', $items, $request_id ), ARRAY_A );
}
