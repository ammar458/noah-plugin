<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Recurring
 *
 * Owns the full lifecycle of a program/membership's recurring Stripe Subscription:
 *   1. create_subscription_for_program() / create_subscription_for_membership()
 *      — create the real Stripe Subscription right after order completion.
 *      WooCommerce/Stripe-for-WooCommerce already collected payment for cycle 1
 *      via the normal checkout, so the Subscription is created with trial_end
 *      set to exactly one billing period out — this makes Stripe skip generating
 *      (and trying to collect) a duplicate invoice for cycle 1. Eligible members
 *      (Noah_Discount::is_eligible) get the Stripe coupon attached so cycle 2+
 *      bills at the discounted amount automatically.
 *   2. setup_stripe_cycle_limit() — sets cancel_at so Stripe stops billing once
 *      the configured number of cycles is reached (programs only; membership is
 *      ongoing).
 *   3. cancel_stripe_subscription() — explicit cancel on the final paid cycle.
 */
class Noah_Stripe_Recurring {

    private static ?Noah_Stripe_Recurring $instance = null;

    public static function instance(): Noah_Stripe_Recurring {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 5: create the real Subscription before the cycle-limit/cancel
        // logic below (priority 10, unchanged) runs — it needs a real stripe_sub_id.
        add_action( 'noah_program_subscription_started', [ $this, 'create_subscription_for_program' ], 5, 3 );
        add_action( 'noah_membership_granted',            [ $this, 'create_subscription_for_membership' ], 5, 2 );

        add_action( 'noah_program_subscription_started', [ $this, 'setup_stripe_cycle_limit' ], 10, 3 );
        add_action( 'noah_program_final_cycle_paid',     [ $this, 'cancel_stripe_subscription' ], 10, 2 );

        // The cycle-1 checkout must always leave the card attached to the Stripe
        // Customer, since create_subscription() below reuses that same payment
        // method for cycle 2+. WC Stripe Gateway only sets setup_future_usage
        // itself when the shopper checks "save card" or when its own
        // has_subscription() check (tied to the separate WooCommerce Subscriptions
        // plugin) fires — it has no concept of our custom noah_subscription
        // product type, so that never happens on its own. Kept as a no-op safety
        // net for the classic/card-element checkout flow; it doesn't cover the
        // Stripe Checkout Session flow below, which builds its own request
        // shape and only applies this filter for a subset of call sites.
        add_filter( 'wc_stripe_generate_create_intent_request', [ $this, 'force_save_payment_method_for_programs' ], 10, 2 );

        // wc_stripe_request_body is a generic filter applied to EVERY Stripe API
        // request body right before it's sent, regardless of checkout flow —
        // this is what actually catches the Checkout Session flow (confirmed via
        // this site's own logs: requests go through checkout/sessions, not a
        // plain payment_intents call), where the gateway only offers the
        // shopper an optional "save card" checkbox and never forces it.
        add_filter( 'wc_stripe_request_body', [ $this, 'force_save_payment_method_on_checkout_session' ], 10, 2 );
    }

    public function force_save_payment_method_for_programs( array $request, ?WC_Order $order ): array {
        if ( $order && $this->order_contains_noah_subscription( $order ) ) {
            $request['setup_future_usage'] = 'off_session';
        }
        return $request;
    }

    public function force_save_payment_method_on_checkout_session( array $request, string $api ): array {
        if ( 'checkout/sessions' !== $api || 'payment' !== ( $request['mode'] ?? '' ) ) {
            return $request;
        }
        if ( ! $this->cart_contains_noah_subscription() ) {
            return $request;
        }
        if ( ! isset( $request['payment_intent_data'] ) || ! is_array( $request['payment_intent_data'] ) ) {
            $request['payment_intent_data'] = [];
        }
        $request['payment_intent_data']['setup_future_usage'] = 'off_session';
        return $request;
    }

    /**
     * A product only needs its payment method saved for future off-session
     * billing if it's a recurring noah_subscription — a one-time payment
     * product never bills again, so there's nothing to save a card for.
     */
    private function is_recurring_noah_subscription( ?WC_Product $product ): bool {
        return $product
            && 'noah_subscription' === $product->get_type()
            && 'yes' !== get_post_meta( $product->get_id(), '_noah_one_time_payment', true );
    }

    private function order_contains_noah_subscription( WC_Order $order ): bool {
        foreach ( $order->get_items() as $item ) {
            if ( $this->is_recurring_noah_subscription( $item->get_product() ) ) {
                return true;
            }
        }
        return false;
    }

    private function cart_contains_noah_subscription(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $this->is_recurring_noah_subscription( $cart_item['data'] ?? null ) ) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------
    // Subscription creation
    // ---------------------------------------------------------------

    public function create_subscription_for_program( int $user_id, int $product_id, int $order_id ): void {
        if ( 'yes' === get_post_meta( $product_id, '_noah_one_time_payment', true ) ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_skipped', "Order #{$order_id} — one-time payment product, no recurring subscription needed" );
            return;
        }
        $sub_id = $this->create_subscription( $user_id, $product_id, $order_id, false );
        if ( $sub_id ) {
            Noah_DB::upsert_program_access( $user_id, $product_id, [ 'stripe_sub_id' => $sub_id ] );
        }
    }

    /**
     * Fires on 'noah_membership_granted'. $order_id is 0 when membership is
     * (re)granted outside a fresh purchase (admin toggle, Stripe webhook resume)
     * — a Subscription must already exist in those cases, so we skip creating one.
     */
    public function create_subscription_for_membership( int $user_id, int $order_id = 0 ): void {
        if ( ! $order_id ) {
            return;
        }
        $product_id = (int) get_option( 'noah_membership_product_id', 0 );
        if ( ! $product_id ) {
            return;
        }
        $sub_id = $this->create_subscription( $user_id, $product_id, $order_id, true );
        if ( $sub_id ) {
            Noah_DB::upsert_member( $user_id, [ 'stripe_sub_id' => $sub_id ] );
        }
    }

    private function create_subscription( int $user_id, int $product_id, int $order_id, bool $is_membership ): ?string {
        $price_id = get_post_meta( $product_id, Noah_Stripe_Customer_Sync::STRIPE_PRICE_ID_META, true );
        if ( ! $price_id ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_skipped', 'No Stripe Price ID configured on product' );
            return null;
        }

        $customer_id = Noah_Stripe_Customer_Sync::get_customer_id( $user_id );
        if ( ! $customer_id ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_error', 'No Stripe customer id on user' );
            return null;
        }

        if ( ! Noah_Stripe_Client::available() ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_client_unavailable', "Order #{$order_id} — WC_Stripe_API class not found" );
            return null;
        }

        $payment_method = $this->resolve_payment_method( (string) $customer_id, $order_id, $user_id, $product_id );
        if ( ! $payment_method ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_error', 'No chargeable payment method found' );
            $this->notify_admin_subscription_failure( $user_id, $product_id, 'No saved payment method available for recurring billing.' );
            return null;
        }

        $period    = get_post_meta( $product_id, '_noah_billing_period', true ) ?: 'week';
        $trial_end = strtotime( '+1 ' . $period, time() );

        $params = [
            'customer'               => $customer_id,
            'items'                  => [ [ 'price' => $price_id ] ],
            'trial_end'              => $trial_end,
            'default_payment_method' => $payment_method,
            'metadata'               => [
                'noah_wp_user_id'  => (string) $user_id,
                'noah_product_id'  => (string) $product_id,
                'noah_order_id'    => (string) $order_id,
            ],
        ];

        if ( ! $is_membership && Noah_Discount::is_eligible( $user_id ) ) {
            $params['discounts'] = [ [ 'coupon' => Noah_Discount::get_coupon_id_for_product( $product_id ) ] ];
        }

        try {
            $subscription = Noah_Stripe_Client::post( $params, 'subscriptions' );
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_created', $subscription->id );
            return $subscription->id;
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_subscription_error', $e->getMessage() );
            $this->notify_admin_subscription_failure( $user_id, $product_id, $e->getMessage() );
            return null;
        }
    }

    /**
     * Find a payment method Stripe can charge off-session for future cycles:
     * prefer the PaymentIntent from the order that was just paid (the method
     * the customer just used), then the customer's stored default, then any
     * card already on file.
     */
    private function resolve_payment_method( string $customer_id, int $order_id, int $user_id, int $product_id ): ?string {
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $intent_id = $order->get_meta( '_stripe_intent_id' );
                if ( $intent_id ) {
                    try {
                        $intent = Noah_Stripe_Client::get( "payment_intents/{$intent_id}" );
                        if ( ! empty( $intent->payment_method ) ) {
                            $pm_id = is_string( $intent->payment_method ) ? $intent->payment_method : $intent->payment_method->id;
                            if ( $this->attach_payment_method( $pm_id, $customer_id, $user_id, $product_id ) ) {
                                return $pm_id;
                            }
                        } else {
                            Noah_DB::log_event( $user_id, $product_id, 'stripe_payment_method_resolution', "Order #{$order_id} intent {$intent_id} has no payment_method" );
                        }
                    } catch ( \Exception $e ) {
                        Noah_DB::log_event( $user_id, $product_id, 'stripe_payment_method_resolution', "Order #{$order_id} intent {$intent_id} lookup failed: {$e->getMessage()}" );
                    }
                } else {
                    Noah_DB::log_event( $user_id, $product_id, 'stripe_payment_method_resolution', "Order #{$order_id} has no _stripe_intent_id meta" );
                }
            }
        }

        try {
            $customer = Noah_Stripe_Client::get( "customers/{$customer_id}" );
            if ( ! empty( $customer->invoice_settings->default_payment_method ) ) {
                return $customer->invoice_settings->default_payment_method;
            }
        } catch ( \Exception $e ) {
            // Fall through.
        }

        try {
            $methods = Noah_Stripe_Client::get( 'payment_methods', [ 'customer' => $customer_id, 'type' => 'card', 'limit' => 1 ] );
            if ( ! empty( $methods->data[0]->id ) ) {
                return $methods->data[0]->id;
            }
        } catch ( \Exception $e ) {
            // No card on file.
        }

        return null;
    }

    /**
     * The checkout's PaymentIntent doesn't reliably leave its payment method
     * attached to the Customer — that depends on WC Stripe Gateway's checkout
     * flow (classic vs. Payment Element/confirmation-token) and settings, and
     * isn't something this plugin can force from the server side in every
     * case. Attach it explicitly here instead, so the Subscription created
     * below always has a valid, reusable payment method regardless of how
     * checkout ran. Attaching a PM already attached to this same customer is
     * a harmless no-op in Stripe; attaching one already attached to a
     * DIFFERENT customer errors, in which case we fall through to the other
     * resolution methods below.
     */
    private function attach_payment_method( string $payment_method_id, string $customer_id, int $user_id, int $product_id ): bool {
        try {
            Noah_Stripe_Client::post( [ 'customer' => $customer_id ], "payment_methods/{$payment_method_id}/attach" );
            return true;
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_payment_method_resolution', "Attach {$payment_method_id} to {$customer_id} failed: {$e->getMessage()}" );
            return false;
        }
    }

    private function notify_admin_subscription_failure( int $user_id, int $product_id, string $reason ): void {
        $user    = get_userdata( $user_id );
        $product = wc_get_product( $product_id );
        wp_mail(
            get_option( 'admin_email' ),
            sprintf( '[NOAH] Could not start recurring billing — %s', $user ? $user->user_email : "user #{$user_id}" ),
            sprintf(
                "Recurring Stripe billing could not be set up.\n\nCustomer: %s\nProduct: %s\nReason: %s\n\nThe first payment was already collected; the customer will not be billed automatically for future cycles until this is resolved.",
                $user ? $user->user_email : "user #{$user_id}",
                $product ? $product->get_name() : "product #{$product_id}",
                $reason
            )
        );
    }

    // ---------------------------------------------------------------
    // Cycle limit / cancellation
    // ---------------------------------------------------------------

    /**
     * Set cancel_at on the Stripe subscription so Stripe stops billing automatically.
     */
    public function setup_stripe_cycle_limit( int $user_id, int $product_id, int $order_id ): void {
        $access = Noah_DB::get_program_access( $user_id, $product_id );
        if ( ! $access || empty( $access->stripe_sub_id ) ) {
            return;
        }

        $period    = get_post_meta( $product_id, '_noah_billing_period', true ) ?: 'week';
        $cycles    = (int) $access->billing_cycles;
        $interval  = $period === 'week' ? "{$cycles} weeks" : "{$cycles} months";
        $cancel_at = strtotime( "+{$interval}", time() );

        if ( ! Noah_Stripe_Client::available() ) {
            return;
        }

        try {
            Noah_Stripe_Client::post( [ 'cancel_at' => $cancel_at ], "subscriptions/{$access->stripe_sub_id}" );
            Noah_DB::log_event( $user_id, $product_id, 'stripe_cancel_at_set',
                "cancel_at: " . gmdate( 'Y-m-d H:i:s', $cancel_at ) );
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_cancel_at_error', $e->getMessage() );
        }
    }

    /**
     * When the final cycle is paid (tracked in Noah_Subscriptions::record_renewal),
     * cancel the Stripe subscription immediately so no extra charge fires.
     */
    public function cancel_stripe_subscription( int $user_id, int $product_id ): void {
        $access = Noah_DB::get_program_access( $user_id, $product_id );
        if ( ! $access || empty( $access->stripe_sub_id ) ) {
            return;
        }

        if ( ! Noah_Stripe_Client::available() ) {
            return;
        }

        try {
            Noah_Stripe_Client::delete( "subscriptions/{$access->stripe_sub_id}" );
            Noah_DB::log_event( $user_id, $product_id, 'stripe_sub_cancelled_final_cycle' );
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_sub_cancel_error', $e->getMessage() );
        }
    }
}
