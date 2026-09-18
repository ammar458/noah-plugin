<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Product_Noah_Subscription' ) ) {

    class WC_Product_Noah_Subscription extends WC_Product_Simple {

        public function get_type(): string {
            return 'noah_subscription';
        }

        public function is_purchasable(): bool {
            return true;
        }

        public function is_virtual(): bool {
            return true;
        }

        public function is_sold_individually(): bool {
            return true;
        }

        public function get_billing_cycles(): int {
            return (int) $this->get_meta( '_noah_billing_cycles', true ) ?: 0;
        }

        public function get_billing_period(): string {
            $period = $this->get_meta( '_noah_billing_period', true );
            return in_array( $period, [ 'week', 'month' ], true ) ? $period : 'week';
        }

        public function is_one_time_payment(): bool {
            return 'yes' === $this->get_meta( '_noah_one_time_payment', true );
        }

        public function add_to_cart_text(): string {
            return apply_filters(
                'woocommerce_product_add_to_cart_text',
                __( 'Select Program', 'noah-protocol' ),
                $this
            );
        }

        public function single_add_to_cart_text(): string {
            return apply_filters(
                'woocommerce_product_single_add_to_cart_text',
                __( 'Add to Cart', 'noah-protocol' ),
                $this
            );
        }
    }
}
