<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Discount_Cart
 * Applies the flat member discount (Noah_Discount) to one-time "simple" products
 * in the cart. noah_subscription products are handled separately by Noah_Pricing
 * (first cycle, charged through normal checkout) and Noah_Stripe_Recurring
 * (the Stripe coupon on the recurring Subscription).
 */
class Noah_Discount_Cart {

    private static ?Noah_Discount_Cart $instance = null;

    public static function instance(): Noah_Discount_Cart {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Run at priority 98, just before Noah_Pricing at 99, to avoid conflicts
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_category_discounts' ], 98 );
        add_action( 'woocommerce_before_cart',          [ $this, 'show_discount_notice' ] );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'show_discount_notice' ] );
    }

    public function apply_category_discounts( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! Noah_Discount::is_eligible( $user_id ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];

            // noah_subscription products are priced by Noah_Pricing (first cycle)
            // and discounted in Stripe via the coupon on the recurring Subscription.
            if ( 'noah_subscription' === $product->get_type() ) {
                continue;
            }

            $percent = Noah_Discount::get_percent_for_product( $cart_item['product_id'] );
            if ( $percent <= 0 ) {
                continue;
            }

            $original = (float) $product->get_regular_price();
            if ( $original > 0 ) {
                $product->set_price( round( $original * ( 1 - $percent / 100 ), 2 ) );
            }
        }
    }

    public function show_discount_notice(): void {
        if ( ! Noah_Discount::is_eligible( get_current_user_id() ) ) {
            return;
        }
        wc_print_notice(
            esc_html__( 'Your NOAH member pricing is applied to all eligible items.', 'noah-protocol' ),
            'notice'
        );
    }
}
