<?php
/**
 * Front-end views: request form, confirmation, requester status page, bad link.
 *
 * Copy is plan 3.5. Every view returns escaped HTML; the router wraps it in .tkdesk.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decorative inline SVG icon (the text next to it carries the meaning).
 *
 * @param string $name error|info|check|dot|circle|lock.
 * @return string
 */
function tkdesk_icon( string $name ): string {
	$paths = array(
		'error'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
		'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
		'check'  => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8 5.7-5.8"/>',
		'dot'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4" fill="currentColor"/>',
		'circle' => '<circle cx="12" cy="12" r="9"/>',
		'lock'   => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/>',
	);
	return '<svg class="tkd-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
}

/**
 * Rate-limit and server-error copy; the email part appears only when fallback_email is set.
 *
 * @return array Keys rate, server.
 */
function tkdesk_form_messages(): array {
	$email = (string) tkdesk_setting( 'fallback_email' );
	$rate  = __( 'We already received several requests from this connection. To protect this form from abuse, please wait an hour before you send another one.', 'takedown-desk' );
	if ( ! is_email( $email ) ) {
		return array(
			'rate'   => $rate,
			'server' => __( 'We could not save your request. Nothing was lost on your side. Please try again.', 'takedown-desk' ),
		);
	}
	return array(
		/* translators: %s: site contact email address. */
		'rate'   => $rate . ' ' . sprintf( __( 'If it is urgent, email %s.', 'takedown-desk' ), $email ),
		/* translators: %s: site contact email address. */
		'server' => sprintf( __( 'We could not save your request. Nothing was lost on your side. Please try again, or email %s.', 'takedown-desk' ), $email ),
	);
}

/**
 * Email address shown on screen as s•••@example.com, for shared or watched screens.
 *
 * @param string $email Address, or ''.
 * @return string '' when there is no address.
 */
function tkdesk_mask_email( string $email ): string {
	$at = strrpos( $email, '@' );
	return $at ? mb_substr( $email, 0, 1 ) . '•••' . substr( $email, $at ) : '';
}

/**
 * Where to get help beyond this site, on every requester view.
 *
 * @return string
 */
function tkdesk_view_support(): string {
	$tab   = '<span class="tkd-sr"> ' . esc_html__( '(opens in a new tab)', 'takedown-desk' ) . '</span>';
	$links = array(
		/* translators: %s: link to the CCRI Crisis Helpline. */
		array( 'https://cybercivilrights.org/ccri-crisis-helpline/', 'CCRI Crisis Helpline', __( '%s: talk to someone at the Cyber Civil Rights Initiative.', 'takedown-desk' ) ),
		/* translators: %s: link to StopNCII.org. */
		array( 'https://stopncii.org/', 'StopNCII.org', __( '%s: helps stop intimate images of adults from being shared on partner platforms.', 'takedown-desk' ) ),
		/* translators: %s: link to NCMEC's Take It Down service. */
		array( 'https://takeitdown.ncmec.org/', 'Take It Down', __( '%s: for images taken when the person was under 18.', 'takedown-desk' ) ),
		/* translators: %s: link to Google's form for removing intimate images from Search. */
		array( 'https://support.google.com/websearch/answer/6302812', __( 'Remove it from Google Search', 'takedown-desk' ), __( '%s: ask Google to stop showing the image in its results.', 'takedown-desk' ) ),
	);
	$items = '';
	foreach ( $links as $l ) {
		$items .= '<li>' . sprintf( esc_html( $l[2] ), '<a href="' . esc_url( $l[0] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $l[1] ) . $tab . '</a>' ) . '</li>';
	}
	return '<div class="tkd-support"><h3>' . esc_html__( 'If you need support now', 'takedown-desk' ) . '</h3><ul>' . $items . '</ul></div>';
}

/**
 * Google reCAPTCHA in a public form (bot mode recaptcha). v2: Google's checkbox is rendered into the widget slot.
 * v3: no widget; Google's notice stands in for its floating badge, which would cover "Leave this page".
 * reCAPTCHA needs JavaScript, so without it the form says what to do.
 *
 * @return string
 */
function tkdesk_view_captcha(): string {
	$tab   = '<span class="tkd-sr"> ' . esc_html__( '(opens in a new tab)', 'takedown-desk' ) . '</span>';
	$email = (string) tkdesk_setting( 'fallback_email' );
	$nojs  = esc_html__( 'This form uses Google reCAPTCHA to check that a person is sending it, and that needs JavaScript. Turn on JavaScript, then reload this page.', 'takedown-desk' );
	if ( is_email( $email ) ) {
		/* translators: %s: site contact email address. */
		$nojs .= ' ' . sprintf( esc_html__( 'You can also email %s.', 'takedown-desk' ), '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' );
	}
	if ( 'v2' === tkdesk_setting( 'recaptcha_version' ) ) {
		$inner = '<div class="tkd-captcha__widget"></div>';
	} else {
		$inner = '<input type="hidden" name="g-recaptcha-response" value="">'
			. '<p class="tkd-help tkd-captcha__legal">' . sprintf(
				/* translators: 1: link to Google's Privacy Policy, 2: link to Google's Terms of Service. */
				esc_html__( 'This form is protected by reCAPTCHA, and the Google %1$s and %2$s apply.', 'takedown-desk' ),
				'<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'takedown-desk' ) . $tab . '</a>',
				'<a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'takedown-desk' ) . $tab . '</a>'
			) . '</p>';
	}
	return '<div class="tkd-captcha" data-tkd-captcha="' . esc_attr( (string) tkdesk_setting( 'recaptcha_version' ) ) . '">' . $inner
		. '<noscript><p class="tkd-box tkd-note">' . tkdesk_icon( 'info' ) . '<span>' . $nojs . '</span></p></noscript></div>';
}

/**
 * Three steps: received, review, decision. Values the JS confirmation fills in carry data-tkd-fill.
 *
 * @param array $request Request row, or status, received_at and due_at for a new request.
 * @return string
 */
function tkdesk_view_steps( array $request ): string {
	$status = (string) $request['status'];
	$closed = in_array( $status, array( 'removed', 'declined' ), true );
	$due    = tkdesk_ts( $request['due_at'] );
	$fill   = function ( string $key, string $text ): string {
		return '<span data-tkd-fill="' . $key . '">' . esc_html( $text ) . '</span>';
	};
	/* translators: %s: date and time the decision is due, with timezone. */
	$by     = sprintf( esc_html__( 'We will decide by %s.', 'takedown-desk' ), $fill( 'due', tkdesk_format_deadline( $due ) ) );
	$review = array( 'now', __( 'Being reviewed', 'takedown-desk' ), $by );
	$end    = array( 'next', __( 'Decision', 'takedown-desk' ), esc_html__( 'We will email you, so you do not need to do anything else.', 'takedown-desk' ) );
	if ( $closed ) {
		$review = array( 'done', __( 'Reviewed', 'takedown-desk' ), '' );
	} elseif ( 'needs_info' === $status ) {
		/* translators: %s: date and time the decision is due, with timezone. */
		$review = array( 'now', __( 'We need more details from you', 'takedown-desk' ), sprintf( esc_html__( 'Please answer the question below. We will still decide by %s.', 'takedown-desk' ), $fill( 'due', tkdesk_format_deadline( $due ) ) ) );
	} elseif ( $due < tkdesk_now() ) {
		$review[2] = esc_html__( 'This is taking longer than we planned. We are still working on it.', 'takedown-desk' );
	}
	if ( 'removed' === $status ) {
		/* translators: %s: date the image was removed. */
		$end = array( 'done', __( 'Removed', 'takedown-desk' ), esc_html( sprintf( __( 'We removed it on %s.', 'takedown-desk' ), wp_date( (string) get_option( 'date_format' ), tkdesk_ts( $request['resolved_at'] ) ) ) ) );
	} elseif ( 'declined' === $status ) {
		$reasons = tkdesk_decline_reasons();
		$end     = array( 'done', __( 'Not removed', 'takedown-desk' ), esc_html( $reasons[ (string) $request['decline_reason'] ] ?? '' ) );
	}
	$steps = array( array( 'done', __( 'Received', 'takedown-desk' ), $fill( 'received', tkdesk_format_deadline( tkdesk_ts( $request['received_at'] ) ) ) ), $review, $end );
	$label = array(
		'done' => __( 'Done:', 'takedown-desk' ),
		'now'  => __( 'Now:', 'takedown-desk' ),
		'next' => __( 'Next:', 'takedown-desk' ),
	);
	$icon  = array(
		'done' => 'check',
		'now'  => 'dot',
		'next' => 'circle',
	);
	$html  = '';
	foreach ( $steps as $s ) {
		$html .= '<li class="tkd-step is-' . $s[0] . '"' . ( 'now' === $s[0] ? ' aria-current="step"' : '' ) . '>' . tkdesk_icon( $icon[ $s[0] ] )
			. '<div><strong><span class="tkd-sr">' . esc_html( $label[ $s[0] ] ) . ' </span>' . esc_html( $s[1] ) . '</strong>'
			. ( '' === $s[2] ? '' : '<span>' . $s[2] . '</span>' ) . '</div></li>';
	}
	return '<ol class="tkd-steps">' . $html . '</ol>';
}

/**
 * One labelled field: label, help, error, control. Help and error are linked with aria-describedby.
 *
 * @param string $name  Field name.
 * @param array  $field Definition from tkdesk_form_fields().
 * @param mixed  $value Current value.
 * @param string $error Error message, '' when valid.
 * @return string
 */
function tkdesk_view_field( string $name, array $field, $value, string $error ): string {
	$id   = 'tkd-' . $name;
	$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
	$help = '';
	$ids  = array();
	if ( ! empty( $field['help'] ) ) {
		$ids[] = $id . '-help';
		$help  = '<p class="tkd-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $field['help'] ) . '</p>';
	}
	if ( '' !== $error ) {
		$ids[] = $id . '-error';
	}
	$err   = '<p class="tkd-error" id="' . esc_attr( $id ) . '-error"' . ( '' === $error ? ' hidden' : '' ) . '>' . tkdesk_icon( 'error' ) . '<span>' . esc_html( $error ) . '</span></p>';
	$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"'
		. ( empty( $field['required'] ) ? '' : ' required' )
		. ( empty( $field['autocomplete'] ) ? '' : ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '"' )
		. ( $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '' )
		. ( '' === $error ? '' : ' aria-invalid="true"' );
	$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';

	if ( 'checkbox' === $type ) {
		return '<div class="tkd-field tkd-check">' . $err . '<input type="checkbox" value="1"' . $attrs . ( empty( $value ) ? '' : ' checked' ) . '>' . $label . $help . '</div>';
	}
	if ( 'textarea' === $type ) {
		$control = '<textarea rows="4"' . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>';
	} elseif ( 'select' === $type ) {
		$control = '<select' . $attrs . '>';
		foreach ( (array) ( $field['options'] ?? array() ) as $key => $text ) {
			$control .= '<option value="' . esc_attr( $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		$control .= '</select>';
	} else {
		$control = '<input type="' . esc_attr( $type ) . '"' . $attrs . ' value="' . esc_attr( (string) $value ) . '">';
	}
	return '<div class="tkd-field">' . $label . $help . $err . $control . '</div>';
}

/**
 * The request form. Posts to admin-post.php; form.js swaps in REST.
 *
 * @param array $state values, errors (field => message), item (ref), notice (message).
 * @return string
 */
function tkdesk_view_form( array $state = array() ): string {
	$values = isset( $state['values'] ) ? (array) $state['values'] : array();
	$errors = isset( $state['errors'] ) ? (array) $state['errors'] : array();
	$notice = isset( $state['notice'] ) ? (string) $state['notice'] : '';
	$item   = isset( $state['item'] ) ? (string) $state['item'] : '';
	$target = '' === $item ? null : tkdesk_parse_item_ref( $item );
	$about  = $target ? tkdesk_describe( $target['object_type'], (int) $target['object_id'] ) : null;
	$intro  = '';
	if ( $about ) {
		/* translators: %s: title of the reported content, or "Reply by {name}". */
		$intro = '<p class="tkd-box tkd-item">' . esc_html( sprintf( __( 'You are reporting: %s', 'takedown-desk' ), $about['label'] ) ) . '</p>';
		if ( empty( $values['url'] ) ) {
			$values['url'] = $about['url'];
		}
	} else {
		$item = '';
	}

	$fields = '';
	foreach ( tkdesk_form_fields() as $name => $field ) {
		$html = tkdesk_view_field( $name, $field, $values[ $name ] ?? '', (string) ( $errors[ $name ] ?? '' ) );
		if ( 'url' === $name && $about ) {
			// The reported item already gives the link, so the field waits behind a toggle.
			$html = '<details class="tkd-change"' . ( isset( $errors['url'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Change the link', 'takedown-desk' ) . '</summary>' . $html . '</details>';
		} elseif ( 'email' === $name ) {
			/* translators: %d: number of days. */
			$kept = sprintf( __( 'Only the people who handle removal requests on this site. Your details are encrypted, and we delete them %d days after your request is closed.', 'takedown-desk' ), (int) tkdesk_setting( 'retention_days' ) );
			$html = '<div class="tkd-box tkd-privacy">' . tkdesk_icon( 'lock' ) . '<p><strong>' . esc_html__( 'Who sees this:', 'takedown-desk' ) . '</strong> ' . esc_html( $kept ) . '</p></div>' . $html;
		} elseif ( 'statement' === $name ) {
			// Shown by form.js: one tap fills the box, and the text stays editable.
			$line  = __( 'This image shows me and was shared without my consent.', 'takedown-desk' );
			$html .= '<button type="button" id="tkd-statement-chip" class="tkd-chip" data-tkd-text="' . esc_attr( $line ) . '" hidden>'
				/* translators: %s: a sentence the requester can use as their statement. */
				. esc_html( sprintf( __( 'Use this sentence: "%s"', 'takedown-desk' ), $line ) ) . '</button>';
		}
		$fields .= $html;
	}
	if ( tkdesk_setting( 'bot_check' ) ) {
		// Bot check without JavaScript; form.js removes it and solves a proof of work instead.
		$q       = tkdesk_question();
		$fields .= '<div class="tkd-q">' . tkdesk_view_field(
			'tkdesk_answer',
			array(
				'type'         => 'text',
				/* translators: 1, 2: numbers from 1 to 9. */
				'label'        => sprintf( __( 'What is %1$d plus %2$d?', 'takedown-desk' ), $q['a'], $q['b'] ),
				'help'         => __( 'Type the answer in digits. This shows that a person is sending the form.', 'takedown-desk' ),
				'required'     => true,
				'autocomplete' => 'off',
			),
			'',
			(string) ( $errors['tkdesk_answer'] ?? '' )
		) . '<input type="hidden" name="tkdesk_q" value="' . esc_attr( $q['token'] ) . '"></div>';
	} elseif ( 'recaptcha' === tkdesk_bot_mode() ) {
		$fields .= tkdesk_view_captcha();
	}
	$list = '';
	foreach ( $errors as $name => $message ) {
		$list .= '<li><a href="#tkd-' . esc_attr( $name ) . '">' . esc_html( $message ) . '</a></li>';
	}

	// A re-shown form keeps its first stamp so a quick correction is not caught by the 3-second trap.
	$stamp = empty( $values['tkdesk_ts'] ) || empty( $values['tkdesk_sig'] ) ? tkdesk_form_stamp() : array(
		'ts'  => $values['tkdesk_ts'],
		'sig' => $values['tkdesk_sig'],
	);
	$tab   = '<span class="tkd-sr"> ' . esc_html__( '(opens in a new tab)', 'takedown-desk' ) . '</span>';
	$note  = sprintf(
		/* translators: 1: link to NCMEC's Take It Down service, 2: link to StopNCII.org. */
		esc_html__( 'If the person in the image was under 18, you can also use %1$s. Adults can also use %2$s.', 'takedown-desk' ),
		'<a href="https://takeitdown.ncmec.org/" target="_blank" rel="noopener">' . esc_html__( 'NCMEC\'s Take It Down service', 'takedown-desk' ) . $tab . '</a>',
		'<a href="https://stopncii.org/" target="_blank" rel="noopener">StopNCII.org' . $tab . '</a>'
	);

	// On the notice page the page title and text already say what this is and that no account is needed,
	// so the default intro (setting form_intro left as shipped) keeps only the "do not upload" line there.
	$nid      = (int) tkdesk_setting( 'notice_page_id' );
	$on_page  = $nid && get_queried_object_id() === $nid;
	$fallback = (string) tkdesk_setting( 'fallback_email' );
	$lead     = (string) tkdesk_setting( 'form_intro' );
	if ( '' === $lead || 'You do not need an account. Do not upload or attach the image.' === $lead ) {
		$lead = $on_page ? __( 'Do not upload or attach the image.', 'takedown-desk' ) : __( 'You do not need an account. Do not upload or attach the image.', 'takedown-desk' );
	}
	$lead = esc_html( $lead );
	if ( is_email( $fallback ) ) {
		/* translators: %s: site contact email address. */
		$lead .= ' ' . sprintf( esc_html__( 'If you cannot use this form, email %s.', 'takedown-desk' ), '<a href="mailto:' . esc_attr( $fallback ) . '">' . esc_html( $fallback ) . '</a>' );
	}

	return '<form class="tkd-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate data-tkd-rest aria-labelledby="tkd-form-title">'
		. '<header class="tkd-head"><h2 class="tkd-title" id="tkd-form-title">' . ( $on_page ? esc_html__( 'Your request', 'takedown-desk' ) : esc_html__( 'Request removal of an intimate image', 'takedown-desk' ) ) . '</h2>'
		. '<p class="tkd-intro">' . $lead . '</p></header>'
		. '<div class="tkd-notice" role="alert" tabindex="-1"' . ( '' === $notice ? ' hidden' : ' autofocus' ) . '>' . tkdesk_icon( 'error' ) . '<span>' . esc_html( $notice ) . '</span></div>'
		. '<div class="tkd-summary" role="alert" tabindex="-1" aria-labelledby="tkd-summary-title"' . ( '' === $list ? ' hidden' : ' autofocus' ) . '>'
		. '<h3 id="tkd-summary-title">' . esc_html__( 'Check these fields', 'takedown-desk' ) . '</h3><ul>' . $list . '</ul></div>'
		. $intro
		. $fields
		. '<div class="tkd-hp" aria-hidden="true"><label for="tkd-website">' . esc_html__( 'Website', 'takedown-desk' ) . '</label><input type="text" id="tkd-website" name="tkdesk_website" value="" tabindex="-1" autocomplete="off"></div>'
		. '<input type="hidden" name="action" value="tkdesk_submit">'
		. '<input type="hidden" name="tkdesk_ts" value="' . esc_attr( $stamp['ts'] ) . '">'
		. '<input type="hidden" name="tkdesk_sig" value="' . esc_attr( $stamp['sig'] ) . '">'
		. '<input type="hidden" name="item" value="' . esc_attr( $item ) . '">'
		. '<input type="hidden" name="tkdesk_nonce" value="' . esc_attr( wp_create_nonce( 'tkdesk_submit' ) ) . '">'
		. '<p class="tkd-box tkd-note">' . tkdesk_icon( 'info' ) . '<span>' . $note . '</span></p>'
		. '<button type="submit" class="tkd-button wp-element-button">' . esc_html__( 'Send request', 'takedown-desk' ) . '</button>'
		. '</form>'
		// Placeholders so every optional part is there; form.js fills them from the response or drops them.
		. '<template class="tkd-done-tpl">' . tkdesk_view_confirmation( 'TD-0000-0000', 'x', tkdesk_now(), '#' ) . '</template>';
}

/**
 * Confirmation after a request was saved: the ID, the three steps, the private status link and support.
 * Optional parts are left out when empty; form.js fills the same markup from the REST response.
 *
 * @param string $public_id  Request ID.
 * @param string $email      Masked address the copy went to, '' when no copy was sent.
 * @param int    $received   Time the request arrived.
 * @param string $status_url Private status link, '' when unknown.
 * @return string
 */
function tkdesk_view_confirmation( string $public_id, string $email, int $received, string $status_url = '' ): string {
	$id   = '<span data-tkd-fill="public_id">' . esc_html( $public_id ) . '</span>';
	$copy = '';
	if ( '' !== $email ) {
		/* translators: %s: requester email address, partly hidden. */
		$copy = '<span data-tkd-need="email">' . sprintf( esc_html__( 'We sent a copy to %s.', 'takedown-desk' ), '<span data-tkd-fill="email">' . esc_html( $email ) . '</span>' ) . ' </span>';
	}
	$link = '';
	if ( '' !== $status_url ) {
		$link = '<p class="tkd-statuslink" data-tkd-need="status_url"><a data-tkd-href="status_url" href="' . esc_url( $status_url ) . '">' . esc_html__( 'Check the status of this request', 'takedown-desk' ) . '</a>'
			. '<span class="tkd-help">' . esc_html__( 'Bookmark the status page to check back later. The link is also in our email.', 'takedown-desk' ) . '</span></p>';
	}
	$steps = array(
		'status'      => 'new',
		'received_at' => tkdesk_mysql( $received ),
		'due_at'      => tkdesk_mysql( tkdesk_due( $received ) ),
	);
	/* translators: %s: request ID, for example TD-2611-0047. */
	$heading = sprintf( esc_html__( 'Request %s received', 'takedown-desk' ), $id );

	return '<div class="tkd-done">'
		. '<header class="tkd-head"><h2 class="tkd-title" tabindex="-1" autofocus>' . tkdesk_icon( 'check' ) . '<span>' . $heading . '</span></h2>'
		. '<p class="tkd-intro">' . $copy . esc_html__( 'Keep the request ID for your records.', 'takedown-desk' ) . '</p></header>'
		. tkdesk_view_steps( $steps )
		. '<p>' . esc_html__( 'You do not need to send another request for copies of the same image on this site. We look for them.', 'takedown-desk' ) . '</p>'
		. $link
		. tkdesk_view_support()
		. '</div>';
}

/**
 * Requester status page: the three steps, the messages so far, the reply form while the request needs
 * details, and support.
 *
 * @param array  $request Request row.
 * @param string $token   Raw requester token (already verified).
 * @param array  $state   sent (bool), notice, values, errors.
 * @return string
 */
function tkdesk_view_status( array $request, string $token, array $state = array() ): string {
	$status = (string) $request['status'];
	$html   = '';
	if ( ! empty( $state['sent'] ) ) {
		$html .= '<div class="tkd-notice tkd-notice--ok" role="status">' . tkdesk_icon( 'check' ) . '<span>' . esc_html__( 'Thanks. We added your reply to the request.', 'takedown-desk' ) . '</span></div>';
	}
	if ( ! empty( $state['notice'] ) ) {
		$html .= '<div class="tkd-notice" role="alert">' . tkdesk_icon( 'error' ) . '<span>' . esc_html( $state['notice'] ) . '</span></div>';
	}
	$html .= tkdesk_view_steps( $request );

	$text = tkdesk_request_text( $request );
	$msgs = '';
	foreach ( is_array( $text ) ? (array) ( $text['info_thread'] ?? array() ) : array() as $entry ) {
		$entry = (array) $entry;
		if ( isset( $entry['from'], $entry['text'] ) ) {
			$mine  = 'requester' === $entry['from'];
			$msgs .= '<li class="tkd-msg' . ( $mine ? ' is-mine' : '' ) . '"><strong>' . ( $mine ? esc_html__( 'Your reply', 'takedown-desk' ) : esc_html__( 'Our question', 'takedown-desk' ) ) . '</strong>'
				. '<p>' . nl2br( esc_html( (string) $entry['text'] ) ) . '</p></li>';
		}
	}
	if ( '' !== $msgs ) {
		$html .= '<div class="tkd-thread"><h3>' . esc_html__( 'Messages', 'takedown-desk' ) . '</h3><ul>' . $msgs . '</ul></div>';
	}

	if ( 'needs_info' === $status ) {
		$field = array(
			'type'     => 'textarea',
			'label'    => __( 'Your reply', 'takedown-desk' ),
			'help'     => sprintf(
				/* translators: %s: date and time the decision is due, with timezone. */
				__( 'Your reply goes straight to the reviewer. We will still decide by %s.', 'takedown-desk' ),
				tkdesk_format_deadline( tkdesk_ts( $request['due_at'] ) )
			),
			'required' => true,
		);
		$html .= '<form class="tkd-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate>'
			. '<input type="hidden" name="action" value="tkdesk_details">'
			. '<input type="hidden" name="public_id" value="' . esc_attr( $request['public_id'] ) . '">'
			. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">'
			. tkdesk_view_field( 'message', $field, $state['values']['message'] ?? '', (string) ( $state['errors']['message'] ?? '' ) )
			. '<button type="submit" class="tkd-button wp-element-button">' . esc_html__( 'Send my reply', 'takedown-desk' ) . '</button>'
			. '</form>';
	}

	$url = tkdesk_notice_url();
	if ( '' !== $url && in_array( $status, array( 'removed', 'declined' ), true ) ) {
		$again = 'removed' === $status ? __( 'If you see the image on this site again, send a new request.', 'takedown-desk' ) : __( 'If you think this is a mistake, or you have more details, reply to our email or send a new request.', 'takedown-desk' );
		$html .= '<p class="tkd-next">' . esc_html( $again ) . ' <a class="tkd-chiplink" href="' . esc_url( $url ) . '">' . esc_html__( 'Send a new request', 'takedown-desk' ) . '</a></p>';
	}

	/* translators: %s: request ID, for example TD-2611-0047. */
	$heading = sprintf( __( 'Request %s', 'takedown-desk' ), $request['public_id'] );

	return '<div class="tkd-status">'
		. '<header class="tkd-head"><h2 class="tkd-title" tabindex="-1">' . esc_html( $heading ) . '</h2></header>'
		. $html
		. tkdesk_view_support()
		. '</div>';
}

/**
 * Bad or expired status link: a calm way forward, never a dead end.
 *
 * @return string
 */
function tkdesk_view_bad_token(): string {
	$url      = tkdesk_notice_url();
	$fallback = (string) tkdesk_setting( 'fallback_email' );
	$html     = '<p>' . esc_html__( 'We send a new link each time we email you, so open our most recent email about your request.', 'takedown-desk' ) . '</p>';
	if ( '' !== $url ) {
		$html .= '<p class="tkd-next">' . esc_html__( 'Lost the email? Send a new request, and add your request ID to the details if you know it.', 'takedown-desk' ) . ' <a class="tkd-chiplink" href="' . esc_url( $url ) . '">' . esc_html__( 'Send a new request', 'takedown-desk' ) . '</a></p>';
	}
	if ( is_email( $fallback ) ) {
		/* translators: %s: site contact email address. */
		$html .= '<p>' . sprintf( esc_html__( 'You can also email us at %s.', 'takedown-desk' ), '<a href="mailto:' . esc_attr( $fallback ) . '">' . esc_html( $fallback ) . '</a>' ) . '</p>';
	}
	return '<div class="tkd-status tkd-badlink">'
		. '<header class="tkd-head"><h2 class="tkd-title" tabindex="-1">' . tkdesk_icon( 'info' ) . '<span>' . esc_html__( 'This link no longer works', 'takedown-desk' ) . '</span></h2></header>'
		. '<div class="tkd-box"><div>' . $html . '</div></div>'
		. tkdesk_view_support()
		. '</div>';
}
