<?php
/**
 * Marketing opt-out - the gate every non-transactional email must pass.
 *
 * -- Why this exists, and why it did not before -------------------------------
 *
 * Until 1.27.0 every email this plugin sent was TRANSACTIONAL: an order
 * confirmation, or a reminder about a ticket the person had already bought.
 * Transactional mail needs no opt-out, and deliberately ignoring subscription
 * state is what let the 2026-09-11 reminder reach all 28 buyers of the Sept 12
 * house concert - including one Mailchimp refused on a years-old unsubscribe.
 * That was correct. Someone who paid for a seat gets told where to sit.
 *
 * The post-concert thank-you breaks the symmetry. The moment an email promotes
 * the NEXT concert it is commercial email: it needs a working opt-out, and it
 * must not override a preference the patron has already expressed. Sending it
 * down the transactional path would do exactly that, silently, to everyone.
 *
 * -- Three design decisions worth keeping -------------------------------------
 *
 * 1. KEYED BY EMAIL ADDRESS, not by user or order. Most ticket buyers have no
 *    WordPress account and comp recipients frequently have neither an account
 *    nor an order of their own. The address is the only identifier every
 *    recipient actually has.
 *
 * 2. ONLY THE EXCEPTIONS ARE STORED. One option row per opted-OUT address,
 *    autoload = false. Row count therefore tracks opt-outs (a few dozen for an
 *    organisation this size) rather than patrons (thousands). The obvious
 *    alternative - one serialised array of everybody - loses writes whenever
 *    two people unsubscribe in the same second, and this is precisely the
 *    operation where a lost write means mailing someone who asked you not to.
 *
 * 3. NO TOKEN TABLE. The link carries an HMAC of the address derived from the
 *    site's own salts, so it is unforgeable without storing anything and
 *    without expiring. An expired unsubscribe link is indistinguishable from a
 *    broken one to the person holding it, and they do not write in to say so -
 *    they reach for the spam button.
 *
 * This gate must NEVER be applied to transactional mail. Opting out of
 * marketing cannot stop someone receiving the ticket they paid for.
 * ans_tb_marketing_email_ids() is the whole list of what it governs, and an id
 * must be added to it deliberately.
 *
 * @package ArsNovaTicketingBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which WC_Email ids count as MARKETING and are therefore gated.
 *
 * Opt-IN by design: an email is transactional unless named here. A new email
 * that forgets to register is over-delivered rather than under-delivered, and
 * over-delivery is the failure we can see and correct. The reverse is silent.
 *
 * @return string[]
 */
function ans_tb_marketing_email_ids() {
	return (array) apply_filters( 'ans_tb_marketing_email_ids', array( 'ans_post_concert' ) );
}

/** Normalise, so "Kim@Example.COM " and "kim@example.com" are one person. */
function ans_tb_optout_normalise( $email ) {
	return strtolower( trim( (string) $email ) );
}

/** Option name holding one address's opt-out. Hashed so the table is not a mailing list. */
function ans_tb_optout_option( $email ) {
	return 'ans_tb_optout_' . md5( ans_tb_optout_normalise( $email ) );
}

/**
 * Unforgeable per-address token.
 *
 * Derived from the site salts, so it is stable as long as they are and needs no
 * storage. Rotating the salts invalidates every outstanding link - worth
 * knowing before anyone rotates them.
 */
function ans_tb_optout_token( $email ) {
	return hash_hmac( 'sha256', ans_tb_optout_normalise( $email ), wp_salt( 'ans_tb_optout' ) );
}

/** Is this address opted out of marketing? */
function ans_tb_is_opted_out( $email ) {
	$email = ans_tb_optout_normalise( $email );
	if ( '' === $email ) {
		return false;
	}
	return '1' === (string) get_option( ans_tb_optout_option( $email ), '' );
}

/**
 * Record or clear an opt-out.
 *
 * Stores the address beside the flag so a human can answer "who has opted out?"
 * without reversing an md5 - the hash keeps the option NAME from being
 * harvestable, it is not meant to hide the data from us.
 */
function ans_tb_set_optout( $email, $opted_out = true ) {
	$email = ans_tb_optout_normalise( $email );
	if ( '' === $email || ! is_email( $email ) ) {
		return false;
	}
	$option = ans_tb_optout_option( $email );

	if ( $opted_out ) {
		update_option( $option, '1', false );
		update_option(
			$option . '_meta',
			array( 'email' => $email, 'when' => current_time( 'mysql' ) ),
			false
		);
	} else {
		delete_option( $option );
		delete_option( $option . '_meta' );
	}
	do_action( 'ans_tb_optout_changed', $email, (bool) $opted_out );
	return true;
}

/**
 * The unsubscribe URL for one address.
 *
 * Points at admin-post.php, NOT at the home page.
 *
 * The first cut used home_url() with query args, and on staging it did nothing
 * at all: Kinsta's full-page cache answered the request from the edge and PHP
 * never ran. The visitor got a perfectly normal home page, `init` never fired,
 * and the opt-out was never recorded. Verified by clicking the link and then
 * re-reading the flag - still opted in.
 *
 * That is the worst failure available to this feature. A broken unsubscribe
 * link is not neutral: the person believes they have opted out, receives the
 * next email anyway, and reports it as spam - which costs the sending domain
 * far more than the one address ever would.
 *
 * admin-post.php is WordPress's own endpoint for exactly this - a front-end
 * action performed by a logged-out visitor - and no page cache caches
 * /wp-admin/. It needs no rewrite rule and no published page, so there is
 * nothing an editor can accidentally delete.
 *
 * @param string $email
 * @return string
 */
function ans_tb_optout_link( $email ) {
	return add_query_arg(
		array(
			'action' => 'ans_tb_optout',
			'e'      => rawurlencode( ans_tb_optout_normalise( $email ) ),
			't'      => ans_tb_optout_token( $email ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** The put-me-back-on URL, offered on the confirmation page for a misclick. */
function ans_tb_resubscribe_link( $email ) {
	return add_query_arg( 'resub', '1', ans_tb_optout_link( $email ) );
}

/**
 * Handle a click on an unsubscribe link.
 *
 * Runs on init rather than on a page, so it needs no published page to exist
 * and cannot be broken by somebody tidying the page tree.
 */
function ans_tb_optout_handle_request() {
	$raw = null;
	if ( isset( $_GET['e'] ) ) {
		$raw = $_GET['e'];                 // admin-post.php - the link we send
	} elseif ( isset( $_GET['ans_optout'] ) ) {
		$raw = $_GET['ans_optout'];        // legacy home_url() shape, kept so an
	}                                      // already-sent link is never dead
	if ( null === $raw || ! isset( $_GET['t'] ) ) {
		return;
	}
	$email = ans_tb_optout_normalise( wp_unslash( $raw ) );
	$token = (string) wp_unslash( $_GET['t'] );

	if ( ! $email || ! is_email( $email ) || ! hash_equals( ans_tb_optout_token( $email ), $token ) ) {
		wp_die(
			esc_html__( 'That unsubscribe link is not valid. Please email info@arsnovasingers.org and we will take care of it for you.', 'ans-tb' ),
			esc_html__( 'Unsubscribe', 'ans-tb' ),
			array( 'response' => 400 )
		);
	}

	$resubscribing = ! empty( $_GET['resub'] );
	ans_tb_set_optout( $email, ! $resubscribing );

	$shown = esc_html( $email );
	if ( $resubscribing ) {
		$body = '<p>You are back on the list. We will let you know about upcoming concerts at <strong>' . $shown . '</strong>.</p>';
	} else {
		$body  = '<p>Done - we have removed <strong>' . $shown . '</strong> from Ars Nova Singers concert announcements.</p>';
		$body .= '<p>You will still receive tickets and order confirmations for anything you buy. Those are not announcements.</p>';
		$body .= '<p><a href="' . esc_url( ans_tb_resubscribe_link( $email ) ) . '">Put me back on the list</a> if you clicked this by accident.</p>';
	}

	wp_die(
		wp_kses_post( $body ),
		esc_html__( 'Ars Nova Singers', 'ans-tb' ),
		array( 'response' => 200, 'back_link' => false )
	);
}
/*
 * Registered on admin-post.php for BOTH logged-out and logged-in visitors -
 * _nopriv alone would fail for the one person most likely to test the link,
 * a signed-in administrator, and fail in the direction that looks like it
 * worked.
 *
 * `init` is kept as a fallback for the legacy home_url() link shape. It is a
 * no-op on any request that carries neither parameter.
 */
add_action( 'admin_post_nopriv_ans_tb_optout', 'ans_tb_optout_handle_request' );
add_action( 'admin_post_ans_tb_optout', 'ans_tb_optout_handle_request' );
add_action( 'init', 'ans_tb_optout_handle_request' );

/**
 * Add List-Unsubscribe to marketing mail.
 *
 * Mailbox providers weight this for deliverability and surface a native
 * unsubscribe control, which is a far better outcome than the recipient
 * reaching for the spam button - a spam report damages the sending domain for
 * every future email, transactional ones included.
 */
function ans_tb_optout_headers( $headers, $email_id, $object = null, $email = null ) {
	if ( ! in_array( (string) $email_id, ans_tb_marketing_email_ids(), true ) ) {
		return $headers;
	}
	$to = '';
	if ( is_object( $email ) && ! empty( $email->recipient ) ) {
		$to = (string) $email->recipient;
	} elseif ( is_object( $object ) && method_exists( $object, 'get_billing_email' ) ) {
		$to = (string) $object->get_billing_email();
	}
	if ( ! $to || ! is_email( $to ) ) {
		return $headers;
	}
	$headers .= 'List-Unsubscribe: <' . ans_tb_optout_link( $to ) . ">\r\n";
	$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
	return $headers;
}
add_filter( 'woocommerce_email_headers', 'ans_tb_optout_headers', 10, 4 );

/* -------------------------------------------------------------------------
 * REST - read and clear an opt-out
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( ANS_TB_NS, '/marketing/optout', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$email = ans_tb_optout_normalise( $req->get_param( 'email' ) );
				if ( ! $email || ! is_email( $email ) ) {
					return new WP_Error( 'bad_email', 'Pass ?email=someone@example.com', array( 'status' => 400 ) );
				}
				return array(
					'email'       => $email,
					'opted_out'   => ans_tb_is_opted_out( $email ),
					'meta'        => get_option( ans_tb_optout_option( $email ) . '_meta', null ),
					'optout_link' => ans_tb_optout_link( $email ),
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_tb_perm',
			'callback'            => function ( $req ) {
				$email = ans_tb_optout_normalise( $req->get_param( 'email' ) );
				if ( ! $email || ! is_email( $email ) ) {
					return new WP_Error( 'bad_email', 'An email address is required.', array( 'status' => 400 ) );
				}
				$out = $req->get_param( 'opted_out' );
				$out = ( null === $out ) ? true : (bool) $out;
				ans_tb_set_optout( $email, $out );
				return array( 'email' => $email, 'opted_out' => ans_tb_is_opted_out( $email ) );
			},
		),
	) );
} );
