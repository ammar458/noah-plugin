<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Import_Sync
 *
 * Covers the Stripe -> website direction of membership sync. The client
 * migrates her PaySimple customers into Stripe by hand (creating the Stripe
 * Customer + Subscription directly in Stripe), so those people never go
 * through our checkout and Noah_Stripe_Customer_Sync never sees them — no
 * WP account, no noah_member role, no linked _stripe_customer_id. This
 * class periodically scans Stripe for customers with an active/trialing
 * Subscription and, for each one, links (or auto-creates) a matching WP
 * account and grants NOAH membership.
 *
 * The reverse reconciliation (a Stripe subscription being cancelled/paused
 * after this sync has linked someone) is intentionally NOT duplicated here —
 * Noah_Stripe_Webhooks already revokes membership in real time for any user
 * with a linked Stripe customer ID, regardless of how that link was made.
 */
class Noah_Stripe_Import_Sync {

    private static ?Noah_Stripe_Import_Sync $instance = null;

    const CRON_HOOK = 'noah_stripe_customer_import_sync';

    // Safety cap on pagination — far more than this business's customer count.
    const MAX_PAGES_PER_STATUS = 50;
    const PAGE_SIZE            = 100;

    public static function instance(): Noah_Stripe_Import_Sync {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'sync' ] );
        add_action( 'admin_post_noah_run_import_sync', [ $this, 'handle_manual_sync' ] );
    }

    // ---------------------------------------------------------------
    // Manual trigger (admin button)
    // ---------------------------------------------------------------

    public function handle_manual_sync(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'noah_run_import_sync' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'noah-protocol' ) );
        }
        $synced = $this->sync();
        wp_safe_redirect( add_query_arg(
            [ 'page' => 'noah-members', 'noah_import_synced' => $synced ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // ---------------------------------------------------------------
    // Sync
    // ---------------------------------------------------------------

    /**
     * Scans Stripe for customers with an active/trialing Subscription,
     * links or creates a matching WP account for each, and grants NOAH
     * membership if not already active. Returns the number of members
     * newly granted/linked.
     */
    public function sync(): int {
        if ( ! Noah_Stripe_Client::available() ) {
            Noah_DB::log_stripe_event( 'import_sync_skipped', 'warning', '', '', null, 'WC_Stripe_API class not found' );
            return 0;
        }

        $subscriptions = array_merge(
            $this->fetch_subscriptions_by_status( 'active' ),
            $this->fetch_subscriptions_by_status( 'trialing' )
        );

        $synced = 0;
        foreach ( $subscriptions as $subscription ) {
            if ( $this->sync_subscription( $subscription ) ) {
                $synced++;
            }
        }

        Noah_DB::log_stripe_event(
            'import_sync_run', 'info', '', '', null,
            sprintf( 'Scanned %d active/trialing subscriptions; granted/linked %d', count( $subscriptions ), $synced )
        );

        return $synced;
    }

    private function sync_subscription( object $subscription ): bool {
        $customer = $subscription->customer ?? null;
        if ( ! is_object( $customer ) || empty( $customer->email ) ) {
            return false; // Expand failed, or a Stripe customer with no email on file.
        }
        $email = sanitize_email( $customer->email );

        $user = Noah_Membership::instance()->get_user_by_stripe_customer( $customer->id );

        if ( ! $user ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                Noah_Stripe_Customer_Sync::set_customer_id( $user->ID, $customer->id );
            }
        }

        if ( ! $user ) {
            $user_id = $this->create_customer_account( $email, $customer->id );
            if ( ! $user_id ) {
                return false;
            }
            $user = get_userdata( $user_id );
        }

        if ( ! $user || Noah_Membership::is_member( $user->ID ) ) {
            return false;
        }

        Noah_Membership::instance()->grant( $user->ID, 0, $subscription->id );
        Noah_DB::log_event( $user->ID, null, 'stripe_import_membership_granted', "Subscription {$subscription->id} / customer {$customer->id}" );

        return true;
    }

    /**
     * Auto-creates a WP customer account for a Stripe customer with no
     * matching WP user, and links it. wc_create_new_customer() itself fires
     * 'woocommerce_created_customer' with the generated password, which
     * WooCommerce's own "New Account" email (WC_Email_Customer_New_Account)
     * already listens to — it emails the customer a set-your-password link,
     * so nothing extra is needed here to make the account usable.
     */
    private function create_customer_account( string $email, string $stripe_customer_id ): int {
        $user_id = wc_create_new_customer( $email, '', wp_generate_password( 20, true ) );
        if ( is_wp_error( $user_id ) ) {
            Noah_DB::log_stripe_event( 'import_sync_account_error', 'warning', '', $stripe_customer_id, null, $user_id->get_error_message() );
            return 0;
        }

        Noah_Stripe_Customer_Sync::set_customer_id( $user_id, $stripe_customer_id );

        return $user_id;
    }

    // ---------------------------------------------------------------
    // Stripe pagination
    // ---------------------------------------------------------------

    private function fetch_subscriptions_by_status( string $status ): array {
        $results        = [];
        $starting_after = '';

        for ( $page = 0; $page < self::MAX_PAGES_PER_STATUS; $page++ ) {
            $query = [
                'status' => $status,
                'limit'  => self::PAGE_SIZE,
                'expand' => [ 'data.customer' ],
            ];
            if ( $starting_after ) {
                $query['starting_after'] = $starting_after;
            }

            try {
                $response = Noah_Stripe_Client::get( 'subscriptions', $query );
            } catch ( \Exception $e ) {
                Noah_DB::log_stripe_event( 'import_sync_fetch_error', 'error', '', '', null, "status={$status}: " . $e->getMessage() );
                break;
            }

            $data = $response->data ?? [];
            if ( empty( $data ) ) {
                break;
            }

            array_push( $results, ...$data );

            if ( empty( $response->has_more ) ) {
                break;
            }
            $starting_after = end( $data )->id;
        }

        return $results;
    }
}
