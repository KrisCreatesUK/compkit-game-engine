<?php
/**
 * Instant Reveal & Orders Email
 *
 * Version: 1.6.4
 *
 * Versioned independently of the plugin, the same way each game is: this module
 * moves on its own schedule, and the settings page shows the number so a support
 * conversation can start from what is actually installed.
 *
 * Read it through COMPKIT_EMAIL_VERSION rather than by parsing this header —
 * unlike the premium games there is no module entry file for get_file_data() to
 * read, because this ships inside the free core.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'COMPKIT_EMAIL_VERSION' ) ) {
    define( 'COMPKIT_EMAIL_VERSION', '1.6.4' );
}
/*
Temporary support debugging:
Uncomment if a client needs CompKit diagnostics
and sends back wp-content/debug.log

define( 'COMPKIT_DEBUG', true );
*/

if ( ! defined( 'COMPKIT_DEBUG' ) ) {
    define( 'COMPKIT_DEBUG', false );
}

/*
 * How long an order's emails wait for its games to be played.
 *
 * Holding is open-ended by nature: a customer who never opens their tickets
 * would otherwise never receive the order confirmation or their ticket numbers.
 * After this long the emails go out anyway, unplayed games and all.
 *
 * Override in wp-config.php, or per site with the compkit_email_hold_timeout
 * filter.
 */
if ( ! defined( 'COMPKIT_EMAIL_HOLD_TIMEOUT' ) ) {
    define( 'COMPKIT_EMAIL_HOLD_TIMEOUT', 6 * HOUR_IN_SECONDS );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function compkit_log( $message, $context = [] ) {

    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }

    if ( ! defined( 'COMPKIT_DEBUG' ) || ! COMPKIT_DEBUG ) {
        return;
    }

    if ( ! empty( $context ) ) {
        $message .= ' | ' . wp_json_encode( $context );
    }

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    error_log( '[CompKit] ' . $message );
}


/**
 * Order meta, HPOS-safe.
 *
 * The reveal bookkeeping used to be written with update_post_meta() and read
 * back with get_post_meta(). With WooCommerce's High-Performance Order Storage
 * turned on an order has no post row, so those markers were written somewhere
 * the rest of the module never looked: every reveal counted as "not revealed",
 * the order never completed, and the held emails were never released.
 *
 * Read through the order CRUD first and fall back to post meta so markers
 * written by older builds still count.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function compkit_order_meta_get( $order_id, $key ) {

    $order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );

    if ( $order ) {
        $value = $order->get_meta( $key, true );
        if ( '' !== $value && null !== $value && array() !== $value ) {
            return $value;
        }
    }

    $id = $order instanceof WC_Order ? $order->get_id() : absint( $order_id );

    return $id ? get_post_meta( $id, $key, true ) : '';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function compkit_order_meta_set( $order_id, $key, $value ) {

    $order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );

    if ( ! $order ) {
        return false;
    }

    $order->update_meta_data( $key, $value );
    $order->save_meta_data();

    return true;
}

/**
 * The tickets in an order that belong to a game product.
 *
 * Only these can ever be revealed, so only these may be counted when deciding
 * whether the order is finished. Counting every lottery ticket in the order
 * held the emails forever as soon as the basket mixed a game with a plain
 * giveaway product.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function compkit_game_ticket_ids_for_order( $order_id ) {

    $ticket_ids = (array) compkit_order_meta_get( $order_id, 'lty_ticket_ids_in_order' );
    $ticket_ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );

    if ( empty( $ticket_ids ) || ! function_exists( 'lty_get_lottery_ticket' ) ) {
        return $ticket_ids;
    }

    $game_ticket_ids = array();

    foreach ( $ticket_ids as $ticket_id ) {

        $ticket = lty_get_lottery_ticket( $ticket_id );
        if ( ! $ticket || ! method_exists( $ticket, 'get_product' ) ) {
            continue;
        }

        $product = $ticket->get_product();

        // "No Instant Wins" products carry tickets with nothing to reveal. Counting
        // them as expected reveals deadlocked the order: the count could never be
        // reached, so the emails were held for good.
        if ( $product && compkit_product_is_game_category( $product ) && ! compkit_product_is_non_instant( $product ) ) {
            $game_ticket_ids[] = $ticket_id;
        }
    }

    if ( count( $game_ticket_ids ) !== count( $ticket_ids ) ) {
        compkit_log(
            'Order mixes game and non-game tickets',
            array(
                'order_id'    => $order_id instanceof WC_Order ? $order_id->get_id() : absint( $order_id ),
                'game'        => count( $game_ticket_ids ),
                'all_tickets' => count( $ticket_ids ),
            )
        );
    }

    return $game_ticket_ids;
}


if ( ! class_exists( 'CompKit_Email_Handler' ) ) {

    class CompKit_Email_Handler {
        const OPTION_TICKET_BG        = 'playkit_ticket_bg_color';
        const OPTION_TICKET_TEXT      = 'playkit_ticket_text_color';
        const OPTION_WIN_TICKET_BG    = 'playkit_win_ticket_bg_color';
        const OPTION_WIN_TICKET_TEXT  = 'playkit_win_ticket_text_color';
        const OPTION_DISCLAIMER             = 'playkit_email_disclaimer';
        const OPTION_DISCLAIMER_INSTANT     = 'playkit_email_disclaimer_instant';
        const OPTION_DISCLAIMER_NON_INSTANT = 'playkit_email_disclaimer_non_instant';
        const META_SENT                     = '_playkit_instant_email_sent';
        const META_RELEASED = '_playkit_order_emails_released';
        const META_HELD_LTY = '_playkit_held_giveaway_emails';
        const META_HELD_SINCE = '_playkit_order_emails_held_since';
        const CRON_RELEASE    = 'compkit_release_order_emails';

        /**
         * The Giveaways (Lottery for WooCommerce) hooks whose notifications fire
         * the moment an order's tickets are confirmed. Every email attached to
         * them is held back while a game in the order is still unplayed.
         */
        protected static $lty_order_hooks = array(
            'lty_lottery_ticket_confirmed',
            'lty_lottery_incorrect_answer_in_order',
        );

        const OPTION_ENABLED = 'playkit_reveal_email_enabled';
        const OPTION_SUBJECT = 'playkit_reveal_email_subject';
        const OPTION_MESSAGE = 'playkit_reveal_email_message';
        protected static $force_release = false;
        public function __construct() {


            add_action( 'admin_init', array( $this, 'maybe_set_defaults' ) );
            // Admin menu, assets and the settings screen now live in
            // CompKit_Email_Admin_Page (see includes/). The old admin_menu(),
            // admin_assets() and settings_page() methods below are retained but
            // no longer hooked.

            add_action('wp_ajax_lty_reveal_instant_win', [$this,'ajax_reveal_email']);
            add_action('wp_ajax_nopriv_lty_reveal_instant_win', [$this,'ajax_reveal_email']);

            // Shared reveal hook — games with their own reveal AJAX (racecar, balloon
            // pop, darts) fire this so they also send the win email and release the
            // held order emails, exactly like the games that use lty_reveal_instant_win.
            add_action('compkit_reveal_ticket', [$this,'process_ticket_reveal'], 10, 2);

            // Safety net: release an order's held emails once the hold window is up,
            // however much of it was never played.
            add_action( self::CRON_RELEASE, [ $this, 'release_order_emails_on_timeout' ], 10, 1 );

            // Hold the Giveaways plugin's own order emails (ticket confirmation,
            // instant winner, wrong answer) while any game in the order is still
            // unplayed. They are re-fired verbatim when the last ticket is revealed.
            foreach ( self::$lty_order_hooks as $lty_hook ) {
                add_action( $lty_hook, [ $this, 'maybe_hold_giveaway_emails' ], 1, 4 );
            }

            add_filter('woocommerce_email_enabled_customer_processing_order', array($this,'maybe_block_wc_email'),10,2);
            add_filter('woocommerce_email_enabled_customer_completed_order', array($this,'maybe_block_wc_email'),10,2);
            add_filter('woocommerce_email_enabled_customer_on_hold_order', array($this,'maybe_block_wc_email'),10,2);
            // Giveaways instant win email (correct ID)
            add_filter(
                'woocommerce_email_enabled_customer_instant_winner_order',
                array( $this, 'maybe_block_instant_email' ),
                10,
                2
            );
            // Giveaways instant win email
            add_filter('woocommerce_email_enabled_customer_instant_win_notification', array($this,'maybe_block_instant_email'),10,2);
        }



        /**
         * Accepts one ticket, or every ticket a single click revealed.
         *
         * The free Instant Reveal Display opens a whole order item at once. Sent
         * as one request per ticket they arrive in parallel, and each one counted
         * the reveals while the others were still writing theirs: every request
         * saw only its own, none ever saw the full set, and the order was never
         * released even though every ticket had been revealed and every win email
         * had gone out. Handled in one request the marks are sequential, so the
         * last one sees them all.
         */
        public function ajax_reveal_email() {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- public-facing AJAX, no nonce available
            $order_id = absint( $_POST['order_id'] ?? 0 );

            $raw = $_POST['ticket_ids'] ?? ( $_POST['ticket_id'] ?? '' );
            $raw = is_array( $raw ) ? $raw : explode( ',', (string) wp_unslash( $raw ) );
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            $ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

            compkit_log( 'Reveal request', [ 'order_id' => $order_id, 'ticket_ids' => $ticket_ids ] );

            foreach ( $ticket_ids as $ticket_id ) {
                $this->process_ticket_reveal( $order_id, $ticket_id );
            }

            wp_die();
        }

        /**
         * Mark a ticket as revealed, send its win email once, and release the held
         * order emails when every ticket in the order has been revealed.
         *
         * Safe to call directly — it never outputs or dies — so a game's own reveal
         * handler can run it via:  do_action( 'compkit_reveal_ticket', $order_id, $ticket_id );
         */
        public function process_ticket_reveal( $order_id, $ticket_id ) {

            $order_id  = absint( $order_id );
            $ticket_id = absint( $ticket_id );

            compkit_log( 'Reveal received', [ 'order_id' => $order_id, 'ticket_id' => $ticket_id ] );

            if ( ! $order_id || ! $ticket_id ) {
                compkit_log( 'Reveal ignored, missing order or ticket id' );
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                compkit_log( 'Reveal ignored, order not found', [ 'order_id' => $order_id ] );
                return;
            }

            $all_tickets = (array) compkit_order_meta_get( $order, 'lty_ticket_ids_in_order' );
            $all_tickets = array_values( array_filter( array_map( 'absint', $all_tickets ) ) );

            if ( count( $all_tickets ) < 1 ) {
                compkit_log( 'Reveal ignored, order holds no ticket meta yet', [ 'order_id' => $order_id ] );
                return;
            }

            // Verify this ticket belongs to the order.
            if ( ! in_array( $ticket_id, $all_tickets, true ) ) {
                compkit_log(
                    'Reveal ignored, ticket is not in this order',
                    [ 'order_id' => $order_id, 'ticket_id' => $ticket_id ]
                );
                return;
            }

            // Count each ticket once.
            $counted_key = '_playkit_revealed_ticket_' . $ticket_id;
            if ( compkit_order_meta_get( $order, $counted_key ) ) {
                compkit_log( 'Reveal already counted', [ 'order_id' => $order_id, 'ticket_id' => $ticket_id ] );
                return;
            }
            compkit_order_meta_set( $order, $counted_key, 1 );

            compkit_log( 'Ticket revealed', [ 'ticket_id' => $ticket_id, 'order_id' => $order_id ] );

            // Confirmed win?
            $is_win = false;
            if ( function_exists('lty_get_instant_winner_log_id_by_ticket_id') && function_exists('lty_get_instant_winner_log') ) {
                $win_log_id = lty_get_instant_winner_log_id_by_ticket_id( $ticket_id );
                if ( $win_log_id ) {
                    $win_log = lty_get_instant_winner_log( $win_log_id );
                    $is_win  = $win_log && method_exists( $win_log, 'has_status' ) && $win_log->has_status( 'lty_won' );
                }
            }

            // Send the win email once per winning ticket. The "already sent" flag
            // is written only after the mail actually goes out — setting it first
            // meant any bail-out inside the sender (mail off, no address on the
            // ticket) burned the one chance that ticket had of being emailed.
            if ( ! $is_win ) {

                compkit_log( 'Revealed ticket has no confirmed win', [ 'ticket_id' => $ticket_id ] );

            } elseif ( get_post_meta( $ticket_id, self::META_SENT, true ) ) {

                compkit_log( 'Win email already sent for this ticket', [ 'ticket_id' => $ticket_id ] );

            } else {

                $ticket = function_exists('lty_get_lottery_ticket') ? lty_get_lottery_ticket( $ticket_id ) : null;

                if ( ! $ticket ) {
                    compkit_log( 'Win email skipped, ticket object not available', [ 'ticket_id' => $ticket_id ] );
                } elseif ( $this->send_instant_reveal_email( $ticket, $order_id ) ) {
                    update_post_meta( $ticket_id, self::META_SENT, 1 );
                }
            }

            // Release the held order emails once every ticket is revealed.
            if ( $this->order_all_reveals_completed( $order_id ) ) {
                if ( ! compkit_order_meta_get( $order, self::META_RELEASED ) ) {
                    $this->send_delayed_emails_for_order( $order_id );
                }
            }
        }



        protected function order_all_reveals_completed( $order_id ) {

            $order = wc_get_order( $order_id );
            if ( ! $order ) return false;

            /* Reveals can still land in separate requests - a player clicking
               through a game quickly, or two tabs open. Re-read the meta from the
               database rather than trusting the copy this request loaded, so a
               mark written moments ago by another request is counted here. */
            $order->read_meta_data( true );

            /*
            ============================
            1. THE TICKETS THAT CAN BE REVEALED
            ============================
            Only tickets on a game product are playable, so only those decide
            when the order is finished.
            */

            $ticket_ids = compkit_game_ticket_ids_for_order( $order );

            compkit_log(
                'Playable ticket meta',
                [
                    'order_id'   => absint( $order_id ),
                    'ticket_ids' => $ticket_ids,
                ]
            );

            /*
            ============================
            2. COUNT REVEALED TICKETS
            ============================
            */

            $revealed_ids = [];

            foreach ( $ticket_ids as $ticket_id ) {
                if ( compkit_order_meta_get( $order, '_playkit_revealed_ticket_' . $ticket_id ) ) {
                    $revealed_ids[] = $ticket_id;
                }
            }

            $revealed = count( $revealed_ids );

            compkit_log(
                'Revealed ticket IDs',
                [
                    'order_id'     => absint( $order_id ),
                    'revealed_ids' => $revealed_ids,
                ]
            );

            /*
            ============================
            3. EXPECTED TOTAL
            ============================
            */

            $expected = count( $ticket_ids );

            // If order has games but tickets not registered yet, block emails
            if ( $expected < 1 && compkit_order_has_playable_games( $order_id ) ) {

            compkit_log(
                'Holding emails, game products found but no ticket meta',
                [
                    'order_id' => absint( $order_id ),
                ]
            );

                return false;
            }

            compkit_log(
                'Reveal progress',
                [
                    'order_id' => absint( $order_id ),
                    'revealed' => absint( $revealed ),
                    'expected' => absint( $expected ),
                ]
            );

            /*
            ============================
            4. FINAL DECISION
            ============================
            */

            if ( $expected > 0 && $revealed >= $expected ) {

            compkit_log(
                'All tickets revealed',
                [
                    'order_id' => absint( $order_id ),
                ]
            );

                return true;
            }

            return false;
        }


        public function admin_assets( $hook ) {

            // Only load on our submenu page
            if ( empty($_GET['page']) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'playkit-reveal-email' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return;
            }

            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );

            wp_enqueue_editor();

            // init pickers
            wp_add_inline_script(
                'wp-color-picker',
                'jQuery(function($){ $(".playkit-color").wpColorPicker(); });'
            );
        }
        public function maybe_set_defaults() {
            if ( get_option( self::OPTION_TICKET_BG, '' ) === '' )
                update_option( self::OPTION_TICKET_BG, '#2aa8f8' );

            if ( get_option( self::OPTION_TICKET_TEXT, '' ) === '' )
                update_option( self::OPTION_TICKET_TEXT, '#000000' );

            if ( get_option( self::OPTION_WIN_TICKET_BG, '' ) === '' )
                update_option( self::OPTION_WIN_TICKET_BG, '#d4af37' );

            if ( get_option( self::OPTION_WIN_TICKET_TEXT, '' ) === '' )
                update_option( self::OPTION_WIN_TICKET_TEXT, '#000000' );
            if ( get_option( self::OPTION_DISCLAIMER_INSTANT, '' ) === '' ) {
                update_option(
                    self::OPTION_DISCLAIMER_INSTANT,
                    'Game replay available, prizes are not re-awarded. Good luck in the main competition draw. Below are your entry details.'
                );
            }

            if ( get_option( self::OPTION_DISCLAIMER_NON_INSTANT, '' ) === '' ) {
                update_option(
                    self::OPTION_DISCLAIMER_NON_INSTANT,
                    'Good luck in the main competition draw. Below are your entry details.'
                );
            }

            if ( get_option( self::OPTION_ENABLED, '' ) === '' )
                update_option( self::OPTION_ENABLED, 'yes' );

            if ( get_option( self::OPTION_SUBJECT, '' ) === '' )
                update_option( self::OPTION_SUBJECT, 'INSTANT WIN REVEALED ON *PRODUCT' );

            if ( get_option( self::OPTION_MESSAGE, '' ) === '' ) {

                update_option( self::OPTION_MESSAGE, '
                <p>Hi {user_name},</p>

                <p><strong>Congratulations</strong>, you just revealed an Instant Win prize.</p>

                <p><strong>{prize_name}</strong></p>

                <p>{prize_image}</p>

                <p>{prize_desc}</p>

                <p><strong>Ticket Number:</strong> {ticket_number}</p>

                <p>Thanks for playing,<br>{site_name}</p>
                ');
                            }
        }

        public function admin_menu() {

        $parent_slug = 'giveaways-addons';

        add_submenu_page(
            $parent_slug,
            'Instant Reveal & Orders Email',
            'Instant Reveal & Orders Email',
            'manage_woocommerce',
            'playkit-reveal-email',
            array( $this, 'settings_page' )
        );
        }

        public function settings_page() {

            if ( isset($_POST['playkit_reveal_email_submit']) && check_admin_referer('playkit_save_email') ) {

                update_option(self::OPTION_ENABLED, isset($_POST['enabled']) ? 'yes' : 'no');
                update_option(self::OPTION_SUBJECT, wp_kses_post( wp_unslash( $_POST['subject'] ?? '' ) ));
                update_option(self::OPTION_MESSAGE, wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) ));
                // NEW: save colours (sanitize as hex)
                update_option(self::OPTION_TICKET_BG, sanitize_hex_color( wp_unslash( $_POST['ticket_bg'] ?? '' ) ) ?: '#2aa8f8');
                update_option(
                    self::OPTION_TICKET_TEXT,
                    sanitize_text_field( wp_unslash( $_POST['ticket_text'] ?? '#000000' ) )
                );

                update_option(
                    self::OPTION_WIN_TICKET_TEXT,
                    sanitize_text_field( wp_unslash( $_POST['win_ticket_text'] ?? '#000000' ) )
                );
                update_option(
                    self::OPTION_DISCLAIMER_INSTANT,
                    wp_kses_post( wp_unslash( $_POST['disclaimer_instant'] ?? '' ) )
                );

                update_option(
                    self::OPTION_DISCLAIMER_NON_INSTANT,
                    wp_kses_post( wp_unslash( $_POST['disclaimer_non_instant'] ?? '' ) )
                );

                update_option(self::OPTION_WIN_TICKET_BG, sanitize_hex_color( wp_unslash( $_POST['win_ticket_bg'] ?? '' ) ) ?: '#d4af37');

                echo '<div class="updated"><p>Settings saved.</p></div>';
            }

            $enabled = get_option(self::OPTION_ENABLED,'yes');
            $subject = get_option(self::OPTION_SUBJECT);
            $message = get_option(self::OPTION_MESSAGE);
            $ticket_bg       = get_option(self::OPTION_TICKET_BG, '#2aa8f8');
            $ticket_text     = get_option(self::OPTION_TICKET_TEXT, '#000000');
            $win_ticket_bg   = get_option(self::OPTION_WIN_TICKET_BG, '#d4af37');
            $win_ticket_text = get_option(self::OPTION_WIN_TICKET_TEXT, '#000000');

            ?>
            <div class="wrap">
                <h1>Instant Reveal & Orders Email Settings</h1>

                <div style="padding:15px; background:#f1f1f1; border-left:5px solid #0aae12; margin-bottom:20px; display:flex; gap:12px; align-items:center;">
                    <div class="edit-email-admin-icon"></div>
                    <div>
                    <h2 style="margin-top:0;">Quick Start Guide</h2>
                    <p class="description important">
                        <strong>Non-instant win competitions:</strong> Make sure these competitions are in the "No Instant Wins" product category. If not, ticket numbers will not be included in customer emails.
                    </p>
                    <p class="description" style="margin-top:5px;">
                        <strong>What this does:</strong><br>
                        When enabled, customer order and ticket emails are held back until all instant win games have been played.
                    </p>

                    <p class="description">
                        Customers will <strong>not see any wins in their initial emails</strong>. Instead, they must play their Scratchcards, Spin the Wheel, or Slots games first.
                    </p>

                    <p class="description">
                        <strong>How emails are sent:</strong>
                    </p>

                    <ul style="margin-left:18px;">
                        <li>An email is sent immediately each time an instant win is revealed.</li>
                        <li>Once all instant wins in an order have been revealed, the normal order and ticket emails are released.</li>
                    </ul>

                    <p class="description">
                        <strong>Email appearance:</strong><br>
                        All related emails use a custom layout with ticket number badges, winning ticket highlights, game replay buttons, and instant win prize details.
                    </p>

                    <p class="description">
                        The settings below control both:
                    </p>

                    <ul style="margin-left:18px;">
                        <li>The instant win reveal emails sent per prize</li>
                        <li>The colours and layout of tickets and prizes shown in all customer emails</li>
                    </ul>

                    <p class="description">
                        This ensures customers only discover their wins after playing the games.
                    </p>
                </div>
            </div>

                <form method="post">
                    <?php wp_nonce_field('playkit_save_email'); ?>

                    <table class="form-table">
                    <tr>
                        <th>Customer Email Message, Instant Win Products</th>
                        <td>
                            <?php
                            wp_editor(
                                get_option( self::OPTION_DISCLAIMER_INSTANT, '' ),
                                'playkit_email_disclaimer_instant_editor',
                                [
                                    'textarea_name' => 'disclaimer_instant',
                                    'textarea_rows' => 6,
                                    'media_buttons' => false,
                                    'teeny'         => true,
                                    'quicktags'     => true,
                                    'tinymce'       => [
                                        'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,removeformat',
                                        'toolbar2' => '',
                                    ],
                                ]
                            );
                            ?>
                            <p class="description">
                                Displayed in customer emails for instant win products. HTML is allowed.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>Customer Email Message, Non-Instant Products</th>
                        <td>
                            <?php
                            wp_editor(
                                get_option( self::OPTION_DISCLAIMER_NON_INSTANT, '' ),
                                'playkit_email_disclaimer_non_instant_editor',
                                [
                                    'textarea_name' => 'disclaimer_non_instant',
                                    'textarea_rows' => 6,
                                    'media_buttons' => false,
                                    'teeny'         => true,
                                    'quicktags'     => true,
                                    'tinymce'       => [
                                        'toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist,removeformat',
                                        'toolbar2' => '',
                                    ],
                                ]
                            );
                            ?>
                            <p class="description">
                                Displayed in customer emails for non-instant products. HTML is allowed.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>Email Ticket Badge Colours</th>
                        <td>
                            <p style="margin:0 0 8px 0;"><strong>Normal ticket</strong></p>
                            <label style="display:inline-block; margin-right:18px;">
                                Ticket BG<br>
                                <input type="text" name="ticket_bg" class="playkit-color" value="<?php echo esc_attr($ticket_bg); ?>">
                            </label>
                            <label style="display:inline-block;">
                                Ticket Text<br>
                                <input type="text" name="ticket_text" class="playkit-color" value="<?php echo esc_attr($ticket_text); ?>">
                            </label>

                            <p style="margin:14px 0 8px 0;"><strong>Winning ticket</strong></p>
                            <label style="display:inline-block; margin-right:18px;">
                                Win Ticket BG<br>
                                <input type="text" name="win_ticket_bg" class="playkit-color" value="<?php echo esc_attr($win_ticket_bg); ?>">
                            </label>
                            <label style="display:inline-block;">
                                Win Ticket Text<br>
                                <input type="text" name="win_ticket_text" class="playkit-color" value="<?php echo esc_attr($win_ticket_text); ?>">
                            </label>
                        </td>
                    </tr>

                        <tr>
                            <th>Enable Email</th>
                            <td>
                                <input type="checkbox" name="enabled" <?php checked($enabled,'yes'); ?>> Enable Instant Reveal Emails
                            </td>
                        </tr>

                        <tr>
                            <th>Subject</th>
                            <td>
                                <input type="text" class="regular-text" name="subject" value="<?php echo esc_attr($subject); ?>">
                                <p>{product_name} becomes giveaway name</p>
                            </td>
                        </tr>

                        <tr>
                            <th>Message Template</th>
                            <td>
<textarea name="message" rows="12" class="large-text"><?php echo esc_textarea($message); ?></textarea>
<p>Available tags: {site_name} {user_name} {ticket_number} {prize_name} {prize_desc} {prize_image}</p>
                            </td>
                        </tr>

                    </table>

                    <p><button class="button button-primary" name="playkit_reveal_email_submit">Save</button></p>
                </form>
            </div>
            <?php
        }

        public function maybe_block_wc_email( $enabled, $order ) {

            /* While releasing, and once released, this filter steps aside and
               WooCommerce's own setting decides. It used to return true here,
               which also switched ON an email the shop had turned off in
               WooCommerce > Settings > Emails - so a disabled Processing Order
               email still went out after the reveal. The only job of this filter
               is to HOLD emails until the reveal, never to enable one. */
            if ( self::$force_release ) {
                return $enabled;
            }

            if ( ! $order instanceof WC_Order ) {
                return $enabled;
            }

            $order_id = $order->get_id();

            // IMPORTANT: once released, never block again
            if ( compkit_order_meta_get( $order, self::META_RELEASED ) ) {
                return $enabled;
            }

            if ( compkit_order_has_playable_games( $order_id ) ) {

                if ( ! $this->order_all_reveals_completed( $order_id ) ) {
                    $this->schedule_hold_timeout( $order_id );
                    return false;
                }
            }

            return $enabled;
        }








        public function maybe_block_instant_email( $enabled, $order_id ) {

            // NEVER block instant reveal emails
            return $enabled;
        }



        /**
         * ONLY THIS FUNCTION WAS IMPROVED
         */
        protected function send_instant_reveal_email( $ticket, $order_id ) {

            if ( get_option( self::OPTION_ENABLED, 'yes' ) !== 'yes' ) {
                compkit_log( 'Win email skipped, instant reveal email is switched off' );
                return false;
            }

            $user_email = $ticket->get_user_email();
            if ( ! $user_email ) {
                compkit_log( 'Win email skipped, ticket carries no email address', [ 'ticket_id' => $ticket->get_id() ] );
                return false;
            }

            $user_name = $ticket->get_user_name();
            $ticket_no = $ticket->get_lottery_ticket_number();

            $prize_name = '';
            $prize_desc = '';
            $img_html   = '';

            if(function_exists('lty_get_instant_winner_log_id_by_ticket_id')){

                $log_id = lty_get_instant_winner_log_id_by_ticket_id($ticket->get_id());
                $log = $log_id ? lty_get_instant_winner_log($log_id) : null;

                if($log){

                    // ------------------------------------------------
                    // ALWAYS USE PRIZE GROUP AS PRIMARY SOURCE
                    // ------------------------------------------------
                    // The log has no get_prize_group(). It never has - it stores the
                    // group ID and you fetch the group yourself, which is what the
                    // scratchcard and reveal-display code has always done. Asking the
                    // log for the object failed the method_exists() guard every time,
                    // so this whole block was skipped and the email went out with no
                    // prize image and no group title.
                    $group = null;

                    if (
                        method_exists( $log, 'get_prize_group_id' )
                        && function_exists( 'lty_get_instant_winner_prize_group' )
                    ) {
                        $group_id = (int) $log->get_prize_group_id();
                        $group    = $group_id > 0 ? lty_get_instant_winner_prize_group( $group_id ) : null;
                    }

                    if($group){

                        if(method_exists($group,'get_title'))
                            $prize_name = $group->get_title();

                        // Not on the group entity today, but harmless to ask.
                        if(method_exists($group,'get_description'))
                            $prize_desc = wpautop($group->get_description());
                    }

                    // The prize image: the group's own, falling back to the one
                    // recorded on the win itself. Both entities expose get_image_url(),
                    // but it hands back the WooCommerce placeholder when there is no
                    // image - emailing a customer a grey placeholder is worse than
                    // emailing them none, so go through the attachment ID instead.
                    $image_id = 0;

                    if ( $group && method_exists( $group, 'get_image_id' ) ) {
                        $image_id = (int) $group->get_image_id();
                    }

                    if ( ! $image_id && method_exists( $log, 'get_image_id' ) ) {
                        $image_id = (int) $log->get_image_id();
                    }

                    $url = $image_id ? wp_get_attachment_url( $image_id ) : '';

                    if ( $url ) {
                        $img_html = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $prize_name ) . '"'
                            . ' width="320" style="max-width:100%;height:auto;border-radius:10px;">';
                    } else {
                        compkit_log(
                            'Win email has no prize image',
                            array(
                                'ticket_id'      => $ticket->get_id(),
                                'prize_group_id' => isset( $group_id ) ? $group_id : 0,
                            )
                        );
                    }

                    // ------------------------------------------------
                    // AUGMENT DESCRIPTION FOR SPECIFIC PRIZE TYPES
                    // (IMAGE ALWAYS REMAINS GROUP IMAGE)
                    // ------------------------------------------------

                    // Gift product prize
                    if(method_exists($log,'get_gift_product_id') && $log->get_gift_product_id()){

                        $gift = wc_get_product($log->get_gift_product_id());

                        if($gift){

                            if(!$prize_desc){
                                $prize_desc = wpautop($gift->get_short_description());
                            }
                        }
                    }

                    // Coupon prize
                    elseif(
                        method_exists($log,'get_prize_type')
                        && $log->get_prize_type()==='coupon'
                        && method_exists($log,'get_coupon_code')
                    ){

                        $code = $log->get_coupon_code();
                        if($code){
                            $prize_desc .= '<p><strong>Coupon Code:</strong> '.$code.'</p>';
                        }

                        if(!$prize_name){
                            $prize_name = 'Coupon Prize';
                        }
                    }

                    // Text only prize
                    elseif(!$prize_desc && method_exists($log,'get_prize_message')){

                        $prize_desc = wpautop($log->get_prize_message());

                        if(!$prize_name){
                            $prize_name = $log->get_prize_message();
                        }
                    }
                }
            }

            $product_name = '';
            $product = $ticket->get_product();
            if($product) $product_name=$product->get_name();

            $subject = get_option(self::OPTION_SUBJECT);
            $message = get_option(self::OPTION_MESSAGE);

            // Backwards compatibility
            $subject = str_replace('{product_name}',$product_name,$subject);

            $body = strtr($message,array(
                '{site_name}'     => get_bloginfo('name'),
                '{user_name}'     => $user_name,
                '{ticket_number}' => $ticket_no,
                '{prize_name}'    => $prize_name,
                '{prize_desc}'    => $prize_desc,
                '{prize_image}'   => $img_html,
            ));

            $wrapped = WC()->mailer()->wrap_message($subject,$body);

            $sent = wp_mail(
                $user_email,
                $subject,
                $wrapped,
                array('Content-Type: text/html; charset=UTF-8')
            );

            compkit_log(
                'Win email sent',
                array(
                    'ticket_id' => $ticket->get_id(),
                    'order_id'  => absint( $order_id ),
                    'accepted'  => $sent ? 1 : 0,
                )
            );

            if ( function_exists('giveaways_audit_log') ) {
                giveaways_audit_log(
                    'reveal_email_sent',
                    $order_id,
                    $ticket->get_id(),
                    [
                        'email' => $user_email,
                        'subject' => $subject,
                    ]
                );
            }

            return true;
        }

        /**
         * How long emails may be held. Filterable per site.
         */
        protected function hold_timeout() {

            $timeout = (int) apply_filters( 'compkit_email_hold_timeout', COMPKIT_EMAIL_HOLD_TIMEOUT );

            // A zero or negative window would schedule the release in the past and
            // defeat the hold entirely; fall back to the shipped default.
            return $timeout > 0 ? $timeout : 6 * HOUR_IN_SECONDS;
        }

        /**
         * Start the clock on a held order, once.
         *
         * Scheduled from the moment the emails are first held, not from each
         * reveal — a customer part-way through their tickets should not keep
         * pushing the deadline back.
         */
        protected function schedule_hold_timeout( $order_id ) {

            $order_id = absint( $order_id );
            if ( ! $order_id ) {
                return;
            }

            if ( wp_next_scheduled( self::CRON_RELEASE, array( $order_id ) ) ) {
                return;
            }

            $timeout = $this->hold_timeout();
            $due     = time() + $timeout;

            wp_schedule_single_event( $due, self::CRON_RELEASE, array( $order_id ) );

            if ( ! compkit_order_meta_get( $order_id, self::META_HELD_SINCE ) ) {
                compkit_order_meta_set( $order_id, self::META_HELD_SINCE, time() );
            }

            compkit_log(
                'Hold timeout scheduled',
                array(
                    'order_id' => $order_id,
                    'seconds'  => $timeout,
                    'due'      => gmdate( 'c', $due ),
                )
            );
        }

        /**
         * The hold window is up. Send whatever is still held.
         *
         * The order is left exactly as it is: unrevealed tickets stay unrevealed,
         * so a customer who comes back later still gets their reveal — they just
         * will not get a second copy of the order emails, because the released
         * flag is set here as well.
         */
        public function release_order_emails_on_timeout( $order_id ) {

            $order_id = absint( $order_id );
            if ( ! $order_id ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                compkit_log( 'Hold timeout fired for a missing order', array( 'order_id' => $order_id ) );
                return;
            }

            if ( compkit_order_meta_get( $order, self::META_RELEASED ) ) {
                return;
            }

            compkit_log(
                'Hold timed out, releasing emails with games still unplayed',
                array( 'order_id' => $order_id )
            );

            $this->send_delayed_emails_for_order( $order_id );
        }

        /**
         * Catch a Giveaways notification hook before its emails run.
         *
         * Runs at priority 1, ahead of every notification class. When the order
         * contains a game that has not been played to the end, the hook's
         * arguments are stored on the order and the notification callbacks are
         * unhooked for the rest of this request, so nothing is sent. They are
         * hooked again on the next request, and send_delayed_emails_for_order()
         * re-fires the hook with the stored arguments once the games are done.
         */
        public function maybe_hold_giveaway_emails( $arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null ) {

            if ( self::$force_release ) {
                return;
            }

            $hook = current_action();
            $args = array( $arg1, $arg2, $arg3, $arg4 );

            $order_id = $this->order_id_from_giveaway_args( $hook, $args );
            if ( ! $order_id ) {
                return;
            }

            if ( compkit_order_meta_get( $order_id, self::META_RELEASED ) ) {
                return;
            }

            if ( ! compkit_order_has_playable_games( $order_id ) ) {
                return;
            }

            if ( $this->order_all_reveals_completed( $order_id ) ) {
                return;
            }

            $held   = (array) compkit_order_meta_get( $order_id, self::META_HELD_LTY );
            $held[] = array(
                'hook' => $hook,
                'args' => $this->pack_giveaway_args( $hook, $args ),
            );
            compkit_order_meta_set( $order_id, self::META_HELD_LTY, $held );

            $removed = $this->unhook_giveaway_notifications( $hook );

            $this->schedule_hold_timeout( $order_id );

            compkit_log(
                'Holding giveaway emails until the games are played',
                array(
                    'order_id' => absint( $order_id ),
                    'hook'     => $hook,
                    'removed'  => $removed,
                )
            );
        }

        /**
         * Which order a Giveaways hook is talking about.
         */
        protected function order_id_from_giveaway_args( $hook, $args ) {

            if ( 'lty_lottery_ticket_confirmed' === $hook ) {
                return absint( $args[2] );
            }

            // lty_lottery_incorrect_answer_in_order( $product, $ticket )
            $ticket = $args[1];
            if ( is_object( $ticket ) && method_exists( $ticket, 'get_order_id' ) ) {
                return absint( $ticket->get_order_id() );
            }

            return 0;
        }

        /**
         * Store hook arguments as scalars - objects do not survive order meta.
         */
        protected function pack_giveaway_args( $hook, $args ) {

            if ( 'lty_lottery_ticket_confirmed' === $hook ) {
                return array(
                    'ticket_id'   => absint( $args[0] ),
                    'ticket_data' => $args[1],
                    'order_id'    => absint( $args[2] ),
                    'winner_ids'  => (array) $args[3],
                );
            }

            $product = $args[0];
            $ticket  = $args[1];

            return array(
                'product_id' => is_object( $product ) && method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0,
                'ticket_id'  => is_object( $ticket ) && method_exists( $ticket, 'get_id' ) ? absint( $ticket->get_id() ) : 0,
            );
        }

        /**
         * Unhook only the Giveaways notification objects, so callbacks other
         * plugins put on the same hook keep running.
         */
        protected function unhook_giveaway_notifications( $hook ) {

            global $wp_filter;

            if ( empty( $wp_filter[ $hook ] ) || ! class_exists( 'LTY_Notifications' ) ) {
                return 0;
            }

            $removed = 0;

            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $callback ) {

                    if (
                        ! is_array( $callback['function'] ) ||
                        ! isset( $callback['function'][0] ) ||
                        ! ( $callback['function'][0] instanceof LTY_Notifications )
                    ) {
                        continue;
                    }

                    remove_action( $hook, $callback['function'], $priority );
                    $removed++;
                }
            }

            return $removed;
        }

        /**
         * Re-fire the Giveaways hooks that were held back for this order.
         */
        protected function release_giveaway_emails( $order_id ) {

            $held = compkit_order_meta_get( $order_id, self::META_HELD_LTY );
            if ( empty( $held ) || ! is_array( $held ) ) {
                return;
            }

            // Clear first: a fatal inside a notification must not leave the order
            // primed to send the same batch again on the next reveal.
            compkit_order_meta_set( $order_id, self::META_HELD_LTY, array() );

            foreach ( $held as $entry ) {

                $hook = isset( $entry['hook'] ) ? $entry['hook'] : '';
                $args = isset( $entry['args'] ) ? (array) $entry['args'] : array();

                if ( ! in_array( $hook, self::$lty_order_hooks, true ) ) {
                    continue;
                }

                compkit_log(
                    'Releasing held giveaway emails',
                    array( 'order_id' => absint( $order_id ), 'hook' => $hook )
                );

                if ( 'lty_lottery_ticket_confirmed' === $hook ) {

                    do_action(
                        $hook,
                        absint( isset( $args['ticket_id'] ) ? $args['ticket_id'] : 0 ),
                        isset( $args['ticket_data'] ) ? $args['ticket_data'] : array(),
                        absint( isset( $args['order_id'] ) ? $args['order_id'] : $order_id ),
                        (array) ( isset( $args['winner_ids'] ) ? $args['winner_ids'] : array() )
                    );

                    continue;
                }

                $product = ! empty( $args['product_id'] ) ? wc_get_product( absint( $args['product_id'] ) ) : null;
                $ticket  = ! empty( $args['ticket_id'] ) && function_exists( 'lty_get_lottery_ticket' )
                    ? lty_get_lottery_ticket( absint( $args['ticket_id'] ) )
                    : null;

                if ( $product && $ticket ) {
                    do_action( $hook, $product, $ticket );
                }
            }
        }


protected function send_delayed_emails_for_order( $order_id ) {

    compkit_log(
        'Releasing order emails',
        [
            'order_id' => absint( $order_id ),
        ]
    );

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    self::$force_release = true;

    // Reset Woo sent flags so trigger() is allowed
    foreach ( array( '_processing_email_sent', '_completed_email_sent', '_on_hold_email_sent' ) as $sent_flag ) {
        $order->delete_meta_data( $sent_flag );
        delete_post_meta( $order_id, $sent_flag );
    }
    $order->save_meta_data();

    WC()->mailer()->init();
    $emails = WC()->mailer()->get_emails();

    $status = $order->get_status();

    /* ONE order email per release. Both were held, so sending Processing AND
       Completed for a completed order delivered two near-identical emails at
       the same moment (normally WooCommerce sends them hours apart). A
       completed order gets Completed; Processing only stands in for it when
       the shop has switched Completed off. trigger() itself still checks
       is_enabled(), so a disabled email is never sent. */
    $processing = $emails['WC_Email_Customer_Processing_Order'] ?? null;
    $completed  = $emails['WC_Email_Customer_Completed_Order'] ?? null;

    if ( $status === 'completed' && $completed && $completed->is_enabled() ) {

        compkit_log( 'Triggering completed email' );
        $completed->trigger( $order_id, $order );

    } elseif ( in_array( $status, [ 'processing', 'completed' ], true ) && $processing ) {

        compkit_log( 'Triggering processing email' );
        $processing->trigger( $order_id, $order );
    }

    // ON HOLD EMAIL
    if ( $status === 'on-hold' ) {

        if ( ! empty( $emails['WC_Email_Customer_On_Hold_Order'] ) ) {

            compkit_log( 'Triggering on-hold email' );

            $emails['WC_Email_Customer_On_Hold_Order']->trigger(
                $order_id,
                $order
            );
        }
    }

    // The Giveaways plugin's own order emails, held back at ticket confirmation
    // because the order contained a game. Still inside $force_release, so the
    // hold callback lets them through this time.
    $this->release_giveaway_emails( $order_id );

    self::$force_release = false;

    // Mark as released AFTER sending
    compkit_order_meta_set( $order_id, self::META_RELEASED, 1 );

    // Nothing left to wait for.
    wp_clear_scheduled_hook( self::CRON_RELEASE, array( absint( $order_id ) ) );

    compkit_log( 'Order emails released successfully' );
}






    }
}

new CompKit_Email_Handler();

/* Settings store + redesigned settings screen (with live preview). */
require_once __DIR__ . '/includes/class-email-settings.php';
require_once __DIR__ . '/includes/class-email-admin-page.php';
new CompKit_Email_Admin_Page();

add_action('admin_head', function () {
    ?>
    <style>
        .edit-email-admin-icon{
            width:100px;
            height:100px;

            /* use SVG as a mask */
            -webkit-mask-image:url('<?php echo esc_url( plugin_dir_url(__FILE__) ); ?>icons/edit-email.svg');
            mask-image:url('<?php echo esc_url( plugin_dir_url(__FILE__) ); ?>icons/edit-email.svg');

            -webkit-mask-repeat:no-repeat;
            mask-repeat:no-repeat;

            -webkit-mask-position:center;
            mask-position:center;

            -webkit-mask-size:contain;
            mask-size:contain;

            /* paint colour */
            background:#0aae12;
        }
        p.description.important {
            color: red;
        }
    </style>
    <?php
});

/**
 * EMAIL SUPPORT FOR GAME BUTTONS + COUPON PRIZES
 */


/**
 * Auto apply coupons when arriving via ?apply_coupon=
 */
add_action( 'init', function() {

    if ( empty( $_GET['apply_coupon'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $coupon = sanitize_text_field( wp_unslash( $_GET['apply_coupon'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! $coupon ) {
        return;
    }

    wc_load_cart();

    $cart = WC()->cart;
    if ( ! $cart ) {
        return;
    }

    if ( ! $cart->has_discount( $coupon ) ) {
        $cart->apply_coupon( $coupon );
    }

    wc_add_notice( sprintf( 'Coupon "%s" has been applied.', $coupon ) );

});



add_filter( 'woocommerce_add_cart_item', function( $cart_item ) {

    if ( ! empty( $cart_item['free_gift'] ) ) {
        $cart_item['data']->update_meta_data( 'free_gift', 1 );
    }

    return $cart_item;

}, 10 );
add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {

    if ( ! empty( $values['free_gift'] ) ) {

        $cart_item['free_gift'] = 1;

        if ( isset( $cart_item['data'] ) ) {
            $cart_item['data']->update_meta_data( 'free_gift', 1 );
        }
    }

    return $cart_item;

}, 10, 2 );



// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function user_has_redeemed_ticket( $user_id, $ticket, $source_order_id ){

    if( ! $user_id ){
        return false;
    }

    $orders = wc_get_orders([
        'customer_id' => $user_id,
        'status'      => ['processing','completed'],
        'limit'       => -1,
        'return'      => 'objects'
    ]);

    foreach( $orders as $o ){

        foreach( $o->get_items() as $item ){

            $gift   = $item->get_meta('free_gift');
            $tn     = $item->get_meta('ticket_number');
            $source = $item->get_meta('source_order');

            if(
                $gift
                && (string)$tn === (string)$ticket
                && (int)$source === (int)$source_order_id
            ){
                return true;
            }
        }
    }

    return false;
}
add_action('init', function(){

    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if( empty($_GET['claim_free_gift']) || empty($_GET['order']) || empty($_GET['ticket']) ) {
        return;
    }

    if( ! function_exists('WC') ) return;

    $product_id = absint($_GET['claim_free_gift']);
    $order_id   = absint($_GET['order']);
    $ticket     = sanitize_text_field( wp_unslash( $_GET['ticket'] ) );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    if( ! $product_id || ! $order_id || ! $ticket ) {
        wp_safe_redirect(wc_get_page_permalink('shop'));
        exit;
    }

    $order = wc_get_order($order_id);
    if( ! $order ) {
        wp_safe_redirect(wc_get_page_permalink('shop'));
        exit;
    }

    /**
     * Validate ticket belongs to the order
     */
    $ticket_valid = false;

    foreach( $order->get_items() as $item ){

        $tickets = $item->get_meta('_lty_lottery_tickets');

        if( empty($tickets) ) {
            continue;
        }

        foreach( (array) $tickets as $num ){

            if( trim((string)$num) === trim((string)$ticket) ){
                $ticket_valid = true;
                break 2;
            }
        }
    }

    if( ! $ticket_valid ){

        wc_add_notice(
            sprintf('That ticket number is not associated with this order: %s', esc_html($ticket)),
            'error'
        );

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }


    /**
     * Prevent duplicate pending cart items
     */
    $in_cart_pending = false;

    if( WC()->cart ){

        foreach( WC()->cart->get_cart() as $cart_item ){

            if(
                ! empty($cart_item['free_gift']) &&
                ! empty($cart_item['ticket_number']) &&
                (string)$cart_item['ticket_number'] === (string)$ticket &&
                ! empty($cart_item['source_order']) &&
                (int)$cart_item['source_order'] === (int)$order_id
            ){
                $in_cart_pending = true;
                break;
            }
        }
    }


    /**
     * Check if already redeemed in a completed order
     */
    $user_id = get_current_user_id();

    if( user_has_redeemed_ticket($user_id, $ticket, $order_id) && ! $in_cart_pending ){

        wc_add_notice(
            sprintf('Ticket %s has already been redeemed.', esc_html($ticket)),
            'error'
        );

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }
    /**
     * Block duplicates already in basket
     */
    foreach( WC()->cart->get_cart() as $cart_item ){

        if(
            ! empty($cart_item['free_gift']) &&
            (int)$cart_item['product_id'] === (int)$product_id &&
            (string)$cart_item['ticket_number'] === (string)$ticket &&
            (int)$cart_item['source_order'] === (int)$order_id
        ){

            wc_add_notice(
                'This prize is already in your basket.',
                'notice'
            );

            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }


    /**
     * Add product free
     */
    $cart_key = WC()->cart->add_to_cart(
        $product_id,
        1,
        0,
        [],
        [
            'free_gift'     => 1,
            'ticket_number' => $ticket,
            'source_order'  => $order_id,
        ]
    );




    wc_add_notice(
        sprintf('Prize for ticket %s has been added to your basket.', esc_html($ticket)),
        'success'
    );

    wp_safe_redirect(wc_get_cart_url());
    exit;
});
add_filter('woocommerce_cart_item_quantity', function($product_quantity, $cart_item_key, $cart_item){

    // Debug so we know this runs
    if ( ! did_action('free_gift_qty_debug_logged') ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        compkit_log( 'Cart quantity filter running' );
        do_action('free_gift_qty_debug_logged'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
    }

    if( ! empty($cart_item['free_gift']) ){

        compkit_log(
            'Overriding free gift quantity display',
            [
                'cart_item_key' => sanitize_text_field( $cart_item_key ),
            ]
        );

        // Absolutely no input, no plus, no minus
        return '<span class="free-gift-qty">1</span>';
    }

    return $product_quantity;

}, 50, 3);



add_action( 'woocommerce_before_calculate_totals', function( $cart ){

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    foreach ( $cart->get_cart() as $item_key => $item ) {

        // Only affect flagged items
        if ( ! empty( $item['free_gift'] ) ) {

            // lock price
            $item['data']->set_price( 0 );

            // lock quantity
            $item['quantity'] = 1;
        }
    }

}, 999 );

add_filter('woocommerce_cart_item_name', function($name, $cart_item){

    if( ! empty($cart_item['free_gift']) ){

        return $name . '<div class="free-gift-qty-display">Quantity: 1</div>';
    }

    return $name;

}, 10, 2);
add_action( 'wp_enqueue_scripts', function () {
    wp_register_style( 'giveaways-free-gift', false, [], '1.0' ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    wp_enqueue_style( 'giveaways-free-gift' );
    wp_add_inline_style( 'giveaways-free-gift',
        '.free-gift-row .product-quantity input.qty,' .
        '.free-gift-row .product-quantity .quantity,' .
        '.free-gift-row .product-quantity{display:none !important;}' .
        '.free-gift-row .wc-block-components-quantity-selector{display:none !important;}'
    );
} );
add_filter('woocommerce_blocks_cart_item_html', function( $html, $cart_item ){

    if( ! empty($cart_item['free_gift']) ){

        if ( preg_match('/<tr[^>]*class="([^"]*)"/', $html, $m) ) {

            $new = str_replace(
                $m[0],
                '<tr class="' . $m[1] . ' free-gift-row"',
                $m[0]
            );

            $html = str_replace($m[0], $new, $html);
        }
    }

    return $html;

}, 10, 2);


// Expose "free_gift" flag to the Cart and Checkout blocks.
add_filter( 'woocommerce_get_item_data', function( $data, $cart_item ) {

    if ( ! empty( $cart_item['free_gift'] ) ) {
        $data[] = array(
            'key'    => '_free_gift',
            'value'  => '1',
            'hidden' => true, // will not be rendered in the markup for blocks
        );
    }

    return $data;

}, 10, 2 );
add_action( 'wp_enqueue_scripts', function() {

    wp_register_script(
        'free-gift-blocks-integration',
        '',
        [],
        '1.2',
        true
    );

    wp_enqueue_script( 'free-gift-blocks-integration' );

    $js = '(function() {' .
        'function initFreeGiftFilters() {' .
            'if (!window.wc || !window.wc.blocksCheckout) return;' .
            'var registerCheckoutFilters =' .
                'window.wc.blocksCheckout.registerCheckoutFilters ||' .
                'window.wc.blocksCheckout.__experimentalRegisterCheckoutFilters;' .
            'if (typeof registerCheckoutFilters !== "function") return;' .
            'registerCheckoutFilters("free-gift-extension", {' .
                'cartItemClass: function(classList, extensions, args) {' .
                    'var cartItem = args && args.cartItem;' .
                    'if (!cartItem || !cartItem.item_data) return classList;' .
                    'var isFreeGift = cartItem.item_data.some(function(i){' .
                        'return i.key === "_free_gift" && i.value === "1";' .
                    '});' .
                    'return isFreeGift ? classList + " free-gift-row" : classList;' .
                '},' .
                'cartItemQuantity: function(qtyUI, extensions, args) {' .
                    'var cartItem = args && args.cartItem;' .
                    'if (!cartItem || !cartItem.item_data) return qtyUI;' .
                    'var isFreeGift = cartItem.item_data.some(function(i){' .
                        'return i.key === "_free_gift" && i.value === "1";' .
                    '});' .
                    'return isFreeGift ? null : qtyUI;' .
                '},' .
                'itemIsInCart: function(defaultValue, extensions, args) {' .
                    'var cartItem = args && args.cartItem;' .
                    'if (!cartItem || !cartItem.item_data) return defaultValue;' .
                    'var isFreeGift = cartItem.item_data.some(function(i){' .
                        'return i.key === "_free_gift" && i.value === "1";' .
                    '});' .
                    'return isFreeGift ? false : defaultValue;' .
                '}' .
            '});' .
        '}' .
        'if (document.readyState === "loading") {' .
            'document.addEventListener("DOMContentLoaded", initFreeGiftFilters);' .
        '} else {' .
            'initFreeGiftFilters();' .
        '}' .
    '})();';

    wp_add_inline_script(
        'free-gift-blocks-integration',
        $js
    );
});


add_action('woocommerce_email_order_details', function($order){
    $GLOBALS['__email_order_id'] = $order->get_id(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}, 1);
add_action('woocommerce_thankyou', function($new_order_id){

    if( ! $new_order_id ) return;

    $order = wc_get_order($new_order_id);
    if( ! $order ) return;

    foreach( $order->get_items() as $item ){

        if( ! empty($item['free_gift']) ){

            // nothing else needed here
            // redemption is detected by this data later
        }
    }

});
function compkit_product_is_instant_game( $product ) {

    if ( ! $product instanceof WC_Product ) {
        return false;
    }

    $instant_slugs = [
    'slots',
    'scratchcard',
    'spin',
    'instant-reveal',
    'football',
    'racecar',
    'claw-machine',
    'balloon-pop',
    'darts',
    'boxing'
    ];

    $terms = get_the_terms( $product->get_id(), 'product_cat' );

    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        return false;
    }

    foreach ( $terms as $term ) {
        if ( in_array( $term->slug, $instant_slugs, true ) ) {
            return true;
        }
    }

    return false;
}

function compkit_order_has_instant_products( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) return false;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && compkit_product_is_instant_game( $product ) ) {
            return true;
        }
    }

    return false;
}

function compkit_order_has_non_instant_products( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) return false;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && compkit_product_is_non_instant( $product ) ) {
            return true;
        }
    }

    return false;
}
function compkit_group_tickets_by_product( $order_id ) {

    if ( ! function_exists('lty_get_lottery_ticket') ) {
        return [];
    }
    $ticket_ids = (array) compkit_order_meta_get( $order_id, 'lty_ticket_ids_in_order' );

        if ( empty( $ticket_ids ) ) {
        return [];
    }

    $grouped = [];

    foreach ( $ticket_ids as $tid ) {

        $ticket = lty_get_lottery_ticket( $tid );
        if ( ! $ticket ) continue;

        $product = $ticket->get_product();
        if ( ! $product ) continue;

        $pid = $product->get_id();

        if ( ! isset( $grouped[$pid] ) ) {
            $grouped[$pid] = [];
        }

        $grouped[$pid][] = $tid;
    }

    return $grouped;
}

function compkit_get_winning_ticket_numbers_for_order( $order_id ) {

    if (
        ! function_exists('lty_get_instant_winner_log_id_by_ticket_id') ||
        ! function_exists('lty_get_instant_winner_log') ||
        ! function_exists('lty_get_lottery_ticket')
    ) {
        return [];
    }

    $tickets = (array) compkit_order_meta_get( $order_id, 'lty_ticket_ids_in_order' );
    if ( empty($tickets) ) return [];

    $wins = [];

    foreach ( $tickets as $tid ) {
        $log_id = lty_get_instant_winner_log_id_by_ticket_id( $tid );
        if ( ! $log_id ) continue;

        $log = lty_get_instant_winner_log( $log_id );
        if ( ! $log || ! method_exists( $log, 'has_status' ) || ! $log->has_status('lty_won') ) continue;

        $ticket = lty_get_lottery_ticket( $tid );
        if ( $ticket && method_exists($ticket, 'get_lottery_ticket_number') ) {
            $wins[] = (string) $ticket->get_lottery_ticket_number();
        }
    }

    return array_values(array_unique($wins));
}






/**
 * Does this order hold a game the customer still has to play?
 *
 * compkit_order_has_game_products() answers a different question - it is also
 * true for "No Instant Wins" products, which is right for the email content but
 * wrong for holding emails back, because those tickets are never revealed.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function compkit_order_has_playable_games( $order_id ) {

    $order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
    if ( ! $order ) return false;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if (
            $product
            && compkit_product_is_game_category( $product )
            && ! compkit_product_is_non_instant( $product )
        ) {
            return true;
        }
    }

    return false;
}


function compkit_order_has_game_products( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) return false;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && compkit_product_is_game_category( $product ) ) {
            return true;
        }
    }

    return false;
}


function compkit_inject_prizes_for_game_order(
    $order_id,
    $ticket_bg,
    $ticket_text,
    $win_ticket_bg,
    $win_ticket_text
) {

    if ( ! $order_id ) return '';

    if ( ! function_exists('lty_get_instant_winner_log_id_by_ticket_id') ) {
        return '';
    }

    $grouped = compkit_group_tickets_by_product( $order_id );

    if ( empty( $grouped ) ) {
        return '';
    }

    $out = '';

    foreach ( $grouped as $product_id => $ticket_ids ) {

        // Does this product have any confirmed instant wins?
        $has_wins = false;

        foreach ( $ticket_ids as $tid ) {
            $log_id = lty_get_instant_winner_log_id_by_ticket_id( $tid );
            if ( ! $log_id ) continue;
            $log = lty_get_instant_winner_log( $log_id );
            if ( $log && method_exists( $log, 'has_status' ) && $log->has_status('lty_won') ) {
                $has_wins = true;
                break;
            }
        }

        if ( ! $has_wins ) {
            continue;
        }

        // Build table only for THIS product's tickets
        $out .= compkit_build_instant_prize_table_for_tickets(
            $order_id,
            $ticket_ids,
            $ticket_bg,
            $ticket_text,
            $win_ticket_bg,
            $win_ticket_text
        );

    }

    return $out;
}



/**
 * Is this product name in this piece of email HTML?
 *
 * The rows of an order email are matched to their order items by product name,
 * but WooCommerce writes the name HTML-escaped (and texturized): a product
 * called "prizes, cash & credit" appears as "prizes, cash &amp; credit". A
 * plain stripos() missed every name with an &, a quote or a dash, so those
 * games' rows were never restyled - no ticket bubbles, and no won prize, which
 * is attached to the same rows. Reported on a Grab Claw product (Oct 2026).
 */
function compkit_email_html_has_name( $html, $name ) {
    $name = (string) $name;
    if ( $name === '' ) {
        return false;
    }
    if ( stripos( $html, $name ) !== false ) {
        return true;
    }
    // Decode entities, then fold curly quotes, dashes and ellipses (as
    // wptexturize() writes them) and runs of spaces to plain forms, both sides.
    $fold = function ( $t ) {
        $t = html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $t = strtr( $t, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ] );
        return preg_replace( '/\s+/u', ' ', $t );
    };
    return stripos( $fold( $html ), $fold( $name ) ) !== false;
}

add_action( 'woocommerce_email_before_order_table', function( $order, $sent_to_admin ) {
    $GLOBALS['__email_order_id']  = $order->get_id(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $GLOBALS['__sent_to_admin']   = (bool) $sent_to_admin; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}, 1, 2 );


add_filter( 'woocommerce_mail_content', function( $html ) {
    $button_html = '';
    $order_id = $GLOBALS['__email_order_id'] ?? 0; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $is_admin_email = ! empty( $GLOBALS['__sent_to_admin'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

    $GLOBALS['__winning_tickets'] = $order_id // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        ? compkit_get_winning_ticket_numbers_for_order( $order_id )
        : [];
    $GLOBALS['__winning_tickets'] = array_map( 'strval', (array) $GLOBALS['__winning_tickets'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

    $ticket_bg       = get_option('playkit_ticket_bg_color', '#2aa8f8');
    $ticket_text     = get_option('playkit_ticket_text_color', '#000000');
    $win_ticket_bg   = get_option('playkit_win_ticket_bg_color', '#d4af37');
    $win_ticket_text = get_option('playkit_win_ticket_text_color', '#000000');

    // safety: ensure valid hex, fallback if not
    $ticket_bg       = sanitize_hex_color($ticket_bg) ?: '#2aa8f8';
    $ticket_text     = sanitize_hex_color($ticket_text) ?: '#000000';
    $win_ticket_bg   = sanitize_hex_color($win_ticket_bg) ?: '#d4af37';
    $win_ticket_text = sanitize_hex_color($win_ticket_text) ?: '#000000';

    $disclaimer_text = '';

    // The order email message only applies to orders that contain a game
    // (a product in a game category) — nothing else is affected.
    if ( $order_id && compkit_order_has_game_products( $order_id ) ) {
        $disclaimer_text = get_option(
            'playkit_email_disclaimer_instant',
            'Game replay available, prizes are not re-awarded. Good luck in the main competition draw. Below are your entry details.'
        );
    }

    $disclaimer_html = '';

    if ( trim( $disclaimer_text ) !== '' ) {

        $content = wpautop( wp_kses_post( $disclaimer_text ) );

        $disclaimer_html = '
        <div style="
            text-align:center;
            font-size:12px;
            color:#666666;
            margin:4px 0 12px 0;
            line-height:1.4;
        ">
            ' . $content . '
        </div>';
    }




    if ( $order_id ) {

        $order = wc_get_order( $order_id );

        if ( $order ) {

            $order_url = esc_url( $order->get_view_order_url() );

            $shown = [
                'scratchcard'    => false,
                'spin'           => false,
                'slots'          => false,
                'football'       => false,
                'racecar'        => false,
                'claw-machine'   => false,
                'instant-reveal' => false,
            ];

            foreach ( $order->get_items() as $item ) {

                $product = $item->get_product();
                if ( ! $product ) continue;

                $cats = wp_get_post_terms(
                    $product->get_id(),
                    'product_cat',
                    [ 'fields' => 'slugs' ]
                );


                if ( ! $shown['scratchcard'] && in_array( 'scratchcard', $cats, true ) ) {
                $button_html .= '
                <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                <td align="left" style="padding:12px 0;">
                    <a href="'.$order_url.'" style="
                        display:inline-block;
                        background:'.$ticket_bg.';
                        color:'.$ticket_text.' !important;
                        padding:14px 22px;
                        border-radius:10px;
                        text-decoration:none;
                        font-size:16px;
                        font-weight:800;
                        text-align:center;">
                        Scratch
                    </a>
                </td>
                </tr>
                </table>';

                    $shown['scratchcard'] = true;
                }

                if ( ! $shown['spin'] && in_array( 'spin', $cats, true ) ) {
                $button_html .= '
                <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                <td align="left" style="padding:12px 0;">
                    <a href="'.$order_url.'" style="
                        display:inline-block;
                        background:'.$ticket_bg.';
                        color:'.$ticket_text.' !important;
                        padding:14px 22px;
                        border-radius:10px;
                        text-decoration:none;
                        font-size:16px;
                        font-weight:800;
                        text-align:center;">
                        Spin
                    </a>
                </td>
                </tr>
                </table>';

                    $shown['spin'] = true;
                }

                if ( ! $shown['slots'] && in_array( 'slots', $cats, true ) ) {
                    $button_html .= '
                    <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                    <td align="left" style="padding:12px 0;">
                        <a href="'.$order_url.'" style="
                            display:inline-block;
                            background:'.$ticket_bg.';
                            color:'.$ticket_text.' !important;
                            padding:14px 22px;
                            border-radius:10px;
                            text-decoration:none;
                            font-size:16px;
                            font-weight:800;
                            text-align:center;">
                            Start
                        </a>
                    </td>
                    </tr>
                    </table>';

                    $shown['slots'] = true;
                }

                if ( ! $shown['instant-reveal'] && in_array( 'instant-reveal', $cats, true ) ) {
                $button_html .= '
                <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                <td align="left" style="padding:12px 0;">
                    <a href="'.$order_url.'" style="
                        display:inline-block;
                        background:'.$ticket_bg.';
                        color:'.$ticket_text.' !important;
                        padding:14px 22px;
                        border-radius:10px;
                        text-decoration:none;
                        font-size:16px;
                        font-weight:800;
                        text-align:center;">
                        Reveal Again
                    </a>
                </td>
                </tr>
                </table>';

                    $shown['instant-reveal'] = true;
                }
            }
        }
    }


    // Make wrapper fluid
    $html = preg_replace(
        '#<td[^>]*width="600"[^>]*>#i',
        '<td style="width:100%;">',
        $html
    );


    // Center header wrapper
    $html = preg_replace(
        '#<td([^>]*id="header_wrapper"[^>]*)>#i',
        '<td$1 style="padding:20px 32px 0;text-align:center;">',
        $html
    );
// Remove ticket meta only for game products
$html = preg_replace_callback(
'#<tr[^>]*class="[^"]*order_item[^"]*"[^>]*>[\s\S]*?</tr>#i',
function( $m ) use ( $order ) {

    $row = $m[0];

    if ( ! $order ) {
        return $row;
    }

    foreach ( $order->get_items() as $item ) {

        if ( ! compkit_email_html_has_name( $row, $item->get_name() ) ) {
            continue;
        }

        $product = $item->get_product();

        if (
            $product &&
            compkit_product_uses_game_ui( $product )
        ) {

            $row = preg_replace(
                '#<div[^>]*class="[^"]*email-order-item-meta[^"]*"[^>]*>.*?</div>#is',
                '',
                $row
            );
        }

        break;
    }

    return $row;
},
$html
);
    // Always center H1
    $html = preg_replace(
        '#<h1[^>]*>(.*?)</h1>#is',
        '<h1 style="font-family:\'Helvetica Neue\',Helvetica,Roboto,Arial,sans-serif;font-size:32px;font-weight:700;letter-spacing:-1px;line-height:120%;margin:0;color:#111;background-color:inherit;text-align:center">$1</h1>',
        $html
    );

    // Center logo image if present
    $html = preg_replace(
        '#<img([^>]*)>#i',
        '<img$1 style="display:block;margin:0 auto;">',
        $html
    );

    // Remove reveal UI
    $html = preg_replace(
        '#<div class="gift-body"></div>\\s*<div class="gift-bow"></div>\\s*<p class="gift-instruction".*?</p>#si',
        '',
        $html
    );



    // Always show instant win table
    $html = str_replace(
        'instant-win-message hidden',
        'instant-win-message',
        $html
    );

    // CAPTURE PRIZE BLOCK
    // CAPTURE PRIZE BLOCKS (FIX: collect ALL, not just last)
    $instant_wrappers = [];

    $html = preg_replace_callback(
        '#(<div[^>]*class="[^"]*instant-win-message[^"]*"[^>]*>[\\s\\S]*?</div>)#i',
        function($m) use (&$instant_wrappers) {
            $instant_wrappers[] = $m[0];
            return '';
        },
        $html
    );

    // Combine them if you still want to use them as fallback
    $instant_wrapper = '';
    if ( ! empty($instant_wrappers) ) {
        $instant_wrapper = implode("\n", $instant_wrappers);
    }


    // Capture ticket numbers from instant-reveal table ONLY if logs produced nothing
    if ( ! empty($instant_wrapper) && empty($GLOBALS['__winning_tickets']) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        if ( preg_match_all('#<tr[^>]*>\s*<td[^>]*>\s*([0-9]+)\s*</td>#i', $instant_wrapper, $mm) ) {
            $GLOBALS['__winning_tickets'] = array_values(array_unique(array_map('strval', $mm[1]))); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        }
    }

    // Center order summary heading
    $html = preg_replace(
        '#(<h2[^>]*class="[^"]*email-order-detail-heading[^"]*"[^>]*style=")[^"]*"#i',
        '$1text-align:center;"',
        $html
    );

    // Ticket pill styling string
    $pill =
        'float:left;display:inline-block;max-width:100%;overflow-wrap:break-word;'.
        'padding:6px 12px;'.
        'background:'.esc_attr($ticket_bg).';'.
        'color:'.esc_attr($ticket_text).';'.
        'font-weight:800;'.
        'font-size:12px;line-height:14px;border-radius:10px;text-align:center;'.
        'margin:4px;vertical-align:middle;';


    // COLLECT ORDER ROWS
    $items = [];

    $html = preg_replace_callback(
        '#<tr[^>]*class="[^"]*order_item[^"]*"[^>]*>[\\s\\S]*?<td[^>]*class="[^"]*text-align-right[^"]*"[^>]*>[\\s\\S]*?</td>\\s*<td[^>]*class="[^"]*text-align-right[^"]*"[^>]*>[\\s\\S]*?</td>\\s*</tr>#i',
function($m) use (&$items, $order) {

    $row = $m[0];

    if ( ! $order ) {
        return $row;
    }

    foreach ( $order->get_items() as $item ) {

        $product = $item->get_product();

        if (
            $product &&
            compkit_product_uses_game_ui( $product ) &&
            compkit_email_html_has_name( $row, $item->get_name() )
        ) {
            $items[] = $row;
            return '';
        }
    }

    return $row;
},
        $html
    );
    $order_id = $GLOBALS['__email_order_id'] ?? 0;
    $order    = $order_id ? wc_get_order($order_id) : null;

    $order_tickets = [];

    if ( $order ) {
        foreach ( $order->get_items() as $item ) {
            $tickets = $item->get_meta('_lty_lottery_tickets');
            if ( $tickets ) {
                $order_tickets[ $item->get_product_id() ] = (array) $tickets;
            }
        }
    }

    // BUILD GRID
    $grid = '';

    foreach ( $items as $row ) {
        preg_match('#<img[^>]*>#i', $row, $img);
        preg_match('#</td>\s*<td[^>]*>([\s\S]*?)</td>#i', $row, $content);
        preg_match('#×\s*([0-9]+)#', $row, $qty);
        $content_clean = preg_replace(
            '#<(a|button)(?![^>]*href=)[^>]*>\s*(?:<span[^>]*>)?.*?(?:</span>)?\s*</\1>#is',
            '',
            $content[1] ?? ''
        );

$product_tickets = [];
$is_playkit_game_row = false;
if ( $order ) {
    foreach ( $order->get_items() as $item ) {

        if ( ! compkit_email_html_has_name( $content_clean, $item->get_name() ) ) {
            continue;
        }

        $product = $item->get_product();
        if ( ! $product ) continue;

        $is_playkit_game_row = compkit_product_uses_game_ui( $product );

        if ( $is_playkit_game_row ) {

            $tickets = $item->get_meta('_lty_lottery_tickets');

            if ( ! empty($tickets) ) {
                $product_tickets = (array) $tickets;
            }
        }

        break;
    }
}

if ( ! empty( $product_tickets ) ) {
    $content_clean .= compkit_render_ticket_list(
        $product_tickets,
        $ticket_bg,
        $ticket_text
    );
}
        // PRICE: handle <bdi> OR inline text
        $price_html = '';

        // 1: £ inside span + number inside bdi
        if ( preg_match('#<span[^>]*class="woocommerce-Price-amount[^"]*"[^>]*>.*?<bdi>(.*?)</bdi>.*?</span>#is', $row, $m) ) {
            $price_html = $m[1];
        }
        // 2: number inside span (no bdi)
        elseif ( preg_match('#<span[^>]*class="woocommerce-Price-amount[^"]*"[^>]*>([^<]*[0-9][^<]*)</span>#is', $row, $m) ) {
            $price_html = $m[1];
        }
        // 3: currency span + number after it
        elseif ( preg_match('#<span[^>]*class="woocommerce-Price-currencySymbol"[^>]*>(.*?)</span>\s*([0-9\.,]+)#is', $row, $m) ) {
            $price_html = $m[1].$m[2];
        }
        // 4: absolute fallback, last number in the row
        elseif ( preg_match('#([0-9]+\.[0-9]{2})#', $row, $m) ) {
            $price_html = '£'.$m[1];
        }
        $row_button_html = '';

        if ( $order ) {

            foreach ( $order->get_items() as $item ) {

                if ( ! compkit_email_html_has_name( $content_clean, $item->get_name() ) ) {
                    continue;
                }

                $product = $item->get_product();
                if ( ! $product ) continue;

                $cats = wp_get_post_terms(
                    $product->get_id(),
                    'product_cat',
                    [ 'fields' => 'slugs' ]
                );
                $wins = function_exists('eiwr_get_instant_win_data_for_order_product')
                    ? eiwr_get_instant_win_data_for_order_product( $order, $product->get_id() )
                    : [];
                if ( ! compkit_product_uses_game_ui( $product ) ) {
                    break;
                }
            if ( in_array( 'scratchcard', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Scratch Replay
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'spin', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Spin Replay
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'slots', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Replay Slots
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'football', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Football Replay
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'racecar', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Racecar Replay
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'claw-machine', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Grab Claw Replay
                            </a>
                        </td>
                    </tr>
                </table>';

            } elseif ( in_array( 'instant-reveal', $cats, true ) ) {

                $row_button_html = '
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left" style="padding:12px 0;">
                            <a href="'.$order_url.'" style="
                                display:inline-block;
                                background:'.$ticket_bg.';
                                color:'.$ticket_text.' !important;
                                padding:14px 22px;
                                border-radius:10px;
                                text-decoration:none;
                                font-size:16px;
                                font-weight:800;">
                                Reveal Again
                            </a>
                        </td>
                    </tr>
                </table>';
            }

            break;
            }
        }
                if ( ! empty( $wins ) ) {
                    $row_button_html .= compkit_build_instant_prize_table_from_eiwr(
                        $wins,
                        $ticket_bg,
                        $ticket_text,
                        $win_ticket_bg,
                        $win_ticket_text
                    );
                }
        $grid .= '
        <table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:1px solid rgba(0,0,0,.15);margin:0 0 14px 0;">
        <tbody>

        <tr>
            <td width="25%" style="vertical-align:top;padding-right:12px;text-align:left;">
                '.($img[0] ?? '').'
            </td>
            <td width="75%" style="vertical-align:top;text-align:left;">
                '.$content_clean.'
                '.$row_button_html.'
            </td>
        </tr>

        <tr>
            <td width="25%"></td>
            <td width="75%" style="vertical-align:top;text-align:right;padding-top:6px;font-size:13px;color:#444;">
                ×'.($qty[1] ?? '1').' &nbsp;&nbsp;
                '.$price_html.'
            </td>
        </tr>

        </tbody>
        </table>';
                    }
        $has_wins = ! empty( $GLOBALS['__winning_tickets'] );

        $win_header = '';

        if ( $has_wins ) {

            $label = $is_admin_email
                ? 'Customer has won'
                : 'Congratulations, you have won!';

            $win_header = '
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px 0;">
                <tr>
                    <td align="center" style="
                        background:'.esc_attr($win_ticket_bg).';
                        padding:18px 12px;
                        border-radius: 12px;
                    ">
                        <div style="
                            color:'.esc_attr($win_ticket_text).' !important;
                            font-size:18px;
                            font-weight:900;
                            letter-spacing:0.4px;
                        ">
                            <font color="'.esc_attr($win_ticket_text).'">'.$label.'</font>
                        </div>
                    </td>
                </tr>
            </table>';
        }



    // INSERT GRID + PRIZE BLOCK
    $after_heading = $disclaimer_html . $win_header . $grid;





    if ( $after_heading !== '' ) {
        $html = preg_replace(
            '#(<h2[^>]*class="[^"]*email-order-detail-heading[^"]*"[^>]*>[\\s\\S]*?</h2>)#i',
            '$1'.$after_heading,
            $html,
            1
        );
    }

    // Convert <button> to <a>
    $html = preg_replace(
        '#<button([^>]*)>(.*?)</button>#si',
        '<a$1 style="text-decoration:none;display:inline-block;">$2</a>',
        $html
    );

    // Make instant win coupon anchors clickable
    $html = preg_replace_callback(
        '#<a([^>]*class="[^"]*instant-win-coupon[^"]*"[^>]*)>(.*?)</a>#si',
        function( $m ) use ( $ticket_bg, $ticket_text )  {

            // Extract coupon from data-coupon OR inner text fallback
            preg_match('#data-coupon="([^"]+)"#i', $m[1], $c);
            $code = !empty($c[1]) ? trim($c[1]) : trim(wp_strip_all_tags($m[2]));

            // Build apply coupon URL
            $url = add_query_arg(
                'apply_coupon',
                rawurlencode($code),
                wc_get_page_permalink('shop')
            );

            return '<a href="'.esc_url($url).'"
                    style="background:'.esc_attr($ticket_bg).';
                            color:'.esc_attr($ticket_text).';
                            padding:8px 12px;
                            border-radius:4px;
                            text-decoration:none;
                            display:inline-block;
                            font-weight:bold;">
                    '.esc_html($code).'
                </a>';
        },
        $html
    );

    // Rewrite prize product links to claim URL WITH IMAGE + BLACK/BLUE BUTTON + GOLD MINI TICKET + CLICK ME
    $html = preg_replace_callback(
        '#<tr[^>]*>\s*<td[^>]*>\s*([0-9]+)\s*</td>\s*<td[^>]*>(.*?)</td>\s*</tr>#is',
        function ( $m ) use (
            $ticket_bg,
            $ticket_text,
            $win_ticket_bg,
            $win_ticket_text
        ) {

            $ticket = trim($m[1]);
            $cell   = $m[2];

            // Extract image if present
            $img = '';
            if ( preg_match('#<img[^>]*>#i', $cell, $i) ) {
                $img = $i[0];
            }

            // Winning ticket badge
            $ticket_gold = '
            <td style="vertical-align:middle;">
                <span style="
                    display:inline-block;
                    background:'.esc_attr($win_ticket_bg).';
                    color:'.esc_attr($win_ticket_text).' !important;
                    padding:4px 8px;
                    border-radius:8px;
                    font-size:11px;
                    font-weight:900;
                    white-space:nowrap;
                ">
                    <font color="'.esc_attr($win_ticket_text).'">'.$ticket.'</font>
                </span>
            </td>';

            // reusable spacer row (EMAIL SAFE)
            $spacer = '
            <tr>
                <td colspan="2" style="height:14px;line-height:14px;font-size:14px;">
                    &nbsp;
                </td>
            </tr>';

            /* =========================
            COUPON PRIZE
            ========================= */

            if ( preg_match('#apply_coupon=#i', $cell) ) {

                return '
                <tr>
                    '.$ticket_gold.'
                    <td style="vertical-align:middle;">
                        '.$cell.'
                    </td>
                </tr>
                '.$spacer;
            }


            /* =========================
            WALLET / SITE CREDIT
            ========================= */
            if ( preg_match('#credit|wallet|cash|balance|account#i', wp_strip_all_tags($cell)) ) {
                return '
                <tr>
                    '.$ticket_gold.'
                    <td style="vertical-align:middle;">
                        <table cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="vertical-align:middle;padding-right:8px;">
                                    '.$img.'
                                </td>
                                <td style="vertical-align:middle;">
                                    <strong>'.wp_strip_all_tags($cell).'</strong><br>
                                    <span style="font-size:11px;color:#666;">
                                        *Applied to account
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                '.$spacer;
            }

            /* =========================
            PRODUCT PRIZE
            ========================= */
            if ( preg_match('#<a[^>]*href="([^"]+)"#i', $cell, $a) ) {

                $product_id = url_to_postid($a[1]);
                if ( $product_id && isset($GLOBALS['__email_order_id']) ) {

                    $claim = add_query_arg(
                        [
                            'claim_free_gift' => $product_id,
                            'order'           => absint($GLOBALS['__email_order_id']),
                            'ticket'          => $ticket,
                        ],
                        wc_get_cart_url()
                    );

                    return '
                    <tr>
                        '.$ticket_gold.'
                        <td style="vertical-align:middle;">
                            <table cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="vertical-align:middle;padding-right:8px;">
                                        '.$img.'
                                    </td>
                                    <td style="vertical-align:middle;">
                                        <a href="'.esc_url($claim).'"
                                        style="
                                                background:'.esc_attr($ticket_text).';
                                                color:'.esc_attr($ticket_bg).';
                                                border:2px solid '.esc_attr($ticket_bg).';
                                                padding:10px 14px;
                                                border-radius:12px;
                                                text-decoration:none;
                                                font-weight:800;
                                                display:inline-block;
                                                text-align:center;
                                                line-height:1.2;
                                        ">
                                            <span style="display:block;font-size:14px;">
                                                '.esc_html(trim(wp_strip_all_tags($cell))).'
                                            </span>
                                            <span style="display:block;font-size:10px;font-weight:600;">
                                                CLICK ME
                                            </span>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    '.$spacer;
                }
            }

            /* =========================
            PHYSICAL PRIZE FALLBACK
            ========================= */
            return '
            <tr>
                '.$ticket_gold.'
                <td style="vertical-align:middle;">
                    <table cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="vertical-align:middle;padding-right:8px;">
                                '.$img.'
                            </td>
                            <td style="vertical-align:middle;">
                                <strong>'.wp_strip_all_tags($cell).'</strong><br>
                                <span style="font-size:11px;color:#666;">
                                    Contact to Redeem
                                </span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            '.$spacer;
        },
        $html
    );



    // STYLE TICKET BADGES (gold for winners)
    $html = preg_replace_callback(
        '#<span[^>]*class="[^"]*ticket-badge[^"]*"[^>]*>(.*?)</span>#si',
        function ( $m ) use (
            $ticket_bg,
            $ticket_text,
            $win_ticket_bg,
            $win_ticket_text
        ) {

            $num  = trim(wp_strip_all_tags($m[1]));
            $wins = isset($GLOBALS['__winning_tickets'])
                ? $GLOBALS['__winning_tickets']
                : [];

            // Winning ticket
            if ( in_array($num, $wins, true) ) {
                return '<span class="ticket-badge" style="
                    float:left;
                    display:inline-block;
                    max-width:100%;
                    padding:6px 12px;
                    background:'.esc_attr($win_ticket_bg).';
                    color:'.esc_attr($win_ticket_text).' !important;
                    font-weight:900;
                    font-size:12px;
                    line-height:14px;
                    border-radius:10px;
                    text-align:center;
                    margin:4px;
                    vertical-align:middle;
                ">
                    <font color="'.esc_attr($win_ticket_text).'">'.$num.'</font>
                </span>';

            }

            // Normal ticket
            return '<span class="ticket-badge" style="
                float:left;
                display:inline-block;
                max-width:100%;
                padding:6px 12px;
                background:'.esc_attr($ticket_bg).';
                color:'.esc_attr($ticket_text).' !important;
                font-weight:800;
                font-size:12px;
                line-height:14px;
                border-radius:10px;
                text-align:center;
                margin:4px;
                vertical-align:middle;
            ">
                <font color="'.esc_attr($ticket_text).'">'.$num.'</font>
            </span>';
        },
        $html
    );


    return $html;

}, 20 );

function compkit_product_uses_game_ui( $product ) {

    if ( ! $product instanceof WC_Product ) {
        return false;
    }

    $slugs = [
        'slots',
        'scratchcard',
        'spin',
        'football',
        'racecar',
        'claw-machine',
        'balloon-pop',
        'darts',
        'boxing',
        'instant-reveal',
    ];

    $terms = get_the_terms(
        $product->get_id(),
        'product_cat'
    );

    if ( empty($terms) || is_wp_error($terms) ) {
        return false;
    }

    foreach ( $terms as $term ) {
        if ( in_array( $term->slug, $slugs, true ) ) {
            return true;
        }
    }

    return false;
}
function compkit_product_is_game_category( $product ) {

    if ( ! $product instanceof WC_Product ) {
        return false;
    }

    $slugs = [
    'slots',
    'scratchcard',
    'spin',
    'instant-reveal',
    'football',
    'racecar',
    'claw-machine',
    'balloon-pop',
    'darts',
    'boxing',
    'non-instant'
    ];

    $terms = get_the_terms( $product->get_id(), 'product_cat' );
    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        return false;
    }

    foreach ( $terms as $term ) {
        if ( in_array( $term->slug, $slugs, true ) ) {
            return true;
        }
    }

    return false;
}

function compkit_product_is_non_instant( $product ) {

    if ( ! $product instanceof WC_Product ) {
        return false;
    }

    $terms = get_the_terms( $product->get_id(), 'product_cat' );

    if ( empty($terms) || is_wp_error($terms) ) {
        return false;
    }

    foreach ( $terms as $term ) {
        if ( $term->slug === 'non-instant' ) {
            return true;
        }
    }

    return false;
}
function compkit_render_ticket_list( $tickets, $bg, $text ) {

    if ( empty($tickets) ) return '';

    $wins = isset($GLOBALS['__winning_tickets'])
        ? array_map('strval', (array) $GLOBALS['__winning_tickets'])
        : [];

    $win_bg   = get_option('playkit_win_ticket_bg_color', '#d4af37');
    $win_text = get_option('playkit_win_ticket_text_color', '#000000');

    $out = '<div style="margin-top:8px;">';

    foreach ( $tickets as $t ) {

        $t = (string) $t;

        $is_win = in_array( $t, $wins, true );

        $badge_bg   = $is_win ? $win_bg : $bg;
        $badge_text = $is_win ? $win_text : $text;
        $weight     = $is_win ? '900' : '800';

        $out .= '
        <span class="ticket-badge" style="
            display:inline-block;
            margin:4px 6px 0 0;
            padding:6px 10px;
            background:'.esc_attr($badge_bg).';
            color:'.esc_attr($badge_text).' !important;
            font-size:12px;
            font-weight:'.$weight.';
            border-radius:10px;
            white-space:nowrap;
        ">
            <font color="'.esc_attr($badge_text).'">'.esc_html($t).'</font>
        </span>';
    }

    $out .= '</div>';

    return $out;
}

/**
 * Build the same "Ticket / Prize" table from the order tickets + instant win logs.
 * This is used when the game email row only contains a Play button and no instant-win markup.
 */
function compkit_build_instant_prize_table_for_tickets(
    $order_id,
    array $ticket_ids,
    $ticket_bg,
    $ticket_text,
    $win_ticket_bg,
    $win_ticket_text
) {

    if ( ! function_exists('lty_get_lottery_ticket') ) {
        return '';
    }

        if ( empty( $ticket_ids ) ) {
        // Some setups store it on the order meta via WC_Order
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $ticket_ids = (array) $order->get_meta( 'lty_ticket_ids_in_order' );
        }
    }

    if ( empty( $ticket_ids ) ) {
        return '';
    }

    // Customisable prize-table styling (Instant Reveal & Orders Email → Prize Table).
    $coupon_btn_bg   = get_option( 'playkit_coupon_btn_bg', '#2aa8f8' );
    $coupon_btn_text = get_option( 'playkit_coupon_btn_text', '#ffffff' );
    $claim_btn_bg    = get_option( 'playkit_claim_btn_bg', '#000000' );
    $claim_btn_text  = get_option( 'playkit_claim_btn_text', '#2aa8f8' );
    $note_credit     = get_option( 'playkit_prize_note_credit', '*Applied to account' );
    $note_physical   = get_option( 'playkit_prize_note_physical', 'Contact Us' );
    $badge_radius      = absint( get_option( 'playkit_badge_radius', 8 ) );
    $badge_size        = absint( get_option( 'playkit_badge_size', 11 ) );
    $coupon_btn_radius = absint( get_option( 'playkit_coupon_btn_radius', 4 ) );
    $coupon_btn_size   = absint( get_option( 'playkit_coupon_btn_size', 13 ) );
    $claim_btn_radius  = absint( get_option( 'playkit_claim_btn_radius', 12 ) );
    $claim_btn_size    = absint( get_option( 'playkit_claim_btn_size', 14 ) );

    $rows = '';

    foreach ( $ticket_ids as $tid ) {

        $tid = absint( $tid );
        if ( ! $tid ) continue;

        // Only show INSTANT WIN tickets
        if ( ! function_exists('lty_get_instant_winner_log_id_by_ticket_id') ) {
            continue;
        }

        $log_id = lty_get_instant_winner_log_id_by_ticket_id( $tid );
        if ( ! $log_id ) {
            continue;
        }

        $ticket = lty_get_lottery_ticket( $tid );
        if ( ! $ticket ) {
            continue;
        }

        $ticket_number = method_exists($ticket, 'get_lottery_ticket_number')
            ? $ticket->get_lottery_ticket_number()
            : $tid;

        $log = function_exists('lty_get_instant_winner_log') ? lty_get_instant_winner_log( $log_id ) : null;
        if ( ! $log ) {
            continue;
        }

        // Defaults
        $prize_name = '';
        $prize_desc = '';
        $img_html   = '';

        // Prefer prize group
        $group = method_exists($log,'get_prize_group') ? $log->get_prize_group() : null;
        if ( $group ) {
            if ( method_exists($group,'get_title') ) {
                $prize_name = (string) $group->get_title();
            }
            if ( method_exists($group,'get_description') ) {
                $prize_desc = (string) $group->get_description();
            }
            if ( method_exists($group,'get_image_id') ) {
                $url = wp_get_attachment_url( $group->get_image_id() );
                if ( $url ) {
                    $img_html = '<img src="'.esc_url($url).'" width="50" height="50" style="width:50px;height:50px;border-radius:6px;vertical-align:middle;margin-right:5px;" alt="">';
                }
            }
        }

        // Determine prize type behaviours (match what you do in the rewrite)
        $is_coupon = false;
        $coupon_code = '';

        if (
            method_exists($log,'get_prize_type')
            && $log->get_prize_type() === 'coupon'
            && method_exists($log,'get_coupon_code')
        ) {
            $is_coupon = true;
            $coupon_code = trim( (string) $log->get_coupon_code() );
            if ( ! $prize_name ) $prize_name = 'Coupon Prize';
        }

        $gift_product_id = ( method_exists($log,'get_gift_product_id') ) ? absint($log->get_gift_product_id()) : 0;

        // Ticket badge (always "winning" style because these are instant wins)
        $ticket_cell = '
            <td style="vertical-align:middle;padding:6px 0;">
                <span style="
                    display:inline-block;
                    background:'.esc_attr($win_ticket_bg).';
                    color:'.esc_attr($win_ticket_text).' !important;
                    padding:4px 8px;
                    border-radius:'.$badge_radius.'px;
                    font-size:'.$badge_size.'px;
                    font-weight:900;
                    white-space:nowrap;
                ">
                    <font color="'.esc_attr($win_ticket_text).'">'.esc_html($ticket_number).'</font>
                </span>
            </td>';

        // Spacer row between items (email-safe)
        $spacer = '
            <tr>
                <td colspan="2" style="height:12px;line-height:12px;font-size:12px;">&nbsp;</td>
            </tr>';

        // COUPON PRIZE row
        if ( $is_coupon && $coupon_code ) {

            $url = add_query_arg(
                'apply_coupon',
                rawurlencode($coupon_code),
                wc_get_page_permalink('shop')
            );

            $prize_cell = '
                <td style="vertical-align:middle;padding:6px 0;">
                    '.( $img_html ? $img_html : '' ).'
                    <strong style="vertical-align:middle;">'.esc_html($prize_name ?: 'Discount Code').'</strong><br>
                    <small>Click to Apply Coupon:
                        <a href="'.esc_url($url).'" style="
                            background:'.esc_attr($coupon_btn_bg).';
                            color:'.esc_attr($coupon_btn_text).' !important;
                            padding:8px 12px;
                            border-radius:'.$coupon_btn_radius.'px;
                            font-size:'.$coupon_btn_size.'px;
                            text-decoration:none;
                            display:inline-block;
                            font-weight:bold;
                        ">
                            <font color="'.esc_attr($coupon_btn_text).'">'.esc_html($coupon_code).'</font>
                        </a>
                    </small>
                </td>';

            $rows .= '<tr>'.$ticket_cell.$prize_cell.'</tr>'.$spacer;
            continue;
        }

        // PRODUCT PRIZE row (claim link)
        if ( $gift_product_id ) {

            $claim = add_query_arg(
                [
                    'claim_free_gift' => $gift_product_id,
                    'order'           => absint($order_id),
                    'ticket'          => $ticket_number,
                ],
                wc_get_cart_url()
            );

            $title = $prize_name;
            if ( ! $title ) {
                $gift = wc_get_product( $gift_product_id );
                if ( $gift ) $title = $gift->get_name();
            }
            if ( ! $title ) $title = 'Claim your prize';

            $prize_cell = '
                <td style="vertical-align:middle;padding:6px 0;">
                    <table cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="vertical-align:middle;padding-right:8px;">
                                '.( $img_html ? $img_html : '' ).'
                            </td>
                            <td style="vertical-align:middle;">
                                <a href="'.esc_url($claim).'" style="
                                    background:'.esc_attr($claim_btn_bg).';
                                    color:'.esc_attr($claim_btn_text).' !important;
                                    border:2px solid '.esc_attr($claim_btn_text).';
                                    padding:10px 14px;
                                    border-radius:'.$claim_btn_radius.'px;
                                    text-decoration:none;
                                    font-weight:800;
                                    display:inline-block;
                                    text-align:center;
                                    line-height:1.2;
                                ">
                                    <span style="display:block;font-size:'.$claim_btn_size.'px;">
                                        <font color="'.esc_attr($claim_btn_text).'">'.esc_html($title).'</font>
                                    </span>
                                    <span style="display:block;font-size:10px;font-weight:600;">
                                        <font color="'.esc_attr($claim_btn_text).'">CLICK ME</font>
                                    </span>
                                </a>
                            </td>
                        </tr>
                    </table>
                </td>';

            $rows .= '<tr>'.$ticket_cell.$prize_cell.'</tr>'.$spacer;
            continue;
        }

        // WALLET / CREDIT / TEXT fallback
        $fallback_title = $prize_name ?: trim( wp_strip_all_tags( $prize_desc ) );
        if ( ! $fallback_title ) $fallback_title = 'Instant Win';

        $note = '';
        if ( preg_match('#credit|wallet#i', $fallback_title) ) {
            $note = '<span style="font-size:11px;color:#666;">'.esc_html($note_credit).'</span>';
        } else {
            $note = '<span style="font-size:11px;color:#666;">'.esc_html($note_physical).'</span>';
        }

        $prize_cell = '
            <td style="vertical-align:middle;padding:6px 0;">
                <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="vertical-align:middle;padding-right:8px;">
                            '.( $img_html ? $img_html : '' ).'
                        </td>
                        <td style="vertical-align:middle;">
                            <strong>'.esc_html($fallback_title).'</strong><br>
                            '.$note.'
                        </td>
                    </tr>
                </table>
            </td>';

        $rows .= '<tr>'.$ticket_cell.$prize_cell.'</tr>'.$spacer;
    }

    if ( ! $rows ) {
        return '';
    }

    // Wrap as a neat table (no borders, just spacing via spacer rows)
    $out = '
    <div style="margin-top:10px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <thead>
                <tr>
                    <th style="padding:8px 12px;padding-left:0;text-align:left;">Ticket #</th>
                    <th style="padding:8px 12px;padding-right:0;text-align:left;">Prize</th>
                </tr>
            </thead>
            <tbody>
                '.$rows.'
            </tbody>
        </table>
    </div>';

    return $out;
}

/**
 * Inject the rebuilt prize table under a "Play" button, if present.
 */
function compkit_inject_prizes_under_product_category(
    $order_id,
    $ticket_bg,
    $ticket_text,
    $win_ticket_bg,
    $win_ticket_text
) {

    if ( ! compkit_order_has_game_products( $order_id ) ) {
        return '';
    }

    if ( ! function_exists('lty_get_instant_winner_log_id_by_ticket_id') ) {
        return '';
    }

    $grouped = compkit_group_tickets_by_product( $order_id );

    if ( empty( $grouped ) ) {
        return '';
    }

    $out = '';

foreach ( $grouped as $product_id => $ticket_ids ) {

    $product = wc_get_product( $product_id );

    // Detect wins
    $has_wins = false;

    foreach ( $ticket_ids as $tid ) {
        if ( lty_get_instant_winner_log_id_by_ticket_id( $tid ) ) {
            $has_wins = true;
            break;
        }
    }

    $is_non_instant = compkit_product_is_non_instant( $product );

    // Skip only if not wins AND not non-instant
    if ( ! $has_wins && ! $is_non_instant ) {
        continue;
    }

    // Handle non-instant (tickets only)
    if ( ! $has_wins && $is_non_instant ) {

        $ticket_numbers = [];

        foreach ( $ticket_ids as $tid ) {
            $ticket = lty_get_lottery_ticket( $tid );
            if ( $ticket && method_exists($ticket,'get_lottery_ticket_number') ) {
                $ticket_numbers[] = $ticket->get_lottery_ticket_number();
            }
        }

        $out .= compkit_render_ticket_list(
            $ticket_numbers,
            $ticket_bg,
            $ticket_text
        );

        continue;
    }

    // ORIGINAL CODE CONTINUES HERE
    $out .= compkit_build_instant_prize_table_for_tickets(
        $order_id,
        $ticket_ids,
        $ticket_bg,
        $ticket_text,
        $win_ticket_bg,
        $win_ticket_text
    );
}
    return $out;
}


function compkit_build_instant_prize_table_from_eiwr(
    array $wins,
    $ticket_bg,
    $ticket_text,
    $win_ticket_bg,
    $win_ticket_text
) {

    if ( empty( $wins ) ) return '';

    $rows = '';

    foreach ( $wins as $win ) {

        $ticket_num = isset($win['number']) ? (string) $win['number'] : '';
        $prize_html = isset($win['prize']) ? (string) $win['prize'] : '';

        // If EIWR already provided a coupon anchor, leave it as-is
        // (your later preg_replace_callback can still restyle it)
        $already_has_coupon_anchor = preg_match('#class="[^"]*instant-win-coupon[^"]*"#i', $prize_html);

        // Try to get coupon code from EIWR structured data (different versions name it differently)
        $coupon_code = '';
        if ( ! empty($win['coupon_code']) ) $coupon_code = (string) $win['coupon_code'];
        elseif ( ! empty($win['code']) )     $coupon_code = (string) $win['code'];
        elseif ( ! empty($win['coupon']) )   $coupon_code = (string) $win['coupon'];

        // If no structured field, try to extract a code from the HTML/text itself
        if ( $coupon_code === '' ) {
            if ( preg_match('#data-coupon="([^"]+)"#i', $prize_html, $m) ) {
                $coupon_code = trim($m[1]);
            } elseif ( preg_match('#\b([A-Z0-9_-]{5,})\b#', wp_strip_all_tags($prize_html), $m) ) {
                $coupon_code = trim($m[1]);
            }
        }

        // Detect "coupon prize" by label (even when code is not present)
        $looks_like_coupon = preg_match('#\bcoupon\b#i', wp_strip_all_tags($prize_html));

        // If it looks like a coupon and we have a code, build a button (and keep any image in $prize_html)
        if ( $looks_like_coupon && $coupon_code !== '' && ! $already_has_coupon_anchor ) {

            // keep image if EIWR included one
            $img = '';
            if ( preg_match('#<img[^>]*>#i', $prize_html, $im) ) {
                $img = $im[0];
            }

            $url = add_query_arg(
                'apply_coupon',
                rawurlencode($coupon_code),
                wc_get_page_permalink('shop')
            );

            $prize_html = '
                <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="vertical-align:middle;padding-right:8px;">
                            '.$img.'
                        </td>
                        <td style="vertical-align:middle;">
                            <strong>Discount Code</strong><br>
                            <a href="'.esc_url($url).'" style="
                                display:inline-block;
                                background:'.esc_attr($ticket_bg).';
                                color:'.esc_attr($ticket_text).' !important;
                                padding:10px 14px;
                                border-radius:10px;
                                text-decoration:none;
                                font-weight:900;
                                margin-top:6px;
                            ">
                                <font color="'.esc_attr($ticket_text).'">'.esc_html($coupon_code).'</font>
                            </a>
                        </td>
                    </tr>
                </table>';
        }

        $rows .= '
        <tr>
            <td>'.esc_html( $ticket_num ).'</td>
            <td>'.$prize_html.'</td>
        </tr>';
    }

    return '
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;">
        <thead>
            <tr>
                <th align="left">Ticket #</th>
                <th align="left">Prize</th>
            </tr>
        </thead>
        <tbody>'.$rows.'</tbody>
    </table>';
}
