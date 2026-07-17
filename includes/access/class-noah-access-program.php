<?php
defined( 'ABSPATH' ) || exit;

class Noah_Access_Program {

    private static ?Noah_Access_Program $instance = null;

    public static function instance(): Noah_Access_Program {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post',      [ $this, 'save_meta_box' ], 10, 2 );
    }

    public function add_meta_box(): void {
        add_meta_box(
            'noah-program-access',
            __( 'NOAH Program Access', 'noah-protocol' ),
            [ $this, 'render_meta_box' ],
            'page',
            'side',
            'default'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        $linked = (int) get_post_meta( $post->ID, '_noah_program_product_id', true );
        $products = wc_get_products( [ 'type' => 'noah_subscription', 'limit' => -1, 'status' => 'publish' ] );
        wp_nonce_field( 'noah_program_access_nonce', 'noah_program_nonce' );
        ?>
        <p><label for="noah_program_product_id"><?php esc_html_e( 'Restrict to program:', 'noah-protocol' ); ?></label></p>
        <select name="noah_program_product_id" id="noah_program_product_id" style="width:100%">
            <option value="0"><?php esc_html_e( '(No restriction)', 'noah-protocol' ); ?></option>
            <?php foreach ( $products as $product ) : ?>
                <option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( $linked, $product->get_id() ); ?>>
                    <?php echo esc_html( $product->get_name() ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description" style="margin-top:8px;">
            <?php esc_html_e( 'Only users with an active subscription to this program can view this page.', 'noah-protocol' ); ?>
        </p>
        <?php
    }

    public function save_meta_box( int $post_id, WP_Post $post ): void {
        if (
            ! isset( $_POST['noah_program_nonce'] )
            || ! wp_verify_nonce( $_POST['noah_program_nonce'], 'noah_program_access_nonce' )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || ! current_user_can( 'edit_post', $post_id )
        ) {
            return;
        }
        $product_id = absint( $_POST['noah_program_product_id'] ?? 0 );
        if ( $product_id ) {
            update_post_meta( $post_id, '_noah_program_product_id', $product_id );
        } else {
            delete_post_meta( $post_id, '_noah_program_product_id' );
        }
    }
}
