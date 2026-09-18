<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Subscription_Length
 * Adds billing cycle + period fields to WooCommerce product edit pages
 * for noah_subscription products, and the membership plan checkbox.
 * Also handles saving all NOAH-specific product meta with correct priority.
 */
class Noah_Subscription_Length {

    private static ?Noah_Subscription_Length $instance = null;

    public static function instance(): Noah_Subscription_Length {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_all_noah_fields' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields_early' ], 5   );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_fields_late'  ], 100 );
    }

    public function render_all_noah_fields(): void {
        global $post;
        $pid             = $post->ID;
        $nonmember_price = get_post_meta( $pid, '_noah_nonmember_price',   true );
        $cycles          = get_post_meta( $pid, '_noah_billing_cycles',    true );
        $period          = get_post_meta( $pid, '_noah_billing_period',    true ) ?: 'week';
        $is_membership   = get_post_meta( $pid, '_noah_is_membership_plan', true );
        $stripe_price_id = get_post_meta( $pid, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META, true );
        $percent_override = get_post_meta( $pid, '_noah_member_discount_percent', true );
        $price_override   = get_post_meta( $pid, '_noah_member_price_override', true );
        $coupon_override  = get_post_meta( $pid, '_noah_stripe_coupon_id', true );
        $discount_percent = Noah_Discount::get_percent_for_product( $pid );

        if ( '' === $nonmember_price ) {
            $regular = get_post_meta( $pid, '_regular_price', true );
            if ( '' !== $regular ) {
                $nonmember_price = $regular;
            }
        }
        ?>

        <div class="options_group">
            <p class="form-field">
                <label for="_noah_member_discount_percent">
                    <?php esc_html_e( 'Member discount override (%)', 'noah-protocol' ); ?>
                </label>
                <input type="number" min="0" max="100" step="0.01" class="short"
                       id="_noah_member_discount_percent" name="_noah_member_discount_percent"
                       value="<?php echo esc_attr( $percent_override ); ?>" placeholder="<?php echo esc_attr( Noah_Discount::get_percent() ); ?>">
                <span class="description"><?php esc_html_e( 'Leave blank to use the global member discount percent for this product (simple or program). Set a value here only if this product\'s member price is not the standard discount.', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="_noah_member_price_override">
                    <?php esc_html_e( 'Member price override ($)', 'noah-protocol' ); ?>
                </label>
                <input type="number" min="0" step="0.01" class="short"
                       id="_noah_member_price_override" name="_noah_member_price_override"
                       value="<?php echo esc_attr( $price_override ); ?>" placeholder="e.g. 1500.00">
                <span class="description"><?php esc_html_e( 'Leave blank to use the percent above. Set an exact member price here instead when the percent-derived price doesn\'t land on a clean number (e.g. 16.67% off $1800 is $1499.94, not $1500) — this value is charged exactly as entered.', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>">
                    <?php esc_html_e( 'Stripe Price ID', 'noah-protocol' ); ?>
                </label>
                <input type="text" class="short"
                       id="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>"
                       name="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>"
                       value="<?php echo esc_attr( $stripe_price_id ); ?>" placeholder="price_...">
                <span class="description"><?php esc_html_e( 'For a program (noah_subscription), this is the recurring Price this product bills against. For a simple one-time product, it\'s optional and only used to tag the buyer\'s Stripe customer record with which price they purchased — it does not affect what they\'re charged.', 'noah-protocol' ); ?></span>
            </p>
        </div>

        <div class="options_group noah-subscription-only">

            <p class="form-field">
                <label for="_noah_nonmember_price">
                    <strong><?php esc_html_e( 'Non-Member Price (per billing period)', 'noah-protocol' ); ?></strong>
                </label>
                <input type="number" min="0" step="0.01" class="short"
                       id="_noah_nonmember_price" name="_noah_nonmember_price"
                       value="<?php echo esc_attr( $nonmember_price ); ?>" placeholder="0.00">
                <span class="description">
                    <?php esc_html_e( 'Price for customers without an active membership. The member price and the frontend total are both calculated automatically from this value.', 'noah-protocol' ); ?>
                    <?php if ( is_numeric( $nonmember_price ) && $nonmember_price > 0 ) : ?>
                        <?php $member_price_preview = Noah_Discount::get_member_price_for_product( $pid, (float) $nonmember_price ); ?>
                        <br>
                        <?php if ( '' !== $price_override && is_numeric( $price_override ) ) : ?>
                            <?php printf(
                                /* translators: 1: member price */
                                esc_html__( 'Member price: %1$s (exact override)', 'noah-protocol' ),
                                wp_kses_post( wc_price( $member_price_preview ) )
                            ); ?>
                        <?php else : ?>
                            <?php printf(
                                /* translators: 1: member price, 2: discount percent */
                                esc_html__( 'Member price: %1$s (%2$s%% off)', 'noah-protocol' ),
                                wp_kses_post( wc_price( $member_price_preview ) ),
                                esc_html( $discount_percent )
                            ); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </span>
            </p>

            <p class="form-field noah-onetime-hide">
                <label for="_noah_stripe_coupon_id">
                    <?php esc_html_e( 'Stripe Coupon ID override', 'noah-protocol' ); ?>
                </label>
                <input type="text" class="short"
                       id="_noah_stripe_coupon_id" name="_noah_stripe_coupon_id"
                       value="<?php echo esc_attr( $coupon_override ); ?>" placeholder="<?php echo esc_attr( Noah_Discount::get_coupon_id() ); ?>">
                <span class="description"><?php esc_html_e( 'Only needed if the discount override above differs from the global percent: create a matching Coupon in the Stripe Dashboard and enter its ID here, so cycle 2+ billing matches the price shown at checkout. Leave blank to use the global coupon. Not used when Billing period is "One Time Payment" — there is no cycle 2+.', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field noah-onetime-hide">
                <label for="_noah_billing_cycles">
                    <?php esc_html_e( 'Number of billing cycles', 'noah-protocol' ); ?>
                </label>
                <input type="number" min="0" step="1" class="short"
                       id="_noah_billing_cycles" name="_noah_billing_cycles"
                       value="<?php echo esc_attr( $cycles ); ?>" placeholder="0">
                <span class="description"><?php esc_html_e( 'e.g. 3 = charge 3 times then stop. 0 = ongoing (membership plan). Not used when Billing period is "One Time Payment".', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="_noah_billing_period">
                    <?php esc_html_e( 'Billing period', 'noah-protocol' ); ?>
                </label>
                <select id="_noah_billing_period" name="_noah_billing_period" class="short">
                    <option value="day"     <?php selected( $period, 'day'     ); ?>><?php esc_html_e( 'Daily',            'noah-protocol' ); ?></option>
                    <option value="week"    <?php selected( $period, 'week'    ); ?>><?php esc_html_e( 'Weekly',           'noah-protocol' ); ?></option>
                    <option value="month"   <?php selected( $period, 'month'   ); ?>><?php esc_html_e( 'Monthly',          'noah-protocol' ); ?></option>
                    <option value="onetime" <?php selected( $period, 'onetime' ); ?>><?php esc_html_e( 'One Time Payment', 'noah-protocol' ); ?></option>
                </select>
                <span class="description"><?php esc_html_e( 'One Time Payment: a single charge at checkout, no recurring Stripe billing, no automatic access expiry — use this for onsite/in-person programs where duration is already described on the product page.', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="_noah_is_membership_plan">
                    <?php esc_html_e( 'This product grants NOAH Membership', 'noah-protocol' ); ?>
                </label>
                <input type="checkbox" id="_noah_is_membership_plan" name="_noah_is_membership_plan"
                       value="yes" <?php checked( $is_membership, 'yes' ); ?>>
                <span class="description"><?php esc_html_e( 'On order completion, the customer receives the noah_member role and member pricing.', 'noah-protocol' ); ?></span>
            </p>

        </div>
        <?php
    }

    public function save_product_fields_early( int $post_id ): void {
        $product_type = $this->get_posted_product_type();

        // Discount override and Stripe Price ID apply to any product type
        // (programs and simple products alike).
        if ( isset( $_POST['_noah_member_discount_percent'] ) ) {
            $percent_override = wc_clean( wp_unslash( $_POST['_noah_member_discount_percent'] ) );
            if ( '' !== $percent_override && is_numeric( $percent_override ) ) {
                update_post_meta( $post_id, '_noah_member_discount_percent', (float) $percent_override );
            } else {
                delete_post_meta( $post_id, '_noah_member_discount_percent' );
            }
        }
        if ( isset( $_POST['_noah_member_price_override'] ) ) {
            $price_override = wc_format_decimal( wc_clean( wp_unslash( $_POST['_noah_member_price_override'] ) ) );
            if ( '' !== $price_override && is_numeric( $price_override ) ) {
                update_post_meta( $post_id, '_noah_member_price_override', (float) $price_override );
            } else {
                delete_post_meta( $post_id, '_noah_member_price_override' );
            }
        }
        if ( isset( $_POST[ Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ] ) ) {
            $price_id = sanitize_text_field( wp_unslash( $_POST[ Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ] ) );
            if ( '' !== $price_id ) {
                update_post_meta( $post_id, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META, $price_id );
            } else {
                delete_post_meta( $post_id, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META );
            }
        }

        if ( 'noah_subscription' === $product_type ) {
            if ( isset( $_POST['_noah_billing_cycles'] ) ) {
                update_post_meta( $post_id, '_noah_billing_cycles', absint( $_POST['_noah_billing_cycles'] ) );
            }
            if ( isset( $_POST['_noah_billing_period'] ) ) {
                $period = sanitize_key( $_POST['_noah_billing_period'] );
                if ( in_array( $period, [ 'day', 'week', 'month', 'onetime' ], true ) ) {
                    update_post_meta( $post_id, '_noah_billing_period', $period );
                }
            }
            if ( isset( $_POST['_noah_stripe_coupon_id'] ) ) {
                $coupon_override = sanitize_text_field( wp_unslash( $_POST['_noah_stripe_coupon_id'] ) );
                if ( '' !== $coupon_override ) {
                    update_post_meta( $post_id, '_noah_stripe_coupon_id', $coupon_override );
                } else {
                    delete_post_meta( $post_id, '_noah_stripe_coupon_id' );
                }
            }
            $is_membership_plan = ! empty( $_POST['_noah_is_membership_plan'] ) ? 'yes' : 'no';
            update_post_meta( $post_id, '_noah_is_membership_plan', $is_membership_plan );
            if ( 'yes' === $is_membership_plan ) {
                update_option( 'noah_membership_product_id', $post_id );
            } elseif ( (int) get_option( 'noah_membership_product_id', 0 ) === $post_id ) {
                delete_option( 'noah_membership_product_id' );
            }
        }
    }

    public function save_product_fields_late( int $post_id ): void {
        if ( 'noah_subscription' !== $this->get_posted_product_type() ) {
            return;
        }
        $this->ensure_product_type_term( $post_id );

        if ( isset( $_POST['_noah_nonmember_price'] ) ) {
            $np = wc_format_decimal( wc_clean( wp_unslash( $_POST['_noah_nonmember_price'] ) ) );
            update_post_meta( $post_id, '_noah_nonmember_price', $np );
            update_post_meta( $post_id, '_regular_price', $np );
            update_post_meta( $post_id, '_price', $np );
        }

        delete_post_meta( $post_id, '_sale_price' );
        delete_post_meta( $post_id, '_sale_price_dates_from' );
        delete_post_meta( $post_id, '_sale_price_dates_to' );
    }

    private function get_posted_product_type(): string {
        return isset( $_POST['product-type'] )
            ? sanitize_text_field( wp_unslash( $_POST['product-type'] ) )
            : '';
    }

    private function ensure_product_type_term( int $post_id ): void {
        $term = get_term_by( 'slug', 'noah_subscription', 'product_type' );
        if ( ! $term ) {
            $result = wp_insert_term( 'noah_subscription', 'product_type' );
            if ( is_wp_error( $result ) ) {
                return;
            }
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        wp_set_object_terms( $post_id, $term_id, 'product_type' );
    }
}
