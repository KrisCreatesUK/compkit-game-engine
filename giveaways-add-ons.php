<?php
/*
Plugin Name: CompKit Game Engine
Plugin URI: https://compkit.kriscreates.co.uk
Description: Adds interactive game-based prize reveal modules to Giveaway for WooCommerce by Flintop (formerly Lottery for WooCommerce). Includes Instant Reveal free, with Spin the Wheel, Slot Machine, Scratch Card, Football Penalty, Race Car, and Grab Claw available as individually licensed premium add-ons.
Version: 3.0.81
Author: KrisCreates
Author URI: https://kriscreates.co.uk
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Text Domain: compkit-game-engine
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define('GIVEAWAYS_PATH', plugin_dir_path(__FILE__));
define('GIVEAWAYS_URL', plugin_dir_url(__FILE__));
define('GIVEAWAYS_VERSION', get_file_data(__FILE__, ['v' => 'Version'])['v'] ?: '0');

require_once GIVEAWAYS_PATH . 'giveaways-license-manager.php';
require_once GIVEAWAYS_PATH . 'giveaways-updater.php';
require_once GIVEAWAYS_PATH . 'giveaways-admin.php';
require_once GIVEAWAYS_PATH . 'giveaways-loader.php';
require_once GIVEAWAYS_PATH . 'giveaways-review-prompt.php';

// Neither plugin is on WordPress.org, so this is the only update channel there
// is. The premium plugin registers itself the same way once it loads.
giveaways_register_updater( __FILE__, 'compkit-game-engine' );
add_action('wp_enqueue_scripts', function () {

    if (is_admin()) {
        return;
    }

    wp_enqueue_style(
        'giveaways-instant-reveal',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        [],
        '1.0.0'
    );

});



/**
 * Activation
 */
register_activation_hook(__FILE__, function () {

    $enabled = get_option('giveaways_enabled_modules', []);
    $enabled['instant_email'] = 1;
    update_option('giveaways_enabled_modules', $enabled);

    if (!taxonomy_exists('product_cat')) {
        return;
    }

    if (!get_term_by('slug', 'prize', 'product_cat')) {
        wp_insert_term('Prizes', 'product_cat', ['slug' => 'prize']);
    }

    if (!get_term_by('slug', 'non-instant', 'product_cat')) {
        wp_insert_term('No Instant Wins', 'product_cat', ['slug' => 'non-instant']);
    }

});

/**
 * Deactivation — remove scheduled cron event
 */
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('giveaways_daily_license_validation');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'giveaways_daily_license_validation');
    }
});
/**
 * Start output buffering on thank you page
 */
add_action('template_redirect', function () {

    if (
        !is_wc_endpoint_url('order-received') &&
        !is_wc_endpoint_url('view-order')
    ) {
        return;
    }

    ob_start('giveaways_filter_prize_rows');
});


/**
 * The ticket NUMBER a prize row prints, from whatever a game's data-tickets
 * holds for one ticket. The prize message's first <b> is the ticket number
 * ({ticket_number} in the giveaway plugin's template), but most games put the
 * ticket's post ID on the page - racecar a bare ID, darts and balloon pop
 * {ticket_id, order_id}, boxing {ticket_id, number}. Matching IDs against
 * numbers never hit, so those games' prize rows were never hidden.
 */
function giveaways_ticket_number_of( $t ): string {
    if ( is_array( $t ) ) {
        if ( isset( $t['number'] ) && $t['number'] !== '' ) return trim( (string) $t['number'] );
        $t = $t['ticket_id'] ?? '';
    }
    $id = (int) $t;
    if ( $id > 0 && function_exists( 'lty_get_lottery_ticket' ) ) {
        $ticket = lty_get_lottery_ticket( $id );
        if ( $ticket && method_exists( $ticket, 'get_lottery_ticket_number' ) ) {
            $n = trim( (string) $ticket->get_lottery_ticket_number() );
            if ( $n !== '' ) return $n;
        }
    }
    return trim( (string) $t );
}

/**
 * Filter unwanted prize rows BEFORE output (no flicker)
 */
function giveaways_filter_prize_rows($html) {

    if (strpos($html, 'prize-thankyou-container') === false) {
        return $html;
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $xpath = new DOMXPath($dom);

    $ticketMap = [];

    /**
     * ======================
     * SCRATCHCARD
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'scratch-reveal-thankyou-container')]") as $node) {
        $data = $node->getAttribute('data-tickets');
        $tickets = json_decode($data, true);

        if (is_array($tickets)) {
            foreach ($tickets as $t) {
                if (($t['prize_type'] ?? '') === 'product') {
                    $ticketMap[trim((string)$t['ticket'])] = 'scratchcard';
                }
            }
        }
    }

    /**
     * ======================
     * SPIN
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'spinbolt-wheel-data')]") as $node) {
        $data = $node->getAttribute('data-wheel');
        $wheel = json_decode($data, true);

        if (!empty($wheel['spins'])) {
            foreach ($wheel['spins'] as $t) {
                if (!empty($t['is_win'])) {
                    $ticketMap[trim((string)$t['ticket'])] = 'spin';
                }
            }
        }
    }

    /**
     * ======================
     * SLOTS
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'slots-thankyou-container')]//table//tr") as $tr) {
        $td = $xpath->query(".//td[1]", $tr)->item(0);
        if ($td) {
            $ticket = trim($td->nodeValue);
            if (!isset($ticketMap[$ticket])) {
                $ticketMap[$ticket] = 'slots';
            }
        }
    }

    /**
     * ======================
     * FOOTBALL
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'football-thankyou-container')]//table//tr") as $tr) {
        $td = $xpath->query(".//td[1]", $tr)->item(0);
        if ($td) {
            $ticket = trim($td->nodeValue);
            if (!isset($ticketMap[$ticket])) {
                $ticketMap[$ticket] = 'football';
            }
        }
    }

    /**
     * ======================
     * RACECAR
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'racecar-game-data')]") as $node) {
        $tickets = json_decode($node->getAttribute('data-tickets'), true);
        if (is_array($tickets)) {
            foreach ($tickets as $t) {
                $ticket = giveaways_ticket_number_of($t);
                if ($ticket !== '' && !isset($ticketMap[$ticket])) {
                    $ticketMap[$ticket] = 'racecar';
                }
            }
        }
    }
    /**
     * ======================
     * DARTS
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'darts-game-data')]") as $node) {
        $tickets = json_decode($node->getAttribute('data-tickets'), true);
        if (is_array($tickets)) {
            foreach ($tickets as $t) {
                $ticket = giveaways_ticket_number_of($t);
                if ($ticket !== '' && !isset($ticketMap[$ticket])) {
                    $ticketMap[$ticket] = 'darts';
                }
            }
        }
    }

    /**
     * ======================
     * BALLOON POP
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'balloon-pop-game-data')]") as $node) {
        $tickets = json_decode($node->getAttribute('data-tickets'), true);
        if (is_array($tickets)) {
            foreach ($tickets as $t) {
                $ticket = giveaways_ticket_number_of($t);
                if ($ticket !== '' && !isset($ticketMap[$ticket])) {
                    $ticketMap[$ticket] = 'balloon-pop';
                }
            }
        }
    }

    /**
     * ======================
     * BOXING
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'boxing-game-data')]") as $node) {
        $tickets = json_decode($node->getAttribute('data-tickets'), true);
        if (is_array($tickets)) {
            foreach ($tickets as $t) {
                $ticket = giveaways_ticket_number_of($t);
                if ($ticket !== '' && !isset($ticketMap[$ticket])) {
                    $ticketMap[$ticket] = 'boxing';
                }
            }
        }
    }
    /**
     * ======================
     * GRABCLAW
     * ======================
     */
    foreach ($xpath->query("//*[contains(@class, 'grabclaw-thankyou-container')]//table//tr") as $tr) {
        $td = $xpath->query(".//td[1]", $tr)->item(0);
        if ($td) {
            $ticket = trim($td->nodeValue);
            if (!isset($ticketMap[$ticket])) {
                $ticketMap[$ticket] = 'grabclaw';
            }
        }
    }
    /**
     * ======================
     * INSTANT REVEAL (fallback)
     * ======================
     */
    foreach ($xpath->query("//tr[contains(@class, 'instant-reveal-thankyou-container')]//table//tr") as $tr) {
        $td = $xpath->query(".//td[1]", $tr)->item(0);
        if ($td) {
            $ticket = trim($td->nodeValue);
            if (!isset($ticketMap[$ticket])) {
                $ticketMap[$ticket] = 'instant-reveal';
            }
        }
    }

    /**
     * ======================
     * APPLY CLASSES ONLY (NO REMOVAL)
     * ======================
     */
    foreach ($xpath->query("//tr[contains(@class, 'prize-thankyou-container')]") as $row) {

        $ticketNode = $xpath->query(".//*[contains(@class, 'lty-instant-win-gift-product-message')]//b", $row)->item(0);

        if (!$ticketNode) {
            continue;
        }

        $ticket = trim($ticketNode->nodeValue);

        if (isset($ticketMap[$ticket])) {

            $source = $ticketMap[$ticket];

            $existingClass = $row->getAttribute('class');
            $row->setAttribute(
                'class',
                trim($existingClass . ' prize-from-' . $source)
            );
        }
    }

    return $dom->saveHTML();
}
/**
 * On order pages, remove the "Ticket Number(s)" meta for items that belong
 * to a game category — those items show a play button instead.
 * This is PHP-side so it works regardless of CSS caching.
 */
add_filter( 'woocommerce_order_item_get_formatted_meta_data', function ( $formatted_meta, $item ) {

    // Only run on frontend order pages
    if ( is_admin() ) return $formatted_meta;
    if ( ! is_wc_endpoint_url( 'view-order' ) && ! is_order_received_page() ) return $formatted_meta;

    $product = $item->get_product();
    if ( ! $product ) return $formatted_meta;

    // All game category slugs — items in these categories show a play button
    $game_cats = apply_filters( 'giveaways_game_category_slugs', [
        'instant-reveal',
        'scratchcard',
        'spin',
        'slots',
        'racecar',
        'football',
        'claw-machine',
        'balloon-pop',
        'darts',
        'boxing',
    ] );

    $is_game = false;
    foreach ( $game_cats as $cat ) {
        if ( has_term( $cat, 'product_cat', $product->get_id() ) ) {
            $is_game = true;
            break;
        }
    }

    if ( ! $is_game ) return $formatted_meta;

    // Strip any meta whose label contains "ticket" (case-insensitive)
    foreach ( $formatted_meta as $meta_id => $meta ) {
        $label = strtolower( wp_strip_all_tags( $meta->display_key ?? '' ) );
        if ( strpos( $label, 'ticket' ) !== false ) {
            unset( $formatted_meta[ $meta_id ] );
        }
    }

    return $formatted_meta;

}, 10, 2 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function gaa_is_order_paid( int $order_id ): bool {

    if ( ! $order_id ) return false;

    $order = wc_get_order( $order_id );

    if ( ! $order ) return false;

    return $order->is_paid();
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function gaa_current_user_owns_order( int $order_id ): bool {

    $order = wc_get_order( $order_id );

    if ( ! $order ) return false;

    // Guests: WooCommerce sets the order key in the URL — verified by WC itself.
    if ( ! is_user_logged_in() ) return true;

    return (int) $order->get_customer_id() === get_current_user_id();
}

add_action( 'template_redirect', function () {

    if ( ! is_wc_endpoint_url( 'order-received' ) ) return;

    $order_id = absint( get_query_var( 'order-received' ) );

    if ( ! $order_id ) return;

    if ( ! gaa_is_order_paid( $order_id ) || ! gaa_current_user_owns_order( $order_id ) ) {
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

} );

/**
 * Quick Edit: enforce single-selection for game categories.
 * When the user checks a game category checkbox, any other game
 * category checkbox in the same quick-edit panel is unchecked.
 */
add_action( 'admin_footer', function () {

    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-product' ) return;

    // The game category slugs — keep in sync with giveaways_game_category_slugs filter
    $game_cats = apply_filters( 'giveaways_game_category_slugs', [
        'instant-reveal',
        'scratchcard',
        'spin',
        'slots',
        'racecar',
        'football',
        'claw-machine',
        'balloon-pop',
        'darts',
        'boxing',
    ] );

    // Resolve each slug to its term ID so we can match checkboxes by value
    $game_term_ids = [];
    foreach ( $game_cats as $slug ) {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( $term ) {
            $game_term_ids[] = (int) $term->term_id;
        }
    }

    if ( empty( $game_term_ids ) ) return;

    $ids_json = json_encode( $game_term_ids );
    ?>
    <script>
    (function () {
        var gameIds = <?php echo wp_json_encode( $game_term_ids ); ?>;

        /**
         * Attach mutual-exclusion listeners to all game-category checkboxes
         * inside a given quick-edit row element.
         */
        function bindQuickEditRow( row ) {
            var checkboxes = row.querySelectorAll(
                'ul.product_cat-checklist input[type="checkbox"]'
            );

            checkboxes.forEach( function ( cb ) {
                if ( gameIds.indexOf( parseInt( cb.value, 10 ) ) === -1 ) return;

                cb.addEventListener( 'change', function () {
                    if ( ! this.checked ) return;

                    // Uncheck every other game category in this same row
                    checkboxes.forEach( function ( other ) {
                        if (
                            other !== cb &&
                            gameIds.indexOf( parseInt( other.value, 10 ) ) !== -1
                        ) {
                            other.checked = false;
                        }
                    } );
                } );
            } );
        }

        // WooCommerce re-renders the quick-edit row each time it opens.
        // Watch for the inline-edit row appearing in the products table.
        var observer = new MutationObserver( function ( mutations ) {
            mutations.forEach( function ( mutation ) {
                mutation.addedNodes.forEach( function ( node ) {
                    if ( node.nodeType !== 1 ) return;

                    // The quick-edit row is a <tr> with class "inline-edit-row"
                    if ( node.classList && node.classList.contains( 'inline-edit-row' ) ) {
                        bindQuickEditRow( node );
                    }

                    // Also catch if it appears nested inside an added node
                    var rows = node.querySelectorAll
                        ? node.querySelectorAll( 'tr.inline-edit-row' )
                        : [];
                    rows.forEach( bindQuickEditRow );
                } );
            } );
        } );

        var table = document.getElementById( 'the-list' );
        if ( table ) {
            observer.observe( table, { childList: true, subtree: true } );
        }
    })();
    </script>
    <?php
} );