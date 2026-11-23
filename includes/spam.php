<?php
/**
 * Spam protection without CAPTCHA: honeypot, signed time trap, rate limit, core disallowed list.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client IP: REMOTE_ADDR (forwarded headers are spoofable). Behind a proxy that does not restore the
 * visitor's address, a developer returns it through the tkdesk_client_ip filter.
 *
 * @return string Valid IP or ''.
 */
function tkdesk_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	/**
	 * Filters the visitor IP used for the rate limit, the duplicate guard and the stored IP hash.
	 *
	 * @param string $ip REMOTE_ADDR when valid, else ''.
	 */
	$ip = apply_filters( 'tkdesk_client_ip', filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '' );
	return is_string( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * HMAC of a form time stamp, keyed with the tkdesk-stamp-v1 subkey.
 *
 * @param int $ts Unix time.
 * @return string 64 hex.
 */
function tkdesk_stamp_sig( int $ts ): string {
	return hash_hmac( 'sha256', (string) $ts, tkdesk_subkey( 'tkdesk-stamp-v1' ) );
}

/**
 * Signed time stamp for the form's hidden fields.
 *
 * @return array array( 'ts' => int, 'sig' => hmac ).
 */
function tkdesk_form_stamp(): array {
	$ts = tkdesk_now();
	return array(
		'ts'  => $ts,
		'sig' => tkdesk_stamp_sig( $ts ),
	);
}

/**
 * Spam verdict for one submission whose fields are valid. Call inside the handler, never in a permission
 * callback. Counts nothing: the caller counts an accepted submission with tkdesk_rate_count().
 *
 * A real person can trip the time trap (autofill, then a quick send) or the stamp (the site key changed),
 * so once the bot check passes those only mark the request as spam: it is kept and can be recovered.
 *
 * @param array  $in Raw input.
 * @param string $ip Client IP.
 * @return string|WP_Error 'ok', 'silent' (honeypot filled: answer a fake success, save nothing), 'spam' (save
 *                         as spam: under 3 s, bad stamp or a disallowed-list hit), or WP_Error
 *                         tkdesk_form_expired (400) / a bot check error (400) / tkdesk_rate_limited (429).
 */
function tkdesk_spam_check( array $in, string $ip ) {
	if ( ! empty( $in['tkdesk_website'] ) ) {
		return 'silent';
	}
	$ts    = isset( $in['tkdesk_ts'] ) && is_scalar( $in['tkdesk_ts'] ) ? (int) $in['tkdesk_ts'] : 0;
	$sig   = isset( $in['tkdesk_sig'] ) && is_string( $in['tkdesk_sig'] ) ? $in['tkdesk_sig'] : '';
	$age   = tkdesk_now() - $ts;
	$stamp = hash_equals( tkdesk_stamp_sig( $ts ), $sig );
	if ( $stamp && $age > DAY_IN_SECONDS ) {
		return new WP_Error( 'tkdesk_form_expired', __( 'This form expired. Reload the page and send it again.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	$human = tkdesk_human_check( $in );
	if ( is_wp_error( $human ) ) {
		return $human;
	}
	if ( ! tkdesk_can( 'tkdesk_manage_requests' ) ) {
		$keys = array_keys( tkdesk_rate_keys( $ip ) );
		if ( (int) get_transient( $keys[0] ) >= 5 || (int) get_transient( $keys[1] ) >= 20 ) {
			return new WP_Error( 'tkdesk_rate_limited', tkdesk_form_messages()['rate'], array( 'status' => 429 ) );
		}
	}
	if ( ! $stamp || $age < 3 ) {
		return 'spam';
	}
	$text = '';
	foreach ( array( 'statement', 'details' ) as $key ) {
		$text .= isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? $in[ $key ] . "\n" : '';
	}
	return wp_check_comment_disallowed_list( '', '', '', $text, '', '' ) ? 'spam' : 'ok';
}

/**
 * Rate-limit transients for one IP (5 accepted requests an hour, 20 a day), with their lifetimes.
 *
 * @param string $ip Client IP.
 * @return array transient name => seconds; the hour counter first.
 */
function tkdesk_rate_keys( string $ip ): array {
	$hash = substr( tkdesk_hash( $ip ), 0, 16 );
	return array(
		'tkdesk_rl_h_' . $hash => HOUR_IN_SECONDS,
		'tkdesk_rl_d_' . $hash => DAY_IN_SECONDS,
	);
}

/**
 * Count one accepted submission toward the rate limit. Invalid or refused attempts never count.
 *
 * @param string $ip Client IP.
 */
function tkdesk_rate_count( string $ip ) {
	if ( tkdesk_can( 'tkdesk_manage_requests' ) ) {
		return;
	}
	foreach ( tkdesk_rate_keys( $ip ) as $key => $ttl ) {
		// ponytail: each accepted submission restarts the window; store window start if exact windows matter.
		set_transient( $key, (int) get_transient( $key ) + 1, $ttl );
	}
}

/**
 * Bot check (setting bot_check): a proof of work solved by form.js, or without JavaScript the answer to
 * the form's simple sum.
 *
 * @param array $in Raw input: tkdesk_pow, or tkdesk_q with tkdesk_answer.
 * @return true|WP_Error tkdesk_bot_check, tkdesk_question_expired or tkdesk_wrong_answer (400).
 */
function tkdesk_bot_check( array $in ) {
	$pow = isset( $in['tkdesk_pow'] ) && is_string( $in['tkdesk_pow'] ) ? $in['tkdesk_pow'] : '';
	$q   = isset( $in['tkdesk_q'] ) && is_string( $in['tkdesk_q'] ) ? $in['tkdesk_q'] : '';
	if ( '' === $pow && '' !== $q ) {
		return tkdesk_question_verify( $q, isset( $in['tkdesk_answer'] ) && is_scalar( $in['tkdesk_answer'] ) ? (string) $in['tkdesk_answer'] : '' );
	}
	if ( tkdesk_pow_verify( $pow ) ) {
		return true;
	}
	return new WP_Error( 'tkdesk_bot_check', __( 'We could not confirm that a person sent this form. Reload the page and send it again.', 'takedown-desk' ), array( 'status' => 400 ) );
}

/**
 * Which bot check the public forms use: the built-in one (setting bot_check, the default), Google reCAPTCHA
 * (bot_check off, recaptcha on, both keys saved), or none.
 *
 * @return string builtin | recaptcha | off
 */
function tkdesk_bot_mode(): string {
	if ( tkdesk_setting( 'bot_check' ) ) {
		return 'builtin';
	}
	if ( tkdesk_setting( 'recaptcha' ) && '' !== (string) tkdesk_setting( 'recaptcha_site_key' ) && '' !== tkdesk_recaptcha_secret() ) {
		return 'recaptcha';
	}
	return 'off';
}

/**
 * The bot check of the active mode for a submitted form.
 *
 * @param array $in Raw input.
 * @return true|WP_Error
 */
function tkdesk_human_check( array $in ) {
	$mode = tkdesk_bot_mode();
	if ( 'builtin' === $mode ) {
		return tkdesk_bot_check( $in );
	}
	if ( 'recaptcha' === $mode ) {
		// The review form carries tkdesk_review; everything else is the request form.
		return tkdesk_recaptcha_check( $in, empty( $in['tkdesk_review'] ) ? 'tkdesk_request' : 'tkdesk_review' );
	}
	return true;
}

/**
 * The saved reCAPTCHA secret key, decrypted; '' when none is saved or it cannot be read.
 *
 * @return string
 */
function tkdesk_recaptcha_secret(): string {
	$stored = (string) get_option( 'tkdesk_recaptcha_secret', '' );
	$secret = '' === $stored ? '' : tkdesk_decrypt( $stored );
	return is_string( $secret ) ? $secret : '';
}

/**
 * Save the reCAPTCHA secret key encrypted ('' removes it). It is never sent back to the browser.
 *
 * @param string $secret Secret key.
 */
function tkdesk_recaptcha_save_secret( string $secret ): void {
	if ( tkdesk_recaptcha_secret() === $secret ) {
		return;
	}
	if ( '' === $secret ) {
		delete_option( 'tkdesk_recaptcha_secret' );
	} else {
		update_option( 'tkdesk_recaptcha_secret', tkdesk_encrypt( $secret ), false );
	}
	delete_option( 'tkdesk_recaptcha_problem' );
	tkdesk_log( 0, 'settings_changed', array( 'keys' => array( 'recaptcha_secret' ) ) );
}

/**
 * Ask Google's siteverify endpoint about one token. Only the secret key and the token are sent (not the
 * visitor's IP address).
 *
 * @param string $secret Secret key.
 * @param string $token  Token from the form.
 * @return array|WP_Error Decoded answer, or WP_Error tkdesk_recaptcha_unreachable.
 */
function tkdesk_recaptcha_verify( string $secret, string $token ) {
	$res  = wp_remote_post(
		'https://www.google.com/recaptcha/api/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => $secret,
				'response' => $token,
			),
		)
	);
	$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $body ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'tkdesk_recaptcha_unreachable', __( 'Google reCAPTCHA could not be reached.', 'takedown-desk' ) );
	}
	return $body;
}

/**
 * Google reCAPTCHA check for one form. When Google cannot be reached or rejects the secret key, the form still goes
 * through (a removal request must never be lost) and the status card tells the site owner (option
 * tkdesk_recaptcha_problem); the time trap, honeypot and rate limit still apply.
 *
 * @param array  $in     Raw input with g-recaptcha-response.
 * @param string $action Expected v3 action: tkdesk_request or tkdesk_review.
 * @return true|WP_Error tkdesk_recaptcha (400) when the token is missing or fails.
 */
function tkdesk_recaptcha_check( array $in, string $action ) {
	$token = isset( $in['g-recaptcha-response'] ) && is_string( $in['g-recaptcha-response'] ) ? trim( $in['g-recaptcha-response'] ) : '';
	$v2    = 'v2' === tkdesk_setting( 'recaptcha_version' );
	$fail  = new WP_Error(
		'tkdesk_recaptcha',
		$v2 ? __( 'Check the "I\'m not a robot" box, then send the form again.', 'takedown-desk' ) : __( 'We could not confirm that a person sent this form. Reload the page and send it again.', 'takedown-desk' ),
		array( 'status' => 400 )
	);
	if ( '' === $token ) {
		return $fail;
	}
	$body = tkdesk_recaptcha_verify( tkdesk_recaptcha_secret(), $token );
	$bad  = is_wp_error( $body ) ? array() : array_intersect( (array) ( $body['error-codes'] ?? array() ), array( 'missing-input-secret', 'invalid-input-secret' ) );
	if ( is_wp_error( $body ) || $bad ) {
		update_option( 'tkdesk_recaptcha_problem', is_wp_error( $body ) ? 'unreachable' : 'secret', false );
		return true;
	}
	delete_option( 'tkdesk_recaptcha_problem' );
	if ( empty( $body['success'] ) ) {
		return $fail;
	}
	if ( ! $v2 && ( ( $body['action'] ?? '' ) !== $action || (float) ( $body['score'] ?? 0 ) < (int) tkdesk_setting( 'recaptcha_score' ) / 10 ) ) {
		return $fail;
	}
	return true;
}

/**
 * POST /admin/recaptcha/test: checks a secret key with Google (a dummy token is refused for the token, not
 * the key, when the key is right). The site key is checked by its format only.
 *
 * @param WP_REST_Request $request Request: secret ('' or missing: the saved one).
 * @return array|WP_Error { ok: true } or tkdesk_recaptcha_test (400).
 */
function tkdesk_rest_recaptcha_test( WP_REST_Request $request ) {
	$secret = trim( tkdesk_rest_str( $request, 'secret' ) );
	$secret = '' === $secret ? tkdesk_recaptcha_secret() : $secret;
	if ( '' === $secret ) {
		return new WP_Error( 'tkdesk_recaptcha_test', __( 'Add the secret key first.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	$body = tkdesk_recaptcha_verify( $secret, 'tkdesk-key-test' );
	if ( is_wp_error( $body ) ) {
		return new WP_Error( 'tkdesk_recaptcha_test', __( 'Google reCAPTCHA could not be reached from this site. Try again later.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	if ( array_intersect( (array) ( $body['error-codes'] ?? array() ), array( 'missing-input-secret', 'invalid-input-secret' ) ) ) {
		return new WP_Error( 'tkdesk_recaptcha_test', __( 'Google does not accept this secret key. Copy it again from the reCAPTCHA admin console.', 'takedown-desk' ), array( 'status' => 400 ) );
	}
	return array( 'ok' => true );
}

/**
 * A new proof-of-work challenge (ALTCHA format): find the number n in [0, maxnumber] with
 * sha256( salt . n ) = challenge. The fields are signed, so the server stores nothing until it is solved.
 *
 * @return array algorithm, salt, challenge, maxnumber, expires (unix), signature.
 */
function tkdesk_pow_challenge(): array {
	/**
	 * Filters the largest secret number; the browser's solve time grows with it.
	 *
	 * @param int $max Default 50000.
	 */
	$max  = max( 1, (int) apply_filters( 'tkdesk_pow_maxnumber', 50000 ) );
	$salt = bin2hex( random_bytes( 12 ) );
	$c    = array(
		'algorithm' => 'SHA-256',
		'salt'      => $salt,
		'challenge' => hash( 'sha256', $salt . random_int( 0, $max ) ),
		'maxnumber' => $max,
		'expires'   => tkdesk_now() + 2 * HOUR_IN_SECONDS,
	);
	return $c + array( 'signature' => tkdesk_pow_sig( $c ) );
}

/**
 * HMAC over every challenge field, keyed with the tkdesk-pow subkey.
 *
 * @param array $c Challenge fields.
 * @return string 64 hex.
 */
function tkdesk_pow_sig( array $c ): string {
	return hash_hmac( 'sha256', implode( '|', array( $c['algorithm'], $c['salt'], $c['challenge'], $c['maxnumber'], $c['expires'] ) ), tkdesk_subkey( 'tkdesk-pow' ) );
}

/**
 * Whether a solved challenge (JSON of the challenge plus `number`) is genuine, unexpired, correct
 * and used for the first time. A good one is spent at once.
 *
 * @param string $payload Solution from form.js.
 * @return bool
 */
function tkdesk_pow_verify( string $payload ): bool {
	$p    = json_decode( $payload, true );
	$p    = is_array( $p ) ? array_map( 'strval', array_filter( $p, 'is_scalar' ) ) : array();
	$need = array( 'algorithm', 'salt', 'challenge', 'maxnumber', 'expires', 'signature', 'number' );
	if ( array_diff( $need, array_keys( $p ) ) || ! ctype_digit( $p['number'] ) || (int) $p['expires'] < tkdesk_now() || ! hash_equals( tkdesk_pow_sig( $p ), $p['signature'] ) || ! hash_equals( $p['challenge'], hash( 'sha256', $p['salt'] . $p['number'] ) ) ) {
		return false;
	}
	// ponytail: read then write, so two copies sent in the same instant could both pass; atomic insert if that matters.
	$used = 'tkdesk_pow_' . substr( hash( 'sha256', $p['salt'] ), 0, 32 );
	if ( get_transient( $used ) ) {
		return false;
	}
	set_transient( $used, 1, max( MINUTE_IN_SECONDS, (int) $p['expires'] - tkdesk_now() ) );
	return true;
}

/**
 * The no-JavaScript question: two numbers from 1 to 9 and a token holding the signed answer for 24 hours,
 * so it works from a cached page.
 *
 * @return array a, b, token.
 */
function tkdesk_question(): array {
	$a       = wp_rand( 1, 9 );
	$b       = wp_rand( 1, 9 );
	$expires = tkdesk_now() + DAY_IN_SECONDS;
	return array(
		'a'     => $a,
		'b'     => $b,
		'token' => $expires . '.' . tkdesk_question_sig( $expires, (string) ( $a + $b ) ),
	);
}

/**
 * HMAC of a question's expiry and answer, keyed with the tkdesk-question subkey.
 *
 * @param int    $expires Unix time.
 * @param string $answer  The sum in digits.
 * @return string 64 hex.
 */
function tkdesk_question_sig( int $expires, string $answer ): string {
	return hash_hmac( 'sha256', $expires . '|' . $answer, tkdesk_subkey( 'tkdesk-question' ) );
}

/**
 * Check the answer to the no-JavaScript question.
 *
 * @param string $token  Token from tkdesk_question().
 * @param string $answer What the person typed.
 * @return true|WP_Error tkdesk_question_expired or tkdesk_wrong_answer (400, field tkdesk_answer).
 */
function tkdesk_question_verify( string $token, string $answer ) {
	$parts   = explode( '.', $token, 2 );
	$answer  = trim( $answer );
	$expired = (int) $parts[0] < tkdesk_now();
	if ( ! $expired && ctype_digit( $answer ) && hash_equals( tkdesk_question_sig( (int) $parts[0], (string) (int) $answer ), (string) ( $parts[1] ?? '' ) ) ) {
		return true;
	}
	$message = $expired ? __( 'This question expired. Answer the new question, or reload the page.', 'takedown-desk' ) : __( 'That answer is not right. Type the sum in digits, for example 12.', 'takedown-desk' );
	if ( ! $expired && '' === $answer ) {
		$message = __( 'Type the answer to the question.', 'takedown-desk' );
	}
	return new WP_Error(
		$expired ? 'tkdesk_question_expired' : 'tkdesk_wrong_answer',
		$message,
		array(
			'status' => 400,
			'fields' => array( 'tkdesk_answer' => $message ),
		)
	);
}
