<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Discount_Cart
 * Applies category/product-rule discounts automatically at cart.
 * Works alongside Noah_Pricing: Noah_Pricing handles explicit _noah_member_price,
 * this class handles any category-wide rules set in the admin.
 * No coupon code required.
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
        if ( ! $user_id || ! Noah_Membership::is_member( $user_id ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            $product    = $cart_item['data'];
            $product_id = (int) $product->get_id();

            // Skip products that already have an explicit member price — Noah_Pricing handles those
            $explicit_member_price = get_post_meta( $product_id, '_noah_member_price', true );
            if ( '' !== $explicit_member_price && is_numeric( $explicit_member_price ) ) {
                continue;
            }

            $percent = Noah_Discount::get_discount_percent( $user_id, $product_id );
            if ( $percent > 0 ) {
                $original = (float) $product->get_regular_price();
                if ( $original > 0 ) {
                    $product->set_price( round( $original * ( 1 - $percent / 100 ), 2 ) );
                }
            }
        }
    }

    public function show_discount_notice(): void {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! Noah_Membership::is_member( $user_id ) ) {
            return;
        }
        wc_print_notice(
            esc_html__( 'Your NOAH member pricing is applied to all eligible items.', 'noah-protocol' ),
            'notice'
        );
    }
}
