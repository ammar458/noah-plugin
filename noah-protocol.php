<?php
/**
 * Plugin Name: Noah Memberships and programs
 * Plugin URI:  https://ringomedia.com
 * Description: Membership subscriptions, fixed-cycle program billing, automatic member discounts, and content access control. Built for WooCommerce + Stripe for WooCommerce.
 * Version:     3.7.2
 * Author:      RingoMedia
 * Author URI:  https://ringomedia.com
 * Text Domain: noah-protocol
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 6.0
 * WC tested up to: 9.9
 */

defined( 'ABSPATH' ) || exit;

define( 'NOAH_VERSION',     '3.7.2' );
define( 'NOAH_FILE',        __FILE__ );
define( 'NOAH_PATH',        plugin_dir_path( __FILE__ ) );
define( 'NOAH_URL',         plugin_dir_url( __FILE__ ) );
define( 'NOAH_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/* ---------------------------------------------------------------
 * HPOS compatibility (WooCommerce 8+)
 * ------------------------------------------------------------- */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );

/* ---------------------------------------------------------------
 * Lifecycle classes loaded immediately at the top level.
 * register_activation_hook fires BEFORE plugins_loaded, so these
 * must be required here — not inside noah_boot() — otherwise
 * WordPress cannot find Noah_Activation when it calls the hook.
 * ------------------------------------------------------------- */
require_once NOAH_PATH . 'hooks/noah-activation.php';
require_once NOAH_PATH . 'hooks/noah-deactivation.php';
require_once NOAH_PATH . 'hooks/noah-uninstall.php';
require_once NOAH_PATH . 'database/class-noah-db.php';

/* ---------------------------------------------------------------
 * GitHub-based update checker
 * Detects new releases published on GitHub (tagged versions) and
 * feeds them into the normal WP admin "update available" flow.
 * ------------------------------------------------------------- */
require_once NOAH_PATH . 'includes/updater/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://github.com/ammar458/noah-plugin',
    __FILE__,
    'noah-protocol'
);

/* ---------------------------------------------------------------
 * Lifecycle hooks
 * ------------------------------------------------------------- */
register_activation_hook(   __FILE__, [ 'Noah_Activation',   'run' ] );
register_deactivation_hook( __FILE__, [ 'Noah_Deactivation', 'run' ] );
register_uninstall_hook(    __FILE__, [ 'Noah_Uninstall',    'run' ] );

/* ---------------------------------------------------------------
 * Boot after all plugins loaded
 * ------------------------------------------------------------- */
add_action( 'plugins_loaded', 'noah_boot', 20 );

function noah_boot(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Noah Memberships and programs requires WooCommerce to be active.', 'noah-protocol' )
                . '</p></div>';
        } );
        return;
    }

    // Ensure tables exist (idempotent — skips if already created)
    Noah_DB::maybe_create_tables();

    // Product type
    require_once NOAH_PATH . 'product-types/class-wc-product-noah-subscription.php';

    // Modules
    require_once NOAH_PATH . 'includes/membership/class-noah-membership.php';
    require_once NOAH_PATH . 'includes/subscriptions/class-noah-subscriptions.php';
    require_once NOAH_PATH . 'includes/subscriptions/class-noah-subscription-length.php';
    require_once NOAH_PATH . 'includes/discounts/class-noah-discount.php';
    require_once NOAH_PATH . 'includes/discounts/class-noah-discount-cart.php';
    require_once NOAH_PATH . 'includes/pricing/class-noah-pricing.php';
    require_once NOAH_PATH . 'includes/stripe/class-noah-stripe-client.php';
    require_once NOAH_PATH . 'includes/stripe/class-noah-stripe-webhooks.php';
    require_once NOAH_PATH . 'includes/stripe/class-noah-stripe-recurring.php';
    require_once NOAH_PATH . 'includes/stripe/class-noah-stripe-customer-sync.php';
    require_once NOAH_PATH . 'includes/access/class-noah-access-manager.php';
    require_once NOAH_PATH . 'includes/access/class-noah-access-program.php';
    require_once NOAH_PATH . 'includes/access/class-noah-access-revoke.php';
    require_once NOAH_PATH . 'includes/admin/class-noah-admin.php';
    require_once NOAH_PATH . 'includes/enqueue.php';

    // Boot singletons
    Noah_Membership::instance();
    Noah_Subscriptions::instance();
    Noah_Subscription_Length::instance();
    Noah_Discount::instance();
    Noah_Discount_Cart::instance();
    Noah_Pricing::instance();
    Noah_Stripe_Webhooks::instance();
    Noah_Stripe_Recurring::instance();
    Noah_Stripe_Customer_Sync::instance();
    Noah_Access_Manager::instance();
    Noah_Access_Program::instance();
    Noah_Access_Revoke::instance();
    Noah_Admin::instance();

    // Text domain
    add_action( 'init', function () {
        load_plugin_textdomain( 'noah-protocol', false, dirname( NOAH_PLUGIN_BASE ) . '/languages' );
    } );
}
