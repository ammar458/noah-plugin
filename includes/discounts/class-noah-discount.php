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

    const STRIPE_CUSTOMER_META = '_stripe_customer_id';

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
        return ! empty( get_user_meta( $user_id, self::STRIPE_CUSTOMER_META, true ) );
    }

    public static function get_percent(): float {
        return (float) get_option( 'noah_member_discount_percent', 50 );
    }

    public static function get_coupon_id(): string {
        return (string) get_option( 'noah_stripe_coupon_id', 'OwNgTFij' );
    }
}
