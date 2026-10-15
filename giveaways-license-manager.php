<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central licensing for Giveaways Add-ons.
 *
 * Stores a single license key and validates entitlements per module.
 */

function giveaways_get_domain() {
    // home_url() reflects what the admin configured in Settings > General —
    // more reliable than HTTP_HOST behind reverse proxies and load balancers.
    $host = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( empty( $host ) ) {
        $host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
    }

    $host = strtolower( (string) $host );
    $host = preg_replace( '/:\d+$/', '', $host );
    $host = preg_replace( '/^www\./', '', $host );

    return $host;
}

/**
 * Map module keys (as used by giveaways_modules()) to their validation endpoints.
 * instant_email is intentionally excluded (always-on module).
 */
define('GIVEAWAYS_LICENCE_SERVER', 'https://licencing.kriscreates.co.uk');

function giveaways_license_endpoints() {
    return [
        'spin'            => GIVEAWAYS_LICENCE_SERVER . '/spinbolt/validate.php',
        'slots'           => GIVEAWAYS_LICENCE_SERVER . '/slots/validate.php',
        'scratchcard'     => GIVEAWAYS_LICENCE_SERVER . '/scratchcardgame/validate.php',
        'instant_display' => GIVEAWAYS_LICENCE_SERVER . '/instant-reveal/validate.php',
        'football'        => GIVEAWAYS_LICENCE_SERVER . '/football/validate.php',
        'racecar'         => GIVEAWAYS_LICENCE_SERVER . '/racecar/validate.php',
        'grabclaw'        => GIVEAWAYS_LICENCE_SERVER . '/grabclaw/validate.php',
        'balloon_pop'     => GIVEAWAYS_LICENCE_SERVER . '/balloon-pop/validate.php',
        'darts'           => GIVEAWAYS_LICENCE_SERVER . '/darts/validate.php',
        'boxing'          => GIVEAWAYS_LICENCE_SERVER . '/boxing/validate.php',
    ];
}

/**
 * Maps plugin module keys to licensing server product slugs.
 */
function giveaways_module_to_product() {
    return [
        'spin'            => 'spinbolt',
        'slots'           => 'slots',
        'scratchcard'     => 'scratchcardgame',
        'instant_display' => 'instant-reveal',
        'football'        => 'footballgame',
        'racecar'         => 'racecar',
        'grabclaw'        => 'grabclaw',
        'balloon_pop'     => 'balloon-pop',
        'darts'           => 'dartsgame',
        'boxing'          => 'boxing',
    ];
}

/**
 * Maps plugin module keys to the folder name inside the plugin directory.
 */
function giveaways_module_to_folder() {
    return [
        'spin'            => 'spin',
        'slots'           => 'slots',
        'scratchcard'     => 'scratchcard',
        'instant_display' => 'instant-reveal-display-addon',
        'football'        => 'football',
        'racecar'         => 'racecar',
        'grabclaw'        => 'grabclaw',
        'balloon_pop'     => 'balloon-pop',
        'darts'           => 'darts',
        'boxing'          => 'boxing',
    ];
}

/**
 * Returns true if the module's folder is present inside the plugin directory.
 * Used to distinguish "licensed but not yet downloaded" from "ready to enable".
 */
function giveaways_module_folder_exists( $module_key ) {
    // A module counts as "installed" when its file is registered and present —
    // whether bundled here (free modules) or supplied by the CompKit Game Engine Premium
    // plugin via the giveaways_register_modules filter.
    if ( function_exists( 'giveaways_modules' ) ) {
        $modules = giveaways_modules();
        if ( ! empty( $modules[ $module_key ]['file'] ) && file_exists( $modules[ $module_key ]['file'] ) ) {
            return true;
        }
    }
    // Legacy fallback: a physical folder inside this plugin.
    $folders = giveaways_module_to_folder();
    $folder  = $folders[ $module_key ] ?? '';
    return $folder !== '' && is_dir( GIVEAWAYS_PATH . $folder );
}

function giveaways_rmdir_recursive( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }
    foreach ( scandir( $dir ) as $item ) {
        if ( $item === '.' || $item === '..' ) {
            continue;
        }
        $full = $dir . DIRECTORY_SEPARATOR . $item;
        if ( is_dir( $full ) ) {
            giveaways_rmdir_recursive( $full );
        } else {
            wp_delete_file( $full );
        }
    }
    return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing an empty dir after recursive delete; WP_Filesystem has no equivalent
}

function giveaways_license_status_option_key() {
    return 'giveaways_license_status';
}

function giveaways_get_license_status() {
    $status = get_option(giveaways_license_status_option_key(), []);
    return is_array($status) ? $status : [];
}

function giveaways_get_license_expiry() {
    $expiry = get_option('giveaways_license_expiry', []);
    return is_array($expiry) ? $expiry : [];
}

/*
|──────────────────────────────────────────────────────────────────────────────
| Signed entitlement grants
|──────────────────────────────────────────────────────────────────────────────
| The licensing server signs every answer with an Ed25519 key. Only the PUBLIC
| half lives here, so this file — which every customer receives in plain text —
| cannot be used to mint an entitlement, to stand up a look-alike licensing
| server, or to hand-edit the stored grant in wp_options into a "yes".
|
| The signature covers the licence key hash, the domain, the product, the
| verdict and a client-chosen nonce, so a captured grant cannot be replayed for
| a different site, a different game, or after it expires.
|
| Verification is enforced only when a public key is present. Ship the plugin
| with the real key from tools/generate-signing-key.php — with this left empty
| the plugin falls back to the old unauthenticated behaviour.
*/
if ( ! defined( 'GIVEAWAYS_GRANT_PUBKEY' ) ) {
    define( 'GIVEAWAYS_GRANT_PUBKEY', 'DKvqoh9pWjbaAFhIKTVxOCanI2aKtI3oAckZrAQHcO0=' );
}

/** Where verified grants are kept. Tampering with it is detected, not trusted. */
function giveaways_grants_option_key() {
    return 'giveaways_license_grants';
}

function giveaways_grant_verification_enabled() {
    return GIVEAWAYS_GRANT_PUBKEY !== ''
        && function_exists('sodium_crypto_sign_verify_detached');
}

/**
 * Rebuild the exact byte string the server signed.
 * Field order is part of the protocol and must match lib/grant.php.
 */
function giveaways_grant_payload( array $g ) {
    return implode('|', [
        'v1',
        (string) ( $g['product']      ?? '' ),
        (string) ( $g['license_hash'] ?? '' ),
        (string) ( $g['domain']       ?? '' ),
        ! empty( $g['valid'] ) ? '1' : '0',
        (string) ( $g['code']     ?? '' ),
        (string) ( $g['expires']  ?? '' ),
        (string) ( $g['interval'] ?? '' ),
        (string) ( $g['nonce']    ?? '' ),
        (string) (int) ( $g['issued_at'] ?? 0 ),
        (string) (int) ( $g['grant_exp'] ?? 0 ),
    ]);
}

/**
 * Is this grant genuine, current, and about this site's licence for this module?
 *
 * Every clause matters. Dropping the domain check makes one paid licence work on
 * unlimited sites; dropping the licence hash check lets a grant for a cheap
 * module unlock an expensive one; dropping grant_exp turns a single captured
 * "valid" into a permanent one.
 *
 * @param array       $grant    Grant object as returned by the server.
 * @param string      $sig      Base64 detached signature.
 * @param string      $module   Module key.
 * @param string      $license  The licence key this site holds.
 * @param string      $domain   This site's domain.
 * @param string|null $nonce    When set, the grant must echo this exact nonce
 *                              (fresh answers only — replays are rejected).
 * @return bool
 */
function giveaways_verify_grant( $grant, $sig, $module, $license, $domain, $nonce = null ) {

    if ( ! giveaways_grant_verification_enabled() ) {
        return false;
    }

    if ( ! is_array( $grant ) || ! is_string( $sig ) || $sig === '' ) {
        return false;
    }

    $products = giveaways_module_to_product();
    $product  = $products[ $module ] ?? '';

    if ( $product === '' || ( $grant['product'] ?? '' ) !== $product ) {
        return false;
    }

    if ( ! hash_equals( hash( 'sha256', strtoupper( trim( (string) $license ) ) ), (string) ( $grant['license_hash'] ?? '' ) ) ) {
        return false;
    }

    if ( (string) ( $grant['domain'] ?? '' ) !== (string) $domain ) {
        return false;
    }

    if ( $nonce !== null && ! hash_equals( (string) $nonce, (string) ( $grant['nonce'] ?? '' ) ) ) {
        return false;
    }

    $now = time();

    // Reject a grant issued in the future, or long enough ago that it is stale
    // regardless of its own expiry — covers a clock pushed backwards locally.
    $issued = (int) ( $grant['issued_at'] ?? 0 );
    if ( $issued <= 0 || $issued > $now + 600 ) {
        return false;
    }

    if ( (int) ( $grant['grant_exp'] ?? 0 ) <= $now ) {
        return false;
    }

    $pub = base64_decode( GIVEAWAYS_GRANT_PUBKEY, true );
    $raw = base64_decode( $sig, true );

    if ( $pub === false || $raw === false || strlen( $pub ) !== 32 ) {
        return false;
    }

    try {
        return sodium_crypto_sign_verify_detached( $raw, giveaways_grant_payload( $grant ), $pub );
    } catch ( Exception $e ) {
        return false;
    }
}

function giveaways_get_stored_grants() {
    $grants = get_option( giveaways_grants_option_key(), [] );
    return is_array( $grants ) ? $grants : [];
}

/** Drop the per-request memo so a re-validation is reflected immediately. */
function giveaways_reset_grant_memo() {
    global $giveaways_grant_memo;
    $giveaways_grant_memo = [];
}

function giveaways_store_grant( $module, $grant, $sig ) {
    $grants            = giveaways_get_stored_grants();
    $grants[ $module ] = [ 'grant' => $grant, 'sig' => $sig ];
    update_option( giveaways_grants_option_key(), $grants, false );
    giveaways_reset_grant_memo();
}

function giveaways_forget_grant( $module ) {
    $grants = giveaways_get_stored_grants();
    if ( isset( $grants[ $module ] ) ) {
        unset( $grants[ $module ] );
        update_option( giveaways_grants_option_key(), $grants, false );
    }
    giveaways_reset_grant_memo();
}

/**
 * Ask for a re-check as soon as possible, without blocking this request.
 *
 * A grant stops verifying for legitimate reasons as well as hostile ones — the
 * site moved domain, the customer pasted a new key, or the plugin was just
 * updated and no grant has ever been fetched. Waiting for the daily cron in
 * those cases would take a paying customer's games offline for up to 24 hours,
 * so nudge the scheduler instead.
 *
 * Debounced to once per 10 minutes: this can be reached from the front end, and
 * a site whose licence genuinely is invalid must not queue an event per view.
 */
function giveaways_queue_revalidation() {

    if ( get_transient( 'giveaways_revalidate_queued' ) ) {
        return;
    }

    set_transient( 'giveaways_revalidate_queued', 1, 10 * MINUTE_IN_SECONDS );

    // Deliberately not a direct HTTP call: this runs on front-end page loads,
    // where a 15-second licence request would be a visible stall.
    wp_schedule_single_event( time() + 30, 'giveaways_daily_license_validation' );
}

/**
 * The stored grant for a module, but only if it still verifies right now.
 * Returns null for anything absent, tampered with, expired, or belonging to a
 * different site or licence key.
 */
function giveaways_get_valid_grant( $module ) {
    $grants = giveaways_get_stored_grants();
    $entry  = $grants[ $module ] ?? null;

    $keys    = get_option( 'giveaways_license_keys', [] );
    $license = is_array( $keys ) ? ( $keys[ $module ] ?? '' ) : '';

    // No key saved for this module — nothing to re-check, this is just a module
    // the customer doesn't own.
    if ( trim( (string) $license ) === '' ) {
        return null;
    }

    // A key is saved but there is no grant behind it. Two ways to get here that
    // both deserve a fast recovery rather than a day in the dark: the plugin was
    // just updated to this version (no grant has ever been stored), or the last
    // answer was a denial that has since been fixed.
    if ( ! is_array( $entry ) || empty( $entry['grant'] ) ) {
        giveaways_queue_revalidation();
        return null;
    }

    $ok = giveaways_verify_grant(
        $entry['grant'],
        (string) ( $entry['sig'] ?? '' ),
        $module,
        $license,
        giveaways_get_domain()
    );

    if ( ! $ok ) {
        // Expired, or bound to a domain or key this site no longer uses. Get a
        // fresh answer; if the licence really is dead the new grant says so.
        giveaways_queue_revalidation();
        return null;
    }

    return $entry['grant'];
}

/**
 * The single gate every premium module is loaded behind.
 *
 * With a public key configured this reads a signed grant rather than a boolean
 * option, so setting `giveaways_license_status` to true by hand — via SQL, WP-CLI
 * or a plugin — no longer grants anything.
 */
function giveaways_is_module_licensed($module_key) {

    if ( giveaways_grant_verification_enabled() ) {
        // Memoised per request — the gate is consulted several times per page
        // load. Cleared whenever a grant is written or removed.
        global $giveaways_grant_memo;
        if ( ! is_array( $giveaways_grant_memo ) ) {
            $giveaways_grant_memo = [];
        }
        if ( ! array_key_exists( $module_key, $giveaways_grant_memo ) ) {
            $grant = giveaways_get_valid_grant( $module_key );
            $giveaways_grant_memo[ $module_key ] = ( $grant !== null && ! empty( $grant['valid'] ) );
        }
        return $giveaways_grant_memo[ $module_key ];
    }

    // No public key compiled in — legacy behaviour.
    $status = giveaways_get_license_status();
    return !empty($status[$module_key]);
}

/*
|──────────────────────────────────────────────────────────────────────────────
| Server-served game assets
|──────────────────────────────────────────────────────────────────────────────
| Some games' JavaScript is not shipped in the download at all — it is served
| from the licence server against a token issued with the grant. That is the one
| protection a modified copy of this plugin cannot defeat by deleting a check:
| the code was never on disk to begin with.
|
| The token is verified server-side on every asset request, so it needs no
| signature here. It expires with the grant it arrived in, which is what stops a
| cancelled licence from keeping the game code.
*/

/** Remember the asset token that arrived with a validation answer. */
function giveaways_store_asset( $module, $asset ) {
    $grants = giveaways_get_stored_grants();

    if ( ! isset( $grants[ $module ] ) || ! is_array( $grants[ $module ] ) ) {
        return;
    }

    if ( is_array( $asset ) && ! empty( $asset['token'] ) ) {
        $grants[ $module ]['asset'] = [
            'module'  => sanitize_text_field( $asset['module'] ?? '' ),
            'token'   => sanitize_text_field( $asset['token'] ),
            'expires' => (int) ( $asset['expires'] ?? 0 ),
        ];
    } else {
        unset( $grants[ $module ]['asset'] );
    }

    update_option( giveaways_grants_option_key(), $grants, false );
}

/**
 * URL for a remote-served asset, or '' when this site currently has no right to it.
 *
 * Returning '' is a real answer, not an error: it means the licence lapsed, the
 * token expired, or the site has never successfully validated. The caller should
 * enqueue nothing, and the game simply does not appear.
 *
 * @param string $module Module key, e.g. 'darts'.
 * @param string $file   Path inside the module folder, e.g. 'assets/darts.js'
 *                       or 'engine/sprites.js'. The server reads a bare
 *                       filename as assets/<file>, which is what every plugin
 *                       released before racecar sends. rawurlencode below turns
 *                       the slash into %2F; add_query_arg does not encode
 *                       values, so this is a single encoding, not a double one.
 * @param string $ver    Module version, for cache busting.
 */
function giveaways_module_asset_url( $module, $file, $ver = '' ) {

    if ( ! giveaways_is_module_licensed( $module ) ) {
        return '';
    }

    $grants = giveaways_get_stored_grants();
    $asset  = $grants[ $module ]['asset'] ?? null;

    if ( ! is_array( $asset ) || empty( $asset['token'] ) ) {
        giveaways_queue_revalidation();
        return '';
    }

    if ( (int) $asset['expires'] <= time() ) {
        // The grant is still good but the asset token has aged out — ask for a
        // fresh one rather than serving a URL the server will refuse.
        giveaways_queue_revalidation();
        return '';
    }

    return add_query_arg(
        [
            'm' => rawurlencode( $asset['module'] ?: $module ),
            'f' => rawurlencode( $file ),
            'd' => rawurlencode( giveaways_get_domain() ),
            'e' => (int) $asset['expires'],
            't' => rawurlencode( $asset['token'] ),
            'v' => rawurlencode( $ver ),
        ],
        GIVEAWAYS_LICENCE_SERVER . '/api/asset.php'
    );
}

function giveaways_clear_license_cache() {
    foreach (array_keys(giveaways_license_endpoints()) as $key) {
        delete_transient('giveaways_license_cache_' . $key);
    }

    // Entitlement decides which modules the premium package contains, so a
    // licence change can change the available update. One button covers both.
    if ( function_exists( 'giveaways_flush_update_cache' ) ) {
        giveaways_flush_update_cache();
    }
}

/**
 * Validate a single module entitlement and cache result.
 */
function giveaways_validate_module_license($module_key, $license = null) {
    $endpoints = giveaways_license_endpoints();
    if (empty($endpoints[$module_key])) {
        return false;
    }

    $keys = get_option('giveaways_license_keys', []);

    $license = $license === null
        ? ($keys[$module_key] ?? '')
        : (string) $license;

    $license = trim($license);

    if ($license === '') {
        return false;
    }


    $domain = giveaways_get_domain();

    $cache_key = 'giveaways_license_cache_' . $module_key;
    $cached = get_transient($cache_key);
    if (is_array($cached) && isset($cached['license'], $cached['domain'], $cached['valid'])) {
        if ($cached['license'] === $license && $cached['domain'] === $domain) {
            return (bool) $cached['valid'];
        }
    }

    // Fresh nonce per request. The server echoes it inside the signed grant, so
    // a response captured earlier (or served by a look-alike server pointed at
    // by a hosts file) cannot be passed off as this request's answer.
    $nonce = bin2hex( random_bytes( 16 ) );

    $response = wp_remote_post($endpoints[$module_key], [
        'timeout'   => 15,
        'sslverify' => true,
        'body'      => [
            'license' => $license,
            'domain'  => $domain,
            'nonce'   => $nonce,
        ],
    ]);

    $valid          = false;
    $server_replied = false;

    if ( is_wp_error( $response ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( 'giveaways_license [' . $module_key . '] request failed: ' . $response->get_error_message() );
    } elseif ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( 'giveaways_license [' . $module_key . '] HTTP ' . wp_remote_retrieve_response_code( $response ) );
    } else {
        $server_replied = true;
        $body  = wp_remote_retrieve_body( $response );
        $data  = json_decode( $body, true );
        $valid = is_array( $data ) && ! empty( $data['valid'] );

        if ( giveaways_grant_verification_enabled() ) {
            // The signature — not the `valid` flag — is what decides. A forged
            // or replayed body fails here and is treated as a denial.
            $grant_ok = is_array( $data ) && giveaways_verify_grant(
                $data['grant']     ?? null,
                (string) ( $data['grant_sig'] ?? '' ),
                $module_key,
                $license,
                $domain,
                $nonce
            );

            if ( $grant_ok ) {
                giveaways_store_grant( $module_key, $data['grant'], $data['grant_sig'] );
                giveaways_store_asset( $module_key, $data['asset'] ?? null );
                $valid = ! empty( $data['grant']['valid'] );
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'giveaways_license [' . $module_key . '] unsigned or unverifiable response — refusing it' );
                giveaways_forget_grant( $module_key );
                $valid = false;
            }
        }

        if ( ! $valid ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'giveaways_license [' . $module_key . '] invalid: ' . $body );
        }

        $expiry_store = giveaways_get_license_expiry();
        if ( $valid && ! empty( $data['expires'] ) ) {
            $expiry_store[ $module_key ] = [
                'expires'  => sanitize_text_field( $data['expires'] ),
                'interval' => sanitize_text_field( $data['interval'] ?? '' ),
                'code'     => sanitize_text_field( $data['code']     ?? '' ),
            ];
        } else {
            unset( $expiry_store[ $module_key ] );
        }
        update_option( 'giveaways_license_expiry', $expiry_store );
    }

    if ( $server_replied ) {
        // Definitive answer — cache for the full day.
        set_transient( $cache_key, [
            'license' => $license,
            'domain'  => $domain,
            'valid'   => (bool) $valid,
            'time'    => time(),
        ], DAY_IN_SECONDS );
    } else {
        // Server unreachable. Fall back to the last signed grant if one is still
        // inside its window — that is what makes an outage survivable without
        // creating an unlimited offline mode, because the window is set and
        // signed by the server and cannot be extended from here. Past it, or
        // with no grant, access stops.
        $grant = giveaways_get_valid_grant( $module_key );
        $valid = ( $grant !== null && ! empty( $grant['valid'] ) );

        set_transient( $cache_key, [
            'license' => $license,
            'domain'  => $domain,
            'valid'   => $valid,
            'time'    => time(),
        ], 5 * MINUTE_IN_SECONDS );
    }

    return (bool) $valid;
}

/**
 * Validate all module entitlements and store in a single option.
 */
function giveaways_validate_all_licenses() {

    $keys = get_option('giveaways_license_keys', []);


    $results = [];
    foreach (array_keys(giveaways_license_endpoints()) as $module_key) {
        $results[$module_key] = giveaways_validate_module_license($module_key);
    }


    $payload = $results;
    $payload['_meta'] = [
        'checked_at' => time(),
        'domain' => giveaways_get_domain(),
        'license_present' => !empty($keys),
    ];


    update_option(giveaways_license_status_option_key(), $payload);

    return $payload;
}

/**
 * Daily re-validation.
 */
add_action('giveaways_daily_license_validation', function () {

    $keys = get_option('giveaways_license_keys', []);

    if (empty($keys)) {
        return;
    }

    giveaways_validate_all_licenses();
});


add_action('init', function () {
    if (!wp_next_scheduled('giveaways_daily_license_validation')) {
        wp_schedule_event(time(), 'daily', 'giveaways_daily_license_validation');
    }
});

/**
 * First run after updating to signed grants.
 *
 * A site upgrading from an earlier version has licence keys but no grant behind
 * them, and the gate needs a grant. Left to the daily cron that would mean a
 * paying customer's games disappearing for up to a day right after an update.
 *
 * Fetch them synchronously on the first admin page load instead — wp-admin can
 * absorb the round trip, the front end cannot. Runs once: as soon as any grant
 * is stored the early return takes over, and the transient stops a site with a
 * genuinely dead licence from retrying on every page.
 */
add_action( 'admin_init', function () {

    if ( ! giveaways_grant_verification_enabled() || wp_doing_ajax() ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $keys = array_filter( array_map( 'trim', (array) get_option( 'giveaways_license_keys', [] ) ) );

    if ( empty( $keys ) || ! empty( giveaways_get_stored_grants() ) ) {
        return;
    }

    if ( get_transient( 'giveaways_grant_bootstrap' ) ) {
        return;
    }

    set_transient( 'giveaways_grant_bootstrap', 1, 10 * MINUTE_IN_SECONDS );

    giveaways_validate_all_licenses();
} );

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Initiate Stripe Checkout
| Returns a Stripe Checkout URL for the customer to pay on.
|──────────────────────────────────────────────────────────────────────────────
*/
add_action('wp_ajax_giveaways_buy_license', function () {

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    check_ajax_referer('giveaways_buy_license', '_nonce');

    $module   = sanitize_text_field(wp_unslash($_POST['module']   ?? ''));
    $interval_raw = sanitize_text_field(wp_unslash($_POST['interval'] ?? ''));
    $interval = in_array($interval_raw, ['monthly', 'yearly'], true) ? $interval_raw : 'monthly';
    $map      = giveaways_module_to_product();
    $product  = $map[$module] ?? '';

    if (!$product) wp_send_json_error('Unknown module');

    $domain      = giveaways_get_domain();
    $success_url = admin_url('admin.php?page=giveaways-addons&compkit_module=' . $module);
    $cancel_url  = admin_url('admin.php?page=giveaways-addons');

    $response = wp_remote_post(GIVEAWAYS_LICENCE_SERVER . '/api/create-checkout.php', [
        'timeout' => 15,
        'body'    => [
            'product'     => $product,
            'interval'    => $interval,
            'domain'      => $domain,
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Could not reach licensing server');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['url'])) {
        wp_send_json_success(['url' => $body['url']]);
    }

    wp_send_json_error($body['error'] ?? 'Checkout creation failed');
});

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Collect license after Stripe redirect
| Called when the admin page reloads with ?compkit_session=cs_xxx
|──────────────────────────────────────────────────────────────────────────────
*/
add_action('wp_ajax_giveaways_collect_license', function () {

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    check_ajax_referer('giveaways_buy_license', '_nonce');

    $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
    $module     = sanitize_text_field(wp_unslash($_POST['module']     ?? ''));

    if (!$session_id || !$module) wp_send_json_error('Missing parameters');

    $domain = giveaways_get_domain();

    $response = wp_remote_post(GIVEAWAYS_LICENCE_SERVER . '/api/checkout-success.php', [
        'timeout' => 15,
        'body'    => [
            'session_id' => $session_id,
            'domain'     => $domain,
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Could not verify payment');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['license_key'])) {
        wp_send_json_error($body['error'] ?? 'No license key returned');
    }

    $key = sanitize_text_field($body['license_key']);

    // Save and validate the key
    $keys           = get_option('giveaways_license_keys', []);
    $keys[$module]  = $key;
    update_option('giveaways_license_keys', $keys);

    giveaways_clear_license_cache();
    $valid = giveaways_validate_module_license($module, $key);

    // Auto-enable only if the module folder is already present (bundled version).
    // If the folder is missing the customer needs to install the module first.
    $installed = $valid && giveaways_module_folder_exists( $module );
    if ( $installed ) {
        $enabled_modules          = get_option( 'giveaways_enabled_modules', [] );
        $enabled_modules[$module] = 1;
        update_option( 'giveaways_enabled_modules', $enabled_modules );
    }

    wp_send_json_success([
        'license_key' => $key,
        'valid'       => $valid,
        'installed'   => $installed,
        'enabled'     => $installed,
        'expires'     => $body['expires'] ?? null,
        'interval'    => $body['interval'] ?? 'monthly',
    ]);
});

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Initiate Stripe Checkout for a Bundle
|──────────────────────────────────────────────────────────────────────────────
*/
add_action('wp_ajax_giveaways_buy_bundle', function () {

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    check_ajax_referer('giveaways_buy_license', '_nonce');

    $bundle   = sanitize_text_field(wp_unslash($_POST['bundle']   ?? ''));
    $interval_raw = sanitize_text_field(wp_unslash($_POST['interval'] ?? ''));
    $interval = in_array($interval_raw, ['monthly', 'yearly'], true) ? $interval_raw : 'monthly';

    if (!$bundle) wp_send_json_error('Unknown bundle');

    $domain      = giveaways_get_domain();
    $success_url = admin_url('admin.php?page=giveaways-addons&compkit_bundle=' . urlencode($bundle));
    $cancel_url  = admin_url('admin.php?page=giveaways-addons');

    $response = wp_remote_post(GIVEAWAYS_LICENCE_SERVER . '/api/create-checkout.php', [
        'timeout' => 15,
        'body'    => [
            'product'     => $bundle,
            'interval'    => $interval,
            'domain'      => $domain,
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Could not reach licensing server');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['url'])) {
        wp_send_json_success(['url' => $body['url']]);
    }

    wp_send_json_error($body['error'] ?? 'Checkout creation failed');
});

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Collect bundle licenses after Stripe redirect
|──────────────────────────────────────────────────────────────────────────────
*/
add_action('wp_ajax_giveaways_collect_bundle', function () {

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    check_ajax_referer('giveaways_buy_license', '_nonce');

    $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));

    if (!$session_id) wp_send_json_error('Missing session_id');

    $domain = giveaways_get_domain();

    $response = wp_remote_post(GIVEAWAYS_LICENCE_SERVER . '/api/checkout-success.php', [
        'timeout' => 15,
        'body'    => [
            'session_id' => $session_id,
            'domain'     => $domain,
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Could not verify payment');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['bundle']) || empty($body['keys'])) {
        wp_send_json_error($body['error'] ?? 'No bundle keys returned');
    }

    // Reverse map: product_slug => module_key
    $reverseMap = array_flip(giveaways_module_to_product());

    $keys    = get_option('giveaways_license_keys', []);
    $results = [];

    foreach ($body['keys'] as $item) {
        $prod      = sanitize_text_field($item['product']     ?? '');
        $licKey    = sanitize_text_field($item['license_key'] ?? '');
        $moduleKey = $reverseMap[$prod] ?? null;

        if ($moduleKey && $licKey) {
            $keys[$moduleKey] = $licKey;
            giveaways_clear_license_cache();
            $valid     = giveaways_validate_module_license($moduleKey, $licKey);
            $installed = $valid && giveaways_module_folder_exists($moduleKey);
            $results[] = [
                'module'      => $moduleKey,
                'product'     => $prod,
                'license_key' => $licKey,
                'valid'       => $valid,
                'installed'   => $installed,
            ];
        }
    }

    update_option('giveaways_license_keys', $keys);

    // Auto-enable modules whose folder is already present (bundled version).
    $enabled_modules = get_option( 'giveaways_enabled_modules', [] );
    foreach ( $results as $r ) {
        if ( $r['installed'] ) {
            $enabled_modules[ $r['module'] ] = 1;
        }
    }
    update_option( 'giveaways_enabled_modules', $enabled_modules );

    wp_send_json_success([
        'keys'    => $results,
        'expires' => $body['expires']  ?? null,
        'interval'=> $body['interval'] ?? 'monthly',
    ]);
});

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Enable a single licensed module
|──────────────────────────────────────────────────────────────────────────────
*/
add_action( 'wp_ajax_giveaways_enable_module', function () {
    check_ajax_referer( 'giveaways_buy_license', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );

    $module = sanitize_key( wp_unslash( $_POST['module'] ?? '' ) );
    if ( ! $module ) wp_send_json_error( 'Missing module' );

    if ( ! giveaways_is_module_licensed( $module ) ) {
        wp_send_json_error( 'Module not licensed' );
    }

    if ( ! giveaways_module_folder_exists( $module ) ) {
        wp_send_json_error( 'Module not installed — please install it first' );
    }

    $enabled_modules          = get_option( 'giveaways_enabled_modules', [] );
    $enabled_modules[$module] = 1;
    update_option( 'giveaways_enabled_modules', $enabled_modules );

    wp_send_json_success( [ 'enabled' => true ] );
} );

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Get the download URL for the customer's entitled premium plugin zip
|──────────────────────────────────────────────────────────────────────────────
| The premium game modules ship as a separate "CompKit Game Engine Premium" plugin
| that the customer installs themselves via Plugins → Add New → Upload. This
| plugin does NOT download or unzip executable code into itself — it only asks
| the licence server to build a download containing exactly the modules this
| licence/bundle is entitled to, and returns that URL for the browser to fetch.
*/
add_action( 'wp_ajax_giveaways_module_download_url', function () {
    check_ajax_referer( 'giveaways_buy_license', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'forbidden' );
    }

    // Build the entitlement zip from every licence key currently saved, so a
    // single download contains all owned modules (individual purchases and the
    // full contents of any bundle the customer holds).
    $keys = get_option( 'giveaways_license_keys', [] );
    $keys = array_values( array_unique( array_filter( array_map( 'trim', (array) $keys ) ) ) );

    if ( empty( $keys ) ) {
        wp_send_json_error( 'No licence keys saved yet — buy or enter a licence first.' );
    }

    $response = wp_remote_post( GIVEAWAYS_LICENCE_SERVER . '/api/build-download.php', [
        'timeout' => 30,
        'body'    => [
            'licenses' => implode( ',', $keys ),
            'domain'   => giveaways_get_domain(),
        ],
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Could not reach licence server: ' . $response->get_error_message() );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data['url'] ) ) {
        wp_send_json_error( $data['error'] ?? 'No download available for your licences yet.' );
    }

    wp_send_json_success( [ 'url' => esc_url_raw( $data['url'] ) ] );
} );

/*
|──────────────────────────────────────────────────────────────────────────────
| AJAX — Delete (uninstall) a module folder
|──────────────────────────────────────────────────────────────────────────────
*/
add_action( 'wp_ajax_giveaways_delete_module', function () {
    ob_clean();
    check_ajax_referer( 'giveaways_buy_license', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorised' );
    }

    $module = sanitize_key( $_POST['module'] ?? '' );
    if ( ! $module ) {
        wp_send_json_error( 'No module specified' );
    }

    $folders = giveaways_module_to_folder();
    $folder  = $folders[ $module ] ?? '';
    if ( ! $folder ) {
        wp_send_json_error( 'Unknown module' );
    }

    $path = GIVEAWAYS_PATH . $folder;
    if ( ! is_dir( $path ) ) {
        wp_send_json_error( 'Module folder not found' );
    }

    if ( ! giveaways_rmdir_recursive( $path ) ) {
        wp_send_json_error( 'Could not delete module folder — check file permissions' );
    }

    // Mark module as disabled in options.
    $enabled = get_option( 'giveaways_enabled_modules', [] );
    unset( $enabled[ $module ] );
    update_option( 'giveaways_enabled_modules', $enabled );

    wp_send_json_success( [ 'deleted' => true ] );
} );

/*
|──────────────────────────────────────────────────────────────────────────────
| Small helper for addon settings pages.
|──────────────────────────────────────────────────────────────────────────────
*/
function giveaways_render_license_notice($module_key) {

    $licensed = giveaways_is_module_licensed($module_key);
    $keys = get_option('giveaways_license_keys', []);
    $has_key = !empty($keys[$module_key]);

    $bg = $licensed ? '#e7f7e7' : '#fbeaea';
    $bord = $licensed ? '#46b450' : '#dc3232';
    $txt = $licensed ? '#1a7f1a' : '#a00';

    if (!$has_key) {
        $message = 'No licence key saved for this module. Enter it on the main Giveaways Add-ons page.';
    }
    elseif ($licensed) {
        $message = 'Licence active. Managed from the main Giveaways Add-ons page.';
    }
    else {
        $message = 'Licence key entered but not valid for this module.';
    }

    echo sprintf(
        '<div style="margin:12px 0; padding:10px 12px; border-left:4px solid %s; background:%s; color:%s; border-radius:3px;">
            <strong>%s</strong>
        </div>',
        esc_attr($bord),
        esc_attr($bg),
        esc_attr($txt),
        esc_html($message)
    );
}
