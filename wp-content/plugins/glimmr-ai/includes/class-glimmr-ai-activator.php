<?php
/**
 * Fired during plugin activation.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Activator
 *
 * This class defines all code necessary to run during the plugin's activation.
 */
class Glimmr_AI_Activator {

    /**
     * Activate the plugin.
     *
     * Creates database tables, sets default options, and schedules cron jobs.
     *
     * @since 1.0.0
     * @return void
     */
    public static function activate() {
        // Load database class.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-database.php';

        // Create database tables.
        Glimmr_AI_Database::create_tables();

        // Set default options.
        self::set_default_options();

        // Schedule cron jobs.
        self::schedule_cron_jobs();

        // Set activation flag for welcome screen.
        set_transient( 'glimmr_ai_activation_redirect', true, 30 );

        // Clear any cached data.
        wp_cache_flush();
    }

    /**
     * Set default plugin options.
     *
     * @since 1.0.0
     * @return void
     */
    private static function set_default_options() {
        // Site-level defaults.
        $site_defaults = array(
            'inherit_network_settings'   => true,
            'openai_api_key'             => '',
            'openai_vector_store_id'     => '',
            'openai_model'               => 'gpt-4o',
            'max_tokens_per_response'    => 1000,
            'max_messages_per_conversation' => 50,
            'conversation_expiry_days'   => 30,
            'rate_limit_authenticated'   => 100,
            'rate_limit_anonymous'       => 20,
            'daily_token_limit'          => 100000,
            'monthly_token_limit'        => 2000000,
            'product_sync_enabled'       => true,
            'product_sync_schedule'      => '03:00',
            'product_sync_batch_size'    => 100,
            'knowledge_sync_schedule'    => '03:30',

            // API & Timeout Settings (new).
            'api_request_timeout_base'   => 90,
            'api_request_timeout_max'    => 180,
            'api_upload_timeout'         => 300,
            'retry_max_attempts'         => 3,
            'retry_backoff_multiplier'   => 2,
            'retry_initial_delay'        => 1,
            'retry_max_delay'            => 30,

            // Content Moderation (v1.7.0).
            'moderation_enabled'         => true,  // Filter messages via OpenAI Moderation API.

            // Token & Context Settings (new).
            'max_context_tokens'         => 32000,
            'context_reserve_tokens'     => 1000,
            'messages_before_sliding_window' => 10,
            'minimum_recent_messages'    => 4,
            'token_estimation_chars_per_token' => 4,

            // Tool Execution Settings (new).
            'max_tool_execution_rounds'  => 5,
            'product_search_default_limit' => 5,
            'product_search_max_limit'   => 10,
            'product_variations_max_return' => 10,
            'product_gallery_max_return' => 5,

            // Rate Limit Settings (new).
            'rate_limit_window_seconds'  => 3600,
            'token_cost_per_million'     => 5,
            'daily_cost_limit'           => 10,
            'monthly_cost_limit'         => 100,

            // Vector Store Settings (new).
            'vector_store_sync_batch_size' => 100,
            'vector_store_fallback_timeout' => 5,
            'vectorize_products'         => true,
            'vectorize_pages'            => true,
            'vectorize_posts'            => false,

            // Error & Logging Settings (new).
            'error_response_style'       => 'helpful',
            'log_level'                  => 'warning',
            'log_ai_requests'            => false,
            'log_tool_execution'         => true,
            'log_vector_store_syncs'     => true,

            // Context Building Settings (new).
            'include_user_context'       => true,
            'include_order_history'      => true,
            'max_orders_in_context'      => 1,
            'include_cart_context'       => true,
            'anonymize_customer_data'    => false,

            // Trusted Proxy Settings (new).
            'trusted_proxies'            => array(),
            'widget_enabled'             => true,
            'widget_position'            => 'bottom-right',
            'widget_primary_color'       => '#4F46E5',
            'widget_secondary_color'     => '#818CF8',
            'widget_text_color'          => '#FFFFFF',
            'widget_font_family'         => 'inherit',
            'widget_header_logo_url'     => '',
            'widget_header_logo_max_width' => 120,
            'widget_header_logo_max_height' => 32,
            'widget_avatar_url'          => '',
            'widget_name'                => 'Shopping Assistant',
            'widget_title_font_size'     => 16,
            'widget_title_font_weight'   => '600',
            'widget_greeting'            => '<p>Hi! How can I help you today?</p>',
            'widget_quick_replies'       => array(
                array(
                    'text'   => 'Track my order',
                    'action' => 'Track my order status',
                ),
                array(
                    'text'   => 'Find products',
                    'action' => 'Help me find a product',
                ),
                array(
                    'text'   => 'Shipping info',
                    'action' => 'What are your shipping options?',
                ),
            ),
            'widget_include_pages'       => array(),
            'widget_exclude_pages'       => array( '/checkout', '/cart' ),
            'system_prompt'              => self::get_default_system_prompt(),
            'agent_tone'                 => 'friendly',
            'agent_personality'          => '',
            'fallback_response'          => "I'm not sure about that. Would you like me to help you find something else, or would you prefer to contact our support team?",
            'enabled_tools'              => array(
                'text_answer'      => true,
                'coupon_lookup'    => true,
                'order_status'     => true,
                'order_history'    => true,
                'reorder'          => true,  // v1.6.0: One-click reorder from previous orders.
                'site_knowledge'   => true,
                'add_to_cart'      => true,
                'view_cart'        => true,
                'update_cart'      => true,
                'apply_coupon'     => true,
                'checkout_link'    => true,
                'recommendations'  => true,
                'account_info'     => true,
                'query_products'   => true,
                'sql_readonly'     => false, // Off by default - advanced feature.
            ),

            // Agent Loop Configuration.
            'max_agent_rounds'              => 5,     // Max rounds per user message.
            'max_tools_per_turn'            => 3,     // Max tool calls per AI turn.
            'coupon_visibility'          => 'public',
            'visible_coupons'            => array(),
            'product_index_mode'         => 'all',
            'product_include_categories' => array(),
            'product_exclude_categories' => array(),
            'product_include_ids'        => array(),
            'product_exclude_ids'        => array(),
            'gdpr_enabled'               => true,
            'gdpr_consent_text'          => 'By chatting, you agree to our privacy policy.',
            'gdpr_delete_on_revoke'      => false,
            'data_retention_days'        => 365,

            // Proactive Engagement Triggers.
            'proactive_time_enabled'     => false,
            'proactive_time_delay'       => 30,  // seconds
            'proactive_time_message'     => 'Hi there! Need help finding anything?',
            'proactive_time_pages'       => array( 'product', 'category', 'shop' ),
            'proactive_exit_enabled'     => false,
            'proactive_exit_message'     => 'Wait! Before you go, is there anything I can help you with?',
            'proactive_exit_pages'       => array( 'cart', 'product' ),
            'proactive_exit_once_per_session' => true,
            'proactive_scroll_enabled'   => false,
            'proactive_scroll_percent'   => 50,  // Trigger at 50% scroll
            'proactive_scroll_message'   => 'Enjoying what you see? Let me help you find the perfect item!',
            'proactive_scroll_pages'     => array( 'product', 'category' ),

            // Artifact Display Settings.
            'artifact_grid_columns'              => '2',
            'artifact_grid_card_style'           => 'detailed',
            'artifact_grid_show_rating'          => true,
            'artifact_grid_show_stock'           => true,
            'artifact_comparison_layout'         => 'table',
            'artifact_comparison_highlight_best' => true,
            'artifact_comparison_max_products'   => 4,
            'artifact_modal_image_style'         => 'gallery',
            'artifact_modal_show_reviews'        => true,
            'artifact_order_show_timeline'       => true,
            'artifact_order_timeline_style'      => 'horizontal',
            'artifact_order_show_items'          => true,
            'artifact_history_max_display'       => 5,
            'artifact_history_show_thumbnails'   => true,
            'artifact_cart_inline_quantity'      => true,
            'artifact_cart_show_savings'         => true,
            'artifact_cart_coupon_input'         => true,
            'artifact_coupon_style'              => 'ticket',
            'artifact_coupon_show_expiry'        => true,
            'artifact_coupon_apply_button'       => true,
            'artifact_carousel_items_visible'    => 3,
            'artifact_carousel_auto_scroll'      => false,
            'artifact_carousel_show_reason'      => true,
            'artifact_account_show_loyalty'      => true,
            'artifact_account_mask_email'        => true,
            'artifact_knowledge_show_sources'    => true,
            'artifact_knowledge_max_sources'     => 3,

            // Google Analytics 4 Integration (v1.8.0).
            'ga4_enabled'                        => false,
            'ga4_measurement_id'                 => '',
            'ga4_track_widget_open'              => true,
            'ga4_track_messages'                 => true,
            'ga4_track_products'                 => true,
            'ga4_track_cart'                     => true,
            'ga4_track_checkout'                 => true,

            // Reviews Integration (v1.8.0).
            'reviews_enabled'                    => true,
            'reviews_count'                      => 3,
            'reviews_min_rating'                 => 0,

            // SEO Integration (v1.8.0).
            'seo_integration_enabled'            => false,
            'seo_faq_schema'                     => true,
            'seo_index_knowledge'                => true,
        );

        // Only set options if they don't exist.
        if ( false === get_option( 'glimmr_ai_settings' ) ) {
            update_option( 'glimmr_ai_settings', $site_defaults );
        }

        // For multisite, set network defaults.
        if ( is_multisite() ) {
            $network_defaults = array(
                'openai_api_key'              => '',
                'openai_vector_store_id'      => '',
                'openai_model'                => 'gpt-4o',
                'max_tokens_per_response'     => 1000,
                'max_messages_per_conversation' => 50,
                'conversation_expiry_days'    => 30,
                'rate_limit_authenticated'    => 100,
                'rate_limit_anonymous'        => 20,
                'daily_token_limit'           => 100000,
                'monthly_token_limit'         => 2000000,
                'product_sync_enabled'        => true,
                'product_sync_schedule'       => '03:00',
                'product_sync_batch_size'     => 100,
                'knowledge_sync_schedule'     => '03:30',
            );

            if ( false === get_site_option( 'glimmr_ai_network_settings' ) ) {
                update_site_option( 'glimmr_ai_network_settings', $network_defaults );
            }
        }
    }

    /**
     * Get the default system prompt.
     *
     * @since 1.0.0
     * @return string
     */
    private static function get_default_system_prompt() {
        return 'You are a helpful shopping assistant for {site_name}. Your role is to help customers find products, manage their shopping cart, track orders, and assist with any shopping-related inquiries.

## Your Capabilities

**Product Discovery:**
- Search and browse products by category, price, size, color, or keywords
- Compare products side-by-side
- Check stock availability
- Provide personalized recommendations

**Cart & Checkout:**
- Add products to cart (including variations like size/color)
- View and update cart contents
- Apply discount codes and coupons
- Guide customers to checkout

**Orders & Account:**
- Track order status and shipping
- View order history
- Quickly reorder items from previous orders (for logged-in customers)
- Provide account information

**Store Information:**
- Answer questions about shipping, returns, and policies
- Provide contact information and support options

## Guidelines

- Be friendly, helpful, and concise
- When showing products, highlight key features and pricing
- For orders, verify customer identity before sharing details
- If you cannot help with something, offer to connect them with support
- Proactively suggest relevant products or actions when appropriate

## Current Context

- Customer: {customer_name} ({is_logged_in})
- Cart: {cart_summary}
- Store: {site_name} ({site_url})';
    }

    /**
     * Schedule cron jobs.
     *
     * @since 1.0.0
     * @return void
     */
    private static function schedule_cron_jobs() {
        // Load required classes.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-settings.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-cron.php';

        // Use the Cron class to schedule events.
        $settings = new Glimmr_AI_Settings();
        $cron = new Glimmr_AI_Cron( $settings );
        $cron->schedule_events();
    }
}
