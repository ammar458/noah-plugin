<?php
defined( 'ABSPATH' ) || exit;

class Noah_Access_Manager {

    private static ?Noah_Access_Manager $instance = null;

    public static function instance(): Noah_Access_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'template_redirect', [ $this, 'gate_program_pages' ] );
    }

    public static function can_access_program( int $user_id, int $product_id ): bool {
        if ( ! $user_id ) {
            return false;
        }
        if ( user_can( $user_id, 'manage_woocommerce' ) ) {
            return true;
        }
        return Noah_DB::has_program_access( $user_id, $product_id );
    }

    public function gate_program_pages(): void {
        if ( ! is_singular() ) {
            return;
        }
        $product_id = (int) get_post_meta( get_the_ID(), '_noah_program_product_id', true );
        if ( ! $product_id ) {
            return;
        }
        $user_id = get_current_user_id();
        if ( ! self::can_access_program( $user_id, $product_id ) ) {
            wp_redirect( $user_id ? wc_get_page_permalink( 'shop' ) : wp_login_url( get_permalink() ) );
            exit;
        }
    }
}
