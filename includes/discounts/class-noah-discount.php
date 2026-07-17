<?php
defined( 'ABSPATH' ) || exit;

class Noah_Discount {

    private static ?Noah_Discount $instance = null;

    public static function instance(): Noah_Discount {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function get_discount_percent( int $user_id, int $product_id ): float {
        if ( ! Noah_Membership::is_member( $user_id ) ) {
            return 0.0;
        }
        $rules = Noah_Discount_Rules::get_rules();

        // Product-level rule wins
        if ( isset( $rules['products'][ $product_id ] ) ) {
            return (float) $rules['products'][ $product_id ];
        }

        // Category-level rule
        foreach ( wc_get_product_cat_ids( $product_id ) as $cat_id ) {
            if ( isset( $rules['categories'][ $cat_id ] ) ) {
                return (float) $rules['categories'][ $cat_id ];
            }
        }

        return 0.0; // Explicit _noah_member_price on the product handles the rest via Noah_Pricing
    }
}
