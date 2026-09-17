<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Customer_Sync
 *
 * On order completion, guarantees the purchasing user has a Stripe Customer
 * object (created via the Stripe API if one doesn't already exist) and tags
 * that customer's metadata with what they bought, using the single recurring
 * Stripe Price ID mapped on each product's edit screen (rendered by
 * Noah_Subscription_Length; the meta key lives here as the shared constant).
 *
 * Does not create Stripe invoices, charges, or subscriptions itself — the
 * initial (cycle 1) payment stays with the Stripe for WooCommerce gateway,
 * and the recurring Subscription is created by Noah_Stripe_Recurring. This
 * class only ensures every purchaser has a matching Customer record in
 * Stripe, and that record reflects what they purchased.
 */
class Noah_Stripe_Customer_Sync {

    private static ?Noah_Stripe_Customer_Sync $instance = null;

    const STRIPE_CUSTOMER_META  = '_stripe_customer_id';
    const SYNCED_ORDER_META     = '_noah_stripe_synced';
    const STRIPE_PRICE_ID_META  = '_noah_stripe_price_id';

    public static function instance(): Noah_Stripe_Customer_Sync {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Run before Noah_Membership / Noah_Subscriptions (default priority 10)
        // so the Stripe customer already exists when those hooks fire.
        add_action( 'woocommerce_order_status_completed', [ $this, 'sync_order' ], 5 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'sync_order' ], 5 );
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

        if ( ! Noah_Stripe_Client::available() ) {
            Noah_DB::log_event( $user_id, null, 'stripe_client_unavailable', "Order #{$order_id} — WC_Stripe_API class not found" );
            return;
        }

        try {
            $customer_id = $this->ensure_stripe_customer( $user_id, $order );
            if ( $customer_id ) {
                $this->tag_customer_with_purchase( $customer_id, $order );
                $order->update_meta_data( self::SYNCED_ORDER_META, 'yes' );
                $order->save();
            }
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, null, 'stripe_customer_sync_error', $e->getMessage() );
        }
    }

    private function ensure_stripe_customer( int $user_id, WC_Order $order ): string {
        $existing = self::get_customer_id( $user_id );
        if ( ! empty( $existing ) ) {
            return $existing;
        }

        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer = Noah_Stripe_Client::post( [
            'email'    => $order->get_billing_email(),
            'name'     => $name ?: $order->get_formatted_billing_full_name(),
            'metadata' => [ 'noah_wp_user_id' => $user_id ],
        ], 'customers' );

        self::set_customer_id( $user_id, $customer->id );
        Noah_DB::log_event( $user_id, null, 'stripe_customer_created', "Order #{$order->get_id()} | {$customer->id}" );

        return $customer->id;
    }

    // ---------------------------------------------------------------
    // Shared customer-ID storage
    //
    // WooCommerce's Stripe Gateway stores the customer ID via
    // update_user_option(), which WordPress prefixes with the table prefix
    // (e.g. 'wp__stripe_customer_id'), NOT the bare '_stripe_customer_id'
    // meta key. Every read/write of this value across the plugin must go
    // through these helpers (or query meta_key() directly) so it reads the
    // exact same row the gateway uses, instead of silently creating a
    // second, duplicate Stripe Customer per purchase.
    // ---------------------------------------------------------------

    public static function get_customer_id( int $user_id ): string {
        return (string) get_user_option( self::STRIPE_CUSTOMER_META, $user_id );
    }

    public static function set_customer_id( int $user_id, string $customer_id ): void {
        update_user_option( $user_id, self::STRIPE_CUSTOMER_META, $customer_id, false );
    }

    /**
     * The literal wp_usermeta.meta_key for raw queries (e.g. get_users()'s
     * 'meta_key' arg, which does a direct DB lookup with no prefix-fallback).
     */
    public static function meta_key(): string {
        global $wpdb;
        return $wpdb->get_blog_prefix() . self::STRIPE_CUSTOMER_META;
    }

    private function tag_customer_with_purchase( string $customer_id, WC_Order $order ): void {
        $names         = [];
        $price_ids     = [];
        $is_membership = false;

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $names[]    = $item->get_name();

            $price_id = get_post_meta( $product_id, self::STRIPE_PRICE_ID_META, true );
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

        Noah_Stripe_Client::post( [ 'metadata' => $metadata ], "customers/{$customer_id}" );
    }
}
