<?php
defined( 'ABSPATH' ) || exit;

/**
 * Base class for the NOAH program/retreat and membership order emails.
 *
 * Each concrete subclass only needs to say which order-status transitions
 * trigger it, which orders it applies to, and what its default copy is —
 * this class handles the WC_Email plumbing (trigger wiring, template
 * rendering, admin-editable subject/heading/content settings) once.
 */
abstract class Noah_Email_Order_Type extends WC_Email {

    public function __construct() {
        $this->template_base  = NOAH_PATH . 'templates/';
        $this->template_html  = 'emails/order-notification.php';
        $this->template_plain = 'emails/plain/order-notification.php';

        add_action( 'woocommerce_order_status_changed', [ $this, 'maybe_trigger' ], 10, 4 );

        parent::__construct();
    }

    /**
     * Order statuses (the "to" side of the transition) that should fire this email.
     */
    abstract protected function trigger_statuses(): array;

    /**
     * Whether this email applies to the given order (e.g. contains a
     * program/retreat product, or a membership product).
     */
    abstract protected function matches_order( WC_Order $order ): bool;

    abstract protected function get_intro_text(): string;

    public function maybe_trigger( int $order_id, string $from_status, string $to_status, $order = null ): void {
        if ( ! in_array( $to_status, $this->trigger_statuses(), true ) ) {
            return;
        }
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order instanceof WC_Order || ! $this->matches_order( $order ) ) {
            return;
        }
        if ( $order->get_meta( $this->sent_flag_key(), true ) ) {
            return; // Already sent for this order — a later status change shouldn't re-send it.
        }
        $this->trigger( $order->get_id(), $order );
    }

    public function trigger( $order_id, $order = false ): void {
        $this->setup_locale();

        if ( $order_id && ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( $order instanceof WC_Order ) {
            $this->object    = $order;
            $this->recipient = $this->customer_email
                ? $order->get_billing_email()
                : $this->get_option( 'recipient', get_option( 'admin_email' ) );

            $this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
            $this->placeholders['{order_number}'] = $order->get_order_number();
        }

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
            if ( $sent && $order instanceof WC_Order ) {
                $order->update_meta_data( $this->sent_flag_key(), current_time( 'mysql' ) );
                $order->save();
            }
        }

        $this->restore_locale();
    }

    protected function sent_flag_key(): string {
        return '_noah_email_sent_' . $this->id;
    }

    public function get_content_html(): string {
        return wc_get_template_html( $this->template_html, [
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => ! $this->customer_email,
            'plain_text'         => false,
            'email'              => $this,
            'noah_intro'         => $this->get_intro_text(),
        ], '', $this->template_base );
    }

    public function get_content_plain(): string {
        return wc_get_template_html( $this->template_plain, [
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => ! $this->customer_email,
            'plain_text'         => true,
            'email'              => $this,
            'noah_intro'         => $this->get_intro_text(),
        ], '', $this->template_base );
    }

    public function get_default_additional_content(): string {
        return '';
    }

    public function init_form_fields(): void {
        $placeholders = '<code>{site_title}, {order_date}, {order_number}</code>';

        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'noah-protocol' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email notification', 'noah-protocol' ),
                'default' => 'yes',
            ],
            'subject' => [
                'title'       => __( 'Subject', 'noah-protocol' ),
                'type'        => 'text',
                'desc_tip'    => true,
                /* translators: %s: available placeholders */
                'description' => sprintf( __( 'Available placeholders: %s', 'noah-protocol' ), $placeholders ),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
            ],
            'heading' => [
                'title'       => __( 'Email heading', 'noah-protocol' ),
                'type'        => 'text',
                'desc_tip'    => true,
                /* translators: %s: available placeholders */
                'description' => sprintf( __( 'Available placeholders: %s', 'noah-protocol' ), $placeholders ),
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
            ],
            'additional_content' => [
                'title'       => __( 'Additional content', 'noah-protocol' ),
                'description' => __( 'Text appended to the bottom of this email, before the footer.', 'noah-protocol' ),
                'css'         => 'width:400px; height: 75px;',
                'placeholder' => __( 'N/A', 'noah-protocol' ),
                'type'        => 'textarea',
                'default'     => $this->get_default_additional_content(),
                'desc_tip'    => true,
            ],
            'email_type' => [
                'title'       => __( 'Email type', 'noah-protocol' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'noah-protocol' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ],
        ];
    }
}
