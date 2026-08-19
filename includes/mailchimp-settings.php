<?php
/**
 * Ars Nova Ticketing Bridge - read/write the Tickera Mailchimp add-on settings.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Tickera Mailchimp add-on stores everything in one option,
 * `tc_mailchimp_settings`, and WordPress exposes no way to reach it: our own
 * `ars-nova/v1/option` route is read-only, and `ans-ops/v1/site/options` is
 * whitelisted to six core WordPress options. Configuring the add-on therefore
 * meant clicking through wp-admin, which is exactly the kind of step that gets
 * done differently on staging and Live and then drifts.
 *
 * THE API KEY IS DELIBERATELY NOT WRITABLE HERE.
 * Read masks it, and write refuses it. Two reasons, and the second is the one
 * that matters. First: an automation that can set a credential is an automation
 * that can leak one. Second, and concretely - on 2026-08-18 a plain read of the
 * WooCommerce payment-gateway settings returned this site's live Stripe secret
 * key in cleartext to a caller that had asked for nothing of the sort. That is
 * the failure mode this endpoint is built to not repeat. The Mailchimp key is
 * entered once, by a human, in wp-admin.
 *
 * @package ars-nova-ticketing-bridge
 * @since   1.9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The option the Tickera Mailchimp add-on reads. */
if ( ! defined( 'ANS_TB_MC_OPTION' ) ) {
    define( 'ANS_TB_MC_OPTION', 'tc_mailchimp_settings' );
}

/**
 * Keys this endpoint will write. Anything else in the payload is reported back
 * as ignored rather than silently dropped - the same discipline v1.9.3 added to
 * the event updater, and for the same reason: a 200 that quietly discarded half
 * the request cost a session once already.
 *
 * `api_key` is absent on purpose. See the file docblock.
 *
 * @return array key => 'bool'|'text'|'choice'
 */
function ans_tb_mc_writable_keys() {
    return array(
        'enable_mailchimp_newsletter' => 'choice_yesno',
        'server_prefix'               => 'text',
        'list_id'                     => 'text',
        'tc_emails_to_collect'        => 'choice_collect',
        'double_optin'                => 'bool',
        'enable_confirmation'         => 'bool',
        'confirmation_label'          => 'text',
        'tag'                         => 'choice_tag',
    );
}

/**
 * Mask a credential for display: enough to recognise, not enough to use.
 *
 * @param string $value Raw value.
 * @return string
 */
function ans_tb_mask( $value ) {
    $value = (string) $value;
    $len   = strlen( $value );
    if ( 0 === $len ) {
        return '';
    }
    return str_repeat( '*', max( 4, $len - 4 ) ) . substr( $value, -4 );
}

/**
 * GET  /tickera/mailchimp-settings  - current settings, API key masked.
 * POST /tickera/mailchimp-settings  - update whitelisted keys.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( ANS_TB_NS, '/tickera/mailchimp-settings', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_tb_mc_get_settings',
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => 'ans_tb_perm',
            'callback'            => 'ans_tb_mc_set_settings',
        ),
    ) );
} );

/**
 * Read the settings, with the credential masked.
 *
 * @return array
 */
function ans_tb_mc_get_settings() {
    $raw = get_option( ANS_TB_MC_OPTION, array() );
    $raw = is_array( $raw ) ? $raw : array();

    $out = array();
    foreach ( $raw as $k => $v ) {
        $out[ $k ] = ( 'api_key' === $k ) ? ans_tb_mask( $v ) : $v;
    }

    return array(
        'option'        => ANS_TB_MC_OPTION,
        'configured'    => ! empty( $raw ),
        'has_api_key'   => ! empty( $raw['api_key'] ),
        'settings'      => $out,
        'writable_keys' => array_keys( ans_tb_mc_writable_keys() ),
        'note'          => 'api_key is masked on read and cannot be written through this endpoint. Set it in wp-admin: Tickera > Settings > Mailchimp.',
    );
}

/**
 * Write whitelisted settings, preserving everything already stored.
 *
 * Merges rather than replaces: the add-on writes keys this endpoint does not
 * know about, and clobbering them would be a silent regression of exactly the
 * kind PROJECT_RULES section 14 warns about.
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function ans_tb_mc_set_settings( $req ) {
    $p = ans_tb_params( $req );
    if ( empty( $p ) || ! is_array( $p ) ) {
        return new WP_Error( 'empty_payload', 'No settings supplied.', array( 'status' => 400 ) );
    }
    if ( array_key_exists( 'api_key', $p ) ) {
        return new WP_Error(
            'api_key_refused',
            'The Mailchimp API key cannot be set through this endpoint, by design. Enter it in wp-admin: Tickera > Settings > Mailchimp.',
            array( 'status' => 400 )
        );
    }

    $allowed = ans_tb_mc_writable_keys();
    $current = get_option( ANS_TB_MC_OPTION, array() );
    $current = is_array( $current ) ? $current : array();

    $applied = array();
    $ignored = array();

    foreach ( $p as $key => $value ) {
        if ( ! isset( $allowed[ $key ] ) ) {
            $ignored[] = $key;
            continue;
        }
        switch ( $allowed[ $key ] ) {
            case 'bool':
                $clean = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? '1' : '';
                break;
            case 'choice_yesno':
                $clean = in_array( (string) $value, array( 'yes', 'no' ), true ) ? (string) $value : 'yes';
                break;
            case 'choice_collect':
                $clean = in_array( (string) $value, array( 'buyer_emails', 'owner_emails', 'both_emails' ), true )
                    ? (string) $value : 'buyer_emails';
                break;
            case 'choice_tag':
                $clean = in_array( (string) $value, array( 'event', 'ticket_type' ), true ) ? (string) $value : 'event';
                break;
            default:
                $clean = sanitize_text_field( (string) $value );
        }
        $current[ $key ] = $clean;
        $applied[ $key ] = $clean;
    }

    if ( empty( $applied ) ) {
        return new WP_Error( 'nothing_applied', 'None of the supplied keys are writable.', array(
            'status'        => 400,
            'ignored'       => $ignored,
            'writable_keys' => array_keys( $allowed ),
        ) );
    }

    update_option( ANS_TB_MC_OPTION, $current );

    $verify = get_option( ANS_TB_MC_OPTION, array() );
    $verify = is_array( $verify ) ? $verify : array();
    $failed = array();
    foreach ( $applied as $k => $v ) {
        if ( ! array_key_exists( $k, $verify ) || (string) $verify[ $k ] !== (string) $v ) {
            $failed[] = $k;
        }
    }

    return array(
        'ok'             => empty( $failed ),
        'applied'        => $applied,
        'ignored_fields' => $ignored,
        'not_persisted'  => $failed,
        'settings'       => ans_tb_mc_get_settings(),
    );
}
