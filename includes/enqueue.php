<?php
defined( 'ABSPATH' ) || exit;

// Frontend: only on WooCommerce pages
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() || is_singular() ) {
        wp_enqueue_style(
            'noah-protocol-frontend',
            NOAH_URL . 'assets/css/frontend.css',
            [],
            NOAH_VERSION
        );
        wp_enqueue_script(
            'noah-protocol-frontend',
            NOAH_URL . 'assets/js/frontend.js',
            [ 'jquery' ],
            NOAH_VERSION,
            true
        );
    }
} );

// Admin: product edit page only
add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'product' !== $screen->post_type ) {
        return;
    }
    ?>
    <style>
    #woocommerce-product-data ul.wc-tabs li.general_options { display: block !important; }
    .noah-subscription-only { display: none; }
    /* Our field labels run longer than WooCommerce's default 150px label
       column (e.g. "Member discount override (%)"), which otherwise wraps
       them across multiple lines. Widen the label column instead so label
       and input stay side by side on one line, same as WooCommerce's own
       fields — just wide enough that the text and its tooltip icon never wrap. */
    .noah-field label {
        width: 260px;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 4px;
        padding-top: 0;
    }
    .noah-field .woocommerce-help-tip {
        margin: 0;
    }
    </style>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        function noahHandleType() {
            var type = $('select#product-type').val();

            $('ul.wc-tabs li.general_options').show();
            $('#general_product_data').show();

            if ( type === 'noah_subscription' ) {
                $('._regular_price_field, ._sale_price_field, .sale_price_dates_fields').hide();
                $('.noah-subscription-only').show();
                $('.noah-subscription-only input').prop('disabled', false);
                noahHandleBillingPeriod();
            } else {
                $('._regular_price_field, ._sale_price_field').show();
                $('.noah-subscription-only').hide();
                $('.noah-subscription-only input').prop('disabled', true);
            }
        }

        function noahHandleBillingPeriod() {
            var isOnetime = $('#_noah_billing_period').val() === 'onetime';
            $('.noah-onetime-hide').toggle(!isOnetime);
            $('.noah-onetime-hide input').prop('disabled', isOnetime);
        }

        noahHandleType();
        $('select#product-type').on('change', noahHandleType);
        $('#_noah_billing_period').on('change', noahHandleBillingPeriod);
        setTimeout(noahHandleType, 600);
    });
    </script>
    <?php
} );
