<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Webhooks
 *
 * Taken from v8 with two changes:
 *   1. Event logging goes to {prefix}noah_stripe_log DB table (not a WP option)
 *   2. Subscription ID resolution uses Noah_DB::find_user_by_stripe_sub()
 *      so program-level revoke also fires (not just membership revoke)
 *
 * Stripe events to subscribe to in the Stripe Dashboard:
 *   customer.subscription.deleted
 *   customer.subscription.paused
 *   customer.subscription.resumed
 *   customer.subscription.updated
 *   invoice.payment_failed
 *   invoice.payment_succeeded
 */
class Noah_Stripe_Webhooks {

    private static ?Noah_Stripe_Webhooks $instance = null;

    const STRIPE_CUSTOMER_META = '_stripe_customer_id';

    public static function instance(): Noah_Stripe_Webhooks {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_route' ] );

        add_action( 'noah_stripe_subscription_cancelled', [ Noah_Membership::instance(), 'revoke_by_stripe_customer' ] );
        add_action( 'noah_stripe_subscription_paused',    [ Noah_Membership::instance(), 'revoke_by_stripe_customer' ] );
        add_action( 'noah_stripe_subscription_resumed',   [ Noah_Membership::instance(), 'grant_by_stripe_customer'  ] );
        add_action( 'noah_stripe_payment_recovered',      [ Noah_Membership::instance(), 'grant_by_stripe_customer'  ] );

        add_action( 'noah_stripe_payment_failed',         [ $this, 'handle_payment_failed' ], 10, 2 );
    }

    // ---------------------------------------------------------------
    // REST endpoint
    // ---------------------------------------------------------------

    public function register_route(): void {
        register_rest_route( 'noah-protocol/v1', '/stripe-webhook', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $payload = $request->get_body();
        $sig     = $request->get_header( 'stripe-signature' );
        $secret  = get_option( 'noah_stripe_webhook_secret', '' );

        if ( ! empty( $secret ) ) {
            if ( ! $this->verify_signature( $payload, $sig, $secret ) ) {
                Noah_DB::log_stripe_event( 'invalid_signature', 'error' );
                return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
            }
        }

        $event = json_decode( $payload, true );
        if ( ! isset( $event['type'] ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
        }

        $this->dispatch( $event );

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    // ---------------------------------------------------------------
    // Event dispatcher
    // ---------------------------------------------------------------

    private function dispatch( array $event ): void {
        $type     = $event['type'];
        $data_obj = $event['data']['object'] ?? [];
        $customer = isset( $data_obj['customer'] ) ? sanitize_text_field( $data_obj['customer'] ) : '';
        $sub_id   = isset( $data_obj['subscription'] ) ? sanitize_text_field( $data_obj['subscription'] ) : ( $data_obj['id'] ?? '' );
        $event_id = $event['id'] ?? '';

        // Resolve user by Stripe subscription ID (covers both membership and program access)
        [ $user_id_by_sub, $product_id ] = Noah_DB::find_user_by_stripe_sub( $sub_id );

        // Log every event to DB table
        $user_by_customer  = $customer ? $this->get_user_by_stripe_customer( $customer ) : null;
        $resolved_user_id  = $user_id_by_sub ?: ( $user_by_customer ? $user_by_customer->ID : null );
        Noah_DB::log_stripe_event( $type, 'info', $event_id, $customer, $resolved_user_id );

        switch ( $type ) {

            case 'customer.subscription.deleted':
                // Program-level revoke if we can resolve the subscription
                if ( $user_id_by_sub && $product_id ) {
                    Noah_Access_Revoke::instance()->revoke_program( $user_id_by_sub, $product_id, 'stripe_sub_deleted' );
                }
                // Membership-level revoke via Stripe customer ID
                if ( $customer ) {
                    do_action( 'noah_stripe_subscription_cancelled', $customer );
                }
                break;

            case 'customer.subscription.paused':
                if ( $user_id_by_sub && $product_id ) {
                    Noah_Access_Revoke::instance()->revoke_program( $user_id_by_sub, $product_id, 'stripe_sub_paused' );
                }
                if ( $customer ) {
                    do_action( 'noah_stripe_subscription_paused', $customer );
                }
                break;

            case 'customer.subscription.resumed':
                if ( $customer ) {
                    do_action( 'noah_stripe_subscription_resumed', $customer );
                }
                break;

            case 'customer.subscription.updated':
                $prev_attrs = $event['data']['previous_attributes'] ?? [];
                $new_status = $data_obj['status'] ?? '';
                $old_status = $prev_attrs['status'] ?? '';

                if ( $customer && $new_status !== $old_status ) {
                    if ( in_array( $new_status, [ 'active', 'trialing' ], true ) ) {
                        do_action( 'noah_stripe_subscription_resumed', $customer );
                    }
                    if ( in_array( $new_status, [ 'past_due', 'incomplete', 'incomplete_expired', 'unpaid', 'canceled' ], true ) ) {
                        if ( $user_id_by_sub && $product_id ) {
                            Noah_Access_Revoke::instance()->revoke_program( $user_id_by_sub, $product_id, "stripe_status_{$new_status}" );
                        }
                        do_action( 'noah_stripe_subscription_cancelled', $customer );
                    }
                }
                break;

            case 'invoice.payment_failed':
                if ( $customer ) {
                    do_action( 'noah_stripe_payment_failed', $customer, $data_obj );
                }
                // Update log level to warning
                Noah_DB::log_stripe_event( $type, 'warning', $event_id, $customer, $resolved_user_id,
                    'Amount: ' . ( isset( $data_obj['amount_due'] ) ? ( $data_obj['amount_due'] / 100 ) : 0 ) );
                break;

            case 'invoice.payment_succeeded':
                if ( $customer && ! empty( $data_obj['subscription'] ) ) {
                    // Record renewal if we can resolve the program subscription
                    if ( $user_id_by_sub && $product_id ) {
                        Noah_Subscriptions::instance()->record_renewal( $user_id_by_sub, $product_id );
                    }
                    do_action( 'noah_stripe_payment_recovered', $customer );
                }
                break;
        }
    }

    // ---------------------------------------------------------------
    // Payment failed handler
    // ---------------------------------------------------------------

    public function handle_payment_failed( string $stripe_customer_id, array $invoice_data ): void {
        $user    = $this->get_user_by_stripe_customer( $stripe_customer_id );
        $amount  = isset( $invoice_data['amount_due'] ) ? $invoice_data['amount_due'] / 100 : 0;
        $attempt = isset( $invoice_data['attempt_count'] ) ? (int) $invoice_data['attempt_count'] : 1;

        // Notify admin on first failure only
        if ( 1 === $attempt ) {
            $user_label  = $user ? $user->user_email : "Stripe customer {$stripe_customer_id}";
            $admin_email = get_option( 'admin_email' );
            wp_mail(
                $admin_email,
                sprintf( '[NOAH] Payment failed — %s', $user_label ),
                sprintf(
                    "A subscription payment failed.\n\nCustomer: %s\nAmount: $%.2f\nStripe Customer ID: %s\n\nStripe will retry automatically. Membership is not revoked yet.",
                    $user_label,
                    $amount,
                    $stripe_customer_id
                )
            );
        }
    }

    // ---------------------------------------------------------------
    // Signature verification (Stripe official algorithm)
    // ---------------------------------------------------------------

    private function verify_signature( string $payload, string $sig_header, string $secret ): bool {
        if ( empty( $sig_header ) ) {
            return false;
        }

        $timestamp = null;
        $v1_sig    = null;

        foreach ( explode( ',', $sig_header ) as $part ) {
            $pieces = explode( '=', $part, 2 );
            $key    = $pieces[0] ?? '';
            $val    = $pieces[1] ?? '';
            if ( 't'  === $key ) $timestamp = $val;
            if ( 'v1' === $key ) $v1_sig    = $val;
        }

        if ( ! $timestamp || ! $v1_sig ) {
            return false;
        }
        // Reject events older than 5 minutes (replay attack protection)
        if ( abs( time() - (int) $timestamp ) > 300 ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        return hash_equals( $expected, $v1_sig );
    }

    public function get_user_by_stripe_customer( string $stripe_customer_id ): ?WP_User {
        $users = get_users( [
            'meta_key'   => self::STRIPE_CUSTOMER_META,
            'meta_value' => sanitize_text_field( $stripe_customer_id ),
            'number'     => 1,
        ] );
        return ! empty( $users ) ? $users[0] : null;
    }
}
