<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Self-update channel for the CompKit plugins.
 *
 * Neither plugin is on WordPress.org, so without this every change reaches
 * customers as "please download a zip and upload it" — which is why nothing
 * could be shipped seamlessly.
 *
 * The security shape matters more here than anywhere else in the plugin. A
 * licence check that gets fooled loses a sale; an update channel that gets
 * fooled executes the attacker's code on every customer site. So:
 *
 *   - the manifest is Ed25519-signed with the same public key as licence grants
 *   - the signature covers the package URL, so it cannot be swapped
 *   - a fresh nonce binds the answer to this request
 *   - the free core's package is verified by sha256 before WordPress unpacks it
 *   - anything that fails to verify offers NO update, rather than an unchecked one
 *
 * The premium package is built per entitlement and therefore differs byte for
 * byte between customers, so it carries no hash — its authorisation is the
 * signed URL plus the server's entitlement re-check at download time.
 */
class Giveaways_Updater {

    /** @var string Absolute path to the plugin's main file. */
    private $file;

    /** @var string e.g. compkit-games-premium/compkit-games-premium.php */
    private $basename;

    /** @var string e.g. compkit-games-premium */
    private $slug;

    /** @var string Installed version, read from the plugin header. */
    private $version;

    public function __construct( $file, $slug ) {
        $this->file     = $file;
        $this->slug     = $slug;
        $this->basename = plugin_basename( $file );
        $this->version  = get_file_data( $file, [ 'v' => 'Version' ] )['v'] ?: '0';

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'details' ], 10, 3 );
        add_filter( 'upgrader_pre_download', [ $this, 'verified_download' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'flush' ], 10, 2 );
    }

    private function cache_key() {
        return 'giveaways_update_' . $this->slug;
    }

    /**
     * Ask the server what the current release is.
     *
     * Cached for 6 hours because WordPress calls the update filter on every
     * admin page load, and the answer changes at most once per release.
     * A failed or unverifiable check is cached briefly too, so an outage doesn't
     * mean an HTTP request per pageview.
     *
     * @return array|null Verified manifest, or null when there is nothing to offer.
     */
    private function get_manifest( $force = false ) {

        // "Check again" on the Updates screen, and the Updates link in the admin
        // bar, both set force-check. Without honouring it our own 6-hour cache
        // silently outlives WordPress's, so a release published minutes after a
        // check stays invisible for hours with no way to prod it — which is
        // exactly what happened on the demo site.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; WordPress itself gates this screen
        if ( ! empty( $_GET['force-check'] ) ) {
            $force = true;
        }

        $cached = get_transient( $this->cache_key() );

        // An answer fetched for a different installed version is stale by
        // definition. Without this the cache survives the plugin changing
        // underneath it — which is exactly what happens on an FTP upload, since
        // that fires none of WordPress's upgrader hooks and so clears nothing.
        if ( is_array( $cached ) && ( $cached['for'] ?? null ) !== $this->version ) {
            $force = true;
        }

        if ( ! $force && is_array( $cached ) ) {
            return $cached['manifest'] ?? null;
        }

        // Without a public key nothing can be verified, so nothing is offered.
        // Failing closed here is deliberate: the alternative is installing code
        // on the strength of an unauthenticated HTTP response.
        if ( ! function_exists( 'giveaways_grant_verification_enabled' )
            || ! giveaways_grant_verification_enabled() ) {
            set_transient(
                $this->cache_key(),
                [ 'manifest' => null, 'modules' => [], 'for' => $this->version ],
                HOUR_IN_SECONDS
            );
            return null;
        }

        $nonce = bin2hex( random_bytes( 16 ) );

        $body = [
            'slug'    => $this->slug,
            'version' => $this->version,
            'domain'  => giveaways_get_domain(),
            'nonce'   => $nonce,
            'brand'   => defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'whitelabel' : 'compkit',
        ];

        // Premium builds are per-entitlement, so the server needs to know which
        // licences this site holds to assemble the right package.
        $keys = array_values( array_unique( array_filter( array_map(
            'trim',
            (array) get_option( 'giveaways_license_keys', [] )
        ) ) ) );

        if ( ! empty( $keys ) ) {
            $body['licenses'] = implode( ',', $keys );
        }

        $response = wp_remote_post( GIVEAWAYS_LICENCE_SERVER . '/api/update-check.php', [
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => $body,
        ] );

        $manifest = null;

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( 'giveaways_update [' . $this->slug . '] request failed: ' . $response->get_error_message() );
        } elseif ( (int) wp_remote_retrieve_response_code( $response ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( is_array( $data ) && ! empty( $data['manifest'] ) ) {
                if ( $this->verify_manifest( $data['manifest'], (string) ( $data['manifest_sig'] ?? '' ), $nonce ) ) {
                    $manifest = $data['manifest'];
                } else {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( 'giveaways_update [' . $this->slug . '] manifest failed verification — ignoring it' );
                }
            }
        }

        // Per-game versions ride alongside the manifest, for the badges on the
        // admin page. Display only — see the note on the server side. Stored in
        // the SAME entry as the manifest so the two cannot drift apart or expire
        // independently, which they did when they were separate transients.
        $modules = [];
        if ( $manifest && ! empty( $data['modules'] ) && is_array( $data['modules'] ) ) {
            foreach ( $data['modules'] as $k => $v ) {
                $modules[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
            }
        }

        set_transient(
            $this->cache_key(),
            [ 'manifest' => $manifest, 'modules' => $modules, 'for' => $this->version ],
            $manifest ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS
        );

        return $manifest;
    }

    /** Public read of the verified manifest, for the admin page. */
    public function manifest() {
        return $this->get_manifest();
    }

    /**
     * Per-game versions currently published by the server.
     *
     * Fetches if the cache is cold. Nothing on the CompKit settings page
     * triggers a WordPress plugin-update check, so without fetching here the
     * badges only ever appeared after a visit to the Plugins screen — which is
     * to say, almost never.
     */
    public function modules() {
        $cached = get_transient( $this->cache_key() );

        // A cache written before this shape existed has no 'modules' key at all,
        // which is different from a fetch that legitimately returned none —
        // without the distinction, a site upgrading into this version would sit
        // there badge-less until the six-hour cache aged out.
        if ( ! is_array( $cached )
            || ! array_key_exists( 'modules', $cached )
            || ( $cached['for'] ?? null ) !== $this->version ) {
            $this->get_manifest( true );
            $cached = get_transient( $this->cache_key() );
        }

        return ( is_array( $cached ) && ! empty( $cached['modules'] ) )
            ? $cached['modules']
            : [];
    }

    public function basename() {
        return $this->basename;
    }

    /**
     * Rebuild the exact byte string the server signed and check it.
     * Field order is protocol — it must match uc_manifest_payload() on the server.
     */
    private function verify_manifest( $m, $sig, $expected_nonce ) {

        if ( ! is_array( $m ) || $sig === '' ) {
            return false;
        }

        // Bindings: this site, this plugin, this request, still fresh.
        if ( ( $m['slug'] ?? '' ) !== $this->slug ) {
            return false;
        }

        if ( ( $m['domain'] ?? '' ) !== giveaways_get_domain() ) {
            return false;
        }

        if ( ! hash_equals( (string) $expected_nonce, (string) ( $m['nonce'] ?? '' ) ) ) {
            return false;
        }

        $now    = time();
        $issued = (int) ( $m['issued_at'] ?? 0 );

        if ( $issued <= 0 || $issued > $now + 600 || (int) ( $m['expires'] ?? 0 ) <= $now ) {
            return false;
        }

        // Belt and braces behind the signature: the package must live on the
        // licensing server. A signature covering the URL already prevents
        // substitution, but this makes a mistake in release tooling loud.
        $package = (string) ( $m['package'] ?? '' );
        $host    = wp_parse_url( $package, PHP_URL_HOST );

        if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( GIVEAWAYS_LICENCE_SERVER, PHP_URL_HOST ) ) ) {
            return false;
        }

        if ( strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) ) !== 'https' ) {
            return false;
        }

        $payload = implode( '|', [
            'u1',
            (string) ( $m['slug']           ?? '' ),
            (string) ( $m['brand']          ?? '' ),
            (string) ( $m['version']        ?? '' ),
            (string) ( $m['package_sha256'] ?? '' ),
            $package,
            (string) ( $m['domain']         ?? '' ),
            (string) ( $m['nonce']          ?? '' ),
            (string) (int) ( $m['issued_at'] ?? 0 ),
            (string) (int) ( $m['expires']   ?? 0 ),
        ] );

        $pub = base64_decode( GIVEAWAYS_GRANT_PUBKEY, true );
        $raw = base64_decode( $sig, true );

        if ( $pub === false || $raw === false || strlen( $pub ) !== 32 ) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached( $raw, $payload, $pub );
        } catch ( Exception $e ) {
            return false;
        }
    }

    /**
     * Tell WordPress an update is available.
     */
    public function inject_update( $transient ) {

        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $manifest = $this->get_manifest();

        if ( ! $manifest || empty( $manifest['version'] ) ) {
            return $transient;
        }

        if ( version_compare( $manifest['version'], $this->version, '<=' ) ) {
            return $transient;
        }

        $update = (object) [
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'new_version'  => $manifest['version'],
            'package'      => $manifest['package'],
            'url'          => $manifest['homepage'] ?? '',
            'tested'       => $manifest['tested'] ?? '',
            'requires'     => $manifest['requires'] ?? '',
            'requires_php' => $manifest['requires_php'] ?? '',
        ];

        $transient->response[ $this->basename ] = $update;

        return $transient;
    }

    /**
     * Populate the "View version details" modal.
     */
    public function details( $result, $action, $args ) {

        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $manifest = $this->get_manifest();

        if ( ! $manifest ) {
            return $result;
        }

        return (object) [
            'name'          => $manifest['name'] ?? $this->slug,
            'slug'          => $this->slug,
            'version'       => $manifest['version'],
            'requires'      => $manifest['requires']     ?? '',
            'requires_php'  => $manifest['requires_php'] ?? '',
            'tested'        => $manifest['tested']       ?? '',
            'last_updated'  => $manifest['last_updated'] ?? '',
            'homepage'      => $manifest['homepage']     ?? '',
            'download_link' => $manifest['package'],
            'sections'      => [
                'changelog' => $manifest['changelog'] ?? '',
            ],
        ];
    }

    /**
     * Download the package ourselves so the bytes can be checked before
     * WordPress unpacks and installs them.
     *
     * Returning a path here replaces WordPress's own download step; returning
     * false lets it proceed normally, and a WP_Error aborts the update with a
     * message the admin actually sees.
     */
    public function verified_download( $reply, $package, $upgrader, $hook_extra = [] ) {

        if ( $reply !== false ) {
            return $reply; // another plugin already handled it
        }

        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $reply;
        }

        $manifest = $this->get_manifest();

        // No verified manifest, or WordPress is about to fetch something other
        // than what we signed for — refuse. This is the case that turns a
        // tampered update transient into a failed update instead of an install.
        if ( ! $manifest || $package !== ( $manifest['package'] ?? '' ) ) {
            return new WP_Error(
                'giveaways_update_unverified',
                __( 'The update package could not be verified against the licence server. Update cancelled.', 'compkit-game-engine' )
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        // The single longest step in the whole install, and the one a customer
        // on a slow connection is most likely to think has hung.
        giveaways_set_update_progress( 30, 'Downloading your games…' );

        $tmp = download_url( $package, 300 );

        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        giveaways_set_update_progress( 65, 'Checking the download is genuine…' );

        $expected = (string) ( $manifest['package_sha256'] ?? '' );

        // Only the free core publishes a hash — the premium build is assembled
        // per entitlement, so no two customers' zips are identical.
        if ( $expected !== '' ) {
            $actual = hash_file( 'sha256', $tmp );

            if ( ! hash_equals( $expected, (string) $actual ) ) {
                wp_delete_file( $tmp );
                return new WP_Error(
                    'giveaways_update_checksum',
                    __( 'The downloaded update did not match the checksum published by the licence server. Update cancelled.', 'compkit-game-engine' )
                );
            }
        }

        giveaways_set_update_progress( 72, 'Installing…' );

        return $tmp;
    }

    /**
     * After an update: drop the cached manifest, and re-validate licences if it
     * was OUR plugin that changed.
     *
     * The re-validation is not housekeeping. A game whose JavaScript is served
     * from the licence server needs the asset token that arrives with a
     * validation answer, and a freshly updated plugin has a stored grant that
     * predates it — so the game renders nothing until the next validation. On a
     * customer's site that is a broken game until the daily cron happens to
     * fire. Ask for the answer now instead.
     *
     * Narrowed to our own plugin on purpose: this hook fires for every plugin
     * update on the site, and revalidating nine endpoints because somebody
     * updated an unrelated plugin would be its own bug.
     */
    public function flush( $upgrader, $hook_extra ) {

        if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
            return;
        }

        delete_transient( $this->cache_key() );

        $plugins = ! empty( $hook_extra['plugins'] )
            ? (array) $hook_extra['plugins']
            : ( ! empty( $hook_extra['plugin'] ) ? [ $hook_extra['plugin'] ] : [] );

        $ours = in_array( $this->basename, $plugins, true );

        // Upload-and-replace doesn't list the plugin, so check what was written.
        if ( ! $ours && isset( $upgrader->result['destination_name'] ) ) {
            $ours = dirname( $this->basename ) === $upgrader->result['destination_name'];
        }

        if ( $ours && function_exists( 'giveaways_validate_all_licenses' ) ) {
            giveaways_clear_license_cache();
            giveaways_validate_all_licenses();
        }
    }
}

/**
 * Register a plugin with the update channel.
 *
 * Called by the core for itself, and by the premium shell for itself, so both
 * plugins update through one implementation.
 *
 * @param string $file Absolute path to the plugin's main file.
 * @param string $slug Release slug, matching a key in the server's manifest.json.
 */
function giveaways_register_updater( $file, $slug ) {
    $registry = giveaways_updater_registry();

    if ( isset( $registry[ $slug ] ) ) {
        return;
    }

    giveaways_updater_registry( $slug, new Giveaways_Updater( $file, $slug ) );
}

/**
 * The registered updaters, so the admin page can ask one for its manifest.
 * A plain static inside giveaways_register_updater() could be written but never
 * read back out.
 */
function giveaways_updater_registry( $set_slug = null, $instance = null ) {
    static $registry = [];

    if ( $set_slug !== null ) {
        $registry[ $set_slug ] = $instance;
    }

    return $registry;
}

/** @return Giveaways_Updater|null */
function giveaways_get_updater( $slug ) {
    $registry = giveaways_updater_registry();
    return $registry[ $slug ] ?? null;
}

/**
 * An updater for the premium plugin, even when it is not installed yet.
 *
 * THE DEADLOCK THIS BREAKS. The premium plugin registers its own updater, and
 * the adoption block below registers it on its behalf for older builds — but
 * both need the plugin to be present. On a site that has never installed
 * premium, nothing registers that slug, so giveaways_get_updater() returns null
 * and the install handler answers "No verified download is available for your
 * licences yet."
 *
 * Which means one-click INSTALL could never work on exactly the site it is for:
 * a new customer with licence keys and no games yet. Found 15 Aug 2026 on a
 * Game Addons install; it affects both editions equally and was invisible on
 * the demo site only because premium was already installed there.
 *
 * Registering against the path the plugin WILL occupy is safe: the folder and
 * main file are both named after the slug (see $BRAND_SHELLS in
 * build-download.php), so once installed the basename is already correct. With
 * no file there yet, get_file_data() yields version '0', so any published
 * release counts as newer — which is what an install wants.
 *
 * Call this ONLY from the install handler. Registering it on every page load
 * would hang an inject_update filter on a plugin that does not exist, and
 * WordPress would offer an update for something the site has not got.
 *
 * @return Giveaways_Updater|null
 */
function giveaways_updater_for_install( $slug ) {

    $updater = giveaways_get_updater( $slug );

    if ( $updater ) {
        return $updater;
    }

    giveaways_register_updater( WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php', $slug );

    return giveaways_get_updater( $slug );
}

/**
 * Register the premium plugin from here when it hasn't registered itself.
 *
 * Premium only started registering with the update channel in 3.0.4, so a
 * customer sitting on an older build has a plugin that cannot ask for something
 * it doesn't know exists — it would never appear in their Updates screen, and
 * they'd be stuck installing by hand forever. Adopting it from the core breaks
 * that deadlock: they update the core once, and premium updates itself after.
 *
 * Both shells define a directory constant from their own __FILE__, which is how
 * we find the main file without guessing at plugin folder names.
 *
 * Runs late on plugins_loaded so a 3.0.4+ premium has already claimed itself —
 * giveaways_register_updater() ignores a duplicate slug, so this is only ever
 * the fallback.
 */
add_action( 'plugins_loaded', function () {

    $shells = [
        'compkit-games-premium' => [ 'COMPKIT_PREMIUM_DIR',     'compkit-games-premium.php' ],
        'game-addons-premium'   => [ 'GIVEAWAYS_WL_PREMIUM_DIR', 'game-addons-premium.php'  ],
    ];

    foreach ( $shells as $slug => list( $constant, $filename ) ) {

        if ( ! defined( $constant ) ) {
            continue; // that edition isn't installed
        }

        $file = constant( $constant ) . $filename;

        if ( file_exists( $file ) ) {
            giveaways_register_updater( $file, $slug );
        }
    }
}, 20 );

/**
 * Which games have a newer version available, and what it is.
 *
 * Games do not update individually — all eight ship inside the one premium
 * plugin, and WordPress updates plugins, not folders. So this drives the badges
 * only; the actual action is a single plugin update that brings every game
 * forward at once. Anything else would mean unzipping code into the plugin
 * directory at runtime, which is what gets plugins flagged as malware.
 *
 * @return array<string,array{installed:string,available:string}>
 */
function giveaways_module_updates() {

    $slug = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons-premium' : 'compkit-games-premium';
    $updater = giveaways_get_updater( $slug );

    if ( ! $updater ) {
        return []; // premium not installed — nothing to compare against
    }

    $available = $updater->modules();

    if ( empty( $available ) ) {
        return [];
    }

    $out = [];

    foreach ( $available as $key => $new ) {

        $installed = function_exists( 'giveaways_module_version' )
            ? giveaways_module_version( $key )
            : '';

        // Not installed is not "out of date" — that's the Download flow, not this.
        if ( $installed === '' || $new === '' ) {
            continue;
        }

        if ( version_compare( $new, $installed, '>' ) ) {
            $out[ $key ] = [ 'installed' => $installed, 'available' => $new ];
        }
    }

    return $out;
}

/**
 * Force one fresh update check when someone is actually looking at the CompKit
 * page, throttled so it costs at most one round trip a minute.
 *
 * The cached answer is keyed on the INSTALLED version, so it goes stale only
 * when the plugin changes underneath it. A release published on the server
 * never invalidates anything — meaning a brand new build stayed invisible here
 * for up to six hours with no way to tell that from "there is no update", which
 * is exactly the confusion that cost two debugging sessions.
 *
 * WordPress's own update screens have "Check Again" for this. The CompKit page
 * is the screen whose whole purpose is showing update state, so it should not
 * need one: landing on it IS the request to check.
 *
 * Only this page. Other admin pages keep the full six-hour cache, because they
 * do not display update state and should not pay for a check they never show.
 */
function giveaways_refresh_updates_for_admin_page() {

    if ( ! current_user_can( 'update_plugins' ) ) {
        return;
    }

    // An explicit check always wins. WordPress's own "Check Again" uses this
    // parameter, and get_manifest() already honours it, so the Check now link
    // in the header needs nothing else to work.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; capability checked above
    $forced = ! empty( $_GET['force-check'] );

    // The throttle stops a reload hammering the licence server, since each pass
    // costs two round trips (core + premium).
    //
    // It was a minute originally, and that was too blunt to live with: publish a
    // release, reload the page, and the answer you get is the one cached moments
    // BEFORE the upload — for up to a minute, with nothing on screen admitting
    // it. Indistinguishable from a broken update channel, and it sent the last
    // debugging session chasing the wrong thing entirely. Fifteen seconds still
    // absorbs a burst of refreshes without making a fresh release feel missing.
    if ( ! $forced && get_transient( 'giveaways_update_lastcheck' ) ) {
        return;
    }

    set_transient( 'giveaways_update_lastcheck', time(), 15 );

    // Dropping the cached entries is enough — the manifest() calls that follow
    // while rendering this page will refetch and repopulate them.
    giveaways_flush_update_cache();
}

/**
 * Is there a newer core (free plugin) build than the one running?
 *
 * The CompKit page had no answer to this at all. Its version badge prints the
 * INSTALLED version and its one button updates the premium plugin, so a core
 * release was invisible here however many times you cleared the cache — the
 * only place it ever surfaced was WordPress's own Plugins screen, via
 * inject_update(). This is what the admin page asks instead.
 *
 * @return array{installed:string,available:string}|null
 */
function giveaways_core_update() {

    $slug    = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons' : 'compkit-game-engine';
    $updater = giveaways_get_updater( $slug );

    if ( ! $updater ) {
        return null;
    }

    $manifest = $updater->manifest();

    /*
     * The white-label core does not define GIVEAWAYS_VERSION — it has its own
     * GIVEAWAYS_WL_VERSION, set in game-addons-config.php. Reading only the
     * CompKit constant left $installed empty on Game Addons, so this returned
     * null and no core update could ever be offered there however the manifest
     * was configured. ck_diag reported it as "core installed: (unknown)".
     *
     * Same shape as the GIVEAWAYS_WL_PREMIUM_VERSION bug in known-issues 1:
     * a constant assumed to exist in both editions when it only exists in one.
     */
    $installed = '';
    foreach ( [ 'GIVEAWAYS_WL_VERSION', 'GIVEAWAYS_VERSION' ] as $const ) {
        if ( defined( $const ) && constant( $const ) !== '' ) {
            $installed = (string) constant( $const );
            break;
        }
    }

    if ( ! $manifest || empty( $manifest['version'] ) || $installed === '' ) {
        return null;
    }

    if ( version_compare( (string) $manifest['version'], (string) $installed, '<=' ) ) {
        return null;
    }

    return [
        'installed' => (string) $installed,
        'available' => (string) $manifest['version'],
    ];
}

/* ── Update progress ──────────────────────────────────────────────────────────
 *
 * The install is one long blocking AJAX request — fetch the manifest, download
 * a ~2MB package, checksum it, unpack it, reactivate, revalidate licences. From
 * the browser that is twenty-odd silent seconds whose only honest signal was the
 * word "Installing…", which on a slow host is indistinguishable from a hang.
 * Customers pressing the button again mid-update is the failure that follows.
 *
 * So the request records its stage as it goes and the page polls a second,
 * cheap endpoint for it.
 *
 * These are STAGE percentages, not bytes. WordPress's upgrader offers no
 * byte-level callback, so a true 0-100 would have to be invented; instead the
 * bar moves only when something real has finished. Downloading is the long
 * stage, which is why it spans the widest jump.
 */

/** Keyed per user, so two administrators updating at once don't cross streams. */
function giveaways_update_progress_key() {
    return 'giveaways_update_progress_' . get_current_user_id();
}

/**
 * Record a stage. Deliberately fire-and-forget: progress reporting must never
 * be able to fail an update, so nothing here is checked, retried or returned.
 */
function giveaways_set_update_progress( $percent, $message ) {

    // Only while our own install is running. verified_download() also runs for
    // ordinary WordPress-initiated updates, and those have their own UI.
    if ( empty( $GLOBALS['giveaways_update_running'] ) ) {
        return;
    }

    set_transient(
        giveaways_update_progress_key(),
        [
            'percent' => max( 0, min( 100, (int) $percent ) ),
            'message' => (string) $message,
            'at'      => time(),
        ],
        5 * MINUTE_IN_SECONDS
    );
}

function giveaways_clear_update_progress() {
    delete_transient( giveaways_update_progress_key() );
}

/**
 * AJAX — how far has the running install got?
 *
 * Read-only and intentionally tiny: it is polled about once a second while an
 * install runs, so it does no work beyond a single transient read.
 */
add_action( 'wp_ajax_giveaways_update_progress', function () {

    check_ajax_referer( 'giveaways_buy_license', '_nonce' );

    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_send_json_error( 'Not permitted' );
    }

    $progress = get_transient( giveaways_update_progress_key() );

    // No stage recorded yet is not an error — the install may not have reached
    // its first checkpoint, and the bar should just hold where it is.
    wp_send_json_success( is_array( $progress ) ? $progress : null );
} );

/**
 * AJAX — install or update the premium plugin in place.
 *
 * This is what replaces "download the zip, upload it, choose Replace current
 * with uploaded". Neither plugin is on WordPress.org, so nothing prevents a
 * normal one-click update; the package still comes from the signed manifest and
 * is still checksum-verified in Giveaways_Updater::verified_download() before
 * WordPress unpacks anything.
 */
add_action( 'wp_ajax_giveaways_update_premium', function () {

    check_ajax_referer( 'giveaways_buy_license', '_nonce' );

    // update_plugins, not manage_options: on multisite this is a super-admin
    // capability, and installing code is exactly the thing that distinction
    // exists to protect.
    if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'install_plugins' ) ) {
        wp_send_json_error( 'You do not have permission to install plugins on this site.' );
    }

    if ( ! function_exists( 'giveaways_register_updater' ) ) {
        wp_send_json_error( 'Updater unavailable' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    // Opens the gate for giveaways_set_update_progress().
    $GLOBALS['giveaways_update_running'] = true;

    // Clear on the way out however this request ends. wp_send_json_error() calls
    // wp_die(), and a PHP fatal during an unpack ends the request outright —
    // neither would reach a clear-up line placed at the end of the function, and
    // the stale transient would then make the NEXT install look half-finished
    // before it had begun.
    register_shutdown_function( 'giveaways_clear_update_progress' );

    // Drop anything a previous attempt left behind before reporting a new stage,
    // or the poll could answer "90%" for an install that has only just started.
    giveaways_clear_update_progress();
    giveaways_set_update_progress( 5, 'Checking for the latest build…' );

    // Always re-check, so a release published moments ago is picked up rather
    // than whatever the six-hour cache happens to hold.
    giveaways_flush_update_cache();

    $slug = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons-premium' : 'compkit-games-premium';

    // Not giveaways_get_updater(): on a site that has never installed premium,
    // nothing has registered that slug and this is precisely the case the
    // Install button exists for. See giveaways_updater_for_install().
    $updater  = giveaways_updater_for_install( $slug );
    $manifest = $updater ? $updater->manifest() : null;

    if ( ! $manifest || empty( $manifest['package'] ) ) {
        wp_send_json_error( 'No verified download is available for your licences yet. Save a licence key first, or try again shortly.' );
    }

    $installed = $updater->basename();
    $is_update = $installed && file_exists( WP_PLUGIN_DIR . '/' . $installed );

    // Plugin_Upgrader::upgrade() registers deactivate_plugin_before_upgrade(),
    // which switches the plugin off and never switches it back on — WordPress
    // reactivates it from wp-admin/update.php, outside the upgrader, and
    // bulk_upgrade() sidesteps the whole thing by not registering that filter.
    // Calling upgrade() directly therefore leaves every game disabled after a
    // successful update, which is precisely what it did on the demo site.
    // Remember the state so it can be restored below.
    $was_active  = $is_update && is_plugin_active( $installed );
    $was_network = $is_update && is_multisite() && is_plugin_active_for_network( $installed );

    giveaways_set_update_progress( 15, $is_update ? 'Preparing the update…' : 'Preparing the install…' );

    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );

    if ( $is_update ) {
        // Seed the transient so WordPress's own upgrade path has a package to
        // fetch; upgrader_pre_download then verifies it against the manifest.
        $transient = get_site_transient( 'update_plugins' );
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }
        $transient->response[ $installed ] = (object) [
            'slug'        => $slug,
            'plugin'      => $installed,
            'new_version' => $manifest['version'],
            'package'     => $manifest['package'],
        ];
        set_site_transient( 'update_plugins', $transient );

        $result = $upgrader->upgrade( $installed );
    } else {
        $result = $upgrader->install( $manifest['package'] );
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    if ( $result === false ) {
        $errors = $skin->get_upgrade_messages();
        wp_send_json_error( 'The install did not complete. ' . esc_html( (string) end( $errors ) ) );
    }

    giveaways_set_update_progress( 85, 'Switching your games back on…' );

    // A fresh install needs activating. An in-place update does too, because
    // the upgrader deactivated it on the way in (see above) — without this the
    // customer's games all go dark on a successful update.
    if ( ! $is_update ) {
        $file = $upgrader->plugin_info();
        if ( $file ) {
            $activated = activate_plugin( $file, '', is_multisite() && is_network_admin() );
            if ( is_wp_error( $activated ) ) {
                wp_send_json_error( 'Installed, but could not activate: ' . $activated->get_error_message() );
            }
        }
    } elseif ( $was_active && ! is_plugin_active( $installed ) ) {
        $activated = activate_plugin( $installed, '', $was_network );
        if ( is_wp_error( $activated ) ) {
            wp_send_json_error( 'Updated, but could not switch the plugin back on: ' . $activated->get_error_message() );
        }
    }

    giveaways_flush_update_cache();

    // Fetch fresh grants before answering. Games whose JavaScript is served from
    // the licence server need the asset token that only arrives with a
    // validation answer, and the grant stored a moment ago predates the module
    // that now wants it — without this the page reloads onto a game that renders
    // nothing until someone presses "Clear cache & re-validate" by hand.
    if ( function_exists( 'giveaways_validate_all_licenses' ) ) {
        giveaways_set_update_progress( 92, 'Refreshing your licences…' );
        giveaways_clear_license_cache();
        giveaways_validate_all_licenses();
    }

    giveaways_set_update_progress( 100, 'Done.' );

    wp_send_json_success( [
        'updated' => $is_update,
        'version' => $manifest['version'],
    ] );
} );

/**
 * AJAX — update the free core plugin in place.
 *
 * Deliberately a separate handler from the premium one, because this is a
 * SELF-update: the plugin being replaced is the plugin running this code. Two
 * consequences shape what follows.
 *
 * First, the upgrader deactivates the plugin on the way in
 * (deactivate_plugin_before_upgrade) and WordPress normally reactivates it from
 * wp-admin/update.php, outside the upgrader. We are not in that flow, so we have
 * to switch it back on ourselves — and here the stakes are higher than for
 * premium, because leaving THIS plugin off takes the whole CompKit admin page
 * with it. The reactivation below is not optional tidying.
 *
 * Second, the files backing the currently running request are swapped underneath
 * PHP. Everything needed has already been loaded into memory by this point, so
 * the request finishes on the old code and the new code takes effect on the next
 * page load — which is why the browser reloads once this returns.
 */
/**
 * The installed core version, whichever edition is running.
 *
 * The CompKit core defines GIVEAWAYS_VERSION; the white-label core defines only
 * GIVEAWAYS_WL_VERSION, in game-addons-config.php. THIS FILE IS SHARED by both
 * editions — it is not one of the three forked files — so naming either constant
 * directly is a fatal on the other edition: PHP 8 raises an Error for an
 * undefined constant.
 *
 * Inside an AJAX handler that surfaces as WordPress's critical-error HTML page,
 * which the admin JS then fails to JSON.parse and reports as
 * "Connection error — try again". That is precisely what made the core update
 * button unusable on every Game Addons site: the update was found, verified and
 * about to install, and the handler fatalled on the version comparison.
 *
 * Same shape as the GIVEAWAYS_WL_PREMIUM_VERSION bug in known-issues 1 and the
 * missing compkit_premium_editing_product_in_category() in the white-label
 * premium shell — a constant or function assumed to exist in both editions when
 * it only exists in one. Grep for bare GIVEAWAYS_VERSION before touching any
 * shared file.
 */
if ( ! function_exists( 'giveaways_installed_core_version' ) ) {
    function giveaways_installed_core_version() {
        foreach ( [ 'GIVEAWAYS_WL_VERSION', 'GIVEAWAYS_VERSION' ] as $const ) {
            if ( defined( $const ) && (string) constant( $const ) !== '' ) {
                return (string) constant( $const );
            }
        }
        return '0';
    }
}

/**
 * The installed PREMIUM version, whichever edition is running, or '' if the
 * premium plugin is not installed at all.
 *
 * The premium plugin IS forked, contrary to what known-issues.md #1 says: the
 * CompKit shell defines COMPKIT_PREMIUM_VERSION, and the Game Addons shell in
 * WhiteLabel Overwrites/premium/ defines GIVEAWAYS_WL_PREMIUM_VERSION and never
 * mentions the CompKit constant.
 *
 * The white-label admin tested `defined( 'COMPKIT_PREMIUM_VERSION' )` to decide
 * whether the installed premium was out of step with the core. On Game Addons
 * that is always false, so the mismatch was always false, so
 * $show_download_bar was false — and the entire "Install / Update Games" bar
 * rendered with display:none. Premium could sit versions behind with no button
 * on screen to update it, which is exactly what happened at 3.0.25.
 *
 * Returns '' when neither constant exists; callers must read that as "premium
 * not installed", not as "version zero".
 */
if ( ! function_exists( 'giveaways_installed_premium_version' ) ) {
    function giveaways_installed_premium_version() {
        foreach ( [ 'GIVEAWAYS_WL_PREMIUM_VERSION', 'COMPKIT_PREMIUM_VERSION' ] as $const ) {
            if ( defined( $const ) && (string) constant( $const ) !== '' ) {
                return (string) constant( $const );
            }
        }
        return '';
    }
}

add_action( 'wp_ajax_giveaways_update_core', function () {

    check_ajax_referer( 'giveaways_buy_license', '_nonce' );

    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_send_json_error( 'You do not have permission to update plugins on this site.' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $GLOBALS['giveaways_update_running'] = true;
    register_shutdown_function( 'giveaways_clear_update_progress' );

    giveaways_clear_update_progress();
    giveaways_set_update_progress( 5, 'Checking for the latest build…' );

    giveaways_flush_update_cache();

    $slug     = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons' : 'compkit-game-engine';
    $updater  = giveaways_get_updater( $slug );
    $manifest = $updater ? $updater->manifest() : null;

    if ( ! $manifest || empty( $manifest['package'] ) ) {
        wp_send_json_error( 'No verified update is available from the licence server right now.' );
    }

    $installed = $updater->basename();

    if ( ! $installed || ! file_exists( WP_PLUGIN_DIR . '/' . $installed ) ) {
        wp_send_json_error( 'Could not locate the installed plugin to update.' );
    }

    $installed_version = giveaways_installed_core_version();

    if ( version_compare( (string) $manifest['version'], $installed_version, '<=' ) ) {
        wp_send_json_error( 'You are already on the latest version (v' . esc_html( $installed_version ) . ').' );
    }

    $was_active  = is_plugin_active( $installed );
    $was_network = is_multisite() && is_plugin_active_for_network( $installed );

    giveaways_set_update_progress( 15, 'Preparing the update…' );

    $skin     = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );

    // Seed the transient so the upgrade path has a package to fetch;
    // upgrader_pre_download then verifies it against the signed manifest and its
    // sha256 before WordPress unpacks anything.
    $transient = get_site_transient( 'update_plugins' );
    if ( ! is_object( $transient ) ) {
        $transient = new stdClass();
    }
    $transient->response[ $installed ] = (object) [
        'slug'        => $slug,
        'plugin'      => $installed,
        'new_version' => $manifest['version'],
        'package'     => $manifest['package'],
    ];
    set_site_transient( 'update_plugins', $transient );

    $result = $upgrader->upgrade( $installed );

    // Switch it back on before reporting anything, including on failure: an
    // aborted upgrade can still have deactivated the plugin, and a site left
    // with the engine off loses every game AND the page you would fix it from.
    if ( $was_active && ! is_plugin_active( $installed ) ) {
        activate_plugin( $installed, '', $was_network );
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    if ( $result === false ) {
        $errors = $skin->get_upgrade_messages();
        wp_send_json_error( 'The update did not complete. ' . esc_html( (string) end( $errors ) ) );
    }

    if ( $was_active && ! is_plugin_active( $installed ) ) {
        wp_send_json_error( 'Updated, but the plugin could not be switched back on. Reactivate CompKit Game Engine from the Plugins screen.' );
    }

    giveaways_set_update_progress( 92, 'Finishing up…' );

    giveaways_flush_update_cache();

    giveaways_set_update_progress( 100, 'Done.' );

    wp_send_json_success( [ 'version' => $manifest['version'] ] );
} );

/**
 * Diagnostics for the update channel: add ?ck_diag=1 to any admin URL.
 *
 * The per-game badge failing to appear cost three round trips of guesswork,
 * because everything it depends on lives server-side and none of it is visible
 * from the page. This shows the whole chain in one screen — what the server
 * published, what is installed, and what the comparison concluded.
 *
 * Admin-only and read-only. It reveals nothing a site administrator cannot
 * already see, and never prints a licence key.
 */
add_action( 'admin_notices', function () {

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic
    if ( empty( $_GET['ck_diag'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $slug    = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons-premium' : 'compkit-games-premium';
    $updater = giveaways_get_updater( $slug );

    echo '<div class="notice notice-info"><p><strong>CompKit update diagnostics</strong></p><pre style="white-space:pre-wrap;font-size:12px;line-height:1.6;">';

    // Core first: it is a separate updater with a separate cache entry, and the
    // CompKit page showed nothing about it at all until the header button was
    // added — so "no update showing" was indistinguishable from "no update".
    $core_slug    = defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? 'game-addons' : 'compkit-game-engine';
    $core_updater = giveaways_get_updater( $core_slug );
    $core_manifest = $core_updater ? $core_updater->manifest() : null;
    $core_update  = function_exists( 'giveaways_core_update' ) ? giveaways_core_update() : null;

    printf( "core slug               : %s\n", esc_html( $core_slug ) );
    printf( "core updater registered : %s\n", $core_updater ? 'yes' : 'NO  <-- nothing can work without this' );
    // Must read the same constants giveaways_core_update() does, or the
    // diagnostic disagrees with the thing it is diagnosing.
    $core_installed = '(unknown)';
    foreach ( [ 'GIVEAWAYS_WL_VERSION', 'GIVEAWAYS_VERSION' ] as $ck_const ) {
        if ( defined( $ck_const ) && constant( $ck_const ) !== '' ) {
            $core_installed = (string) constant( $ck_const );
            break;
        }
    }

    printf( "core installed          : %s\n", esc_html( $core_installed ) );
    printf( "core published          : %s\n", $core_manifest ? esc_html( (string) $core_manifest['version'] ) : 'NULL  <-- fetch or verification failed' );
    printf( "core verdict            : %s\n", $core_update ? 'UPDATE — button expected in the page header' : 'up to date (or nothing published)' );

    echo "\n";
    printf( "premium slug            : %s\n", esc_html( $slug ) );
    printf( "updater registered      : %s\n", $updater ? 'yes' : 'NO  <-- nothing can work without this' );
    printf( "grant verification on   : %s\n", giveaways_grant_verification_enabled() ? 'yes' : 'NO  <-- no public key / no sodium' );
    printf( "registered slugs        : %s\n", esc_html( implode( ', ', array_keys( giveaways_updater_registry() ) ) ?: '(none)' ) );

    $raw = get_transient( 'giveaways_update_' . $slug );
    printf( "\ncached entry            : %s\n", is_array( $raw ) ? 'present' : 'absent (will fetch)' );
    if ( is_array( $raw ) ) {
        printf( "  fetched for version   : %s\n", esc_html( (string) ( $raw['for'] ?? '(not recorded — old cache shape)' ) ) );
        printf( "  manifest version      : %s\n", esc_html( (string) ( $raw['manifest']['version'] ?? '(none)' ) ) );
        printf( "  modules in cache      : %d\n", is_array( $raw['modules'] ?? null ) ? count( $raw['modules'] ) : 0 );
    }

    if ( $updater ) {
        $manifest = $updater->manifest();
        $modules  = $updater->modules();

        printf( "\nmanifest()              : %s\n", $manifest ? 'v' . esc_html( $manifest['version'] ) : 'NULL  <-- fetch or verification failed' );
        printf( "modules()               : %d game(s)\n", count( $modules ) );

        echo "\ngame                installed   published   verdict\n";
        foreach ( giveaways_module_to_product() as $key => $product ) {
            if ( $key === 'instant_display' ) {
                continue;
            }
            $installed = function_exists( 'giveaways_module_version' ) ? giveaways_module_version( $key ) : '';
            $published = $modules[ $key ] ?? '';
            $verdict   = '';
            if ( $installed === '' ) {
                $verdict = 'not installed';
            } elseif ( $published === '' ) {
                $verdict = 'server published nothing';
            } elseif ( version_compare( $published, $installed, '>' ) ) {
                $verdict = 'UPDATE — badge expected';
            } else {
                $verdict = 'up to date';
            }
            printf( "%-18s  %-10s  %-10s  %s\n",
                esc_html( $key ),
                esc_html( $installed ?: '—' ),
                esc_html( $published ?: '—' ),
                esc_html( $verdict )
            );
        }

        printf( "\ngiveaways_module_updates() returns %d entr(y/ies)\n", count( giveaways_module_updates() ) );
    }

    echo '</pre></div>';
} );

/**
 * Force a fresh check on the next admin load.
 * Hooked to the existing licence cache clear so the button in the admin covers
 * updates too, rather than needing a second one.
 */
function giveaways_flush_update_cache() {
    foreach ( [ 'compkit-game-engine', 'compkit-games-premium', 'game-addons', 'game-addons-premium' ] as $slug ) {
        delete_transient( 'giveaways_update_' . $slug );
    }
}
