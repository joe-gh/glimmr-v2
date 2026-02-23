<?php
/**
 * License client for Glimmr AI.
 *
 * Handles license activation, deactivation, and validation against the
 * Glimmr licensing server at glimmr.us.
 *
 * @package Glimmr_AI
 * @since   1.9.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_License
 *
 * Singleton class managing all license operations for the Glimmr AI plugin.
 * Communicates with the licensing server REST API to activate, deactivate,
 * and periodically validate licenses. Implements a 24-hour validation cache
 * with a 7-day grace period for network errors.
 */
class Glimmr_AI_License {

    /**
     * Option key for the stored license key.
     *
     * @var string
     */
    const OPT_LICENSE_KEY = 'glimmr_ai_license_key';

    /**
     * Option key for cached license data.
     *
     * @var string
     */
    const OPT_LICENSE_DATA = 'glimmr_ai_license_data';

    /**
     * Default licensing server URL.
     *
     * @var string
     */
    const SERVER_URL = 'https://glimmr.us/wp-json/glimmr-licensing/v1/';

    /**
     * Cache duration in seconds (24 hours).
     *
     * @var int
     */
    const CACHE_DURATION = 86400;

    /**
     * Grace period in seconds (7 days).
     *
     * @var int
     */
    const GRACE_PERIOD = 604800;

    /**
     * HTTP request timeout in seconds.
     *
     * @var int
     */
    const REQUEST_TIMEOUT = 15;

    /**
     * Development license key that bypasses server validation.
     *
     * This key works offline and grants unlimited access for development.
     * Format matches standard license key format for UI consistency.
     *
     * @var string
     */
    const DEV_LICENSE_KEY = 'GLMR-DEV0-UNLM-ITD0-JPDG';

    /**
     * Singleton instance.
     *
     * @var Glimmr_AI_License|null
     */
    private static $instance = null;

    /**
     * Cached license data for the current request.
     *
     * @var array|null
     */
    private $cached_data = null;

    /**
     * Get the singleton instance.
     *
     * @return Glimmr_AI_License
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton.
     */
    private function __construct() {}

    /**
     * Check if the plugin is licensed (quick local check with cache).
     *
     * Uses cached validation data. If the cache has expired (>24h),
     * triggers a remote validation. If the server is unreachable,
     * enters a 7-day grace period. If the server explicitly says invalid,
     * disables immediately.
     *
     * @return bool True if licensed and valid.
     */
    public function is_licensed() {
        $license_key = get_option( self::OPT_LICENSE_KEY, '' );

        if ( empty( $license_key ) ) {
            return false;
        }

        // Dev key bypass - works offline with unlimited access.
        if ( strtoupper( $license_key ) === self::DEV_LICENSE_KEY ) {
            return true;
        }

        $data = $this->get_license_data();

        if ( empty( $data ) || empty( $data['activation_id'] ) ) {
            return false;
        }

        // Check if status is explicitly invalid.
        if ( ! empty( $data['status'] ) && 'active' !== $data['status'] ) {
            return false;
        }

        // If HMAC verification fails, force remote validation on next check.
        if ( ! $this->verify_hmac( $data ) ) {
            $result = $this->validate( true );
            if ( true === $result ) {
                return true;
            }
            return $this->is_in_grace_period();
        }

        // Check if validation cache is still fresh.
        $last_validated = ! empty( $data['last_validated'] ) ? (int) $data['last_validated'] : 0;
        $age            = time() - $last_validated;

        if ( $age < self::CACHE_DURATION ) {
            // Cache is fresh — license is valid.
            return true;
        }

        // Cache expired — only attempt remote validation on admin or AJAX/REST requests.
        // This prevents synchronous HTTP calls on frontend page loads.
        if ( ! is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) {
            // On the frontend, trust the cached status during the grace window.
            if ( $this->is_in_grace_period() || $age < ( self::CACHE_DURATION + self::GRACE_PERIOD ) ) {
                return true;
            }
            return false;
        }

        // Admin/AJAX/REST — attempt remote validation.
        $result = $this->validate();

        if ( true === $result ) {
            return true;
        }

        // Validation failed — check if we're in grace period.
        if ( $this->is_in_grace_period() ) {
            return true;
        }

        return false;
    }

    /**
     * Activate a license key on this site.
     *
     * @param string $license_key The license key to activate.
     * @return array{success: bool, message: string, data?: array} Result array.
     */
    public function activate( $license_key ) {
        $license_key = strtoupper( trim( $license_key ) );

        if ( empty( $license_key ) ) {
            return array(
                'success' => false,
                'message' => __( 'Please enter a license key.', 'glimmr-ai' ),
            );
        }

        // Dev key bypass - activate locally without server contact.
        if ( $license_key === self::DEV_LICENSE_KEY ) {
            update_option( self::OPT_LICENSE_KEY, $license_key );
            $license_data = array(
                'activation_id'    => 'dev-' . wp_generate_uuid4(),
                'plan'             => 'plan_unlimited',
                'site_limit'       => 999,
                'activations_used' => 1,
                'expiry'           => '',
                'last_validated'   => time(),
                'status'           => 'active',
            );
            $this->store_license_data( $license_data );
            $this->clear_grace_period();

            return array(
                'success' => true,
                'message' => __( 'Development license activated.', 'glimmr-ai' ),
                'data'    => $license_data,
            );
        }

        $response = $this->remote_post( 'activate', array(
            'license_key' => $license_key,
            'site_url'    => home_url(),
            'site_name'   => get_bloginfo( 'name' ),
            'environment' => $this->get_environment(),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => __( 'Could not connect to the licensing server. Please try again.', 'glimmr-ai' ),
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid response from licensing server.', 'glimmr-ai' ),
            );
        }

        if ( empty( $body['success'] ) ) {
            $message = ! empty( $body['message'] )
                ? sanitize_text_field( $body['message'] )
                : __( 'License activation failed.', 'glimmr-ai' );
            return array(
                'success' => false,
                'message' => $message,
            );
        }

        // Activation successful — store license data.
        update_option( self::OPT_LICENSE_KEY, $license_key );
        $license_data = array(
            'activation_id'    => sanitize_text_field( $body['activation_id'] ),
            'plan'             => sanitize_text_field( $body['plan'] ?? '' ),
            'site_limit'       => absint( $body['site_limit'] ?? 0 ),
            'activations_used' => absint( $body['activations_used'] ?? 0 ),
            'expiry'           => sanitize_text_field( $body['expiry'] ?? '' ),
            'last_validated'   => time(),
            'status'           => 'active',
        );
        $this->store_license_data( $license_data );
        $this->clear_grace_period();

        // Audit log: License activation.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_license_event( 'activate', array(
                'license_key' => $license_key,
                'plan'        => $license_data['plan'],
            ) );
        }

        return array(
            'success' => true,
            'message' => __( 'License activated successfully.', 'glimmr-ai' ),
            'data'    => $license_data,
        );
    }

    /**
     * Deactivate the current license from this site.
     *
     * @return array{success: bool, message: string} Result array.
     */
    public function deactivate() {
        $license_key = get_option( self::OPT_LICENSE_KEY, '' );
        $data        = $this->get_license_data();

        if ( empty( $license_key ) || empty( $data['activation_id'] ) ) {
            // Nothing to deactivate — just clean up.
            $this->clear_license_data();
            return array(
                'success' => true,
                'message' => __( 'License removed.', 'glimmr-ai' ),
            );
        }

        // Notify the server.
        $response = $this->remote_post( 'deactivate', array(
            'license_key'   => $license_key,
            'activation_id' => $data['activation_id'],
            'site_url'      => home_url(),
        ) );

        // Clear local data regardless of server response.
        $this->clear_license_data();

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => true,
                'message' => __( 'License removed locally. Server could not be reached.', 'glimmr-ai' ),
            );
        }

        // Audit log: License deactivation.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_license_event( 'deactivate', array(
                'license_key' => $license_key,
            ) );
        }

        return array(
            'success' => true,
            'message' => __( 'License deactivated successfully.', 'glimmr-ai' ),
        );
    }

    /**
     * Validate the current license against the server.
     *
     * @param bool $force Force validation even if cache is fresh.
     * @return bool True if validation succeeded, false otherwise.
     */
    public function validate( $force = false ) {
        $license_key = get_option( self::OPT_LICENSE_KEY, '' );
        $data        = $this->get_license_data();

        if ( empty( $license_key ) || empty( $data['activation_id'] ) ) {
            return false;
        }

        // Dev key bypass - always valid, no server contact.
        if ( strtoupper( $license_key ) === self::DEV_LICENSE_KEY ) {
            return true;
        }

        // Check cache unless forced.
        if ( ! $force ) {
            $last_validated = ! empty( $data['last_validated'] ) ? (int) $data['last_validated'] : 0;
            if ( ( time() - $last_validated ) < self::CACHE_DURATION ) {
                return 'active' === ( $data['status'] ?? '' );
            }
        }

        $response = $this->remote_post( 'validate', array(
            'license_key'   => $license_key,
            'activation_id' => $data['activation_id'],
            'site_url'      => home_url(),
        ) );

        if ( is_wp_error( $response ) ) {
            // Network error — enter grace period.
            $this->enter_grace_period();
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) ) {
            $this->enter_grace_period();
            return false;
        }

        if ( ! empty( $body['valid'] ) ) {
            // Valid — update cached data.
            $data['plan']             = sanitize_text_field( $body['plan'] ?? $data['plan'] ?? '' );
            $data['site_limit']       = absint( $body['site_limit'] ?? $data['site_limit'] ?? 0 );
            $data['activations_used'] = absint( $body['activations_used'] ?? $data['activations_used'] ?? 0 );
            $data['expiry']           = sanitize_text_field( $body['expiry'] ?? $data['expiry'] ?? '' );
            $data['last_validated']   = time();
            $data['status']           = 'active';
            $this->store_license_data( $data );
            $this->clear_grace_period();
            return true;
        }

        // Server explicitly says invalid — disable immediately (no grace period).
        $data['status']         = 'invalid';
        $data['last_validated'] = time();
        $this->store_license_data( $data );
        $this->clear_grace_period();

        // Audit log: License validation failure.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_license_event( 'validation_failed', array(
                'license_key' => $license_key,
                'reason'      => 'server_rejected',
            ) );
        }

        return false;
    }

    /**
     * Get the current license status for display.
     *
     * @return array License status information.
     */
    public function get_status() {
        $license_key = get_option( self::OPT_LICENSE_KEY, '' );
        $data        = $this->get_license_data();

        if ( empty( $license_key ) ) {
            return array(
                'status'  => 'inactive',
                'message' => __( 'No license key entered.', 'glimmr-ai' ),
            );
        }

        // Dev key has special status display.
        if ( strtoupper( $license_key ) === self::DEV_LICENSE_KEY ) {
            return array(
                'status'           => 'active',
                'license_key'      => 'GLMR-****-****-****-JPDG',
                'plan'             => 'dev_unlimited',
                'plan_label'       => __( 'Development (Unlimited)', 'glimmr-ai' ),
                'site_limit'       => 999,
                'activations_used' => 1,
                'expiry'           => '',
                'last_validated'   => time(),
                'grace_period'     => false,
                'is_dev'           => true,
            );
        }

        $masked_key = $this->mask_license_key( $license_key );

        return array(
            'status'           => $data['status'] ?? 'unknown',
            'license_key'      => $masked_key,
            'plan'             => $data['plan'] ?? '',
            'plan_label'       => $this->get_plan_label( $data['plan'] ?? '' ),
            'site_limit'       => $data['site_limit'] ?? 0,
            'activations_used' => $data['activations_used'] ?? 0,
            'expiry'           => $data['expiry'] ?? '',
            'last_validated'   => $data['last_validated'] ?? 0,
            'grace_period'     => $this->is_in_grace_period(),
        );
    }

    /**
     * Get human-readable plan label.
     *
     * @param string $plan Plan identifier.
     * @return string Human-readable plan name.
     */
    public function get_plan_label( $plan = '' ) {
        if ( empty( $plan ) ) {
            $data = $this->get_license_data();
            $plan = $data['plan'] ?? '';
        }

        $labels = array(
            'plan_1'         => __( '1 Site', 'glimmr-ai' ),
            'plan_10'        => __( '10 Sites', 'glimmr-ai' ),
            'plan_100'       => __( '100 Sites', 'glimmr-ai' ),
            'plan_unlimited' => __( 'Unlimited Sites', 'glimmr-ai' ),
        );

        return $labels[ $plan ] ?? $plan;
    }

    /**
     * Make a POST request to the licensing server.
     *
     * @param string $endpoint API endpoint (e.g. 'activate', 'validate').
     * @param array  $data     Request body data.
     * @return array|WP_Error Response array or WP_Error on failure.
     */
    private function remote_post( $endpoint, $data ) {
        $url = $this->get_server_url() . $endpoint;

        // Disable SSL verification for .local domains (self-signed certs).
        $host      = wp_parse_url( $url, PHP_URL_HOST );
        $sslverify = ! ( $host && str_ends_with( $host, '.local' ) );

        return wp_remote_post( $url, array(
            'timeout'   => self::REQUEST_TIMEOUT,
            'headers'   => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'      => wp_json_encode( $data ),
            'sslverify' => $sslverify,
        ) );
    }

    /**
     * Get the licensing server URL.
     *
     * Automatically uses glimmr.local when running on a .local domain,
     * otherwise defaults to glimmr.us. Filterable for further overrides.
     *
     * @return string Server URL.
     */
    private function get_server_url() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $host && str_ends_with( $host, '.local' ) ) {
            return 'https://glimmr.local/wp-json/glimmr-licensing/v1/';
        }

        return self::SERVER_URL;
    }

    /**
     * Get environment information for activation requests.
     *
     * @return array Environment data.
     */
    private function get_environment() {
        return array(
            'php_version'    => phpversion(),
            'wp_version'     => get_bloginfo( 'version' ),
            'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'plugin_version' => defined( 'GLIMMR_AI_VERSION' ) ? GLIMMR_AI_VERSION : 'unknown',
        );
    }

    /**
     * Enter grace period after a network error during validation.
     *
     * Grace period allows the plugin to continue working for 7 days
     * from the last successful validation.
     */
    private function enter_grace_period() {
        $data = $this->get_license_data();
        if ( empty( $data['grace_period_start'] ) ) {
            $data['grace_period_start'] = time();
            $this->store_license_data( $data );
        }
    }

    /**
     * Check if currently in grace period.
     *
     * @return bool True if in grace period and it hasn't expired.
     */
    private function is_in_grace_period() {
        $data = $this->get_license_data();

        if ( empty( $data['grace_period_start'] ) ) {
            return false;
        }

        $elapsed = time() - (int) $data['grace_period_start'];
        return $elapsed < self::GRACE_PERIOD;
    }

    /**
     * Clear the grace period flag.
     */
    private function clear_grace_period() {
        $data = $this->get_license_data();
        if ( isset( $data['grace_period_start'] ) ) {
            unset( $data['grace_period_start'] );
            $this->store_license_data( $data );
        }
    }

    /**
     * Compute HMAC for license data integrity verification.
     *
     * @param array $data License data array.
     * @return string HMAC-SHA256 hex digest.
     */
    private function compute_hmac( $data ) {
        $payload = ( $data['activation_id'] ?? '' ) . '|' . ( $data['plan'] ?? '' ) . '|' . ( $data['status'] ?? '' );
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    /**
     * Verify HMAC integrity of license data.
     *
     * @param array $data License data array.
     * @return bool True if HMAC is valid or not yet set.
     */
    private function verify_hmac( $data ) {
        if ( empty( $data['_hmac'] ) ) {
            // No HMAC stored yet (pre-upgrade data) — allow but flag for re-validation.
            return false;
        }
        return hash_equals( $data['_hmac'], $this->compute_hmac( $data ) );
    }

    /**
     * Store license data with HMAC integrity tag.
     *
     * @param array $data License data array.
     * @return void
     */
    private function store_license_data( $data ) {
        $data['_hmac'] = $this->compute_hmac( $data );
        update_option( self::OPT_LICENSE_DATA, $data );
        $this->cached_data = $data;
    }

    /**
     * Get cached license data from the database.
     *
     * @return array License data.
     */
    private function get_license_data() {
        if ( null === $this->cached_data ) {
            $this->cached_data = get_option( self::OPT_LICENSE_DATA, array() );
        }
        return $this->cached_data;
    }

    /**
     * Clear all stored license data.
     */
    private function clear_license_data() {
        delete_option( self::OPT_LICENSE_KEY );
        delete_option( self::OPT_LICENSE_DATA );
        $this->cached_data = null;
    }

    /**
     * Mask a license key for display (show first and last segments).
     *
     * @param string $key License key.
     * @return string Masked key (e.g. "GLMR-****-****-****-G7H8").
     */
    private function mask_license_key( $key ) {
        $parts = explode( '-', $key );
        if ( count( $parts ) < 5 ) {
            return '****-****-****-****';
        }
        return $parts[0] . '-****-****-****-' . $parts[4];
    }
}
