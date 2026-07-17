<?php
defined( 'ABSPATH' ) || exit;

class Noah_Activation {

    public static function run(): void {
        require_once NOAH_PATH . 'database/class-noah-db.php';
        Noah_DB::create_tables();

        // Create noah_member role inheriting subscriber capabilities
        if ( ! get_role( 'noah_member' ) ) {
            $subscriber = get_role( 'subscriber' );
            $caps = $subscriber
                ? array_merge( $subscriber->capabilities, [ 'noah_member' => true ] )
                : [ 'read' => true, 'noah_member' => true ];
            add_role( 'noah_member', __( 'NOAH Member', 'noah-protocol' ), $caps );
        }

        // Daily cleanup cron
        if ( ! wp_next_scheduled( 'noah_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'noah_daily_cleanup' );
        }

        flush_rewrite_rules();
    }
}
