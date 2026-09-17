<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Pricing
 * Applies member prices in the cart and shows dual price HTML on product pages.
 * Member price is always derived (nonmember price minus the discount percent),
 * never a manually-entered per-product price — but the percent itself can be
 * overridden per product via Noah_Discount::get_percent_for_product(), falling
 * back to the global noah_member_discount_percent option.
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
     * Override the cart price for noah_subscription (program/membership) items:
     * eligible members pay the derived member rate, everyone else pays the
     * non-member rate. Noah_Discount_Cart handles plain "simple" products.
     */
    public function apply_member_prices( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $is_eligible = Noah_Discount::is_eligible( get_current_user_id() );

        foreach ( $cart->get_cart() as $cart_item ) {
            $product    = $cart_item['data'];
            $product_id = $cart_item['product_id'];

            if ( 'noah_subscription' !== $product->get_type() ) {
                continue;
            }

            $nonmember_price = get_post_meta( $product_id, '_noah_nonmember_price', true );
            if ( '' === $nonmember_price || ! is_numeric( $nonmember_price ) ) {
                continue;
            }

            $price = (float) $nonmember_price;
            if ( $is_eligible ) {
                $percent = Noah_Discount::get_percent_for_product( $product_id );
                $price   = round( $price * ( 1 - $percent / 100 ), 2 );
            }
            $product->set_price( $price );
        }
    }

    /**
     * Show a dual price on the product page:
     *   - Members: see their member price with a badge
     *   - Non-members: see regular price + a teaser "Members pay $X/week"
     */
    public function show_dual_price_html( string $price_html, WC_Product $product ): string {
        if ( is_admin() || 'noah_subscription' !== $product->get_type() ) {
            return $price_html;
        }

        $nonmember_price = get_post_meta( $product->get_id(), '_noah_nonmember_price', true );
        if ( '' === $nonmember_price || ! is_numeric( $nonmember_price ) ) {
            return $price_html;
        }

        $percent      = Noah_Discount::get_percent_for_product( $product->get_id() );
        $member_price = round( (float) $nonmember_price * ( 1 - $percent / 100 ), 2 );

        if ( Noah_Discount::is_eligible( get_current_user_id() ) ) {
            $badge = get_option( 'noah_member_badge_text', __( 'Member price', 'noah-protocol' ) );
            return '<span class="noah-member-price">'
                . wc_price( $member_price )
                . ' <span class="noah-price-badge">' . esc_html( $badge ) . '</span>'
                . '</span>';
        }

        $teaser_template = get_option(
            'noah_nonmember_teaser_text',
            __( 'Members pay {price}/week', 'noah-protocol' )
        );
        $teaser = str_replace( '{price}', wc_price( $member_price ), $teaser_template );

        return $price_html . ' <span class="noah-member-teaser">' . $teaser . '</span>';
    }
}
