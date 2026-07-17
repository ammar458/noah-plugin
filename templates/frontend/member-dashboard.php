<?php
defined( 'ABSPATH' ) || exit;

$user_id   = get_current_user_id();
$member    = Noah_DB::get_member( $user_id );
$is_active = Noah_Membership::is_member( $user_id );
?>
<div class="noah-member-dashboard">
    <h3><?php esc_html_e( 'My NOAH Membership', 'noah-protocol' ); ?></h3>

    <?php if ( $member && $is_active ) : ?>

        <p>
            <strong><?php esc_html_e( 'Status:', 'noah-protocol' ); ?></strong>
            <span class="noah-badge noah-badge--active"><?php esc_html_e( 'Active', 'noah-protocol' ); ?></span>
        </p>
        <p>
            <strong><?php esc_html_e( 'Member Since:', 'noah-protocol' ); ?></strong>
            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $member->started_at ) ) ); ?>
        </p>

        <h4><?php esc_html_e( 'Your Member Benefits', 'noah-protocol' ); ?></h4>
        <ul class="noah-benefits-list">
            <li><?php esc_html_e( 'Member pricing on all programs', 'noah-protocol' ); ?></li>
            <li><?php esc_html_e( 'Member pricing on retreats and workshops', 'noah-protocol' ); ?></li>
            <li><?php esc_html_e( 'Member pricing on consultations', 'noah-protocol' ); ?></li>
            <li><?php esc_html_e( 'Priority booking', 'noah-protocol' ); ?></li>
            <li><?php esc_html_e( 'Members-only content', 'noah-protocol' ); ?></li>
        </ul>

        <?php
        global $wpdb;
        $accesses = $wpdb->get_results( $wpdb->prepare(
            "SELECT pa.*, p.post_title AS program_name
             FROM {$wpdb->prefix}noah_program_access pa
             LEFT JOIN {$wpdb->posts} p ON p.ID = pa.product_id
             WHERE pa.user_id = %d ORDER BY pa.started_at DESC",
            $user_id
        ) );
        if ( ! empty( $accesses ) ) :
        ?>
        <h4><?php esc_html_e( 'My Programs', 'noah-protocol' ); ?></h4>
        <table class="noah-program-table">
            <thead><tr>
                <th><?php esc_html_e( 'Program', 'noah-protocol' ); ?></th>
                <th><?php esc_html_e( 'Status', 'noah-protocol' ); ?></th>
                <th><?php esc_html_e( 'Progress', 'noah-protocol' ); ?></th>
                <th><?php esc_html_e( 'Access Until', 'noah-protocol' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $accesses as $access ) : ?>
                <tr>
                    <td><?php echo esc_html( $access->program_name ); ?></td>
                    <td><span class="noah-badge noah-badge--<?php echo esc_attr( $access->status ); ?>"><?php echo esc_html( ucfirst( $access->status ) ); ?></span></td>
                    <td><?php printf( esc_html__( '%1$d / %2$d payments', 'noah-protocol' ), esc_html( $access->cycles_paid ), esc_html( $access->billing_cycles ) ); ?></td>
                    <td><?php echo $access->expires_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $access->expires_at ) ) ) : '--'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php elseif ( $member ) : ?>

        <p><strong><?php esc_html_e( 'Status:', 'noah-protocol' ); ?></strong>
            <span class="noah-badge noah-badge--<?php echo esc_attr( $member->status ); ?>"><?php echo esc_html( ucfirst( $member->status ) ); ?></span>
        </p>
        <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button">
            <?php esc_html_e( 'Renew Membership', 'noah-protocol' ); ?>
        </a>

    <?php else : ?>

        <p><?php esc_html_e( 'You do not have an active NOAH Membership.', 'noah-protocol' ); ?></p>
        <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button">
            <?php esc_html_e( 'Become a Member', 'noah-protocol' ); ?>
        </a>

    <?php endif; ?>
</div>
