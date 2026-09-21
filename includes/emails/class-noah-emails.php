<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the NOAH program/retreat and membership order emails, and
 * turns off WooCommerce's generic order emails for orders they cover —
 * plain product orders keep using WooCommerce's defaults untouched.
 */
class Noah_Emails {

    private static ?Noah_Emails $instance = null;

    public static function instance(): Noah_Emails {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'register_email_classes' ] );

        add_filter( 'woocommerce_email_enabled_customer_processing_order', [ $this, 'maybe_disable_default_email' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order',  [ $this, 'maybe_disable_default_email' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_new_order',                 [ $this, 'maybe_disable_default_email' ], 10, 2 );
    }

    public function register_email_classes( array $email_classes ): array {
        require_once NOAH_PATH . 'includes/emails/class-noah-email-order-type.php';
        require_once NOAH_PATH . 'includes/emails/class-noah-email-customer-program.php';
        require_once NOAH_PATH . 'includes/emails/class-noah-email-customer-membership.php';
        require_once NOAH_PATH . 'includes/emails/class-noah-email-admin-program.php';
        require_once NOAH_PATH . 'includes/emails/class-noah-email-admin-membership.php';

        $email_classes['noah_customer_program']    = new Noah_Email_Customer_Program();
        $email_classes['noah_customer_membership'] = new Noah_Email_Customer_Membership();
        $email_classes['noah_admin_program']       = new Noah_Email_Admin_Program();
        $email_classes['noah_admin_membership']    = new Noah_Email_Admin_Membership();

        return $email_classes;
    }

    /**
     * Orders covered by a NOAH program/retreat or membership email don't
     * also need WooCommerce's generic order email — simple product orders
     * are untouched.
     */
    public function maybe_disable_default_email( $enabled, $order ) {
        if ( ! $enabled || ! $order instanceof WC_Order ) {
            return $enabled;
        }
        if ( self::order_is_membership( $order ) || self::order_is_program_or_retreat( $order ) ) {
            return false;
        }
        return $enabled;
    }

    public static function order_is_membership( WC_Order $order ): bool {
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' === get_post_meta( $item->get_product_id(), '_noah_is_membership_plan', true ) ) {
                return true;
            }
        }
        return false;
    }

    public static function order_is_program_or_retreat( WC_Order $order ): bool {
        foreach ( $order->get_items() as $item ) {
            if ( has_term( [ 'retreats', 'programs' ], 'product_cat', $item->get_product_id() ) ) {
                return true;
            }
        }
        return false;
    }
}
