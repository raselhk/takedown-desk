<?php
/**
 * Object registry and removal engine: the kinds of site content a request can point at, and how
 * each one is taken offline, deleted and put back.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

// Approve guard: wp_set_comment_status() has no filter before it writes, so hold the comment again at once.
add_action(
	'wp_set_comment_status',
	static function ( $id, $status ) {
		if ( in_array( (string) $status, array( 'approve', '1' ), true ) && get_comment_meta( (int) $id, '_tkdesk_removed', true ) ) {
			wp_set_comment_status( (int) $id, 'hold' );
		}
	},
	0,
	2
);
add_filter(
	'wp_update_comment_data',
	static function ( $data, $comment ) {
		if ( 1 === (int) ( $data['comment_approved'] ?? 0 ) && get_comment_meta( (int) $comment['comment_ID'], '_tkdesk_removed', true ) ) {
			$data['comment_approved'] = 0;
		}
		return $data;
	},
	10,
	2
);
// Republish guard: content taken offline stays offline until the desk puts it back (tkdesk_update_post()
// writes its own status last, at PHP_INT_MAX). Trash is allowed; untrash returns it to the removed status.
add_filter( 'wp_insert_post_data', 'tkdesk_keep_removed', 10, 2 );
add_filter( 'wp_insert_attachment_data', 'tkdesk_keep_removed', 10, 2 );
add_filter(
	'wp_untrash_post_status',
	static function ( $status, $id, $previous ) {
		return 'tkdesk_removed' === $previous ? 'tkdesk_removed' : $status;
	},
	10,
	3
);

/**
 * Keeps `tkdesk_removed` on a save from outside the desk. Administrators may still change a post's
 * status by hand; an attachment always keeps it, because core resets attachments to `inherit` on any save.
 *
 * @param array $data    Post data about to be saved.
 * @param array $postarr Post array passed to wp_insert_post().
 * @return array
 */
function tkdesk_keep_removed( $data, $postarr ) {
	$id = (int) ( $postarr['ID'] ?? 0 );
	if ( $id && 'trash' !== $data['post_status'] && 'tkdesk_removed' === get_post_field( 'post_status', $id, 'raw' ) && ( 'attachment' === $data['post_type'] || ! tkdesk_can( 'tkdesk_manage_settings' ) ) ) {
		$data['post_status'] = 'tkdesk_removed';
	}
	return $data;
}

/**
 * Registered object types, keyed by registry key.
 *
 * Each entry: `label`, `active` (callable => bool), `resolve_url` (callable( string $url ): ?int),
 * `describe` (callable( int $id ): ?array), `media` (callable( int $id ): array of `source_key`,
 * `path`, `url`, `attachment_id`), `take_offline` (callable( int $id, array $ctx ): restore data or
 * WP_Error), `restore` (callable( int $id, array $restore_data ): true or WP_Error), `delete`
 * (callable( int $id ): true or WP_Error) and `purge_urls` (callable( int $id ): string[]). Order
 * matters: tkdesk_resolve_url() asks each type in turn, so file URLs reach `attachment` before
 * `post` runs url_to_postid().
 *
 * @return array
 */
function tkdesk_object_types(): array {
	$types = array(
		'comment'    => array(
			'label'        => __( 'Comment', 'takedown-desk' ),
			'active'       => '__return_true',
			'resolve_url'  => function ( string $url ): ?int {
				$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
				return preg_match( '/^comment-([0-9]+)$/', $fragment, $m ) && get_comment( (int) $m[1] ) ? (int) $m[1] : null;
			},
			'describe'     => function ( int $id ): ?array {
				$comment = get_comment( $id );
				if ( ! $comment ) {
					return null;
				}
				return array(
					/* translators: %s: display name of the comment author. */
					'label'     => sprintf( __( 'Comment by %s', 'takedown-desk' ), get_comment_author( $comment ) ),
					'url'       => get_comment_link( $comment ),
					'author_id' => (int) $comment->user_id,
					'excerpt'   => wp_trim_words( $comment->comment_content, 30 ),
					'edit_url'  => admin_url( 'comment.php?action=editcomment&c=' . $comment->comment_ID ),
				);
			},
			'media'        => '__return_empty_array',
			// Not trash: trash empties itself. Held plus the meta, which the approve guard above enforces.
			'take_offline' => static function ( int $id, array $ctx ) {
				$previous = (string) get_comment( $id )->comment_approved;
				update_comment_meta( $id, '_tkdesk_removed', (int) $ctx['request_id'] );
				if ( '0' !== $previous && ! wp_set_comment_status( $id, 'hold' ) ) {
					delete_comment_meta( $id, '_tkdesk_removed' );
					return new WP_Error( 'tkdesk_item_failed', __( 'The comment could not be hidden.', 'takedown-desk' ) );
				}
				return array( 'status' => $previous );
			},
			'restore'      => static function ( int $id, array $data ) {
				delete_comment_meta( $id, '_tkdesk_removed' );
				// The author was told about this comment when it was first approved.
				add_filter( 'notify_post_author', '__return_false' );
				$done = (string) get_comment( $id )->comment_approved === $data['status'] || wp_set_comment_status( $id, $data['status'] );
				remove_filter( 'notify_post_author', '__return_false' );
				return $done ? true : new WP_Error( 'tkdesk_restore_failed', __( 'The comment could not be put back.', 'takedown-desk' ) );
			},
			'delete'       => static function ( int $id ) {
				return wp_delete_comment( $id, true ) ? true : new WP_Error( 'tkdesk_item_failed', __( 'The comment could not be deleted.', 'takedown-desk' ) );
			},
			'purge_urls'   => static function ( int $id ): array {
				$comment = get_comment( $id );
				return $comment ? array( get_comment_link( $comment ), (string) get_permalink( (int) $comment->comment_post_ID ) ) : array();
			},
		),
		'attachment' => array(
			'label'        => __( 'Attachment', 'takedown-desk' ),
			'active'       => '__return_true',
			'resolve_url'  => function ( string $url ): ?int {
				$file = preg_replace( '/[?#].*$/s', '', $url );
				$base = preg_replace( '#^https?:#i', '', wp_get_upload_dir()['baseurl'] ) . '/';
				if ( 0 !== stripos( preg_replace( '#^https?:#i', '', $file ), $base ) ) {
					$id = url_to_postid( $url );
					return $id && 'attachment' === get_post_type( $id ) ? $id : null;
				}
				// Sized copies (-300x200) and the original of a -scaled upload all map back to one attachment.
				$full = preg_replace( '/-[0-9]+x[0-9]+(?=\.\w+$)/', '', $file );
				foreach ( array_unique( array( $file, $full, preg_replace( '/(?=\.\w+$)/', '-scaled', $full ) ) ) as $try ) {
					$id = attachment_url_to_postid( $try );
					if ( $id ) {
						return $id;
					}
				}
				return null;
			},
			'describe'     => function ( int $id ): ?array {
				$obj = get_post( $id );
				if ( ! $obj || 'attachment' !== $obj->post_type ) {
					return null;
				}
				return array(
					'label'     => '' !== $obj->post_title ? $obj->post_title : wp_basename( (string) get_attached_file( $id ) ),
					'url'       => (string) wp_get_attachment_url( $id ),
					'author_id' => (int) $obj->post_author,
					'excerpt'   => wp_trim_words( $obj->post_excerpt, 30 ),
					'edit_url'  => (string) get_edit_post_link( $obj, 'raw' ),
				);
			},
			'media'        => 'tkdesk_attachment_media',
			'take_offline' => static function ( int $id ) {
				if ( ! tkdesk_attachment_media( $id ) ) {
					return new WP_Error( 'tkdesk_offloaded', __( 'This file is stored outside this server, so it can only be deleted permanently.', 'takedown-desk' ) );
				}
				$previous = get_post_field( 'post_status', $id, 'raw' );
				$moved    = tkdesk_quarantine_attachment( $id );
				if ( is_wp_error( $moved ) ) {
					return $moved;
				}
				$saved = tkdesk_update_post( $id, array( 'post_status' => 'tkdesk_removed' ) );
				if ( is_wp_error( $saved ) ) {
					tkdesk_quarantine_restore( $moved );
					return $saved;
				}
				return array(
					'status' => $previous,
					'files'  => $moved['files'],
				);
			},
			'restore'      => static function ( int $id, array $data ) {
				if ( 'tkdesk_removed' !== get_post_status( $id ) ) {
					return true;
				}
				$back = tkdesk_quarantine_restore( $data );
				return is_wp_error( $back ) ? $back : tkdesk_update_post( $id, array( 'post_status' => $data['status'] ) );
			},
			'delete'       => static function ( int $id ) {
				$base  = wp_get_upload_dir()['basedir'] . '/';
				$files = tkdesk_attachment_files( $id );
				if ( ! wp_delete_attachment( $id, true ) ) {
					return new WP_Error( 'tkdesk_item_failed', __( 'The file could not be deleted.', 'takedown-desk' ) );
				}
				// Core skips sizes and original_image when path_is_absolute() rejects a drive path (D:/...).
				foreach ( $files as $rel ) {
					wp_delete_file_from_directory( $base . $rel, $base );
				}
				return true;
			},
			'purge_urls'   => static function ( int $id ): array {
				$base = wp_get_upload_dir()['baseurl'] . '/';
				// A permanently deleted attachment has no page link any more (get_attachment_link() would warn).
				$urls = get_post( $id ) ? array( (string) get_attachment_link( $id ) ) : array();
				foreach ( tkdesk_attachment_files( $id ) as $rel ) {
					$urls[] = $base . $rel;
				}
				return $urls;
			},
		),
		'post'       => array(
			'label'        => __( 'Post', 'takedown-desk' ),
			'active'       => '__return_true',
			'resolve_url'  => function ( string $url ): ?int {
				$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
				if ( function_exists( 'bbp_get_reply_post_type' ) && preg_match( '/^post-([0-9]+)$/', $fragment, $m )
					&& in_array( get_post_type( (int) $m[1] ), array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
					return (int) $m[1];
				}
				$id = url_to_postid( $url );
				return $id && 'attachment' !== get_post_type( $id ) ? $id : null;
			},
			'describe'     => function ( int $id ): ?array {
				$obj = get_post( $id );
				if ( ! $obj || 'attachment' === $obj->post_type ) {
					return null;
				}
				$label = $obj->post_title;
				if ( function_exists( 'bbp_get_reply_post_type' ) && bbp_get_reply_post_type() === $obj->post_type ) {
					/* translators: %s: display name of the reply author. */
					$label = sprintf( __( 'Reply by %s', 'takedown-desk' ), bbp_get_reply_author_display_name( $id ) );
				}
				return array(
					'label'     => $label,
					'url'       => (string) get_permalink( $obj ),
					'author_id' => (int) $obj->post_author,
					'excerpt'   => wp_trim_words( $obj->post_content, 30 ),
					'edit_url'  => (string) get_edit_post_link( $obj, 'raw' ),
				);
			},
			'media'        => static function ( int $id ): array {
				return array_merge( array(), ...array_map( 'tkdesk_attachment_media', tkdesk_post_images( $id ) ) );
			},
			'take_offline' => static function ( int $id ) {
				$previous = get_post_field( 'post_status', $id, 'raw' );
				$saved    = tkdesk_update_post( $id, array( 'post_status' => 'tkdesk_removed' ) );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				tkdesk_bbp_recount( $id );
				return array( 'status' => $previous );
			},
			'restore'      => static function ( int $id, array $data ) {
				$saved = tkdesk_update_post( $id, array( 'post_status' => $data['status'] ) );
				tkdesk_bbp_recount( $id );
				return $saved;
			},
			'delete'       => static function ( int $id ) {
				return wp_delete_post( $id, true ) ? true : new WP_Error( 'tkdesk_item_failed', __( 'The post could not be deleted.', 'takedown-desk' ) );
			},
			'purge_urls'   => static function ( int $id ): array {
				$post = get_post( $id );
				if ( ! $post ) {
					return array();
				}
				$urls = array( get_permalink( $post ), get_post_type_archive_link( $post->post_type ) );
				if ( $post->post_parent ) {
					$urls[] = get_permalink( $post->post_parent );
				}
				return array_values( array_filter( $urls ) );
			},
		),
	);
	return apply_filters( 'tkdesk_object_types', $types );
}

/**
 * Describe one object: label (plain text, max 191), url, author_id, excerpt, edit_url.
 *
 * @param string $type Registry key.
 * @param int    $id   Object ID.
 * @return array|null Null when the type is unknown or inactive, or the object does not exist.
 */
function tkdesk_describe( string $type, int $id ): ?array {
	$types = tkdesk_object_types();
	if ( empty( $types[ $type ] ) || ! call_user_func( $types[ $type ]['active'] ) ) {
		return null;
	}
	$desc = call_user_func( $types[ $type ]['describe'], $id );
	if ( ! is_array( $desc ) ) {
		return null;
	}
	$label = trim( html_entity_decode( wp_strip_all_tags( (string) $desc['label'] ), ENT_QUOTES, 'UTF-8' ) );
	/* translators: 1: object type label, 2: object ID. */
	$desc['label'] = mb_substr( '' !== $label ? $label : sprintf( __( '%1$s #%2$d', 'takedown-desk' ), $types[ $type ]['label'], $id ), 0, 191 );
	return $desc;
}

/**
 * The original file of an attachment as a media entry; empty when there is no local file (offloaded).
 *
 * @param int $id Attachment ID.
 * @return array
 */
function tkdesk_attachment_media( int $id ): array {
	$path = wp_get_original_image_path( $id );
	$path = $path ? $path : (string) get_attached_file( $id );
	if ( '' === $path || ! is_file( $path ) ) {
		return array();
	}
	return array(
		array(
			'source_key'    => 'attachment:' . $id,
			'path'          => $path,
			'url'           => (string) wp_get_attachment_url( $id ),
			'attachment_id' => $id,
		),
	);
}

/**
 * Change only the given columns of a post and write every other column back exactly as stored.
 *
 * Core's wp_update_post() runs kses and other save filters over every field and forces attachments
 * to `inherit`, so a late data filter puts the stored values back. That keeps removal and restore exact
 * while core still fires its status hooks (term counts, cache plugins).
 *
 * @param int   $id      Post ID.
 * @param array $changes Column => value, e.g. post_status or post_content.
 * @return true|WP_Error
 */
function tkdesk_update_post( int $id, array $changes ) {
	$stored = get_post( $id, ARRAY_A );
	if ( ! $stored ) {
		return new WP_Error( 'tkdesk_item_gone', __( 'This item no longer exists.', 'takedown-desk' ) );
	}
	$exact = static function ( array $data, array $postarr ) use ( $id, $stored, $changes ): array {
		if ( (int) ( $postarr['ID'] ?? 0 ) !== $id ) {
			return $data;
		}
		$keep = array_intersect_key( array_merge( $stored, $changes ), $data );
		unset( $keep['post_modified'], $keep['post_modified_gmt'] );
		return array_merge( $data, wp_slash( $keep ) );
	};
	add_filter( 'wp_insert_post_data', $exact, PHP_INT_MAX, 2 );
	add_filter( 'wp_insert_attachment_data', $exact, PHP_INT_MAX, 2 );
	$saved = wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $changes ) ), true );
	remove_filter( 'wp_insert_post_data', $exact, PHP_INT_MAX );
	remove_filter( 'wp_insert_attachment_data', $exact, PHP_INT_MAX );
	return is_wp_error( $saved ) ? $saved : true;
}

/**
 * Refresh bbPress topic and forum counts after a topic or reply changed status.
 *
 * @param int $id Post ID.
 */
function tkdesk_bbp_recount( int $id ): void {
	if ( ! function_exists( 'bbp_get_reply_post_type' ) || ! in_array( get_post_type( $id ), array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
		return;
	}
	$topic = bbp_get_reply_post_type() === get_post_type( $id ) ? bbp_get_reply_topic_id( $id ) : $id;
	$forum = bbp_get_topic_forum_id( $topic );
	bbp_update_topic_reply_count( $topic );
	bbp_update_forum_topic_count( $forum );
	bbp_update_forum_reply_count( $forum );
}

/**
 * Content kinds that may be removed: post type names plus `comment` and `attachment`.
 *
 * @return string[]
 */
function tkdesk_removable_types(): array {
	$types = (array) tkdesk_setting( 'removable_types' );
	if ( ! $types ) {
		$types = array_merge( array_values( get_post_types( array( 'public' => true ) ) ), array( 'comment', 'attachment' ) );
	}
	return (array) apply_filters( 'tkdesk_removable_types', array_values( array_unique( $types ) ) );
}

/**
 * Whether the current user may remove an item.
 *
 * @param array $item Item row (object_type, object_id).
 * @return true|WP_Error
 */
function tkdesk_can_remove( array $item ) {
	// Who wrote or uploaded it ($owner): an administrator's post, file or comment needs tkdesk_manage_settings.
	$type    = (string) $item['object_type'];
	$id      = (int) $item['object_id'];
	$kind    = 'post' === $type ? (string) get_post_type( $id ) : $type;
	$comment = 'comment' === $type ? get_comment( $id ) : null;
	$owner   = $comment ? (int) $comment->user_id : ( in_array( $type, array( 'post', 'attachment' ), true ) ? (int) get_post_field( 'post_author', $id ) : 0 );
	$texts   = array(
		'post'       => __( 'Only an administrator can remove pages or content written by an administrator.', 'takedown-desk' ),
		'attachment' => __( 'Only an administrator can remove files uploaded by an administrator.', 'takedown-desk' ),
		'comment'    => __( 'Only an administrator can remove comments written by an administrator.', 'takedown-desk' ),
	);
	$reason  = '';
	if ( ! in_array( $kind, tkdesk_removable_types(), true ) ) {
		$reason = __( 'Removing this kind of content is turned off in Settings.', 'takedown-desk' );
	} elseif ( isset( $texts[ $type ] ) && ! tkdesk_can( 'tkdesk_manage_settings' ) && ( 'page' === $kind || ( $owner && user_can( $owner, 'manage_options' ) ) ) ) {
		$reason = $texts[ $type ];
	}
	if ( apply_filters( 'tkdesk_can_remove', '' === $reason, $item, get_current_user_id() ) ) {
		return true;
	}
	return new WP_Error( 'tkdesk_cannot_remove', '' !== $reason ? $reason : __( 'You cannot remove this item.', 'takedown-desk' ), array( 'status' => 403 ) );
}

/**
 * Point a request at an object: sets the request's object and label and upserts its reported item.
 *
 * @param int   $request_id Request ID.
 * @param array $target     Object target.
 * @return array|WP_Error Request row.
 */
function tkdesk_match_item( int $request_id, array $target ) {
	global $wpdb;
	$type  = (string) ( $target['object_type'] ?? '' );
	$id    = (int) ( $target['object_id'] ?? 0 );
	$desc  = tkdesk_describe( $type, $id );
	$items = tkdesk_table( 'items' );
	if ( ! $desc || ! tkdesk_get_request( $request_id ) ) {
		return new WP_Error( 'tkdesk_item_not_found', __( 'We could not find that item on this site.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	$set = array(
		'object_type' => $type,
		'object_id'   => $id,
		'item_label'  => $desc['label'],
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_requests.
	$wpdb->update( tkdesk_table( 'requests' ), $set, array( 'id' => $request_id ) );
	// An earlier match nobody acted on is replaced; acted-on rows stay for the record.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE request_id = %d AND role = %s AND action = %s AND NOT ( object_type = %s AND object_id = %d )', $items, $request_id, 'reported', 'none', $type, $id ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (request_id, role, object_type, object_id, label, url) VALUES (%d, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE role = VALUES(role), label = VALUES(label), url = VALUES(url)', $items, $request_id, 'reported', $type, $id, $desc['label'], $desc['url'] ) );
	tkdesk_log(
		$request_id,
		'matched_item',
		array(
			'object_type' => $type,
			'object_id'   => $id,
		)
	);
	return tkdesk_get_request( $request_id );
}

/**
 * Remove items of a request. Each item is its own try block; a failed item is rolled back to how
 * it was and never stops the others. Order per item: files, content, status, caches, row, log, action.
 *
 * With `hide` the items go offline as `hidden`: no note, no purge date, and the request stays open.
 * Each logged item row carries `check`, the links that must stop answering (see tkdesk_reach_check()).
 *
 * @param int   $request_id Request ID.
 * @param array $args       items (item row IDs), scope (item|images), images (attachment IDs), mode (offline|delete), note, hide.
 * @return array|WP_Error array( 'request' => row, 'results' => array( array( 'item_id', 'ok', 'message' ) ) ).
 */
function tkdesk_remove_items( int $request_id, array $args ) {
	global $wpdb;
	$args    = wp_parse_args(
		$args,
		array(
			'items'  => array(),
			'scope'  => 'item',
			'images' => array(),
			'mode'   => 'offline',
			'note'   => '',
			'hide'   => false,
		)
	);
	$hide    = (bool) $args['hide'];
	$note    = sanitize_textarea_field( (string) $args['note'] );
	$ids     = array_map( 'absint', (array) $args['items'] );
	$images  = $hide ? array() : array_map( 'absint', (array) $args['images'] );
	$mode    = 'delete' === $args['mode'] && ! $hide ? 'delete' : 'offline';
	$scope   = 'images' === $args['scope'] && ! $hide ? 'images' : 'item';
	$request = tkdesk_get_request( $request_id );
	if ( ! $request ) {
		return new WP_Error( 'tkdesk_not_found', __( 'This request does not exist.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	// Under 18 in the image (minors.php): offline only, so the files are kept.
	if ( 'delete' === $mode && ! empty( $request['minor'] ) ) {
		tkdesk_log( $request_id, 'delete_refused' );
		return new WP_Error( 'tkdesk_minor_hold', __( 'The person in the image was under 18, so items can only be taken offline. Their files are kept.', 'takedown-desk' ), array( 'status' => 403 ) );
	}
	if ( ! $hide && mb_strlen( $note ) < 10 ) {
		return new WP_Error( 'tkdesk_missing_note', __( 'Write a decision note of at least 10 characters.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( ! tkdesk_is_open( $request['status'] ) ) {
		return new WP_Error( 'tkdesk_closed', __( 'This request is closed. Reopen it before removing anything.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	if ( ! $ids || ( 'images' === $scope && ! $images ) ) {
		return new WP_Error( 'tkdesk_nothing_selected', __( 'Choose what to remove.', 'takedown-desk' ), array( 'status' => 400 ) );
	}

	$types  = tkdesk_object_types();
	$remove = static function ( array $item, array &$restore, array &$check ) use ( $types, $images, $scope, $mode, $request_id, $hide ) {
		$type = (string) $item['object_type'];
		$id   = (int) $item['object_id'];
		$desc = empty( $types[ $type ] ) ? null : tkdesk_describe( $type, $id );
		if ( ! $desc ) {
			return new WP_Error( 'tkdesk_item_gone', __( 'This item no longer exists.', 'takedown-desk' ) );
		}
		$allowed = tkdesk_can_remove( $item );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// A hidden item is already offline; taking it offline for good keeps it exactly as it is.
		$hidden  = 'hidden' === $item['action'];
		$offline = 'comment' === $type ? (bool) get_comment_meta( $id, '_tkdesk_removed', true ) : 'tkdesk_removed' === get_post_status( $id );
		if ( in_array( $item['action'], array( 'offline', 'image_removed', 'deleted' ), true ) || ( 'offline' === $mode && $offline && ( $hide || ! $hidden ) ) ) {
			return new WP_Error( 'tkdesk_already_removed', __( 'This item is already offline.', 'takedown-desk' ) );
		}
		$def  = $types[ $type ];
		$file = $types['attachment'];
		$ctx  = array( 'request_id' => $request_id );
		$only = 'post' === $type && 'images' === $scope;
		$imgs = 'post' === $type ? array_values( array_intersect( $images, tkdesk_post_images( $id ) ) ) : array();
		$live = array_values(
			array_filter(
				$imgs,
				static function ( int $aid ): bool {
					return 'tkdesk_removed' !== get_post_status( $aid );
				}
			)
		);
		if ( $only && ! $imgs ) {
			return new WP_Error( 'tkdesk_no_images', __( 'None of the chosen images is inside this post.', 'takedown-desk' ) );
		}
		// Each image is a file in its own right, so the same rules decide whether it may go.
		foreach ( $live as $aid ) {
			$allowed = tkdesk_can_remove(
				array(
					'object_type' => 'attachment',
					'object_id'   => $aid,
				)
			);
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}
		$urls = call_user_func( $def['purge_urls'], $id );
		// Links that must stop answering: every file link of a file, otherwise the item's own link.
		$check = 'attachment' === $type ? $urls : ( $only ? array() : array( $desc['url'], (string) $item['url'] ) );

		// Block the hashes first: the file must still exist to be hashed, and deletion keeps only blocked rows.
		$restore['blocked'] = 'attachment' === $type ? array_merge( $live, array( $id ) ) : $live;
		foreach ( $restore['blocked'] as $aid ) {
			tkdesk_block_hash( $aid, $request_id );
		}
		// Plan 3.7: deleting images comes after the content, which still needs them to resolve image links.
		if ( $only && 'delete' === $mode ) {
			$done = tkdesk_neutralize_images( $id, $imgs );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		foreach ( $live as $aid ) {
			$gone  = call_user_func( $file['purge_urls'], $aid );
			$urls  = array_merge( $urls, $gone );
			$check = array_merge( $check, $gone );
			$done  = 'delete' === $mode ? call_user_func( $file['delete'], $aid ) : call_user_func( $file['take_offline'], $aid, $ctx );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( 'offline' === $mode ) {
				$restore['images'][ $aid ] = $done;
			}
		}
		if ( $only && 'offline' === $mode ) {
			$done = tkdesk_neutralize_images( $id, $imgs );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$restore['content'] = $done;
		}
		if ( ! $only ) {
			$saved = $hidden ? json_decode( (string) $item['restore_data'], true ) : null;
			if ( 'delete' === $mode ) {
				$done = call_user_func( $def['delete'], $id );
			} else {
				$done = isset( $saved['object'] ) ? $saved['object'] : call_user_func( $def['take_offline'], $id, $ctx );
			}
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( 'offline' === $mode ) {
				$restore['object'] = $done;
			}
		}
		if ( 'comment' === $type ) {
			clean_comment_cache( $id );
		} else {
			clean_post_cache( $id );
		}
		$urls  = array_values( array_unique( array_filter( $urls ) ) );
		$check = array_values( array_unique( array_filter( $check ) ) );
		do_action( 'tkdesk_purge_urls', $urls, $request_id );
		return $urls;
	};

	$table    = tkdesk_table( 'items' );
	$now      = tkdesk_now();
	$results  = array();
	$copies   = 0;
	$any      = false;
	$reported = false;
	$messages = array(
		'offline'       => __( 'Taken offline.', 'takedown-desk' ),
		'image_removed' => __( 'Images removed.', 'takedown-desk' ),
		'deleted'       => __( 'Deleted permanently.', 'takedown-desk' ),
		'hidden'        => __( 'Hidden while you review.', 'takedown-desk' ),
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id', $table, $request_id ), ARRAY_A );
	foreach ( $rows as $item ) {
		if ( ! in_array( (int) $item['id'], $ids, true ) ) {
			continue;
		}
		// Deleting a hidden file, or keeping a hidden post without its images, needs the item back in place first.
		$back  = 'hidden' === $item['action'] && ( 'images' === $scope ? 'post' === $item['object_type'] : 'delete' === $mode && 'attachment' === $item['object_type'] );
		$shown = $back ? tkdesk_unhide_items( $request_id, array( (int) $item['id'] ) ) : array();
		if ( $shown && ! $shown[0]['ok'] ) {
			$results[] = $shown[0];
			continue;
		}
		if ( $back ) {
			$item['action'] = 'none';
		}
		$restore = array();
		$check   = array();
		if ( ! $hide ) {
			/**
			 * Fires before an item goes offline or is deleted, while the object still exists.
			 *
			 * @param array  $item    Item row.
			 * @param array  $request Request row.
			 * @param string $mode    offline | delete.
			 */
			do_action( 'tkdesk_before_item_removed', $item, $request, $mode );
		}
		try {
			$done = $remove( $item, $restore, $check );
		} catch ( Throwable $e ) {
			$done = new WP_Error( 'tkdesk_item_failed', $e->getMessage() );
		}
		$ok = ! is_wp_error( $done );
		if ( ! $ok && 'offline' === $mode && $restore ) {
			tkdesk_restore_item( $item, $restore );
		}
		if ( ! $ok ) {
			$action = 'failed';
		} elseif ( $hide ) {
			$action = 'hidden';
		} elseif ( 'post' === $item['object_type'] && 'images' === $scope ) {
			$action = 'image_removed';
		} else {
			$action = 'delete' === $mode ? 'deleted' : 'offline';
		}
		$keep = $ok && 'offline' === $mode;
		$set  = array(
			'action'       => $action,
			'action_at'    => tkdesk_mysql( $now ),
			'action_by'    => get_current_user_id(),
			'restore_data' => $keep ? wp_json_encode( $restore ) : null,
			'purge_after'  => $keep && ! $hide ? tkdesk_mysql( $now + (int) tkdesk_setting( 'purge_removed_days' ) * DAY_IN_SECONDS ) : null,
		);
		// A hidden item that could not be removed stays hidden, row untouched.
		if ( $ok || 'hidden' !== $item['action'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
			$wpdb->update( $table, $set, array( 'id' => $item['id'] ) );
		}
		$detail = array(
			'item_id'     => (int) $item['id'],
			'object_type' => $item['object_type'],
			'object_id'   => (int) $item['object_id'],
		);
		$detail = $ok ? $detail + array(
			'mode'  => $mode,
			'urls'  => $done,
			'check' => $check,
		) : $detail + array( 'error' => $done->get_error_code() );
		tkdesk_log( $request_id, 'item_' . $action, $detail );
		if ( $ok && ! $hide ) {
			$item = array_merge( $item, $set );
			do_action( 'tkdesk_item_removed', $item, $request, $mode );
			$any      = true;
			$reported = $reported || 'reported' === $item['role'];
			$copies  += 'reported' === $item['role'] ? 0 : 1;
		}
		$results[] = array(
			'item_id' => (int) $item['id'],
			'ok'      => $ok,
			'message' => $ok ? $messages[ $action ] : $done->get_error_message(),
		);
	}
	if ( $reported || ( $any && ! in_array( 'reported', wp_list_pluck( $rows, 'role' ), true ) ) ) {
		tkdesk_transition(
			$request_id,
			'removed',
			array(
				'note'          => $note,
				'removed_count' => $copies,
			)
		);
	}
	if ( $any ) {
		/**
		 * Fires once after a removal in which at least one item went offline or was deleted (after the
		 * request was closed as removed, when it was).
		 *
		 * @param int $request_id Request ID.
		 */
		do_action( 'tkdesk_items_removed', $request_id );
	}
	return array(
		'request' => tkdesk_get_request( $request_id ),
		'results' => $results,
	);
}

/**
 * Put one item back from its restore data: files, then content, then status, then hash blocks.
 * Every step is safe to repeat, so a retry after a partial failure finishes the job.
 *
 * @param array $item    Item row.
 * @param array $restore Decoded restore data.
 * @return true|WP_Error
 */
function tkdesk_restore_item( array $item, array $restore ) {
	$types = tkdesk_object_types();
	$id    = (int) $item['object_id'];
	foreach ( (array) ( $restore['images'] ?? array() ) as $aid => $data ) {
		$done = call_user_func( $types['attachment']['restore'], (int) $aid, $data );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
	}
	if ( isset( $restore['content'] ) ) {
		$done = tkdesk_update_post( $id, array( 'post_content' => $restore['content']['post_content'] ) );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		if ( $restore['content']['thumbnail_id'] ) {
			update_post_meta( $id, '_thumbnail_id', (int) $restore['content']['thumbnail_id'] );
		}
	}
	if ( isset( $restore['object'] ) ) {
		$done = call_user_func( $types[ $item['object_type'] ]['restore'], $id, $restore['object'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
	}
	foreach ( (array) ( $restore['blocked'] ?? array() ) as $aid ) {
		tkdesk_block_hash( (int) $aid, (int) $item['request_id'], false );
	}
	return true;
}

/**
 * Put removed items back (administrators only, inside the purge window).
 *
 * @param int    $request_id Request ID.
 * @param array  $item_ids   Item row IDs.
 * @param string $note       Why, 10+ characters.
 * @return array|WP_Error Same shape as tkdesk_remove_items().
 */
function tkdesk_restore_items( int $request_id, array $item_ids, string $note ) {
	global $wpdb;
	if ( ! tkdesk_can( 'tkdesk_manage_settings' ) ) {
		return new WP_Error( 'tkdesk_forbidden', __( 'Only an administrator can put removed items back.', 'takedown-desk' ), array( 'status' => 403 ) );
	}
	$note    = sanitize_textarea_field( $note );
	$request = tkdesk_get_request( $request_id );
	if ( mb_strlen( $note ) < 10 ) {
		return new WP_Error( 'tkdesk_missing_note', __( 'Write a decision note of at least 10 characters.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( ! $request ) {
		return new WP_Error( 'tkdesk_not_found', __( 'This request does not exist.', 'takedown-desk' ), array( 'status' => 404 ) );
	}
	// Under 18 in the image (minors.php): removed items stay offline while the flag is on.
	if ( ! empty( $request['minor'] ) ) {
		tkdesk_log( $request_id, 'restore_refused' );
		return new WP_Error( 'tkdesk_minor_hold', __( 'The person in the image was under 18, so removed items stay offline.', 'takedown-desk' ), array( 'status' => 403 ) );
	}
	$ids     = array_map( 'absint', $item_ids );
	$table   = tkdesk_table( 'items' );
	$now     = tkdesk_now();
	$results = array();
	$any     = false;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id', $table, $request_id ), ARRAY_A );
	foreach ( $rows as $item ) {
		if ( ! in_array( (int) $item['id'], $ids, true ) ) {
			continue;
		}
		$restore = json_decode( (string) $item['restore_data'], true );
		$done    = new WP_Error( 'tkdesk_cannot_restore', __( 'This item can no longer be put back.', 'takedown-desk' ) );
		if ( is_array( $restore ) && in_array( $item['action'], array( 'offline', 'image_removed' ), true ) && tkdesk_ts( $item['purge_after'] ) > $now ) {
			try {
				$done = tkdesk_restore_item( $item, $restore );
			} catch ( Throwable $e ) {
				$done = new WP_Error( 'tkdesk_restore_failed', $e->getMessage() );
			}
		}
		$ok = ! is_wp_error( $done );
		if ( $ok ) {
			$set = array(
				'action'       => 'restored',
				'action_at'    => tkdesk_mysql( $now ),
				'action_by'    => get_current_user_id(),
				'restore_data' => null,
				'purge_after'  => null,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
			$wpdb->update( $table, $set, array( 'id' => $item['id'] ) );
			tkdesk_log(
				$request_id,
				'restored',
				array(
					'item_id'     => (int) $item['id'],
					'object_type' => $item['object_type'],
					'object_id'   => (int) $item['object_id'],
				)
			);
			do_action( 'tkdesk_item_restored', array_merge( $item, $set ), $request );
			$any = true;
		}
		$results[] = array(
			'item_id' => (int) $item['id'],
			'ok'      => $ok,
			'message' => $ok ? __( 'Put back.', 'takedown-desk' ) : $done->get_error_message(),
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_id = %d AND action IN (%s, %s, %s)', $table, $request_id, 'offline', 'image_removed', 'deleted' ) );
	if ( $any && ! $left && 'removed' === $request['status'] ) {
		tkdesk_transition(
			$request_id,
			'declined',
			array(
				'note'   => $note,
				'reason' => 'restored_after_review',
			)
		);
	}
	return array(
		'request' => tkdesk_get_request( $request_id ),
		'results' => $results,
	);
}

/**
 * Hide while reviewing: the reported item goes offline in one click and the request stays open.
 *
 * @param int $request_id Request ID.
 * @return array|WP_Error Same shape as tkdesk_remove_items().
 */
function tkdesk_hide_item( int $request_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE request_id = %d AND role = %s', tkdesk_table( 'items' ), $request_id, 'reported' ) );
	if ( ! $id ) {
		return new WP_Error( 'tkdesk_item_not_found', __( 'Match the reported item before hiding it.', 'takedown-desk' ), array( 'status' => 409 ) );
	}
	return tkdesk_remove_items(
		$request_id,
		array(
			'items' => array( $id ),
			'hide'  => true,
		)
	);
}

/**
 * Put hidden items back (any moderator, no note: hiding is a temporary step), or with $keep turn them
 * into ordinary offline items with the usual restore window. Under 18 in the image (minors.php), hidden
 * items are never put back.
 *
 * @param int   $request_id Request ID.
 * @param int[] $item_ids   Item row IDs; empty means every hidden item of the request.
 * @param bool  $keep       Keep them offline instead.
 * @return array Results: array( array( 'item_id', 'ok', 'message' ) ).
 */
function tkdesk_unhide_items( int $request_id, array $item_ids = array(), bool $keep = false ): array {
	global $wpdb;
	$table   = tkdesk_table( 'items' );
	$now     = tkdesk_now();
	$ids     = array_map( 'intval', $item_ids );
	$request = tkdesk_get_request( $request_id );
	$results = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d AND action = %s ORDER BY id', $table, $request_id, 'hidden' ), ARRAY_A );
	foreach ( $rows as $item ) {
		if ( $ids && ! in_array( (int) $item['id'], $ids, true ) ) {
			continue;
		}
		$set     = array(
			'action'       => 'none',
			'action_at'    => tkdesk_mysql( $now ),
			'action_by'    => get_current_user_id(),
			'restore_data' => null,
			'purge_after'  => null,
		);
		$detail  = array(
			'item_id'     => (int) $item['id'],
			'object_type' => $item['object_type'],
			'object_id'   => (int) $item['object_id'],
		);
		$restore = json_decode( (string) $item['restore_data'], true );
		$done    = new WP_Error( 'tkdesk_cannot_restore', __( 'This item can no longer be put back.', 'takedown-desk' ) );
		if ( $keep ) {
			$set['action']       = 'offline';
			$set['restore_data'] = $item['restore_data'];
			$set['purge_after']  = tkdesk_mysql( $now + (int) tkdesk_setting( 'purge_removed_days' ) * DAY_IN_SECONDS );
			$detail['mode']      = 'offline';
			$done                = true;
		} elseif ( ! empty( $request['minor'] ) ) {
			tkdesk_log( $request_id, 'restore_refused', $detail );
			$done = new WP_Error( 'tkdesk_minor_hold', __( 'The person in the image was under 18, so removed items stay offline.', 'takedown-desk' ), array( 'status' => 403 ) );
		} elseif ( is_array( $restore ) ) {
			try {
				$done = tkdesk_restore_item( $item, $restore );
			} catch ( Throwable $e ) {
				$done = new WP_Error( 'tkdesk_restore_failed', $e->getMessage() );
			}
		}
		$ok = ! is_wp_error( $done );
		if ( $ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table tkdesk_items.
			$wpdb->update( $table, $set, array( 'id' => $item['id'] ) );
			tkdesk_log( $request_id, $keep ? 'item_offline' : 'unhidden', $detail );
		}
		$results[] = array(
			'item_id' => (int) $item['id'],
			'ok'      => $ok,
			'message' => $ok ? ( $keep ? __( 'Taken offline.', 'takedown-desk' ) : __( 'Put back.', 'takedown-desk' ) ) : $done->get_error_message(),
		);
	}
	return $results;
}
