<?php
defined( 'ABSPATH' ) || exit;

class Noah_Deactivation {

    public static function run(): void {
        wp_clear_scheduled_hook( 'noah_daily_cleanup' );
        wp_clear_scheduled_hook( 'noah_revoke_expired_access' );
        flush_rewrite_rules();
    }
}
