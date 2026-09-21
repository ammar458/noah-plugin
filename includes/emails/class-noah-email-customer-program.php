<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer for orders containing a program or retreat product
 * (and no membership product) — replaces WooCommerce's generic
 * processing/completed order email for that order.
 */
class Noah_Email_Customer_Program extends Noah_Email_Order_Type {

    public function __construct() {
        $this->id             = 'noah_customer_program';
        $this->customer_email = true;
        $this->title          = __( 'NOAH: Program/retreat order (customer)', 'noah-protocol' );
        $this->description    = __( 'Sent to the customer when an order containing a program or retreat is processing or completed.', 'noah-protocol' );

        parent::__construct();
    }

    protected function trigger_statuses(): array {
        return [ 'processing', 'completed', 'on-hold' ];
    }

    protected function matches_order( WC_Order $order ): bool {
        return Noah_Emails::order_is_program_or_retreat( $order ) && ! Noah_Emails::order_is_membership( $order );
    }

    protected function get_intro_text(): string {
        return __( 'Thanks for booking with NOAH Protocol — here are your program/retreat order details.', 'noah-protocol' );
    }

    public function get_default_subject(): string {
        return __( 'Your NOAH program/retreat is confirmed — order #{order_number}', 'noah-protocol' );
    }

    public function get_default_heading(): string {
        return __( 'Your program/retreat is confirmed', 'noah-protocol' );
    }
}
