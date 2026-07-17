<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Admin
 *
 * Merged from v8 (WC settings tab, Stripe setup checklist, members column)
 * and our plugin (reports tab, DB-backed Stripe log, discount rules UI).
 */
class Noah_Admin {

    private static ?Noah_Admin $instance = null;

    public static function instance(): Noah_Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'woocommerce_settings_tabs_array',          [ $this, 'add_settings_tab'   ], 60 );
        add_action( 'woocommerce_settings_tabs_noah_protocol',  [ $this, 'render_settings'    ] );
        add_action( 'woocommerce_update_options_noah_protocol', [ $this, 'save_settings'      ] );

        add_filter( 'manage_users_columns',       [ $this, 'add_member_column'    ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_member_column' ], 10, 3 );

        add_action( 'admin_menu', [ $this, 'add_submenus' ] );

        add_action( 'admin_post_noah_clear_stripe_log',      [ $this, 'clear_stripe_log'      ] );
        add_action( 'admin_post_noah_cancel_member',         [ $this, 'handle_cancel_member'  ] );
        add_action( 'admin_post_noah_save_program_discount', [ $this, 'handle_save_discount'  ] );
    }

    // ---------------------------------------------------------------
    // WooCommerce settings tab
    // ---------------------------------------------------------------

    public function add_settings_tab( array $tabs ): array {
        $tabs['noah_protocol'] = __( 'NOAH Protocol', 'noah-protocol' );
        return $tabs;
    }

    public function render_settings(): void {
        $active_tab = isset( $_GET['noah_tab'] ) ? sanitize_key( $_GET['noah_tab'] ) : 'general';
        $tabs = [
            'general' => __( 'General',        'noah-protocol' ),
            'stripe'  => __( 'Stripe Setup',   'noah-protocol' ),
            'log'     => __( 'Stripe Log',     'noah-protocol' ),
            'reports' => __( 'Reports',        'noah-protocol' ),
        ];
        ?>
        <div style="margin:10px 0 0;">
            <?php foreach ( $tabs as $key => $label ) :
                $url    = add_query_arg( 'noah_tab', $key );
                $active = $active_tab === $key ? 'nav-tab-active' : '';
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
        switch ( $active_tab ) {
            case 'stripe':  $this->render_stripe_setup_tab(); break;
            case 'log':     $this->render_stripe_log_tab();   break;
            case 'reports': $this->render_reports_tab();      break;
            default:        woocommerce_admin_fields( $this->get_settings() );
        }
    }

    public function save_settings(): void {
        $active_tab = isset( $_GET['noah_tab'] ) ? sanitize_key( $_GET['noah_tab'] ) : 'general';
        if ( 'general' === $active_tab ) {
            woocommerce_update_options( $this->get_settings() );
        }
    }

    private function get_settings(): array {
        return [
            [
                'title' => __( 'NOAH Protocol Settings', 'noah-protocol' ),
                'type'  => 'title',
                'id'    => 'noah_section_main',
            ],
            [
                'title'   => __( 'Member price badge text', 'noah-protocol' ),
                'type'    => 'text',
                'id'      => 'noah_member_badge_text',
                'default' => __( 'Member price', 'noah-protocol' ),
            ],
            [
                'title'   => __( 'Non-member teaser text', 'noah-protocol' ),
                'type'    => 'text',
                'desc'    => __( 'Use {price} as a placeholder for the member price.', 'noah-protocol' ),
                'id'      => 'noah_nonmember_teaser_text',
                'default' => __( 'Members pay {price}/week', 'noah-protocol' ),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'noah_section_main',
            ],
            [
                'title' => __( 'Stripe', 'noah-protocol' ),
                'type'  => 'title',
                'id'    => 'noah_section_stripe',
            ],
            [
                'title'   => __( 'Stripe Webhook Secret', 'noah-protocol' ),
                'type'    => 'password',
                'desc'    => __( 'Signing secret from Stripe Dashboard. Leave blank during testing.', 'noah-protocol' ),
                'id'      => 'noah_stripe_webhook_secret',
                'default' => '',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'noah_section_stripe',
            ],
            [
                'title' => __( 'Data', 'noah-protocol' ),
                'type'  => 'title',
                'id'    => 'noah_section_data',
            ],
            [
                'title'   => __( 'Delete data on uninstall', 'noah-protocol' ),
                'type'    => 'checkbox',
                'desc'    => __( 'Remove all NOAH Protocol tables and options when the plugin is uninstalled.', 'noah-protocol' ),
                'id'      => 'noah_delete_data_on_uninstall',
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'noah_section_data',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Stripe setup checklist tab (from v8, kept as-is — excellent UX)
    // ---------------------------------------------------------------

    private function render_stripe_setup_tab(): void {
        $webhook_url   = rest_url( 'noah-protocol/v1/stripe-webhook' );
        $secret_saved  = ! empty( get_option( 'noah_stripe_webhook_secret', '' ) );
        $stripe_plugin = class_exists( 'WC_Stripe' ) || class_exists( 'WooCommerce_Stripe' );

        $required_events = [
            'customer.subscription.deleted' => __( 'Subscription ends (cycles complete or manually cancelled)', 'noah-protocol' ),
            'customer.subscription.paused'  => __( 'Subscription paused (e.g. payment grace period)', 'noah-protocol' ),
            'customer.subscription.resumed' => __( 'Subscription resumed after pause', 'noah-protocol' ),
            'customer.subscription.updated' => __( 'Subscription status changes (past_due, active, etc.)', 'noah-protocol' ),
            'invoice.payment_failed'        => __( 'Weekly renewal payment fails', 'noah-protocol' ),
            'invoice.payment_succeeded'     => __( 'Payment succeeds (including after failure recovery)', 'noah-protocol' ),
        ];
        ?>
        <div style="max-width:800px;margin-top:24px;">
            <h2><?php esc_html_e( 'Stripe Configuration Checklist', 'noah-protocol' ); ?></h2>
            <p><?php esc_html_e( 'Complete these steps in the Stripe Dashboard when you are ready to go live.', 'noah-protocol' ); ?></p>

            <div style="<?php echo $this->card_style( $stripe_plugin ); ?>">
                <h3 style="margin:0 0 8px;"><?php echo $this->step_icon( $stripe_plugin ); ?> <?php esc_html_e( 'Step 1 — Install Stripe for WooCommerce', 'noah-protocol' ); ?></h3>
                <?php if ( $stripe_plugin ) : ?>
                    <p style="margin:0;color:#1a7b2e;"><?php esc_html_e( 'Stripe for WooCommerce plugin detected.', 'noah-protocol' ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Install and activate the official Stripe for WooCommerce plugin (by WooCommerce/Automattic).', 'noah-protocol' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=stripe+woocommerce&tab=search' ) ); ?>" class="button"><?php esc_html_e( 'Go to Plugin Search', 'noah-protocol' ); ?></a>
                <?php endif; ?>
            </div>

            <div style="<?php echo $this->card_style( false ); ?>">
                <h3 style="margin:0 0 8px;">&#9881; <?php esc_html_e( 'Step 2 — Register Webhook Endpoint in Stripe', 'noah-protocol' ); ?></h3>
                <p><?php esc_html_e( 'In Stripe Dashboard, go to Developers, then Webhooks, then Add endpoint, and paste this URL:', 'noah-protocol' ); ?></p>
                <code style="display:block;background:#f0f0f0;padding:10px 14px;border-radius:6px;font-size:13px;word-break:break-all;"><?php echo esc_url( $webhook_url ); ?></code>
                <button type="button"
                        onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>').then(()=>{this.textContent='Copied!';setTimeout(()=>{this.textContent='Copy URL';},2000);})"
                        class="button" style="margin-top:10px;"><?php esc_html_e( 'Copy URL', 'noah-protocol' ); ?></button>
            </div>

            <div style="<?php echo $this->card_style( false ); ?>">
                <h3 style="margin:0 0 8px;">&#128203; <?php esc_html_e( 'Step 3 — Subscribe to These Events', 'noah-protocol' ); ?></h3>
                <table class="widefat" style="margin-top:8px;">
                    <thead><tr><th><?php esc_html_e( 'Stripe Event', 'noah-protocol' ); ?></th><th><?php esc_html_e( 'Why it is needed', 'noah-protocol' ); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ( $required_events as $event => $reason ) : ?>
                        <tr><td><code><?php echo esc_html( $event ); ?></code></td><td><?php echo esc_html( $reason ); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="<?php echo $this->card_style( $secret_saved ); ?>">
                <h3 style="margin:0 0 8px;"><?php echo $this->step_icon( $secret_saved ); ?> <?php esc_html_e( 'Step 4 — Save the Signing Secret', 'noah-protocol' ); ?></h3>
                <?php if ( $secret_saved ) : ?>
                    <p style="margin:0;color:#1a7b2e;"><?php esc_html_e( 'Webhook signing secret is saved.', 'noah-protocol' ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'After creating the endpoint in Stripe, click Reveal under Signing secret and paste it in the General tab.', 'noah-protocol' ); ?></p>
                    <a href="<?php echo esc_url( add_query_arg( 'noah_tab', 'general' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Go to General settings', 'noah-protocol' ); ?></a>
                <?php endif; ?>
            </div>

            <div style="<?php echo $this->card_style( false ); ?>">
                <h3 style="margin:0 0 8px;">&#128293; <?php esc_html_e( 'Step 5 — Note on Stripe Product Configuration', 'noah-protocol' ); ?></h3>
                <p><?php esc_html_e( 'NOAH Protocol automatically sets cancel_at on each Stripe subscription when a program starts, based on the billing cycles you configure on the product. You do NOT need to manually set cycle limits in the Stripe Dashboard.', 'noah-protocol' ); ?></p>
                <ul style="list-style:disc;padding-left:20px;line-height:1.8;">
                    <li><?php esc_html_e( 'Set billing period on each product: Weekly or Monthly', 'noah-protocol' ); ?></li>
                    <li><?php esc_html_e( 'Set billing cycles on each product: e.g. 3 for a 3-week program', 'noah-protocol' ); ?></li>
                    <li><?php esc_html_e( 'Leave billing cycles at 0 on the Membership Plan (ongoing)', 'noah-protocol' ); ?></li>
                </ul>
            </div>

            <div style="<?php echo $this->card_style( false ); ?>">
                <h3 style="margin:0 0 8px;">&#129514; <?php esc_html_e( 'Step 6 — Test with Stripe CLI', 'noah-protocol' ); ?></h3>
                <code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:10px 14px;border-radius:6px;font-size:12px;">stripe listen --forward-to <?php echo esc_html( $webhook_url ); ?></code>
                <p style="margin-top:10px;"><?php esc_html_e( 'Then trigger a test event:', 'noah-protocol' ); ?></p>
                <code style="display:block;background:#1e1e1e;color:#d4d4d4;padding:10px 14px;border-radius:6px;font-size:12px;">stripe trigger customer.subscription.deleted</code>
                <p style="margin-top:8px;"><?php esc_html_e( 'Check the Stripe Log tab to confirm the event was received.', 'noah-protocol' ); ?></p>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Stripe log tab (DB-backed, not WP option)
    // ---------------------------------------------------------------

    private function render_stripe_log_tab(): void {
        $log = Noah_DB::get_stripe_log( 100 );
        $colors = [ 'info' => '#0a7acf', 'warning' => '#d97706', 'error' => '#dc2626' ];
        ?>
        <div style="max-width:1000px;margin-top:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h2 style="margin:0;"><?php esc_html_e( 'Stripe Event Log', 'noah-protocol' ); ?></h2>
                <?php if ( ! empty( $log ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="noah_clear_stripe_log">
                    <?php wp_nonce_field( 'noah_clear_log' ); ?>
                    <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Clear the event log?', 'noah-protocol' ); ?>')"><?php esc_html_e( 'Clear log', 'noah-protocol' ); ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php if ( empty( $log ) ) : ?>
                <p style="color:#666;"><?php esc_html_e( 'No events logged yet.', 'noah-protocol' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Time', 'noah-protocol' ); ?></th>
                        <th><?php esc_html_e( 'Level', 'noah-protocol' ); ?></th>
                        <th><?php esc_html_e( 'Event', 'noah-protocol' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'noah-protocol' ); ?></th>
                        <th><?php esc_html_e( 'User', 'noah-protocol' ); ?></th>
                        <th><?php esc_html_e( 'Note', 'noah-protocol' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $log as $row ) :
                            $color = $colors[ $row->level ] ?? '#333';
                        ?>
                        <tr>
                            <td style="font-size:12px;color:#666;"><?php echo esc_html( $row->created_at ); ?></td>
                            <td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;font-size:11px;text-transform:uppercase;"><?php echo esc_html( $row->level ); ?></span></td>
                            <td><code style="font-size:12px;"><?php echo esc_html( $row->event_type ); ?></code></td>
                            <td style="font-size:12px;"><code><?php echo esc_html( $row->customer_id ?: '--' ); ?></code></td>
                            <td style="font-size:12px;"><?php echo esc_html( $row->user_email ?: ( $row->user_id ? "#{$row->user_id}" : '--' ) ); ?></td>
                            <td style="font-size:12px;"><?php echo esc_html( $row->note ?: '--' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Reports tab
    // ---------------------------------------------------------------

    private function render_reports_tab(): void {
        global $wpdb;
        $total_members  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noah_members WHERE status = 'active'" );
        $total_access   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noah_program_access WHERE status = 'active'" );
        $recent_events  = Noah_DB::get_stripe_log( 20 );
        ?>
        <div style="max-width:900px;margin-top:24px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
                <div style="<?php echo $this->card_style( true ); ?>text-align:center;">
                    <p style="margin:0;font-size:13px;color:#666;"><?php esc_html_e( 'Active Members', 'noah-protocol' ); ?></p>
                    <p style="margin:4px 0 0;font-size:2em;font-weight:700;color:#7b5c3a;"><?php echo esc_html( $total_members ); ?></p>
                </div>
                <div style="<?php echo $this->card_style( true ); ?>text-align:center;">
                    <p style="margin:0;font-size:13px;color:#666;"><?php esc_html_e( 'Active Program Subscriptions', 'noah-protocol' ); ?></p>
                    <p style="margin:4px 0 0;font-size:2em;font-weight:700;color:#7b5c3a;"><?php echo esc_html( $total_access ); ?></p>
                </div>
            </div>
            <h3><?php esc_html_e( 'Recent Access Log', 'noah-protocol' ); ?></h3>
            <?php
            $access_log = $wpdb->get_results(
                "SELECT l.*, u.display_name, p.post_title AS program
                 FROM {$wpdb->prefix}noah_access_log l
                 LEFT JOIN {$wpdb->users}  u ON u.ID = l.user_id
                 LEFT JOIN {$wpdb->posts}  p ON p.ID = l.product_id
                 ORDER BY l.created_at DESC LIMIT 30"
            );
            ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Time', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'User', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Program', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Note', 'noah-protocol' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $access_log as $row ) : ?>
                    <tr>
                        <td style="font-size:12px;color:#666;"><?php echo esc_html( $row->created_at ); ?></td>
                        <td><?php echo esc_html( $row->display_name ?: "User #{$row->user_id}" ); ?></td>
                        <td><code><?php echo esc_html( $row->event ); ?></code></td>
                        <td><?php echo esc_html( $row->program ?: '--' ); ?></td>
                        <td style="font-size:12px;"><?php echo esc_html( $row->note ?: '--' ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Submenus
    // ---------------------------------------------------------------

    public function add_submenus(): void {
        add_submenu_page(
            'woocommerce',
            __( 'NOAH Members', 'noah-protocol' ),
            __( 'NOAH Members', 'noah-protocol' ),
            'manage_woocommerce',
            'noah-members',
            [ $this, 'render_members_page' ]
        );
    }

    public function render_members_page(): void {
        global $wpdb;
        $page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        $offset   = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}noah_members m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
             ORDER BY m.created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}noah_members" );
        $pages = ceil( $total / $per_page );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'NOAH Protocol Members', 'noah-protocol' ); ?></h1>
            <?php if ( isset( $_GET['cancelled'] ) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Membership cancelled.', 'noah-protocol' ); ?></p></div>
            <?php endif; ?>
            <p><?php printf( esc_html__( 'Total: %d members', 'noah-protocol' ), esc_html( $total ) ); ?></p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Name', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Member Since', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Stripe Sub ID', 'noah-protocol' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'noah-protocol' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No members yet.', 'noah-protocol' ); ?></td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_user_link( $row->user_id ) ); ?>"><?php echo esc_html( $row->display_name ); ?></a></td>
                        <td><?php echo esc_html( $row->user_email ); ?></td>
                        <td><span class="noah-status noah-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
                        <td><?php echo esc_html( $row->started_at ?: '--' ); ?></td>
                        <td><code><?php echo esc_html( $row->stripe_sub_id ?: '--' ); ?></code></td>
                        <td>
                            <?php if ( 'active' === $row->status ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm('<?php esc_attr_e( 'Cancel this membership?', 'noah-protocol' ); ?>')">
                                <input type="hidden" name="action"  value="noah_cancel_member">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr( $row->user_id ); ?>">
                                <?php wp_nonce_field( 'noah_cancel_member', 'noah_nonce' ); ?>
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Cancel', 'noah-protocol' ); ?></button>
                            </form>
                            <?php else : echo '--'; endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom"><div class="tablenav-pages">
                <?php echo paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ] ); ?>
            </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // Users list column
    // ---------------------------------------------------------------

    public function add_member_column( array $columns ): array {
        $columns['noah_member'] = __( 'NOAH Member', 'noah-protocol' );
        return $columns;
    }

    public function render_member_column( string $output, string $column_name, int $user_id ): string {
        if ( 'noah_member' !== $column_name ) {
            return $output;
        }
        return Noah_Membership::is_member( $user_id )
            ? '<span style="color:#00a32a;font-weight:600;">&#10004; ' . esc_html__( 'Member', 'noah-protocol' ) . '</span>'
            : '<span style="color:#888;">&#8212;</span>';
    }

    // ---------------------------------------------------------------
    // Action handlers
    // ---------------------------------------------------------------

    public function clear_stripe_log(): void {
        check_admin_referer( 'noah_clear_log' );
        if ( current_user_can( 'manage_woocommerce' ) ) {
            global $wpdb;
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}noah_stripe_log" );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'wc-settings', 'tab' => 'noah_protocol', 'noah_tab' => 'log' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_cancel_member(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'noah_cancel_member', 'noah_nonce' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'noah-protocol' ) );
        }
        $user_id = absint( $_POST['user_id'] ?? 0 );
        if ( $user_id ) {
            Noah_Membership::instance()->revoke( $user_id, 'admin_manual_cancel' );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=noah-members&cancelled=1' ) );
        exit;
    }

    public function handle_save_discount(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'noah_save_discount', 'noah_nonce' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'noah-protocol' ) );
        }
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $discount   = (float) ( $_POST['discount'] ?? 0 );
        if ( $product_id ) {
            $discount > 0
                ? Noah_Discount_Rules::set_product_discount( $product_id, $discount )
                : Noah_Discount_Rules::remove_product_discount( $product_id );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'wc-settings', 'tab' => 'noah_protocol', 'noah_tab' => 'reports' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function card_style( bool $complete ): string {
        $border = $complete ? '#b8d9b8' : '#e0e0e0';
        $bg     = $complete ? '#f2f9f2' : '#fafafa';
        return "background:{$bg};border:1px solid {$border};border-radius:8px;padding:16px 20px;margin-bottom:16px;";
    }

    private function step_icon( bool $complete ): string {
        return $complete ? '&#9989;' : '&#11036;';
    }
}
