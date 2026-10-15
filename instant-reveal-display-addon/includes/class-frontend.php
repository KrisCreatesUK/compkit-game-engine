<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EIWR_Frontend {

    public static function register(): void {
        add_action( 'wp_enqueue_scripts',         [ __CLASS__, 'enqueue'           ] );
        add_action( 'wp_footer',                  [ __CLASS__, 'wp_footer'         ] );
        add_filter( 'body_class',                 [ __CLASS__, 'body_class_simple' ], 10  );
        add_filter( 'body_class',                 [ __CLASS__, 'body_class_refined'], 99  );
        add_filter( 'woocommerce_order_item_class', [ __CLASS__, 'item_class'      ], 10, 3 );
        add_action( 'woocommerce_order_item_meta_end', [ __CLASS__, 'render'       ], 21, 3 );
        add_action( 'wp_ajax_eiwr_apply_coupon',        [ __CLASS__, 'apply_coupon_ajax' ] );
        add_action( 'wp_ajax_nopriv_eiwr_apply_coupon', [ __CLASS__, 'apply_coupon_ajax' ] );
        add_shortcode( 'instant_reveal',          [ __CLASS__, 'shortcode'         ] );
    }

    /* ── Licence ───────────────────────────────────────────────────── */

    public static function is_licensed(): bool {
        if ( ! defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ) {
            return true;
        }
        return function_exists( 'giveaways_is_module_licensed' )
            && giveaways_is_module_licensed( 'instant_display' );
    }

    /* ── Category helpers ──────────────────────────────────────────── */

    public static function game_slugs(): array {
        return [ 'instant-reveal', 'spin', 'slots', 'scratchcard', 'football' ];
    }

    public static function is_game_category_by_slugs( array $slugs ): bool {
        if ( empty( $slugs ) ) return false;
        return (bool) array_intersect( $slugs, self::game_slugs() );
    }

    public static function is_instant_reveal_only_by_slugs( array $slugs ): bool {
        return is_array( $slugs ) && in_array( 'instant-reveal', $slugs, true );
    }

    public static function is_game_category( int $product_id ): bool {
        $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
        if ( empty( $cats ) || is_wp_error( $cats ) ) return false;
        return (bool) array_intersect( $cats, self::game_slugs() );
    }

    /* ── Page detection ────────────────────────────────────────────── */

    private static function on_relevant_page(): bool {
        return is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' );
    }

    private static function current_order_id(): int {
        return absint( get_query_var( 'order-received' ) ?: get_query_var( 'view-order' ) );
    }

    /* ── Enqueue ───────────────────────────────────────────────────── */

    public static function enqueue(): void {
        if ( ! self::is_licensed() ) return;
        if ( ! self::on_relevant_page() ) return;

        wp_enqueue_style(
            'eiwr-css',
            EIWR_URL . 'instant-reveal.css',
            [],
            eiwr_asset_version()
        );

        wp_enqueue_script(
            'eiwr-js',
            EIWR_URL . 'instant-reveal.js',
            [],
            eiwr_asset_version(),
            true
        );

        $t = EIWR_Settings::get();
        wp_localize_script( 'eiwr-js', 'eiwrSettings', [
            'style'     => $t['style']     ?? 'giftbox',
            'lose_text' => $t['lose_text'] ?? 'Sorry, better luck next time',
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
        ] );

        wp_add_inline_style( 'eiwr-css', '
            body:not(.has-instant-reveal) .enhanced-instant-win-reveal {
                display:none !important;
            }
        ' );

        $css_vars = EIWR_Settings::build_css_vars();
        if ( $css_vars ) {
            wp_add_inline_style( 'eiwr-css', '.enhanced-instant-win-reveal{' . $css_vars . '}' );
        }
    }

    /* ── Order item class ──────────────────────────────────────────── */

    public static function item_class( string $class, $item, $order ): string {
        if ( ! self::is_licensed() ) return $class;
        if ( ! is_a( $order, 'WC_Order' ) ) return $class;

        $product = $item->get_product();
        if ( ! is_a( $product, 'WC_Product' ) ) return $class;

        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
        if ( ! empty( $categories ) && self::is_game_category_by_slugs( $categories ) ) {
            $class .= ' instant-win-thankyou-container';
            if ( self::is_instant_reveal_only_by_slugs( $categories ) ) {
                $class .= ' instant-reveal-thankyou-container';
            }
        }

        return $class;
    }

    /* ── Order item render ─────────────────────────────────────────── */

    public static function render( int $item_id, $item, $order ): void {
        if ( ! self::is_licensed() ) return;
        if ( ! is_a( $order, 'WC_Order' ) ) return;
        if ( $order->get_user_id() > 0 && get_current_user_id() !== (int) $order->get_user_id() ) return;
        if ( ! $order->is_paid() ) return;
        if (
            ! function_exists( 'lty_get_lottery_ticket' )
            || ! function_exists( 'lty_get_instant_winner_log_id_by_ticket_id' )
            || ! function_exists( 'lty_get_instant_winner_log' )
        ) return;

        $product = $item->get_product();
        if ( ! $product ) return;

        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
        if ( ! self::is_instant_reveal_only_by_slugs( $categories ) ) return;

        $data = self::collect_item_tickets( $order, $item );
        if ( empty( $data['all_numbers'] ) ) return;

        $settings = EIWR_Settings::get_product_settings( $product->get_id() );
        self::render_reveal_html( $data['all_numbers'], $data['winning'], $settings, $order->get_id(), $data['ticket_ids'] );
    }

    /* ── Body class (simple — adds has-instant-reveal for any game category) ── */

    public static function body_class_simple( array $classes ): array {
        if ( ! self::is_licensed() ) return $classes;
        if ( ! self::on_relevant_page() ) return $classes;

        $order_id = absint( get_query_var( 'order-received', 0 ) );
        if ( ! $order_id ) $order_id = absint( get_query_var( 'view-order', 0 ) );

        $order = wc_get_order( $order_id );
        if ( ! $order ) return $classes;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
            if ( self::is_game_category_by_slugs( $cats ) ) {
                $classes[] = 'has-instant-reveal';
                break;
            }
        }

        return $classes;
    }

    /* ── Body class (refined — corrects has-instant-reveal, adds has-game-product) ── */

    public static function body_class_refined( array $classes ): array {
        if ( ! self::is_licensed() ) return $classes;
        if ( ! self::on_relevant_page() ) return $classes;

        $order = wc_get_order( self::current_order_id() );
        if ( ! $order ) return $classes;

        $has_game = false;
        $has_ir   = false;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
            if ( self::is_game_category_by_slugs( $cats ) ) $has_game = true;
            if ( self::is_instant_reveal_only_by_slugs( $cats ) ) $has_ir = true;
        }

        if ( $has_game ) $classes[] = 'has-game-product';
        if ( $has_ir ) {
            $classes[] = 'has-instant-reveal';
        } else {
            $classes = array_values( array_diff( $classes, [ 'has-instant-reveal' ] ) );
        }

        return array_unique( $classes );
    }

    /* ── Coupon AJAX ───────────────────────────────────────────────── */

    public static function apply_coupon_ajax(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public-facing coupon apply, no nonce required
        if ( empty( $_POST['code'] ) ) {
            wp_send_json_error( [ 'message' => 'No coupon code sent' ] );
        }
        $code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['code'] ) ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! function_exists( 'WC' ) ) {
            wp_send_json_error( [ 'message' => 'WooCommerce not loaded' ] );
        }

        if ( WC()->session === null ) WC()->initialize_session();
        if ( ! WC()->cart ) wc_load_cart();

        WC()->cart->get_cart();
        wc_clear_notices();

        if ( in_array( $code, WC()->cart->get_applied_coupons(), true ) ) {
            wp_send_json_success( [ 'message' => 'Coupon already applied', 'code' => $code ] );
        }

        $result = WC()->cart->apply_coupon( $code );
        WC()->cart->calculate_totals();

        if ( ! $result ) {
            $messages = wc_get_notices( 'error' );
            wc_clear_notices();
            $msg = ! empty( $messages[0]['notice'] )
                ? wp_strip_all_tags( $messages[0]['notice'] )
                : 'Coupon rejected';
            if ( stripos( $msg, 'already applied' ) !== false ) {
                wp_send_json_success( [ 'message' => 'Coupon already applied', 'code' => $code ] );
            }
            wp_send_json_error( [ 'message' => $msg, 'code' => $code ] );
        }

        wp_send_json_success( [ 'message' => 'Coupon applied', 'code' => $code ] );
    }

    /* ── Footer coupon popup ───────────────────────────────────────── */

    public static function wp_footer(): void {
        if ( ! self::on_relevant_page() ) return;
        ?>
        <style>
            .eiwr-coupon-popup {
                position: fixed; left: 50%; top: 20%; transform: translateX(-50%);
                background: #2ecc71; color: #fff; padding: 14px 18px;
                border-radius: 8px; font-size: 15px; z-index: 99999;
                box-shadow: 0 6px 18px rgba(0,0,0,0.25); display: none;
            }
            .eiwr-coupon-popup.error { background: #e74c3c; }
        </style>
        <div class="eiwr-coupon-popup" id="eiwrCouponPopup"></div>
        <script>
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.instant-win-coupon');
            if (!btn) return;
            var code = btn.getAttribute('data-coupon');
            if (!code) return;
            var popup = document.getElementById('eiwrCouponPopup');
            popup.classList.remove('error');
            popup.textContent = 'Applying coupon...';
            popup.style.display = 'block';
            var form = new FormData();
            form.append('action', 'eiwr_apply_coupon');
            form.append('code', code);
            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST', credentials: 'same-origin', body: form
            })
            .then(r => r.json())
            .then(function(resp){
                if (resp && resp.success) {
                    popup.textContent = 'Coupon applied: ' + code;
                } else {
                    popup.textContent = 'Coupon could not be applied';
                    popup.classList.add('error');
                }
                clearTimeout(window.eiwrPopupTimer);
                window.eiwrPopupTimer = setTimeout(function(){ popup.style.display = 'none'; }, 3000);
            })
            .catch(function(){
                popup.textContent = 'Coupon could not be applied';
                popup.classList.add('error');
                clearTimeout(window.eiwrPopupTimer);
                window.eiwrPopupTimer = setTimeout(function(){ popup.style.display = 'none'; }, 3000);
            });
        });
        </script>
        <?php
    }

    /* ── Shortcode ─────────────────────────────────────────────────── */

    public static function shortcode(): string {
        if (
            ! function_exists( 'wc_get_order' )
            || ! function_exists( 'lty_get_lottery_ticket' )
            || ! function_exists( 'lty_get_instant_winner_log_id_by_ticket_id' )
            || ! function_exists( 'lty_get_instant_winner_log' )
        ) return '';

        $order_id = self::current_order_id();
        if ( ! $order_id ) return '';

        $order = wc_get_order( $order_id );
        if ( ! $order ) return '';
        if ( $order->get_user_id() > 0 && get_current_user_id() !== (int) $order->get_user_id() ) return '';
        if ( ! $order->is_paid() ) return '';

        $html = '';
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            if ( ! self::is_game_category( $product->get_id() ) ) continue;

            $data = self::collect_item_tickets( $order, $item );
            if ( empty( $data['all_numbers'] ) ) continue;

            $settings = EIWR_Settings::get_product_settings( $product->get_id() );
            ob_start();
            self::render_reveal_html( $data['all_numbers'], $data['winning'], $settings, $order_id, $data['ticket_ids'] );
            $html .= ob_get_clean();
        }

        return $html;
    }

    /* ── Public utility for email addon ────────────────────────────── */

    public static function get_instant_win_data( WC_Order $order, $product_id ): array {
        if (
            ! function_exists( 'lty_get_lottery_ticket' )
            || ! function_exists( 'lty_get_instant_winner_log_id_by_ticket_id' )
            || ! function_exists( 'lty_get_instant_winner_log' )
        ) return [];

        $ticket_ids = (array) $order->get_meta( 'lty_ticket_ids_in_order' );
        if ( empty( $ticket_ids ) ) return [];

        $wins = [];
        foreach ( $ticket_ids as $ticket_id ) {
            $ticket = lty_get_lottery_ticket( $ticket_id );
            if ( ! $ticket || ! $ticket->exists() ) continue;
            if ( (int) $ticket->get_product_id() !== (int) $product_id ) continue;

            $log_id = lty_get_instant_winner_log_id_by_ticket_id( $ticket_id );
            if ( ! $log_id ) continue;

            $log = lty_get_instant_winner_log( $log_id );
            if ( ! $log || ! method_exists( $log, 'has_status' ) || ! $log->has_status( 'lty_won' ) ) continue;

            $wins[] = [
                'number' => $ticket->get_lottery_ticket_number(),
                'prize'  => self::build_prize_display( $log ),
            ];
        }

        return $wins;
    }

    /* ── Private helpers ───────────────────────────────────────────── */

    private static function collect_item_tickets( WC_Order $order, $item ): array {
        $ticket_ids  = (array) $order->get_meta( 'lty_ticket_ids_in_order' );
        $all_numbers = [];
        $item_ids    = [];
        $winning     = [];

        foreach ( $ticket_ids as $ticket_id ) {
            $ticket = lty_get_lottery_ticket( $ticket_id );
            if ( ! $ticket || ! $ticket->exists() ) continue;
            if ( $ticket->get_product_id() != $item->get_product_id() ) continue;

            $all_numbers[] = $ticket->get_lottery_ticket_number();
            $item_ids[]    = absint( $ticket_id );

            $log_id = lty_get_instant_winner_log_id_by_ticket_id( $ticket_id );
            if ( ! $log_id ) continue;

            $log = lty_get_instant_winner_log( $log_id );
            if ( ! $log || ! method_exists( $log, 'has_status' ) || ! $log->has_status( 'lty_won' ) ) continue;

            $winning[] = [
                'number' => $ticket->get_lottery_ticket_number(),
                'prize'  => self::build_prize_display( $log ),
            ];
        }

        return [ 'all_numbers' => $all_numbers, 'ticket_ids' => $item_ids, 'winning' => $winning ];
    }

    private static function build_prize_display( $log ): string {
        $group_title = method_exists( $log, 'get_prize_group_title' )
            ? trim( (string) $log->get_prize_group_title() )
            : '';
        $group_image = method_exists( $log, 'get_image_url' )
            ? trim( (string) $log->get_image_url() )
            : '';
        $prize_type  = method_exists( $log, 'get_prize_type' )
            ? trim( (string) $log->get_prize_type() )
            : '';

        $label        = $group_title;
        $linked_label = '';
        $coupon_code  = '';

        if ( $prize_type === 'coupon' && method_exists( $log, 'get_coupon_code' ) && $log->get_coupon_code() ) {
            $coupon_code = trim( (string) $log->get_coupon_code() );
        }

        if ( method_exists( $log, 'get_gift_product_id' ) && $log->get_gift_product_id() ) {
            $gift = wc_get_product( $log->get_gift_product_id() );
            if ( $gift ) {
                $label        = $label ?: $gift->get_name();
                $linked_label = '<a href="' . esc_url( get_permalink( $gift->get_id() ) ) . '">'
                    . esc_html( $label ) . '</a>';
            }
        }

        if ( ! $label && method_exists( $log, 'get_prize_message' ) ) {
            $label = $log->get_prize_message();
        }

        $image_tag = $group_image
            ? '<img src="' . esc_url( $group_image ) . '" style="width:50px;height:50px;border-radius:6px;vertical-align:middle;margin-right:5px;">'
            : '';

        $display = $image_tag . ( $linked_label ?: esc_html( $label ) );

        if ( $coupon_code ) {
            $display .= '<br><small class="instant-win-coupon-wrap">'
                . esc_html__( 'Click to Apply Coupon', 'compkit-game-engine' ) . ': '
                . '<button type="button" class="instant-win-coupon" data-coupon="' . esc_attr( $coupon_code ) . '">'
                . esc_html( $coupon_code ) . '</button></small>';
        }

        return $display;
    }

    private static function render_reveal_html( array $all_numbers, array $winning_tickets, array $settings, int $order_id = 0, array $ticket_ids = [] ): void {
        $css_vars = EIWR_Settings::build_css_vars_from( $settings );
        $style    = $settings['style']            ?: 'giftbox';
        $title    = $settings['ticket_title']     ?: 'Your Tickets';
        $instr    = $settings['instruction_text'] ?: 'Click to reveal your prize';
        $win_hdg  = $settings['win_heading']      ?: 'Congratulations, you have won:';
        $lose_txt = $settings['lose_text']        ?: 'Sorry, better luck next time';

        /* The order and the tickets this block covers. The reveal is one click for
           the whole item, so every ticket listed here is reported as revealed —
           that is what releases the order's held emails and sends the win email. */
        echo '<div class="enhanced-instant-win-reveal" style="' . esc_attr( $css_vars ) . '" data-lose-text="' . esc_attr( $lose_txt ) . '"'
           . ' data-order-id="' . esc_attr( $order_id ) . '"'
           . ' data-ticket-ids="' . esc_attr( implode( ',', array_map( 'absint', $ticket_ids ) ) ) . '">';

        echo '<div class="ticket-wrap">';
        echo '<div class="ticket-title">' . esc_html( $title ) . '</div>';
        echo '<div class="ticket-list">';
        foreach ( $all_numbers as $n ) {
            echo '<span class="ticket-badge">' . esc_html( $n ) . '</span>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div class="gift-reveal-container">';
        if ( $style === 'envelope' ) {
            echo '<div class="eiwr-envelope"><div class="eiwr-env-body"><div class="eiwr-env-flap"></div><div class="eiwr-env-letter">&#x2709;</div></div></div>';
        } elseif ( $style === 'flipcard' ) {
            echo '<div class="eiwr-flipcard"><div class="eiwr-flipcard-inner">'
               . '<div class="eiwr-flipcard-back"><div class="eiwr-card-pattern"></div><div class="eiwr-card-qmark">?</div></div>'
               . '<div class="eiwr-flipcard-front"><div class="eiwr-card-icon">&#127873;</div></div>'
               . '</div></div>';
        } else {
            echo '<div class="eiwr-giftbox"><div class="eiwr-gift-lid"></div><div class="eiwr-gift-body"></div><div class="eiwr-gift-bow"></div></div>';
        }
        echo '</div>';

        echo '<p class="eiwr-instruction">' . esc_html( $instr ) . '</p>';

        echo '<div class="instant-win-message hidden">';
        if ( ! empty( $winning_tickets ) ) {
            echo '<p class="instant-win-title"><strong>' . esc_html( $win_hdg ) . '</strong></p>';
            echo '<table class="instant-win-table"><thead><tr><th>Ticket #</th><th>Prize</th></tr></thead><tbody>';
            foreach ( $winning_tickets as $win ) {
                echo '<tr><td>' . esc_html( $win['number'] ) . '</td><td>' . wp_kses_post( $win['prize'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '</div>';
    }
}
