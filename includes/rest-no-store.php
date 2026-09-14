<?php
/**
 * No-store cache headers for every ars-nova/v1 REST response.
 *
 * -- Why this exists ----------------------------------------------------------
 *
 * On 2026-09-13 GET /wp-json/ars-nova/v1/reminder/schedule was caught returning
 * a byte-identical body twice, minutes apart, both reporting now=11:31:43 while
 * the site clock said 12:09. Appending ?cb=... returned the true time. That is
 * Kinsta's full-page edge cache answering a diagnostic route, not WordPress.
 *
 * A route whose entire job is reporting WHAT IS DUE RIGHT NOW is worse than
 * useless when it is stale: it is the thing a human reads before deciding
 * whether to fire a manual send. A cached board showing a send as still pending
 * invites a second send; one showing it as sent hides a miss. The failure is
 * silent in both directions - the body looks perfectly well-formed, and the
 * only tell is a timestamp nobody was watching.
 *
 * -- The scope decision: the whole namespace, not just GETs --------------------
 *
 * Caches key on GET, so in principle only GETs can be served from the edge and
 * a GET-only rule would be sufficient today. It is still the wrong rule.
 *
 * Every route in ars-nova/v1 is either a diagnostic reporting live state or an
 * operation whose response reports what it just changed. Not one of them is
 * ever correct to answer from a cache, so there is no case the narrower rule
 * protects and no cost to the broader one - a POST response carrying
 * Cache-Control: no-store is simply describing a fact about itself. Meanwhile
 * the narrow rule adds a condition that can be wrong: a route reached by a
 * method we did not anticipate, a proxy with its own idea of what is
 * cacheable, or a future GET wrapper over a POST handler would each quietly
 * fall outside it. One unconditional rule over one namespace has nothing to
 * get wrong, and reads in one line.
 *
 * This plugin's own namespace only. Core routes, WooCommerce routes and
 * anything else on this site are left exactly as they are.
 *
 * -- What is actually sent ----------------------------------------------------
 *
 * Cache-Control covers standards-compliant caches; Pragma and Expires cover
 * older intermediaries; X-Accel-Expires: 0 is the one nginx-family edges
 * (Kinsta's included) act on directly. Headers are a REQUEST, not a guarantee -
 * an edge is free to ignore all of them. That is precisely why the diagnostic
 * routes also carry their own generated_at timestamp: this file makes staleness
 * unlikely, and the timestamp is what lets a human NOTICE it anyway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The namespace this file guards, as a route prefix.
 *
 * ANS_TB_NS is defined in the main plugin file; the literal is a fallback for
 * the case where this include is reached without it, so a missing constant
 * degrades to "still correct" rather than to "guards nothing".
 *
 * @return string e.g. '/ars-nova/v1'
 */
function ans_tb_no_store_route_prefix() {
	$ns = defined( 'ANS_TB_NS' ) ? ANS_TB_NS : 'ars-nova/v1';

	return '/' . trim( (string) $ns, '/' );
}

/**
 * Stamp no-store headers on every response in this plugin's REST namespace.
 *
 * Hooked at a late priority so anything else that touches headers has already
 * had its say - this is the last word on cacheability for these routes.
 *
 * @param WP_HTTP_Response|mixed $result  Response about to be served.
 * @param WP_REST_Server|mixed   $server  Server instance (unused).
 * @param WP_REST_Request|mixed  $request The request being answered.
 * @return WP_HTTP_Response|mixed The response, unchanged except for headers.
 */
function ans_tb_no_store_rest_headers( $result, $server, $request ) {
	// A filter must return what it was handed, whatever that is. Anything that
	// is not a WP_HTTP_Response has no header() to call, and a fatal here would
	// take down every REST route on the site.
	if ( ! ( $result instanceof WP_HTTP_Response ) || ! ( $request instanceof WP_REST_Request ) ) {
		return $result;
	}

	$prefix = ans_tb_no_store_route_prefix();
	$route  = (string) $request->get_route();

	// Prefix match, not a substring search: '/ars-nova/v1' must be where the
	// route STARTS, or an unrelated namespace that merely mentions it would be
	// caught too.
	if ( 0 !== strpos( $route, $prefix ) ) {
		return $result;
	}

	$result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$result->header( 'Pragma', 'no-cache' );
	$result->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );

	// nginx-family edges (Kinsta's included) act on this one directly.
	$result->header( 'X-Accel-Expires', '0' );

	return $result;
}
add_filter( 'rest_post_dispatch', 'ans_tb_no_store_rest_headers', 999, 3 );
