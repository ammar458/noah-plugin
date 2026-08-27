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
        $discount_percent = Noah_Discount::get_percent();

        if ( '' === $nonmember_price ) {
            $regular = get_post_meta( $pid, '_regular_price', true );
            if ( '' !== $regular ) {
                $nonmember_price = $regular;
            }
        }
        ?>

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
                        <br><?php printf(
                            /* translators: 1: member price, 2: discount percent */
                            esc_html__( 'Member price: %1$s (%2$s%% off)', 'noah-protocol' ),
                            wp_kses_post( wc_price( round( (float) $nonmember_price * ( 1 - $discount_percent / 100 ), 2 ) ) ),
                            esc_html( $discount_percent )
                        ); ?>
                    <?php endif; ?>
                </span>
            </p>

            <p class="form-field">
                <label for="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>">
                    <?php esc_html_e( 'Stripe Price ID (recurring)', 'noah-protocol' ); ?>
                </label>
                <input type="text" class="short"
                       id="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>"
                       name="<?php echo esc_attr( Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ); ?>"
                       value="<?php echo esc_attr( $stripe_price_id ); ?>" placeholder="price_...">
                <span class="description"><?php esc_html_e( 'The Stripe recurring Price this product bills weekly/monthly. Eligible members get this price with the discount coupon attached, rather than a separate Stripe Price.', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="_noah_billing_cycles">
                    <?php esc_html_e( 'Number of billing cycles', 'noah-protocol' ); ?>
                </label>
                <input type="number" min="0" step="1" class="short"
                       id="_noah_billing_cycles" name="_noah_billing_cycles"
                       value="<?php echo esc_attr( $cycles ); ?>" placeholder="0">
                <span class="description"><?php esc_html_e( 'e.g. 3 = charge 3 times then stop. 0 = ongoing (membership plan).', 'noah-protocol' ); ?></span>
            </p>

            <p class="form-field">
                <label for="_noah_billing_period">
                    <?php esc_html_e( 'Billing period', 'noah-protocol' ); ?>
                </label>
                <select id="_noah_billing_period" name="_noah_billing_period" class="short">
                    <option value="week"  <?php selected( $period, 'week'  ); ?>><?php esc_html_e( 'Weekly',  'noah-protocol' ); ?></option>
                    <option value="month" <?php selected( $period, 'month' ); ?>><?php esc_html_e( 'Monthly', 'noah-protocol' ); ?></option>
                </select>
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

        if ( 'noah_subscription' === $product_type ) {
            if ( isset( $_POST[ Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ] ) ) {
                $price_id = sanitize_text_field( wp_unslash( $_POST[ Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META ] ) );
                if ( '' !== $price_id ) {
                    update_post_meta( $post_id, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META, $price_id );
                } else {
                    delete_post_meta( $post_id, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META );
                }
            }
            if ( isset( $_POST['_noah_billing_cycles'] ) ) {
                update_post_meta( $post_id, '_noah_billing_cycles', absint( $_POST['_noah_billing_cycles'] ) );
            }
            if ( isset( $_POST['_noah_billing_period'] ) ) {
                $period = sanitize_key( $_POST['_noah_billing_period'] );
                if ( in_array( $period, [ 'week', 'month' ], true ) ) {
                    update_post_meta( $post_id, '_noah_billing_period', $period );
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
