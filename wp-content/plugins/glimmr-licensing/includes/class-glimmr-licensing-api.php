<?php
/**
 * REST API endpoints for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_API
 *
 * Handles REST API endpoints: activate, deactivate, validate, ping.
 * Includes IP-based rate limiting.
 */
class Glimmr_Licensing_API {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'glimmr-licensing/v1';

    /**
     * Option key for development keys.
     *
     * @var string
     */
    const DEV_KEYS_OPTION = 'glimmr_licensing_dev_keys';

    /**
     * Rate limit: requests per minute per IP.
     *
     * @var int
     */
    const RATE_LIMIT = 60;

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes() {
        $license_key_arg = array(
            'required'          => true,
            'type'              => 'string',
            'description'       => __( 'License key in GLMR-XXXX-XXXX-XXXX-XXXX format.', 'glimmr-licensing' ),
            'sanitize_callback' => 'sanitize_text_field',
        );

        $site_url_arg = array(
            'required'          => true,
            'type'              => 'string',
            'description'       => __( 'The site URL being activated.', 'glimmr-licensing' ),
            'sanitize_callback' => 'esc_url_raw',
        );

        $activation_id_arg = array(
            'required'          => true,
            'type'              => 'string',
            'description'       => __( 'UUID of the activation.', 'glimmr-licensing' ),
            'sanitize_callback' => 'sanitize_text_field',
        );

        register_rest_route( self::NAMESPACE, '/activate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_activate' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key' => $license_key_arg,
                'site_url'    => $site_url_arg,
                'site_name'   => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'environment' => array(
                    'type'    => 'object',
                    'default' => array(),
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_deactivate' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key'   => $license_key_arg,
                'activation_id' => $activation_id_arg,
                'site_url'      => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/validate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_validate' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'license_key'   => $license_key_arg,
                'activation_id' => $activation_id_arg,
                'site_url'      => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/ping', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_ping' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Handle POST /activate.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_activate( $request ) {
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'rate_limited', 'message' => $rate_check->get_error_message() ),
                429
            );
        }

        $license_key = strtoupper( sanitize_text_field( $request->get_param( 'license_key' ) ?? '' ) );
        $site_url    = esc_url_raw( $request->get_param( 'site_url' ) ?? '' );
        $site_name   = sanitize_text_field( $request->get_param( 'site_name' ) ?? '' );
        $environment = $request->get_param( 'environment' );
        $environment = is_array( $environment ) ? $environment : array();
        $ip_address  = $this->get_client_ip();

        if ( empty( $license_key ) || empty( $site_url ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'missing_params', 'message' => __( 'License key and site URL are required.', 'glimmr-licensing' ) ),
                200
            );
        }

        // Validate key format before hitting the database.
        if ( ! Glimmr_Licensing_Key_Generator::is_valid_format( $license_key ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'invalid_key', 'message' => __( 'Invalid license key format.', 'glimmr-licensing' ) ),
                200
            );
        }

        // Development keys bypass the database entirely.
        if ( $this->is_dev_key( $license_key ) ) {
            $manager = new Glimmr_Licensing_Manager();
            $manager->log_action( 0, 'dev_activate', array( 'site_url' => $site_url ), $ip_address );
            return new WP_REST_Response( array(
                'success'          => true,
                'activation_id'    => $this->dev_activation_id( $license_key, $site_url ),
                'plan'             => 'development',
                'site_limit'       => 0,
                'activations_used' => 0,
                'expiry'           => null,
            ), 200 );
        }

        // Limit environment data size to 2KB.
        if ( is_array( $environment ) && strlen( wp_json_encode( $environment ) ) > 2048 ) {
            $environment = array();
        }

        $manager = new Glimmr_Licensing_Manager();
        $result  = $manager->activate( $license_key, $site_url, $site_name, $environment, $ip_address );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ),
                200
            );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Handle POST /deactivate.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_deactivate( $request ) {
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'rate_limited', 'message' => $rate_check->get_error_message() ),
                429
            );
        }

        $license_key   = strtoupper( sanitize_text_field( $request->get_param( 'license_key' ) ?? '' ) );
        $activation_id = sanitize_text_field( $request->get_param( 'activation_id' ) ?? '' );
        $site_url      = esc_url_raw( $request->get_param( 'site_url' ) ?? '' );
        $ip_address    = $this->get_client_ip();

        if ( empty( $license_key ) || empty( $activation_id ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'missing_params', 'message' => __( 'License key and activation ID are required.', 'glimmr-licensing' ) ),
                200
            );
        }

        if ( ! Glimmr_Licensing_Key_Generator::is_valid_format( $license_key ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => 'invalid_key', 'message' => __( 'Invalid license key format.', 'glimmr-licensing' ) ),
                200
            );
        }

        // Development keys — no-op deactivation.
        if ( $this->is_dev_key( $license_key ) ) {
            $manager = new Glimmr_Licensing_Manager();
            $manager->log_action( 0, 'dev_deactivate', array( 'site_url' => $site_url ), $ip_address );
            return new WP_REST_Response( array(
                'success' => true,
                'message' => __( 'Site deactivated successfully.', 'glimmr-licensing' ),
            ), 200 );
        }

        $manager = new Glimmr_Licensing_Manager();
        $result  = $manager->deactivate( $license_key, $activation_id, $site_url, $ip_address );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array( 'success' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ),
                200
            );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Handle POST /validate.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_validate( $request ) {
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return new WP_REST_Response(
                array( 'valid' => false, 'error' => 'rate_limited', 'message' => $rate_check->get_error_message() ),
                429
            );
        }

        $license_key   = strtoupper( sanitize_text_field( $request->get_param( 'license_key' ) ?? '' ) );
        $activation_id = sanitize_text_field( $request->get_param( 'activation_id' ) ?? '' );
        $site_url      = esc_url_raw( $request->get_param( 'site_url' ) ?? '' );
        $ip_address    = $this->get_client_ip();

        if ( empty( $license_key ) || empty( $activation_id ) ) {
            return new WP_REST_Response(
                array( 'valid' => false, 'error' => 'missing_params', 'message' => __( 'License key and activation ID are required.', 'glimmr-licensing' ) ),
                200
            );
        }

        if ( ! Glimmr_Licensing_Key_Generator::is_valid_format( $license_key ) ) {
            return new WP_REST_Response(
                array( 'valid' => false, 'error' => 'invalid_key', 'message' => __( 'Invalid license key format.', 'glimmr-licensing' ) ),
                200
            );
        }

        // Development keys are always valid.
        if ( $this->is_dev_key( $license_key ) ) {
            $manager = new Glimmr_Licensing_Manager();
            $manager->log_action( 0, 'dev_validate', array( 'site_url' => $site_url ), $ip_address );
            return new WP_REST_Response( array(
                'valid'            => true,
                'plan'             => 'development',
                'site_limit'       => 0,
                'activations_used' => 0,
                'expiry'           => null,
            ), 200 );
        }

        $manager = new Glimmr_Licensing_Manager();
        $result  = $manager->validate( $license_key, $activation_id, $site_url, $ip_address );

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Handle GET /ping.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_ping( $request ) {
        return new WP_REST_Response( array(
            'success' => true,
            'version' => GLIMMR_LICENSING_VERSION,
            'time'    => gmdate( 'c' ),
        ), 200 );
    }

    /**
     * Get the configured rate limit per minute.
     *
     * Reads from plugin settings, falls back to the default constant.
     *
     * @return int Requests per minute limit.
     */
    private function get_rate_limit() {
        $settings = get_option( 'glimmr_licensing_settings', array() );
        return absint( $settings['rate_limit_per_minute'] ?? self::RATE_LIMIT );
    }

    /**
     * IP-based rate limiting using transients.
     *
     * Uses a fixed window: the transient TTL is set only when the window
     * is first created, and subsequent increments preserve the original expiry
     * by computing the remaining TTL from the window start time.
     *
     * @return true|WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit() {
        $ip         = $this->get_client_ip();
        $ip_hash    = md5( $ip );
        $cache_key  = 'glimmr_rate_' . $ip_hash;
        $limit      = $this->get_rate_limit();

        $data = get_transient( $cache_key );

        if ( false === $data ) {
            // Start a new window.
            set_transient( $cache_key, array( 'count' => 1, 'start' => time() ), 60 );
            return true;
        }

        $count      = (int) ( $data['count'] ?? 0 );
        $start      = (int) ( $data['start'] ?? time() );
        $elapsed    = time() - $start;
        $remaining  = max( 1, 60 - $elapsed );

        // If the window has expired (should not happen if transient is properly expired,
        // but handle defensively), start a new window.
        if ( $elapsed >= 60 ) {
            set_transient( $cache_key, array( 'count' => 1, 'start' => time() ), 60 );
            return true;
        }

        if ( $count >= $limit ) {
            return new WP_Error(
                'rate_limited',
                sprintf(
                    /* translators: %d: seconds remaining */
                    __( 'Rate limit exceeded. Try again in %d seconds.', 'glimmr-licensing' ),
                    $remaining
                )
            );
        }

        // Increment count, preserving the original window TTL.
        $data['count'] = $count + 1;
        set_transient( $cache_key, $data, $remaining );

        return true;
    }

    /**
     * Get client IP address.
     *
     * Prefers REMOTE_ADDR to prevent IP spoofing via X-Forwarded-For.
     * Only falls back to proxy headers if REMOTE_ADDR is unavailable.
     *
     * @return string IP address.
     */
    private function get_client_ip() {
        $ip = '';

        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    /**
     * Check if a license key is a development key.
     *
     * Only accepts dev keys when explicitly enabled via the
     * GLIMMR_DEV_KEYS_ENABLED constant, preventing dev key
     * bypass in production environments.
     *
     * @param string $key License key.
     * @return bool True if this is a dev key.
     */
    private function is_dev_key( $key ) {
        if ( ! defined( 'GLIMMR_DEV_KEYS_ENABLED' ) || ! GLIMMR_DEV_KEYS_ENABLED ) {
            return false;
        }
        return Glimmr_Licensing_Admin::is_dev_key( $key );
    }

    /**
     * Generate a deterministic activation ID for a dev key + site URL pair.
     *
     * Uses a hash so the same key + site always returns the same activation ID,
     * making the activate endpoint idempotent for dev keys without DB storage.
     *
     * @param string $key      License key.
     * @param string $site_url Site URL.
     * @return string UUID-formatted activation ID.
     */
    private function dev_activation_id( $key, $site_url ) {
        $hash = md5( 'dev:' . $key . ':' . $site_url );
        // Format as UUID v4-like: 8-4-4-4-12
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr( $hash, 0, 8 ),
            substr( $hash, 8, 4 ),
            substr( $hash, 12, 4 ),
            substr( $hash, 16, 4 ),
            substr( $hash, 20, 12 )
        );
    }
}
