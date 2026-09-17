<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Discount
 *
 * Member discount eligibility requires BOTH:
 *   - an active NOAH membership (Noah_Membership::is_member)
 *   - an existing Stripe Customer record for that user (already recognized by Stripe)
 *
 * Eligible users get a flat percentage off (default 50%), realized either as
 * the Stripe coupon attached to their program Subscription (Noah_Stripe_Recurring)
 * or, for one-time simple products, as a direct cart price reduction (Noah_Discount_Cart).
 */
class Noah_Discount {

    private static ?Noah_Discount $instance = null;

    public static function instance(): Noah_Discount {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function is_eligible( int $user_id ): bool {
        if ( ! $user_id || ! Noah_Membership::is_member( $user_id ) ) {
            return false;
        }
        return ! empty( Noah_Stripe_Customer_Sync::get_customer_id( $user_id ) );
    }

    public static function get_percent(): float {
        return (float) get_option( 'noah_member_discount_percent', 50 );
    }

    public static function get_coupon_id(): string {
        return (string) get_option( 'noah_stripe_coupon_id', 'OwNgTFij' );
    }

    /**
     * Per-product discount percent, falling back to the global default when
     * the product has no override set (blank meta = use the global rule).
     */
    public static function get_percent_for_product( int $product_id ): float {
        $override = get_post_meta( $product_id, '_noah_member_discount_percent', true );
        if ( '' !== $override && is_numeric( $override ) ) {
            return (float) $override;
        }
        return self::get_percent();
    }

    /**
     * Per-product Stripe coupon, falling back to the global default. Only
     * relevant for noah_subscription products: cycle 2+ billing must use a
     * coupon whose percent_off in Stripe matches get_percent_for_product().
     */
    public static function get_coupon_id_for_product( int $product_id ): string {
        $override = get_post_meta( $product_id, '_noah_stripe_coupon_id', true );
        if ( '' !== $override ) {
            return (string) $override;
        }
        return self::get_coupon_id();
    }
}
