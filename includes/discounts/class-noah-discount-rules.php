<?php
defined( 'ABSPATH' ) || exit;

class Noah_Discount_Rules {

    const OPTION_KEY = 'noah_discount_rules';

    private static ?Noah_Discount_Rules $instance = null;

    public static function instance(): Noah_Discount_Rules {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function get_rules(): array {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), [
            'categories' => [],
            'products'   => [],
        ] );
    }

    public static function save_rules( array $rules ): void {
        update_option( self::OPTION_KEY, $rules );
    }

    public static function set_category_discount( int $cat_id, float $percent ): void {
        $rules = self::get_rules();
        $rules['categories'][ $cat_id ] = $percent;
        self::save_rules( $rules );
    }

    public static function set_product_discount( int $product_id, float $percent ): void {
        $rules = self::get_rules();
        $rules['products'][ $product_id ] = $percent;
        self::save_rules( $rules );
    }

    public static function remove_category_discount( int $cat_id ): void {
        $rules = self::get_rules();
        unset( $rules['categories'][ $cat_id ] );
        self::save_rules( $rules );
    }

    public static function remove_product_discount( int $product_id ): void {
        $rules = self::get_rules();
        unset( $rules['products'][ $product_id ] );
        self::save_rules( $rules );
    }
}
