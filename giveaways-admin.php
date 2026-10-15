<?php
if (!defined('ABSPATH')) exit;

/* ===============================
   ADMIN MENU
================================ */
add_action('admin_menu', function () {

    // WordPress core requires the `data:image/svg+xml;base64,` prefix to treat a
    // menu icon as a tintable SVG; base64 is the only accepted encoding here.
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    $gamepad_icon = 'data:image/svg+xml;base64,' . base64_encode('
<svg xmlns="http://www.w3.org/2000/svg" viewBox="2 2 20 20" fill="black">
  <path d="M7.2 10.2c-1.7 0-3.1 1.4-3.1 3.1v.9c0 2 1.6 3.6 3.6 3.6.9 0 1.7-.3 2.4-.9l1.2-1.1c.4-.4 1-.6 1.6-.6s1.2.2 1.6.6l1.2 1.1c.7.6 1.5.9 2.4.9 2 0 3.6-1.6 3.6-3.6v-.9c0-1.7-1.4-3.1-3.1-3.1H7.2zM8 12h1.6v1.4H11V15h-1.4v1.4H8V15H6.6v-1.6H8zM17.2 13.4a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4zm2.4 1a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z"/>
</svg>
');

    add_menu_page(
        'CompKit Game Engine',
        'CompKit Game Engine',
        'manage_options',
        'giveaways-addons',
        'giveaways_settings',
        $gamepad_icon,
        56
    );

}, 10);


/* ===============================
   COMPKIT LITE
================================ */
// The WordPress.org edition (CompKit Lite) and this full engine share every
// function name, so only one may run. This one loads first and Lite stands down
// on its own; switching Lite off as well stops it ever being half-active. This
// file is not part of the Lite build, so it can never switch Lite off itself.
add_action('admin_init', function () {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (is_plugin_active('compkit-lite/giveaways-add-ons.php') && current_user_can('activate_plugins')) {
        deactivate_plugins('compkit-lite/giveaways-add-ons.php', true);
    }
});


/* ===============================
   AJAX MAIL HANDLER
================================ */
add_action('wp_ajax_giveaways_send_message', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    if (
        empty($_POST['_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_nonce'])), 'giveaways_send_message')
    ) {
        wp_send_json_error('Security check failed');
    }

    $type    = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

    if (!$message) {
        wp_send_json_error('Empty message');
    }

    $admin_email = get_option('admin_email');
    $site_name   = get_bloginfo('name');

    $subject = ($type === 'feature')
        ? 'Feature Request from ' . $site_name
        : 'Support Request from ' . $site_name;

    $body  = "Site: {$site_name}\n";
    $body .= "From: {$admin_email}\n\n";
    $body .= $message;

    wp_mail(
        'hello@kriscreates.co.uk',
        $subject,
        $body,
        ['Reply-To: ' . $admin_email]
    );

    wp_send_json_success();
});


/* ===============================
   DASHBOARD: SPOTLIGHT + WHAT'S NEW
================================ */

/**
 * The game the dashboard spotlights: the newest one released in the last 60
 * days ('released' in its meta), otherwise the first game this site has no
 * licence for, otherwise none. The NEW badge drops off on its own.
 */
if ( ! function_exists( 'compkit_dashboard_spotlight' ) ) {
    function compkit_dashboard_spotlight( array $module_meta, array $license_status ): string {
        $newest = ''; $newest_ts = 0;
        foreach ( $module_meta as $key => $meta ) {
            $ts = ! empty( $meta['released'] ) ? strtotime( $meta['released'] ) : 0;
            if ( $ts && $ts > $newest_ts && $ts > time() - 60 * DAY_IN_SECONDS ) { $newest = $key; $newest_ts = $ts; }
        }
        if ( $newest ) return $newest;
        foreach ( $module_meta as $key => $meta ) {
            if ( empty( $license_status[ $key ] ) ) return $key;
        }
        return '';
    }
}

if ( ! function_exists( 'compkit_dashboard_is_new' ) ) {
    function compkit_dashboard_is_new( array $meta ): bool {
        $ts = ! empty( $meta['released'] ) ? strtotime( $meta['released'] ) : 0;
        return $ts && $ts > time() - 60 * DAY_IN_SECONDS;
    }
}

/**
 * The last few releases from the plugin's own readme changelog: version, and
 * up to three lines each, every line tagged with the games it names so the
 * dashboard can show their icons. Nothing to maintain - writing the changelog
 * entry is enough.
 */
if ( ! function_exists( 'compkit_dashboard_news' ) ) {
    function compkit_dashboard_news( array $module_meta, int $releases = 2, int $per = 3 ): array {
        $file = GIVEAWAYS_PATH . 'readme.txt';
        if ( ! is_readable( $file ) ) return [];
        $text = (string) file_get_contents( $file );
        $at   = strpos( $text, '== Changelog ==' );
        if ( false === $at ) return [];
        $text = substr( $text, $at );

        // Names a line might use for each game (its label, plus the short forms
        // the changelog actually uses).
        $names = [];
        foreach ( $module_meta as $key => $meta ) $names[ strtolower( $meta['label'] ) ] = $key;
        $alias = [ 'race car' => 'racecar', 'racecar' => 'racecar', 'spin' => 'spin', 'slots' => 'slots',
                   'scratchcard' => 'scratchcard', 'football' => 'football', 'claw' => 'grabclaw',
                   'grab claw' => 'grabclaw', 'balloon pop' => 'balloon_pop', 'darts' => 'darts',
                   'boxing' => 'boxing', 'instant reveal' => 'instant_display' ];
        foreach ( $alias as $n => $k ) if ( isset( $module_meta[ $k ] ) || $k === 'instant_display' ) $names[ $n ] = $k;
        uksort( $names, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );   // longest first

        $out = [];
        if ( ! preg_match_all( '/^= *([0-9][0-9.]*) *=\s*$(.*?)(?=^= *[0-9]|\z)/ms', $text, $m, PREG_SET_ORDER ) ) return [];
        foreach ( $m as $rel ) {
            if ( ! preg_match_all( '/^\* +(.+)$/m', $rel[2], $b ) ) continue;
            $items = [];
            foreach ( array_slice( $b[1], 0, $per ) as $line ) {
                $line = trim( wp_strip_all_tags( $line ) );
                if ( preg_match( '/^maintenance release\.?$/i', $line ) ) continue;
                // "Race Car, Darts and Balloon Pop: the order page ..." -> lead + rest.
                $lead = ''; $rest = $line;
                if ( preg_match( '/^([^:]{2,60}):\s*(.+)$/', $line, $mm ) ) { $lead = $mm[1]; $rest = $mm[2]; }
                // One sentence, kept short.
                $rest = preg_split( '/(?<=[.!?])\s/', $rest )[0];
                if ( mb_strlen( $rest ) > 90 ) {   // cut at a word, never mid-word
                    $rest = mb_substr( $rest, 0, 88 );
                    $sp   = mb_strrpos( $rest, ' ' );
                    $rest = rtrim( $sp ? mb_substr( $rest, 0, $sp ) : $rest, ' ,;-' ) . '…';
                }
                // Games it names, in the order named.
                $hay = strtolower( $lead !== '' ? $lead . ' ' . $rest : $rest );
                $hay = substr( $hay, 0, 140 );
                $found = [];
                foreach ( $names as $n => $k ) {
                    $pos = strpos( $hay, $n );
                    if ( false !== $pos && ! in_array( $k, $found, true ) ) { $found[ $pos ] = $k; $hay = substr_replace( $hay, str_repeat( '#', strlen( $n ) ), $pos, strlen( $n ) ); }
                }
                ksort( $found );
                $items[] = [ 'lead' => $lead, 'text' => $rest, 'modules' => array_values( array_slice( $found, 0, 3 ) ), 'email' => (bool) preg_match( '/\bemail/i', $line ) ];
            }
            if ( $items ) $out[] = [ 'version' => $rel[1], 'items' => $items ];
            if ( count( $out ) >= $releases ) break;
        }
        return $out;
    }
}


/* ===============================
   SETTINGS PAGE
================================ */
function giveaways_settings() {

    if (!function_exists('giveaways_modules')) {
        echo '<div class="wrap"><h1>CompKit Game Engine</h1><p>Missing giveaways_modules()</p></div>';
        return;
    }

    $modules = giveaways_modules();
    $enabled = get_option('giveaways_enabled_modules', []);

    $license_keys = function_exists('giveaways_get_license_keys')
        ? giveaways_get_license_keys()
        : get_option('giveaways_license_keys', []);

    $license_status = function_exists('giveaways_get_license_status')
        ? giveaways_get_license_status()
        : [];

    $license_expiry = function_exists('giveaways_get_license_expiry')
        ? giveaways_get_license_expiry()
        : [];

    $last_checked = 0;
    if (is_array($license_status) && isset($license_status['_meta']['checked_at'])) {
        $last_checked = (int) $license_status['_meta']['checked_at'];
    }

    /* ===== CLEAR CACHE ===== */
    if (isset($_POST['clear_license_cache'])) {

        if (!current_user_can('manage_options')) wp_die('Permission denied');

        if (
            !isset($_POST['giveaways_license_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['giveaways_license_nonce'])), 'giveaways_save_license')
        ) {
            wp_die('Security check failed');
        }

        if (function_exists('giveaways_clear_license_cache')) {
            giveaways_clear_license_cache();
        }

        delete_option('giveaways_license_status');

        if (function_exists('giveaways_validate_all_licenses')) {
            $license_status = giveaways_validate_all_licenses();
        }

        $last_checked = time();

        $diag_rows = '';
        if (function_exists('giveaways_license_endpoints')) {
            foreach (giveaways_license_endpoints() as $mod => $url) {
                $ok = !empty($license_status[$mod]) ? '✔ Valid' : '✘ Invalid';
                $colour = !empty($license_status[$mod]) ? 'green' : '#c00';
                $diag_rows .= sprintf(
                    '<tr><td style="padding:3px 8px;font-family:monospace;">%s</td><td style="padding:3px 8px;color:%s;font-weight:600;">%s</td></tr>',
                    esc_html($mod), esc_attr($colour), $ok
                );
            }
        }
        echo '<div class="updated notice"><p><strong>Licence cache cleared and re-validated.</strong></p>'
           . '<table style="border-collapse:collapse;margin-top:6px;font-size:13px;">' . $diag_rows . '</table></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $diag_rows is built with esc_html/esc_attr internally
    }

    /* ===== SAVE LICENSES (per-card submit button uses same form) ===== */
    if (isset($_POST['save_license'])) {

        if (!current_user_can('manage_options')) wp_die('Permission denied');

        if (
            !isset($_POST['giveaways_license_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['giveaways_license_nonce'])), 'giveaways_save_license')
        ) {
            wp_die('Security check failed');
        }

        $new_keys = array_map('sanitize_text_field', wp_unslash($_POST['license_keys'] ?? []));
        update_option('giveaways_license_keys', $new_keys);

        if (function_exists('giveaways_clear_license_cache')) {
            giveaways_clear_license_cache();
        }

        if (function_exists('giveaways_validate_all_licenses')) {
            $license_status = giveaways_validate_all_licenses();
        }

        $license_keys = $new_keys;

        echo '<div class="updated notice"><p>Licence saved and validated.</p></div>';
    }

    /* ===== SAVE MODULE TOGGLES ===== */
    if (isset($_POST['save'])) {

        if (!current_user_can('manage_options')) wp_die('Permission denied');

        if (
            !isset($_POST['giveaways_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['giveaways_nonce'])), 'giveaways_save_modules')
        ) {
            wp_die('Security check failed');
        }

        // Both the per-module "Save" and this "Save Changes" button submit the
        // same shared form (and both nonces are always present in it), so a
        // customer who only ever clicks "Save Changes" would otherwise have
        // any newly-typed licence keys silently ignored: $license_status would
        // stay stale/empty and every module would revert to disabled below.
        // Persist + (re)validate keys here too, exactly like the save_license
        // handler does, so a single click enables modules correctly.
        if (
            isset($_POST['license_keys']) &&
            isset($_POST['giveaways_license_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['giveaways_license_nonce'])), 'giveaways_save_license')
        ) {
            $new_keys = array_map('sanitize_text_field', wp_unslash($_POST['license_keys']));
            update_option('giveaways_license_keys', $new_keys);

            if (function_exists('giveaways_clear_license_cache')) {
                giveaways_clear_license_cache();
            }

            if (function_exists('giveaways_validate_all_licenses')) {
                $license_status = giveaways_validate_all_licenses();
            }

            $license_keys = $new_keys;
        }

        $new = [];
        foreach ($modules as $key => $module) {
            if ($key === 'instant_email') continue;

            // Ask the gate, not the status option: it verifies the server's
            // signature, so a hand-edited option can't turn a module on here.
            $is_licensed = function_exists('giveaways_is_module_licensed')
                ? giveaways_is_module_licensed($key)
                : !empty($license_status[$key]);
            $requested = isset($_POST['modules'][$key]) ? 1 : 0;

            $new[$key] = ($requested && $is_licensed) ? 1 : 0;
        }

        $new['instant_email'] = 1;

        update_option('giveaways_enabled_modules', $new);
        $enabled = $new;

        echo '<div class="updated notice"><p>Settings saved</p></div>';
    }

    // ── per-module meta ─────────────────────────────────────────────
    $module_meta = [
        'instant_display' => [
            'icon'  => plugins_url( 'icons/instant-reveal.svg', __FILE__ ),
            'color' => '#38d4c0',
            'label' => 'Instant Reveal',
            'page'  => 'instant-reveal',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/instantrevealfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/instantrevealglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/instantrevealproduct.webp', __FILE__ ),
            ],
        ],

        'spin' => [
            'icon'  => plugins_url( 'icons/spin.svg', __FILE__ ),
            'color' => '#0091ff',
            'label' => 'Spin the Wheel',
            'page'  => 'spinbolt-license',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/spinthewheelfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/spinthewheelglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/spinthewheelproduct.webp', __FILE__ ),
            ],
        ],

        'slots' => [
            'icon'  => plugins_url( 'icons/slots.svg', __FILE__ ),
            'color' => '#7c3aed',
            'label' => 'Slot Machine',
            'page'  => 'slots-license',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/slotsfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/slotsglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/slotsproduct.webp', __FILE__ ),
            ],
        ],

        'scratchcard' => [
            'icon'  => plugins_url( 'icons/scratchcard.svg', __FILE__ ),
            'color' => '#ff8800',
            'label' => 'Scratch Card',
            'page'  => 'scratchcard',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/scratchcardfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/scratchcardglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/scratchcardproduct.webp', __FILE__ ),
            ],
        ],

        'football' => [
            'icon'  => plugins_url( 'icons/football.svg', __FILE__ ),
            'color' => '#331cc6',
            'label' => 'Football Penalty',
            'page'  => 'football',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/footballfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/footballglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/footballproduct.webp', __FILE__ ),
            ],
        ],

        'racecar' => [
            'icon'  => plugins_url( 'icons/racecar.svg', __FILE__ ),
            'color' => '#c61c2a',
            'label' => 'Race Car',
            'page'  => 'racecar-license',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/kartsfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/kartsglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/kartsproduct.webp', __FILE__ ),
            ],
        ],

        'grabclaw' => [
            'icon'  => plugins_url( 'icons/grabclaw.svg', __FILE__ ),
            'color' => '#c000c4',
            'label' => 'Grab Claw',
            'page'  => 'grabclaw-settings',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/clawmachinefrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/clawmachineglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/clawmachineproduct.webp', __FILE__ ),
            ],
        ],

        'balloon_pop' => [
            'icon'  => plugins_url( 'icons/balloon-pop.svg', __FILE__ ),
            'color' => '#6c5ce7',
            'label' => 'Balloon Pop',
            'page'  => 'balloon-pop-settings',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/balloonpopfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/balloonpopglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/balloonpopproduct.webp', __FILE__ ),
            ],
        ],

        'darts' => [
            'icon'  => plugins_url( 'icons/darts.svg', __FILE__ ),
            'color' => '#e17055',
            'label' => 'Darts',
            'page'  => 'darts-settings',
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/dartsfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/dartsglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/dartsproduct.webp', __FILE__ ),
            ],
        ],
        'boxing' => [
            'icon'  => plugins_url( 'icons/boxing.svg', __FILE__ ),
            'color' => '#e11d48',
            'label' => 'Boxing',
            'page'  => 'boxing',
            // Spotlit on the dashboard, with a NEW badge, for 60 days from here.
            'released' => '2026-10-02',
            // 'global' and 'product' still show the placeholder until those
            // screenshots exist in assets/img/info/.
            'images' => [
                'frontend' => plugins_url( 'assets/img/info/boxingfrontend.webp', __FILE__ ),
                'global'   => plugins_url( 'assets/img/info/boxingglobal.webp', __FILE__ ),
                'product'  => plugins_url( 'assets/img/info/boxingproduct.webp', __FILE__ ),
            ],
        ],
    ];
    $ir_icon = plugins_url('icons/instant-reveal.svg', __FILE__);

    // ── preview data ─────────────────────────────────────────────────
    $preview_data = [
        'spin' => [
            'desc'             => 'The customer spins a wheel to uncover their prize — configurable segments, speed, and colours.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Spin</strong> category — or embed with <code>[spin]</code>',
            ],
            'settings'         => [
                'Wheel — desktop and mobile size, custom frame image and padding',
                'Segments — count, win / no-win colours, text colours, borders, and custom segment images or icons',
                'Pointer and indicator — style, colour, knob colour, plus a custom badge (image or label, colours, size, shape)',
                'Spin button — full styling: label, colours, size, border, icon (emoji or image), shadow, hover, and alignment',
                'Play button and Reveal All button — independent labels, colours, icons, and styling',
                'Ticket column — layout, card shape and size, scrollbar, and win / lose ticket styling',
                'Win and Lose popups — modal and overlay, colours, heading, and which fields show',
                'Sound effects — built-in or custom upload, with volume',
                'Behaviour — auto play, summary, and win popup options',
            ],
            'product_settings' => [
                'Override any global setting for this product, or keep “Use Global”',
                'Wheel appearance, size, frame, segments, and segment images',
                'Pointer, badge, and modal / overlay styling',
                'Play, Spin, and Reveal All button overrides',
                'Ticket column layout, win / lose ticket style, and prize vault',
                'Per-product sound and behaviour',
            ],
        ],
        'slots' => [
            'desc'             => 'A slot machine the customer plays to reveal their prize — fully customisable symbols and theme.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Slots</strong> category — or embed with <code>[slots]</code>',
            ],
            'settings'         => [
                '5 reel symbols — text, emoji, or custom image per symbol',
                'Reels — background, border, and shadow colours',
                'Header — heading text, colour, size, weight, and alignment',
                'Play button and control buttons — labels, colours, size, and icons',
                'Win and Lose results — colours, shadows, and which fields show (ticket, status, prize, description, image)',
                'Nudge feature — colours and glow',
                'Turbo mode',
                'Modal / popup — mode, size, padding, radius, and close button',
                'Sound effects — built-in or custom upload, with volume',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Per-product reel symbols (text / emoji / image)',
                'Reel and header styling overrides',
                'Play and control button overrides',
                'Win / Lose result colours and shown fields',
                'Nudge, Turbo, modal, and sound overrides',
            ],
        ],
        'scratchcard' => [
            'desc'             => 'A virtual scratch card the customer rubs away to reveal their prize underneath.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Scratchcard</strong> category — or embed with <code>[scratchcard]</code>',
            ],
            'settings'         => [
                'Game modes — Standard scratch, plus Symbol-match and Sums modes with a Prize Vault',
                'Scratch surface — custom image or colour',
                'Win and Lose cards — background, text colours, and win icon',
                'Win popup and modal — layout, optional fullscreen, size, and close button',
                'Play button — label, colours, size, radius, border, and styling',
                'Navigation buttons — Scratch Page, Previous, Next, and page buttons, fully styled',
                'Header and sound effects (built-in or custom upload, with volume)',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Per-product game mode (standard / symbol / sums)',
                'Scratch surface, win / lose card, and win popup overrides',
                'Play and navigation button overrides',
                'Header and sound overrides',
            ],
        ],
        'football' => [
            'desc'             => 'A penalty shootout the customer plays to discover their prize — fully branded stadium and boards.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Football</strong> category — or embed with <code>[football]</code>',
            ],
            'settings'         => [
                'Stadium — pitch, crowd, ball, and cutscene images',
                'Team strip / kit and opponent team name',
                'Advertising boards — left, middle, and right images',
                'Scoreboard styling',
                'Controls — aim and shoot behaviour',
                'Game header and play button — label, colours, and size',
                'Win and Lose results and the results strip — colours and shown fields',
                'Modal / popup styling',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Opponent name and team strip overrides',
                'Stadium and advertising board image overrides',
                'Scoreboard, controls, and header overrides',
                'Play button and win / lose result overrides',
            ],
        ],
        'racecar' => [
            'desc'             => 'The customer watches their car race against opponents — win or lose reveals the prize.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Racecar</strong> category — or embed with <code>[racecar]</code>',
            ],
            'settings'         => [
                'Car sprites — player and opponent car images',
                'Background / track and start-line style',
                'Advertising boards — trackside billboard images',
                'HUD — on-screen race info styling',
                'Theme colours',
                'Win and Lose results — styling and shown fields',
                'Play button — label, colours, and size',
                'Sound effects — engine and finish (built-in or custom upload, with volume)',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Player and opponent car sprite overrides',
                'Background, start line, and billboard overrides',
                'HUD, theme, and win / lose overrides',
                'Play button and sound overrides',
            ],
        ],
        'grabclaw' => [
            'desc'             => 'A grab claw machine the customer controls to pick up and reveal their prize.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Claw Machine</strong> category — or embed with <code>[grabclaw]</code>',
            ],
            'settings'         => [
                'Style presets — built-in themes (Neon Night, Candy Shop, and more)',
                'Machine colours — cabinet, pit / glass, accent, and panel',
                'Cabinet shape',
                'Machine title — text, colour, and show / hide',
                'Custom toy images — up to 6 slots (100×100 px recommended)',
                'Play button — label, colour, size, and width',
            ],
            'product_settings' => [
                'Override any global setting for this product, or inherit global',
                'Play button override',
                'Machine title — show / hide and styling',
                'Machine colour and cabinet shape overrides',
                'Custom toy image overrides (up to 6)',
            ],
        ],
        'balloon_pop' => [
            'desc'             => 'Customers pop balloons (or bubbles, stars, gifts, or pumpkins) to reveal their prize.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Balloon Pop</strong> category — or embed with <code>[balloon_pop]</code>',
            ],
            'settings'         => [
                'Object type — Balloon, Bubble, Star, Gift Box, or Pumpkin',
                'Object colour',
                'Game area — background image and styling',
                'Win and Lose results — background and text colours, and shown fields',
                'Play button — label, colour, size, and width',
                'Sound effects — Pop and Win (built-in or custom upload, with volume)',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Object type and colour per product',
                'Game area background override',
                'Win / lose colour overrides',
                'Play button and sound overrides',
            ],
        ],
        'darts' => [
            'desc'             => 'Customers throw a dart at a board to reveal their prize — choose from Classic, Neon, or Gold dart and board styles.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Darts</strong> category — or embed with <code>[darts_game]</code>',
            ],
            'settings'         => [
                'Dart and board styles — Classic, Neon, Gold, or Wood',
                'Branding — custom board and overlay images',
                'Power bar — aim and throw mechanic styling',
                'Win and Lose results — background and text colours, and shown fields',
                'Play button — label, colour, size, and width',
                'Sound effects — Throw, Hit, and Win (built-in or custom upload, with volume)',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Dart and board style overrides',
                'Branding image overrides',
                'Power bar and win / lose overrides',
                'Play button and sound overrides',
            ],
        ],
        'boxing' => [
            'desc'             => 'A typing fight: every key the customer types throws a punch, and their opponent types back. The ticket decides who lands the knockout.',
            'how_to_use'       => [
                'Create a <strong>Giveaway</strong> product in WooCommerce',
                'Enable <strong>Instant Wins</strong> on the product',
                'Add <strong>Prize Groups</strong> — enable winners and upload prize tick images',
                'Assign to the <strong>Boxing</strong> category — or embed with <code>[boxing_game]</code>',
            ],
            'settings'         => [
                'Phrases — what the player types, themed to your prize',
                'Seven fighters — players pick theirs; opponents never share their colour',
                'Opponent pace — how close each fight feels',
                'Arena, fighters, and the built-in phone keyboard — sized per device',
                'Ticket, knockout splash, and the final judges&rsquo; scorecard',
                'Play button — label, colour, size, and width',
                'Sound effects — Punch, Hit, and Win (built-in or custom upload, with volume)',
            ],
            'product_settings' => [
                'Override any global setting for this product, or use the global default',
                'Phrases themed to this competition',
                'Fighter, opponent, and artwork overrides',
                'Arena, keyboard, ticket, and scorecard overrides',
                'Play button and sound overrides',
            ],
        ],
    ];

    // ── stats ────────────────────────────────────────────────────────
    // Instant Reveal is in $module_meta for its icon and colour, but it is the
    // free game: it was being counted as a premium one ("0 of 10").
    $premium_keys    = array_values(array_diff(array_keys($module_meta), ['instant_display', 'instant_email']));
    $licensed_count  = count(array_filter($premium_keys, fn($k) => !empty($license_status[$k])));
    $active_count    = count(array_filter($premium_keys, fn($k) => !empty($enabled[$k]) && !empty($license_status[$k])));
    $premium_total   = count($premium_keys);
    ?>

    <?php
    $ck_has_woo      = class_exists('WooCommerce');
    $ck_has_giveaways = function_exists('lty_get_lottery_ticket');
    if (!$ck_has_woo || !$ck_has_giveaways) : ?>
    <div class="ck-req-banner">
        <div class="ck-req-icon">!</div>
        <div class="ck-req-body">
            <div class="ck-req-heading">Required plugins</div>
            <p class="ck-req-desc">CompKit Game Engine requires these plugins to be installed and active:</p>
            <ul class="ck-req-list">
                <li class="ck-req-item <?php echo $ck_has_woo ? 'ck-req-ok' : 'ck-req-missing'; ?>">
                    <span class="ck-req-dot"></span>
                    <span class="ck-req-name">WooCommerce</span>
                    <?php if (!$ck_has_woo) : ?>
                    <a class="ck-req-link" href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>">Install →</a>
                    <?php endif; ?>
                </li>
                <li class="ck-req-item <?php echo $ck_has_giveaways ? 'ck-req-ok' : 'ck-req-missing'; ?>">
                    <span class="ck-req-dot"></span>
                    <span class="ck-req-name">Giveaway for WooCommerce <span class="ck-req-by">by Flintop <span class="ck-req-aka">(formerly Lottery for WooCommerce)</span></span></span>
                    <?php if (!$ck_has_giveaways) : ?>
                    <a class="ck-req-link" href="<?php echo esc_url(admin_url('plugin-install.php?s=giveaways+woocommerce+flintop&tab=search&type=term')); ?>">Install →</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Landing on this page IS the request to check for updates — it is the one
    // screen whose purpose is showing update state. Throttled to once a minute
    // inside the function; without this a release stays invisible here for up
    // to six hours because the cache only expires on the INSTALLED version
    // changing, which a server-side release never does.
    if ( function_exists( 'giveaways_refresh_updates_for_admin_page' ) ) {
        giveaways_refresh_updates_for_admin_page();
    }
    ?>
    <div class="wrap ck-dashboard">
    <div class="ck-page-container">

        <!-- ══ HEADER ════════════════════════════════════════════════ -->
        <div class="ck-header">
            <div class="ck-header-brand">
                <img src="<?php echo esc_url( GIVEAWAYS_URL . 'assets/img/compkit-logo-300.png' ); ?>"
                     alt="CompKit Game Engine" class="ck-header-logo-img">
                <div class="ck-header-title">
                    <h1>CompKit Game Engine</h1>
                    <span><?php echo esc_html( sprintf( '%d instant-win games for your competitions', $premium_total + 1 ) ); /* + the free Instant Reveal */ ?></span>
                </div>
            </div>
            <div class="ck-header-right">
                <span class="ck-head-pill" title="<?php echo $last_checked ? esc_attr( 'Licences last checked ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_checked ) ) : ''; ?>">
                    <span class="ck-head-dot"></span><?php echo esc_html( sprintf( '%d of %d premium games active', $active_count, $premium_total ) ); ?>
                </span>
                <?php // Read from the plugin header at runtime, like the white-label edition
                      // already does. Hardcoded, this sat at v2.2.1 for three releases —
                      // and version.ps1 never caught it because it isn't one of the eight
                      // locations it manages. ?>
                <span class="ck-version-badge">v<?php echo esc_html( GIVEAWAYS_VERSION ); ?></span>
                <?php
                // The badge above is the INSTALLED version and always will be, so
                // on its own it can never tell you a release exists. Until this
                // was added, a new core build only ever surfaced on WordPress's
                // own Plugins screen — which is not where anyone was looking.
                $ck_core_update = function_exists( 'giveaways_core_update' ) ? giveaways_core_update() : null;

                // Landing on the page checks for you, but that is throttled, so
                // there is a short window after publishing where the answer is
                // the one cached a moment before. This is the escape hatch, and
                // it is here rather than buried in the licence panel because
                // "is there an update" is the question the header answers.
                if ( ! $ck_core_update && current_user_can( 'update_plugins' ) ) : ?>
                <a class="ck-check-updates" href="<?php echo esc_url( add_query_arg( 'force-check', '1' ) ); ?>"
                   title="Ask the licence server again right now, ignoring the cached answer.">Check for updates</a>
                <?php endif;

                if ( $ck_core_update && current_user_can( 'update_plugins' ) ) : ?>
                <button type="button" class="ck-core-update-btn" id="ck-core-update-btn"
                        data-version="<?php echo esc_attr( $ck_core_update['available'] ); ?>"
                        title="<?php echo esc_attr( sprintf( 'You have v%s. Updating installs v%s.', $ck_core_update['installed'], $ck_core_update['available'] ) ); ?>">
                    ↑ Update to v<?php echo esc_html( $ck_core_update['available'] ); ?>
                </button>
                <?php
                // Its own bar rather than the premium one: that lives inside the
                // download panel, which is hidden whenever there is nothing
                // premium to install — and a progress bar you cannot see is
                // worse than none, because the page then looks frozen.
                ?>
                <div class="ck-update-progress ck-core-progress" id="ck-core-progress" hidden>
                    <div class="ck-update-progress-track"
                         role="progressbar"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                         aria-labelledby="ck-core-progress-label">
                        <div class="ck-update-progress-fill" id="ck-core-progress-fill"></div>
                    </div>
                    <span class="ck-update-progress-label" id="ck-core-progress-label" aria-live="polite"></span>
                </div>
                <?php endif; ?>
                <?php
                /* Leave a review, without waiting to be asked.
                 *
                 * The nag in giveaways-review-prompt.php only fires once a site
                 * has really been used (50 reveals, 14 days). Those numbers are
                 * a guess about when someone has an opinion worth asking for —
                 * they must never stop someone who already wants to give one.
                 * This is that door, and it has no thresholds on it.
                 *
                 * function_exists() because the white-label fork of
                 * giveaways-add-ons.php deliberately does not load the review
                 * prompt, so on that edition this button correctly vanishes
                 * rather than fataling. */
                if ( function_exists( 'compkit_review_can_review' ) && compkit_review_can_review() ) : ?>
                <a class="ck-check-updates" href="#" data-ckrv-open
                   title="<?php esc_attr_e( 'Write a line about CompKit for the website. Nothing is published until it has been read.', 'compkit-game-engine' ); ?>"><?php esc_html_e( 'Leave a review', 'compkit-game-engine' ); ?></a>
                <?php endif; ?>
                <a href="https://compkit.kriscreates.co.uk" target="_blank" rel="noopener" class="ck-header-link">CompKit Game Engine ↗</a>
            </div>
        </div>

        <!-- ══ SPOTLIGHT + WHAT'S NEW ═══════════════════════════════ -->
        <?php
        $ck_spot      = compkit_dashboard_spotlight( $module_meta, (array) $license_status );
        $ck_news      = compkit_dashboard_news( $module_meta );
        $ck_icon_url  = function ( $k ) use ( $module_meta, $ir_icon ) { return $k === 'instant_display' ? $ir_icon : ( $module_meta[ $k ]['icon'] ?? '' ); };
        $ck_icon_col  = function ( $k ) use ( $module_meta ) { return $k === 'instant_display' ? '#38d4c0' : ( $module_meta[ $k ]['color'] ?? '#475569' ); };
        if ( $ck_spot || $ck_news ) : ?>
        <div class="ck-hero<?php echo $ck_spot ? '' : ' ck-hero-solo'; ?>">
            <?php if ( $ck_spot ) :
                $sm        = $module_meta[ $ck_spot ];
                $s_lic     = ! empty( $license_status[ $ck_spot ] );
                $s_on      = $s_lic && ! empty( $enabled[ $ck_spot ] ) && giveaways_module_folder_exists( $ck_spot );
                $s_img     = $sm['images']['frontend'] ?? '';
                $s_new     = compkit_dashboard_is_new( $sm );
                $s_desc    = $preview_data[ $ck_spot ]['desc'] ?? '';
            ?>
            <div class="ck-spot<?php echo $s_img ? ' has-img' : ''; ?>"
                 style="--card-color:<?php echo esc_attr( $sm['color'] ); ?>;<?php echo $s_img ? '--spot-img:url(' . esc_url( $s_img ) . ');' : ''; ?>">
                <?php if ( ! $s_img ) : ?>
                <span class="ck-spot-art" style="-webkit-mask-image:url('<?php echo esc_url( $sm['icon'] ); ?>');mask-image:url('<?php echo esc_url( $sm['icon'] ); ?>');"></span>
                <?php endif; ?>
                <div class="ck-spot-tags">
                    <span class="ck-spot-chip ck-spot-new"><?php echo $s_new ? 'New game' : 'Add to your site'; ?></span>
                    <?php if ( $s_on ) : ?><span class="ck-spot-chip">Active on this site</span><?php endif; ?>
                </div>
                <div class="ck-spot-body">
                    <div class="ck-spot-name">
                        <span class="ck-spot-icon" style="-webkit-mask-image:url('<?php echo esc_url( $sm['icon'] ); ?>');mask-image:url('<?php echo esc_url( $sm['icon'] ); ?>');"></span>
                        <?php echo esc_html( $sm['label'] ); ?>
                    </div>
                    <?php if ( $s_desc ) : ?><p><?php echo esc_html( $s_desc ); ?></p><?php endif; ?>
                    <div class="ck-spot-btns">
                        <?php if ( $s_on ) : ?>
                        <a class="ck-spot-btn primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $sm['page'] ) ); ?>">Open <?php echo esc_html( $sm['label'] ); ?> settings</a>
                        <?php elseif ( $s_lic ) : ?>
                        <button type="button" class="ck-spot-btn primary" data-open-row="<?php echo esc_attr( $ck_spot ); ?>">Set up <?php echo esc_html( $sm['label'] ); ?></button>
                        <?php else : ?>
                        <button type="button" class="ck-spot-btn primary" data-open-row="<?php echo esc_attr( $ck_spot ); ?>">Get <?php echo esc_html( $sm['label'] ); ?></button>
                        <?php endif; ?>
                        <button type="button" class="ck-spot-btn" data-open-row="<?php echo esc_attr( $ck_spot ); ?>">See what&rsquo;s inside</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $ck_news ) : ?>
            <div class="ck-news">
                <div class="ck-news-title">What&rsquo;s new</div>
                <?php foreach ( $ck_news as $rel ) : ?>
                <div class="ck-news-rel">
                    <div class="ck-news-ver">v<?php echo esc_html( $rel['version'] ); ?></div>
                    <ul>
                        <?php foreach ( $rel['items'] as $it ) : ?>
                        <li>
                            <span class="ck-news-mods">
                                <?php if ( $it['modules'] ) : foreach ( $it['modules'] as $mk ) : ?>
                                <span class="ck-news-mod" style="--c:<?php echo esc_attr( $ck_icon_col( $mk ) ); ?>;"><i style="-webkit-mask-image:url('<?php echo esc_url( $ck_icon_url( $mk ) ); ?>');mask-image:url('<?php echo esc_url( $ck_icon_url( $mk ) ); ?>');"></i></span>
                                <?php endforeach; else : ?>
                                <span class="ck-news-mod ck-news-gen<?php echo $it['email'] ? ' is-email' : ''; ?>"><i></i></span>
                                <?php endif; ?>
                            </span>
                            <span><?php if ( $it['lead'] ) : ?><b><?php echo esc_html( $it['lead'] ); ?>:</b> <?php endif; echo esc_html( $it['text'] ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
                <a class="ck-news-more thickbox" href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=compkit-game-engine&section=changelog&TB_iframe=true&width=640&height=640' ) ); ?>">Full changelog &rarr;</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" id="ck-main-form">
            <?php
                wp_nonce_field('giveaways_save_modules', 'giveaways_nonce');
                wp_nonce_field('giveaways_save_license', 'giveaways_license_nonce');
            ?>

            <!-- ══ YOUR GAMES ════════════════════════════════════════ -->
            <div class="ck-section-header ck-games-head">
                <span class="ck-section-label">Your games</span>
                <span class="ck-section-line"></span>
                <span class="ck-filters" role="tablist">
                    <button type="button" class="is-on" data-filter="all">All</button>
                    <button type="button" data-filter="active">Active</button>
                    <button type="button" data-filter="locked">Not licensed</button>
                </span>
            </div>

            <?php
            // Does the customer hold at least one active premium licence, and is
            // every licensed game both installed AND enabled? If so there's nothing
            // left to do — the download button fades away and the how-to collapses.
            $has_premium_licence = false;
            $all_licensed_active = true;
            foreach ( $modules as $mk => $mv ) {
                if ( $mk === 'instant_email' || $mk === 'instant_display' ) continue;
                if ( ! empty( $license_status[ $mk ] ) ) {
                    $has_premium_licence = true;
                    $mk_installed = giveaways_module_folder_exists( $mk );
                    $mk_enabled   = ! empty( $enabled[ $mk ] );
                    if ( ! $mk_installed || ! $mk_enabled ) {
                        $all_licensed_active = false;
                    }
                }
            }
            $premium_all_set = $has_premium_licence && $all_licensed_active;

            // Is the installed premium plugin a different version from this engine?
            // Keep both plugins on the same version number always — that match (or
            // mismatch) is exactly what drives the Download/Update state below.
            // Resolved through the shared helper rather than by naming the constant,
            // so this stays identical to the Game Addons fork. That fork tested the
            // CompKit constant, which its own premium shell never defines, and the
            // whole "Install / Update Games" bar stayed hidden as a result.
            $ck_premium_installed = function_exists( 'giveaways_installed_premium_version' )
                ? giveaways_installed_premium_version()
                : '';

            $premium_version_mismatch = '' !== $ck_premium_installed
                && defined( 'GIVEAWAYS_VERSION' )
                && $ck_premium_installed !== GIVEAWAYS_VERSION;

            // Which games a single premium update would bring forward. Drives the
            // per-row badges below; the action is always the one plugin update.
            $ck_module_updates = function_exists( 'giveaways_module_updates' )
                ? giveaways_module_updates()
                : [];

            // Can this user actually install? On multisite that's a super-admin
            // capability, so offering the button to anyone else just produces a
            // permission error after the click.
            $ck_can_update = current_user_can( 'update_plugins' ) && current_user_can( 'install_plugins' );

            // Nothing to show unless there's an update pending or something not yet
            // fully active — fades out once everything's licensed, installed, and on.
            $show_download_bar = $premium_version_mismatch || ! empty( $ck_module_updates )
                || ( $has_premium_licence && ! $all_licensed_active );
            ?>


                <?php
                // One action, whatever prompted it: the eight games ship inside a
                // single plugin, so "update darts" and "update everything" are the
                // same operation. The per-game badges below say which games it
                // brings forward.
                $ck_update_count = count( $ck_module_updates );
                $ck_needs_update = $premium_version_mismatch || $ck_update_count > 0;
                ?>
                <div class="ck-premium-download-bar" id="ck-premium-download-bar"<?php echo $show_download_bar ? '' : ' style="display:none"'; ?>>
                    <button type="button" id="ck-download-premium"
                            class="ck-download-premium-btn<?php echo $ck_needs_update ? ' ck-update-games' : ''; ?>"
                            data-mode="<?php echo $ck_needs_update ? 'update' : 'download'; ?>"
                            <?php disabled( ! $ck_can_update ); ?>>
                        <?php echo $ck_needs_update ? 'Update all games' : 'Install games'; ?>
                    </button>
                    <?php if ( $ck_needs_update ) : ?>
                    <span class="ck-premium-version-note" id="ck-premium-version-note">New version available: v<?php echo esc_html( GIVEAWAYS_VERSION ); ?></span>
                    <?php endif; ?>
                    <span class="ck-premium-download-hint" id="ck-download-hint"><?php
                        if ( ! $ck_can_update ) {
                            echo 'You need permission to install plugins on this site. Ask an administrator to run the update.';
                        } elseif ( $ck_update_count > 0 ) {
                            printf(
                                /* translators: %d = number of games with an update */
                                esc_html( _n( '%d game has an update. One click updates them all — nothing you have is lost.', '%d games have updates. One click updates them all — nothing you have is lost.', $ck_update_count, 'compkit-game-engine' ) ),
                                (int) $ck_update_count
                            );
                        } elseif ( $premium_version_mismatch ) {
                            echo 'Your installed <strong>CompKit Game Engine Premium</strong> (v' . esc_html( $ck_premium_installed ) . ') is out of date.';
                        } elseif ( $has_premium_licence ) {
                            echo 'Installs every game your licences cover, directly into WordPress.';
                        } else {
                            echo 'Save a licence key on any game below to unlock your games.';
                        }
                    ?></span>
                    <?php
                    // Hidden until an install starts. Rendered here rather than
                    // built in JS so the markup is visible to anyone reading the
                    // page for class names, and so it cannot appear at all on a
                    // page where the button is absent.
                    ?>
                    <div class="ck-update-progress" id="ck-update-progress" hidden>
                        <div class="ck-update-progress-track"
                             role="progressbar"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                             aria-labelledby="ck-update-progress-label">
                            <div class="ck-update-progress-fill" id="ck-update-progress-fill"></div>
                        </div>
                        <span class="ck-update-progress-label" id="ck-update-progress-label" aria-live="polite"></span>
                    </div>
                </div>

            <?php
            $ck_preview_json = wp_json_encode($preview_data);
            $ck_meta_json    = wp_json_encode(array_map(
                fn($m) => ['color' => $m['color'], 'label' => $m['label'], 'page' => $m['page'], 'icon' => $m['icon']],
                $module_meta
            ));
            echo '<script>var ckPreviewData=' . $ck_preview_json . ';var ckModuleMeta=' . $ck_meta_json . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>

            <div class="ck-games-list">
                <div class="ck-free-tile" data-filter-key="free" style="--card-color:#38d4c0;">
                    <div class="ck-row-header">
                        <div class="ck-row-left">
                            <div class="ck-row-icon" style="background-color:#38d4c0;-webkit-mask-image:url('<?php echo esc_url($ir_icon); ?>');mask-image:url('<?php echo esc_url($ir_icon); ?>');"></div>
                            <div class="ck-row-meta">
                                <div class="ck-row-name-wrap"><span class="ck-row-name">Instant Reveal</span></div>
                                <span class="ck-row-status">Included free &middot; the animated reveal on the order page</span>
                            </div>
                            <span class="ck-free-chip">Free</span>
                        </div>
                        <div class="ck-row-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=instant-reveal')); ?>" class="ck-settings-pill">Settings &rarr;</a>
                        </div>
                    </div>
                </div>
                <?php foreach ($modules as $key => $module) :

                    if ($key === 'instant_email' || $key === 'instant_display') continue;
                    if (!isset($module_meta[$key])) continue;

                    $meta         = $module_meta[$key];
                    $active         = !empty($enabled[$key]);
                    $is_licensed    = !empty($license_status[$key]);
                    $has_key        = !empty($license_keys[$key]);
                    $is_installed   = giveaways_module_folder_exists($key);
                    $really_active  = $active && $is_installed;

                    $status_text = 'Enter your licence key to unlock';
                    if ($has_key && $is_licensed)  $status_text = 'Licence active';
                    if ($has_key && !$is_licensed) $status_text = 'Invalid licence key';

                    $badge_text  = !$is_licensed ? 'Add Licence' : ($really_active ? 'Enabled' : 'Disabled');
                    $badge_class = !$is_licensed ? 'locked'      : ($really_active ? 'enabled' : 'disabled');
                    $dot_class   = !$is_licensed ? 'dot-locked'  : ($really_active ? 'dot-active' : 'dot-idle');

                    $expiry_text = '';
                    if ($is_licensed && !empty($license_expiry[$key]['expires'])) {
                        $exp         = $license_expiry[$key];
                        $exp_ts      = strtotime($exp['expires']);
                        $exp_date    = $exp_ts ? date_i18n('j M Y', $exp_ts) : '';
                        $exp_label   = in_array($exp['code'] ?? '', ['cancelled', 'paused'], true) ? 'Expires' : 'Renews';
                        $expiry_text = $exp_date ? $exp_label . ' ' . $exp_date : '';
                    }
                ?>

                <div class="ck-game-row <?php echo $is_licensed ? 'is-licensed' : 'is-locked'; ?><?php echo compkit_dashboard_is_new( $meta ) ? ' is-new' : ''; ?>"
                     data-licensed="<?php echo $is_licensed ? '1' : '0'; ?>"
                     data-enabled="<?php echo $really_active ? '1' : '0'; ?>"
                     data-installed="<?php echo $is_installed ? '1' : '0'; ?>"
                     data-settings-url="<?php echo esc_url( admin_url( 'admin.php?page=' . $meta['page'] ) ); ?>"
                     style="--card-color:<?php echo esc_attr($meta['color']); ?>;"
                     data-key="<?php echo esc_attr($key); ?>">

                    <!-- ── Clickable header ── -->
                    <div class="ck-row-header">
                        <div class="ck-row-left">
                            <span class="ck-dot <?php echo esc_attr($dot_class); ?>"></span>
                            <div class="ck-row-icon"
                                 style="background-color:<?php echo esc_attr($meta['color']); ?>;
                                        -webkit-mask-image:url('<?php echo esc_url($meta['icon']); ?>');
                                        mask-image:url('<?php echo esc_url($meta['icon']); ?>');"></div>
                            <div class="ck-row-meta">
                                <div class="ck-row-name-wrap">
                                    <span class="ck-row-name"><?php echo esc_html($meta['label']); ?></span>
                                    <span class="ck-premium-tag">PREMIUM</span>
                                    <?php
                                    // Only meaningful once the game is actually installed.
                                    $module_ver = ( $is_installed && function_exists( 'giveaways_module_version' ) )
                                        ? giveaways_module_version( $key )
                                        : '';
                                    if ( $module_ver ) : ?>
                                    <span class="ck-row-version">v<?php echo esc_html( $module_ver ); ?></span>
                                    <?php endif;

                                    // Games don't update on their own — they all live in the
                                    // one premium plugin — so this badge marks which games a
                                    // single update will bring forward, and the button runs
                                    // that one update.
                                    $mod_update = $ck_module_updates[ $key ] ?? null;
                                    if ( $mod_update ) : ?>
                                    <span class="ck-row-update" title="<?php echo esc_attr( sprintf( 'You have v%s. Updating installs v%s.', $mod_update['installed'], $mod_update['available'] ) ); ?>">
                                        ↑ v<?php echo esc_html( $mod_update['available'] ); ?> available
                                    </span>
                                    <button type="button" class="ck-row-update-btn ck-update-games"
                                            data-game="<?php echo esc_attr( $meta['label'] ); ?>">Update</button>
                                    <?php endif; ?>
                                </div>
                                <span class="ck-row-status"><?php echo esc_html($status_text); ?></span>
                                <?php if ($expiry_text) :
                                    $is_expiring = isset($exp) && in_array($exp['code'] ?? '', ['cancelled', 'paused'], true); ?>
                                <span class="ck-expiry-badge<?php echo $is_expiring ? ' ck-expiry-warn' : ''; ?>">
                                    <?php echo $is_expiring ? '⚠' : '↻'; ?> <?php echo esc_html($expiry_text); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ck-row-actions">
                            <?php $sw_state = !$is_licensed ? 'sw-locked' : ($really_active ? 'sw-on' : 'sw-off'); ?>
                            <label class="ck-toggle-pill <?php echo esc_attr($sw_state); ?><?php echo !$is_licensed ? ' ck-toggle-locked' : ''; ?>"
                                   onclick="event.stopPropagation()">
                                <input type="checkbox"
                                       class="giveaways-toggle"
                                       name="modules[<?php echo esc_attr($key); ?>]"
                                       <?php checked($really_active); ?>
                                       <?php disabled(!$is_licensed); ?>>
                                <span class="ck-toggle-dot"></span>
                                <span class="ck-toggle-text"><?php echo esc_html($badge_text); ?></span>
                            </label>
                            <?php if ($is_licensed && $really_active) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $meta['page'])); ?>"
                               class="ck-settings-pill"
                               onclick="event.stopPropagation()">Settings →</a>
                            <?php endif; ?>
                            <button type="button" class="ck-chevron-btn"
                                    aria-label="Info for <?php echo esc_attr($meta['label']); ?>">
                                <span class="ck-chevron-text">Details</span>
                                <span class="ck-chevron-icon">▾</span>
                            </button>
                        </div>
                    </div><!-- /.ck-row-header -->

                    <!-- ── Expandable panel ── -->
                    <div class="ck-row-panel" aria-hidden="true">
                        <div class="ck-drawer-head">
                            <span class="ck-drawer-tile"><i style="-webkit-mask-image:url('<?php echo esc_url($meta['icon']); ?>');mask-image:url('<?php echo esc_url($meta['icon']); ?>');"></i></span>
                            <div class="ck-drawer-title">
                                <?php if ( compkit_dashboard_is_new( $meta ) ) : ?><span class="ck-drawer-chip">New game</span><?php endif; ?>
                                <span class="ck-drawer-name"><?php echo esc_html($meta['label']); ?></span>
                            </div>
                            <button type="button" class="ck-drawer-close" aria-label="Close">&times;</button>
                        </div>
                        <div class="ck-row-panel-inner">

                            <!-- ── Licence section ── -->
                            <div class="ck-panel-licence">
                                <?php if (!$is_licensed) : ?>
                                <div class="ck-buy-section">
                                    <div class="ck-buy-label">Get a licence</div>
                                    <div class="ck-buy-buttons">
                                        <button type="button" class="ck-buy-btn ck-buy-monthly"
                                                data-module="<?php echo esc_attr($key); ?>" data-interval="monthly">
                                            Monthly <span class="ck-buy-price" data-module="<?php echo esc_attr($key); ?>" data-interval="monthly">…</span>
                                        </button>
                                        <button type="button" class="ck-buy-btn ck-buy-yearly"
                                                data-module="<?php echo esc_attr($key); ?>" data-interval="yearly">
                                            Yearly <span class="ck-buy-price" data-module="<?php echo esc_attr($key); ?>" data-interval="yearly">…</span>
                                        </button>
                                    </div>
                                    <div class="ck-buy-divider">or enter a key manually</div>
                                </div>
                                <?php endif; ?>
                                <div class="ck-licence-field">
                                    <label class="ck-licence-label">Licence Key</label>
                                    <div class="ck-licence-row">
                                        <input type="text"
                                               class="giveaways-license-input"
                                               name="license_keys[<?php echo esc_attr($key); ?>]"
                                               value="<?php echo esc_attr($license_keys[$key] ?? ''); ?>"
                                               placeholder="XXXX-XXXX-XXXX-XXXX"
                                               autocomplete="off">
                                        <button type="submit" name="save_license" value="1" class="giveaways-license-save">Save</button>
                                    </div>
                                    <div class="giveaways-inline-status <?php echo ($has_key && $is_licensed) ? 'status-ok' : (($has_key && !$is_licensed) ? 'status-err' : ''); ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </div>
                                </div>
                            </div><!-- /.ck-panel-licence -->

                            <?php if ( $is_licensed ) : ?>
                            <div class="ck-module-prompt" data-module-prompt="<?php echo esc_attr( $key ); ?>">
                                <?php if ( $really_active ) : ?>
                                <span class="ck-active-badge-inline">✓ Module Active</span>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $meta['page'] ) ); ?>"
                                   class="ck-settings-cta-inline">Configure Settings →</a>
                                <span class="ck-uninstall-note">To remove a game, turn it off above, or deactivate the <strong>CompKit Game Engine Premium</strong> plugin.</span>
                                <?php elseif ( $is_installed ) : ?>
                                <p class="ck-enable-msg">Your licence is active — enable the module to start using it.</p>
                                <button type="button" class="ck-enable-btn"
                                        data-module="<?php echo esc_attr( $key ); ?>">
                                    Enable <?php echo esc_html( $meta['label'] ); ?> →
                                </button>
                                <?php else : ?>
                                <p class="ck-install-msg">✓ Licence active — this game is included in your download. Use <strong>Download CompKit Games</strong> at the top, then install via Plugins → Add New → Upload.</p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- ① Frontend View -->
                            <div class="ck-info-section">
                                <div class="ck-info-placeholder ck-placeholder-frontend"
                                     style="--ph-color:<?php echo esc_attr( $meta['color'] ); ?>;">

                                    <?php if ( ! empty( $meta['images']['frontend'] ) ) : ?>

                                        <img
                                            src="<?php echo esc_url( $meta['images']['frontend'] ); ?>"
                                            alt=""
                                            class="ck-preview-image">

                                    <?php else : ?>

                                        <svg class="ck-ph-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <rect x="2" y="4" width="20" height="14" rx="2.5" stroke="currentColor" stroke-width="1.6"/>
                                            <path d="M8 22h8M12 18v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                            <circle cx="12" cy="11" r="3" stroke="currentColor" stroke-width="1.5"/>
                                        </svg>

                                        <span>Frontend View</span>
                                        <span class="ck-ph-sub">Screenshot coming soon</span>

                                        <div class="ck-portrait-ph">
                                            <svg class="ck-portrait-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <rect x="5" y="2" width="14" height="20" rx="3" stroke="currentColor" stroke-width="1.6"/>
                                                <path d="M9 6h6M12 18h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                            </svg>
                                            <span class="ck-ph-sub">Mobile view</span>
                                        </div>

                                    <?php endif; ?>

                                </div>

                                <div class="ck-info-content">
                                    <div class="ck-info-title">Frontend View</div>
                                    <p class="ck-panel-preview-desc"></p>
                                    <div class="ck-panel-section-label" style="margin-top:6px;">How to use</div>
                                    <p class="ck-panel-howto"></p>
                                    <div class="ck-panel-actions"></div>
                                </div>
                            </div>

                            <!-- ② Global Settings -->
                            <div class="ck-info-section">
                                <div class="ck-info-placeholder ck-placeholder-global"
                                     style="--ph-color:<?php echo esc_attr( $meta['color'] ); ?>;">

                                    <?php if ( ! empty( $meta['images']['global'] ) ) : ?>

                                        <img
                                            src="<?php echo esc_url( $meta['images']['global'] ); ?>"
                                            alt=""
                                            class="ck-preview-image">

                                    <?php else : ?>

                                        <svg class="ck-ph-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
                                            <path d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                        </svg>

                                        <span>Global Settings</span>
                                        <span class="ck-ph-sub">Screenshot coming soon</span>

                                    <?php endif; ?>

                                </div>

                                <div class="ck-info-content">
                                    <div class="ck-info-title">Global Settings</div>
                                    <p class="ck-info-hint">These settings apply to all products using this game unless overridden at product level.</p>
                                    <ul class="ck-panel-settings-list"></ul>
                                </div>
                            </div>

                            <!-- ③ Product Level Settings -->
                            <div class="ck-info-section">
                                <div class="ck-info-placeholder ck-placeholder-product"
                                     style="--ph-color:<?php echo esc_attr( $meta['color'] ); ?>;">

                                    <?php if ( ! empty( $meta['images']['product'] ) ) : ?>

                                        <img
                                            src="<?php echo esc_url( $meta['images']['product'] ); ?>"
                                            alt=""
                                            class="ck-preview-image">

                                    <?php else : ?>

                                        <svg class="ck-ph-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                                            <circle cx="7" cy="7" r="1.5" fill="currentColor"/>
                                        </svg>

                                        <span>Product Settings</span>
                                        <span class="ck-ph-sub">Screenshot coming soon</span>

                                    <?php endif; ?>

                                </div>

                                <div class="ck-info-content">
                                    <div class="ck-info-title">Product Level Settings</div>
                                    <p class="ck-info-hint">Set on each WooCommerce product individually — overrides the global defaults above.</p>
                                    <ul class="ck-panel-product-settings-list"></ul>
                                </div>
                            </div>


                        </div><!-- /.ck-row-panel-inner -->
                    </div><!-- /.ck-row-panel -->

                </div><!-- /.ck-game-row -->
                <?php endforeach; ?>
            </div><!-- /.ck-games-list -->

            <!-- ══ STICKY SAVE BAR ══════════════════════════════════ -->
            <div class="ck-save-bar">
                <div class="ck-save-inner">
                    <button class="ck-btn-primary" name="save" value="1">Save Changes</button>
                    <button class="ck-btn-secondary" name="clear_license_cache" value="1">&#8635; Clear Cache &amp; Re-validate</button>
                    <span class="ck-save-hint">Toggle switches then Save Changes to activate a module</span>
                </div>
            </div>

        </form>

        <!-- ══ BUNDLE DEALS ═════════════════════════════════════════ -->
        <div class="ck-section-header" style="margin-top:40px;" id="ck-bundles-header" style="display:none">
            <span class="ck-section-label">Bundle Deals</span>
            <span class="ck-section-line"></span>
        </div>
        <div id="ck-bundles-grid" class="ck-bundles-grid"></div>

        <!-- ══ HELP SECTION ═════════════════════════════════════════ -->
        <div class="ck-section-header" style="margin-top:40px;">
            <span class="ck-section-label">Need help?</span>
            <span class="ck-section-line"></span>
        </div>

        <div class="ck-drawer-scrim" id="ck-drawer-scrim"></div>

        <div class="ck-help-grid">
            <div class="ck-help-card" id="giveaways-support">
                <div class="ck-help-icon support"></div>
                <div class="ck-help-body">
                    <div class="ck-help-title">Support</div>
                    <div class="ck-help-desc">Report a bug or get help with setup</div>
                </div>
                <span class="ck-help-arrow">→</span>
            </div>
            <div class="ck-help-card" id="giveaways-feature">
                <div class="ck-help-icon feature"></div>
                <div class="ck-help-body">
                    <div class="ck-help-title">Feature Request</div>
                    <div class="ck-help-desc">Suggest new games or functionality</div>
                </div>
                <span class="ck-help-arrow">→</span>
            </div>
        </div>

        <!-- How premium games work -->
        <div class="ck-premium-howto is-collapsed" id="ck-premium-howto">
            <div class="ck-premium-howto-title" id="ck-howto-toggle" role="button" tabindex="0">
                <span>How premium games work</span>
                <span class="ck-howto-caret">▾</span>
            </div>
            <div class="ck-premium-howto-body">
            <ol class="ck-premium-howto-steps">
                <li><strong>Buy or enter a licence key</strong> for a game (or a bundle), then press <strong>Save</strong>.</li>
                <li>Press <strong>Install&nbsp;/&nbsp;Update Games</strong> below. Your games are installed straight into WordPress — no download, no upload. The install always contains <strong>every game you own</strong>.</li>
                <li>Your games switch on here automatically once installed.</li>
                <li><strong>Bought more later?</strong> Save the new key and press the button again. The new game is added and nothing you already have is lost.</li>
            </ol>

            </div><!-- /.ck-premium-howto-body -->
        </div>


        <!-- ══ MODAL ════════════════════════════════════════════════ -->
        <div id="giveaways-modal" style="display:none;">
            <div class="giveaways-modal-box">
                <div class="ck-modal-header">
                    <h2 id="giveaways-modal-title"></h2>
                    <button type="button" id="giveaways-modal-close" class="ck-modal-x" aria-label="Close">✕</button>
                </div>
                <textarea id="giveaways-message" rows="6" placeholder="Type your message..."></textarea>
                <div id="giveaways-modal-status" style="display:none;"></div>
                <div class="ck-modal-footer">
                    <button type="button" class="ck-btn-primary" id="giveaways-send">Send Message</button>
                    <button type="button" class="ck-btn-ghost" id="giveaways-modal-close-2">Cancel</button>
                </div>
            </div>
        </div>

    </div><!-- /.ck-page-container -->
    </div>
    <?php
}


/* ===============================
   ADMIN CSS
================================ */
add_action('admin_head', function () {

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'toplevel_page_giveaways-addons') return;
    ?>
    <style>
        /* ── Requirements banner ───────────────────────────── */
        .ck-req-banner {
            display: flex; align-items: flex-start; gap: 16px;
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-left: 4px solid #f59e0b;
            border-radius: 12px;
            padding: 18px 22px;
            margin: 16px 0 20px;
            box-shadow: 0 2px 8px rgba(245,158,11,.1);
        }
        .ck-req-icon {
            flex-shrink: 0;
            width: 28px; height: 28px; border-radius: 50%;
            background: #f59e0b;
            color: #fff; font-weight: 900; font-size: 15px;
            display: flex; align-items: center; justify-content: center;
            margin-top: 1px;
        }
        .ck-req-body { flex: 1; min-width: 0; }
        .ck-req-heading { font-size: 14px; font-weight: 700; color: #92400e; margin-bottom: 4px; }
        .ck-req-desc    { font-size: 13px; color: #78350f; margin: 0 0 10px; }
        .ck-req-list    { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 7px; }
        .ck-req-item    { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .ck-req-dot {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        }
        .ck-req-ok   .ck-req-dot { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .ck-req-missing .ck-req-dot { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.18); }
        .ck-req-ok   .ck-req-name { color: #166534; font-weight: 600; }
        .ck-req-missing .ck-req-name { color: #991b1b; font-weight: 600; }
        .ck-req-by  { font-weight: 400; color: #6b7280; }
        .ck-req-aka { font-size: 11px; color: #9ca3af; }
        .ck-req-link {
            font-size: 12px; font-weight: 700; color: #b45309;
            text-decoration: none; margin-left: 4px;
            padding: 2px 8px; border-radius: 999px;
            background: rgba(245,158,11,.15); border: 1px solid #fcd34d;
            transition: background .15s;
        }
        .ck-req-link:hover { background: rgba(245,158,11,.3); color: #92400e; }

        /* ── Reset ─────────────────────────────────────────── */
        .ck-dashboard * { box-sizing: border-box; }

        /* ── CSS Variables (CompKit brand) ──────────────────── */
        .ck-dashboard {
            --ck-blue:   #0082F9;
            --ck-green:  #40B104;
            --ck-purple: #7C26E3;
            --ck-orange: #FB6400;
            --ck-teal:   #d3c41b;
            --ck-game-green: #97e226;
            --ck-ink:    #0f172a;
            --ck-muted:  #475569;
        }

        /* ── Page container ─────────────────────────────────── */
        .ck-page-container { max-width: 1440px; margin: 0 auto; }

        /* ── Header ─────────────────────────────────────────── */
        .ck-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px 0 18px;
            border-bottom: 1px solid #e5e7eb;
        }
        .ck-header::before { display: none; }
        .ck-header-brand { display: flex; align-items: center; gap: 0; }
        .ck-header-logo-img {
            height: 96px;
            width: auto;
            display: block;
        }
        .ck-header-right { display: flex; align-items: center; gap: 14px; }
        .ck-version-badge {
            background: rgba(0,130,249,.08);
            border: 1px solid rgba(0,130,249,.22);
            color: var(--ck-blue);
            font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 999px;
            letter-spacing: .4px;
        }
        /* Core update. Orange rather than the header's blue, matching the
           premium update button, so "there is something to install" reads the
           same wherever it appears on this page. */
        .ck-core-update-btn {
            border: 0; cursor: pointer;
            background: linear-gradient(180deg, #fb923c, #ea580c);
            color: #fff; font-size: 11px; font-weight: 700;
            padding: 5px 12px; border-radius: 999px;
            letter-spacing: .3px; white-space: nowrap;
            box-shadow: 0 3px 10px rgba(234,88,12,.35);
            transition: filter .2s, transform .2s;
        }
        .ck-check-updates {
            font-size: 11px; font-weight: 600;
            color: #64748b; text-decoration: none;
            border-bottom: 1px dotted #94a3b8;
            transition: color .2s;
        }
        .ck-check-updates:hover { color: var(--ck-blue); border-bottom-color: var(--ck-blue); }
        .ck-core-update-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .ck-core-update-btn:disabled { opacity: .6; cursor: default; box-shadow: none; transform: none; }
        /* The shared progress styles assume the premium bar's wrapping flex row.
           In the header row there is no wrapping, so opt out of the full-width
           basis and give the track a sane width of its own. */
        .ck-core-progress {
            flex-basis: auto; order: 0;
            min-width: 210px; margin-top: 0;
        }
        .ck-core-progress .ck-update-progress-label { font-size: 11.5px; }
        .ck-header-link {
            font-size: 13px; color: var(--ck-blue);
            text-decoration: none; font-weight: 700;
            letter-spacing: .2px;
            transition: color .2s;
        }
        .ck-header-link:hover { color: var(--ck-purple); }

        /* ── Stats bar ──────────────────────────────────────── */
        .ck-stats-bar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 0;
            padding: 14px 0 18px;
            margin-bottom: 28px;
            border-bottom: 1px solid #e5e7eb;
        }
        .ck-stat {
            display: flex; align-items: baseline; gap: 6px;
            padding: 2px 28px 2px 0;
            border-right: 1px solid #e5e7eb;
            margin-right: 28px;
        }
        .ck-stat:last-child { border-right: none; margin-right: 0; padding-right: 0; }
        .ck-stat-num   { font-size: 26px; font-weight: 800; color: var(--ck-blue); line-height: 1; }
        .ck-stat-label { font-size: 13px; color: #6b7280; }
        .ck-stat-checked { font-size: 12px; color: #6b7280; align-items: center; }
        .ck-stat-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #22c55e; display: inline-block; margin-right: 4px;
            box-shadow: 0 0 0 3px rgba(34,197,94,.2);
        }

        /* ── Section header ─────────────────────────────────── */
        .ck-section-header {
            display: flex; align-items: center; gap: 14px;
            margin-bottom: 16px;
        }
        .ck-section-label {
            font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
            text-transform: uppercase; color: #9ca3af; white-space: nowrap;
        }
        .ck-section-line {
            flex: 1; height: 1px; background: #e5e7eb;
        }

        /* ── Free module row (Instant Reveal) ───────────────── */
        .ck-free-card {
            display: flex; align-items: flex-start; gap: 20px;
            padding: 18px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        .ck-free-icon {
            width: 56px; height: 56px; flex-shrink: 0;
            background: #38d4c0;
            -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat;
            -webkit-mask-position: center; mask-position: center;
            -webkit-mask-size: contain; mask-size: contain;
        }
        .ck-free-body { flex: 1; min-width: 0; }
        .ck-free-title { font-size: 16px; font-weight: 700; color: #111827; }
        .ck-free-desc  { font-size: 13px; color: #6b7280; margin-top: 3px; }
        .ck-free-settings {
            margin: 12px 0 2px; padding-left: 18px;
            font-size: 12.5px; color: #4b5563; line-height: 1.6;
        }
        .ck-free-settings li { margin-bottom: 4px; }
        .ck-free-settings li:last-child { margin-bottom: 0; }
        .ck-free-settings strong { color: #374151; }
        .ck-free-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; flex-shrink: 0; padding-top: 2px; }

        /* ── Badges ─────────────────────────────────────────── */
        .ck-badge {
            font-size: 11px; font-weight: 700; padding: 4px 12px;
            border-radius: 999px; letter-spacing: .3px;
        }
        .ck-badge-free {
            background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
        }

        .ck-premium-badge {
            position: absolute; top: 10px; right: 10px;
            font-size: 9px; font-weight: 800; letter-spacing: 1px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e; border: 1px solid #fcd34d;
            padding: 2px 7px; border-radius: 999px;
        }

        /* ── Settings link ──────────────────────────────────── */
        .ck-settings-link {
            font-size: 12px; font-weight: 600; color: var(--ck-blue);
            text-decoration: none; transition: color .15s;
        }
        .ck-settings-link:hover { color: var(--ck-purple); text-decoration: underline; }
        .ck-settings-link-card {
            display: block; margin-top: 10px;
            font-size: 12px; text-align: center;
        }

        /* ── Status dot (legacy – kept for free-card area) ──── */
        .ck-status-dot {
            width: 9px; height: 9px; border-radius: 50%;
        }

        /* ── Stacked game list ──────────────────────────────── */
        .ck-games-list { display: flex; flex-direction: column; gap: 8px; margin-top: 0; }

        /* How premium games work */
        .ck-premium-howto {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 16px 20px;
            margin: 0 0 18px;
        }
        .ck-premium-howto-title {
            font-size: 13px; font-weight: 700; color: #1e3a8a;
            text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between;
            cursor: pointer; user-select: none;
        }
        .ck-howto-caret { transition: transform .2s; font-size: 11px; }
        .ck-premium-howto.is-collapsed .ck-premium-howto-title { margin-bottom: 0; }
        .ck-premium-howto.is-collapsed .ck-premium-howto-body { display: none; }
        .ck-premium-howto.is-collapsed .ck-howto-caret { transform: rotate(-90deg); }
        .ck-premium-howto-steps {
            margin: 0; padding-left: 20px;
            font-size: 13px; line-height: 1.55; color: #1e293b;
        }
        .ck-premium-howto-steps li { margin-bottom: 6px; }
        .ck-premium-howto-steps li:last-child { margin-bottom: 0; }
        .ck-premium-howto-note {
            margin: 12px 0 0; padding-top: 10px;
            border-top: 1px solid #dbeafe;
            font-size: 12px; color: #475569;
        }
        .ck-premium-download-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 12px;
            margin-top: 14px; padding-top: 14px; border-top: 1px solid #dbeafe;
            opacity: 1; max-height: 200px; overflow: hidden;
            transition: opacity .3s ease, max-height .3s ease, margin .3s ease, padding .3s ease, border-color .3s ease;
        }
        .ck-premium-download-bar.ck-fading-out {
            opacity: 0; max-height: 0;
            margin-top: 0; padding-top: 0; border-top-color: transparent;
        }
        .ck-download-premium-btn {
            display: inline-flex; align-items: center; gap: 9px;
            background: linear-gradient(180deg, #1a90ff, #0073e6); color: #fff; border: none;
            height: 44px; padding: 0 24px; border-radius: 10px;
            font-size: 14px; font-weight: 700; cursor: pointer; white-space: nowrap;
            box-shadow: 0 4px 14px rgba(0,130,249,.35);
            transition: transform .12s ease, box-shadow .15s ease, filter .15s ease, background .15s ease;
        }
        .ck-download-premium-btn::before { content: '⬇'; font-size: 16px; line-height: 1; }
        .ck-download-premium-btn[data-mode="install"]::before { content: none; }
        .ck-download-premium-btn[data-mode="update"] {
            background: linear-gradient(180deg, #fb923c, #ea580c);
            box-shadow: 0 4px 14px rgba(234,88,12,.35);
        }
        .ck-download-premium-btn[data-mode="update"]::before { content: '⟳'; }
        .ck-download-premium-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,130,249,.45); filter: brightness(1.05); }
        .ck-download-premium-btn[data-mode="update"]:hover { box-shadow: 0 6px 20px rgba(234,88,12,.45); }
        .ck-download-premium-btn:active { transform: translateY(0); }
        .ck-download-premium-btn:disabled { opacity: .55; cursor: default; box-shadow: none; transform: none; }
        .ck-premium-download-hint { font-size: 12px; color: #475569; line-height: 1.5; flex: 1; min-width: 220px; }
        .ck-premium-version-note {
            flex-basis: 100%; order: 3;
            font-size: 11.5px; font-weight: 700; color: #c2410c;
            background: #fff7ed; border: 1px solid #fed7aa;
            padding: 4px 10px; border-radius: 999px; width: fit-content;
        }

        /* Install progress. order:4 puts it on its own line under the button and
           the version note, so appearing mid-install never reflows them. */
        .ck-update-progress {
            flex-basis: 100%; order: 4;
            display: flex; align-items: center; gap: 10px;
            margin-top: 2px;
        }
        .ck-update-progress[hidden] { display: none; }
        .ck-update-progress-track {
            flex: 1; min-width: 140px; height: 8px;
            background: #e2e8f0; border-radius: 999px;
            overflow: hidden;
        }
        .ck-update-progress-fill {
            width: 0; height: 100%;
            background: linear-gradient(90deg, #fb923c, #ea580c);
            border-radius: 999px;
            transition: width .4s ease;
        }
        /* Stage percentages arrive in jumps, so between them the bar would sit
           perfectly still and read as a hang — the very thing it is here to
           prevent. A sheen travelling across the filled part says "still
           working" without claiming progress that has not happened. */
        .ck-update-progress-fill::after {
            content: ''; display: block; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.45), transparent);
            animation: ck-progress-sheen 1.4s ease-in-out infinite;
        }
        @keyframes ck-progress-sheen {
            from { transform: translateX(-100%); }
            to   { transform: translateX(100%); }
        }
        .ck-update-progress.is-done .ck-update-progress-fill {
            background: linear-gradient(90deg, #22c55e, #16a34a);
        }
        .ck-update-progress.is-done .ck-update-progress-fill::after { animation: none; }
        .ck-update-progress-label {
            font-size: 12px; font-weight: 600; color: #475569;
            white-space: nowrap;
        }
        @media (prefers-reduced-motion: reduce) {
            .ck-update-progress-fill { transition: none; }
            .ck-update-progress-fill::after { animation: none; }
        }

        .ck-game-row {
            background: #fff;
            border: 1.5px solid #e5e7eb;
            border-radius: 16px;
            overflow: hidden;
            transition: border-color .2s, box-shadow .2s;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .ck-game-row:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
        .ck-game-row.ck-row-open {
            border-color: var(--card-color, var(--ck-blue));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--card-color, var(--ck-blue)) 14%, transparent);
        }
        .ck-game-row.is-locked { opacity: .9; }

        /* ── Row header (clickable bar) ─────────────────────── */
        .ck-row-header {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 18px;
            cursor: pointer;
            user-select: none;
        }
        .ck-row-left {
            display: flex; align-items: center; gap: 12px;
            flex: 1; min-width: 0;
        }
        .ck-dot {
            width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0;
        }
        .dot-active { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
        .dot-idle   { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
        .dot-locked { background: #d1d5db; }
        .ck-row-icon {
            width: 68px; height: 68px; flex-shrink: 0;
            -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat;
            -webkit-mask-position: center; mask-position: center;
            -webkit-mask-size: contain; mask-size: contain;
        }
        .ck-row-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .ck-row-name-wrap { display: flex; align-items: center; gap: 7px; }
        .ck-row-name { font-size: 14px; font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ck-premium-tag {
            font-size: 8px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e; border: 1px solid #fcd34d;
            padding: 2px 6px; border-radius: 999px;
            white-space: nowrap; flex-shrink: 0;
        }
        /* Each game carries its own version, independent of the plugin version. */
        .ck-row-version {
            font-size: 9px; font-weight: 700; letter-spacing: .3px;
            background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb;
            padding: 2px 6px; border-radius: 999px;
            white-space: nowrap; flex-shrink: 0; font-variant-numeric: tabular-nums;
        }
        /* Update marker on a game row. Amber rather than the grey of the version
           pill next to it, so "there is something to do" reads at a glance
           without having to compare two version numbers. */
        .ck-row-update {
            font-size: 9px; font-weight: 800; letter-spacing: .3px;
            background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa;
            padding: 2px 6px; border-radius: 999px;
            white-space: nowrap; flex-shrink: 0; font-variant-numeric: tabular-nums;
        }
        .ck-row-update-btn {
            font-size: 10px; font-weight: 700; line-height: 1;
            background: #ea580c; color: #fff; border: 0;
            padding: 4px 9px; border-radius: 999px;
            cursor: pointer; flex-shrink: 0;
            transition: background .15s, opacity .15s;
        }
        .ck-row-update-btn:hover { background: #c2410c; }
        .ck-row-update-btn:disabled { opacity: .55; cursor: default; }
        .ck-row-status {
            font-size: 11px; color: #9ca3af; font-weight: 500;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ck-expiry-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 700; letter-spacing: .2px;
            padding: 2px 8px; border-radius: 999px;
            background: rgba(99,102,241,.07);
            color: #6366f1;
            border: 1px solid rgba(99,102,241,.18);
            width: fit-content;
        }
        .ck-expiry-badge.ck-expiry-warn {
            background: rgba(245,158,11,.08);
            color: #b45309;
            border-color: rgba(245,158,11,.25);
        }
        .ck-row-actions {
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }

        /* ── Toggle pill ─────────────────────────────────────── */
        /* Checkbox is hidden — the label pill IS the control     */
        .giveaways-toggle {
            display: none !important;
        }
        .ck-toggle-pill {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 13px 6px 9px;
            border-radius: 999px; border: 1.5px solid #e5e7eb;
            background: #f9fafb; cursor: pointer; flex-shrink: 0;
            transition: background .18s, border-color .18s, box-shadow .18s;
            user-select: none;
        }
        .ck-toggle-pill:hover { border-color: #d1d5db; background: #f3f4f6; }
        .ck-toggle-locked { cursor: pointer; }
        .ck-toggle-pill.sw-locked:hover {
            background: #fee2e2; border-color: #f87171;
            box-shadow: 0 0 0 3px rgba(239,68,68,.12);
        }

        /* Coloured dot indicator */
        .ck-toggle-dot {
            width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
            background: #d1d5db; transition: background .18s;
        }
        .ck-toggle-text {
            font-size: 12px; font-weight: 700; white-space: nowrap;
            color: #6b7280; transition: color .18s;
        }

        /* ON state */
        .ck-toggle-pill.sw-on {
            background: #f0fdf4; border-color: #86efac;
        }
        .ck-toggle-pill.sw-on .ck-toggle-dot  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
        .ck-toggle-pill.sw-on .ck-toggle-text { color: #16a34a; }

        /* OFF state */
        .ck-toggle-pill.sw-off {
            background: #f9fafb; border-color: #e5e7eb;
        }
        .ck-toggle-pill.sw-off .ck-toggle-dot  { background: #d1d5db; }
        .ck-toggle-pill.sw-off .ck-toggle-text { color: #9ca3af; }

        /* LOCKED state */
        .ck-toggle-pill.sw-locked {
            background: #fef2f2; border-color: #fca5a5;
        }
        .ck-toggle-pill.sw-locked .ck-toggle-dot  { background: #f87171; }
        .ck-toggle-pill.sw-locked .ck-toggle-text { color: #dc2626; }

        /* ── Settings pill ──────────────────────────────────── */
        .ck-settings-pill {
            display: inline-flex; align-items: center;
            padding: 6px 13px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            color: var(--card-color, var(--ck-blue));
            border: 1.5px solid var(--card-color, var(--ck-blue));
            text-decoration: none; white-space: nowrap; flex-shrink: 0;
            transition: background .15s;
        }
        .ck-settings-pill:hover { background: color-mix(in srgb, var(--card-color, var(--ck-blue)) 8%, transparent); }

        /* ── Info / chevron button ──────────────────────────── */
        .ck-chevron-btn {
            display: inline-flex; align-items: center; gap: 5px;
            height: 34px; padding: 0 12px; flex-shrink: 0;
            border-radius: 999px;
            border: 1.5px solid #e5e7eb; background: #f9fafb;
            cursor: pointer;
            color: #6b7280; transition: background .15s, border-color .15s, color .15s;
        }
        .ck-chevron-btn:hover { border-color: #d1d5db; background: #f3f4f6; color: #374151; }
        .ck-game-row.ck-row-open .ck-chevron-btn {
            border-color: var(--card-color, var(--ck-blue));
            background: var(--card-color, var(--ck-blue));
            color: #fff;
        }
        .ck-chevron-text { font-size: 12px; font-weight: 600; }
        .ck-chevron-icon { transition: transform .25s; display: inline-block; line-height: 1; font-size: 11px; }
        .ck-game-row.ck-row-open .ck-chevron-icon { transform: rotate(180deg); }

        /* ── Accordion panel ────────────────────────────────── */
        .ck-row-panel {
            max-height: 0; overflow: hidden;
            transition: max-height .38s cubic-bezier(.4,0,.2,1);
        }
        .ck-game-row.ck-row-open .ck-row-panel { max-height: 1200px; }
        .ck-row-panel-inner {
            display: flex; flex-direction: column;
            border-top: 1px solid #f0f2f5;
        }

        /* ── Licence section (inside panel) ─────────────────── */
        .ck-panel-licence {
            padding: 18px 20px;
            border-bottom: 1px solid #f0f2f5;
            background: #fafbfc;
        }
        .ck-licence-label {
            display: block; font-size: 10px; font-weight: 700;
            letter-spacing: .8px; text-transform: uppercase;
            color: #9ca3af; margin-bottom: 8px;
        }
        .ck-licence-row {
            display: flex; gap: 8px; align-items: center;
        }
        .giveaways-license-input {
            flex: 1; min-width: 0; padding: 9px 11px;
            border-radius: 9px; border: 1.5px solid #e5e7eb;
            font-family: ui-monospace, monospace;
            font-size: 12px; letter-spacing: 1.2px; text-transform: uppercase;
            background: #fff;
            transition: border .2s, box-shadow .2s;
        }
        .giveaways-license-input:focus {
            outline: none; border-color: var(--ck-blue);
            box-shadow: 0 0 0 3px rgba(0,130,249,.12);
        }
        @keyframes ckInputHighlight {
            0%   { box-shadow: 0 0 0 0   rgba(0,130,249,.5);  border-color: var(--ck-blue); }
            60%  { box-shadow: 0 0 0 6px rgba(0,130,249,.15); border-color: var(--ck-blue); }
            100% { box-shadow: 0 0 0 3px rgba(0,130,249,.12); border-color: var(--ck-blue); }
        }
        .ck-licence-input-highlight { animation: ckInputHighlight .7s ease forwards; }
        .giveaways-license-save {
            padding: 9px 14px; border-radius: 9px; border: none;
            background: var(--ck-blue); color: #fff;
            font-size: 12px; font-weight: 700; cursor: pointer;
            white-space: nowrap; flex-shrink: 0;
            box-shadow: 0 3px 0 #005DB5;
            transition: transform .12s, box-shadow .12s;
        }
        .giveaways-license-save:hover { transform: translateY(-1px); box-shadow: 0 5px 0 #005DB5; }
        .giveaways-inline-status {
            display: block; margin-top: 6px;
            font-size: 11px; font-weight: 600; color: #9ca3af;
        }
        .giveaways-inline-status.status-ok  { color: #16a34a; }
        .giveaways-inline-status.status-err { color: #dc2626; }

        /* ── 3-section info layout ──────────────────────────── */
        .ck-info-section {
            display: flex; align-items: stretch;
            border-bottom: 1px solid #f0f2f5;
        }
        .ck-info-section:last-child { border-bottom: none; }

        /* 16:9 placeholder */
        .ck-info-placeholder {
            flex: 0 0 440px;
            aspect-ratio: 16/9;
            align-self: flex-start;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 6px;
            background: color-mix(in srgb, var(--ph-color, #9ca3af) 9%, #f4f5f8);
            border-right: 1px solid #edf0f4;
            text-align: center; box-sizing: border-box;
            font-size: 22px; font-weight: 600;
            color: color-mix(in srgb, var(--ph-color, #9ca3af) 55%, #6b7280);
        }
        .ck-preview-image{
            width:100%;
            height:100%;
            object-fit:contain;
            object-position:center;
            display:block;
            box-sizing:border-box;

            padding:18px;
            border-radius:18px;

            filter:
                drop-shadow(0 8px 24px rgba(0,0,0,.12))
                drop-shadow(0 2px 6px rgba(0,0,0,.08));

            transform:scale(.96);
            transition:
                transform .22s ease,
                filter .22s ease;
        }

        .ck-info-section:hover .ck-preview-image{
            transform:scale(1);
            filter:
                drop-shadow(0 14px 34px rgba(0,0,0,.16))
                drop-shadow(0 4px 10px rgba(0,0,0,.10));
        }
        .ck-ph-icon {
            width: 52px; height: 52px;
            color: color-mix(in srgb, var(--ph-color, #9ca3af) 45%, #c0c8d4);
        }
        .ck-ph-sub { font-size: 20px; font-weight: 400; color: #b0b8c4; }
        /* Portrait (9:16) overlay — right side of each placeholder */
        .ck-portrait-ph {
            position: absolute; right: 0; top: 0; height: 100%;
            aspect-ratio: 9/16;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 6px;
            background: color-mix(in srgb, var(--ph-color, #9ca3af) 14%, #e8eaf0);
            border-left: 1px solid color-mix(in srgb, var(--ph-color, #9ca3af) 20%, #dde0e8);
            transition: opacity .22s ease;
            z-index: 2; pointer-events: none;
        }
        .ck-portrait-icon {
            width: 36px; height: 36px;
            color: color-mix(in srgb, var(--ph-color, #9ca3af) 45%, #c0c8d4);
        }
        .ck-info-placeholder:hover .ck-portrait-ph { opacity: 0; }

        /* Content side */
        .ck-info-content {
            flex: 1; min-width: 0; padding: 11px 16px;
            display: flex; flex-direction: column; gap: 6px;
        }
        .ck-info-title {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--card-color, #9ca3af);
        }
        .ck-info-hint { margin: 0; font-size: 11px; color: #b0b8c4; }
        .ck-panel-section-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .07em; color: #9ca3af; margin-top: 2px;
        }
        .ck-panel-preview-desc { margin: 0; font-size: 12px; color: #374151; line-height: 1.5; }
        .ck-panel-howto { margin: 0; }
        .ck-panel-actions { display: flex; gap: 8px; margin-top: 2px; }
        .ck-preview-settings-link {
            font-size: 12px; font-weight: 600; color: var(--ck-blue); text-decoration: none;
        }
        .ck-preview-settings-link:hover { text-decoration: underline; color: var(--ck-purple); }

        /* Steps list — 2-column numbered */
        .ck-steps-list {
            margin: 0; padding: 0; list-style: none;
            display: grid; grid-template-columns: 1fr 1fr; gap: 5px 16px;
            counter-reset: ck-step;
        }
        .ck-steps-list li {
            display: flex; align-items: flex-start; gap: 7px;
            font-size: 12px; color: #374151; line-height: 1.4;
            counter-increment: ck-step;
        }
        .ck-steps-list li::before {
            content: counter(ck-step);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 17px; height: 17px; border-radius: 50%;
            background: var(--card-color, #9ca3af);
            color: #fff; font-size: 9px; font-weight: 800;
        }

        /* Bullet lists — 2-column */
        .ck-panel-settings-list,
        .ck-panel-product-settings-list {
            margin: 0; padding: 0; list-style: none;
            display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px;
        }
        .ck-panel-settings-list li,
        .ck-panel-product-settings-list li {
            font-size: 12px; color: #374151;
            display: flex; align-items: flex-start; gap: 7px; line-height: 1.4;
        }
        .ck-panel-settings-list li::before,
        .ck-panel-product-settings-list li::before {
            content: ''; flex-shrink: 0;
            width: 5px; height: 5px; border-radius: 50%;
            background: var(--card-color, #9ca3af); margin-top: 4px;
        }

        /* ── Buy section ────────────────────────────────────── */
        .ck-buy-section { margin-bottom: 12px; }
        .ck-buy-label {
            font-size: 10px; font-weight: 700; letter-spacing: .8px;
            text-transform: uppercase; color: #9ca3af; margin-bottom: 8px;
        }
        .ck-buy-buttons { display: flex; gap: 8px; }
        .ck-buy-btn {
            flex: 1; padding: 10px 8px; border-radius: 10px; border: none;
            font-size: 12px; font-weight: 700; cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            display: flex; flex-direction: column; align-items: center; gap: 2px;
        }
        .ck-buy-monthly {
            background: var(--ck-blue);
            color: #fff;
            box-shadow: 0 4px 0 #005DB5, 0 8px 18px rgba(0,130,249,.3);
        }
        .ck-buy-yearly {
            background: var(--ck-green);
            color: #fff;
            box-shadow: 0 4px 0 #2E7E03, 0 8px 18px rgba(64,177,4,.3);
        }
        .ck-buy-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(0,0,0,.2); }
        .ck-buy-btn:disabled { opacity: .6; cursor: wait; transform: none; }
        .ck-buy-price { font-size: 15px; font-weight: 800; }
        .ck-buy-divider {
            text-align: center; font-size: 10px; color: #d1d5db;
            margin: 10px 0 8px; position: relative;
        }
        .ck-buy-divider::before, .ck-buy-divider::after {
            content: ''; position: absolute; top: 50%;
            width: 30%; height: 1px; background: #e5e7eb;
        }
        .ck-buy-divider::before { left: 0; }
        .ck-buy-divider::after  { right: 0; }

        /* ── Checkout loading overlay ────────────────────────── */
        .ck-checkout-loading {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            z-index: 99999; color: #fff; gap: 16px;
            font-size: 16px; font-weight: 600;
        }
        .ck-spinner {
            width: 40px; height: 40px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,.2);
            border-top-color: #fff;
            animation: ckSpin .8s linear infinite;
        }
        @keyframes ckSpin { to { transform: rotate(360deg); } }

        /* ── Bundle deals list ───────────────────────────────── */
        .ck-bundles-grid {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-bottom: 8px;
        }
        .ck-bundle-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 20px 0;
            border-bottom: 1px solid #f0f2f5;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            transition: background .15s;
        }
        .ck-bundle-card:hover {
            background: #f9fafb;
        }
        .ck-bundle-name {
            font-weight: 800; font-size: 15px; color: var(--ck-ink); margin-bottom: 2px;
        }
        .ck-bundle-desc {
            font-size: 12px; color: var(--ck-muted); margin-bottom: 2px;
        }
        .ck-bundle-games {
            font-size: 11px; color: var(--ck-muted); opacity: .8;
        }
        /* Icon strip inside bundle cards */
        .ck-bundle-icons {
            display: flex; align-items: center; justify-content: center;
            gap: 6px; flex-wrap: wrap; margin: 4px 0;
        }
        .ck-bundle-icon {
            width: 52px; height: 52px; border-radius: 14px;
            flex-shrink: 0;
            -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat;
            -webkit-mask-position: center; mask-position: center;
            -webkit-mask-size: 44px 44px; mask-size: 44px 44px;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .ck-bundle-plus {
            font-size: 1.4rem; font-weight: 800; color: #94a3b8; line-height: 1;
        }
        .ck-bundle-btns {
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .ck-bundle-btns .ck-buy-btn {
            flex: 1;
        }

        /* ── Sticky save bar ────────────────────────────────── */
        .ck-save-bar {
            position: sticky; bottom: 0;
            margin: 28px -4px 0;
            padding: 4px;
            z-index: 100;
        }
        .ck-save-inner {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(10px);
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px 20px;
            box-shadow: 0 -4px 20px rgba(0,0,0,.1), 0 2px 8px rgba(0,0,0,.06);
        }
        .ck-save-hint {
            margin-left: auto; font-size: 12px; color: #9ca3af;
        }

        /* ── Shared buttons ─────────────────────────────────── */
        .ck-btn-primary {
            padding: 10px 22px; border-radius: 10px; border: none;
            background: var(--ck-green);
            color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;
            box-shadow: 0 6px 0 #2E7E03, 0 12px 26px rgba(64,177,4,.35);
            transition: transform .15s, box-shadow .15s;
        }
        .ck-btn-primary:hover {
            background: #59d90a;
            transform: translateY(-1px);
            box-shadow: 0 8px 0 #2E7E03, 0 18px 36px rgba(64,177,4,.45);
        }
        .ck-btn-secondary {
            padding: 10px 18px; border-radius: 10px;
            border: 1.5px solid #d1d5db;
            background: #fff; color: #374151;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .ck-btn-secondary:hover { border-color: var(--ck-blue); background: rgba(0,130,249,.06); color: var(--ck-blue); }
        .ck-btn-ghost {
            padding: 10px 18px; border-radius: 10px;
            border: none; background: transparent;
            color: #6b7280; font-size: 13px; cursor: pointer;
        }
        .ck-btn-ghost:hover { color: #111; }

        /* ── Module enable / active prompt ─────────────────── */
        .ck-module-prompt {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 20px;
            background: #f0fdf4; border: 1.5px solid #bbf7d0;
            border-radius: 12px; margin: 0 20px 20px;
        }
        .ck-active-badge-inline {
            font-size: 13px; font-weight: 700; color: #16a34a;
        }
        .ck-settings-cta-inline {
            font-size: 13px; font-weight: 600; color: var(--ck-blue);
            text-decoration: none;
        }
        .ck-settings-cta-inline:hover { text-decoration: underline; }
        .ck-enable-msg, .ck-install-msg {
            margin: 0; font-size: 13px; color: #374151; flex: 1;
        }
        .ck-enable-btn {
            padding: 9px 18px; border-radius: 8px;
            background: var(--card-color); border: none;
            color: #fff; font-size: 13px; font-weight: 700;
            cursor: pointer; white-space: nowrap;
            transition: opacity .15s, transform .1s;
        }
        .ck-enable-btn:hover { opacity: .85; transform: translateY(-1px); }
        .ck-enable-btn:disabled { opacity: .45; cursor: default; transform: none; }
        .ck-uninstall-note { font-size: 12px; color: #9ca3af; }

        /* ── Help section ───────────────────────────────────── */
        .ck-help-grid {
            display: flex; gap: 4px;
            margin-bottom: 40px;
        }
        .ck-help-card {
            flex: 1;
            display: flex; align-items: center; gap: 14px;
            padding: 12px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s;
        }
        .ck-help-card:hover { background: #f3f4f6; }
        .ck-help-icon {
            width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
            -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat;
            -webkit-mask-position: center; mask-position: center;
            -webkit-mask-size: 55%; mask-size: 55%;
        }
        .ck-help-icon.support {
            background: #22c55e;
            -webkit-mask-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='white' d='M12 2a10 10 0 0 0-10 10v3a3 3 0 0 0 3 3h1v-8H5a7 7 0 0 1 14 0h-1v8h1a3 3 0 0 0 3-3v-3A10 10 0 0 0 12 2z'/></svg>");
            mask-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='white' d='M12 2a10 10 0 0 0-10 10v3a3 3 0 0 0 3 3h1v-8H5a7 7 0 0 1 14 0h-1v8h1a3 3 0 0 0 3-3v-3A10 10 0 0 0 12 2z'/></svg>");
        }
        .ck-help-icon.feature {
            background: var(--ck-purple);
            -webkit-mask-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='white' d='M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z'/></svg>");
            mask-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='white' d='M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z'/></svg>");
        }
        .ck-help-body  { flex: 1; }
        .ck-help-title { font-size: 15px; font-weight: 700; color: #111827; }
        .ck-help-desc  { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .ck-help-arrow { font-size: 18px; color: #d1d5db; transition: color .2s; }
        .ck-help-card:hover .ck-help-arrow { color: var(--ck-blue); }

        /* ── Modal ──────────────────────────────────────────── */
        #giveaways-modal {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.55); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            z-index: 99999; animation: ckFadeIn .2s ease;
        }
        @keyframes ckFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .giveaways-modal-box {
            background: #fff; border-radius: 18px;
            max-width: 500px; width: 94%;
            box-shadow: 0 24px 60px rgba(0,0,0,.25);
            overflow: hidden;
            animation: ckSlideUp .25s cubic-bezier(.4,0,.2,1);
        }
        @keyframes ckSlideUp {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .ck-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 24px 0;
        }
        .ck-modal-header h2 { margin: 0; font-size: 18px; }
        .ck-modal-x {
            background: none; border: none; font-size: 18px;
            color: #9ca3af; cursor: pointer; padding: 4px 8px;
            border-radius: 6px; transition: background .15s, color .15s;
        }
        .ck-modal-x:hover { background: #f3f4f6; color: #111; }
        #giveaways-message {
            display: block; width: 100%; margin-top: 16px; padding: 0 24px;
            box-sizing: border-box; resize: vertical;
            border: none; border-top: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0;
            padding: 16px 24px; font-size: 14px; outline: none;
            min-height: 120px; color: #374151;
        }
        #giveaways-modal-status {
            padding: 8px 24px; font-size: 13px; font-weight: 600; color: #16a34a;
        }
        .ck-modal-footer {
            display: flex; align-items: center; gap: 10px;
            padding: 16px 24px;
        }

        /* ══════════════════════════════════════════════════════════
           DASHBOARD v2 - laid over the rules above, so every class the
           script relies on keeps working. Header pills, a spotlight +
           what's-new row, games as a grid of cards, and each card's panel
           shown as a floating drawer instead of an expanding row.
           ══════════════════════════════════════════════════════════ */
        .ck-dashboard { --ck-card-radius: 16px; --ck-line: #e8eaee; }

        /* Header */
        .ck-header { align-items: center; gap: 18px; padding: 6px 0 18px; border-bottom: 0; }
        .ck-header-brand { display: flex; align-items: center; gap: 16px; }
        .ck-header-logo-img { height: 52px; width: auto; }
        .ck-header-title h1 { margin: 0; padding: 0; font-size: 20px; line-height: 1.2; font-weight: 800; color: #111827; }
        .ck-header-title span { font-size: 13px; color: #6b7280; }
        .ck-header-right { gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .ck-head-pill { display: inline-flex; align-items: center; gap: 7px; height: 32px; padding: 0 13px; border-radius: 999px;
            background: #fff; border: 1px solid var(--ck-line); font-size: 12px; font-weight: 700; color: #374151; }
        .ck-head-dot { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; }

        /* Missing plugins: one tidy line, not a big box */
        .ck-req-banner { padding: 12px 16px; border-radius: 12px; border-width: 1px; border-left-width: 4px; margin: 0 0 18px; align-items: center; }
        .ck-req-banner .ck-req-desc { display: none; }
        .ck-req-banner .ck-req-list { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 4px 0 0; }
        .ck-req-banner .ck-req-item.ck-req-ok { display: none; }

        /* Spotlight + what's new */
        .ck-hero { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 18px; margin: 4px 0 30px; }
        .ck-hero.ck-hero-solo { grid-template-columns: 1fr; }
        .ck-spot { position: relative; overflow: hidden; min-height: 300px; border-radius: 18px; color: #fff;
            display: flex; flex-direction: column; justify-content: flex-end;
            background:
                radial-gradient(120% 90% at 85% 10%, color-mix(in srgb, var(--card-color) 65%, #fff) 0%, transparent 55%),
                linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 45%, #0b0b10));
            box-shadow: 0 10px 30px color-mix(in srgb, var(--card-color) 30%, transparent); }
        .ck-spot.has-img { background: linear-gradient(180deg, rgba(10,6,12,0) 25%, rgba(10,6,12,.9) 82%), var(--spot-img) center 40% / cover; }
        .ck-spot-art { position: absolute; right: -30px; top: -20px; width: 300px; height: 300px; background: rgba(255,255,255,.14);
            -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; transform: rotate(-12deg); }
        .ck-spot-tags { position: absolute; top: 18px; left: 20px; display: flex; gap: 8px; }
        .ck-spot-chip { height: 24px; padding: 0 10px; border-radius: 999px; display: inline-flex; align-items: center;
            font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; background: rgba(255,255,255,.18); color: #fff; }
        .ck-spot-chip.ck-spot-new { background: #fff; color: var(--card-color); }
        .ck-spot-body { position: relative; padding: 24px 26px; }
        .ck-spot-name { display: flex; align-items: center; gap: 12px; font-size: 30px; font-weight: 900; letter-spacing: -.02em; line-height: 1.1; }
        .ck-spot-icon { width: 42px; height: 42px; background: #fff; -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; }
        .ck-spot p { margin: 8px 0 18px; max-width: 560px; color: rgba(255,255,255,.9); font-size: 14px; line-height: 1.5; }
        .ck-spot-btns { display: flex; gap: 10px; flex-wrap: wrap; }
        .ck-spot-btn { height: 40px; padding: 0 18px; border-radius: 10px; border: 0; cursor: pointer; font: 700 13px/40px inherit; font-family: inherit;
            background: rgba(255,255,255,.16); color: #fff; text-decoration: none; display: inline-flex; align-items: center; }
        .ck-spot-btn:hover { background: rgba(255,255,255,.26); color: #fff; }
        .ck-spot-btn.primary { background: #fff; color: #111827; }
        .ck-spot-btn.primary:hover { background: #f3f4f6; color: #111827; }

        .ck-news { background: #fff; border: 1px solid var(--ck-line); border-radius: 18px; padding: 20px 22px 16px; display: flex; flex-direction: column;
            box-shadow: 0 1px 2px rgba(16,24,40,.05), 0 8px 24px rgba(16,24,40,.06); }
        .ck-news-title { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #6b7280; font-weight: 800; margin-bottom: 12px; }
        .ck-news-rel { display: flex; gap: 14px; padding: 12px 0; border-top: 1px solid var(--ck-line); }
        .ck-news-rel:first-of-type { border-top: 0; padding-top: 0; }
        .ck-news-ver { flex-shrink: 0; width: 58px; font-weight: 800; font-size: 13px; color: #111827; }
        .ck-news-rel ul { margin: 0; padding: 0; list-style: none; }
        .ck-news-rel li { display: flex; gap: 9px; align-items: flex-start; margin: 0 0 8px; font-size: 13px; line-height: 1.4; color: #374151; }
        .ck-news-rel li b { color: #111827; }
        .ck-news-mods { display: flex; flex-shrink: 0; margin-top: -1px; }
        .ck-news-mod { width: 22px; height: 22px; border-radius: 7px; display: grid; place-items: center;
            background: color-mix(in srgb, var(--c, #475569) 14%, #fff); box-shadow: 0 0 0 2px #fff; }
        .ck-news-mod + .ck-news-mod { margin-left: -7px; }
        .ck-news-mod i { width: 14px; height: 14px; background: var(--c, #475569);
            -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; }
        .ck-news-gen i { -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2l2.4 6.9H22l-6 4.6 2.3 7L12 16.2 5.7 20.5 8 13.5 2 8.9h7.6z'/%3E%3C/svg%3E");
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2l2.4 6.9H22l-6 4.6 2.3 7L12 16.2 5.7 20.5 8 13.5 2 8.9h7.6z'/%3E%3C/svg%3E"); }
        .ck-news-gen.is-email i { -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 2.2V17h16V7.2l-8 5.6-8-5.6zM5.6 7l6.4 4.5L18.4 7H5.6z'/%3E%3C/svg%3E");
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 2.2V17h16V7.2l-8 5.6-8-5.6zM5.6 7l6.4 4.5L18.4 7H5.6z'/%3E%3C/svg%3E"); }
        .ck-news-more { margin-top: auto; padding-top: 6px; font-weight: 700; font-size: 13px; color: #2563eb; text-decoration: none; }

        /* Section head + filters */
        .ck-games-head { margin-top: 0 !important; }
        .ck-filters { display: inline-flex; gap: 6px; margin-left: 12px; }
        .ck-filters button { height: 30px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--ck-line); background: #fff;
            font: 700 12px/28px inherit; font-family: inherit; color: #4b5563; cursor: pointer; }
        .ck-filters button.is-on { background: #111827; border-color: #111827; color: #fff; }

        /* Install / update strip, above the grid */
        .ck-premium-download-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin: 0 0 14px; padding: 12px 16px;
            background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; }
        .ck-premium-download-bar .ck-download-premium-btn { order: 3; margin-left: auto; }

        /* Games: a grid of cards */
        .ck-games-list { display: grid !important; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 14px; flex-direction: initial; }
        .ck-game-row, .ck-free-tile { position: relative; overflow: visible; opacity: 1 !important; transform: none; filter: none;
            background: #fff; border: 1px solid var(--ck-line); border-radius: var(--ck-card-radius);
            box-shadow: 0 1px 2px rgba(16,24,40,.05), 0 8px 24px rgba(16,24,40,.06); }
        .ck-game-row:hover, .ck-free-tile:hover { box-shadow: 0 2px 4px rgba(16,24,40,.06), 0 14px 30px rgba(16,24,40,.1); }
        .ck-game-row.ck-row-open { border-color: var(--card-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--card-color) 18%, transparent); }
        .ck-game-row.is-new::after { content: 'NEW'; position: absolute; top: -9px; right: 14px; padding: 3px 8px; border-radius: 999px;
            background: var(--card-color); color: #fff; font-size: 10px; font-weight: 900; letter-spacing: .08em; }
        .ck-game-row.is-hidden, .ck-free-tile.is-hidden { display: none; }
        .ck-row-header { flex-direction: column; align-items: stretch; gap: 14px; padding: 16px; height: 100%; }
        .ck-row-left { align-items: flex-start; gap: 12px; min-width: 0; }
        .ck-row-left .ck-dot, .ck-premium-tag { display: none; }
        .ck-row-icon { width: 52px; height: 52px; flex-shrink: 0; border-radius: 14px;
            -webkit-mask-size: 62%; mask-size: 62%; -webkit-mask-position: center; mask-position: center; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat;
            box-shadow: inset 0 0 0 100px transparent; }
        /* The icon sits on a soft tile in the game's colour: the tile is a pseudo layer behind the masked glyph. */
        .ck-row-left { position: relative; }
        .ck-row-left::before { content: ''; position: absolute; left: 0; top: 0; width: 52px; height: 52px; border-radius: 14px;
            background: color-mix(in srgb, var(--card-color, #38d4c0) 13%, #fff); }
        .ck-row-icon { position: relative; }
        .ck-row-meta { min-width: 0; }
        .ck-row-name { font-size: 15px; font-weight: 800; }
        .ck-row-status { font-size: 12px; color: #6b7280; }
        .ck-row-actions { margin-top: auto; flex-wrap: wrap; justify-content: flex-start; gap: 8px; }
        .ck-chevron-icon { display: none; }
        .ck-free-chip { margin-left: auto; height: 22px; padding: 0 9px; border-radius: 999px; background: #ccfbf1; color: #0f766e;
            font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; flex-shrink: 0; }
        .ck-free-tile .ck-row-header { cursor: default; }

        /* The panel as a floating drawer under the wp-admin bar */
        .ck-row-panel { position: fixed; z-index: 9991; top: calc(var(--wp-admin--admin-bar--height, 32px) + 12px); right: 12px; bottom: 12px;
            width: 460px; max-width: calc(100vw - 24px); max-height: none !important; overflow: hidden;
            display: flex; flex-direction: column; background: #fff; border-radius: 18px;
            box-shadow: 0 24px 60px rgba(17,24,39,.28);
            transform: translateX(calc(100% + 30px)); visibility: hidden;
            transition: transform .28s cubic-bezier(.2,.8,.2,1), visibility 0s linear .28s; }
        .ck-game-row.ck-row-open .ck-row-panel { transform: none; visibility: visible; transition: transform .28s cubic-bezier(.2,.8,.2,1), visibility 0s; }
        .ck-drawer-head { flex-shrink: 0; display: flex; align-items: center; gap: 14px; padding: 18px 18px 16px 20px; color: #fff;
            background: linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 68%, #000)); }
        .ck-drawer-tile { width: 48px; height: 48px; border-radius: 14px; background: rgba(255,255,255,.18); display: grid; place-items: center; flex-shrink: 0; }
        .ck-drawer-tile i { width: 30px; height: 30px; background: #fff; -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center; }
        .ck-drawer-title { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
        .ck-drawer-chip { height: 20px; padding: 0 9px; border-radius: 999px; background: rgba(255,255,255,.22); font-size: 10px; font-weight: 800;
            letter-spacing: .06em; text-transform: uppercase; display: inline-flex; align-items: center; }
        .ck-drawer-name { font-size: 19px; font-weight: 800; line-height: 1.2; }
        .ck-drawer-close { margin-left: auto; width: 34px; height: 34px; border: 0; border-radius: 10px; background: rgba(255,255,255,.2);
            color: #fff; font-size: 20px; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .ck-drawer-close:hover { background: rgba(255,255,255,.32); }
        .ck-row-panel-inner { flex: 1; overflow-y: auto; border-top: 0; }
        /* Inside the drawer each info section stacks: screenshot on top, text under it. */
        .ck-row-panel-inner .ck-info-section { flex-direction: column; }
        .ck-row-panel-inner .ck-info-placeholder { flex: none; width: 100%; border-right: 0; border-bottom: 1px solid #edf0f4; font-size: 16px; }
        .ck-row-panel-inner .ck-ph-sub { font-size: 13px; }
        .ck-row-panel-inner .ck-ph-icon { width: 40px; height: 40px; }
        .ck-row-panel-inner .ck-portrait-icon { width: 26px; height: 26px; }
        .ck-row-panel-inner .ck-info-content { padding: 16px 20px 18px; }
        /* One column in the drawer; a step is plain text with its number pinned
           left (as a flex row, bold words inside a step became their own columns). */
        .ck-row-panel-inner .ck-steps-list,
        .ck-row-panel-inner .ck-panel-settings-list,
        .ck-row-panel-inner .ck-panel-product-settings-list { grid-template-columns: 1fr; gap: 6px; }
        .ck-row-panel-inner .ck-steps-list li { display: block; position: relative; padding-left: 26px; font-size: 13px; }
        .ck-row-panel-inner .ck-steps-list li::before { position: absolute; left: 0; top: 1px; width: 18px; height: 18px; }
        .ck-drawer-scrim { position: fixed; z-index: 9990; inset: var(--wp-admin--admin-bar--height, 32px) 0 0 0;
            background: rgba(17,24,39,.35); opacity: 0; pointer-events: none; transition: opacity .2s; }
        body.ck-drawer-open .ck-drawer-scrim { opacity: 1; pointer-events: auto; }

        /* Help: How-it-works sits with Support and Feature Request */
        .ck-premium-howto { margin-top: 14px; }

        @media (max-width: 1100px) { .ck-hero { grid-template-columns: 1fr; } }
        @media (max-width: 782px) { .ck-row-panel { top: calc(46px + 8px); right: 8px; left: 8px; bottom: 8px; width: auto; } }
    </style>
    <?php
});


/* ===============================
   ADMIN JS
================================ */
add_action('admin_footer', function () {

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->id !== 'toplevel_page_giveaways-addons') return;
    ?>
    <script>
        const giveawaysNonce = <?php echo wp_json_encode(wp_create_nonce('giveaways_send_message')); ?>;
        const giveawaysBuyNonce = <?php echo wp_json_encode(wp_create_nonce('giveaways_buy_license')); ?>;
        const ckUploadPluginUrl = <?php echo wp_json_encode(admin_url('plugin-install.php?tab=upload')); ?>;
        const licenceServer = <?php echo wp_json_encode(GIVEAWAYS_LICENCE_SERVER); ?>;
        const productIcons = <?php echo wp_json_encode([
            'spinbolt'       => ['icon' => plugins_url('icons/spin.svg',          __FILE__), 'color' => '#0082F9'],
            'slots'          => ['icon' => plugins_url('icons/slots.svg',         __FILE__), 'color' => '#7C26E3'],
            'scratchcardgame'=> ['icon' => plugins_url('icons/scratchcard.svg',   __FILE__), 'color' => '#FB6400'],
            'footballgame'   => ['icon' => plugins_url('icons/football.svg',      __FILE__), 'color' => '#331cc6'],
            'racecar'        => ['icon' => plugins_url('icons/racecar.svg',       __FILE__), 'color' => '#c61c2a'],
            'grabclaw'       => ['icon' => plugins_url('icons/grabclaw.svg',      __FILE__), 'color' => '#c000c4'],
            'instant-reveal' => ['icon' => plugins_url('icons/instant-reveal.svg', __FILE__), 'color' => '#97e226'],
            'balloon-pop'    => ['icon' => plugins_url('icons/balloon-pop.svg',   __FILE__), 'color' => '#6c5ce7'],
            'dartsgame'      => ['icon' => plugins_url('icons/darts.svg',         __FILE__), 'color' => '#e17055'],
            'boxing'         => ['icon' => plugins_url('icons/boxing.svg',        __FILE__), 'color' => '#e11d48'],
        ]); ?>;
        (function(){
            const rows        = document.querySelectorAll('.ck-game-row');
            const previewData = typeof ckPreviewData !== 'undefined' ? ckPreviewData : {};
            const moduleMeta  = typeof ckModuleMeta  !== 'undefined' ? ckModuleMeta  : {};

            function ajaxJson(r) {
                return r.text().then(raw => JSON.parse(raw.replace(/^﻿+/, '')));
            }

            function refreshStatus(row){
                const checkbox = row.querySelector('.giveaways-toggle');
                const pill     = row.querySelector('.ck-toggle-pill');
                const textEl   = row.querySelector('.ck-toggle-text');
                const statusEl = row.querySelector('.ck-row-status');
                const licensed = row.dataset.licensed === '1';
                const isOn     = checkbox && checkbox.checked;

                if(pill) pill.classList.remove('sw-on','sw-off','sw-locked');

                if(!licensed){
                    pill?.classList.add('sw-locked');
                    if(textEl)   textEl.textContent   = 'Add Licence';
                    if(statusEl) statusEl.textContent = 'Enter your licence key to unlock';
                } else if(isOn){
                    pill?.classList.add('sw-on');
                    if(textEl)   textEl.textContent   = 'Enabled';
                    if(statusEl) statusEl.textContent = 'Enabled';
                } else {
                    pill?.classList.add('sw-off');
                    if(textEl)   textEl.textContent   = 'Disabled';
                    if(statusEl) statusEl.textContent = 'Disabled';
                }
            }

            /* ── Update a row's UI to the "enabled" state ───────────────── */
            function updateRowToEnabled(row) {
                row.dataset.enabled = '1';

                const toggle = row.querySelector('.giveaways-toggle');
                if (toggle) { toggle.checked = true; toggle.disabled = false; }
                refreshStatus(row);

                // Add Settings pill to header if not already there
                const settingsUrl  = row.dataset.settingsUrl;
                const rowActions   = row.querySelector('.ck-row-actions');
                if (rowActions && settingsUrl && !rowActions.querySelector('.ck-settings-pill')) {
                    const pill = document.createElement('a');
                    pill.href      = settingsUrl;
                    pill.className = 'ck-settings-pill';
                    pill.addEventListener('click', e => e.stopPropagation());
                    pill.textContent = 'Settings →';
                    const chevron = rowActions.querySelector('.ck-chevron-btn');
                    chevron ? rowActions.insertBefore(pill, chevron) : rowActions.appendChild(pill);
                }

                // Swap enable prompt → active prompt
                const prompt = row.querySelector('[data-module-prompt]');
                if (prompt && settingsUrl) {
                    prompt.innerHTML =
                        '<span class="ck-active-badge-inline">✓ Module Active</span>' +
                        '<a href="' + settingsUrl + '" class="ck-settings-cta-inline">Configure Settings →</a>';
                }

                maybeHideDownloadBar();
            }

            /* ── Fade out the download bar once nothing is left to do ───────
               Every licensed row must be enabled, and there must be no pending
               version update (that note only renders when an update is due). */
            function maybeHideDownloadBar() {
                const bar = document.getElementById('ck-premium-download-bar');
                if (!bar || bar.style.display === 'none' || bar.classList.contains('ck-fading-out')) return;
                if (document.getElementById('ck-premium-version-note')) return;

                const allActive = Array.from(rows).every(r =>
                    r.dataset.licensed !== '1' || r.dataset.enabled === '1'
                );
                if (!allActive) return;

                bar.classList.add('ck-fading-out');
                setTimeout(() => { bar.style.display = 'none'; }, 320);

                const howto = document.getElementById('ck-premium-howto');
                if (howto) howto.classList.add('is-collapsed');
            }

            /* ── AJAX-enable a module then update the UI ─────────────────── */
            function enableModule(row, moduleKey) {
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'giveaways_enable_module',
                        _nonce: giveawaysBuyNonce,
                        module: moduleKey
                    })
                })
                .then(ajaxJson)
                .then(r => {
                    if (r.success) {
                        updateRowToEnabled(row);
                    } else {
                        const btn = row.querySelector('.ck-enable-btn');
                        if (btn) { btn.textContent = 'Enable failed — try again'; btn.disabled = false; }
                    }
                })
                .catch(() => {
                    const btn = row.querySelector('.ck-enable-btn');
                    if (btn) { btn.textContent = 'Connection error — try again'; btn.disabled = false; }
                });
            }

            /* ── Download the premium modules package from the licence server ───
               Premium game modules ship as a separate "CompKit Game Engine Premium"
               plugin. We ask the licence server for a download containing exactly
               the modules this site is entitled to, then hand the customer the zip
               to install via Plugins → Add New → Upload. No code is unzipped into
               this plugin. */
            /* One-click install/update, replacing the download-and-re-upload dance.
               Neither plugin is on WordPress.org, so WordPress's own upgrader can
               do this directly. The package still comes from the signed manifest
               and is still checksum-verified before it is unpacked.

               Every one of these buttons runs the same action — the eight games
               live inside one plugin, so updating "darts" and updating everything
               are the same operation. The per-row badges say which games it
               brings forward. */
            /* Progress bar.

               The install is one long blocking request, so the browser learns
               nothing until it finishes. The server records its stage in a
               transient as it goes and we poll for it here.

               The bar only ever moves forwards. A poll that arrives late, or
               after the shutdown hook has cleared the transient, must not drag
               a nearly-finished bar backwards. */
            const ckProgress = {
                el:    null,
                fill:  null,
                label: null,
                timer: null,
                pct:   0,

                /* ids lets the same object drive either bar: the one inside the
                   premium download panel, or the one in the page header used by
                   the core update. Defaults to the premium bar. */
                start(firstMessage, ids) {
                    ids = ids || {
                        wrap:  'ck-update-progress',
                        fill:  'ck-update-progress-fill',
                        label: 'ck-update-progress-label'
                    };
                    this.el    = document.getElementById(ids.wrap);
                    this.fill  = document.getElementById(ids.fill);
                    this.label = document.getElementById(ids.label);
                    if (!this.el || !this.fill || !this.label) { this.el = null; return; }

                    this.pct = 0;
                    this.el.hidden = false;
                    this.el.classList.remove('is-done');
                    this.set(3, firstMessage || 'Starting…');

                    this.timer = setInterval(() => this.poll(), 900);
                },

                set(pct, message) {
                    if (!this.el) return;
                    if (typeof pct === 'number' && pct > this.pct) {
                        this.pct = pct;
                        this.fill.style.width = pct + '%';
                        const track = this.el.querySelector('.ck-update-progress-track');
                        if (track) { track.setAttribute('aria-valuenow', String(pct)); }
                    }
                    if (message) { this.label.textContent = message; }
                    if (this.pct >= 100) { this.el.classList.add('is-done'); }
                },

                poll() {
                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'giveaways_update_progress',
                            _nonce: giveawaysBuyNonce
                        })
                    })
                    .then(r => r.json())
                    .then(r => {
                        if (r && r.success && r.data) {
                            this.set(r.data.percent, r.data.message);
                        }
                    })
                    // A failed poll is not a failed install. Stay quiet and let
                    // the install request itself report the outcome.
                    .catch(() => {});
                },

                finish(message) {
                    this.stop();
                    this.set(100, message || 'Done.');
                },

                stop() {
                    if (this.timer) { clearInterval(this.timer); this.timer = null; }
                },

                hide() {
                    this.stop();
                    if (this.el) { this.el.hidden = true; }
                }
            };

            function installOrUpdateGames(trigger) {
                const top  = document.getElementById('ck-download-premium');
                const hint = document.querySelector('.ck-premium-download-hint');
                const btns = document.querySelectorAll('.ck-update-games');
                const label = trigger && trigger.dataset.game ? trigger.dataset.game : null;

                btns.forEach(b => { b.disabled = true; });
                if (top) { top.disabled = true; top.textContent = 'Installing…'; }
                if (hint) { hint.textContent = label
                    ? 'Updating your games (' + label + ' included)…'
                    : 'Installing your games…'; }

                ckProgress.start(label ? 'Preparing to update ' + label + '…' : 'Preparing…');

                fetch(ajaxurl, {
                    method:  'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'giveaways_update_premium',
                        _nonce: giveawaysBuyNonce
                    })
                })
                .then(r => r.text())
                .then(raw => {
                    let r;
                    try { r = JSON.parse(raw.replace(/^﻿+/, '')); } catch (e) {
                        ckProgress.hide();
                        btns.forEach(b => { b.disabled = false; });
                        if (top) { top.disabled = false; top.textContent = 'Connection error — try again'; }
                        return;
                    }
                    if (r.success) {
                        // Set 100% here rather than waiting for a poll to see it:
                        // the shutdown hook clears the transient as this response
                        // is sent, so the bar would otherwise stop short of full.
                        ckProgress.finish('Done.');
                        if (hint) { hint.innerHTML = '<strong>Done.</strong> Reloading…'; }
                        // Reload so the version badges and module list reflect what
                        // is now actually on disk, rather than what was there before.
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        const msg = (r.data && typeof r.data === 'string') ? r.data : 'Install failed';
                        ckProgress.hide();
                        btns.forEach(b => { b.disabled = false; });
                        if (top) { top.disabled = false; top.textContent = 'Try again'; }
                        // Installing in place needs a directly writable plugins
                        // folder. Plenty of hosts ask for FTP credentials instead,
                        // and there the upgrader simply cannot work — so leave the
                        // old download route reachable rather than stranding them.
                        if (hint) {
                            hint.innerHTML = '';
                            hint.appendChild(document.createTextNode(msg + ' '));
                            const a = document.createElement('a');
                            a.href = '#';
                            a.textContent = 'Download the zip instead';
                            a.addEventListener('click', function (ev) {
                                ev.preventDefault();
                                if (top) { top.dataset.mode = 'zip'; }
                                downloadPremiumPackage();
                            });
                            hint.appendChild(a);
                        }
                    }
                })
                .catch(() => {
                    ckProgress.hide();
                    btns.forEach(b => { b.disabled = false; });
                    if (top) { top.disabled = false; top.textContent = 'Connection error — try again'; }
                });
            }

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.ck-update-games');
                if (!btn || btn.disabled) return;
                e.preventDefault();
                e.stopPropagation();
                installOrUpdateGames(btn);
            });

            /* ── Update the free core plugin ────────────────────────────────
               Separate from the games update: this replaces the plugin that is
               currently running, so the page must reload onto the new code
               afterwards rather than carrying on with the old. */
            function updateCore(btn) {
                const target = btn.dataset.version ? 'v' + btn.dataset.version : 'the latest version';

                btn.disabled = true;
                btn.textContent = 'Updating…';

                ckProgress.start('Starting…', {
                    wrap:  'ck-core-progress',
                    fill:  'ck-core-progress-fill',
                    label: 'ck-core-progress-label'
                });

                fetch(ajaxurl, {
                    method:  'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'giveaways_update_core',
                        _nonce: giveawaysBuyNonce
                    })
                })
                .then(r => r.text())
                .then(raw => {
                    let r;
                    try { r = JSON.parse(raw.replace(/^﻿+/, '')); } catch (e) {
                        ckProgress.hide();
                        btn.disabled = false;
                        btn.textContent = 'Connection error — try again';
                        return;
                    }
                    if (r.success) {
                        ckProgress.finish('Updated to ' + target + '.');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        ckProgress.hide();
                        btn.disabled = false;
                        btn.textContent = 'Update failed — try again';
                        const msg = (r.data && typeof r.data === 'string') ? r.data : '';
                        if (msg) { btn.title = msg; window.console && console.warn('CompKit core update:', msg); }
                    }
                })
                .catch(() => {
                    ckProgress.hide();
                    btn.disabled = false;
                    btn.textContent = 'Connection error — try again';
                });
            }

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.ck-core-update-btn');
                if (!btn || btn.disabled) return;
                e.preventDefault();
                e.stopPropagation();
                updateCore(btn);
            });

            /* Legacy path: still used where a customer would rather have the zip. */
            function downloadPremiumPackage() {
                const btn  = document.getElementById('ck-download-premium');
                const hint = document.querySelector('.ck-premium-download-hint');
                if (btn) { btn.disabled = true; btn.textContent = 'Preparing download…'; }
                fetch(ajaxurl, {
                    method:  'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'giveaways_module_download_url',
                        _nonce: giveawaysBuyNonce
                    })
                })
                .then(r => r.text())
                .then(raw => {
                    let r;
                    try { r = JSON.parse(raw.replace(/^﻿+/, '')); } catch(e) {
                        if (btn) { btn.disabled = false; btn.textContent = 'Connection error — try again'; }
                        return;
                    }
                    if (r.success && r.data && r.data.url) {
                        // Trigger the download as an attachment without navigating away,
                        // so the admin page stays exactly where it is.
                        const a = document.createElement('a');
                        a.href = r.data.url;
                        a.download = 'compkit-games-premium.zip';
                        a.rel = 'noopener';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        // Keep the button as a manual fallback…
                        if (btn) {
                            btn.disabled = false;
                            btn.dataset.mode = 'install';
                            btn.textContent = 'Install Premium Games →';
                        }
                        if (hint) {
                            hint.innerHTML = '<strong>Downloading…</strong> taking you to the plugin upload screen — ' +
                                'choose the <strong>compkit-games-premium.zip</strong> that just downloaded and Activate. ' +
                                'If it says already installed, choose <strong>“Replace current with uploaded.”</strong>';
                        }
                        // …but auto-continue to the Upload Plugin screen once the
                        // download has had a moment to start.
                        setTimeout(function () { window.location.href = ckUploadPluginUrl; }, 1800);
                    } else {
                        const msg = (r.data && typeof r.data === 'string') ? r.data : 'Download failed';
                        if (btn) { btn.disabled = false; btn.textContent = msg + ' — try again'; }
                    }
                })
                .catch(() => {
                    if (btn) { btn.disabled = false; btn.textContent = 'Connection error — try again'; }
                });
            }

            /* Reveal the single Download button once any licence becomes active
               (used after a Stripe purchase is collected, without a page reload). */
            function revealDownloadButton() {
                // A new purchase means there's something to install — un-fade the bar
                // in case everything was previously fully active (or never shown yet).
                const bar = document.getElementById('ck-premium-download-bar');
                if (bar) { bar.style.display = ''; bar.classList.remove('ck-fading-out'); }

                const dlBtn = document.getElementById('ck-download-premium');
                if (dlBtn) {
                    // Reset to plain "download" mode for the new package. If an update
                    // was also pending, that resurfaces correctly on the next full page
                    // load (the version check re-runs from PHP) — download still works
                    // either way in the meantime.
                    dlBtn.dataset.mode = 'download';
                    dlBtn.textContent = 'Download CompKit Games';
                }
                const dlHint = document.getElementById('ck-download-hint');
                if (dlHint) dlHint.innerHTML = 'Contains every game your licences cover. Then install via <strong>Plugins → Add New → Upload Plugin</strong>.';
                // Expand the how-to box back open.
                const box = document.getElementById('ck-premium-howto');
                if (box) box.classList.remove('is-collapsed');
            }

            /* ── Panel message shown once a licence activates (no per-row button;
                  the single Download button at the top handles everything). ── */
            function showInstallPrompt(row, moduleKey, moduleLabel) {
                const prompt = row.querySelector('[data-module-prompt]');
                if (!prompt) return;
                prompt.innerHTML =
                    '<p class="ck-install-msg">✓ Licence activated! This game is included in your download — ' +
                    'use <strong>Download CompKit Games</strong> at the top of Premium Games, then install via ' +
                    'Plugins → Add New → Upload.</p>';
            }

            function populatePanel(row){
                const key  = row.dataset.key;
                const data = previewData[key];
                const meta = moduleMeta[key];
                if(!data) return;

                const descEl  = row.querySelector('.ck-panel-preview-desc');
                const howtoEl = row.querySelector('.ck-panel-howto');
                if(descEl) descEl.textContent = data.desc || '';
                if(howtoEl){
                    const steps = data.how_to_use;
                    if(Array.isArray(steps)){
                        howtoEl.innerHTML = '<ol class="ck-steps-list">' +
                            steps.map(s => '<li>' + s + '</li>').join('') + '</ol>';
                    } else { howtoEl.innerHTML = steps || ''; }
                }

                const actionsEl = row.querySelector('.ck-panel-actions');
                if(actionsEl){
                    actionsEl.innerHTML = '';
                    const isLicensed = row.dataset.licensed === '1';
                    const toggle     = row.querySelector('.giveaways-toggle');
                    const isActive   = toggle && toggle.checked;
                    if(isLicensed && isActive && meta && meta.page){
                        const link = document.createElement('a');
                        link.href = 'admin.php?page=' + meta.page;
                        link.className = 'ck-preview-settings-link';
                        link.textContent = 'Open Settings →';
                        actionsEl.appendChild(link);
                    }
                }

                const globalListEl = row.querySelector('.ck-panel-settings-list');
                if(globalListEl) globalListEl.innerHTML = (data.settings||[]).map(s=>'<li>'+s+'</li>').join('');

                const productListEl = row.querySelector('.ck-panel-product-settings-list');
                if(productListEl) productListEl.innerHTML = (data.product_settings||[]).map(s=>'<li>'+s+'</li>').join('');
            }

            function openRow(row){
                rows.forEach(r => {
                    if(r !== row && r.classList.contains('ck-row-open')){
                        r.classList.remove('ck-row-open');
                        const p = r.querySelector('.ck-row-panel');
                        if(p) p.setAttribute('aria-hidden','true');
                    }
                });
                populatePanel(row);
                row.classList.add('ck-row-open');
                const panel = row.querySelector('.ck-row-panel');
                if(panel) panel.setAttribute('aria-hidden','false');
                // The panel is a drawer now: it slides in over the page, so no scrolling to it.
                document.body.classList.add('ck-drawer-open');
            }

            function closeRow(row){
                row.classList.remove('ck-row-open');
                const panel = row.querySelector('.ck-row-panel');
                if(panel) panel.setAttribute('aria-hidden','true');
                if (!document.querySelector('.ck-game-row.ck-row-open')) document.body.classList.remove('ck-drawer-open');
            }
            function closeAll(){ rows.forEach(r => { if (r.classList.contains('ck-row-open')) closeRow(r); }); }

            // Drawer: its close button, the backdrop, and Esc all close it. Clicks
            // inside the drawer must not reach the card header (which toggles it).
            rows.forEach(row => {
                const panel = row.querySelector('.ck-row-panel');
                if (panel) panel.addEventListener('click', e => e.stopPropagation());
                const x = row.querySelector('.ck-drawer-close');
                if (x) x.addEventListener('click', e => { e.preventDefault(); closeRow(row); });
            });
            const scrim = document.getElementById('ck-drawer-scrim');
            if (scrim) scrim.addEventListener('click', closeAll);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });

            // Spotlight buttons open that game's drawer.
            document.querySelectorAll('[data-open-row]').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.preventDefault();
                    const row = document.querySelector('.ck-game-row[data-key="' + btn.dataset.openRow + '"]');
                    if (row) openRow(row);
                });
            });

            // Filters: All / Active / Not licensed.
            document.querySelectorAll('.ck-filters [data-filter]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.ck-filters [data-filter]').forEach(b => b.classList.toggle('is-on', b === btn));
                    const f = btn.dataset.filter;
                    document.querySelectorAll('.ck-game-row, .ck-free-tile').forEach(card => {
                        const free = card.classList.contains('ck-free-tile');
                        const on   = free || card.dataset.enabled === '1';
                        const lic  = free || card.dataset.licensed === '1';
                        const show = f === 'all' || ( f === 'active' && on ) || ( f === 'locked' && ! lic );
                        card.classList.toggle('is-hidden', ! show);
                    });
                });
            });

            rows.forEach(row => {
                const checkbox   = row.querySelector('.giveaways-toggle');
                const rowHeader  = row.querySelector('.ck-row-header');
                const chevronBtn = row.querySelector('.ck-chevron-btn');

                // Whole header opens/closes panel
                if (rowHeader) {
                    rowHeader.addEventListener('click', () => {
                        if (row.classList.contains('ck-row-open')) closeRow(row); else openRow(row);
                    });
                }

                // Chevron also toggles (stopProp so it doesn't double-fire with header)
                if (chevronBtn) {
                    chevronBtn.addEventListener('click', e => {
                        e.stopPropagation();
                        if (row.classList.contains('ck-row-open')) closeRow(row); else openRow(row);
                    });
                }

                // Toggle change → refresh status text
                if (checkbox) checkbox.addEventListener('change', () => refreshStatus(row));
                refreshStatus(row);

                // "Add Licence" locked pill → open panel + focus + highlight input
                const lockedPill = row.querySelector('.ck-toggle-pill.sw-locked');
                if (lockedPill) {
                    lockedPill.addEventListener('click', e => {
                        e.stopPropagation();
                        openRow(row);
                        setTimeout(() => {
                            const input = row.querySelector('.giveaways-license-input');
                            if (input) {
                                input.focus();
                                input.classList.remove('ck-licence-input-highlight');
                                void input.offsetWidth; // force reflow so animation restarts
                                input.classList.add('ck-licence-input-highlight');
                                setTimeout(() => input.classList.remove('ck-licence-input-highlight'), 750);
                            }
                        }, 390);
                    });
                }
            });

            // ── Enable-module buttons (panel prompt) ──────────────────────
            document.querySelectorAll('.ck-enable-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const moduleKey = this.dataset.module;
                    const row = document.querySelector('[data-key="' + moduleKey + '"]');
                    if (!row) return;
                    this.textContent = 'Enabling…';
                    this.disabled    = true;
                    enableModule(row, moduleKey);
                });
            });

            // ── The single install / update button ────────────────────────
            // This listener is bound directly to the button and calls
            // stopPropagation, so it runs INSTEAD of the delegated
            // .ck-update-games handler above, never as well as it. That is why
            // this has to dispatch explicitly rather than falling through —
            // otherwise the old download path silently wins every click.
            (function () {
                const dl = document.getElementById('ck-download-premium');
                if (dl) {
                    dl.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (this.dataset.mode === 'zip') {
                            // Fallback offered after an in-place install failed.
                            downloadPremiumPackage();
                        } else if (this.dataset.mode === 'install') {
                            window.location.href = ckUploadPluginUrl;
                        } else {
                            installOrUpdateGames(this);
                        }
                    });
                }
            })();

            // ── Collapse/expand the "How premium games work" box ──────────
            (function () {
                const toggle = document.getElementById('ck-howto-toggle');
                const box    = document.getElementById('ck-premium-howto');
                if (toggle && box) {
                    toggle.addEventListener('click', () => box.classList.toggle('is-collapsed'));
                    toggle.addEventListener('keydown', e => {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); box.classList.toggle('is-collapsed'); }
                    });
                }
            })();

            // (Per-module "Uninstall" removed — games live in the separate
            //  CompKit Game Engine Premium plugin. Turn a game off with its toggle, or
            //  deactivate that plugin, to remove it.)

            // Modal support/feature
            let type = 'support';

            const supportBtn = document.getElementById('giveaways-support');
            const featureBtn = document.getElementById('giveaways-feature');
            const closeBtn  = document.getElementById('giveaways-modal-close');
            const closeBtn2 = document.getElementById('giveaways-modal-close-2');
            const sendBtn = document.getElementById('giveaways-send');

            if(supportBtn) supportBtn.onclick = () => openModal('Support Request', 'support');
            if(featureBtn) featureBtn.onclick = () => openModal('Feature Request', 'feature');
            if(closeBtn)  closeBtn.onclick  = closeModal;
            if(closeBtn2) closeBtn2.onclick = closeModal;

            if(sendBtn){
                sendBtn.onclick = function(){
                    const msg = document.getElementById('giveaways-message').value.trim();
                    if(!msg) return alert('Enter a message');

                    fetch(ajaxurl, {
                        method:'POST',
                        headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body:new URLSearchParams({action:'giveaways_send_message', type:type, message:msg, _nonce:giveawaysNonce})
                    })
                    .then(ajaxJson)
                    .then(r => {
                        const status = document.getElementById('giveaways-modal-status');
                        status.style.display='block';
                        status.innerText = r.success ? 'Sent successfully' : 'Send failed';
                        if(r.success) setTimeout(closeModal, 1500);
                    });
                };
            }

            function openModal(title, t){
                type = t;
                document.getElementById('giveaways-modal-title').innerText = title;
                document.getElementById('giveaways-modal').style.display = 'flex';
            }

            function closeModal(){
                document.getElementById('giveaways-modal').style.display = 'none';
            }

            const moduleToProduct = {
                spin: 'spinbolt', slots: 'slots', scratchcard: 'scratchcardgame',
                instant_display: 'instant-reveal', football: 'footballgame',
                racecar: 'racecar', grabclaw: 'grabclaw', balloon_pop: 'balloon-pop',
                darts: 'dartsgame', boxing: 'boxing'
            };

            function showCheckoutOverlay() {
                const overlay = document.createElement('div');
                overlay.className = 'ck-checkout-loading';
                overlay.innerHTML = '<div class="ck-spinner"></div><div>Redirecting to secure checkout…</div>';
                document.body.appendChild(overlay);
                document.querySelectorAll('.ck-buy-btn').forEach(b => b.disabled = true);
                return overlay;
            }

            function hideCheckoutOverlay(overlay) {
                overlay.remove();
                document.querySelectorAll('.ck-buy-btn').forEach(b => b.disabled = false);
            }

            // ── Fetch prices + render bundles ─────────────────────────────
            fetch(licenceServer + '/api/prices.php')
                .then(ajaxJson)
                .then(data => {
                    const prices  = data.products || {};
                    const bundles = data.bundles  || {};

                    // Populate per-game price spans
                    document.querySelectorAll('.ck-buy-price').forEach(el => {
                        const slug = moduleToProduct[el.dataset.module] || el.dataset.module;
                        const row  = prices[slug];
                        if (row) {
                            el.textContent = el.dataset.interval === 'monthly'
                                ? row.monthly_display + '/mo'
                                : row.yearly_display + '/yr';
                        }
                    });

                    // Render bundle cards
                    const bundleSlugs = Object.keys(bundles);
                    if (bundleSlugs.length) {
                        document.getElementById('ck-bundles-header').style.display = '';
                        const grid = document.getElementById('ck-bundles-grid');
                        grid.innerHTML = '';

                        bundleSlugs.forEach(slug => {
                            const b = bundles[slug];
                            const gameNames = (b.products || []).map(p => prices[p] ? prices[p].label : p).join(', ');

                            // Build icon strip from product slugs
                            const iconParts = [];
                            (b.products || []).forEach((p, i) => {
                                const iconData = productIcons[p];
                                if (iconData) {
                                    if (i > 0) iconParts.push('<span class="ck-bundle-plus">+</span>');
                                    iconParts.push(
                                        `<div class="ck-bundle-icon" style="background-color:${iconData.color};` +
                                        `-webkit-mask-image:url('${iconData.icon}');` +
                                        `mask-image:url('${iconData.icon}');"></div>`
                                    );
                                }
                            });
                            const iconsHtml = iconParts.length
                                ? `<div class="ck-bundle-icons">${iconParts.join('')}</div>`
                                : '';

                            const card = document.createElement('div');
                            card.className = 'ck-bundle-card';
                            card.innerHTML = `
                                ${iconsHtml}
                                <div class="ck-bundle-info">
                                    <div class="ck-bundle-name">${b.label}</div>
                                    ${b.description ? '<div class="ck-bundle-desc">' + b.description + '</div>' : ''}
                                    ${gameNames ? '<div class="ck-bundle-games">Includes: ' + gameNames + '</div>' : ''}
                                </div>
                                <div class="ck-bundle-btns">
                                    ${b.monthly_display ? `<button class="ck-buy-btn ck-buy-monthly" data-bundle="${slug}" data-interval="monthly">Monthly <span>${b.monthly_display}/mo</span></button>` : ''}
                                    ${b.yearly_display  ? `<button class="ck-buy-btn ck-buy-yearly"  data-bundle="${slug}" data-interval="yearly">Yearly <span>${b.yearly_display}/yr</span></button>` : ''}
                                </div>`;
                            grid.appendChild(card);
                        });

                        // Attach buy handlers to newly rendered bundle buttons
                        grid.querySelectorAll('.ck-buy-btn').forEach(btn => {
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                const overlay = showCheckoutOverlay();
                                fetch(ajaxurl, {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: new URLSearchParams({
                                        action:   'giveaways_buy_bundle',
                                        _nonce:   giveawaysBuyNonce,
                                        bundle:   this.dataset.bundle,
                                        interval: this.dataset.interval
                                    })
                                })
                                .then(ajaxJson)
                                .then(r => {
                                    if (r.success && r.data.url) {
                                        window.location.href = r.data.url;
                                    } else {
                                        hideCheckoutOverlay(overlay);
                                        alert('Could not start checkout: ' + (r.data || 'Unknown error'));
                                    }
                                })
                                .catch(() => {
                                    hideCheckoutOverlay(overlay);
                                    alert('Connection error — please try again');
                                });
                            });
                        });
                    }
                })
                .catch(() => {
                    document.querySelectorAll('.ck-buy-price').forEach(el => { el.textContent = ''; });
                });

            // ── Per-game buy button clicks → Stripe Checkout ─────────────
            document.querySelectorAll('.ck-game-row .ck-buy-buttons .ck-buy-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const overlay = showCheckoutOverlay();
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action:   'giveaways_buy_license',
                            _nonce:   giveawaysBuyNonce,
                            module:   this.dataset.module,
                            interval: this.dataset.interval
                        })
                    })
                    .then(ajaxJson)
                    .then(r => {
                        if (r.success && r.data.url) {
                            window.location.href = r.data.url;
                        } else {
                            hideCheckoutOverlay(overlay);
                            alert('Could not start checkout: ' + (r.data || 'Unknown error'));
                        }
                    })
                    .catch(() => {
                        hideCheckoutOverlay(overlay);
                        alert('Connection error — please try again');
                    });
                });
            });

            // ── Detect Stripe redirect return ─────────────────────────────
            const urlParams = new URLSearchParams(window.location.search);
            const sessionId = urlParams.get('compkit_session');
            const retModule = urlParams.get('compkit_module');
            const retBundle = urlParams.get('compkit_bundle');

            // ── Single-game return ────────────────────────────────────────
            if (sessionId && retModule) {
                const row = document.querySelector('[data-key="' + retModule + '"]');
                if (row) {
                    openRow(row);
                    const statusEl = row.querySelector('.giveaways-inline-status');
                    if (statusEl) {
                        statusEl.className = 'giveaways-inline-status';
                        statusEl.textContent = 'Verifying payment and activating licence…';
                    }
                }

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:     'giveaways_collect_license',
                        _nonce:     giveawaysBuyNonce,
                        session_id: sessionId,
                        module:     retModule
                    })
                })
                .then(ajaxJson)
                .then(r => {
                    if (r.success) {
                        if (row) {
                            const input = row.querySelector('.giveaways-license-input');
                            if (input) input.value = r.data.license_key;
                            const statusEl = row.querySelector('.giveaways-inline-status');
                            if (statusEl) {
                                statusEl.className = 'giveaways-inline-status status-ok';
                                statusEl.textContent = '✓ Licence activated! ' + (r.data.expires ? 'Expires ' + r.data.expires : '');
                            }
                            row.dataset.licensed = '1';
                            row.classList.remove('is-locked');
                            row.classList.add('is-licensed');
                            const buySection = row.querySelector('.ck-buy-section');
                            if (buySection) buySection.style.display = 'none';
                            if (r.data.installed) {
                                row.dataset.installed = '1';
                                updateRowToEnabled(row);
                            } else {
                                const moduleLabel = moduleMeta[retModule]?.label || retModule;
                                showInstallPrompt(row, retModule, moduleLabel);
                                const toggle = row.querySelector('.giveaways-toggle');
                                if (toggle) toggle.disabled = false;
                                refreshStatus(row);
                            }
                            revealDownloadButton();
                        }
                        history.replaceState(null, '', window.location.pathname + '?page=giveaways-addons');
                    } else {
                        if (row) {
                            const statusEl = row.querySelector('.giveaways-inline-status');
                            if (statusEl) {
                                statusEl.className = 'giveaways-inline-status status-err';
                                statusEl.textContent = 'Activation failed: ' + (r.data || 'Unknown error');
                            }
                        }
                    }
                })
                .catch(() => {
                    if (row) {
                        const statusEl = row.querySelector('.giveaways-inline-status');
                        if (statusEl) {
                            statusEl.className = 'giveaways-inline-status status-err';
                            statusEl.textContent = 'Could not verify payment — please contact support';
                        }
                    }
                });
            }

            // ── Bundle return ─────────────────────────────────────────────
            if (sessionId && retBundle) {
                // Show a top-of-page notice while we activate
                const notice = document.createElement('div');
                notice.style.cssText = 'position:fixed;top:32px;left:0;right:0;z-index:9999;background:#6366f1;color:#fff;text-align:center;padding:12px;font-size:14px;font-weight:600;';
                notice.textContent = 'Verifying bundle payment and activating licences…';
                document.body.appendChild(notice);

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action:     'giveaways_collect_bundle',
                        _nonce:     giveawaysBuyNonce,
                        session_id: sessionId
                    })
                })
                .then(ajaxJson)
                .then(r => {
                    notice.remove();
                    if (r.success) {
                        const activated = r.data.keys || [];
                        activated.forEach(item => {
                            const row = document.querySelector('[data-key="' + item.module + '"]');
                            if (!row) return;
                            openRow(row);
                            const input = row.querySelector('.giveaways-license-input');
                            if (input) input.value = item.license_key;
                            const statusEl = row.querySelector('.giveaways-inline-status');
                            if (statusEl) {
                                statusEl.className = 'giveaways-inline-status status-ok';
                                statusEl.textContent = '✓ Activated! ' + (item.expires ? 'Expires ' + item.expires : '');
                            }
                            row.dataset.licensed = '1';
                            row.classList.remove('is-locked');
                            row.classList.add('is-licensed');
                            const buySection = row.querySelector('.ck-buy-section');
                            if (buySection) buySection.style.display = 'none';
                            if (item.installed) {
                                row.dataset.installed = '1';
                                updateRowToEnabled(row);
                            } else {
                                const moduleLabel = moduleMeta[item.module]?.label || item.module;
                                showInstallPrompt(row, item.module, moduleLabel);
                                const toggle = row.querySelector('.giveaways-toggle');
                                if (toggle) toggle.disabled = false;
                                refreshStatus(row);
                            }
                        });

                        revealDownloadButton();

                        const successNotice = document.createElement('div');
                        successNotice.style.cssText = 'position:fixed;top:32px;left:0;right:0;z-index:9999;background:#16a34a;color:#fff;text-align:center;padding:12px;font-size:14px;font-weight:600;';
                        successNotice.textContent = '✓ Bundle activated — ' + activated.length + ' licence' + (activated.length !== 1 ? 's' : '') + ' added!';
                        document.body.appendChild(successNotice);
                        setTimeout(() => successNotice.remove(), 5000);

                        history.replaceState(null, '', window.location.pathname + '?page=giveaways-addons');
                    } else {
                        alert('Bundle activation failed: ' + (r.data || 'Unknown error'));
                    }
                })
                .catch(() => {
                    notice.remove();
                    alert('Could not verify bundle payment — please contact support');
                });
            }

        })();
    </script>
    <?php
});
