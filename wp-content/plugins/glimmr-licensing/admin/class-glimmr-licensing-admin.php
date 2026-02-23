<?php
/**
 * Admin functionality for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Admin
 *
 * Handles admin pages: dashboard, license list, license detail, settings.
 */
class Glimmr_Licensing_Admin {

    /**
     * Option key for development keys.
     *
     * @var string
     */
    const DEV_KEYS_OPTION = 'glimmr_licensing_dev_keys';

    /**
     * Menu slug.
     *
     * @var string
     */
    const MENU_SLUG = 'glimmr-licensing';

    /**
     * Capability required for access.
     *
     * @var string
     */
    const CAPABILITY = 'manage_woocommerce';

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_wcs_notice' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_glimmr_licensing_create_license', array( $this, 'ajax_create_license' ) );
        add_action( 'wp_ajax_glimmr_licensing_update_status', array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_glimmr_licensing_delete_license', array( $this, 'ajax_delete_license' ) );
        add_action( 'wp_ajax_glimmr_licensing_deactivate_site', array( $this, 'ajax_deactivate_site' ) );
        add_action( 'wp_ajax_glimmr_licensing_update_license', array( $this, 'ajax_update_license' ) );
        add_action( 'wp_ajax_glimmr_licensing_bulk_action', array( $this, 'ajax_bulk_action' ) );
        add_action( 'wp_ajax_glimmr_licensing_add_dev_key', array( $this, 'ajax_add_dev_key' ) );
        add_action( 'wp_ajax_glimmr_licensing_delete_dev_key', array( $this, 'ajax_delete_dev_key' ) );
    }

    /**
     * Add admin menu pages.
     *
     * @return void
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Glimmr Licensing', 'glimmr-licensing' ),
            __( 'Glimmr Licensing', 'glimmr-licensing' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_dashboard_page' ),
            'dashicons-admin-network',
            56
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'glimmr-licensing' ),
            __( 'Dashboard', 'glimmr-licensing' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_dashboard_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Licenses', 'glimmr-licensing' ),
            __( 'Licenses', 'glimmr-licensing' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-licenses',
            array( $this, 'render_licenses_page' )
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings', 'glimmr-licensing' ),
            __( 'Settings', 'glimmr-licensing' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin styles.
     *
     * @param string $hook Current page hook.
     * @return void
     */
    public function enqueue_styles( $hook ) {
        if ( false === strpos( $hook, self::MENU_SLUG ) ) {
            return;
        }

        wp_enqueue_style(
            'glimmr-licensing-admin',
            GLIMMR_LICENSING_PLUGIN_URL . 'admin/css/glimmr-licensing-admin.css',
            array(),
            GLIMMR_LICENSING_VERSION
        );
    }

    /**
     * Render dashboard page.
     *
     * @return void
     */
    public function render_dashboard_page() {
        $manager = new Glimmr_Licensing_Manager();
        $stats   = $manager->get_stats();

        include GLIMMR_LICENSING_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }

    /**
     * Render licenses list page (or detail if ID is in URL).
     *
     * @return void
     */
    public function render_licenses_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['license_id'] ) ) {
            $this->render_license_detail_page();
            return;
        }

        $manager = new Glimmr_Licensing_Manager();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $args = array(
            'status'   => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
            'plan'     => isset( $_GET['plan'] ) ? sanitize_text_field( wp_unslash( $_GET['plan'] ) ) : '',
            'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'per_page' => 20,
            'page'     => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
        );
        // phpcs:enable

        $result = $manager->get_licenses( $args );

        $licenses    = $result['items'];
        $total       = $result['total'];
        $total_pages = ceil( $total / $args['per_page'] );

        include GLIMMR_LICENSING_PLUGIN_DIR . 'admin/partials/licenses.php';
    }

    /**
     * Render license detail page.
     *
     * @return void
     */
    private function render_license_detail_page() {
        $manager = new Glimmr_Licensing_Manager();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $license_id = absint( $_GET['license_id'] );
        $license    = $manager->get_license( $license_id );

        if ( ! $license ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>';
            esc_html_e( 'License not found.', 'glimmr-licensing' );
            echo '</p></div></div>';
            return;
        }

        $activations = $manager->get_activations( $license_id );
        $logs        = $manager->get_logs( $license_id );

        include GLIMMR_LICENSING_PLUGIN_DIR . 'admin/partials/license-detail.php';
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        // Handle form submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['glimmr_licensing_save_settings'] ) ) {
            check_admin_referer( 'glimmr_licensing_settings' );
            if ( ! current_user_can( self::CAPABILITY ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'glimmr-licensing' ) );
            }

            $settings = array(
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'rate_limit_per_minute' => absint( $_POST['rate_limit_per_minute'] ?? 60 ),
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'auto_email_license'    => isset( $_POST['auto_email_license'] ),
            );

            update_option( 'glimmr_licensing_settings', $settings );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'glimmr-licensing' ) . '</p></div>';
        }

        $settings = get_option( 'glimmr_licensing_settings', array(
            'rate_limit_per_minute' => 60,
            'auto_email_license'    => true,
        ) );

        include GLIMMR_LICENSING_PLUGIN_DIR . 'admin/partials/settings.php';
    }

    /**
     * AJAX: Create a new license.
     *
     * @return void
     */
    public function ajax_create_license() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $manager = new Glimmr_Licensing_Manager();
        $result  = $manager->create_license( array(
            'customer_email' => sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) ),
            'customer_name'  => sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) ),
            'plan'           => sanitize_text_field( wp_unslash( $_POST['plan'] ?? 'plan_1' ) ),
            'expiry_date'    => ! empty( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : null,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Update license status.
     *
     * @return void
     */
    public function ajax_update_status() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $license_id = absint( $_POST['license_id'] ?? 0 );
        $status     = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

        if ( 0 === $license_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid license ID.', 'glimmr-licensing' ) ) );
        }

        $manager = new Glimmr_Licensing_Manager();
        $updated = $manager->update_license_status( $license_id, $status );

        if ( $updated ) {
            wp_send_json_success( array( 'message' => __( 'Status updated.', 'glimmr-licensing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to update status.', 'glimmr-licensing' ) ) );
        }
    }

    /**
     * AJAX: Delete a license.
     *
     * @return void
     */
    public function ajax_delete_license() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $license_id = absint( $_POST['license_id'] ?? 0 );

        if ( 0 === $license_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid license ID.', 'glimmr-licensing' ) ) );
        }

        $manager = new Glimmr_Licensing_Manager();
        $deleted = $manager->delete_license( $license_id );

        if ( $deleted ) {
            wp_send_json_success( array( 'message' => __( 'License deleted.', 'glimmr-licensing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete license.', 'glimmr-licensing' ) ) );
        }
    }

    /**
     * AJAX: Deactivate a site activation.
     *
     * @return void
     */
    public function ajax_deactivate_site() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $activation_id = absint( $_POST['activation_id'] ?? 0 );

        if ( 0 === $activation_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid activation ID.', 'glimmr-licensing' ) ) );
        }

        $manager     = new Glimmr_Licensing_Manager();
        $deactivated = $manager->deactivate_activation( $activation_id );

        if ( $deactivated ) {
            wp_send_json_success( array( 'message' => __( 'Site deactivated.', 'glimmr-licensing' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to deactivate site.', 'glimmr-licensing' ) ) );
        }
    }

    /**
     * AJAX: Update license details.
     *
     * @return void
     */
    public function ajax_update_license() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $license_id = absint( $_POST['license_id'] ?? 0 );

        if ( 0 === $license_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid license ID.', 'glimmr-licensing' ) ) );
        }

        $args = array();

        if ( isset( $_POST['customer_name'] ) ) {
            $args['customer_name'] = sanitize_text_field( wp_unslash( $_POST['customer_name'] ) );
        }
        if ( isset( $_POST['customer_email'] ) ) {
            $args['customer_email'] = sanitize_email( wp_unslash( $_POST['customer_email'] ) );
        }
        if ( isset( $_POST['plan'] ) ) {
            $args['plan'] = sanitize_text_field( wp_unslash( $_POST['plan'] ) );
        }
        if ( isset( $_POST['site_limit'] ) ) {
            $args['site_limit'] = absint( $_POST['site_limit'] );
        }
        if ( array_key_exists( 'expiry_date', $_POST ) ) {
            $args['expiry_date'] = sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) );
        }

        $manager = new Glimmr_Licensing_Manager();
        $result  = $manager->update_license( $license_id, $args );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'License updated.', 'glimmr-licensing' ) ) );
    }

    /**
     * AJAX: Bulk action on licenses (delete or status change).
     *
     * @return void
     */
    public function ajax_bulk_action() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ids = isset( $_POST['license_ids'] ) ? array_map( 'absint', (array) $_POST['license_ids'] ) : array();
        $ids = array_filter( $ids ); // Remove zeroes.

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No licenses selected.', 'glimmr-licensing' ) ) );
        }

        $bulk_action = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ?? '' ) );
        $manager     = new Glimmr_Licensing_Manager();
        $count       = 0;

        if ( 'delete' === $bulk_action ) {
            foreach ( $ids as $id ) {
                if ( $manager->delete_license( $id ) ) {
                    $count++;
                }
            }
            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d: number of deleted licenses */
                    __( '%d license(s) deleted.', 'glimmr-licensing' ),
                    $count
                ),
            ) );
        } elseif ( 'status' === $bulk_action ) {
            $status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
            foreach ( $ids as $id ) {
                if ( $manager->update_license_status( $id, $status ) ) {
                    $count++;
                }
            }
            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d: number of updated licenses */
                    __( '%d license(s) updated.', 'glimmr-licensing' ),
                    $count
                ),
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Invalid bulk action.', 'glimmr-licensing' ) ) );
        }
    }

    /**
     * Show admin notice if WooCommerce Subscriptions is missing and product seeding was skipped.
     *
     * @return void
     */
    public function maybe_show_wcs_notice() {
        if ( ! get_transient( 'glimmr_licensing_wcs_missing_notice' ) ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        esc_html_e( 'Glimmr Licensing: WooCommerce Subscriptions is required to create subscription products. Please install and activate it, then re-save settings.', 'glimmr-licensing' );
        echo '</p></div>';

        delete_transient( 'glimmr_licensing_wcs_missing_notice' );
    }

    /**
     * Mask a license key for display.
     *
     * @param string $key License key.
     * @return string Masked key.
     */
    public static function mask_key( $key ) {
        $parts = explode( '-', $key );
        if ( count( $parts ) < 5 ) {
            return '****-****-****-****';
        }
        return $parts[0] . '-****-****-****-' . $parts[4];
    }

    /**
     * Get plan label.
     *
     * @param string $plan Plan identifier.
     * @return string Label.
     */
    public static function plan_label( $plan ) {
        $labels = array(
            'plan_1'         => __( '1 Site', 'glimmr-licensing' ),
            'plan_10'        => __( '10 Sites', 'glimmr-licensing' ),
            'plan_100'       => __( '100 Sites', 'glimmr-licensing' ),
            'plan_unlimited' => __( 'Unlimited Sites', 'glimmr-licensing' ),
        );
        return $labels[ $plan ] ?? $plan;
    }

    /**
     * Get status badge HTML.
     *
     * @param string $status Status string.
     * @return string HTML badge.
     */
    public static function status_badge( $status ) {
        $classes = array(
            'active'    => 'glimmr-badge-success',
            'expired'   => 'glimmr-badge-warning',
            'cancelled' => 'glimmr-badge-danger',
            'suspended' => 'glimmr-badge-danger',
        );
        $class = $classes[ $status ] ?? 'glimmr-badge-default';
        return '<span class="glimmr-badge ' . esc_attr( $class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
    }

    /**
     * Hash a dev key for storage.
     *
     * @param string $key Raw license key.
     * @return string HMAC-SHA256 hash of the key.
     */
    public static function hash_dev_key( $key ) {
        return hash_hmac( 'sha256', strtoupper( $key ), wp_salt( 'auth' ) );
    }

    /**
     * Get all development keys.
     *
     * @return array Associative array keyed by hashed key, value is array with 'label' and 'created_at'.
     */
    public static function get_dev_keys() {
        $dev_keys = get_option( self::DEV_KEYS_OPTION, array() );

        // One-time migration: re-hash any plaintext keys stored before hashing was implemented.
        $needs_migration = false;
        foreach ( array_keys( $dev_keys ) as $stored_key ) {
            // A SHA-256 HMAC hex digest is exactly 64 hex characters.
            if ( 64 !== strlen( $stored_key ) || ! ctype_xdigit( $stored_key ) ) {
                $needs_migration = true;
                break;
            }
        }

        if ( $needs_migration ) {
            $migrated = array();
            foreach ( $dev_keys as $stored_key => $meta ) {
                $hashed = self::hash_dev_key( $stored_key );
                $migrated[ $hashed ] = $meta;
            }
            update_option( self::DEV_KEYS_OPTION, $migrated );
            $dev_keys = $migrated;
        }

        return $dev_keys;
    }

    /**
     * Check if a license key is a development key.
     *
     * @param string $key License key.
     * @return bool True if this is a dev key.
     */
    public static function is_dev_key( $key ) {
        $dev_keys = self::get_dev_keys();
        $hashed   = self::hash_dev_key( $key );
        return isset( $dev_keys[ $hashed ] );
    }

    /**
     * AJAX: Add a development key.
     *
     * @return void
     */
    public function ajax_add_dev_key() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        if ( empty( $label ) ) {
            wp_send_json_error( array( 'message' => __( 'Label is required.', 'glimmr-licensing' ) ) );
        }

        $key      = Glimmr_Licensing_Key_Generator::generate();
        $hashed   = self::hash_dev_key( $key );
        $dev_keys = self::get_dev_keys();

        $dev_keys[ $hashed ] = array(
            'label'      => $label,
            'created_at' => current_time( 'mysql' ),
        );

        update_option( self::DEV_KEYS_OPTION, $dev_keys );

        wp_send_json_success( array(
            'message' => __( 'Development key created.', 'glimmr-licensing' ),
            'key'     => $key,
            'label'   => $label,
        ) );
    }

    /**
     * AJAX: Delete a development key.
     *
     * @return void
     */
    public function ajax_delete_dev_key() {
        check_ajax_referer( 'glimmr_licensing_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-licensing' ) ) );
        }

        $key = strtoupper( sanitize_text_field( wp_unslash( $_POST['dev_key'] ?? '' ) ) );
        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => __( 'Key is required.', 'glimmr-licensing' ) ) );
        }

        $hashed   = self::hash_dev_key( $key );
        $dev_keys = self::get_dev_keys();

        if ( ! isset( $dev_keys[ $hashed ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Key not found.', 'glimmr-licensing' ) ) );
        }

        unset( $dev_keys[ $hashed ] );
        update_option( self::DEV_KEYS_OPTION, $dev_keys );

        wp_send_json_success( array( 'message' => __( 'Development key deleted.', 'glimmr-licensing' ) ) );
    }
}
