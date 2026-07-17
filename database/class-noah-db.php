<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_DB
 *
 * Tables:
 *   {prefix}noah_members          - one row per user, membership status + expiry
 *   {prefix}noah_program_access   - per-user per-product access + cycle tracking
 *   {prefix}noah_access_log       - full audit trail for all membership/access events
 *   {prefix}noah_stripe_log       - Stripe webhook event log (replaces WP option approach)
 */
class Noah_DB {

    const DB_VERSION_OPTION = 'noah_db_version';
    const DB_VERSION        = '2.0.0';

    public static function maybe_create_tables(): void {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }
        self::create_tables();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}noah_members (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            order_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            stripe_sub_id   VARCHAR(255)        NOT NULL DEFAULT '',
            status          VARCHAR(20)         NOT NULL DEFAULT 'inactive',
            started_at      DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
            expires_at      DATETIME                     DEFAULT NULL,
            cancelled_at    DATETIME                     DEFAULT NULL,
            created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id),
            KEY status (status),
            KEY stripe_sub_id (stripe_sub_id(50))
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}noah_program_access (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            product_id      BIGINT(20) UNSIGNED NOT NULL,
            order_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            stripe_sub_id   VARCHAR(255)        NOT NULL DEFAULT '',
            status          VARCHAR(20)         NOT NULL DEFAULT 'inactive',
            billing_cycles  TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            cycles_paid     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            started_at      DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
            expires_at      DATETIME                     DEFAULT NULL,
            revoked_at      DATETIME                     DEFAULT NULL,
            created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_product (user_id, product_id),
            KEY status (status),
            KEY stripe_sub_id (stripe_sub_id(50))
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}noah_access_log (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20) UNSIGNED NOT NULL,
            product_id  BIGINT(20) UNSIGNED          DEFAULT NULL,
            event       VARCHAR(80)         NOT NULL,
            note        TEXT                         DEFAULT NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id    (user_id),
            KEY product_id (product_id),
            KEY event      (event)
        ) $charset;" );

        // Stripe event log as a proper table (not a WP option)
        dbDelta( "CREATE TABLE {$wpdb->prefix}noah_stripe_log (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id    VARCHAR(255)        NOT NULL DEFAULT '',
            event_type  VARCHAR(100)        NOT NULL,
            level       VARCHAR(10)         NOT NULL DEFAULT 'info',
            customer_id VARCHAR(255)        NOT NULL DEFAULT '',
            user_id     BIGINT(20) UNSIGNED          DEFAULT NULL,
            note        TEXT                         DEFAULT NULL,
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type  (event_type),
            KEY customer_id (customer_id(50)),
            KEY created_at  (created_at)
        ) $charset;" );
    }

    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noah_stripe_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noah_access_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noah_program_access" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}noah_members" );
        delete_option( self::DB_VERSION_OPTION );
    }

    // ---------------------------------------------------------------
    // Members
    // ---------------------------------------------------------------

    public static function get_member( int $user_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}noah_members WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
    }

    public static function upsert_member( int $user_id, array $data ): void {
        global $wpdb;
        if ( self::get_member( $user_id ) ) {
            $wpdb->update( "{$wpdb->prefix}noah_members", $data, [ 'user_id' => $user_id ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}noah_members", array_merge( [ 'user_id' => $user_id ], $data ) );
        }
    }

    public static function is_active_member( int $user_id ): bool {
        $m = self::get_member( $user_id );
        if ( ! $m || $m->status !== 'active' ) {
            return false;
        }
        if ( $m->expires_at && strtotime( $m->expires_at ) < time() ) {
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Program access
    // ---------------------------------------------------------------

    public static function get_program_access( int $user_id, int $product_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}noah_program_access
             WHERE user_id = %d AND product_id = %d LIMIT 1",
            $user_id, $product_id
        ) );
    }

    public static function upsert_program_access( int $user_id, int $product_id, array $data ): void {
        global $wpdb;
        if ( self::get_program_access( $user_id, $product_id ) ) {
            $wpdb->update(
                "{$wpdb->prefix}noah_program_access",
                $data,
                [ 'user_id' => $user_id, 'product_id' => $product_id ]
            );
        } else {
            $wpdb->insert(
                "{$wpdb->prefix}noah_program_access",
                array_merge( [ 'user_id' => $user_id, 'product_id' => $product_id ], $data )
            );
        }
    }

    public static function has_program_access( int $user_id, int $product_id ): bool {
        $a = self::get_program_access( $user_id, $product_id );
        if ( ! $a || $a->status !== 'active' ) {
            return false;
        }
        if ( $a->expires_at && strtotime( $a->expires_at ) < time() ) {
            return false;
        }
        return true;
    }

    // ---------------------------------------------------------------
    // Logs
    // ---------------------------------------------------------------

    public static function log_event( int $user_id, ?int $product_id, string $event, string $note = '' ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}noah_access_log", [
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'event'      => sanitize_key( $event ),
            'note'       => sanitize_text_field( $note ),
        ] );
    }

    public static function log_stripe_event(
        string $event_type,
        string $level = 'info',
        string $event_id = '',
        string $customer_id = '',
        ?int $user_id = null,
        string $note = ''
    ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}noah_stripe_log", [
            'event_id'    => $event_id,
            'event_type'  => $event_type,
            'level'       => $level,
            'customer_id' => $customer_id,
            'user_id'     => $user_id,
            'note'        => sanitize_text_field( $note ),
        ] );
        // Keep table trimmed to last 500 rows
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}noah_stripe_log
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM {$wpdb->prefix}noah_stripe_log ORDER BY id DESC LIMIT 500
                 ) tmp
             )"
        );
    }

    public static function get_stripe_log( int $limit = 100 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, u.user_email
             FROM {$wpdb->prefix}noah_stripe_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             ORDER BY l.created_at DESC LIMIT %d",
            $limit
        ) );
    }

    // ---------------------------------------------------------------
    // Subscription ID lookups
    // ---------------------------------------------------------------

    public static function find_user_by_stripe_sub( string $stripe_sub_id ): array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, product_id FROM {$wpdb->prefix}noah_program_access
             WHERE stripe_sub_id = %s LIMIT 1",
            $stripe_sub_id
        ) );
        if ( $row ) {
            return [ (int) $row->user_id, (int) $row->product_id ];
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}noah_members
             WHERE stripe_sub_id = %s LIMIT 1",
            $stripe_sub_id
        ) );
        if ( $row ) {
            return [ (int) $row->user_id, null ];
        }

        return [ 0, null ];
    }
}
