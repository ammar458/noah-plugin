<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Recurring
 * Automatically configures Stripe subscriptions with a cancel_at date
 * based on the program's billing cycles. This means Stripe stops charging
 * without any manual Stripe Dashboard configuration per product.
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
        // After program access record is created, push cancel_at to Stripe
        add_action( 'noah_program_subscription_started', [ $this, 'setup_stripe_cycle_limit' ], 10, 3 );
        // After final cycle paid, explicitly cancel the Stripe subscription
        add_action( 'noah_program_final_cycle_paid',     [ $this, 'cancel_stripe_subscription' ], 10, 2 );
    }

    /**
     * Set cancel_at on the Stripe subscription so Stripe stops billing automatically.
     * This removes the need for manual configuration in the Stripe Dashboard per product.
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

        $stripe = $this->get_stripe_client();
        if ( ! $stripe ) {
            return;
        }

        try {
            $stripe->subscriptions->update( $access->stripe_sub_id, [
                'cancel_at' => $cancel_at,
            ] );
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

        $stripe = $this->get_stripe_client();
        if ( ! $stripe ) {
            return;
        }

        try {
            $stripe->subscriptions->cancel( $access->stripe_sub_id );
            Noah_DB::log_event( $user_id, $product_id, 'stripe_sub_cancelled_final_cycle' );
        } catch ( \Exception $e ) {
            Noah_DB::log_event( $user_id, $product_id, 'stripe_sub_cancel_error', $e->getMessage() );
        }
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
