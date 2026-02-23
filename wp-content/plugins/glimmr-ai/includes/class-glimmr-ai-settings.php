<?php
/**
 * Settings management for Glimmr AI.
 *
 * Handles both network-level (multisite) and site-level settings with inheritance.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Settings
 *
 * Manages plugin settings with multisite support.
 * Can be used both statically and as an instance.
 */
class Glimmr_AI_Settings {

    /**
     * Option name for site-level settings.
     *
     * @var string
     */
    const SITE_OPTION = 'glimmr_ai_settings';

    /**
     * Option name for network-level settings.
     *
     * @var string
     */
    const NETWORK_OPTION = 'glimmr_ai_network_settings';

    /**
     * Cached settings.
     *
     * @var array|null
     */
    private static $cached_settings = null;

    /**
     * Transient cache key for network settings.
     *
     * @var string
     */
    const NETWORK_CACHE_KEY = 'glimmr_ai_network_settings_cache';

    /**
     * Transient cache duration in seconds (5 minutes).
     *
     * @var int
     */
    const NETWORK_CACHE_DURATION = 300;

    /**
     * Constructor.
     *
     * Allows the class to be instantiated for dependency injection.
     */
    public function __construct() {
        // Settings are loaded on demand via get/get_all methods.
    }

    /**
     * Network settings that can be inherited.
     *
     * Sites can override any of these by setting their own value.
     * Empty/unset site values will fall back to network defaults.
     *
     * @var array
     */
    private static $network_inheritable = array(
        // API Configuration.
        'openai_api_key_encrypted',
        'openai_vector_store_id',
        'openai_model',
        'reasoning_effort',          // GPT-5 series reasoning effort level.
        'max_tokens_per_response',
        'max_messages_per_conversation',
        'conversation_expiry_days',

        // Rate Limits & Token Budgets.
        'rate_limit_authenticated',
        'rate_limit_anonymous',
        'rate_limit_window_seconds',
        'daily_token_limit',
        'monthly_token_limit',

        // Sync Settings.
        'product_sync_enabled',
        'product_sync_schedule',
        'product_sync_batch_size',
        'knowledge_sync_schedule',

        // Widget Appearance (Network Branding Defaults).
        'widget_enabled',
        'widget_position',
        'widget_width',
        'widget_height',
        'widget_primary_color',
        'widget_primary_hover',
        'widget_secondary_color',
        'widget_bg_color',
        'widget_bg_light',
        'widget_border_color',
        'widget_text_color',
        'widget_text_dark',
        'widget_text_muted',
        'widget_success_color',
        'widget_error_color',
        'widget_button_border',
        'widget_button_border_width',
        'widget_border_radius',
        'widget_font_family',
        'widget_avatar_url',
        'widget_name',
        'widget_greeting',

        // System Prompt & Agent Configuration.
        'system_prompt',
        'agent_guardrails',
        'slot_filling_system_prompt',
        'agent_tone',
        'agent_personality',
        'fallback_response',

        // Agent Loop Configuration.
        'max_agent_rounds',
        'max_tools_per_turn',

        // Tools Configuration (Network can restrict available tools).
        'enabled_tools',
        'network_allowed_tools', // Tools that network permits sites to enable.

        // Privacy & GDPR.
        'gdpr_enabled',
        'gdpr_consent_text',
        'privacy_export_enabled',
        'privacy_erasure_enabled',
        'data_retention_days',

        // Support Contact Info.
        'support_email',
        'support_phone',
        'contact_request_email',

        // Security Settings.
        'max_message_length',                    // Maximum message length in chars (default 4000).
        'conversation_history_retention_days',   // Days to retain conversation history (default 30).
    );

    /**
     * Settings that network can lock (sites cannot override).
     *
     * @var array
     */
    private static $network_lockable = array(
        'openai_api_key_encrypted',    // Force all sites to use network API key.
        'openai_model',                // Restrict model usage.
        'daily_token_limit',           // Enforce token budgets.
        'monthly_token_limit',
        'rate_limit_authenticated',
        'rate_limit_anonymous',
        'enabled_tools',               // Restrict which tools sites can use.
    );

    /**
     * Get all settings with inheritance applied.
     *
     * @param bool $force_refresh Force refresh from database.
     * @return array The merged settings array.
     */
    public static function get_all( $force_refresh = false ) {
        if ( null !== self::$cached_settings && ! $force_refresh ) {
            return self::$cached_settings;
        }

        $site_settings = get_option( self::SITE_OPTION, array() );

        // If not multisite, return site settings as-is.
        if ( ! is_multisite() ) {
            self::$cached_settings = $site_settings;
            return self::$cached_settings;
        }

        // If plugin is not network-activated, skip inheritance entirely.
        // Network settings only apply when the plugin is enabled at network level.
        if ( ! self::is_network_activated() ) {
            self::$cached_settings = $site_settings;
            return self::$cached_settings;
        }

        // Get network settings with network-wide transient caching.
        // Uses get_site_transient so the cache is shared across all sites in the network.
        $network_settings = get_site_transient( self::NETWORK_CACHE_KEY );
        if ( false === $network_settings ) {
            $network_settings = get_site_option( self::NETWORK_OPTION, array() );
            set_site_transient( self::NETWORK_CACHE_KEY, $network_settings, self::NETWORK_CACHE_DURATION );
        }

        // Get locked settings from network.
        $locked_settings = isset( $network_settings['locked_settings'] ) && is_array( $network_settings['locked_settings'] )
            ? $network_settings['locked_settings']
            : array();

        // Apply inheritance for inheritable settings.
        foreach ( self::$network_inheritable as $key ) {
            // If setting is locked by network, always use network value.
            if ( in_array( $key, $locked_settings, true ) ) {
                if ( isset( $network_settings[ $key ] ) ) {
                    $site_settings[ $key ] = $network_settings[ $key ];
                }
                continue;
            }

            // If site has opted into inheritance OR site value is empty, use network value.
            $inherit_enabled = ! empty( $site_settings['inherit_network_settings'] );
            $site_value_empty = ! isset( $site_settings[ $key ] ) || '' === $site_settings[ $key ];

            if ( ( $inherit_enabled || $site_value_empty ) && isset( $network_settings[ $key ] ) && '' !== $network_settings[ $key ] ) {
                // Only override if site hasn't explicitly set a value (or is inheriting).
                if ( $site_value_empty || $inherit_enabled ) {
                    $site_settings[ $key ] = $network_settings[ $key ];
                }
            }
        }

        // Add metadata about inheritance state.
        $site_settings['_network_inheritance_active'] = true;
        $site_settings['_locked_settings'] = $locked_settings;

        self::$cached_settings = $site_settings;
        return self::$cached_settings;
    }

    /**
     * Check if the plugin is network-activated.
     *
     * Network settings and inheritance only apply when the plugin
     * is activated at the network level in multisite.
     *
     * @return bool True if network-activated, false otherwise.
     */
    public static function is_network_activated() {
        if ( ! is_multisite() ) {
            return false;
        }

        // Include plugin.php if not already loaded (needed for is_plugin_active_for_network).
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network( 'glimmr-ai/glimmr-ai.php' );
    }

    /**
     * Check if a setting is locked by network (cannot be overridden by site).
     *
     * @param string $key The setting key.
     * @return bool True if locked.
     */
    public static function is_setting_locked( $key ) {
        if ( ! is_multisite() || ! self::is_network_activated() ) {
            return false;
        }

        $network_settings = self::get_network();
        $locked_settings = isset( $network_settings['locked_settings'] ) && is_array( $network_settings['locked_settings'] )
            ? $network_settings['locked_settings']
            : array();

        return in_array( $key, $locked_settings, true );
    }

    /**
     * Check if a setting is being inherited from network (not overridden by site).
     *
     * @param string $key The setting key.
     * @return bool True if inherited from network.
     */
    public static function is_setting_inherited( $key ) {
        if ( ! is_multisite() || ! self::is_network_activated() ) {
            return false;
        }

        $site_raw = self::get_site_raw();
        $network_settings = self::get_network();

        // Not inheritable.
        if ( ! in_array( $key, self::$network_inheritable, true ) ) {
            return false;
        }

        // Site has its own value.
        if ( isset( $site_raw[ $key ] ) && '' !== $site_raw[ $key ] ) {
            return false;
        }

        // Network has a value.
        return isset( $network_settings[ $key ] ) && '' !== $network_settings[ $key ];
    }

    /**
     * Get list of inheritable setting keys.
     *
     * @return array List of setting keys that can be inherited.
     */
    public static function get_inheritable_settings() {
        return self::$network_inheritable;
    }

    /**
     * Get list of lockable setting keys.
     *
     * @return array List of setting keys that network can lock.
     */
    public static function get_lockable_settings() {
        return self::$network_lockable;
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     The setting key.
     * @param mixed  $default Default value if not found.
     * @return mixed The setting value.
     */
    public static function get( $key, $default = null ) {
        $settings = self::get_all();

        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }

        return $default;
    }

    /**
     * Filter sensitive data from settings before exposing to JavaScript/export.
     *
     * S16: Export Security - API keys excluded from export and JavaScript.
     *
     * @param array $settings The settings array to filter.
     * @return array Filtered settings without sensitive data.
     */
    public static function filter_sensitive_settings( $settings ) {
        // Keys that contain secrets - never expose to client or export.
        $sensitive_keys = array(
            'openai_api_key',
            'openai_api_key_encrypted',
            'encryption_key',
            'api_secret',
        );

        foreach ( $sensitive_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                unset( $settings[ $key ] );
            }
        }

        return $settings;
    }

    /**
     * Get all settings filtered for safe JavaScript exposure.
     *
     * S16: Export Security - API keys excluded from JavaScript.
     *
     * @return array Filtered settings safe for JavaScript.
     */
    public static function get_all_safe() {
        return self::filter_sensitive_settings( self::get_all() );
    }

    /**
     * Update site-level settings.
     *
     * @param array $new_settings The new settings to save.
     * @return bool True on success, false on failure.
     */
    public static function update( $new_settings ) {
        $current = get_option( self::SITE_OPTION, array() );
        $merged  = wp_parse_args( $new_settings, $current );

        // Sanitize settings.
        $merged = self::sanitize_settings( $merged );

        // update_option returns false if value unchanged, so check if option exists first.
        $option_exists = get_option( self::SITE_OPTION ) !== false;

        if ( $option_exists ) {
            $result = update_option( self::SITE_OPTION, $merged );
            // update_option returns false if value is unchanged, which is still a "success".
            // We verify by checking if the option now has our data.
            if ( ! $result ) {
                $saved = get_option( self::SITE_OPTION, array() );
                $result = ( $saved === $merged );
            }
        } else {
            $result = add_option( self::SITE_OPTION, $merged, '', 'yes' );
        }

        // Clear cache.
        self::$cached_settings = null;

        return $result;
    }

    /**
     * Update a single setting.
     *
     * @param string $key   The setting key.
     * @param mixed  $value The value to save.
     * @return bool True on success, false on failure.
     */
    public static function set( $key, $value ) {
        return self::update( array( $key => $value ) );
    }

    /**
     * Update network-level settings (multisite only).
     *
     * Requires `manage_network_options` capability.
     *
     * @param array $new_settings The new settings to save.
     * @return bool|WP_Error True on success, false on failure, WP_Error if unauthorized.
     */
    public static function update_network( $new_settings ) {
        if ( ! is_multisite() ) {
            return false;
        }

        // Security check: Only network admins can update network settings.
        if ( ! current_user_can( 'manage_network_options' ) ) {
            return new WP_Error(
                'unauthorized',
                __( 'You do not have permission to update network settings.', 'glimmr-ai' )
            );
        }

        $current = get_site_option( self::NETWORK_OPTION, array() );
        $merged  = wp_parse_args( $new_settings, $current );

        // Sanitize settings.
        $merged = self::sanitize_network_settings( $merged );

        $result = update_site_option( self::NETWORK_OPTION, $merged );

        // Clear caches.
        self::$cached_settings = null;
        delete_site_transient( self::NETWORK_CACHE_KEY );

        return $result;
    }

    /**
     * Get network-level settings.
     *
     * @return array The network settings array.
     */
    public static function get_network() {
        if ( ! is_multisite() ) {
            return array();
        }

        return get_site_option( self::NETWORK_OPTION, array() );
    }

    /**
     * Get site-level settings without inheritance.
     *
     * @return array The raw site settings array.
     */
    public static function get_site_raw() {
        return get_option( self::SITE_OPTION, array() );
    }

    /**
     * Check if a specific tool is enabled.
     *
     * @param string $tool_name The tool name.
     * @return bool True if enabled, false otherwise.
     */
    public static function is_tool_enabled( $tool_name ) {
        $enabled_tools = self::get( 'enabled_tools', array() );

        if ( ! is_array( $enabled_tools ) ) {
            return true; // Default to enabled if not an array.
        }

        return isset( $enabled_tools[ $tool_name ] ) ? (bool) $enabled_tools[ $tool_name ] : true;
    }

    /**
     * Get the OpenAI API key.
     *
     * Decrypts the key if it was stored encrypted.
     *
     * @return string The API key or empty string.
     */
    public static function get_api_key() {
        $encrypted_key = self::get( 'openai_api_key_encrypted', '' );

        // If we have an encrypted key, decrypt it.
        if ( ! empty( $encrypted_key ) ) {
            return self::decrypt_value( $encrypted_key );
        }

        // Fallback to legacy plain text key (for migration).
        $plain_key = self::get( 'openai_api_key', '' );

        // If we have a plain text key, migrate it to encrypted.
        if ( ! empty( $plain_key ) ) {
            self::set_api_key( $plain_key );
            // Remove the plain text version.
            $settings = get_option( self::SITE_OPTION, array() );
            unset( $settings['openai_api_key'] );
            update_option( self::SITE_OPTION, $settings );
            self::$cached_settings = null;
            return $plain_key;
        }

        return '';
    }

    /**
     * Set the OpenAI API key.
     *
     * Stores the key in encrypted form.
     *
     * @param string $api_key The API key to store.
     * @return bool True on success.
     */
    public static function set_api_key( $api_key ) {
        if ( empty( $api_key ) ) {
            return self::set( 'openai_api_key_encrypted', '' );
        }

        $encrypted = self::encrypt_value( $api_key );
        return self::set( 'openai_api_key_encrypted', $encrypted );
    }

    /**
     * Encrypt a sensitive value.
     *
     * Uses WordPress AUTH_KEY for encryption.
     *
     * @param string $value The value to encrypt.
     * @return string The encrypted value (base64 encoded).
     */
    private static function encrypt_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        // Use OpenSSL if available.
        if ( function_exists( 'openssl_encrypt' ) ) {
            $key = hash( 'sha256', wp_salt( 'auth' ), true );
            $iv = openssl_random_pseudo_bytes( 16 );
            $encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

            if ( $encrypted !== false ) {
                // Prepend IV to encrypted data.
                return base64_encode( $iv . $encrypted );
            }
        }

        // Fallback: Use simple obfuscation (better than plain text).
        $key = wp_salt( 'auth' );
        $obfuscated = '';
        for ( $i = 0; $i < strlen( $value ); $i++ ) {
            $obfuscated .= chr( ord( $value[ $i ] ) ^ ord( $key[ $i % strlen( $key ) ] ) );
        }
        return 'obf:' . base64_encode( $obfuscated );
    }

    /**
     * Decrypt a sensitive value.
     *
     * @param string $encrypted_value The encrypted value.
     * @return string The decrypted value.
     */
    private static function decrypt_value( $encrypted_value ) {
        if ( empty( $encrypted_value ) ) {
            return '';
        }

        // Check for obfuscated format.
        if ( strpos( $encrypted_value, 'obf:' ) === 0 ) {
            $obfuscated = base64_decode( substr( $encrypted_value, 4 ) );
            $key = wp_salt( 'auth' );
            $decrypted = '';
            for ( $i = 0; $i < strlen( $obfuscated ); $i++ ) {
                $decrypted .= chr( ord( $obfuscated[ $i ] ) ^ ord( $key[ $i % strlen( $key ) ] ) );
            }
            return $decrypted;
        }

        // OpenSSL decryption.
        if ( function_exists( 'openssl_decrypt' ) ) {
            $data = base64_decode( $encrypted_value );
            if ( $data === false || strlen( $data ) < 17 ) {
                // Log this as it indicates corrupted data.
                if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                    Glimmr_AI_Logger::warning(
                        'API key decryption failed: invalid base64 or data too short',
                        array( 'data_length' => $data === false ? 0 : strlen( $data ) ),
                        'settings'
                    );
                }
                return '';
            }

            $iv = substr( $data, 0, 16 );
            $encrypted = substr( $data, 16 );
            $key = hash( 'sha256', wp_salt( 'auth' ), true );

            $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

            if ( $decrypted !== false ) {
                return $decrypted;
            }

            // Log decryption failure.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::warning(
                    'API key decryption failed: OpenSSL decrypt returned false',
                    array( 'openssl_error' => openssl_error_string() ),
                    'settings'
                );
            }
        }

        return '';
    }

    /**
     * Get the OpenAI model.
     *
     * @return string The model name.
     */
    public static function get_model() {
        return self::get( 'openai_model', 'gpt-4o' );
    }

    /**
     * Get the vector store ID.
     *
     * @return string The vector store ID or empty string.
     */
    public static function get_vector_store_id() {
        return self::get( 'openai_vector_store_id', '' );
    }

    /**
     * Check if the widget should be displayed on the current page.
     *
     * @return bool True if widget should be displayed.
     */
    public static function should_display_widget() {
        if ( ! self::get( 'widget_enabled', true ) ) {
            return false;
        }

        // Get current URL path.
        $current_path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

        // Check exclude pages.
        $exclude_pages = self::get( 'widget_exclude_pages', array() );
        if ( ! empty( $exclude_pages ) && is_array( $exclude_pages ) ) {
            foreach ( $exclude_pages as $page ) {
                if ( ! empty( $page ) && strpos( $current_path, $page ) !== false ) {
                    return false;
                }
            }
        }

        // Check include pages (if specified, only show on these pages).
        $include_pages = self::get( 'widget_include_pages', array() );
        if ( ! empty( $include_pages ) && is_array( $include_pages ) ) {
            $found = false;
            foreach ( $include_pages as $page ) {
                if ( ! empty( $page ) && strpos( $current_path, $page ) !== false ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get rate limit for current user type.
     *
     * @return int The rate limit (requests per hour).
     */
    public static function get_rate_limit() {
        if ( is_user_logged_in() ) {
            return (int) self::get( 'rate_limit_authenticated', 100 );
        }

        return (int) self::get( 'rate_limit_anonymous', 20 );
    }

    /**
     * Get widget configuration for frontend.
     *
     * @return array Widget configuration array.
     */
    public static function get_widget_config() {
        return array(
            'enabled'        => self::get( 'widget_enabled', true ),
            'debugMode'      => self::get( 'widget_debug_mode', false ),
            'position'       => self::get( 'widget_position', 'bottom-right' ),
            'width'          => self::get( 'widget_width', 400 ),
            'height'         => self::get( 'widget_height', 650 ),
            'primaryColor'   => self::get( 'widget_primary_color', '#4F46E5' ),
            'secondaryColor' => self::get( 'widget_secondary_color', '#818CF8' ),
            'textColor'      => self::get( 'widget_text_color', '#FFFFFF' ),
            'fontFamily'     => self::get( 'widget_font_family', 'inherit' ),
            'avatarUrl'      => self::get( 'widget_avatar_url', '' ),
            'name'           => self::get( 'widget_name', 'Shopping Assistant' ),
            'greeting'       => self::get( 'widget_greeting', '<p>Hi! How can I help you today?</p>' ),
            'quickReplies'   => self::get( 'widget_quick_replies', array() ),
            'gdprEnabled'    => self::get( 'gdpr_enabled', true ),
            'gdprText'       => self::get( 'gdpr_consent_text', 'By chatting, you agree to our privacy policy.' ),

            // Proactive engagement triggers.
            'triggers'       => array(
                'time' => array(
                    'enabled'  => (bool) self::get( 'proactive_time_enabled', false ),
                    'delay'    => (int) self::get( 'proactive_time_delay', 30 ) * 1000, // Convert to ms.
                    'message'  => self::get( 'proactive_time_message', 'Hi there! Need help finding anything?' ),
                    'pages'    => self::get( 'proactive_time_pages', array( 'product', 'category', 'shop' ) ),
                ),
                'exit' => array(
                    'enabled'        => (bool) self::get( 'proactive_exit_enabled', false ),
                    'message'        => self::get( 'proactive_exit_message', 'Wait! Before you go, is there anything I can help you with?' ),
                    'pages'          => self::get( 'proactive_exit_pages', array( 'cart', 'product' ) ),
                    'oncePerSession' => (bool) self::get( 'proactive_exit_once_per_session', true ),
                ),
                'scroll' => array(
                    'enabled'  => (bool) self::get( 'proactive_scroll_enabled', false ),
                    'percent'  => (int) self::get( 'proactive_scroll_percent', 50 ),
                    'message'  => self::get( 'proactive_scroll_message', 'Enjoying what you see? Let me help you find the perfect item!' ),
                    'pages'    => self::get( 'proactive_scroll_pages', array( 'product', 'category' ) ),
                ),
                'abandonedCart' => array(
                    'enabled'        => (bool) self::get( 'abandoned_cart_enabled', false ),
                    'minValue'       => (float) self::get( 'abandoned_cart_min_value', 0 ),
                    'minItems'       => (int) self::get( 'abandoned_cart_min_items', 1 ),
                    'inactivityDelay' => (int) self::get( 'abandoned_cart_inactivity_delay', 60 ) * 1000, // Convert to ms.
                    'message'        => self::get( 'abandoned_cart_message', 'I noticed you have items in your cart. Would you like help completing your order?' ),
                    'includeItems'   => (bool) self::get( 'abandoned_cart_include_items', true ),
                    'offerCoupon'    => (bool) self::get( 'abandoned_cart_offer_coupon', false ),
                    'couponCode'     => self::get( 'abandoned_cart_coupon_code', '' ),
                    'pages'          => self::get( 'abandoned_cart_pages', array( 'cart', 'checkout', 'product' ) ),
                    'oncePerSession' => (bool) self::get( 'abandoned_cart_once_per_session', true ),
                ),
                'idleEngagement' => array(
                    'enabled'          => (bool) self::get( 'idle_engagement_enabled', false ),
                    'delay'            => (int) self::get( 'idle_engagement_delay', 45 ) * 1000, // Convert to ms.
                    'message'          => self::get( 'idle_engagement_message', 'Is there anything I can help you with today?' ),
                    'pages'            => self::get( 'idle_engagement_pages', array( 'shop', 'product', 'category' ) ),
                    'oncePerSession'   => (bool) self::get( 'idle_engagement_once_per_session', true ),
                    'requireEmptyCart' => (bool) self::get( 'idle_engagement_require_empty_cart', false ),
                ),
            ),

            // Google Analytics 4 integration.
            'ga4Enabled'         => (bool) self::get( 'ga4_enabled', false ),
            'ga4MeasurementId'   => self::get( 'ga4_measurement_id', '' ),
            'ga4TrackWidgetOpen' => (bool) self::get( 'ga4_track_widget_open', true ),
            'ga4TrackMessages'   => (bool) self::get( 'ga4_track_messages', true ),
            'ga4TrackProducts'   => (bool) self::get( 'ga4_track_products', true ),
            'ga4TrackCart'       => (bool) self::get( 'ga4_track_cart', true ),
            'ga4TrackCheckout'   => (bool) self::get( 'ga4_track_checkout', true ),

            // Reviews integration.
            'reviewsEnabled'     => (bool) self::get( 'reviews_enabled', true ),
            'reviewsCount'       => (int) self::get( 'reviews_count', 3 ),
            'reviewsMinRating'   => (int) self::get( 'reviews_min_rating', 0 ),
        );
    }

    /**
     * Sanitize site-level settings.
     *
     * @param array $settings The settings to sanitize.
     * @return array Sanitized settings.
     */
    private static function sanitize_settings( $settings ) {
        // Handle API key encryption separately.
        if ( isset( $settings['openai_api_key'] ) && ! empty( $settings['openai_api_key'] ) ) {
            // Encrypt and store as openai_api_key_encrypted.
            $settings['openai_api_key_encrypted'] = self::encrypt_value( $settings['openai_api_key'] );
            unset( $settings['openai_api_key'] ); // Remove plain text version.
        }

        // Sanitize text fields.
        $text_fields = array(
            'openai_vector_store_id',
            'openai_model',
            'widget_position',
            'widget_primary_color',
            'widget_primary_hover',
            'widget_secondary_color',
            'widget_bg_color',
            'widget_bg_light',
            'widget_border_color',
            'widget_text_color',
            'widget_text_dark',
            'widget_text_muted',
            'widget_success_color',
            'widget_error_color',
            'widget_button_border',
            'widget_font_family',
            'widget_avatar_url',
            'widget_name',
            'agent_tone',
            'agent_personality',
            'fallback_response',
            'coupon_visibility',
            'product_index_mode',
            'gdpr_consent_text',
            'product_sync_schedule',
            'knowledge_sync_schedule',
            'support_email',
            'support_phone',
            'contact_request_email',
            // Artifact text/select fields.
            'artifact_grid_columns',
            'artifact_grid_card_style',
            'artifact_comparison_layout',
            'artifact_modal_image_style',
            'artifact_order_timeline_style',
            'artifact_coupon_style',
            // Proactive trigger text fields.
            'proactive_time_message',
            'proactive_exit_message',
            'proactive_scroll_message',
            // Abandoned cart and idle engagement text fields.
            'abandoned_cart_message',
            'abandoned_cart_coupon_code',
            'idle_engagement_message',
            // GA4 integration text fields.
            'ga4_measurement_id',
        );

        foreach ( $text_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = sanitize_text_field( $settings[ $field ] );
            }
        }

        // Sanitize integer fields.
        $int_fields = array(
            'max_tokens_per_response',
            'max_messages_per_conversation',
            'conversation_expiry_days',
            'rate_limit_authenticated',
            'rate_limit_anonymous',
            'daily_token_limit',
            'monthly_token_limit',
            'product_sync_batch_size',
            'data_retention_days',
            // Widget dimension and style fields.
            'widget_width',
            'widget_height',
            'widget_offset_x',
            'widget_offset_y',
            'widget_z_index',
            'widget_button_border_width',
            'widget_border_radius',
            // Artifact integer fields.
            'artifact_comparison_max_products',
            'artifact_history_max_display',
            'artifact_carousel_items_visible',
            'artifact_knowledge_max_sources',
            // Slot-filling agent settings.
            'max_agent_rounds',
            'max_tools_per_turn',
            // Proactive trigger integer fields.
            'proactive_time_delay',
            'proactive_scroll_percent',
            // Abandoned cart and idle engagement integer fields.
            'abandoned_cart_min_items',
            'abandoned_cart_inactivity_delay',
            'idle_engagement_delay',
            // Reviews integration integer fields.
            'reviews_count',
            'reviews_min_rating',
        );

        foreach ( $int_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = absint( $settings[ $field ] );
            }
        }

        // Sanitize boolean fields.
        $bool_fields = array(
            'inherit_network_settings',
            'product_sync_enabled',
            'product_auto_sync_enabled',   // Auto-sync products on schedule (default false).
            'knowledge_auto_sync_enabled', // Auto-sync knowledge on schedule (default false).
            'widget_enabled',
            'widget_debug_mode',
            'gdpr_enabled',
            'privacy_export_enabled',
            'privacy_erasure_enabled',
            // Artifact boolean fields.
            'artifact_grid_show_rating',
            'artifact_grid_show_stock',
            'artifact_comparison_highlight_best',
            'artifact_modal_show_reviews',
            'artifact_order_show_timeline',
            'artifact_order_show_items',
            'artifact_history_show_thumbnails',
            'artifact_cart_inline_quantity',
            'artifact_cart_show_savings',
            'artifact_cart_coupon_input',
            'artifact_coupon_show_expiry',
            'artifact_coupon_apply_button',
            'artifact_carousel_auto_scroll',
            'artifact_carousel_show_reason',
            'artifact_account_show_loyalty',
            'artifact_account_mask_email',
            'artifact_knowledge_show_sources',
            // Slot-filling agent settings.
            'streaming_enabled',
            // Proactive trigger boolean fields.
            'proactive_time_enabled',
            'proactive_exit_enabled',
            'proactive_exit_once_per_session',
            'proactive_scroll_enabled',
            // Abandoned cart and idle engagement boolean fields.
            'abandoned_cart_enabled',
            'abandoned_cart_include_items',
            'abandoned_cart_offer_coupon',
            'abandoned_cart_once_per_session',
            'idle_engagement_enabled',
            'idle_engagement_once_per_session',
            'idle_engagement_require_empty_cart',
            // GA4 integration boolean fields.
            'ga4_enabled',
            'ga4_track_widget_open',
            'ga4_track_messages',
            'ga4_track_products',
            'ga4_track_cart',
            'ga4_track_checkout',
            // Reviews integration boolean fields.
            'reviews_enabled',
            // SEO integration boolean fields.
            'seo_integration_enabled',
            'seo_faq_schema',
            'seo_index_knowledge',
            // Contact form boolean fields.
            'contact_include_context',
            'contact_email_notifications',
            'contact_require_phone',
            // Logging boolean fields.
            'log_ai_requests',
            'log_tool_execution',
        );

        foreach ( $bool_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = (bool) $settings[ $field ];
            }
        }

        // Sanitize log_level (enum validation).
        if ( isset( $settings['log_level'] ) ) {
            $allowed_levels = array( 'debug', 'info', 'warning', 'error', 'critical' );
            if ( ! in_array( $settings['log_level'], $allowed_levels, true ) ) {
                $settings['log_level'] = 'warning'; // Default to warning if invalid.
            }
        }

        // Sanitize float fields.
        $float_fields = array(
            'abandoned_cart_min_value',
        );

        foreach ( $float_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = (float) max( 0, $settings[ $field ] );
            }
        }

        // Sanitize HTML fields with restricted tag set (prevent XSS).
        if ( isset( $settings['widget_greeting'] ) ) {
            $settings['widget_greeting'] = self::sanitize_widget_html( $settings['widget_greeting'] );
        }
        if ( isset( $settings['system_prompt'] ) ) {
            // System prompt should be plain text only.
            $settings['system_prompt'] = wp_strip_all_tags( $settings['system_prompt'] );
        }

        // Sanitize arrays.
        $array_fields = array(
            'widget_include_pages',
            'widget_exclude_pages',
            'visible_coupons',
            'product_include_categories',
            'product_exclude_categories',
            'product_include_ids',
            'product_exclude_ids',
            // Proactive trigger page arrays.
            'proactive_time_pages',
            'proactive_exit_pages',
            'proactive_scroll_pages',
            // Abandoned cart and idle engagement page arrays.
            'abandoned_cart_pages',
            'idle_engagement_pages',
        );

        foreach ( $array_fields as $field ) {
            if ( isset( $settings[ $field ] ) && ! is_array( $settings[ $field ] ) ) {
                $settings[ $field ] = array();
            }
        }

        // Sanitize quick replies array.
        if ( isset( $settings['widget_quick_replies'] ) && is_array( $settings['widget_quick_replies'] ) ) {
            $settings['widget_quick_replies'] = array_map(
                function ( $reply ) {
                    return array(
                        'text'   => sanitize_text_field( $reply['text'] ?? '' ),
                        'action' => sanitize_text_field( $reply['action'] ?? '' ),
                    );
                },
                $settings['widget_quick_replies']
            );
        }

        // Sanitize enabled_tools array.
        if ( isset( $settings['enabled_tools'] ) && is_array( $settings['enabled_tools'] ) ) {
            $settings['enabled_tools'] = array_map( 'boolval', $settings['enabled_tools'] );
        }

        // Sanitize vector store custom attributes (max 5 mappings).
        if ( isset( $settings['vector_store_custom_attributes'] ) ) {
            $custom = $settings['vector_store_custom_attributes'];
            if ( is_array( $custom ) ) {
                $reserved_keys = array(
                    'price', 'max_price', 'stock_status', 'on_sale', 'featured',
                    'product_type', 'rating', 'review_count', 'total_sales',
                    'date_created', 'regular_price',
                );
                $sanitized = array();
                foreach ( array_slice( $custom, 0, 5 ) as $mapping ) {
                    if ( ! empty( $mapping['meta_key'] ) && ! empty( $mapping['attribute_key'] ) ) {
                        $attr_key = preg_replace( '/[^a-z0-9_]/', '_', strtolower( sanitize_key( $mapping['attribute_key'] ) ) );
                        // Skip reserved attribute keys.
                        if ( in_array( $attr_key, $reserved_keys, true ) ) {
                            continue;
                        }
                        $sanitized[] = array(
                            'meta_key'      => sanitize_key( $mapping['meta_key'] ),
                            'attribute_key' => $attr_key,
                            'type'          => in_array( $mapping['type'] ?? 'string', array( 'string', 'number' ), true ) ? $mapping['type'] : 'string',
                        );
                    }
                }
                $settings['vector_store_custom_attributes'] = $sanitized;
            } else {
                $settings['vector_store_custom_attributes'] = array();
            }
        }

        return $settings;
    }

    /**
     * Sanitize HTML for widget display.
     *
     * Only allows safe formatting tags, no event handlers or scripts.
     *
     * @param string $html The HTML to sanitize.
     * @return string Sanitized HTML.
     */
    private static function sanitize_widget_html( $html ) {
        // Define allowed tags - only safe formatting, no scripts/events.
        $allowed_html = array(
            'p'      => array(
                'class' => array(),
                'style' => array(),
            ),
            'br'     => array(),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'u'      => array(),
            'span'   => array(
                'class' => array(),
                'style' => array(),
            ),
            'a'      => array(
                'href'   => array(),
                'title'  => array(),
                'target' => array(),
                'rel'    => array(),
                'class'  => array(),
            ),
            'ul'     => array( 'class' => array() ),
            'ol'     => array( 'class' => array() ),
            'li'     => array( 'class' => array() ),
        );

        // Use wp_kses with restricted tag set.
        $sanitized = wp_kses( $html, $allowed_html );

        // Additional filtering: remove any javascript: URLs.
        // Note: preg_replace returns null on error, fall back to previous value.
        $result = preg_replace( '/href\s*=\s*["\']?\s*javascript:/i', 'href="#', $sanitized );
        $sanitized = ( null !== $result ) ? $result : $sanitized;

        // Remove any data: URLs.
        $result = preg_replace( '/href\s*=\s*["\']?\s*data:/i', 'href="#', $sanitized );
        $sanitized = ( null !== $result ) ? $result : $sanitized;

        // Remove dangerous CSS expressions.
        $result = preg_replace( '/expression\s*\(/i', '', $sanitized );
        $sanitized = ( null !== $result ) ? $result : $sanitized;

        $result = preg_replace( '/url\s*\(/i', '', $sanitized );
        $sanitized = ( null !== $result ) ? $result : $sanitized;

        return $sanitized;
    }

    /**
     * Sanitize network-level settings.
     *
     * @param array $settings The settings to sanitize.
     * @return array Sanitized settings.
     */
    private static function sanitize_network_settings( $settings ) {
        // Handle API key encryption separately.
        if ( isset( $settings['openai_api_key'] ) && ! empty( $settings['openai_api_key'] ) ) {
            // Encrypt and store as openai_api_key_encrypted.
            $settings['openai_api_key_encrypted'] = self::encrypt_value( $settings['openai_api_key'] );
            unset( $settings['openai_api_key'] ); // Remove plain text version.
        }

        // Sanitize text fields.
        $text_fields = array(
            'openai_vector_store_id',
            'openai_model',
            'product_sync_schedule',
            'knowledge_sync_schedule',
        );

        foreach ( $text_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = sanitize_text_field( $settings[ $field ] );
            }
        }

        // Sanitize integer fields.
        $int_fields = array(
            'max_tokens_per_response',
            'max_messages_per_conversation',
            'conversation_expiry_days',
            'rate_limit_authenticated',
            'rate_limit_anonymous',
            'daily_token_limit',
            'monthly_token_limit',
            'product_sync_batch_size',
        );

        foreach ( $int_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $settings[ $field ] = absint( $settings[ $field ] );
            }
        }

        // Sanitize boolean fields.
        if ( isset( $settings['product_sync_enabled'] ) ) {
            $settings['product_sync_enabled'] = (bool) $settings['product_sync_enabled'];
        }

        return $settings;
    }

    /**
     * Delete all plugin settings.
     *
     * Used during uninstall.
     *
     * @return void
     */
    public static function delete_all() {
        delete_option( self::SITE_OPTION );

        if ( is_multisite() ) {
            delete_site_option( self::NETWORK_OPTION );
        }

        self::$cached_settings = null;
    }

    /**
     * Reset settings to defaults.
     *
     * @return bool True on success.
     */
    public static function reset_to_defaults() {
        delete_option( self::SITE_OPTION );
        self::$cached_settings = null;

        // Re-run activation to set defaults.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-activator.php';
        Glimmr_AI_Activator::activate();

        return true;
    }

    /**
     * Export settings as JSON.
     *
     * S16: Export Security - API keys excluded from export.
     *
     * @return string JSON encoded settings.
     */
    public static function export() {
        // S16: Filter sensitive data from export.
        $site_settings    = self::filter_sensitive_settings( self::get_site_raw() );
        $network_settings = self::filter_sensitive_settings( self::get_network() );

        $data = array(
            'site_settings'    => $site_settings,
            'network_settings' => $network_settings,
            'export_date'      => current_time( 'mysql' ),
            'version'          => GLIMMR_AI_VERSION,
            'note'             => 'API keys excluded for security. Re-enter after import.',
        );

        return wp_json_encode( $data, JSON_PRETTY_PRINT );
    }

    /**
     * Import settings from JSON.
     *
     * @param string $json The JSON string to import.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function import( $json ) {
        $data = json_decode( $json, true );

        if ( null === $data ) {
            return new WP_Error( 'invalid_json', __( 'Invalid JSON format.', 'glimmr-ai' ) );
        }

        if ( isset( $data['site_settings'] ) && is_array( $data['site_settings'] ) ) {
            self::update( $data['site_settings'] );
        }

        if ( is_multisite() && isset( $data['network_settings'] ) && is_array( $data['network_settings'] ) ) {
            $network_result = self::update_network( $data['network_settings'] );
            if ( is_wp_error( $network_result ) ) {
                return $network_result;
            }
        }

        return true;
    }

    // =========================================================================
    // Grouped Settings Helpers
    // =========================================================================

    /**
     * Get API configuration settings.
     *
     * @return array API config array.
     */
    public static function get_api_config() {
        return array(
            'timeout_base'       => (int) self::get( 'api_request_timeout_base', 90 ),
            'timeout_max'        => (int) self::get( 'api_request_timeout_max', 180 ),
            'upload_timeout'     => (int) self::get( 'api_upload_timeout', 300 ),
            'max_attempts'       => (int) self::get( 'retry_max_attempts', 3 ),
            'backoff_multiplier' => (float) self::get( 'retry_backoff_multiplier', 2 ),
            'initial_delay'      => (float) self::get( 'retry_initial_delay', 1 ),
            'max_delay'          => (float) self::get( 'retry_max_delay', 30 ),
        );
    }

    /**
     * Get context/token settings.
     *
     * @return array Context config array.
     */
    public static function get_context_config() {
        return array(
            'max_context_tokens'        => (int) self::get( 'max_context_tokens', 32000 ),
            'reserve_tokens'            => (int) self::get( 'context_reserve_tokens', 1000 ),
            'sliding_window_threshold'  => (int) self::get( 'messages_before_sliding_window', 10 ),
            'minimum_recent_messages'   => (int) self::get( 'minimum_recent_messages', 4 ),
            'chars_per_token'           => (int) self::get( 'token_estimation_chars_per_token', 4 ),
        );
    }

    /**
     * Get tool execution settings.
     *
     * @return array Tool config array.
     */
    public static function get_tool_config() {
        return array(
            'max_tool_rounds'           => (int) self::get( 'max_tool_execution_rounds', 5 ),
            'search_default_limit'      => (int) self::get( 'product_search_default_limit', 5 ),
            'search_max_limit'          => (int) self::get( 'product_search_max_limit', 10 ),
            'variations_max_return'     => (int) self::get( 'product_variations_max_return', 10 ),
            'gallery_max_return'        => (int) self::get( 'product_gallery_max_return', 5 ),
            'semantic_min_score'        => (float) self::get( 'semantic_min_score', 0.80 ),
        );
    }

    /**
     * Get rate limit settings.
     *
     * @return array Rate limit config array.
     */
    public static function get_rate_limit_config() {
        return array(
            'window_seconds'     => (int) self::get( 'rate_limit_window_seconds', 3600 ),
            'authenticated'      => (int) self::get( 'rate_limit_authenticated', 100 ),
            'anonymous'          => (int) self::get( 'rate_limit_anonymous', 20 ),
            'token_cost_million' => (float) self::get( 'token_cost_per_million', 5 ),
            'daily_cost_limit'   => (float) self::get( 'daily_cost_limit', 10 ),
            'monthly_cost_limit' => (float) self::get( 'monthly_cost_limit', 100 ),
            'daily_token_limit'  => (int) self::get( 'daily_token_limit', 100000 ),
            'monthly_token_limit' => (int) self::get( 'monthly_token_limit', 2000000 ),
        );
    }

    /**
     * Get logging settings.
     *
     * @return array Logging config array.
     */
    public static function get_logging_config() {
        return array(
            'log_level'            => self::get( 'log_level', 'warning' ),
            'log_ai_requests'      => (bool) self::get( 'log_ai_requests', false ),
            'log_tool_execution'   => (bool) self::get( 'log_tool_execution', true ),
            'log_vector_syncs'     => (bool) self::get( 'log_vector_store_syncs', true ),
            'error_response_style' => self::get( 'error_response_style', 'helpful' ),
        );
    }

    /**
     * Get HTTP client config for OpenAI requests.
     *
     * @return array HTTP client config.
     */
    public static function get_http_client_config() {
        $api_config = self::get_api_config();

        return array(
            'max_attempts'       => $api_config['max_attempts'],
            'base_timeout'       => $api_config['timeout_base'],
            'max_timeout'        => $api_config['timeout_max'],
            'backoff_multiplier' => $api_config['backoff_multiplier'],
            'initial_delay'      => $api_config['initial_delay'],
            'max_delay'          => $api_config['max_delay'],
            'retry_on_timeout'   => true,
            'retry_on_5xx'       => true,
            'retry_on_429'       => true,
        );
    }

    // =========================================================================
    // Slot-Filling Agent Settings
    // =========================================================================

    /**
     * Get the slot-filling system prompt.
     *
     * @return string The system prompt for slot-filling mode.
     */
    public static function get_slot_filling_prompt() {
        $default_prompt = self::get_default_slot_filling_prompt();
        return self::get( 'slot_filling_system_prompt', $default_prompt );
    }

    /**
     * Get the default slot-filling system prompt.
     *
     * @return string Default prompt.
     */
    public static function get_default_slot_filling_prompt() {
        return <<<'PROMPT'
You are a shopping assistant operating in SLOT-FILLING mode.

## Response Format

You must respond with a JSON object following the controller schema:

```json
{
  "action": "clarify" | "tool" | "final",
  "thought": "Your internal reasoning about the current state and next action",
  "workspace_updates": {
    "constraints": { "category": "...", "size": "...", "price_range": "..." },
    "candidates": [product_ids...],
    "shortlist": [product_ids...]
  },
  "tool_call": { "name": "tool_name", "arguments": {...} },
  "user_message": "Message shown to the user"
}
```

## Actions

- **clarify**: Ask the user a question. Requires `user_message`. STOP and wait for user response.
- **tool**: Execute a tool. Requires `tool_call` with `name` and `arguments`. Loop continues after tool result.
- **final**: Provide final response to user. Requires `user_message`. STOP.

## Key Capabilities

**Product Discovery:**
- query_products: Search with category, price, size, color filters
- recommendations: Get personalized product suggestions

**Cart Management:**
- add_to_cart: Add products (with variations) to cart
- view_cart: Show current cart contents
- update_cart: Change quantities or remove items
- apply_coupon: Apply discount codes
- checkout_link: Send customer to checkout

**Order Management:**
- order_status: Track a specific order
- order_history: View past orders (logged-in only)
- reorder: Quickly add all items from a previous order to cart (logged-in only)

**Information:**
- site_knowledge: Answer policy/shipping/contact questions
- account_info: Customer account details

## Slot-Filling Process

1. **GATHER CONSTRAINTS**: Before searching, collect: category, price_range, size, color, use_case
2. **SEARCH WITH CONSTRAINTS**: When you have category + one other constraint, use query_products
3. **SHORTLIST**: When <10 candidates, select top 3-5 and present

## Reorder Flow

When a logged-in customer wants to reorder from a previous order:
1. If they mention a specific order number, use `reorder` with that order_id
2. If they just say "reorder" or "buy again", first use `order_history` to show recent orders
3. Then use `reorder` with their chosen order_id

## CRITICAL STOPPING RULES

1. **Stop-after-clarify**: When action="clarify", STOP and wait for user response
2. **Max 3 tools per turn**: After 3 tool calls, must output clarify or final
3. **Max 5 rounds**: After 5 rounds total, must output final
4. **No duplicate tool calls**: Skip if same tool+args already called
5. **Fallback Rule**: If first search returns 0, try ONE broader search, then clarify

## Current Workspace State

{workspace_state}
PROMPT;
    }
}
