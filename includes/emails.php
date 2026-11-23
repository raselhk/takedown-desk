<?php
/**
 * Emails: plain text with an HTML alternative from one template, no remote images.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moderator addresses: users holding tkdesk_manage_requests plus the extra_emails setting, unique.
 * Filter tkdesk_notify_recipients runs in tkdesk_send_email(), where the key and request are known.
 *
 * @return string[]
 */
function tkdesk_moderator_emails(): array {
	$emails = (array) tkdesk_setting( 'extra_emails' );
	foreach ( tkdesk_moderator_ids() as $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$emails[] = $user->user_email;
		}
	}
	return array_values( array_unique( array_filter( array_map( 'strtolower', array_filter( $emails, 'is_string' ) ), 'is_email' ) ) );
}

/**
 * The emails (plan 3.9, plus the under-18 confirmation): default subject and text with {fields}, the fields each one can fill in and
 * the fields it must keep. A saved subject or text that is empty means this default, so translations keep
 * working. Fields go in through %s, so translators never see or change a field name.
 *
 * @return array key => array( 'subject', 'body', 'tags' => string[], 'required' => string[] )
 */
function tkdesk_email_templates(): array {
	/* translators: %s: link to the request in the admin. */
	$open = sprintf( __( 'Open the request: %s', 'takedown-desk' ), '{admin_link}' );
	// Each: subject, paragraphs, fields beyond the shared ones, fields it must keep beyond {request_id}.
	$list = array(
		'requester_received'   => array(
			/* translators: %s: request ID. */
			sprintf( __( 'We received your removal request %s', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: date and time the request arrived, 2: request ID. */
				sprintf( __( 'We received your request on %1$s. Your request ID is %2$s.', 'takedown-desk' ), '{received_time}', '{request_id}' ),
				__( 'We review every request and act within 48 hours. We will email you the outcome.', 'takedown-desk' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can check the status or add details here: %s', 'takedown-desk' ), '{status_link}' ),
				/* translators: %s: site name. */
				sprintf( __( 'You do not need to report copies of the same image on %s. We look for them.', 'takedown-desk' ), '{site_name}' ),
				'{site_name}',
			),
			array( 'status_link' ),
			array(),
		),
		// Sent instead of requester_received when the requester says the person was under 18 (minors.php).
		'requester_minor'      => array(
			/* translators: %s: request ID. */
			sprintf( __( 'We received your removal request %s', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: date and time the request arrived, 2: request ID. */
				sprintf( __( 'We received your request on %1$s. Your request ID is %2$s.', 'takedown-desk' ), '{received_time}', '{request_id}' ),
				__( 'Thank you for telling us the person in the image was under 18. We handle these requests with extra care. We review every request and act within 48 hours, and we will email you the outcome.', 'takedown-desk' ),
				/* translators: %s: address of NCMEC's Take It Down service. */
				sprintf( __( 'NCMEC\'s free Take It Down service can help stop the image from spreading on other sites that take part: %s', 'takedown-desk' ), 'https://takeitdown.ncmec.org/' ),
				/* translators: %s: address of the NCMEC CyberTipline. */
				sprintf( __( 'You can also report the image to the NCMEC CyberTipline: %s', 'takedown-desk' ), 'https://report.cybertip.org/' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can check the status or add details here: %s', 'takedown-desk' ), '{status_link}' ),
				'{site_name}',
			),
			array( 'status_link' ),
			array(),
		),
		'requester_needs_info' => array(
			/* translators: %s: request ID. */
			sprintf( __( 'We need a few details for request %s', 'takedown-desk' ), '{request_id}' ),
			array(
				__( 'To find the image you reported, we need a little more information:', 'takedown-desk' ),
				'{question}',
				/* translators: %s: private status link. */
				sprintf( __( 'Reply here: %s', 'takedown-desk' ), '{status_link}' ),
				__( 'We keep working on your request while we wait.', 'takedown-desk' ),
			),
			array( 'question', 'status_link' ),
			array( 'question', 'status_link' ),
		),
		'requester_removed'    => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Your removal request %s: Removed', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: what was removed, such as "the image you reported and 2 identical copies", 2: site name, 3: date and time, 4: time taken. */
				sprintf( __( 'We removed %1$s from %2$s on %3$s, %4$s after your request.', 'takedown-desk' ), '{removed_items}', '{site_name}', '{removed_time}', '{time_taken}' ),
				/* translators: %s: link to the removal request page. */
				sprintf( __( 'If you see it on this site again, tell us here: %s', 'takedown-desk' ), '{notice_link}' ),
				'{redress}',
				tkdesk_email_aftercare(),
			),
			array( 'removed_items', 'copies', 'removed_time', 'time_taken', 'notice_link', 'redress' ),
			array(),
		),
		'requester_declined'   => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Your removal request %s: Not removed', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: %s: reason sentence. */
				sprintf( __( 'We reviewed your request and did not remove the item. Reason: %s', 'takedown-desk' ), '{reason}' ),
				'{message}',
				// A closed request takes no replies on its status page, so the way back is email or a new request.
				/* translators: %s: link to the removal request page. */
				sprintf( __( 'If you think this is a mistake, or you have more details, reply to this email or send a new request: %s', 'takedown-desk' ), '{notice_link}' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can see the status of your request here: %s', 'takedown-desk' ), '{status_link}' ),
				'{redress}',
				tkdesk_email_aftercare(),
			),
			array( 'reason', 'message', 'status_link', 'notice_link', 'redress' ),
			array( 'reason' ),
		),
		'mod_new'              => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: Due in 48 hours', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: item label, 2: deadline with timezone. */
				sprintf( __( 'A removal request arrived for %1$s. The deadline is %2$s.', 'takedown-desk' ), '{item}', '{deadline}' ),
				$open,
				__( 'We will remind you at 24 and 40 hours if it is still open.', 'takedown-desk' ),
			),
			array( 'hours_left' ),
			array(),
		),
		'mod_24h'              => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: 24 hours left', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: request ID, 2: item label, 3: deadline. */
				sprintf( __( 'Request %1$s for %2$s is still open. The deadline is %3$s.', 'takedown-desk' ), '{request_id}', '{item}', '{deadline}' ),
				$open,
			),
			array( 'hours_left' ),
			array(),
		),
		'mod_40h'              => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: 8 hours left', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: request ID, 2: item label, 3: deadline. */
				sprintf( __( 'Request %1$s for %2$s is still open and due at %3$s. Please act now.', 'takedown-desk' ), '{request_id}', '{item}', '{deadline}' ),
				$open,
			),
			array( 'hours_left' ),
			array(),
		),
		'mod_overdue'          => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: Overdue', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: request ID, 2: deadline. */
				sprintf( __( 'Request %1$s passed its 48-hour deadline at %2$s and is still open. Act on it as soon as you can and record the reason for the delay in the decision note.', 'takedown-desk' ), '{request_id}', '{deadline}' ),
				$open,
			),
			array(),
			array(),
		),
		'mod_reply'            => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: The requester replied', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: request ID, 2: deadline. */
				sprintf( __( 'The requester added details to request %1$s. The deadline is still %2$s.', 'takedown-desk' ), '{request_id}', '{deadline}' ),
				$open,
			),
			array( 'hours_left' ),
			array(),
		),
		// Once, while a request is still open 6 days after it arrived (Cal. Bus. & Prof. Code 22671(b)(2), poster.php).
		'requester_update'     => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Your removal request %s: Still in review', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: %s: date and time the request arrived. */
				sprintf( __( 'Your request is still open. We received it on %s and have not made a final decision yet.', 'takedown-desk' ), '{received_time}' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can check the status or add details here: %s', 'takedown-desk' ), '{status_link}' ),
				__( 'We will email you as soon as we decide.', 'takedown-desk' ),
				'{site_name}',
			),
			array( 'status_link' ),
			array(),
		),
		// To the person who posted removed content (poster.php). Never any requester data or requester text.
		'poster_removed'       => array(
			/* translators: %s: site name. */
			sprintf( __( 'We removed content you posted on %s', 'takedown-desk' ), '{site_name}' ),
			array(
				/* translators: 1: date and time of the removal, 2: site name. */
				sprintf( __( 'On %1$s, we removed content you posted on %2$s:', 'takedown-desk' ), '{removed_time}', '{site_name}' ) . "\n{poster_items}",
				/* translators: %s: request ID. */
				sprintf( __( 'Why: we received a request to remove an intimate image shared without consent, under the Take It Down Act, a US law. A moderator reviewed the request and removed the content. Reference: %s.', 'takedown-desk' ), '{request_id}' ),
				__( 'We do not share who sent the request.', 'takedown-desk' ),
				/* translators: %s: private review link. */
				sprintf( __( 'If you think this was a mistake, ask for a review here: %s', 'takedown-desk' ), '{review_link}' ),
				__( 'A moderator will look at it again. The content stays offline while they review it.', 'takedown-desk' ),
				'{redress}',
				'{site_name}',
			),
			array( 'poster_items', 'removed_time', 'review_link', 'redress' ),
			array( 'review_link' ),
		),
		'mod_review'           => array(
			/* translators: %s: request ID. */
			sprintf( __( 'Removal request %s: Review requested', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: %s: request ID. */
				sprintf( __( 'The person who posted the content removed under request %s asked for a review. The content stays offline until you decide.', 'takedown-desk' ), '{request_id}' ),
				$open,
			),
			array(),
			array(),
		),
		// Copyright notices (setting dmca): tkdesk_email_key() picks these instead of the ones above.
		'dmca_received'        => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'We received your copyright notice %s', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: date and time the notice arrived, 2: notice ID. */
				sprintf( __( 'We received your copyright notice on %1$s. Its ID is %2$s.', 'takedown-desk' ), '{received_time}', '{request_id}' ),
				/* translators: %s: target date and time. */
				sprintf( __( 'We review every notice and aim to decide by %s. We will email you the outcome.', 'takedown-desk' ), '{deadline}' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can check the status or add details here: %s', 'takedown-desk' ), '{status_link}' ),
				'{site_name}',
			),
			array( 'status_link' ),
			array(),
		),
		'dmca_needs_info'      => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'We need a few details for copyright notice %s', 'takedown-desk' ), '{request_id}' ),
			array(
				__( 'To act on your copyright notice, we need a little more information:', 'takedown-desk' ),
				'{question}',
				/* translators: %s: private status link. */
				sprintf( __( 'Reply here: %s', 'takedown-desk' ), '{status_link}' ),
				__( 'We keep working on your notice while we wait.', 'takedown-desk' ),
			),
			array( 'question', 'status_link' ),
			array( 'question', 'status_link' ),
		),
		'dmca_removed'         => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'Your copyright notice %s: Removed', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: site name, 2: date and time of the removal. */
				sprintf( __( 'We removed the material named in your notice from %1$s on %2$s.', 'takedown-desk' ), '{site_name}', '{removed_time}' ),
				/* translators: %s: link to the copyright notice page. */
				sprintf( __( 'If you see it on this site again, send a new notice: %s', 'takedown-desk' ), '{notice_link}' ),
				'{redress}',
			),
			array( 'copies', 'removed_time', 'time_taken', 'notice_link', 'redress' ),
			array(),
		),
		'dmca_declined'        => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'Your copyright notice %s: Not removed', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: %s: reason sentence. */
				sprintf( __( 'We reviewed your notice and did not remove the material. Reason: %s', 'takedown-desk' ), '{reason}' ),
				'{message}',
				/* translators: %s: link to the copyright notice page. */
				sprintf( __( 'If you think this is a mistake, or you have more details, reply to this email or send a new notice: %s', 'takedown-desk' ), '{notice_link}' ),
				/* translators: %s: private status link. */
				sprintf( __( 'You can see the status of your notice here: %s', 'takedown-desk' ), '{status_link}' ),
				'{redress}',
			),
			array( 'reason', 'message', 'status_link', 'notice_link', 'redress' ),
			array( 'reason' ),
		),
		// Never names who sent the notice.
		'dmca_poster_removed'  => array(
			/* translators: %s: site name. */
			sprintf( __( 'We removed content you posted on %s', 'takedown-desk' ), '{site_name}' ),
			array(
				/* translators: 1: date and time of the removal, 2: site name. */
				sprintf( __( 'On %1$s, we removed content you posted on %2$s:', 'takedown-desk' ), '{removed_time}', '{site_name}' ) . "\n{poster_items}",
				/* translators: %s: notice ID. */
				sprintf( __( 'Why: we received a copyright notice about this content. A moderator reviewed the notice and removed the content. Reference: %s.', 'takedown-desk' ), '{request_id}' ),
				/* translators: %s: private review link. */
				sprintf( __( 'If you think this was a mistake, ask for a review here: %s', 'takedown-desk' ), '{review_link}' ),
				__( 'A moderator will look at it again. The content stays offline while they review it.', 'takedown-desk' ),
				'{redress}',
				'{site_name}',
			),
			array( 'poster_items', 'removed_time', 'review_link', 'redress' ),
			array( 'review_link' ),
		),
		'mod_dmca_new'         => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'Copyright notice %s: Received', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: item label, 2: target date and time with timezone. */
				sprintf( __( 'A copyright notice arrived for %1$s. The target is %2$s.', 'takedown-desk' ), '{item}', '{deadline}' ),
				$open,
				__( 'We will remind you halfway to the target and again shortly before it, if it is still open.', 'takedown-desk' ),
			),
			array( 'hours_left' ),
			array(),
		),
		'mod_dmca_reminder'    => array(
			/* translators: 1: notice ID, 2: time left, e.g. 17 h 42 min. */
			sprintf( __( 'Copyright notice %1$s: %2$s left', 'takedown-desk' ), '{request_id}', '{time_left}' ),
			array(
				/* translators: 1: notice ID, 2: item label, 3: target date and time, 4: time left. */
				sprintf( __( 'Copyright notice %1$s for %2$s is still open. The target is %3$s, %4$s from now.', 'takedown-desk' ), '{request_id}', '{item}', '{deadline}', '{time_left}' ),
				$open,
			),
			array( 'hours_left', 'time_left' ),
			array(),
		),
		'mod_dmca_overdue'     => array(
			/* translators: %s: notice ID. */
			sprintf( __( 'Copyright notice %s: Past the target', 'takedown-desk' ), '{request_id}' ),
			array(
				/* translators: 1: notice ID, 2: target date and time. */
				sprintf( __( 'Copyright notice %1$s passed its target at %2$s and is still open. Act on it as soon as you can.', 'takedown-desk' ), '{request_id}', '{deadline}' ),
				$open,
			),
			array(),
			array(),
		),
	);

	$out = array();
	foreach ( $list as $key => $t ) {
		// The poster emails get no request times: what they may say is fixed by poster.php.
		$base        = false !== strpos( $key, 'poster_' ) ? array( 'site_name', 'site_url', 'request_id' ) : array( 'site_name', 'site_url', 'request_id', 'received_time', 'deadline' );
		$mod         = 0 === strpos( $key, 'mod_' ) ? array( 'item', 'admin_link' ) : array();
		$out[ $key ] = array(
			'subject'  => $t[0],
			'body'     => implode( "\n\n", $t[1] ),
			'tags'     => array_merge( $base, $mod, $t[2] ),
			'required' => array_merge( array( 'request_id' ), $t[3] ),
		);
		// The Settings list groups these and shows them only while setting dmca is on.
		if ( false !== strpos( $key, 'dmca_' ) ) {
			$out[ $key ]['type'] = 'dmca';
		}
	}
	return $out;
}

/**
 * Aftercare lines for the outcome emails: where else to ask for removal (plain text, one per line).
 *
 * @return string
 */
function tkdesk_email_aftercare(): string {
	return implode(
		"\n",
		array(
			__( 'If the image is also on other sites, these free services can help:', 'takedown-desk' ),
			/* translators: %s: address of Google's form for removing intimate images from Search. */
			sprintf( __( 'Ask Google to remove it from Search results: %s', 'takedown-desk' ), 'https://support.google.com/websearch/answer/6302812' ),
			/* translators: %s: StopNCII.org address. */
			sprintf( __( 'StopNCII.org, for adults: %s', 'takedown-desk' ), 'https://stopncii.org/' ),
			/* translators: %s: address of NCMEC's Take It Down service. */
			sprintf( __( 'NCMEC Take It Down, if you were under 18 in the image: %s', 'takedown-desk' ), 'https://takeitdown.ncmec.org/' ),
		)
	);
}

/**
 * What each field fills in, for the template editor.
 *
 * @return array tag => description.
 */
function tkdesk_email_tag_labels(): array {
	return array(
		'site_name'     => __( 'Your site name', 'takedown-desk' ),
		'site_url'      => __( 'Link to your home page', 'takedown-desk' ),
		'request_id'    => __( 'The request ID', 'takedown-desk' ),
		'received_time' => __( 'Date and time the request arrived', 'takedown-desk' ),
		'deadline'      => __( 'Date and time the 48 hours end', 'takedown-desk' ),
		'status_link'   => __( 'Private link where the requester checks the status and replies', 'takedown-desk' ),
		'question'      => __( 'Your question to the requester', 'takedown-desk' ),
		'reason'        => __( 'The reason you chose, as a full sentence', 'takedown-desk' ),
		'message'       => __( 'Your optional message to the requester', 'takedown-desk' ),
		'removed_items' => __( 'What was removed, including any identical copies', 'takedown-desk' ),
		'copies'        => __( 'Number of identical copies removed with it', 'takedown-desk' ),
		'removed_time'  => __( 'Date and time of the removal', 'takedown-desk' ),
		'time_taken'    => __( 'Time from the request to the removal', 'takedown-desk' ),
		'notice_link'   => __( 'Link to your removal request page', 'takedown-desk' ),
		'item'          => __( 'The reported item, such as a post title', 'takedown-desk' ),
		'admin_link'    => __( 'Link to the request in your dashboard', 'takedown-desk' ),
		'hours_left'    => __( 'Whole hours left before the deadline', 'takedown-desk' ),
		'time_left'     => __( 'Time left before the target, in hours and minutes', 'takedown-desk' ),
		'poster_items'  => __( 'What was removed from the person who posted it, one item per line', 'takedown-desk' ),
		'review_link'   => __( 'Private link where the person who posted it asks for a review', 'takedown-desk' ),
		'redress'       => __( 'How to contest the decision, when "This site has visitors in the EU" is on', 'takedown-desk' ),
	);
}

/**
 * The value of every field for one request, as plain text ('' when there is nothing to show).
 *
 * @param array $request Request row.
 * @param array $vars    question, message, reason, removed_count, token, duplicate_of, poster_items, review_link.
 * @return array tag => string.
 */
function tkdesk_email_values( array $request, array $vars = array() ): array {
	$vars      = wp_parse_args(
		$vars,
		array(
			'question'      => '',
			'message'       => '',
			'reason'        => '',
			'removed_count' => 0,
			'token'         => '',
			'duplicate_of'  => '',
			'poster_items'  => '',
			'review_link'   => '',
		)
	);
	$received  = tkdesk_ts( $request['received_at'] );
	$due       = tkdesk_ts( $request['due_at'] );
	$resolved  = empty( $request['resolved_at'] ) ? tkdesk_now() : tkdesk_ts( $request['resolved_at'] );
	$copies    = (int) $vars['removed_count'];
	$reason    = '' !== $vars['reason'] ? $vars['reason'] : (string) ( $request['decline_reason'] ?? '' );
	$label     = (string) ( $request['item_label'] ?? '' );
	$copyright = 'dmca' === ( $request['type'] ?? '' ) && function_exists( 'tkdesk_copyright_url' ) ? tkdesk_copyright_url() : '';
	return array(
		'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'site_url'      => home_url( '/' ),
		'request_id'    => (string) $request['public_id'],
		'received_time' => tkdesk_format_deadline( $received ),
		'deadline'      => tkdesk_format_deadline( $due ),
		'status_link'   => '' !== $vars['token'] ? tkdesk_status_url( $request, $vars['token'] ) : '',
		'question'      => (string) $vars['question'],
		'reason'        => tkdesk_decline_reasons( (string) $vars['duplicate_of'], (string) ( $request['type'] ?? 'ncii' ) )[ $reason ] ?? '',
		'message'       => (string) $vars['message'],
		/* translators: %d: number of identical copies. */
		'removed_items' => $copies > 0 ? sprintf( _n( 'the image you reported and %d identical copy', 'the image you reported and %d identical copies', $copies, 'takedown-desk' ), $copies ) : __( 'the image you reported', 'takedown-desk' ),
		'copies'        => (string) $copies,
		'removed_time'  => tkdesk_format_deadline( $resolved ),
		'time_taken'    => tkdesk_duration_text( $resolved - $received ),
		// A copyright notice points back to the copyright notice page.
		'notice_link'   => '' !== $copyright ? $copyright : tkdesk_notice_url(),
		'item'          => '' !== $label ? $label : __( 'a link that is not matched to an item yet', 'takedown-desk' ),
		'admin_link'    => tkdesk_admin_request_url( $request ),
		'hours_left'    => (string) intdiv( max( 0, $due - tkdesk_now() ), HOUR_IN_SECONDS ),
		'time_left'     => tkdesk_duration_text( max( 0, $due - tkdesk_now() ) ),
		'poster_items'  => (string) $vars['poster_items'],
		'review_link'   => (string) $vars['review_link'],
		// DSA Art. 16(5) and 17(3)(f): how to contest the decision, only when the site has visitors in the EU.
		'redress'       => tkdesk_setting( 'eu_visitors' ) ? __( 'If you disagree with this decision, you can also take it to a court or, if you are in the EU, to an out-of-court dispute settlement body under the Digital Services Act.', 'takedown-desk' ) : '',
	);
}

/**
 * Replaces the known {fields} in a text with plain-text values in one pass (a value is never read as a
 * field). Unknown {fields} stay as typed. With $drop, a line whose fields all came out empty is left out,
 * such as a link line when there is no link.
 *
 * @param string $text   Text with {fields}.
 * @param array  $values tag => string, only the fields this email may fill in.
 * @param bool   $drop   Leave out lines whose fields are all empty.
 * @return string
 */
function tkdesk_email_fill( string $text, array $values, bool $drop = true ): string {
	$out = array();
	foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) ) as $line ) {
		$found  = 0;
		$filled = 0;
		$line   = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			function ( $m ) use ( $values, &$found, &$filled ) {
				if ( ! array_key_exists( $m[1], $values ) ) {
					return $m[0];
				}
				++$found;
				$filled += '' === $values[ $m[1] ] ? 0 : 1;
				return $values[ $m[1] ];
			},
			$line
		);
		if ( ! $drop || ! $found || $filled ) {
			$out[] = $line;
		}
	}
	return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
}

/**
 * The {fields} a text uses.
 *
 * @param string $text Text.
 * @return string[]
 */
function tkdesk_email_used( string $text ): array {
	preg_match_all( '/\{([a-z0-9_]+)\}/', $text, $m );
	return array_values( array_unique( $m[1] ) );
}

/**
 * Saved state of one email: on or off, and its own subject and text ('' = the default).
 *
 * @param string $key Email key.
 * @return array array( 'active' => bool, 'subject' => string, 'body' => string ).
 */
function tkdesk_email_saved( string $key ): array {
	$all = (array) tkdesk_setting( 'emails' );
	return wp_parse_args(
		isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array(),
		array(
			'active'  => true,
			'subject' => '',
			'body'    => '',
		)
	);
}

/**
 * Field errors for one email's subject and text, as typed in Settings (empty = the default).
 *
 * @param string $key Email key.
 * @param array  $in  subject, body.
 * @return array field (subject|body) => message.
 */
function tkdesk_email_errors( string $key, array $in ): array {
	$tpl = tkdesk_email_templates()[ $key ] ?? null;
	if ( ! $tpl ) {
		return array( 'subject' => __( 'Choose one of the listed emails.', 'takedown-desk' ) );
	}
	$text   = array(
		'subject' => is_scalar( $in['subject'] ?? '' ) ? (string) ( $in['subject'] ?? '' ) : '',
		'body'    => is_scalar( $in['body'] ?? '' ) ? (string) ( $in['body'] ?? '' ) : '',
	);
	$errors = array();
	foreach ( array(
		'subject' => 200,
		'body'    => 5000,
	) as $field => $max ) {
		$unknown = array_diff( tkdesk_email_used( $text[ $field ] ), $tpl['tags'] );
		if ( 'subject' === $field && preg_match( '/[\r\n]/', $text[ $field ] ) ) {
			$errors[ $field ] = __( 'Keep the subject on one line.', 'takedown-desk' );
		} elseif ( mb_strlen( trim( $text[ $field ] ) ) > $max ) {
			/* translators: %d: maximum number of characters. */
			$errors[ $field ] = sprintf( __( 'Use %d characters or fewer.', 'takedown-desk' ), $max );
		} elseif ( $unknown ) {
			/* translators: %s: list of fields such as {item}. */
			$errors[ $field ] = sprintf( _n( 'This email cannot fill in %s. Remove it or pick a field from the list.', 'This email cannot fill in %s. Remove them or pick fields from the list.', count( $unknown ), 'takedown-desk' ), '{' . implode( '}, {', $unknown ) . '}' );
		}
	}
	$all     = ( '' !== trim( $text['subject'] ) ? $text['subject'] : $tpl['subject'] ) . "\n" . ( '' !== trim( $text['body'] ) ? $text['body'] : $tpl['body'] );
	$missing = array_diff( $tpl['required'], tkdesk_email_used( $all ) );
	if ( $missing && ! isset( $errors['body'] ) ) {
		/* translators: %s: list of fields such as {request_id}. */
		$errors['body'] = sprintf( _n( 'This email must include %s. Add it back to the subject or the text.', 'This email must include %s. Add them back to the subject or the text.', count( $missing ), 'takedown-desk' ), '{' . implode( '}, {', $missing ) . '}' );
	}
	return $errors;
}

/**
 * Sanitizes the emails setting: key => array( active, subject, body ). The subject is one line of up to
 * 200 characters, the text up to 5000. Text equal to the default is stored as '' (the translatable
 * default), and so is any text that drops a field its email must keep. Emails left fully at their
 * defaults are not stored.
 *
 * @param mixed $in Raw value.
 * @return array
 */
function tkdesk_sanitize_emails( $in ): array {
	$in  = is_array( $in ) ? $in : array();
	$out = array();
	foreach ( tkdesk_email_templates() as $key => $tpl ) {
		$t       = isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? $in[ $key ] : array();
		$subject = is_scalar( $t['subject'] ?? '' ) ? (string) ( $t['subject'] ?? '' ) : '';
		$body    = is_scalar( $t['body'] ?? '' ) ? (string) ( $t['body'] ?? '' ) : '';
		$subject = mb_substr( sanitize_text_field( $subject ), 0, 200 );
		$body    = trim( mb_substr( sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $body ) ), 0, 5000 ) );
		$subject = $tpl['subject'] === $subject ? '' : $subject;
		$body    = $tpl['body'] === $body ? '' : $body;
		if ( array_diff( $tpl['required'], tkdesk_email_used( ( '' !== $subject ? $subject : $tpl['subject'] ) . "\n" . ( '' !== $body ? $body : $tpl['body'] ) ) ) ) {
			$subject = '';
			$body    = '';
		}
		$active = ! isset( $t['active'] ) || rest_sanitize_boolean( $t['active'] );
		if ( ! $active || '' !== $subject || '' !== $body ) {
			$out[ $key ] = array(
				'active'  => $active,
				'subject' => $subject,
				'body'    => $body,
			);
		}
	}
	return $out;
}

/**
 * Sample request and values for Preview, Send me a test and the editor's live preview.
 *
 * @return array array( 'request' => row, 'vars' => template vars ).
 */
function tkdesk_email_sample(): array {
	$received = tkdesk_now() - 6 * HOUR_IN_SECONDS;
	return array(
		'request' => array(
			'id'             => 0,
			'public_id'      => 'TD-' . wp_date( 'ym', $received ) . '-0000',
			'item_label'     => __( 'Sample forum post', 'takedown-desk' ),
			'received_at'    => tkdesk_mysql( $received ),
			'due_at'         => tkdesk_mysql( tkdesk_due( $received ) ),
			'resolved_at'    => null,
			'decline_reason' => 'not_found',
			'locale'         => get_user_locale(),
			'is_test'        => 1,
		),
		'vars'    => array(
			'question'      => __( 'Which page shows the image? Paste its link.', 'takedown-desk' ),
			'message'       => __( 'We checked the page you linked and every image on it.', 'takedown-desk' ),
			'removed_count' => 2,
			'token'         => 'sample',
			'sample'        => true,
			'poster_items'  => '- ' . __( 'Sample forum post', 'takedown-desk' ),
			'review_link'   => tkdesk_notice_url(
				array(
					'tkdesk_review' => 'TD-' . wp_date( 'ym', $received ) . '-0000',
					'token'         => 'sample',
				)
			),
		),
	);
}

/**
 * Subject and plain-text body for one email: the saved subject and text, or the default, with the fields
 * this email may use filled in. The subject is always one line.
 *
 * @param string $key     Email key.
 * @param array  $request Request row.
 * @param array  $vars    question, message, reason, removed_count, token, duplicate_of.
 * @return array array( 'subject', 'body' ); both '' for an unknown key.
 */
function tkdesk_email_template( string $key, array $request, array $vars = array() ): array {
	$tpl = tkdesk_email_templates()[ $key ] ?? null;
	if ( ! $tpl ) {
		return array(
			'subject' => '',
			'body'    => '',
		);
	}
	$saved  = tkdesk_email_saved( $key );
	$values = array_intersect_key( tkdesk_email_values( $request, $vars ), array_flip( $tpl['tags'] ) );
	return array(
		'subject' => trim( (string) preg_replace( '/[\r\n\t]+/', ' ', tkdesk_email_fill( '' !== $saved['subject'] ? $saved['subject'] : $tpl['subject'], $values, false ) ) ),
		'body'    => tkdesk_email_fill( '' !== $saved['body'] ? $saved['body'] : $tpl['body'], $values ),
	);
}

/**
 * The template a request uses for an email: copyright notices have their own.
 *
 * @param string $key     Email key as callers name it (requester_received, mod_new, ...).
 * @param array  $request Request row.
 * @return string
 */
function tkdesk_email_key( string $key, array $request ): string {
	$dmca = array(
		'requester_received'   => 'dmca_received',
		'requester_needs_info' => 'dmca_needs_info',
		'requester_removed'    => 'dmca_removed',
		'requester_declined'   => 'dmca_declined',
		'poster_removed'       => 'dmca_poster_removed',
		'mod_new'              => 'mod_dmca_new',
		'mod_24h'              => 'mod_dmca_reminder',
		'mod_40h'              => 'mod_dmca_reminder',
		'mod_overdue'          => 'mod_dmca_overdue',
	);
	return 'dmca' === ( $request['type'] ?? '' ) && isset( $dmca[ $key ] ) ? $dmca[ $key ] : $key;
}

/**
 * Send one email. Requester keys go to the decrypted contact email in the request's locale; poster keys
 * go to $vars['poster_email']; moderator keys go to the moderators. Test requests only ever reach the
 * current user. An email turned off in Settings is not sent and logs email_off. A copyright notice uses
 * its own template (tkdesk_email_key()); the recipients follow the key passed in.
 *
 * @param string $key     Email key.
 * @param array  $request Request row.
 * @param array  $vars    Template vars, plus sample (bool: a Settings test, sent even when turned off) and poster_email.
 * @return bool Sent.
 */
function tkdesk_send_email( string $key, array $request, array $vars = array() ): bool {
	$to_requester = 0 === strpos( $key, 'requester_' );
	$to_poster    = 0 === strpos( $key, 'poster_' );
	$key          = tkdesk_email_key( $key, $request );
	// Filed by the site itself: there is no requester to email.
	if ( $to_requester && 'site' === ( $request['relationship'] ?? '' ) ) {
		return false;
	}
	if ( empty( $vars['sample'] ) && ! tkdesk_email_saved( $key )['active'] ) {
		tkdesk_log( (int) $request['id'], 'email_off', array( 'key' => $key ) );
		return false;
	}
	if ( $to_requester ) {
		$contact = tkdesk_request_contact( $request );
		if ( is_wp_error( $contact ) ) {
			return false;
		}
		$to = array( $contact['email'] );
	} elseif ( $to_poster ) {
		// The person who posted removed content (poster.php): the address is never stored.
		$to = array_filter( array( (string) ( $vars['poster_email'] ?? '' ) ), 'is_email' );
	} else {
		$to = apply_filters( 'tkdesk_notify_recipients', tkdesk_moderator_emails(), $key, $request );
	}
	if ( ! empty( $request['is_test'] ) ) {
		$to = array_filter( array( wp_get_current_user()->user_email ) );
	}
	if ( ! $to ) {
		return false;
	}

	$switched = $to_requester && '' !== (string) $request['locale'] && switch_to_locale( $request['locale'] );
	$template = tkdesk_email_template( $key, $request, $vars );
	if ( $switched ) {
		restore_previous_locale();
	}
	if ( '' === $template['subject'] ) {
		return false;
	}

	$footer    = (string) tkdesk_setting( 'email_footer' );
	$reply_to  = (string) tkdesk_setting( 'reply_to' );
	$from_name = (string) tkdesk_setting( 'from_name' );
	$from_name = '' !== $from_name ? $from_name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$email     = array(
		'to'      => $to,
		'subject' => $template['subject'],
		'body'    => '' !== $footer ? $template['body'] . "\n\n" . $footer : $template['body'],
		'headers' => array( 'Reply-To: ' . ( is_email( $reply_to ) ? $reply_to : get_option( 'admin_email' ) ) ),
	);
	$email     = apply_filters( 'tkdesk_email', $email, $key, $request );

	$plain  = (string) $email['body'];
	$mailer = function ( $phpmailer ) use ( $plain, $from_name ) {
		$phpmailer->set( 'AltBody', $plain );
		$phpmailer->set( 'FromName', $from_name );
	};
	add_action( 'phpmailer_init', $mailer );
	$sent = wp_mail( $email['to'], $email['subject'], wpautop( make_clickable( esc_html( $plain ) ) ), array_merge( (array) $email['headers'], array( 'Content-Type: text/html; charset=UTF-8' ) ) );
	remove_action( 'phpmailer_init', $mailer );

	tkdesk_log( (int) $request['id'], $sent ? 'email_sent' : 'email_failed', array( 'key' => $key ) );
	return $sent;
}
