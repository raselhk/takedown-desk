<?php
/**
 * Front end: notice page, notice links, private view headers, assets and the no-JS handlers.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', 'tkdesk_footer_link' );
add_filter( 'the_content', 'tkdesk_content_link', 99 );
add_filter( 'the_content', 'tkdesk_view_first', 20 );
add_filter( 'comment_text', 'tkdesk_comment_link', 99, 2 );
add_action( 'bbp_theme_after_topic_content', 'tkdesk_bbp_link' );
add_action( 'bbp_theme_after_reply_content', 'tkdesk_bbp_link' );
add_action( 'template_redirect', 'tkdesk_private_view_headers' );
add_action( 'wp_enqueue_scripts', 'tkdesk_maybe_enqueue_form_assets' );
add_action( 'admin_post_tkdesk_submit', 'tkdesk_post_submit' );
add_action( 'admin_post_nopriv_tkdesk_submit', 'tkdesk_post_submit' );
add_action( 'admin_post_tkdesk_details', 'tkdesk_post_details' );
add_action( 'admin_post_nopriv_tkdesk_details', 'tkdesk_post_details' );

/**
 * Notice page URL (translated page under WPML or Polylang) plus query args.
 *
 * @param array $args Query args to add.
 * @return string Empty when no published notice page exists.
 */
function tkdesk_notice_url( array $args = array() ): string {
	$id = (int) tkdesk_setting( 'notice_page_id' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter (plan 3.12).
	$id = (int) apply_filters( 'wpml_object_id', $id, 'page', true );
	if ( $id && function_exists( 'pll_get_post' ) && pll_get_post( $id ) ) {
		$id = (int) pll_get_post( $id );
	}
	if ( ! $id || 'publish' !== get_post_status( $id ) ) {
		return '';
	}
	return add_query_arg( array_map( 'rawurlencode', $args ), get_permalink( $id ) );
}

/**
 * Requester status page URL.
 *
 * @param array  $request Request row.
 * @param string $token   Raw requester token.
 * @return string
 */
function tkdesk_status_url( array $request, string $token ): string {
	return tkdesk_notice_url(
		array(
			'tkdesk_request' => $request['public_id'],
			'token'          => $token,
		)
	);
}

/**
 * Admin URL of one request.
 *
 * @param array $request Request row.
 * @return string
 */
function tkdesk_admin_request_url( array $request ): string {
	return admin_url( 'admin.php?page=takedown-desk#request/' . (int) $request['id'] );
}

/**
 * Default notice text, one paragraph each (a "\n" is a line break): what the site does, the four parts of a
 * request, and what is not needed. The form block adds the fallback email, so it is never out of date.
 *
 * @return string[]
 */
function tkdesk_notice_paragraphs(): array {
	return array(
		__( 'If someone posted an intimate image or video of you on this site without your consent, you can ask us to remove it. You do not need an account.', 'takedown-desk' ),
		__( 'What we do: we review every request. If the request is valid, we remove the image within 48 hours. We also look for exact copies of the same file on this site and remove them too, so you do not need to report each copy.', 'takedown-desk' ),
		implode(
			"\n",
			array(
				__( 'Your request needs four things:', 'takedown-desk' ),
				__( '1. Where the image is, such as a link to the page.', 'takedown-desk' ),
				__( '2. A short statement that it was shared without consent.', 'takedown-desk' ),
				__( '3. A way to reach you, such as an email address.', 'takedown-desk' ),
				__( '4. Your name, typed as a signature.', 'takedown-desk' ),
			)
		),
		__( 'We do not need a copy of the image or an ID.', 'takedown-desk' ),
		__( 'You get a request ID right away. We email you when we decide, and you can check the status at any time.', 'takedown-desk' ),
		__( 'If the person in the image was under 18 when it was taken, you can also use NCMEC\'s free Take It Down service. Adults can use StopNCII.org. Both can help stop the image from spreading to other sites.', 'takedown-desk' ),
	);
}

/**
 * Create the notice page, or return the existing one.
 *
 * @return int|WP_Error Page ID.
 */
function tkdesk_create_notice_page() {
	$id = (int) tkdesk_setting( 'notice_page_id' );
	if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
		return $id;
	}
	$content = '';
	foreach ( tkdesk_notice_paragraphs() as $text ) {
		$content .= "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Request removal of intimate images', 'takedown-desk' ),
				'post_name'      => 'request-removal',
				'post_content'   => tkdesk_styled_group( 'tkdesk-notice', $content ) . '<!-- wp:takedown-desk/request-form /-->',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	tkdesk_update_settings( array( 'notice_page_id' => (int) $id ) );
	return (int) $id;
}

/**
 * A Group block around page text Takedown Desk writes (notice page, policy page) so it takes the plugin's
 * look (form.css). It stays an ordinary Group: the site owner can edit the text or remove the wrapper.
 *
 * @param string $name    tkdesk-notice | tkdesk-policy.
 * @param string $content Serialized blocks.
 * @return string
 */
function tkdesk_styled_group( string $name, string $content ): string {
	return '<!-- wp:group {"className":"tkdesk ' . $name . '"} -->' . "\n"
		. '<div class="wp-block-group tkdesk ' . $name . '">' . "\n" . trim( $content ) . "\n" . '</div>' . "\n"
		. '<!-- /wp:group -->' . "\n\n";
}

/**
 * Inline CSS for the footer and content links, printed once (no file).
 */
function tkdesk_link_css() {
	if ( wp_style_is( 'tkdesk-links' ) ) {
		return;
	}
	wp_register_style( 'tkdesk-links', false, array(), TKDESK_VERSION );
	wp_enqueue_style( 'tkdesk-links' );
	// In the theme's own colors (light or dark), with the plugin's shapes: a soft underline in the footer, and a
	// small flag chip after content (a stand-alone link). ":is(html,#tkdesk)" always matches but weighs as an ID,
	// so forum and community plugins that style links by ID (bbPress: #bbpress-forums ... a) do not undo it.
	$flag = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 21V4h11l-1.5 4L16 12H5'/%3E%3C/svg%3E";
	$foot = ':is(html,#tkdesk) .tkdesk-footer-link a';
	$chip = ':is(html,#tkdesk) .tkdesk-request a.tkdesk-request-link';
	wp_add_inline_style(
		'tkdesk-links',
		'.tkdesk-footer-link{margin:0;padding:0 1rem;text-align:center}'
		. $foot . '{display:inline-flex;align-items:center;min-height:44px;font-size:max(14px,.875em);color:inherit;text-decoration:underline;text-decoration-thickness:1.5px;text-decoration-color:color-mix(in srgb,currentColor 30%,transparent);text-underline-offset:.3em}'
		. $foot . ':hover{text-decoration-color:currentColor}'
		. '.tkdesk-request{margin-block:.75rem 0}'
		. $chip . '{display:inline-flex;align-items:center;gap:.4em;min-height:40px;padding:0 .9rem;font-size:max(14px,.8125em);font-weight:600;line-height:1.2;color:inherit;text-decoration:none;background:none;border:1px solid color-mix(in srgb,currentColor 28%,transparent);border-radius:999px;transition:background-color .12s,border-color .12s}'
		. $chip . '::before{content:"";flex:none;width:1em;height:1em;background:currentColor;-webkit-mask:url("' . $flag . '") center/contain no-repeat;mask:url("' . $flag . '") center/contain no-repeat;opacity:.75}'
		. $chip . ':hover{text-decoration:none;background:color-mix(in srgb,currentColor 7%,transparent);border-color:color-mix(in srgb,currentColor 55%,transparent)}'
		. $chip . ':focus-visible{outline:3px solid currentColor;outline-offset:2px}'
	);
}

/**
 * Footer link markup, also used by the notice link block and shortcode.
 *
 * @return string Empty when there is no notice page.
 */
function tkdesk_notice_link_html(): string {
	$url = tkdesk_notice_url();
	if ( '' === $url ) {
		return '';
	}
	tkdesk_link_css();
	$text = (string) tkdesk_setting( 'footer_link_text' );
	if ( '' === $text || 'Request removal of intimate images' === $text ) {
		$text = __( 'Request removal of intimate images', 'takedown-desk' );
	}
	return '<p class="tkdesk-footer-link"><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
}

/**
 * Print the footer link when the setting is auto.
 */
function tkdesk_footer_link() {
	if ( 'auto' === tkdesk_setting( 'footer_link' ) ) {
		echo wp_kses_post( tkdesk_notice_link_html() );
	}
}

/**
 * "Request removal" link for one object, filtered by tkdesk_request_link.
 *
 * @param string $type Registry key (post, comment, attachment).
 * @param int    $id   Object ID.
 * @return string
 */
function tkdesk_request_link_html( string $type, int $id ): string {
	$url = tkdesk_notice_url( array( 'tkdesk_item' => $type . ':' . $id ) );
	if ( '' === $url ) {
		return '';
	}
	tkdesk_link_css();
	$html = '<p class="tkdesk-request"><a class="tkdesk-request-link" rel="nofollow" href="' . esc_url( $url ) . '">' . esc_html__( 'Request removal', 'takedown-desk' ) . '</a></p>';
	return (string) apply_filters( 'tkdesk_request_link', $html, $type, $id );
}

/**
 * Append the link after singular content (main query only; bbPress uses its own hooks).
 *
 * @param string $content Post content.
 * @return string
 */
function tkdesk_content_link( $content ) {
	if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || ( function_exists( 'is_bbpress' ) && is_bbpress() ) ) {
		return $content;
	}
	$post = get_post();
	if ( ! $post || (int) tkdesk_setting( 'notice_page_id' ) === $post->ID ) {
		return $content;
	}
	if ( is_attachment() ) {
		return tkdesk_setting( 'link_attachments' ) ? $content . tkdesk_request_link_html( 'attachment', $post->ID ) : $content;
	}
	if ( in_array( $post->post_type, (array) tkdesk_setting( 'link_post_types' ), true ) ) {
		$content .= tkdesk_request_link_html( 'post', $post->ID );
	}
	return $content;
}

/**
 * A status, confirmation, review or form error view replaces the text of the page that holds the form, so the
 * person sees it first and the notice text above it ("send the form", 48 hours, and so on) is not repeated.
 * Runs after blocks, wpautop and shortcodes so the view is not reformatted.
 *
 * @param string $content Post content.
 * @return string
 */
function tkdesk_view_first( $content ) {
	$post = get_post();
	if ( ! $post || ! is_singular() || ! in_the_loop() || ! is_main_query() || ! ( has_block( 'takedown-desk/request-form', $post ) || has_shortcode( $post->post_content, 'takedown_desk_form' ) ) ) {
		return $content;
	}
	foreach ( array( 'tkdesk_request', 'tkdesk_done', 'tkdesk_e', 'tkdesk_review' ) as $key ) {
		if ( '' !== tkdesk_query_arg( $key ) ) {
			return tkdesk_render_form_block();
		}
	}
	return $content;
}

/**
 * Append the link to comment text.
 *
 * @param string          $text    Comment text.
 * @param WP_Comment|null $comment Comment.
 * @return string
 */
function tkdesk_comment_link( $text, $comment = null ) {
	if ( is_admin() || is_feed() || ! $comment instanceof WP_Comment || ! tkdesk_setting( 'link_comments' ) ) {
		return $text;
	}
	return $text . tkdesk_request_link_html( 'comment', (int) $comment->comment_ID );
}

/**
 * Print the link after a bbPress topic or reply.
 */
function tkdesk_bbp_link() {
	$id = 'bbp_theme_after_topic_content' === current_action() ? bbp_get_topic_id() : bbp_get_reply_id();
	if ( $id && in_array( get_post_type( $id ), (array) tkdesk_setting( 'link_post_types' ), true ) ) {
		echo wp_kses_post( tkdesk_request_link_html( 'post', (int) $id ) );
	}
}

/**
 * One query arg from the current URL.
 *
 * @param string $key Arg name.
 * @return string Sanitized value, '' when absent.
 */
function tkdesk_query_arg( string $key ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only views; tokens are verified where used.
	return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
}

/**
 * Noindex, no-store and no page cache for item, status, confirmation, review and error views. No referrer
 * either, so the private link's token never reaches another site through a link or asset on the page.
 */
function tkdesk_private_view_headers() {
	foreach ( array( 'tkdesk_item', 'tkdesk_request', 'tkdesk_done', 'tkdesk_e', 'tkdesk_review' ) as $key ) {
		if ( '' !== tkdesk_query_arg( $key ) ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex' );
			header( 'Referrer-Policy: no-referrer' );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page-cache plugins' shared constant (contract 3).
				define( 'DONOTCACHEPAGE', true );
			}
			return;
		}
	}
}

/**
 * Enqueue form.css and form.js (once) with the JS settings.
 */
function tkdesk_enqueue_form_assets() {
	if ( wp_script_is( 'tkdesk-form' ) ) {
		return;
	}
	$copy = tkdesk_form_messages();
	// The server's own messages for empty required fields and a malformed email, for checks before sending.
	$none    = tkdesk_validate_request( array() );
	$bad     = tkdesk_validate_request( array( 'email' => 'x' ) );
	$i18n    = array(
		'sending' => __( 'Sending...', 'takedown-desk' ),
		'rate'    => $copy['rate'],
		'server'  => $copy['server'],
		'fields'  => is_wp_error( $none ) ? (array) ( $none->get_error_data()['fields'] ?? array() ) : array(),
		'email'   => is_wp_error( $bad ) ? (string) ( $bad->get_error_data()['fields']['email'] ?? '' ) : '',
	);
	$mode    = tkdesk_bot_mode();
	$captcha = null;
	if ( 'recaptcha' === $mode ) {
		$captcha = array(
			'v'   => (string) tkdesk_setting( 'recaptcha_version' ),
			'key' => (string) tkdesk_setting( 'recaptcha_site_key' ),
		);
		$i18n   += array(
			'captcha'     => __( 'Check the "I\'m not a robot" box, then send the form again.', 'takedown-desk' ),
			'captchaLoad' => __( 'Google reCAPTCHA did not load, so this form cannot be sent yet. Check your connection or turn off content blockers for this site, then reload the page.', 'takedown-desk' ),
		);
	}
	wp_enqueue_style( 'tkdesk-form' );
	wp_enqueue_script( 'tkdesk-form' );
	wp_localize_script(
		'tkdesk-form',
		'tkdeskForm',
		array(
			'rest'    => esc_url_raw( rest_url( 'takedown-desk/v1/' ) ),
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			// The bot check adds nothing when it is off.
			'pow'     => 'builtin' === $mode ? 1 : 0,
			'captcha' => $captcha,
			'i18n'    => 'builtin' === $mode ? $i18n + array( 'checking' => __( 'Checking...', 'takedown-desk' ) ) : $i18n,
		)
	);
	if ( $captcha ) {
		// Google's script only in reCAPTCHA mode, after form.js, which holds its onload callback.
		wp_enqueue_script(
			'tkdesk-recaptcha',
			add_query_arg(
				array(
					'onload' => 'tkdeskCaptchaLoad',
					'render' => 'v2' === $captcha['v'] ? 'explicit' : rawurlencode( $captcha['key'] ),
				),
				'https://www.google.com/recaptcha/api.js'
			),
			array( 'tkdesk-form' ),
			TKDESK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}

/**
 * Enqueue early (in the head) when the queried post holds the form, so classic themes do not flash unstyled.
 */
function tkdesk_maybe_enqueue_form_assets() {
	$post = get_queried_object();
	if ( $post instanceof WP_Post && ( has_block( 'takedown-desk/request-form', $post ) || has_shortcode( $post->post_content, 'takedown_desk_form' ) ) ) {
		tkdesk_enqueue_form_assets();
	} elseif ( $post instanceof WP_Post && preg_match( '/\btkdesk-(notice|policy)\b/', $post->post_content ) ) {
		wp_enqueue_style( 'tkdesk-form' ); // A policy page (or notice text) on its own: styles only, no script.
	}
}

/**
 * Read posted string fields, unslashed and sanitized as they arrive: the link as a URL, the multi-line
 * fields keeping their line breaks, everything else as plain text. Validation happens in the handlers.
 *
 * @param string[] $keys Field names.
 * @return array
 */
function tkdesk_posted( array $keys ): array {
	$out = array();
	foreach ( $keys as $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce-free public forms by design (signed time stamp, honeypot, bot check); the logged-in nonce is checked by the caller.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			$out[ $key ] = '';
		} elseif ( 'url' === $key ) {
			$out[ $key ] = esc_url_raw( wp_unslash( $_POST[ $key ] ), array( 'http', 'https' ) );
		} elseif ( in_array( $key, array( 'details', 'statement', 'message' ), true ) ) {
			$out[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
		} else {
			$out[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
	return $out;
}

/**
 * View state carried across a no-JS redirect (encrypted 5-minute transient keyed by tkdesk_e).
 *
 * @return array
 */
function tkdesk_form_state(): array {
	$key = sanitize_key( tkdesk_query_arg( 'tkdesk_e' ) );
	$raw = '' === $key ? false : get_transient( 'tkdesk_fe_' . $key );
	if ( ! is_string( $raw ) ) {
		return array();
	}
	$json  = tkdesk_decrypt( $raw );
	$state = is_string( $json ) ? json_decode( $json, true ) : null;
	return is_array( $state ) ? $state : array();
}

/**
 * Redirect back to the notice page (or the referring page) and stop.
 *
 * @param array $args  Query args.
 * @param array $state View state to carry; never put in the URL itself.
 */
function tkdesk_redirect_back( array $args, array $state = array() ) {
	if ( $state ) {
		$key = bin2hex( random_bytes( 12 ) );
		set_transient( 'tkdesk_fe_' . $key, tkdesk_encrypt( (string) wp_json_encode( $state ) ), 5 * MINUTE_IN_SECONDS );
		$args['tkdesk_e'] = $key;
	}
	$url = tkdesk_notice_url( $args );
	if ( '' === $url ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args ), wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
	}
	wp_safe_redirect( $url, 303 );
	exit;
}

/**
 * No-JS request form submission (admin-post.php, action tkdesk_submit).
 */
function tkdesk_post_submit() {
	// Without a valid nonce (a forged post, or a form from a cached page) a logged-in user is handled as a
	// logged-out visitor: rate limit and public-object check apply. Never refused, so cached pages keep working.
	if ( is_user_logged_in() && ! wp_verify_nonce( sanitize_text_field( tkdesk_posted( array( 'tkdesk_nonce' ) )['tkdesk_nonce'] ), 'tkdesk_submit' ) ) {
		wp_set_current_user( 0 );
	}
	$in     = tkdesk_posted( array_merge( array_keys( tkdesk_form_fields() ), array( 'tkdesk_website', 'tkdesk_ts', 'tkdesk_sig', 'item', 'tkdesk_pow', 'tkdesk_q', 'tkdesk_answer', 'g-recaptcha-response' ) ) );
	$result = tkdesk_handle_submit( $in, tkdesk_client_ip() );
	if ( is_wp_error( $result ) ) {
		$data   = (array) $result->get_error_data();
		$fields = empty( $data['fields'] ) ? array() : (array) $data['fields'];
		$status = isset( $data['status'] ) ? (int) $data['status'] : 500;
		$copy   = tkdesk_form_messages();
		$notice = '';
		if ( ! $fields ) {
			$notice = $copy['server'];
			if ( 429 === $status ) {
				$notice = $copy['rate'];
			} elseif ( 400 === $status ) {
				$notice = $result->get_error_message();
			}
		}
		unset( $in['tkdesk_website'], $in['g-recaptcha-response'] ); // A token counts once.
		if ( 'tkdesk_form_expired' === $result->get_error_code() ) {
			// A fresh stamp, or the re-shown form would expire again on every send.
			unset( $in['tkdesk_ts'], $in['tkdesk_sig'] );
		}
		tkdesk_redirect_back(
			array(),
			array(
				'values' => $in,
				'errors' => $fields,
				'notice' => $notice,
				'item'   => $in['item'],
			)
		);
	}
	tkdesk_redirect_back(
		array(
			'tkdesk_done' => $result['public_id'],
			't'           => $result['done_token'],
		),
		array(
			'email'      => $result['email'],
			'status_url' => $result['status_url'],
		)
	);
}

/**
 * No-JS requester reply on the status page (admin-post.php, action tkdesk_details).
 */
function tkdesk_post_details() {
	$in      = tkdesk_posted( array( 'public_id', 'token', 'message' ) );
	$request = tkdesk_get_request_by_public_id( $in['public_id'] );
	$args    = array(
		'tkdesk_request' => $in['public_id'],
		'token'          => $in['token'],
	);
	if ( ! $request || ! tkdesk_verify_token( $request, $in['token'] ) ) {
		tkdesk_redirect_back( $args );
	}
	$result = tkdesk_add_requester_reply( (int) $request['id'], $in['message'] );
	$state  = array( 'sent' => true );
	if ( is_wp_error( $result ) ) {
		$state = array( 'values' => array( 'message' => $in['message'] ) );
		if ( 'tkdesk_missing_reply' === $result->get_error_code() ) {
			$state['errors'] = array( 'message' => __( 'Write a short reply before sending.', 'takedown-desk' ) );
		} elseif ( 'tkdesk_reply_limited' === $result->get_error_code() ) {
			$state['notice'] = $result->get_error_message();
		} elseif ( 'tkdesk_closed' !== $result->get_error_code() ) {
			$state['notice'] = tkdesk_form_messages()['server'];
		}
	}
	tkdesk_redirect_back( $args, $state );
}

/**
 * "Leave this page" link for the requester views (form, status, review). A plain link without
 * JavaScript; form.js moves it to the top of the page, leaves with location.replace() and clears
 * the forms, and Escape pressed twice does the same.
 *
 * @return string '' when the quick_exit setting is off.
 */
function tkdesk_quick_exit_html(): string {
	// Not in the block editor preview, where a fixed link would float over the canvas.
	if ( ! tkdesk_setting( 'quick_exit' ) || wp_is_serving_rest_request() ) {
		return '';
	}
	/**
	 * Filters where "Leave this page" goes. Pick a page that looks ordinary on a shared screen.
	 *
	 * @param string $url Default https://www.google.com/search?q=weather.
	 */
	$url = (string) apply_filters( 'tkdesk_quick_exit_url', 'https://www.google.com/search?q=weather' );
	return '<a class="tkd-exit" href="' . esc_url( $url ) . '" rel="noreferrer" data-tkd-exit>' . esc_html__( 'Leave this page', 'takedown-desk' ) . '</a>';
}

/**
 * Policy text for terms of service or community rules: one array( heading, text ) per section, the
 * first heading being the section title. Uses the site name and the notice page link.
 *
 * @return array[]
 */
function tkdesk_policy_sections(): array {
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$url  = tkdesk_notice_url();
	return array(
		array(
			__( 'Intimate images shared without consent', 'takedown-desk' ),
			/* translators: %s: site name. */
			sprintf( __( '%s does not allow anyone to post or share an intimate image or video of a person without their consent. This includes fake or altered images, such as AI deepfakes, that show a real, identifiable person.', 'takedown-desk' ), '' !== $site ? $site : __( 'This site', 'takedown-desk' ) ),
		),
		array(
			__( 'How to report it', 'takedown-desk' ),
			/* translators: %s: link to the removal request page. */
			sprintf( __( 'If you see an intimate image of yourself, or of someone you are authorized to act for, ask us to remove it here: %s. You do not need an account, and you do not need to send us the image.', 'takedown-desk' ), '' !== $url ? $url : __( '[link to your removal request page]', 'takedown-desk' ) ),
		),
		array(
			__( 'What happens next', 'takedown-desk' ),
			__( 'We review every request. If it is valid, we remove the image within 48 hours, along with any exact copies we find on this site, and we tell you what we decided. Please only send a request in good faith. We may decline requests that are false or made to harass someone.', 'takedown-desk' ),
		),
		array(
			__( 'What happens to people who break this rule', 'takedown-desk' ),
			__( 'We may remove their posts and suspend or close their accounts. Images of anyone under 18 are removed and may be reported to the National Center for Missing & Exploited Children (NCMEC).', 'takedown-desk' ),
		),
	);
}

/**
 * The policy as plain text, ready to paste: each heading on its own line, sections separated by a blank line.
 *
 * @return string
 */
function tkdesk_policy_text(): string {
	return implode(
		"\n\n",
		array_map(
			static function ( $s ) {
				return $s[0] . "\n" . $s[1];
			},
			tkdesk_policy_sections()
		)
	);
}

/**
 * POST /admin/policy-page: a new draft page with the policy text. Never published automatically.
 *
 * @return array|WP_Error { page_id, edit_url }
 */
function tkdesk_create_policy_page() {
	$url     = tkdesk_notice_url();
	$content = '';
	foreach ( tkdesk_policy_sections() as $i => $s ) {
		$text = htmlspecialchars( $s[1], ENT_NOQUOTES, 'UTF-8' );
		if ( '' !== $url ) {
			$text = str_replace( htmlspecialchars( $url, ENT_NOQUOTES, 'UTF-8' ), '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>', $text );
		}
		if ( $i ) {
			$content .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $s[0] ) . "</h2>\n<!-- /wp:heading -->\n\n";
		}
		$content .= "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => tkdesk_policy_sections()[0][0],
				'post_content' => tkdesk_styled_group( 'tkdesk-policy', trim( $content ) ),
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return array(
		'page_id'  => (int) $id,
		'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
	);
}
