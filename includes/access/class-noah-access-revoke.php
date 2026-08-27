<?php
defined( 'ABSPATH' ) || exit;

class Noah_Access_Revoke {

    private static ?Noah_Access_Revoke $instance = null;

    public static function instance(): Noah_Access_Revoke {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'noah_membership_revoked',  [ $this, 'revoke_all_program_accesses' ] );
        add_action( 'noah_membership_expired',  [ $this, 'revoke_all_program_accesses' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle_order_cancelled' ] );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'handle_order_cancelled' ] );
    }

    public function revoke_program( int $user_id, int $product_id, string $reason = '' ): void {
        Noah_DB::upsert_program_access( $user_id, $product_id, [
            'status'     => 'revoked',
            'revoked_at' => current_time( 'mysql' ),
        ] );
        Noah_DB::log_event( $user_id, $product_id, 'program_access_revoked', $reason );
        $this->send_revocation_email( $user_id, $product_id );
        do_action( 'noah_program_access_revoked', $user_id, $product_id, $reason );
    }

    public function revoke_all_program_accesses( int $user_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id FROM {$wpdb->prefix}noah_program_access
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );
        foreach ( $rows as $row ) {
            $this->revoke_program( $user_id, (int) $row->product_id, 'membership_ended' );
        }
    }

    public function handle_order_cancelled( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = (int) $order->get_customer_id();
        foreach ( $order->get_items() as $item ) {
            $product = wc_get_product( $item->get_product_id() );
            if ( $product && $product->get_type() === 'noah_subscription' ) {
                $this->revoke_program( $user_id, (int) $product->get_id(), 'order_cancelled' );
            }
        }
    }

    private function send_revocation_email( int $user_id, int $product_id ): void {
        $user    = get_userdata( $user_id );
        $product = wc_get_product( $product_id );
        if ( ! $user || ! $product ) {
            return;
        }
        wp_mail(
            $user->user_email,
            sprintf( __( 'Your access to %s has ended', 'noah-protocol' ), $product->get_name() ),
            sprintf(
                __( "Hi %1\$s,\n\nYour access to the %2\$s program has ended.\n\nIf you believe this is an error, please contact us.\n\nThe Noah Memberships and programs Team", 'noah-protocol' ),
                $user->display_name,
                $product->get_name()
            ),
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
    }
}
