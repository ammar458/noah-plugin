<?php
defined( 'ABSPATH' ) || exit;

class Noah_Uninstall {

    public static function run(): void {
        if ( ! get_option( 'noah_delete_data_on_uninstall', false ) ) {
            return;
        }

        require_once NOAH_PATH . 'database/class-noah-db.php';
        Noah_DB::drop_tables();

        remove_role( 'noah_member' );

        $options = [
            'noah_db_version',
            'noah_membership_product_id',
            'noah_discount_percentage',
            'noah_member_badge_text',
            'noah_nonmember_teaser_text',
            'noah_stripe_webhook_secret',
            'noah_delete_data_on_uninstall',
        ];
        foreach ( $options as $key ) {
            delete_option( $key );
        }
    }
}
