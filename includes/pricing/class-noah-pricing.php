<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Pricing
 * Applies member prices in the cart and shows dual price HTML on product pages.
 * Taken from v8 — this is the better implementation.
 */
class Noah_Pricing {

    private static ?Noah_Pricing $instance = null;

    public static function instance(): Noah_Pricing {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_member_prices'  ], 99 );
        add_filter( 'woocommerce_get_price_html',          [ $this, 'show_dual_price_html' ], 10, 2 );
    }

    /**
     * Override cart item prices for members using the stored member price meta.
     * This covers products that have an explicit _noah_member_price set.
     * The discount rules engine (Noah_Discount_Cart) handles category-level discounts separately.
     */
    public function apply_member_prices( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $is_member = Noah_Membership::is_member( get_current_user_id() );

        foreach ( $cart->get_cart() as $cart_item ) {
            $product    = $cart_item['data'];
            $product_id = $cart_item['product_id'];

            if ( $is_member ) {
                $member_price = get_post_meta( $product_id, '_noah_member_price', true );
                if ( '' !== $member_price && is_numeric( $member_price ) ) {
                    $product->set_price( (float) $member_price );
                }
            } else {
                if ( 'noah_subscription' === $product->get_type() ) {
                    $nonmember_price = get_post_meta( $product_id, '_noah_nonmember_price', true );
                    if ( '' !== $nonmember_price && is_numeric( $nonmember_price ) ) {
                        $product->set_price( (float) $nonmember_price );
                    }
                }
            }
        }
    }

    /**
     * Show a dual price on the product page:
     *   - Members: see their member price with a badge
     *   - Non-members: see regular price + a teaser "Members pay $X/week"
     */
    public function show_dual_price_html( string $price_html, WC_Product $product ): string {
        if ( is_admin() ) {
            return $price_html;
        }

        $member_price = get_post_meta( $product->get_id(), '_noah_member_price', true );
        if ( '' === $member_price || ! is_numeric( $member_price ) ) {
            return $price_html;
        }

        $is_member = Noah_Membership::is_member( get_current_user_id() );

        if ( $is_member ) {
            $badge = get_option( 'noah_member_badge_text', __( 'Member price', 'noah-protocol' ) );
            return '<span class="noah-member-price">'
                . wc_price( (float) $member_price )
                . ' <span class="noah-price-badge">' . esc_html( $badge ) . '</span>'
                . '</span>';
        }

        $teaser_template = get_option(
            'noah_nonmember_teaser_text',
            __( 'Members pay {price}/week', 'noah-protocol' )
        );
        $teaser = str_replace( '{price}', wc_price( (float) $member_price ), $teaser_template );

        return $price_html . ' <span class="noah-member-teaser">' . $teaser . '</span>';
    }
}
