<?php
/**
 * Template add-to-cart para tipo NOAH Subscription.
 * Copia de simple.php de WooCommerce — renderiza el botón estándar
 * para que funcione con cualquier tema (incluido Hello Elementor).
 */
defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product->is_purchasable() ) return;

echo wc_get_stock_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( $product->is_in_stock() ) : ?>

    <?php do_action( 'woocommerce_before_add_to_cart_form' ); ?>

    <form class="cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype='multipart/form-data'>
        <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

        <?php
        do_action( 'woocommerce_before_add_to_cart_quantity' );
        woocommerce_quantity_input(
            array(
                'min_value'   => apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product ),
                'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product ),
                'input_value' => isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : $product->get_min_purchase_quantity(), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            )
        );
        do_action( 'woocommerce_after_add_to_cart_quantity' );
        ?>

        <button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>">
            <?php echo esc_html( $product->single_add_to_cart_text() ); ?>
        </button>

        <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>

        <input type="hidden" name="add-to-cart" value="<?php echo absint( $product->get_id() ); ?>" />
        <input type="hidden" name="product_id" value="<?php echo absint( $product->get_id() ); ?>" />
        <input type="hidden" name="variation_id" value="0" />
    </form>

    <?php do_action( 'woocommerce_after_add_to_cart_form' ); ?>

<?php endif;
