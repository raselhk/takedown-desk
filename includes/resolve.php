<?php
/**
 * URL resolver: maps a reported link to an object on this site. Never performs an HTTP request.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosts that count as this site.
 *
 * @return string[]
 */
function tkdesk_allowed_hosts(): array {
	$hosts = array(
		wp_parse_url( home_url(), PHP_URL_HOST ),
		wp_parse_url( site_url(), PHP_URL_HOST ),
		wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_HOST ),
	);
	$hosts = array_merge( $hosts, (array) tkdesk_setting( 'allowed_hosts' ) );
	$hosts = array_values( array_unique( array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) ) );
	return apply_filters( 'tkdesk_allowed_hosts', $hosts );
}

/**
 * Whether a link is http(s) on an allowed host. A leading "www." is ignored on both sides.
 *
 * @param string $url Link.
 * @return bool
 */
function tkdesk_url_on_site( string $url ): bool {
	$parts = wp_parse_url( trim( $url ) );
	if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$bare = function ( $host ) {
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	};
	return in_array( $bare( $parts['host'] ), array_map( $bare, tkdesk_allowed_hosts() ), true );
}

/**
 * Parse an item reference `{key}:{id}`.
 *
 * @param string $ref Item reference.
 * @return array|null Object target when the key is registered and the object exists and may be shown.
 */
function tkdesk_parse_item_ref( string $ref ): ?array {
	// By ID an attachment must be viewable like a post: an ID alone must not reveal the file of a draft.
	if ( ! preg_match( '/^([a-z0-9_-]+):([0-9]+)$/', $ref, $m ) || ! tkdesk_describe( $m[1], (int) $m[2] ) || ! tkdesk_target_public( 'attachment' === $m[1] ? 'post' : $m[1], (int) $m[2] ) ) {
		return null;
	}
	return array(
		'object_type' => $m[1],
		'object_id'   => (int) $m[2],
	);
}

/**
 * Resolve a link to an object target. Asks each active registry type in order; no HTTP request.
 *
 * @param string $url Link.
 * @return array|null Object target or null.
 */
function tkdesk_resolve_url( string $url ): ?array {
	$target = null;
	if ( tkdesk_url_on_site( $url ) ) {
		foreach ( tkdesk_object_types() as $type => $def ) {
			$id = call_user_func( $def['active'] ) ? (int) call_user_func( $def['resolve_url'], $url ) : 0;
			if ( $id && tkdesk_target_public( $type, $id ) ) {
				$target = array(
					'object_type' => $type,
					'object_id'   => $id,
				);
				break;
			}
		}
	}
	return apply_filters( 'tkdesk_resolve_url', $target, $url );
}

/**
 * Whether a target may be resolved for the current user: anything for moderators, public objects
 * for everyone else. Upload files are public by nature, so attachments always pass.
 *
 * @param string $type Registry key.
 * @param int    $id   Object ID.
 * @return bool
 */
function tkdesk_target_public( string $type, int $id ): bool {
	if ( tkdesk_can( 'tkdesk_manage_requests' ) ) {
		return true;
	}
	if ( 'post' === $type ) {
		// bbPress keeps topics and replies of private and hidden forums published: their forum decides.
		$owner = 'attachment' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
		$forum = function_exists( 'bbp_user_can_view_forum' ) ? (int) get_post_meta( $owner, '_bbp_forum_id', true ) : 0;
		return is_post_publicly_viewable( $id ) && ( ! $forum || bbp_user_can_view_forum(
			array(
				'forum_id'        => $forum,
				'check_ancestors' => true,
			)
		) );
	}
	if ( 'comment' === $type ) {
		$comment = get_comment( $id );
		return $comment && '1' === $comment->comment_approved && is_post_publicly_viewable( (int) $comment->comment_post_ID );
	}
	return true;
}
