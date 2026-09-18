<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Subscriptions
 *
 * Merges:
 *   - v8's full product type registration, add-to-cart UI, cart meta display
 *   - v8's product info block (duration, billing, total, member upsell)
 *   - Our DB-backed program access creation on order completion
 *   - Our billing cycle tracking (cycles_paid incremented per renewal)
 */
class Noah_Subscriptions {

    private static ?Noah_Subscriptions $instance = null;

    public static function instance(): Noah_Subscriptions {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_product_class' ], 5 );
        add_filter( 'product_type_selector',          [ $this, 'add_product_type'     ] );
        add_filter( 'woocommerce_product_class',      [ $this, 'set_product_class'    ], 10, 2 );
        add_filter( 'woocommerce_product_type_query', [ $this, 'validate_product_type'], 10, 2 );
        add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'autocomplete_virtual_orders' ], 10, 3 );
        add_action( 'woocommerce_noah_subscription_add_to_cart', [ $this, 'render_add_to_cart' ] );

        add_filter( 'woocommerce_get_item_data',
                    [ $this, 'display_cart_item_meta' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item',
                    [ $this, 'save_order_item_meta'   ], 10, 4 );
        add_filter( 'woocommerce_order_item_display_meta_key',
                    [ $this, 'format_meta_key'        ] );

        // Grant program access when order completes
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_start_program_access' ] );

        // Cron: revoke expired program accesses
        add_action( 'noah_daily_cleanup', [ $this, 'revoke_expired_program_accesses' ] );

        // noah_subscription products are sold_individually (see
        // WC_Product_Noah_Subscription). WooCommerce's own "already in cart"
        // duplicate guard for sold_individually products can false-positive
        // during checkout (something re-triggers WC_Cart::add_to_cart() for
        // an item already present, e.g. the Stripe Optimized Checkout
        // extension rebuilding checkout-session line items), throwing "You
        // cannot add another X to your cart" even with a single legitimate
        // item in the cart. Our own DB (Noah_DB program_access / members
        // tables) is the real source of truth for "does this user already
        // have this" — WooCommerce's cart-level guard is redundant here and
        // only causes false blocks, so disable it for this product type.
        add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', [ $this, 'allow_sold_individually_readd' ], 10, 2 );
    }

    public function allow_sold_individually_readd( bool $found_in_cart, int $product_id ): bool {
        $product = wc_get_product( $product_id );
        return ( $product && 'noah_subscription' === $product->get_type() ) ? false : $found_in_cart;
    }
    public function autocomplete_virtual_orders( string $status, int $order_id, WC_Order $order ): string {
    foreach ( $order->get_items() as $item ) {
        $product = wc_get_product( $item->get_product_id() );
        if ( $product && $product->is_virtual() ) {
            return 'completed';
        }
    }
    return $status;
}
    // ---------------------------------------------------------------
    // Product type registration
    // ---------------------------------------------------------------

    public function register_product_class(): void {
        if ( ! class_exists( 'WC_Product' ) ) {
            return;
        }
        require_once NOAH_PATH . 'product-types/class-wc-product-noah-subscription.php';
    }

    public function add_product_type( array $types ): array {
        $types['noah_subscription'] = __( 'NOAH Subscription', 'noah-protocol' );
        return $types;
    }

    public function set_product_class( string $classname, string $product_type ): string {
        return 'noah_subscription' === $product_type ? 'WC_Product_Noah_Subscription' : $classname;
    }

    public function validate_product_type( bool $found, string $product_type ): bool {
        return 'noah_subscription' === $product_type ? true : $found;
    }

    // ---------------------------------------------------------------
    // Add-to-cart on product page
    // ---------------------------------------------------------------

    public function render_add_to_cart(): void {
        global $product;
        if ( ! $product || ! $product->is_purchasable() ) {
            return;
        }
        $this->render_program_info_block( $product );
        wc_get_template(
            'single-product/add-to-cart/noah_subscription.php',
            [],
            '',
            NOAH_PATH . 'woocommerce/'
        );
    }

    private function render_program_info_block( WC_Product $product ): void {
        $product_id      = $product->get_id();
        $nonmember_price = (float) get_post_meta( $product_id, '_noah_nonmember_price', true );
        $member_price    = Noah_Discount::get_member_price_for_product( $product_id, $nonmember_price );
        $is_membership   = 'yes' === get_post_meta( $product_id, '_noah_is_membership_plan', true );
        $period          = get_post_meta( $product_id, '_noah_billing_period', true ) ?: 'week';
        $is_onetime      = 'onetime' === $period;
        // Billing cycles/period don't apply to a One Time Payment product — its
        // duration is described on the product page, not tracked here.
        $cycles          = $is_onetime ? 0 : (int) get_post_meta( $product_id, '_noah_billing_cycles', true );
        $is_eligible     = Noah_Discount::is_eligible( get_current_user_id() );
        $active_price    = $is_eligible ? $member_price : $nonmember_price;
        // Frontend total is always based on the non-member rate — an auto-calculated
        // marketing figure (e.g. "$120 for 3 weeks"), independent of what any one
        // buyer is actually charged.
        $total_price     = $cycles > 0 ? $nonmember_price * $cycles : $nonmember_price;
        $cycle_days      = [ 'day' => 1, 'week' => 7, 'month' => 30 ][ $period ] ?? 7;
        $total_days      = max( $cycles, 0 ) * $cycle_days;
        $period_noun     = [ 'day' => __( 'daily', 'noah-protocol' ), 'week' => __( 'weekly', 'noah-protocol' ), 'month' => __( 'monthly', 'noah-protocol' ) ][ $period ] ?? __( 'weekly', 'noah-protocol' );
        ?>
        <div class="noah-program-info">

            <?php if ( $is_onetime ) : ?>
            <div class="noah-program-meta">
                <div class="noah-meta-item">
                    <span class="noah-meta-icon" aria-hidden="true">&#128179;</span>
                    <div>
                        <span class="noah-meta-label"><?php esc_html_e( 'Program total', 'noah-protocol' ); ?></span>
                        <span class="noah-meta-value noah-meta-total"><?php echo wp_kses_post( wc_price( $total_price ) ); ?></span>
                    </div>
                </div>
            </div>
            <?php elseif ( $cycles > 0 ) : ?>
            <div class="noah-program-meta">

                <div class="noah-meta-item">
                    <span class="noah-meta-icon" aria-hidden="true">&#9200;</span>
                    <div>
                        <span class="noah-meta-label"><?php esc_html_e( 'Duration', 'noah-protocol' ); ?></span>
                        <span class="noah-meta-value">
                            <?php if ( 'day' === $period ) : ?>
                                <?php echo esc_html( sprintf( _n( '%d day', '%d days', $cycles, 'noah-protocol' ), $cycles ) ); ?>
                            <?php elseif ( 'month' === $period ) : ?>
                                <?php echo esc_html( sprintf(
                                    _n( '%1$d month (%2$d days)', '%1$d months (%2$d days)', $cycles, 'noah-protocol' ),
                                    $cycles, $total_days
                                ) ); ?>
                            <?php else : ?>
                                <?php echo esc_html( sprintf(
                                    _n( '%1$d week (%2$d days)', '%1$d weeks (%2$d days)', $cycles, 'noah-protocol' ),
                                    $cycles, $total_days
                                ) ); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="noah-meta-item">
                    <span class="noah-meta-icon" aria-hidden="true">&#8635;</span>
                    <div>
                        <span class="noah-meta-label"><?php esc_html_e( 'Billing', 'noah-protocol' ); ?></span>
                        <span class="noah-meta-value">
                            <?php echo esc_html( sprintf(
                                /* translators: 1: number of cycles, 2: billing cadence (daily/weekly/monthly) */
                                _n( '%1$d %2$s payment', '%1$d %2$s payments', $cycles, 'noah-protocol' ),
                                $cycles, $period_noun
                            ) ); ?>
                        </span>
                    </div>
                </div>

                <div class="noah-meta-item">
                    <span class="noah-meta-icon" aria-hidden="true">&#128179;</span>
                    <div>
                        <span class="noah-meta-label"><?php esc_html_e( 'Program total', 'noah-protocol' ); ?></span>
                        <span class="noah-meta-value noah-meta-total"><?php echo wp_kses_post( wc_price( $total_price ) ); ?></span>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <?php if ( ! $is_eligible && ! $is_membership && $member_price > 0 && $member_price < $nonmember_price ) : ?>
            <div class="noah-member-upsell">
                <span class="noah-upsell-icon" aria-hidden="true">&#127807;</span>
                <span>
                    <?php
                    $savings = ( $nonmember_price - $member_price ) * max( $cycles, 1 );
                    printf(
                        wp_kses(
                            /* translators: 1: member price per week, 2: total savings */
                            __( '<strong>Are you a member?</strong> You pay %1$s/week and save %2$s on this program.', 'noah-protocol' ),
                            [ 'strong' => [] ]
                        ),
                        wp_kses_post( wc_price( $member_price ) ),
                        wp_kses_post( wc_price( $savings ) )
                    );
                    ?>
                    <a href="<?php echo esc_url( 'https://noahprotocol.com/pricing/#membership' ); ?>" class="noah-upsell-link">
                        <?php esc_html_e( 'See membership plan', 'noah-protocol' ); ?>
                    </a>
                </span>
            </div>
            <?php endif; ?>

            <?php if ( $cycles > 0 ) : ?>
            <p class="noah-auto-cancel-note">
                <?php esc_html_e( 'Subscription cancels automatically when the program ends. No action required.', 'noah-protocol' ); ?>
            </p>
            <?php endif; ?>

        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Cart and order meta
    // ---------------------------------------------------------------

    public function display_cart_item_meta( array $item_data, array $cart_item ): array {
        $product_id = $cart_item['product_id'];
        $period     = get_post_meta( $product_id, '_noah_billing_period', true ) ?: 'week';

        if ( 'onetime' === $period ) {
            $item_data[] = [
                'key'   => __( 'Billing', 'noah-protocol' ),
                'value' => __( 'One-time payment', 'noah-protocol' ),
            ];
            return $item_data;
        }

        $cycles      = (int) get_post_meta( $product_id, '_noah_billing_cycles', true );
        $period_noun = [ 'day' => __( 'Daily', 'noah-protocol' ), 'week' => __( 'Weekly', 'noah-protocol' ), 'month' => __( 'Monthly', 'noah-protocol' ) ][ $period ] ?? __( 'Weekly', 'noah-protocol' );
        $period_unit = [ 'day' => _n( '%d day', '%d days', $cycles, 'noah-protocol' ), 'week' => _n( '%d week', '%d weeks', $cycles, 'noah-protocol' ), 'month' => _n( '%d month', '%d months', $cycles, 'noah-protocol' ) ][ $period ] ?? _n( '%d week', '%d weeks', $cycles, 'noah-protocol' );

        if ( $cycles > 0 ) {
            $item_data[] = [
                'key'   => __( 'Billing', 'noah-protocol' ),
                /* translators: 1: billing cadence (Daily/Weekly/Monthly), 2: cycle count phrase, e.g. "3 weeks" */
                'value' => sprintf( __( '%1$s for %2$s', 'noah-protocol' ), $period_noun, sprintf( $period_unit, $cycles ) ),
            ];
        } elseif ( 0 === $cycles && 'yes' === get_post_meta( $product_id, '_noah_is_membership_plan', true ) ) {
            $item_data[] = [
                'key'   => __( 'Billing', 'noah-protocol' ),
                'value' => __( 'Weekly — cancel any time', 'noah-protocol' ),
            ];
        }
        return $item_data;
    }

    public function save_order_item_meta( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
        $cycles = (int) get_post_meta( $values['product_id'], '_noah_billing_cycles', true );
        if ( $cycles > 0 ) {
            $item->add_meta_data( '_noah_billing_cycles', $cycles, true );
        }
    }

    public function format_meta_key( string $key ): string {
        return '_noah_billing_cycles' === $key ? __( 'Billing cycles', 'noah-protocol' ) : $key;
    }

    // ---------------------------------------------------------------
    // Program access on order completion
    // ---------------------------------------------------------------

    public function maybe_start_program_access( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = (int) $order->get_customer_id();
        if ( ! $user_id ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            $product    = wc_get_product( $product_id );
            if ( ! $product || $product->get_type() !== 'noah_subscription' ) {
                continue;
            }
            // Skip the membership plan itself — handled by Noah_Membership
            if ( 'yes' === get_post_meta( $product_id, '_noah_is_membership_plan', true ) ) {
                continue;
            }

            $period = get_post_meta( $product_id, '_noah_billing_period', true ) ?: 'week';

            // A One Time Payment program (e.g. an onsite/in-person program whose
            // duration is already described on the product page) has no billing
            // cycles and no automatic access expiry — access simply doesn't expire
            // on its own.
            if ( 'onetime' === $period ) {
                $cycles     = 1;
                $expires_at = null;
            } else {
                $cycles     = (int) get_post_meta( $product_id, '_noah_billing_cycles', true ) ?: 1;
                $expires_at = self::calculate_expiry( $cycles, $period );
            }

            Noah_DB::upsert_program_access( $user_id, $product_id, [
                'order_id'       => $order_id,
                'status'         => 'active',
                'billing_cycles' => $cycles,
                'cycles_paid'    => 1,
                'started_at'     => current_time( 'mysql' ),
                'expires_at'     => $expires_at,
            ] );

            Noah_DB::log_event( $user_id, $product_id, 'program_access_granted', "Order #{$order_id} | " . ( 'onetime' === $period ? 'one-time payment' : "{$cycles} {$period}(s)" ) );
            do_action( 'noah_program_subscription_started', $user_id, $product_id, $order_id );
        }
    }

    public function record_renewal( int $user_id, int $product_id ): void {
        $access = Noah_DB::get_program_access( $user_id, $product_id );
        if ( ! $access ) {
            return;
        }
        $cycles_paid    = (int) $access->cycles_paid + 1;
        $billing_cycles = (int) $access->billing_cycles;

        $update = [ 'cycles_paid' => $cycles_paid ];
        if ( $billing_cycles > 0 && $cycles_paid >= $billing_cycles ) {
            $update['status'] = 'completing';
            do_action( 'noah_program_final_cycle_paid', $user_id, $product_id );
        }

        Noah_DB::upsert_program_access( $user_id, $product_id, $update );
        Noah_DB::log_event( $user_id, $product_id, 'program_renewal', "Cycle {$cycles_paid}/{$billing_cycles}" );
    }

    public static function calculate_expiry( int $cycles, string $period ): string {
        $unit = [ 'day' => 'days', 'week' => 'weeks', 'month' => 'months' ][ $period ] ?? 'weeks';
        return gmdate( 'Y-m-d H:i:s', strtotime( "+{$cycles} {$unit}", time() ) );
    }

    // ---------------------------------------------------------------
    // Cron: clean up expired accesses
    // ---------------------------------------------------------------

    public function revoke_expired_program_accesses(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT user_id, product_id FROM {$wpdb->prefix}noah_program_access
             WHERE status IN ('active','completing')
             AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        foreach ( $rows as $row ) {
            Noah_Access_Revoke::instance()->revoke_program(
                (int) $row->user_id, (int) $row->product_id, 'expired'
            );
        }
    }

}
