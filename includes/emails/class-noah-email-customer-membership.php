<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer for orders containing a NOAH membership product —
 * replaces WooCommerce's generic processing/completed order email for
 * that order.
 */
class Noah_Email_Customer_Membership extends Noah_Email_Order_Type {

    public function __construct() {
        $this->id             = 'noah_customer_membership';
        $this->customer_email = true;
        $this->title          = __( 'NOAH: Membership order (customer)', 'noah-protocol' );
        $this->description    = __( 'Sent to the customer when an order containing the NOAH membership is processing or completed.', 'noah-protocol' );

        parent::__construct();
    }

    protected function trigger_statuses(): array {
        return [ 'processing', 'completed', 'on-hold' ];
    }

    protected function matches_order( WC_Order $order ): bool {
        return Noah_Emails::order_is_membership( $order );
    }

    protected function get_intro_text(): string {
        return __( 'Welcome to the NOAH Membership! Here are your membership order details.', 'noah-protocol' );
    }

    public function get_default_subject(): string {
        return __( 'Welcome to NOAH Membership — order #{order_number}', 'noah-protocol' );
    }

    public function get_default_heading(): string {
        return __( 'Welcome to NOAH Membership', 'noah-protocol' );
    }
}
