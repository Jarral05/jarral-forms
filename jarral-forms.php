<?php
/**
 * Plugin Name:       Jarral Forms
 * Description:       Contact form with nonce protection, honeypot, timing trap, rate limiting and full sanitisation. Stores every enquiry and emails you.
 * Version:     1.4.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Update URI:        https://github.com/Jarral05/jarral-forms
 * Author:            Mohsin Jarral
 * License:           GPL-2.0-or-later
 * Text Domain:       jarral-forms
 *
 * READ THIS BEFORE EDITING
 * -----------------------
 * A contact form is the only place where a stranger sends you data. That
 * makes it the most attacked component on the site. Every guard below
 * exists because of a real attack class:
 *
 *   Nonce        -> cross-site request forgery
 *   Honeypot     -> bots that fill every field
 *   Timing trap  -> bots that submit in under three seconds
 *   Rate limit   -> flooding your inbox and database
 *   Sanitisation -> SQL injection and stored XSS
 *   Escaping     -> XSS when you read the enquiry in the admin
 *
 * Remove any one and you have reopened that hole.
 *
 * @package JarralForms
 */

defined( 'ABSPATH' ) || exit;

define( 'JDF_VERSION', '1.4.0' );

/* =========================================================================
   0. CONFIGURATION

   The three lines you are likely to want to change. Each is wrapped in
   defined(), so you can override any of them from wp-config.php without
   editing this file - which means a plugin update will never undo your
   change.
   ========================================================================= */

// The address both emails are sent FROM, and the one clients reply to.
if ( ! defined( 'JDF_FROM_EMAIL' ) ) {
	define( 'JDF_FROM_EMAIL', 'hello@jarraldigital.com' );
}

// The name clients see in their inbox as the sender.
if ( ! defined( 'JDF_FROM_NAME' ) ) {
	define( 'JDF_FROM_NAME', 'Mohsin Jarral' );
}

// Set to false to stop sending clients a confirmation email.
if ( ! defined( 'JDF_AUTOREPLY' ) ) {
	define( 'JDF_AUTOREPLY', true );
}

// The reply window promised in both the form and the confirmation email.
// Change it in one place and every message that mentions it follows.
if ( ! defined( 'JDF_REPLY_WINDOW' ) ) {
	define( 'JDF_REPLY_WINDOW', 'twelve hours' );
}

/* =========================================================================
   1. STORAGE
   Enquiries are a private post type. No custom tables to maintain, and
   WordPress handles backup and export for you.
   ========================================================================= */

/**
 * Register the Enquiries post type.
 */
function jdf_register_storage() {
	register_post_type(
		'jd_enquiry',
		array(
			'labels'          => array(
				'name'          => __( 'Enquiries', 'jarral-forms' ),
				'singular_name' => __( 'Enquiry', 'jarral-forms' ),
				'menu_name'     => __( 'Enquiries', 'jarral-forms' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => false,
			'menu_icon'       => 'dashicons-email-alt',
			'menu_position'   => 21,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'jdf_register_storage' );

/**
 * Allowed budget values. Anything else is rejected.
 *
 * @return string[]
 */
function jdf_budgets() {
	return array( 'Under $500', '$500 - $1,500', '$1,500 - $5,000', '$5,000+' );
}

/**
 * Allowed timing values.
 *
 * @return string[]
 */
function jdf_timings() {
	return array( 'This month', 'Next 1-3 months', 'Just exploring' );
}

/**
 * A privacy-respecting visitor key: a salted hash, never the raw IP.
 * Storing raw IP addresses is personal data under GDPR.
 *
 * @return string
 */
function jdf_visitor_key() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	return substr( hash( 'sha256', $ip . wp_salt() ), 0, 24 );
}

/* =========================================================================
   2. RENDERING
   ========================================================================= */

/**
 * Output the contact form. Called from the theme and from the shortcode.
 *
 * @param array $args {
 *     Optional.
 *
 *     @type string $service Pre-tag the enquiry with this service name. Used by
 *                           the service pages, so an enquiry sent from the
 *                           Headless WordPress page arrives labelled as one
 *                           even when the visitor never touched the estimator.
 *                           A real estimator selection in the URL always wins,
 *                           because that one carries a price as well.
 * }
 */
function jd_render_contact_form( $args = array() ) {

	$args = wp_parse_args(
		$args,
		array(
			'service' => '',
		)
	);

	$key   = 'jdf_state_' . jdf_visitor_key();
	$state = get_transient( $key );
	delete_transient( $key );

	if ( is_array( $state ) && isset( $state['status'] ) && 'ok' === $state['status'] ) {
		echo '<div class="jdf-note jdf-note-ok" role="status">' .
			esc_html__( 'Thank you. Your message is with me and I will reply within twelve hours.', 'jarral-forms' ) .
			'</div>';
		return;
	}

	$errors = ( is_array( $state ) && ! empty( $state['errors'] ) ) ? $state['errors'] : array();
	$old    = ( is_array( $state ) && ! empty( $state['old'] ) ) ? $state['old'] : array();

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill, nothing saved.
	$pre_service = isset( $_GET['est_service'] ) ? sanitize_text_field( wp_unslash( $_GET['est_service'] ) ) : '';
	$pre_range   = isset( $_GET['est_range'] ) ? sanitize_text_field( wp_unslash( $_GET['est_range'] ) ) : '';
	$pre_extras  = isset( $_GET['est_extras'] ) ? sanitize_text_field( wp_unslash( $_GET['est_extras'] ) ) : '';
	// phpcs:enable

	// No estimator selection in the URL, but the page itself names a service.
	// Tag it, so you can tell a Headless WordPress enquiry from a MERN one at
	// a glance in the admin list.
	if ( '' === $pre_service && '' !== $args['service'] ) {
		$pre_service = sanitize_text_field( $args['service'] );
	}

	// Which page this form sits on. Written as a hidden field rather than read
	// from the referrer on submit, because admin-post.php is the referrer by
	// then and the real origin is already lost.
	$source = '';
	if ( is_singular() || is_page() ) {
		$source = get_the_title();
	} elseif ( is_front_page() ) {
		$source = __( 'Homepage', 'jarral-forms' );
	}

	$val = static function ( $k ) use ( $old ) {
		return isset( $old[ $k ] ) ? $old[ $k ] : '';
	};

	$err = static function ( $k ) use ( $errors ) {
		if ( empty( $errors[ $k ] ) ) {
			return '';
		}
		return '<span class="jdf-err">' . esc_html( $errors[ $k ] ) . '</span>';
	};

	if ( ! empty( $errors['_general'] ) ) {
		echo '<div class="jdf-note jdf-note-bad" role="alert">' . esc_html( $errors['_general'] ) . '</div>';
	}
	?>

	<form class="form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>

		<input type="hidden" name="action" value="jdf_submit">
		<?php wp_nonce_field( 'jdf_submit_form', 'jdf_nonce' ); ?>

		<?php // Timing trap: records when the page was served. ?>
		<input type="hidden" name="jdf_time" value="<?php echo esc_attr( (string) time() ); ?>">

		<?php // Honeypot: invisible to people, irresistible to bots. ?>
		<div class="jdf-hp" aria-hidden="true">
			<label for="jdf_website">Leave this empty</label>
			<input type="text" id="jdf_website" name="jdf_website" tabindex="-1" autocomplete="off">
		</div>

		<?php if ( $source ) : ?>
			<input type="hidden" name="jdf_source" value="<?php echo esc_attr( $source ); ?>">
		<?php endif; ?>

		<?php if ( $pre_service ) : ?>
			<input type="hidden" name="jdf_est_service" value="<?php echo esc_attr( $pre_service ); ?>">
			<input type="hidden" name="jdf_est_range" value="<?php echo esc_attr( $pre_range ); ?>">
			<input type="hidden" name="jdf_est_extras" value="<?php echo esc_attr( $pre_extras ); ?>">

			<div class="jdf-quote">
				<p class="jdf-quote-k">
					<?php
					echo $pre_range
						? esc_html__( 'Your estimate', 'jarral-forms' )
						: esc_html__( 'Enquiring about', 'jarral-forms' );
					?>
				</p>
				<p class="jdf-quote-svc"><?php echo esc_html( $pre_service ); ?></p>

				<?php if ( $pre_range ) : ?>
					<p class="jdf-quote-price"><?php echo esc_html( $pre_range ); ?></p>
				<?php endif; ?>

				<?php if ( $pre_extras ) : ?>
					<p class="jdf-quote-extras">
						<?php
						printf(
							/* translators: %s: comma-separated list of chosen extras */
							esc_html__( 'Including: %s', 'jarral-forms' ),
							esc_html( $pre_extras )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $pre_range ) : ?>
					<p class="jdf-quote-foot">
						<?php esc_html_e( 'Carried over with your message, so you do not need to repeat it.', 'jarral-forms' ); ?>
						<a href="<?php echo esc_url( home_url( '/#estimator' ) ); ?>"><?php esc_html_e( 'Change it', 'jarral-forms' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="form-row">
			<div class="field">
				<label for="jdf_name"><?php esc_html_e( 'Name', 'jarral-forms' ); ?></label>
				<input type="text" id="jdf_name" name="jdf_name" required autocomplete="name"
				       value="<?php echo esc_attr( $val( 'name' ) ); ?>" placeholder="Jane Whitfield">
				<?php echo wp_kses_post( $err( 'name' ) ); ?>
			</div>
			<div class="field">
				<label for="jdf_email"><?php esc_html_e( 'Email', 'jarral-forms' ); ?></label>
				<input type="email" id="jdf_email" name="jdf_email" required autocomplete="email"
				       value="<?php echo esc_attr( $val( 'email' ) ); ?>" placeholder="jane@company.com">
				<?php echo wp_kses_post( $err( 'email' ) ); ?>
			</div>
		</div>

		<div class="field">
			<label for="jdf_company"><?php esc_html_e( 'Company', 'jarral-forms' ); ?></label>
			<input type="text" id="jdf_company" name="jdf_company" autocomplete="organization"
			       value="<?php echo esc_attr( $val( 'company' ) ); ?>" placeholder="Whitfield Consulting">
		</div>

		<div class="field">
			<label for="jdf_message"><?php esc_html_e( 'What are you building?', 'jarral-forms' ); ?></label>
			<textarea id="jdf_message" name="jdf_message" required
			          placeholder="We need a members area where clients log in and download their reports."><?php echo esc_textarea( $val( 'message' ) ); ?></textarea>
			<?php echo wp_kses_post( $err( 'message' ) ); ?>
		</div>

		<div class="form-row">
			<div class="field">
				<label for="jdf_budget"><?php esc_html_e( 'Budget range', 'jarral-forms' ); ?></label>
				<select id="jdf_budget" name="jdf_budget">
					<?php foreach ( jdf_budgets() as $b ) : ?>
						<option value="<?php echo esc_attr( $b ); ?>" <?php selected( $val( 'budget' ), $b ); ?>><?php echo esc_html( $b ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="jdf_timing"><?php esc_html_e( 'How soon?', 'jarral-forms' ); ?></label>
				<select id="jdf_timing" name="jdf_timing">
					<?php foreach ( jdf_timings() as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $val( 'timing' ), $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div>
			<button type="submit" class="btn btn-invert"><?php esc_html_e( 'Send project details', 'jarral-forms' ); ?></button>
		</div>
	</form>
	<?php
}

/* =========================================================================
   3. HANDLING THE SUBMISSION
   This is the security-critical part. Read the order of the checks:
   cheap rejections first, expensive work last.
   ========================================================================= */

/**
 * Process the posted form.
 */
function jdf_handle_submit() {

	$back   = wp_get_referer() ? wp_get_referer() : home_url( '/contact/' );
	$key    = 'jdf_state_' . jdf_visitor_key();
	$errors = array();

	// --- Guard 1: nonce. Proves the request came from our own form. -------
	if ( ! isset( $_POST['jdf_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jdf_nonce'] ) ), 'jdf_submit_form' ) ) {
		$errors['_general'] = __( 'Your session expired. Please try again.', 'jarral-forms' );
		jdf_bounce( $key, $errors, array(), $back );
	}

	// --- Guard 2: honeypot. A real person cannot see this field. ----------
	if ( ! empty( $_POST['jdf_website'] ) ) {
		// Silently pretend it worked. Telling a bot it failed just makes it retry.
		set_transient( $key, array( 'status' => 'ok' ), 60 );
		wp_safe_redirect( $back . '#contact' );
		exit;
	}

	// --- Guard 3: timing. Nobody reads and fills this form in 3 seconds. --
	$started = isset( $_POST['jdf_time'] ) ? absint( $_POST['jdf_time'] ) : 0;
	if ( $started > 0 && ( time() - $started ) < 3 ) {
		set_transient( $key, array( 'status' => 'ok' ), 60 );
		wp_safe_redirect( $back . '#contact' );
		exit;
	}

	// --- Guard 4: rate limit. Five submissions per hour per visitor. ------
	$rate_key = 'jdf_rate_' . jdf_visitor_key();
	$count    = (int) get_transient( $rate_key );
	if ( $count >= 5 ) {
		$errors['_general'] = __( 'Too many messages from this connection. Please try again later, or email me directly.', 'jarral-forms' );
		jdf_bounce( $key, $errors, array(), $back );
	}
	set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

	// --- Clean every value. Never trust anything from $_POST. -------------
	$name    = isset( $_POST['jdf_name'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_name'] ) ) : '';
	$email   = isset( $_POST['jdf_email'] ) ? sanitize_email( wp_unslash( $_POST['jdf_email'] ) ) : '';
	$company = isset( $_POST['jdf_company'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_company'] ) ) : '';
	$message = isset( $_POST['jdf_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jdf_message'] ) ) : '';
	$budget  = isset( $_POST['jdf_budget'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_budget'] ) ) : '';
	$timing  = isset( $_POST['jdf_timing'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_timing'] ) ) : '';

	$est_service = isset( $_POST['jdf_est_service'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_est_service'] ) ) : '';
	$est_range   = isset( $_POST['jdf_est_range'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_est_range'] ) ) : '';
	$est_extras  = isset( $_POST['jdf_est_extras'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_est_extras'] ) ) : '';
	$source      = isset( $_POST['jdf_source'] ) ? sanitize_text_field( wp_unslash( $_POST['jdf_source'] ) ) : '';

	$old = compact( 'name', 'email', 'company', 'message', 'budget', 'timing' );

	// --- Validate. -------------------------------------------------------
	if ( '' === $name || mb_strlen( $name ) > 120 ) {
		$errors['name'] = __( 'Please give your name.', 'jarral-forms' );
	}

	if ( '' === $email || ! is_email( $email ) ) {
		$errors['email'] = __( 'That email address does not look right.', 'jarral-forms' );
	}

	if ( mb_strlen( $message ) < 15 ) {
		$errors['message'] = __( 'Please tell me a little more, at least a sentence.', 'jarral-forms' );
	}

	if ( mb_strlen( $message ) > 5000 ) {
		$errors['message'] = __( 'That is longer than this form accepts. Please email me instead.', 'jarral-forms' );
	}

	// Dropdowns are checked against the allowed list, because a determined
	// attacker edits the HTML before posting. Never trust a select field.
	if ( ! in_array( $budget, jdf_budgets(), true ) ) {
		$budget = '';
	}
	if ( ! in_array( $timing, jdf_timings(), true ) ) {
		$timing = '';
	}

	// A link-stuffed message is spam, not a client.
	if ( substr_count( strtolower( $message ), 'http' ) > 3 ) {
		$errors['message'] = __( 'Please remove the links and describe the project in your own words.', 'jarral-forms' );
	}

	if ( $errors ) {
		jdf_bounce( $key, $errors, $old, $back );
	}

	// --- Store it. -------------------------------------------------------
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'jd_enquiry',
			'post_status' => 'private',
			'post_title'  => sprintf(
				'%1$s (%2$s)',
				$name,
				$company ? $company : $email
			),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		$errors['_general'] = __( 'Something went wrong saving your message. Please email me directly.', 'jarral-forms' );
		jdf_bounce( $key, $errors, $old, $back );
	}

	$meta = array(
		'_jdf_name'        => $name,
		'_jdf_email'       => $email,
		'_jdf_company'     => $company,
		'_jdf_message'     => $message,
		'_jdf_budget'      => $budget,
		'_jdf_timing'      => $timing,
		'_jdf_est_service' => $est_service,
		'_jdf_est_range'   => $est_range,
		'_jdf_est_extras'  => $est_extras,
		'_jdf_source'      => $source,
	);

	foreach ( $meta as $mk => $mv ) {
		update_post_meta( $post_id, $mk, $mv );
	}

	// --- Email yourself. -------------------------------------------------
	// Yours first. If the client confirmation fails for any reason, the lead
	// is already saved and you have already been told about it.
	jdf_notify( $meta );
	jdf_notify_client( $meta );

	set_transient( $key, array( 'status' => 'ok' ), 60 );
	wp_safe_redirect( $back . '#contact' );
	exit;
}
add_action( 'admin_post_nopriv_jdf_submit', 'jdf_handle_submit' ); // Logged-out visitors.
add_action( 'admin_post_jdf_submit', 'jdf_handle_submit' );        // And logged-in ones.

/**
 * Send the visitor back with their input and the errors intact.
 *
 * @param string $key    Transient key.
 * @param array  $errors Field errors.
 * @param array  $old    Submitted values.
 * @param string $back   URL to return to.
 */
function jdf_bounce( $key, $errors, $old, $back ) {
	set_transient(
		$key,
		array(
			'status' => 'error',
			'errors' => $errors,
			'old'    => $old,
		),
		120
	);
	wp_safe_redirect( $back . '#contact' );
	exit;
}

/**
 * Email the enquiry.
 *
 * The From address is always your own domain. Setting From to the visitor's
 * address makes your mail fail SPF and land in spam, which is how people
 * lose leads without ever knowing.
 *
 * @param array $m Meta values.
 */
function jdf_notify( $m ) {

	$to     = apply_filters( 'jdf_notify_email', get_option( 'admin_email' ) );
	$domain = wp_parse_url( home_url(), PHP_URL_HOST );

	/*
	 * The subject carries the service and the price.
	 *
	 * The old subject was just the sender's name, which meant opening every
	 * email to find out what it was about. On a phone, the inbox list shows
	 * roughly 45 characters, so the service goes first and the name follows.
	 */
	$est = '';
	if ( $m['_jdf_est_service'] ) {
		$est = $m['_jdf_est_service'];
		if ( $m['_jdf_est_range'] ) {
			$est .= ' ' . $m['_jdf_est_range'];
		}
	}

	$subject = $est
		? sprintf( '%1$s - %2$s', $est, $m['_jdf_name'] )
		: sprintf( 'New enquiry: %s', $m['_jdf_name'] );

	$lines = array();

	// The estimate goes at the very top of the body too, for the same reason:
	// it is the thing that decides whether you reply in five minutes or five
	// hours, so it should not be below five other fields.
	if ( $m['_jdf_est_service'] ) {
		$lines[] = '--- WHAT THEY PICKED ---';
		$lines[] = 'Service:  ' . $m['_jdf_est_service'];
		$lines[] = 'Estimate: ' . ( $m['_jdf_est_range'] ? $m['_jdf_est_range'] : 'not calculated' );
		if ( $m['_jdf_est_extras'] ) {
			$lines[] = 'Extras:   ' . $m['_jdf_est_extras'];
		}
		$lines[] = '';
	}

	$lines[] = 'Name:     ' . $m['_jdf_name'];
	$lines[] = 'Email:    ' . $m['_jdf_email'];
	$lines[] = 'Company:  ' . ( $m['_jdf_company'] ? $m['_jdf_company'] : '-' );
	$lines[] = 'Budget:   ' . ( $m['_jdf_budget'] ? $m['_jdf_budget'] : '-' );
	$lines[] = 'Timing:   ' . ( $m['_jdf_timing'] ? $m['_jdf_timing'] : '-' );

	if ( ! empty( $m['_jdf_source'] ) ) {
		$lines[] = 'Sent from: ' . $m['_jdf_source'];
	}

	$lines[] = '';
	$lines[] = $m['_jdf_message'];

	$headers = array(
		'From: ' . get_bloginfo( 'name' ) . ' <' . JDF_FROM_EMAIL . '>',
		'Reply-To: ' . $m['_jdf_name'] . ' <' . $m['_jdf_email'] . '>',
		'Content-Type: text/plain; charset=UTF-8',
	);

	wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
}

/* =========================================================================
   3b. THE CLIENT CONFIRMATION

   One email to you (above, plain text, built for scanning fast) and one to
   the client (below, designed, built to reassure).

   Deliberately, the client's copy does NOT repeat their free-text message
   back to them. The form accepts any email address a visitor types, so
   anything echoed into this email is something a stranger could aim at
   somebody else's inbox. The structured fields come from your own dropdowns
   and estimator, so they are safe to include; a free paragraph is not.
   ========================================================================= */

/**
 * Force text/html for one wp_mail call.
 *
 * @return string
 */
function jdf_html_content_type() {
	return 'text/html';
}

/**
 * Wrap body HTML in an email-safe shell.
 *
 * Tables, not flexbox. Inline styles, not a stylesheet. Outlook renders mail
 * with Word's engine, which supports neither, and Gmail strips most of what
 * survives that. 600px is the width that still fits an Outlook reading pane.
 *
 * @param string $body      Inner HTML.
 * @param string $preheader The grey line shown after the subject in inboxes.
 * @return string
 */
function jdf_email_shell( $body, $preheader = '' ) {

	$pine = '#14453A';
	$bone = '#F7F7F4';
	$ink  = '#111512';
	$soft = '#4A524C';

	ob_start();
	?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $bone ); ?>;">

<?php // Shown next to the subject in most inbox lists, then hidden. ?>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
	<?php echo esc_html( $preheader ); ?>
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
	style="background:<?php echo esc_attr( $bone ); ?>;">
<tr><td align="center" style="padding:24px 12px;">

<?php
/*
 * The card is a 100%-wide table capped at 600px, NOT a table set to 600px.
 *
 * `width="600" style="width:600px;max-width:100%"` looks like it should be
 * responsive, but table layout resolves the specified width first and
 * max-width never gets a say - the card stayed 600px wide inside a 360px
 * phone and the whole email scrolled sideways. A percentage width with a
 * max-width cap shrinks correctly.
 *
 * Outlook on Windows ignores max-width, so for Outlook only, the comment
 * below opens a real fixed-width table. Every other client skips it.
 */
?>
<!--[if mso]>
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;"><tr><td>
<![endif]-->

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
	style="max-width:600px;background:#FFFFFF;border:1px solid #E2E5E0;border-radius:6px;">

	<?php // Wordmark as text, not an image: images are blocked by default. ?>
	<tr><td style="background:<?php echo esc_attr( $pine ); ?>;padding:22px 30px;border-radius:5px 5px 0 0;">
		<span style="font-family:'Segoe UI',Helvetica,Arial,sans-serif;font-size:19px;font-weight:700;
			letter-spacing:-0.3px;color:#FFFFFF;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	</td></tr>

	<tr><td style="padding:30px;font-family:'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;
		line-height:1.6;color:<?php echo esc_attr( $ink ); ?>;">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below. ?>
	</td></tr>

	<tr><td style="padding:20px 30px 26px;border-top:1px solid #E2E5E0;
		font-family:'Segoe UI',Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;
		color:<?php echo esc_attr( $soft ); ?>;">
		<strong style="color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong><br>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"
			style="color:<?php echo esc_attr( $pine ); ?>;"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a>
		&nbsp;&middot;&nbsp;
		<a href="mailto:<?php echo esc_attr( JDF_FROM_EMAIL ); ?>"
			style="color:<?php echo esc_attr( $pine ); ?>;"><?php echo esc_html( JDF_FROM_EMAIL ); ?></a>
		<br><br>
		<span style="font-size:12px;">
			You are receiving this because you sent an enquiry through
			<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>.
			This is a one-off confirmation, not a mailing list, and you have not been subscribed to anything.
		</span>
	</td></tr>

</table>

<!--[if mso]>
</td></tr></table>
<![endif]-->

</td></tr></table>
</body></html>
	<?php
	return (string) ob_get_clean();
}

/**
 * Send the client their confirmation.
 *
 * @param array $m Meta values.
 */
function jdf_notify_client( $m ) {

	if ( ! JDF_AUTOREPLY || empty( $m['_jdf_email'] ) || ! is_email( $m['_jdf_email'] ) ) {
		return;
	}

	$pine  = '#14453A';
	$tint  = '#E4EDE8';
	$soft  = '#4A524C';

	// First name only. "Hello Jane Whitfield" reads like a database wrote it.
	$first = trim( (string) strtok( (string) $m['_jdf_name'], ' ' ) );
	$first = $first ? $first : 'there';

	$window = JDF_REPLY_WINDOW;

	$b  = '<p style="margin:0 0 16px;font-size:20px;font-weight:600;line-height:1.3;">';
	$b .= sprintf( 'Thanks, %s &mdash; your message reached me.', esc_html( $first ) );
	$b .= '</p>';

	$b .= '<p style="margin:0 0 16px;">';
	$b .= sprintf(
		'I read every enquiry myself, and you will have a reply within <strong>%s</strong>. Not an automated one &mdash; a real answer with three things in it: whether I can build what you need, what it costs, and how long it takes.',
		esc_html( $window )
	);
	$b .= '</p>';

	$b .= '<p style="margin:0 0 22px;">If it turns out not to be a fit, I will tell you that too, and point you somewhere better. That is faster for both of us than a week of back and forth.</p>';

	// What they picked, if the estimator carried anything over.
	if ( ! empty( $m['_jdf_est_service'] ) ) {
		$b .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
			style="margin:0 0 22px;"><tr><td style="background:' . $tint . ';border-left:3px solid ' . $pine . ';
			padding:16px 18px;">';
		$b .= '<p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:1.3px;
			text-transform:uppercase;color:#3C6B5C;">What you asked about</p>';
		$b .= '<p style="margin:0;font-size:17px;font-weight:600;color:' . $pine . ';">'
			. esc_html( $m['_jdf_est_service'] ) . '</p>';

		if ( ! empty( $m['_jdf_est_range'] ) ) {
			$b .= '<p style="margin:3px 0 0;font-size:24px;font-weight:700;color:' . $pine . ';">'
				. esc_html( $m['_jdf_est_range'] ) . '</p>';
			$b .= '<p style="margin:8px 0 0;font-size:13px;color:#3C6B5C;">'
				. 'An estimate from the website calculator, not a quote. Your real price comes in my reply and does not change after you accept it.'
				. '</p>';
		}

		if ( ! empty( $m['_jdf_est_extras'] ) ) {
			$b .= '<p style="margin:8px 0 0;font-size:13px;color:#3C6B5C;">Including: '
				. esc_html( $m['_jdf_est_extras'] ) . '</p>';
		}

		$b .= '</td></tr></table>';
	}

	// What happens next.
	$b .= '<p style="margin:0 0 10px;font-size:15px;font-weight:600;">What happens next</p>';
	$b .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">';

	$steps = array(
		sprintf( 'Within %s you get scope, price and a timeline in writing.', $window ),
		'If anything in your message is unclear, I ask rather than guess and quote wrong.',
		'If it fits, a 50% deposit starts the work. Nothing is owed before that.',
	);

	$n = 1;
	foreach ( $steps as $step ) {
		$b .= '<tr>';
		$b .= '<td width="26" valign="top" style="padding:0 0 9px;font-size:14px;font-weight:700;color:' . $pine . ';">'
			. (int) $n . '.</td>';
		$b .= '<td valign="top" style="padding:0 0 9px;font-size:15px;line-height:1.55;color:' . $soft . ';">'
			. esc_html( $step ) . '</td>';
		$b .= '</tr>';
		++$n;
	}
	$b .= '</table>';

	$b .= '<p style="margin:0 0 6px;">In the meantime, reply to this email if you remember anything else &mdash; it comes straight to me.</p>';
	$b .= '<p style="margin:22px 0 0;">Mohsin<br>'
		. '<span style="color:' . $soft . ';font-size:14px;">Jarral Digital</span></p>';

	$preheader = sprintf( 'I have your message and will reply within %s.', $window );
	$html      = jdf_email_shell( $b, $preheader );

	$headers = array(
		'From: ' . JDF_FROM_NAME . ' <' . JDF_FROM_EMAIL . '>',
		'Reply-To: ' . JDF_FROM_NAME . ' <' . JDF_FROM_EMAIL . '>',
	);

	/*
	 * The content type is set with a filter and removed immediately after.
	 * Leaving it attached would turn every other plugin's plain-text email
	 * on this site into text/html, which shows raw markup to the recipient.
	 */
	add_filter( 'wp_mail_content_type', 'jdf_html_content_type' );
	wp_mail(
		$m['_jdf_email'],
		sprintf( 'Your enquiry — %s', get_bloginfo( 'name' ) ),
		$html,
		$headers
	);
	remove_filter( 'wp_mail_content_type', 'jdf_html_content_type' );
}

/* =========================================================================
   4. READING ENQUIRIES IN THE ADMIN
   ========================================================================= */

/**
 * Show the enquiry contents on the edit screen.
 */
function jdf_admin_box() {
	add_meta_box( 'jdf_detail', __( 'Enquiry', 'jarral-forms' ), 'jdf_admin_box_html', 'jd_enquiry', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'jdf_admin_box' );

/**
 * Render it. Everything is escaped, because the content came from a stranger.
 *
 * @param WP_Post $post Current enquiry.
 */
function jdf_admin_box_html( $post ) {

	$svc   = (string) get_post_meta( $post->ID, '_jdf_est_service', true );
	$range = (string) get_post_meta( $post->ID, '_jdf_est_range', true );

	// The estimate first, in a panel you cannot miss, rather than as row six
	// of a seven-row table.
	if ( '' !== $svc ) {
		echo '<div style="background:#E4EDE8;border-left:4px solid #14453A;padding:14px 18px;margin:0 0 16px">';
		echo '<p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#3C6B5C">' .
			esc_html__( 'What they picked', 'jarral-forms' ) . '</p>';
		echo '<p style="margin:0;font-size:17px;font-weight:600;color:#14453A">' . esc_html( $svc ) . '</p>';
		if ( '' !== $range ) {
			echo '<p style="margin:2px 0 0;font-size:23px;font-weight:700;color:#14453A">' . esc_html( $range ) . '</p>';
		}
		$extras = (string) get_post_meta( $post->ID, '_jdf_est_extras', true );
		if ( '' !== $extras ) {
			echo '<p style="margin:8px 0 0;font-size:13px;color:#3C6B5C">' .
				esc_html__( 'Including: ', 'jarral-forms' ) . esc_html( $extras ) . '</p>';
		}
		echo '</div>';
	}

	$rows = array(
		__( 'Name', 'jarral-forms' )    => get_post_meta( $post->ID, '_jdf_name', true ),
		__( 'Email', 'jarral-forms' )   => get_post_meta( $post->ID, '_jdf_email', true ),
		__( 'Company', 'jarral-forms' ) => get_post_meta( $post->ID, '_jdf_company', true ),
		__( 'Budget', 'jarral-forms' )  => get_post_meta( $post->ID, '_jdf_budget', true ),
		__( 'Timing', 'jarral-forms' )  => get_post_meta( $post->ID, '_jdf_timing', true ),
		__( 'Sent from', 'jarral-forms' ) => get_post_meta( $post->ID, '_jdf_source', true ),
	);

	echo '<table class="widefat striped"><tbody>';
	foreach ( $rows as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}
		echo '<tr><th style="width:150px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h4>' . esc_html__( 'Message', 'jarral-forms' ) . '</h4>';
	echo '<p style="white-space:pre-wrap;background:#fff;padding:14px;border:1px solid #ccd0d4">' .
		esc_html( get_post_meta( $post->ID, '_jdf_message', true ) ) . '</p>';

	$email = get_post_meta( $post->ID, '_jdf_email', true );
	if ( $email ) {
		printf(
			'<p><a class="button button-primary" href="mailto:%1$s">%2$s</a></p>',
			esc_attr( $email ),
			esc_html__( 'Reply by email', 'jarral-forms' )
		);
	}
}

/**
 * Useful columns on the Enquiries list.
 *
 * @param array $cols Existing columns.
 * @return array
 */
function jdf_columns( $cols ) {
	return array(
		'cb'         => isset( $cols['cb'] ) ? $cols['cb'] : '',
		'title'      => __( 'From', 'jarral-forms' ),
		'jdf_service'=> __( 'Service', 'jarral-forms' ),
		'jdf_est'    => __( 'Estimate', 'jarral-forms' ),
		'jdf_budget' => __( 'Budget', 'jarral-forms' ),
		'jdf_timing' => __( 'Timing', 'jarral-forms' ),
		'date'       => __( 'Received', 'jarral-forms' ),
	);
}
add_filter( 'manage_jd_enquiry_posts_columns', 'jdf_columns' );

/**
 * Fill those columns.
 *
 * @param string $col Column key.
 * @param int    $id  Post ID.
 */
function jdf_column_content( $col, $id ) {
	if ( 'jdf_service' === $col ) {
		$svc = get_post_meta( $id, '_jdf_est_service', true );
		echo $svc ? esc_html( $svc ) : '<span style="color:#8c8f94">&mdash;</span>';
	}
	if ( 'jdf_est' === $col ) {
		$range = get_post_meta( $id, '_jdf_est_range', true );
		echo $range
			? '<strong>' . esc_html( $range ) . '</strong>'
			: '<span style="color:#8c8f94">&mdash;</span>';
	}
	if ( 'jdf_budget' === $col ) {
		echo esc_html( get_post_meta( $id, '_jdf_budget', true ) );
	}
	if ( 'jdf_timing' === $col ) {
		echo esc_html( get_post_meta( $id, '_jdf_timing', true ) );
	}
}
add_action( 'manage_jd_enquiry_posts_custom_column', 'jdf_column_content', 10, 2 );

/* =========================================================================
   6. SHORTCODE

   The homepage renders the form through its own template part. Every OTHER
   page needs a way to show it too - the Contact page most of all, since the
   header button and the estimator both point there.

   Usage: put [jarral_contact_form] into any page or post.
   ========================================================================= */

/**
 * Render the contact form from a shortcode.
 *
 * Attributes, all optional:
 *
 *   heading  A heading above the form. Supplying one wraps the whole thing in
 *            a finished section instead of leaving a bare form on the page.
 *   intro    One or two sentences under the heading.
 *   service  Tags the enquiry with this service, so you can tell at a glance
 *            which page it came from. Use the exact name from the estimator.
 *   style    "light" (default) or "dark" for the pine band treatment.
 *   id       Anchor id, default "contact", so buttons can link to #contact.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function jdf_form_shortcode( $atts = array() ) {
	if ( ! function_exists( 'jd_render_contact_form' ) ) {
		return '';
	}

	$a = shortcode_atts(
		array(
			'heading' => '',
			'intro'   => '',
			'service' => '',
			'style'   => 'light',
			'id'      => 'contact',
		),
		$atts,
		'jarral_contact_form'
	);

	// jd_render_contact_form() echoes, and a shortcode must RETURN. Without
	// this buffer the form prints at the very top of the page instead of
	// where the shortcode sits - a classic and very confusing WordPress bug.
	ob_start();
	jd_render_contact_form( array( 'service' => $a['service'] ) );
	$form = (string) ob_get_clean();

	// No heading means the caller just wants the form, unwrapped.
	if ( '' === trim( $a['heading'] ) && '' === trim( $a['intro'] ) ) {
		return $form;
	}

	$dark = ( 'dark' === strtolower( $a['style'] ) );

	$out  = '<section class="band jdf-block' . ( $dark ? ' band-dark' : '' ) . '" id="' .
		esc_attr( sanitize_title( $a['id'] ) ) . '">';
	$out .= '<div class="wrap">';
	$out .= '<div class="jdf-block-head">';

	if ( '' !== trim( $a['heading'] ) ) {
		$out .= '<h2>' . esc_html( $a['heading'] ) . '</h2>';
	}
	if ( '' !== trim( $a['intro'] ) ) {
		$out .= '<p class="jdf-block-intro">' . esc_html( $a['intro'] ) . '</p>';
	}

	$out .= '</div>' . $form . '</div></section>';

	return $out;
}
add_shortcode( 'jarral_contact_form', 'jdf_form_shortcode' );

/* =========================================================================
   5. UPDATES

   Custom plugins get no updates from wordpress.org, so this wires WordPress's
   normal update screen to a GitHub repository instead. You tag a release on
   GitHub, and every site running this plugin sees an update notice.

   The "Update URI" header above is the other half of this. Without it, if
   anyone ever publishes a plugin on wordpress.org with the same folder name,
   WordPress would offer THEIR code as an update to YOURS. That is a real
   supply-chain hijack, and the header is what prevents it.
   ========================================================================= */

/**
 * Register the update checker.
 */
function jdf_updates() {

	$lib = __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

	if ( ! file_exists( $lib ) ) {
		return; // Library missing: fail quietly rather than fatally.
	}

	require_once $lib;

	$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

	if ( ! class_exists( $factory ) ) {
		return;
	}

	$checker = $factory::buildUpdateChecker(
		'https://github.com/Jarral05/jarral-forms/',
		__FILE__,
		'jarral-forms'
	);

	// Only offer tagged releases, never whatever is sitting on the branch.
	// Without this you would push half-finished work to live client sites.
	if ( method_exists( $checker->getVcsApi(), 'enableReleaseAssets' ) ) {
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
add_action( 'init', 'jdf_updates' );
