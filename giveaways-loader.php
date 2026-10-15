<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_notices', function () {
    if (function_exists('lty_get_lottery_ticket')) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>CompKit Game Engine</strong> requires the <em>Giveaway for WooCommerce</em> plugin by <strong>Flintop</strong> (formerly <em>Lottery for WooCommerce</em>) to be installed and active &mdash; available at <a href="' . esc_url( 'https://woocommerce.com/products/giveaway-for-woocommerce/' ) . '" target="_blank" rel="noopener">woocommerce.com</a>. Note: this is <strong>not</strong> the similarly-named plugin by WebCartisan.</p></div>';
});

/**
 * Gate: prevent game categories from being assigned to a product unless both
 * "Instant Win Prizes" is enabled on the product AND at least one Instant Win
 * Prize Group is already configured for it.
 *
 * Taxonomy term saving happens in edit_post() AFTER wp_update_post() returns,
 * so modifying $_POST['tax_input']['product_cat'] here (inside save_post via
 * woocommerce_process_product_meta) reliably prevents the terms from being written.
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- fired inside woocommerce_process_product_meta which runs after WooCommerce's own nonce check
add_action('woocommerce_process_product_meta', function ( $post_id ) {

    // Resolve slugs to term IDs (skip any that don't exist yet)
    $game_term_ids = [];
    foreach ( array_keys( gaa_game_tab_config() ) as $slug ) {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $term ) {
            $game_term_ids[ $slug ] = (int) $term->term_id;
        }
    }

    if ( empty( $game_term_ids ) ) {
        return;
    }

    // Which term IDs is this product being saved with?
    $submitted = isset( $_POST['tax_input']['product_cat'] )
        ? array_map( 'intval', (array) $_POST['tax_input']['product_cat'] )
        : [];

    // Find which game categories are in the submission
    $bad_term_ids  = [];
    $bad_slugs     = [];
    foreach ( $game_term_ids as $slug => $tid ) {
        if ( in_array( $tid, $submitted, true ) ) {
            $bad_term_ids[] = $tid;
            $bad_slugs[]    = $slug;
        }
    }

    if ( empty( $bad_term_ids ) ) {
        return; // No game category being assigned — nothing to gate
    }

    // ---------------------------------------------------------------
    // Only one game category may be assigned at a time
    // ---------------------------------------------------------------
    if ( count( $bad_term_ids ) > 1 ) {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
            WC_Admin_Meta_Boxes::add_error(
                __( 'Only one game category can be assigned to a product at a time. Please choose a single game category.', 'compkit-game-engine' )
            );
        }
        gaa_strip_game_cats_from_post( $bad_term_ids );
        return;
    }

    // ---------------------------------------------------------------
    // Prerequisite 1 — LFW must be active
    // ---------------------------------------------------------------
    if ( ! function_exists( 'lty_get_lottery_ticket' ) ) {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
            WC_Admin_Meta_Boxes::add_error(
                __( 'Game categories require Giveaways (Lottery) for WooCommerce to be active. The game category was not saved.', 'compkit-game-engine' )
            );
        }
        gaa_strip_game_cats_from_post( $bad_term_ids );
        return;
    }

    // ---------------------------------------------------------------
    // Prerequisite 2 — Instant Win Prizes must be enabled on the product
    // (LFW renders this as a checkbox; its presence in $_POST means "yes")
    // ---------------------------------------------------------------
    $instant_wins_on = isset( $_POST['lty_instant_winners'] );

    // ---------------------------------------------------------------
    // Prerequisite 3 — At least one Prize Group must exist for this product
    // ---------------------------------------------------------------
    $has_prize_groups = function_exists( 'lty_get_instant_winner_prize_group_ids' )
        && ! empty( lty_get_instant_winner_prize_group_ids( $post_id ) );

    if ( $instant_wins_on && $has_prize_groups ) {
        return; // All prerequisites met — allow the save
    }

    // ---------------------------------------------------------------
    // Gate triggered — build a clear, actionable error message
    // ---------------------------------------------------------------
    $problems = [];

    if ( ! $instant_wins_on ) {
        $problems[] = __( 'Instant Win Prizes is not enabled on this product — tick the checkbox on the <strong>Instant Win Prizes</strong> tab', 'compkit-game-engine' );
    }

    if ( ! $has_prize_groups ) {
        $problems[] = __( 'no Instant Win Prize Groups are configured — add at least one group on the <strong>Instant Win Prize Groups</strong> tab and save first', 'compkit-game-engine' );
    }

    if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
        WC_Admin_Meta_Boxes::add_error(
            sprintf(
                /* translators: %s = semicolon-separated list of problems */
                __( 'The game category (<code>%1$s</code>) could not be saved: %2$s.', 'compkit-game-engine' ),
                esc_html( implode( ', ', $bad_slugs ) ),
                implode( '; ', $problems )
            )
        );
    }

    // Strip the invalid game categories from the POST data so WordPress
    // never writes them to the database (taxonomy save runs after this hook)
    gaa_strip_game_cats_from_post( $bad_term_ids );

}, 5 ); // Priority 5 — before WooCommerce's own meta save (priority 10)
// phpcs:enable WordPress.Security.NonceVerification.Missing


/**
 * Remove specific term IDs from the submitted product_cat taxonomy data.
 *
 * @param int[] $term_ids Term IDs to remove.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function gaa_strip_game_cats_from_post( array $term_ids ) {

    if ( ! isset( $_POST['tax_input']['product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $_POST['tax_input']['product_cat'] = array_values(
        array_filter(
            (array) $_POST['tax_input']['product_cat'],
            function ( $id ) use ( $term_ids ) {
                return ! in_array( (int) $id, $term_ids, true );
            }
        )
    );
    // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}

/**
 * A single game's version, read from its module entry file's docblock header.
 *
 * Each game carries its own version, independent of the plugin version, and it is
 * maintained by scripts/module-version.ps1 — the file is the source of truth, there
 * is no manifest to drift.
 *
 * Deliberately lives here rather than in the premium plugin: the Game Addons
 * white-label ships its own premium shell, which does NOT define
 * compkit_premium_module_version(), so a badge relying on that would silently
 * vanish on the white-label edition. Both editions populate the module 'file' key
 * via the giveaways_register_modules filter, so reading it here works for both.
 *
 * Touches disk once per module, so the result is cached for the request. Only call
 * it where a version is actually displayed.
 *
 * @param string $key Module key, e.g. 'grabclaw' or 'balloon_pop'.
 * @return string Version, or '' when the module isn't installed or has no header.
 */
function giveaways_module_version( $key ) {
    static $cache = [];

    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }

    $cache[ $key ] = '';

    if ( ! function_exists( 'giveaways_modules' ) ) {
        return '';
    }

    $modules = giveaways_modules();
    $file    = $modules[ $key ]['file'] ?? '';

    if ( $file && file_exists( $file ) ) {
        $cache[ $key ] = get_file_data( $file, [ 'v' => 'Version' ] )['v'] ?: '';
    }

    return $cache[ $key ];
}

/**
 * Central config — every game category slug this plugin registers,
 * mapped to the tab key the bolt-on adds via lty_product_lottery_tab
 * (null = no per-product tab for that game).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function gaa_game_tab_config() {
    return [
        // slug            tab_key registered by bolt-on (null = no per-product tab)
        'instant-reveal' => 'instant_reveal', // per-product tab added
        'spin'           => 'spin_wheel',
        'scratchcard'    => 'scratchcard',
        'football'       => 'football',  // per-product tab added
        'racecar'        => 'racecar',
        'claw-machine'   => 'grabclaw',
        'slots'          => 'slots',
        'balloon-pop'    => 'balloon_pop',
        'darts'          => 'darts',
        'boxing'         => 'boxing',
    ];
}


/**
 * Hide each game's per-product settings tab unless the product
 * belongs to the corresponding game category.
 *
 * Runs at priority 99 — after LFW (priority 10) has already merged
 * all bolt-on tabs via the lty_product_lottery_tab filter.
 */
add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {

    global $post;
    if ( ! $post || get_post_type( $post ) !== 'product' ) {
        return $tabs;
    }

    foreach ( gaa_game_tab_config() as $cat_slug => $tab_key ) {

        if ( $tab_key === null ) {
            continue; // no tab registered for this game
        }

        if ( ! isset( $tabs[ $tab_key ] ) ) {
            continue; // bolt-on not loaded / tab not registered
        }

        if ( ! has_term( $cat_slug, 'product_cat', $post->ID ) ) {
            // Keep the tab in the DOM (so JS can reveal it instantly when the
            // category checkbox is ticked) but hide it via a CSS class.
            $tabs[ $tab_key ]['class'] = array_merge(
                (array) ( $tabs[ $tab_key ]['class'] ?? [] ),
                [ 'gaa-tab-hidden' ]
            );
        }
    }

    return $tabs;

}, 99 );


/**
 * On product edit screens:
 *   • Hide game category checkboxes until at least one Prize Group exists.
 *   • Output CSS/JS that reveals/re-hides the per-product game tab the
 *     moment a game category checkbox is ticked (no extra save required).
 */
add_action( 'admin_footer', function () {

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'product' ) {
        return;
    }

    global $post;
    if ( ! $post ) {
        return;
    }

    // Build term_id => tab_key map so JS knows which checkbox controls which tab.
    // Also collect all game category term IDs for the prize-group gate.
    $tab_map      = []; // term_id => tab_key
    $game_term_ids = []; // all game category term IDs

    foreach ( gaa_game_tab_config() as $slug => $tab_key ) {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term ) {
            continue;
        }
        $tid            = (int) $term->term_id;
        $game_term_ids[] = $tid;
        if ( $tab_key !== null ) {
            $tab_map[ $tid ] = $tab_key;
        }
    }

    if ( empty( $game_term_ids ) ) {
        return;
    }

    // Does this product already have at least one Prize Group saved?
    $has_prize_groups = function_exists( 'lty_get_instant_winner_prize_group_ids' )
        && ! empty( lty_get_instant_winner_prize_group_ids( $post->ID ) );
    // Tab key => SVG URL for the product-tab icon override.
    // Single source of truth: the core plugin's /icons folder (premium game folders
    // live in a separate plugin, so we never reference them from here).
    $icons_base = plugin_dir_url( __FILE__ ) . 'icons/';
    $tab_icons = [
        'spin_wheel'     => $icons_base . 'spin.svg',
        'slots'          => $icons_base . 'slots.svg',
        'scratchcard'    => $icons_base . 'scratchcard.svg',
        'football'       => $icons_base . 'football.svg',
        'racecar'        => $icons_base . 'racecar.svg',
        'grabclaw'       => $icons_base . 'grabclaw.svg',
        'balloon_pop'    => $icons_base . 'balloon-pop.svg',
        'darts'          => $icons_base . 'darts.svg',
        'boxing'         => $icons_base . 'boxing.svg',
        'instant_reveal' => $icons_base . 'instant-reveal.svg',
    ];
    ?>
    <style>
        ul.product_data_tabs li.gaa-tab-hidden { display: none !important; }

        /*
         * Replace the default WooCommerce spanner icon with each game's SVG.
         *
         * Strategy: hide WooCommerce's ::before (which carries the spanner font glyph)
         * entirely, then use ::after (which WooCommerce does not touch for tab icons)
         * to render our SVG via mask-image. This sidesteps any specificity/!important
         * battle over the ::before content property.
         */
        <?php foreach ( $tab_icons as $tab_key => $svg_url ) : ?>
        /* Hide WooCommerce's spanner glyph on ::before */
        #woocommerce-product-data ul.product_data_tabs li.<?php echo esc_attr( $tab_key ); ?>_tab > a::before {
            display: none !important;
        }
        /* Make <a> a flex row so we can push ::after to the front with order:-1 */
        #woocommerce-product-data ul.product_data_tabs li.<?php echo esc_attr( $tab_key ); ?>_tab > a {
            display: flex !important;
            align-items: center !important;
            gap: 6px;
        }
        /* Render the SVG icon before the label text */
        #woocommerce-product-data ul.product_data_tabs li.<?php echo esc_attr( $tab_key ); ?>_tab > a::after {
            content: '' !important;
            order: -1;
            display: block !important;
            flex-shrink: 0;
            width: 17px;
            height: 17px;
            background-color: currentColor;
            -webkit-mask-image: url('<?php echo esc_url( $svg_url ); ?>');
                    mask-image: url('<?php echo esc_url( $svg_url ); ?>');
            -webkit-mask-repeat: no-repeat;
                    mask-repeat: no-repeat;
            -webkit-mask-size: contain;
                    mask-size: contain;
            -webkit-mask-position: center;
                    mask-position: center;
        }
        <?php endforeach; ?>
    </style>
    <script>
    (function ($) {
        var tabMap         = <?php echo wp_json_encode( $tab_map ); ?>;
        var gameCatIds     = <?php echo wp_json_encode( $game_term_ids ); ?>;
        var hasPrizeGroups = <?php echo $has_prize_groups ? 'true' : 'false'; ?>;

        // Find a game-category checkbox by term ID regardless of which metabox
        // markup WooCommerce/WP uses — search the whole page by value attribute.
        function getCb(termId) {
            return $('input[type="checkbox"][value="' + termId + '"]');
        }

        function getTabLi(tabKey) {
            return $('ul.product_data_tabs li.' + tabKey + '_tab');
        }

        /* --- Prize-group gate -------------------------------------------------
         *
         * Game categories only make sense once the product actually has instant
         * win prizes, so they stay hidden until it does.
         *
         * hasPrizeGroups is what the SERVER knew when the page rendered, i.e.
         * what was last saved. Gating on that alone meant the categories could
         * not appear until after a save: tick Instant Win Prizes, add a prize
         * group, and nothing happened — you had to save, reload, and only then
         * could you pick the game. Two round trips to configure one product.
         *
         * So the gate is re-evaluated live against LFW's own fields:
         *   #lty_instant_winners                        the enable checkbox
         *   lty_instant_winner_rules[<id>][group_id]    the per-rule group select
         *
         * Those rule rows are added and removed dynamically, so the listener is
         * delegated on document rather than bound to the selects themselves.
         */
        var $catList = $('#product_catdiv, #product_catchecklist, .product_cat-checklist').first();
        if ($catList.length && !$('.gaa-cat-locked').length) {
            $catList.after(
                '<p class="gaa-cat-locked" style="margin:8px 0 0; color:#888; font-size:12px;">' +
                '🔒 Game categories unlock once you\'ve enabled <strong>Instant Win Prizes</strong> ' +
                'and chosen a <strong>Prize Group</strong>.' +
                '</p>'
            );
        }

        function prizeGroupsReady() {

            /* Instant wins off means there is nothing for a game to reveal, so
               this comes first and overrides everything below. */
            if (!$('#lty_instant_winners').is(':checked')) return false;

            /* The intended trigger: LFW's "Instant Win Prize Display Mode" set to
               "Display Prizes by Group" (value 2). That is the moment the product
               is committed to prize groups, and the moment the game categories
               become meaningful. */
            if ($('#lty_instant_winner_display_mode').val() === '2') return true;

            // Saved with groups already — keeps existing products working.
            if (hasPrizeGroups) return true;

            /* Belt and braces: a group actually picked on a rule row, for a
               product using per-rule groups without the grouped display mode. */
            var chosen = false;
            $('select[name^="lty_instant_winner_rules"][name$="[group_id]"]').each(function () {
                var v = $(this).val();
                if (v && v !== '0') chosen = true;
            });
            return chosen;
        }

        function syncCatGate() {
            var ready = prizeGroupsReady();

            gameCatIds.forEach(function (id) {
                var $li = getCb(id).closest('li');
                if (ready) { $li.show(); } else { $li.hide(); }
            });

            $('.gaa-cat-locked').toggle(!ready);

            /* Tab visibility is deliberately NOT touched here. It is owned by the
               load-time sync and the checkbox handler below, both of which key off
               which category is actually ticked. Hiding a category row does not
               untick it, so a product saved in a game category keeps its settings
               tab — which is correct, and two places writing the same class would
               only race. */
        }

        syncCatGate();

        $(document).on(
            'change',
            '#lty_instant_winners, #lty_instant_winner_display_mode, ' +
            'select[name^="lty_instant_winner_rules"][name$="[group_id]"]',
            syncCatGate
        );

        // --- Scroll to WC save errors so they are never missed ---
        var $wcError = $('.woocommerce-message.error, #woocommerce-product-updated.error, .notice-error');
        if ($wcError.length) {
            $('html, body').animate({ scrollTop: $wcError.first().offset().top - 40 }, 300);
        }

        // --- On load: enforce single selection and sync tab visibility ---
        // The PHP-side `woocommerce_product_data_tabs` filter only hides a
        // tab if the product is NOT in that game category, but it can't
        // correct products that already have more than one game category
        // saved (e.g. from before this gate existed). Do both here so the
        // page always loads with exactly one game tab visible.
        (function () {
            var checkedIds = gameCatIds.filter(function (id) {
                return getCb(id).is(':checked');
            });

            // If more than one game category is checked, keep only the first
            // and uncheck the rest.
            if (checkedIds.length > 1) {
                checkedIds.slice(1).forEach(function (id) {
                    getCb(id).prop('checked', false);
                });
                checkedIds = checkedIds.slice(0, 1);
            }

            // Show only the tab for the selected game category; hide the rest.
            gameCatIds.forEach(function (id) {
                var tabKey = tabMap[id];
                if (!tabKey) return;
                var $li = getTabLi(tabKey);
                if (checkedIds.indexOf(id) !== -1) {
                    $li.removeClass('gaa-tab-hidden');
                } else {
                    $li.addClass('gaa-tab-hidden');
                }
            });
        })();

        // --- Tab visibility: enforce single game category selection ---
        // Listen broadly on document for any checkbox — we filter by gameCatIds
        // immediately, so every non-game checkbox is a no-op.
        $(document).on('change', 'input[type="checkbox"]', function () {
            var termId = parseInt($(this).val(), 10);
            if (isNaN(termId) || gameCatIds.indexOf(termId) === -1) return;

            // tabKey may be absent for categories with no per-product tab (instant-reveal)
            var tabKey = tabMap[termId];
            var $li    = tabKey ? getTabLi(tabKey) : $();

            if ($(this).is(':checked')) {
                // Uncheck every other game category and hide its tab.
                gameCatIds.forEach(function (otherId) {
                    if (otherId === termId) return;
                    var $otherCb = getCb(otherId);
                    if ($otherCb.is(':checked')) {
                        $otherCb.prop('checked', false);
                        var otherKey = tabMap[otherId];
                        if (otherKey) {
                            var $otherLi = getTabLi(otherKey);
                            if ($otherLi.hasClass('active')) {
                                $('ul.product_data_tabs li:not(.gaa-tab-hidden):first a').trigger('click');
                            }
                            $otherLi.addClass('gaa-tab-hidden');
                        }
                    }
                });
                if ($li.length) $li.removeClass('gaa-tab-hidden');
                /* On a saved product the premium plugin only loads the game the
                   product was SAVED in (compkit_premium_editing_product_in_category),
                   so switching to another game has no tab to reveal until the
                   product is updated. Say so, rather than appear to do nothing. */
                $('.gaa-cat-needs-save').remove();
                if (tabKey && !$li.length) {
                    $catList.after(
                        '<p class="gaa-cat-needs-save" style="margin:8px 0 0; color:#b26200; font-size:12px;">' +
                        'Click <strong>Update</strong> to load this game\'s settings tab.' +
                        '</p>'
                    );
                }
            } else {
                $('.gaa-cat-needs-save').remove();
                if ($li.length) {
                    if ($li.hasClass('active')) {
                        $('ul.product_data_tabs li:not(.gaa-tab-hidden):first a').trigger('click');
                    }
                    $li.addClass('gaa-tab-hidden');
                }
            }
        });
    })(jQuery);
    </script>
    <?php
} );


function giveaways_modules() {

    // Free modules — bundled in this plugin, always present.
    $modules = [
        'instant_display' => [
            'name' => 'Instant Reveal Display',
            'file' => GIVEAWAYS_PATH . 'instant-reveal-display-addon/instant-reveal-display.php'
        ],
        'instant_email' => [
            'name' => 'Instant Reveal Email',
            'file' => GIVEAWAYS_PATH . 'instant-email/email-handler.php'
        ],
    ];

    // Premium catalogue — metadata only (drives the Buy / Download UI and pricing).
    // The actual module CODE ships in the separate "CompKit Game Engine Premium" plugin,
    // which injects the real 'file' path via the giveaways_register_modules filter
    // once installed. Entries without a 'file' are shown as available to
    // download/buy and are never loaded.
    $premium = [
        'spin'        => ['name' => 'Spin'],
        'slots'       => ['name' => 'Slots'],
        'scratchcard' => ['name' => 'Scratch Card'],
        'football'    => ['name' => 'Football Add-on'],
        'racecar'     => ['name' => 'Racecar'],
        'grabclaw'    => ['name' => 'Prize Grab-claw'],
        'balloon_pop' => ['name' => 'Balloon Pop'],
        'darts'       => ['name' => 'Darts'],
        'boxing'      => ['name' => 'Boxing'],
    ];

    $modules = array_merge( $modules, $premium );

    /**
     * The CompKit Game Engine Premium plugin supplies the real file paths for whichever
     * modules the customer is entitled to and has installed.
     */
    return apply_filters( 'giveaways_register_modules', $modules );
}

add_action('plugins_loaded', function() {
    $enabled = get_option('giveaways_enabled_modules', []);
    $enabled_changed = false;

    // Ask the gate per module rather than reading the status option directly:
    // giveaways_is_module_licensed() verifies the server's signature over the
    // entitlement, so an option edited by hand does not load a premium game.
    $is_module_licensed = function ( $key ) {
        return function_exists( 'giveaways_is_module_licensed' )
            && giveaways_is_module_licensed( $key );
    };

    foreach (giveaways_modules() as $key => $module) {

        // instant_email is always free; instant_display is free on standard, licence-gated on whitelabel
        $is_whitelabel = defined('GIVEAWAYS_WL_PLUGIN_NAME');

        // Premium modules only have a 'file' once the CompKit Game Engine Premium plugin
        // supplies it; skip cleanly when it's absent (not installed yet).
        $has_file = ! empty( $module['file'] ) && file_exists( $module['file'] );

        if ($key === 'instant_email' || ($key === 'instant_display' && !$is_whitelabel)) {
            if ($has_file) {
                require_once $module['file'];
            }
            continue;
        }

        $is_licensed = $is_module_licensed($key);

        // Auto-enable a licensed, installed premium game the first time it appears
        // (i.e. right after the CompKit Game Engine Premium plugin is activated), so the
        // customer doesn't have to toggle each one on. A game the user has explicitly
        // turned off is stored as 0 and is respected — only never-seen games turn on.
        if ($is_licensed && $has_file && ! array_key_exists($key, $enabled)) {
            $enabled[$key]   = 1;
            $enabled_changed = true;
        }

        $is_enabled = !empty($enabled[$key]);

        if ($is_enabled && $is_licensed && $has_file) {
            require_once $module['file'];
        }
    }

    if ($enabled_changed) {
        update_option('giveaways_enabled_modules', $enabled);
    }
});
