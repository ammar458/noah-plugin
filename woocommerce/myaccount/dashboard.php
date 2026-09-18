<?php
/**
 * Override of woocommerce/templates/myaccount/dashboard.php.
 * Same hooks as WooCommerce core's own template, for compatibility with
 * anything else that adds content via woocommerce_before/after_account_dashboard.
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_account_dashboard' );

$current_user = wp_get_current_user();
$is_member    = Noah_Membership::is_member( $current_user->ID );
?>
<div class="noah-dashboard">

	<div class="noah-dashboard-welcome">
		<h2>
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Welcome back, %s', 'noah-protocol' ),
				esc_html( $current_user->display_name )
			);
			?>
			<?php if ( $is_member ) : ?>
				<span class="noah-badge noah-badge--active"><?php esc_html_e( 'Active Member', 'noah-protocol' ); ?></span>
			<?php endif; ?>
		</h2>
		<p><?php esc_html_e( 'Manage your orders, addresses, payment methods, and membership all in one place.', 'noah-protocol' ); ?></p>
		<p class="noah-dashboard-meta">
			<?php
			printf(
				wp_kses(
					/* translators: 1: user display name, 2: logout url */
					__( 'Not %1$s? <a href="%2$s">Log out</a>', 'noah-protocol' ),
					[ 'a' => [ 'href' => [] ] ]
				),
				esc_html( $current_user->display_name ),
				esc_url( wc_logout_url() )
			);
			?>
		</p>
	</div>

	<div class="noah-dashboard-grid">
		<a class="noah-dashboard-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
			<span class="noah-dashboard-card-icon" aria-hidden="true">&#128230;</span>
			<span class="noah-dashboard-card-title"><?php esc_html_e( 'Orders', 'noah-protocol' ); ?></span>
			<span class="noah-dashboard-card-desc"><?php esc_html_e( 'View your recent orders and order history', 'noah-protocol' ); ?></span>
		</a>
		<a class="noah-dashboard-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>">
			<span class="noah-dashboard-card-icon" aria-hidden="true">&#127968;</span>
			<span class="noah-dashboard-card-title"><?php esc_html_e( 'Addresses', 'noah-protocol' ); ?></span>
			<span class="noah-dashboard-card-desc"><?php esc_html_e( 'Manage your shipping and billing addresses', 'noah-protocol' ); ?></span>
		</a>
		<a class="noah-dashboard-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'payment-methods' ) ); ?>">
			<span class="noah-dashboard-card-icon" aria-hidden="true">&#128179;</span>
			<span class="noah-dashboard-card-title"><?php esc_html_e( 'Payment Methods', 'noah-protocol' ); ?></span>
			<span class="noah-dashboard-card-desc"><?php esc_html_e( 'View and manage your saved payment methods', 'noah-protocol' ); ?></span>
		</a>
		<a class="noah-dashboard-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'noah-membership' ) ); ?>">
			<span class="noah-dashboard-card-icon" aria-hidden="true">&#127807;</span>
			<span class="noah-dashboard-card-title"><?php esc_html_e( 'My Membership', 'noah-protocol' ); ?></span>
			<span class="noah-dashboard-card-desc">
				<?php
				echo $is_member
					? esc_html__( 'View your membership status and programs', 'noah-protocol' )
					: esc_html__( 'Become a member for exclusive pricing', 'noah-protocol' );
				?>
			</span>
		</a>
		<a class="noah-dashboard-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>">
			<span class="noah-dashboard-card-icon" aria-hidden="true">&#128100;</span>
			<span class="noah-dashboard-card-title"><?php esc_html_e( 'Account Details', 'noah-protocol' ); ?></span>
			<span class="noah-dashboard-card-desc"><?php esc_html_e( 'Edit your password and account information', 'noah-protocol' ); ?></span>
		</a>
	</div>

</div>
<?php do_action( 'woocommerce_after_account_dashboard' ); ?>
