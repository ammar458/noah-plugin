<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Customer_Sync
 *
 * On order completion, guarantees the purchasing user has a Stripe Customer
 * object (created via the Stripe API if one doesn't already exist) and tags
 * that customer's metadata with what they bought, using the Stripe Price ID
 * mapped on each product's edit screen ("Stripe Price ID (Member)" /
 * "Stripe Price ID (Non-Member)" fields — whichever applies to the purchaser).
 *
 * Does not create Stripe invoices, charges, or subscriptions — actual
 * payment collection stays with the Stripe for WooCommerce gateway. This
 * only ensures every member/purchaser has a matching Customer record in
 * Stripe, and that record reflects what they purchased.
 */
class Noah_Stripe_Customer_Sync {

    private static ?Noah_Stripe_Customer_Sync $instance = null;

    const STRIPE_CUSTOMER_META    = '_stripe_customer_id';
    const SYNCED_ORDER_META       = '_noah_stripe_synced';
    const MEMBER_PRICE_ID_META    = '_noah_stripe_price_id_member';
    const NONMEMBER_PRICE_ID_META = '_noah_stripe_price_id_nonmember';

    public static function instance(): Noah_Stripe_Customer_Sync {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Product edit screen: map a WooCommerce product to an existing Stripe Price.
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_price_id_field' ] );
        add_action( 'woocommerce_process_product_meta',                 [ $this, 'save_price_id_field'   ] );

        // Run before Noah_Membership / Noah_Subscriptions (default priority 10)
        // so the Stripe customer already exists when those hooks fire.
        add_action( 'woocommerce_order_status_completed', [ $this, 'sync_order' ], 5 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'sync_order' ], 5 );
    }

    // ---------------------------------------------------------------
    // Product edit screen field
    // ---------------------------------------------------------------

    public function render_price_id_field(): void {
        global $post;
        $member_price_id    = get_post_meta( $post->ID, self::MEMBER_PRICE_ID_META,    true );
        $nonmember_price_id = get_post_meta( $post->ID, self::NONMEMBER_PRICE_ID_META, true );
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="_noah_stripe_price_id_nonmember">
                    <?php esc_html_e( 'Stripe Price ID (Non-Member)', 'noah-protocol' ); ?>
                </label>
                <input type="text" class="short"
                       id="_noah_stripe_price_id_nonmember" name="_noah_stripe_price_id_nonmember"
                       value="<?php echo esc_attr( $nonmember_price_id ); ?>" placeholder="price_...">
                <span class="description">
                    <?php esc_html_e( 'Stripe Price used for customers without an active membership.', 'noah-protocol' ); ?>
                </span>
            </p>
            <p class="form-field">
                <label for="_noah_stripe_price_id_member">
                    <?php esc_html_e( 'Stripe Price ID (Member)', 'noah-protocol' ); ?>
                </label>
                <input type="text" class="short"
                       id="_noah_stripe_price_id_member" name="_noah_stripe_price_id_member"
                       value="<?php echo esc_attr( $member_price_id ); ?>" placeholder="price_...">
                <span class="description">
                    <?php esc_html_e( 'Stripe Price used for active members. Used to tag the Stripe Customer with what they purchased.', 'noah-protocol' ); ?>
                </span>
            </p>
        </div>
        <?php
    }

    public function save_price_id_field( int $post_id ): void {
        if ( isset( $_POST['_noah_stripe_price_id_nonmember'] ) ) {
            $nonmember_price_id = sanitize_text_field( wp_unslash( $_POST['_noah_stripe_price_id_nonmember'] ) );
            if ( '' !== $nonmember_price_id ) {
                update_post_meta( $post_id, self::NONMEMBER_PRICE_ID_META, $nonmember_price_id );
            } else {
                delete_post_meta( $post_id, self::NONMEMBER_PRICE_ID_META );
            }
        }

        if ( isset( $_POST['_noah_stripe_price_id_member'] ) ) {
            $member_price_id = sanitize_text_field( wp_unslash( $_POST['_noah_stripe_price_id_member'] ) );
            if ( '' !== $member_price_id ) {
                update_post_meta( $post_id, self::MEMBER_PRICE_ID_META, $member_price_id );
            } else {
                delete_post_meta( $post_id, self::MEMBER_PRICE_ID_META );
            }
        }
    }

    // ---------------------------------------------------------------
    // Order sync
    // ---------------------------------------------------------------

    public function sync_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' === $order->get_meta( self::SYNCED_ORDER_META ) ) {
            return;
        }

        $user_id = (int) $order->get_customer_id();
        if ( ! $user_id ) {
            return; // Guest checkouts have no WP account to attach a Stripe customer to.
        }

        $stripe = $this->get_stripe_client();
        if ( ! $stripe ) {
            return;
        }

        try {
            $customer_id = $this->ensure_stripe_customer( $stripe, $user_id, $order );
            if ( $customer_id ) {
                $this->tag_customer_with_purchase( $stripe, $customer_id, $order );
                $order->update_meta_data( self::SYNCED_ORDER_META, 'yes' );
                $order->save();
            }
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, null, 'stripe_customer_sync_error', $e->getMessage() );
        }
    }

    private function ensure_stripe_customer( object $stripe, int $user_id, WC_Order $order ): string {
        $existing = get_user_meta( $user_id, self::STRIPE_CUSTOMER_META, true );
        if ( ! empty( $existing ) ) {
            return $existing;
        }

        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer = $stripe->customers->create( [
            'email'    => $order->get_billing_email(),
            'name'     => $name ?: $order->get_formatted_billing_full_name(),
            'metadata' => [ 'noah_wp_user_id' => $user_id ],
        ] );

        update_user_meta( $user_id, self::STRIPE_CUSTOMER_META, $customer->id );
        Noah_DB::log_event( $user_id, null, 'stripe_customer_created', "Order #{$order->get_id()} | {$customer->id}" );

        return $customer->id;
    }

    private function tag_customer_with_purchase( object $stripe, string $customer_id, WC_Order $order ): void {
        $names         = [];
        $price_ids     = [];
        $is_membership = false;
        $is_member     = Noah_Membership::is_member( (int) $order->get_customer_id() );

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $names[]    = $item->get_name();

            $price_id = $is_member
                ? get_post_meta( $product_id, self::MEMBER_PRICE_ID_META, true )
                : get_post_meta( $product_id, self::NONMEMBER_PRICE_ID_META, true );
            if ( $price_id ) {
                $price_ids[] = $price_id;
            }
            if ( 'yes' === get_post_meta( $product_id, '_noah_is_membership_plan', true ) ) {
                $is_membership = true;
            }
        }

        $metadata = [
            'noah_last_order_id' => (string) $order->get_id(),
            'noah_last_product'  => mb_substr( implode( ', ', $names ), 0, 500 ),
        ];
        if ( $price_ids ) {
            $metadata['noah_last_stripe_price_ids'] = mb_substr( implode( ', ', array_unique( $price_ids ) ), 0, 500 );
        }
        if ( $is_membership ) {
            $metadata['noah_membership_status'] = 'active';
        }

        $stripe->customers->update( $customer_id, [ 'metadata' => $metadata ] );
    }

    /**
     * Get the Stripe PHP client from the official WC Stripe plugin.
     */
    private function get_stripe_client(): ?object {
        if ( class_exists( 'WC_Stripe_API' ) && method_exists( 'WC_Stripe_API', 'get_stripe_client' ) ) {
            return WC_Stripe_API::get_stripe_client();
        }
        return null;
    }
}
