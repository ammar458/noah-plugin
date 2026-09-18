<?php
/**
 * Override of woocommerce/templates/notices/notice.php.
 * Renders nothing visible here — assets/js/frontend.js picks up each
 * .noah-notice-data node and shows it in a custom popup instead of
 * WooCommerce's default inline notice banner.
 */
defined( 'ABSPATH' ) || exit;

if ( empty( $messages ) ) {
	return;
}
foreach ( $messages as $message ) : ?>
<div class="noah-notice-data" data-type="notice" hidden><?php echo wp_kses_post( $message ); ?></div>
<?php endforeach; ?>
