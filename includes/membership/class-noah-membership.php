<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Membership
 *
 * Merges:
 *   - DB-backed member records with status + expiry (our plugin)
 *   - Stripe customer ID lookup via user meta (v8)
 *   - Admin user profile grant/revoke toggle (v8)
 *   - Fix: removed 'processing' hook — only 'completed' grants membership
 *   - Fix: user_has_active_membership_order uses a targeted DB query, not limit -1
 */
class Noah_Membership {

    private static ?Noah_Membership $instance = null;

    /** Meta key used by Stripe for WooCommerce to store the Stripe Customer ID */
    const STRIPE_CUSTOMER_META = '_stripe_customer_id';

    public static function instance(): Noah_Membership {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only 'completed' — not 'processing' — grants membership
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_grant_membership' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'maybe_revoke_membership' ] );
        add_action( 'woocommerce_order_status_refunded',  [ $this, 'maybe_revoke_membership' ] );

        // Stripe webhook actions (fired by Noah_Stripe_Webhooks)
        add_action( 'noah_stripe_subscription_cancelled', [ $this, 'revoke_by_stripe_customer' ] );
        add_action( 'noah_stripe_subscription_paused',    [ $this, 'revoke_by_stripe_customer' ] );
        add_action( 'noah_stripe_subscription_resumed',   [ $this, 'grant_by_stripe_customer'  ] );
        add_action( 'noah_stripe_payment_recovered',      [ $this, 'grant_by_stripe_customer'  ] );

        // Admin user profile
        add_action( 'show_user_profile',        [ $this, 'render_profile_fields' ] );
        add_action( 'edit_user_profile',        [ $this, 'render_profile_fields' ] );
        add_action( 'personal_options_update',  [ $this, 'save_profile_fields'   ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_profile_fields'   ] );

        // Daily cleanup
        add_action( 'noah_daily_cleanup', [ $this, 'expire_lapsed_memberships' ] );

        // My Account membership tab
        add_filter( 'woocommerce_account_menu_items',                [ $this, 'add_account_tab'    ] );
        add_action( 'woocommerce_account_noah-membership_endpoint',  [ $this, 'render_account_tab' ] );
        add_action( 'init',                                          [ $this, 'register_endpoint'  ] );
    }

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    public function grant( int $user_id, int $order_id = 0, string $stripe_sub_id = '' ): void {
        if ( ! $user_id ) {
            return;
        }

        Noah_DB::upsert_member( $user_id, [
            'order_id'      => $order_id,
            'stripe_sub_id' => $stripe_sub_id,
            'status'        => 'active',
            'started_at'    => current_time( 'mysql' ),
            'expires_at'    => null,
            'cancelled_at'  => null,
        ] );

        $user = get_userdata( $user_id );
        if ( $user && ! in_array( 'noah_member', (array) $user->roles, true ) ) {
            $user->add_role( 'noah_member' );
        }

        update_user_meta( $user_id, '_noah_member_since', current_time( 'mysql' ) );
        Noah_DB::log_event( $user_id, null, 'membership_granted', "Order #{$order_id}" );
        do_action( 'noah_membership_granted', $user_id, $order_id );
    }

    public function revoke( int $user_id, string $reason = '' ): void {
        if ( ! $user_id ) {
            return;
        }

        Noah_DB::upsert_member( $user_id, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time( 'mysql' ),
        ] );

        $user = get_userdata( $user_id );
        if ( $user && in_array( 'noah_member', (array) $user->roles, true ) ) {
            $user->remove_role( 'noah_member' );
        }

        update_user_meta( $user_id, '_noah_member_revoked', current_time( 'mysql' ) );
        Noah_DB::log_event( $user_id, null, 'membership_revoked', $reason );
        do_action( 'noah_membership_revoked', $user_id );
    }

    public function expire( int $user_id ): void {
        Noah_DB::upsert_member( $user_id, [
            'status'     => 'expired',
            'expires_at' => current_time( 'mysql' ),
        ] );

        $user = get_userdata( $user_id );
        if ( $user ) {
            $user->remove_role( 'noah_member' );
        }

        Noah_DB::log_event( $user_id, null, 'membership_expired' );
        do_action( 'noah_membership_expired', $user_id );
    }

    public static function is_member( int $user_id = 0 ): bool {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        // Fast role check first; DB is the source of truth for expiry
        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( 'noah_member', (array) $user->roles, true ) ) {
            return false;
        }
        return Noah_DB::is_active_member( $user_id );
    }

    // ---------------------------------------------------------------
    // Order hooks
    // ---------------------------------------------------------------

    public function maybe_grant_membership( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $this->order_contains_membership_product( $order ) ) {
            $user_id = (int) $order->get_customer_id();
            if ( $user_id ) {
                $this->grant( $user_id, $order_id );
            }
        }
    }

    public function maybe_revoke_membership( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( ! $this->order_contains_membership_product( $order ) ) {
            return;
        }
        $user_id = (int) $order->get_customer_id();
        if ( $user_id && ! $this->user_has_other_active_membership_order( $user_id, $order_id ) ) {
            $this->revoke( $user_id, "order_{$order_id}_cancelled_or_refunded" );
        }
    }

    // ---------------------------------------------------------------
    // Stripe hooks
    // ---------------------------------------------------------------

    public function revoke_by_stripe_customer( string $stripe_customer_id ): void {
        $user = $this->get_user_by_stripe_customer( $stripe_customer_id );
        if ( $user ) {
            $this->revoke( $user->ID, "stripe_customer_{$stripe_customer_id}" );
        }
    }

    public function grant_by_stripe_customer( string $stripe_customer_id ): void {
        $user = $this->get_user_by_stripe_customer( $stripe_customer_id );
        if ( $user ) {
            $this->grant( $user->ID, 0, '' );
        }
    }

    // ---------------------------------------------------------------
    // Cron: expire lapsed memberships
    // ---------------------------------------------------------------

    public function expire_lapsed_memberships(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT user_id FROM {$wpdb->prefix}noah_members
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        foreach ( $rows as $row ) {
            $this->expire( (int) $row->user_id );
        }
    }

    // ---------------------------------------------------------------
    // Admin user profile
    // ---------------------------------------------------------------

    public function render_profile_fields( WP_User $user ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $is_member = self::is_member( $user->ID );
        $since     = get_user_meta( $user->ID, '_noah_member_since',   true );
        $revoked   = get_user_meta( $user->ID, '_noah_member_revoked', true );
        ?>
        <h2><?php esc_html_e( 'NOAH Protocol Membership', 'noah-protocol' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="noah_is_member"><?php esc_html_e( 'Active Member', 'noah-protocol' ); ?></label></th>
                <td>
                    <input type="checkbox" id="noah_is_member" name="noah_is_member" value="1" <?php checked( $is_member ); ?>>
                    <span class="description"><?php esc_html_e( 'Check to grant, uncheck to revoke NOAH membership.', 'noah-protocol' ); ?></span>
                    <?php if ( $since ) : ?>
                        <p class="description"><?php printf( esc_html__( 'Member since: %s', 'noah-protocol' ), esc_html( $since ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $revoked ) : ?>
                        <p class="description"><?php printf( esc_html__( 'Last revoked: %s', 'noah-protocol' ), esc_html( $revoked ) ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php wp_nonce_field( 'noah_membership_nonce', '_noah_nonce' ); ?>
        <?php
    }

    public function save_profile_fields( int $user_id ): void {
        if ( ! isset( $_POST['_noah_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_noah_nonce'] ) ), 'noah_membership_nonce' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! empty( $_POST['noah_is_member'] ) ) {
            $this->grant( $user_id );
        } else {
            $this->revoke( $user_id, 'admin_profile_update' );
        }
    }

    // ---------------------------------------------------------------
    // My Account tab
    // ---------------------------------------------------------------

    public function register_endpoint(): void {
        add_rewrite_endpoint( 'noah-membership', EP_ROOT | EP_PAGES );
    }

    public function add_account_tab( array $items ): array {
        $logout = $items['customer-logout'] ?? null;
        unset( $items['customer-logout'] );
        $items['noah-membership'] = __( 'My Membership', 'noah-protocol' );
        if ( $logout ) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public function render_account_tab(): void {
        $user_id = get_current_user_id();
        $member  = Noah_DB::get_member( $user_id );
        include NOAH_PATH . 'templates/frontend/member-dashboard.php';
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function order_contains_membership_product( WC_Order $order ): bool {
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' === get_post_meta( $item->get_product_id(), '_noah_is_membership_plan', true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Targeted query replacing the old wc_get_orders( 'limit' => -1 ) approach.
     * Uses WooCommerce order item meta tables directly to avoid loading all orders.
     */
    private function user_has_other_active_membership_order( int $user_id, int $exclude_order_id ): bool {
        global $wpdb;

        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT o.id)
             FROM {$wpdb->prefix}wc_orders o
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.id
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                 ON oim.order_item_id = oi.order_item_id
                 AND oim.meta_key = '_noah_is_membership_plan'
                 AND oim.meta_value = 'yes'
             WHERE o.customer_id = %d
               AND o.status IN ('wc-completed', 'wc-processing')
               AND o.id != %d",
            $user_id,
            $exclude_order_id
        ) );

        return $count > 0;
    }

    public function get_user_by_stripe_customer( string $stripe_customer_id ): ?WP_User {
        $users = get_users( [
            'meta_key'   => self::STRIPE_CUSTOMER_META,
            'meta_value' => sanitize_text_field( $stripe_customer_id ),
            'number'     => 1,
        ] );
        return ! empty( $users ) ? $users[0] : null;
    }
}
