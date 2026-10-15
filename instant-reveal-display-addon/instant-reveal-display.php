<?php
/**
 * Instant Reveal Display — bootstrap
 *
 * admin.php is superseded by EIWR_Admin_Page and is no longer included.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EIWR_DIR', plugin_dir_path( __FILE__ ) );
define( 'EIWR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cache-busting version for this add-on's assets.
 *
 * The three enqueues used to pass a hardcoded '1.1.0'. Because that string never
 * changed, instant-reveal.css kept the same URL across every release, so browsers
 * and CDNs served whatever copy they cached the first time — indefinitely. That
 * turns a shipped CSS fix into a fix nobody receives: the corrected file sits on
 * the server while every existing browser carries on using the old one. That is
 * exactly how the unscoped `.hidden` rule — which hid WooCommerce's entire
 * Product data type dropdown — survived the update to 3.0.25.
 *
 * Tied to the plugin version instead, so every release busts the cache. Both
 * constants are checked because the white-label core defines only
 * GIVEAWAYS_WL_VERSION (game-addons-config.php) and the CompKit core only
 * GIVEAWAYS_VERSION — the same edition split that broke the core update check.
 */
function eiwr_asset_version(): string {
	foreach ( [ 'GIVEAWAYS_WL_VERSION', 'GIVEAWAYS_VERSION' ] as $const ) {
		if ( defined( $const ) && (string) constant( $const ) !== '' ) {
			return (string) constant( $const );
		}
	}
	// Last resort: still changes when the file does, which beats a fixed string.
	$mtime = @filemtime( EIWR_DIR . 'instant-reveal.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is not actionable here
	return $mtime ? (string) $mtime : '0';
}

require_once EIWR_DIR . 'includes/class-settings.php';
require_once EIWR_DIR . 'includes/class-admin-page.php';
require_once EIWR_DIR . 'includes/class-product-panel.php';
require_once EIWR_DIR . 'includes/class-frontend.php';

add_action( 'woocommerce_init', function () {
    if ( ! taxonomy_exists( 'product_cat' ) ) return;
    if ( ! term_exists( 'instant-reveal', 'product_cat' ) ) {
        wp_insert_term( 'Instant Reveal', 'product_cat', [
            'slug'        => 'instant-reveal',
            'description' => 'Products eligible for Instant Prize Reveal',
        ] );
    }
}, 5 );

EIWR_Admin_Page::register();
EIWR_Product_Panel::register();
EIWR_Frontend::register();

/* ── Legacy function shims ─────────────────────────────────────── */

function eiwr_appearance_defaults()                   { return EIWR_Settings::defaults(); }
function eiwr_get_appearance()                        { return EIWR_Settings::get(); }
function eiwr_lighten_hex( $hex, $amount = 0.22 )     { return EIWR_Settings::lighten_hex( $hex, (float) $amount ); }
function eiwr_is_licensed()                           { return EIWR_Frontend::is_licensed(); }
function eiwr_game_slugs()                            { return EIWR_Frontend::game_slugs(); }
function eiwr_is_game_category_by_slugs( $slugs )     { return EIWR_Frontend::is_game_category_by_slugs( (array) $slugs ); }
function eiwr_is_instant_reveal_only_by_slugs( $slugs ) { return EIWR_Frontend::is_instant_reveal_only_by_slugs( (array) $slugs ); }
function eiwr_is_game_category( $product_id )         { return EIWR_Frontend::is_game_category( (int) $product_id ); }
function eiwr_apply_coupon_handler()                  { EIWR_Frontend::apply_coupon_ajax(); }
function eiwr_get_instant_win_data_for_order_product( WC_Order $order, $product_id ) {
    return EIWR_Frontend::get_instant_win_data( $order, $product_id );
}
