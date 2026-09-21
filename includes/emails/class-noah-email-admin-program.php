<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the site admin for orders containing a program or retreat product
 * (and no membership product) — replaces WooCommerce's generic "New order"
 * email for that order.
 */
class Noah_Email_Admin_Program extends Noah_Email_Order_Type {

    public function __construct() {
        $this->id             = 'noah_admin_program';
        $this->customer_email = false;
        $this->title          = __( 'NOAH: New program/retreat order (admin)', 'noah-protocol' );
        $this->description    = __( 'Sent to the admin when an order containing a program or retreat is processing or completed.', 'noah-protocol' );

        parent::__construct();
    }

    protected function trigger_statuses(): array {
        return [ 'processing', 'completed', 'on-hold' ];
    }

    protected function matches_order( WC_Order $order ): bool {
        return Noah_Emails::order_is_program_or_retreat( $order ) && ! Noah_Emails::order_is_membership( $order );
    }

    protected function get_intro_text(): string {
        return __( 'A new program/retreat order has been placed.', 'noah-protocol' );
    }

    public function get_default_subject(): string {
        return __( '[{site_title}] New program/retreat order (#{order_number})', 'noah-protocol' );
    }

    public function get_default_heading(): string {
        return __( 'New program/retreat order', 'noah-protocol' );
    }

    public function init_form_fields(): void {
        parent::init_form_fields();

        $recipient_field = [ 'recipient' => [
            'title'       => __( 'Recipient(s)', 'noah-protocol' ),
            'type'        => 'text',
            /* translators: %s: default admin email */
            'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'noah-protocol' ), esc_attr( get_option( 'admin_email' ) ) ),
            'placeholder' => '',
            'default'     => '',
            'desc_tip'    => true,
        ] ];

        $enabled = [ 'enabled' => $this->form_fields['enabled'] ];
        unset( $this->form_fields['enabled'] );

        $this->form_fields = $enabled + $recipient_field + $this->form_fields;
    }
}
