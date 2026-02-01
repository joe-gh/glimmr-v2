<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Public
 *
 * Handles all frontend functionality including the chat widget.
 */
class Glimmr_AI_Public {

    /**
     * Initialize the class.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Constructor.
    }

    /**
     * Register the stylesheets for the public-facing side.
     *
     * @since 1.0.0
     * @return void
     */
    public function enqueue_styles() {
        // Only load if widget should be displayed.
        if ( ! Glimmr_AI_Settings::should_display_widget() ) {
            return;
        }

        // Load bundled widget styles (compiled from SCSS).
        $css_file = 'public/js/glimmr-ai-widget-bundle.css';
        if ( file_exists( GLIMMR_AI_PLUGIN_DIR . $css_file ) ) {
            wp_enqueue_style(
                'glimmr-ai-widget',
                GLIMMR_AI_PLUGIN_URL . $css_file,
                array(),
                GLIMMR_AI_VERSION,
                'all'
            );
        }
    }

    /**
     * Register the JavaScript for the public-facing side.
     *
     * @since 1.0.0
     * @return void
     */
    public function enqueue_scripts() {
        // Only load if widget should be displayed.
        if ( ! Glimmr_AI_Settings::should_display_widget() ) {
            return;
        }

        // Load bundled Preact widget.
        $js_file = 'public/js/glimmr-ai-widget-bundle.js';
        if ( file_exists( GLIMMR_AI_PLUGIN_DIR . $js_file ) ) {
            wp_enqueue_script(
                'glimmr-ai-widget',
                GLIMMR_AI_PLUGIN_URL . $js_file,
                array(),
                GLIMMR_AI_VERSION,
                true
            );
        }

        // Localize script with configuration.
        wp_localize_script(
            'glimmr-ai-widget',
            'glimmrAIWidget',
            $this->get_widget_config()
        );
    }

    /**
     * Get widget configuration for frontend.
     *
     * S3: Only expose safe, whitelisted settings to the frontend.
     * Never expose API keys, internal IDs, or sensitive configuration.
     *
     * @return array
     */
    private function get_widget_config() {
        // S3: Whitelist of safe settings to expose to frontend.
        $safe_widget_settings = array(
            // Widget state.
            'enabled'           => Glimmr_AI_Settings::get( 'widget_enabled', true ),
            'debugMode'         => Glimmr_AI_Settings::get( 'widget_debug_mode', false ),
            'position'          => Glimmr_AI_Settings::get( 'widget_position', 'bottom-right' ),

            // Brand colors.
            'primaryColor'      => Glimmr_AI_Settings::get( 'widget_primary_color', '#4F46E5' ),
            'primaryHover'      => Glimmr_AI_Settings::get( 'widget_primary_hover', '#4338CA' ),
            'secondaryColor'    => Glimmr_AI_Settings::get( 'widget_secondary_color', '#818CF8' ),

            // Background & surface colors.
            'bgColor'           => Glimmr_AI_Settings::get( 'widget_bg_color', '#FFFFFF' ),
            'bgLight'           => Glimmr_AI_Settings::get( 'widget_bg_light', '#F3F4F6' ),
            'borderColor'       => Glimmr_AI_Settings::get( 'widget_border_color', '#E5E7EB' ),

            // Text colors.
            'textColor'         => Glimmr_AI_Settings::get( 'widget_text_color', '#FFFFFF' ),
            'textDark'          => Glimmr_AI_Settings::get( 'widget_text_dark', '#1F2937' ),
            'textMuted'         => Glimmr_AI_Settings::get( 'widget_text_muted', '#6B7280' ),

            // Status colors.
            'successColor'      => Glimmr_AI_Settings::get( 'widget_success_color', '#059669' ),
            'errorColor'        => Glimmr_AI_Settings::get( 'widget_error_color', '#DC2626' ),

            // Button style.
            'buttonBorder'      => Glimmr_AI_Settings::get( 'widget_button_border', 'transparent' ),
            'buttonBorderWidth' => Glimmr_AI_Settings::get( 'widget_button_border_width', 0 ),
            'borderRadius'      => Glimmr_AI_Settings::get( 'widget_border_radius', 16 ),

            // Widget dimensions.
            'width'             => Glimmr_AI_Settings::get( 'widget_width', 400 ),
            'height'            => Glimmr_AI_Settings::get( 'widget_height', 650 ),

            // Widget positioning.
            'offsetX'           => Glimmr_AI_Settings::get( 'widget_offset_x', 20 ),
            'offsetY'           => Glimmr_AI_Settings::get( 'widget_offset_y', 20 ),
            'zIndex'            => Glimmr_AI_Settings::get( 'widget_z_index', 999999 ),

            // Branding.
            'fontFamily'        => Glimmr_AI_Settings::get( 'widget_font_family', 'inherit' ),
            'headerLogoUrl'     => Glimmr_AI_Settings::get( 'widget_header_logo_url', '' ),
            'headerLogoMaxWidth' => Glimmr_AI_Settings::get( 'widget_header_logo_max_width', 120 ),
            'headerLogoMaxHeight' => Glimmr_AI_Settings::get( 'widget_header_logo_max_height', 32 ),
            'avatarUrl'         => Glimmr_AI_Settings::get( 'widget_avatar_url', '' ),
            'name'              => Glimmr_AI_Settings::get( 'widget_name', 'Shopping Assistant' ),
            'titleFontSize'     => Glimmr_AI_Settings::get( 'widget_title_font_size', 16 ),
            'titleFontWeight'   => Glimmr_AI_Settings::get( 'widget_title_font_weight', '600' ),
            'greeting'          => Glimmr_AI_Settings::get( 'widget_greeting', '<p>Hi! How can I help you today?</p>' ),
            'quickReplies'      => Glimmr_AI_Settings::get( 'widget_quick_replies', array() ),

            // GDPR.
            'gdprEnabled'       => Glimmr_AI_Settings::get( 'gdpr_enabled', true ),
            'gdprText'          => Glimmr_AI_Settings::get( 'gdpr_consent_text', 'By chatting, you agree to our privacy policy.' ),

            // Artifact display settings.
            'artifacts'         => array(
                // Product grid.
                'gridColumns'            => Glimmr_AI_Settings::get( 'artifact_grid_columns', '2' ),
                'gridCardStyle'          => Glimmr_AI_Settings::get( 'artifact_grid_card_style', 'detailed' ),
                'gridShowRating'         => Glimmr_AI_Settings::get( 'artifact_grid_show_rating', true ),
                'gridShowStock'          => Glimmr_AI_Settings::get( 'artifact_grid_show_stock', true ),
                // Comparison table.
                'comparisonLayout'       => Glimmr_AI_Settings::get( 'artifact_comparison_layout', 'table' ),
                'comparisonHighlightBest' => Glimmr_AI_Settings::get( 'artifact_comparison_highlight_best', true ),
                'comparisonMaxProducts'  => Glimmr_AI_Settings::get( 'artifact_comparison_max_products', 4 ),
                // Product modal.
                'modalImageStyle'        => Glimmr_AI_Settings::get( 'artifact_modal_image_style', 'gallery' ),
                'modalShowReviews'       => Glimmr_AI_Settings::get( 'artifact_modal_show_reviews', true ),
                // Order display.
                'orderShowTimeline'      => Glimmr_AI_Settings::get( 'artifact_order_show_timeline', true ),
                'orderTimelineStyle'     => Glimmr_AI_Settings::get( 'artifact_order_timeline_style', 'horizontal' ),
                'orderShowItems'         => Glimmr_AI_Settings::get( 'artifact_order_show_items', true ),
                'historyMaxDisplay'      => Glimmr_AI_Settings::get( 'artifact_history_max_display', 5 ),
                'historyShowThumbnails'  => Glimmr_AI_Settings::get( 'artifact_history_show_thumbnails', true ),
                // Cart & checkout.
                'cartInlineQuantity'     => Glimmr_AI_Settings::get( 'artifact_cart_inline_quantity', true ),
                'cartShowSavings'        => Glimmr_AI_Settings::get( 'artifact_cart_show_savings', true ),
                'cartCouponInput'        => Glimmr_AI_Settings::get( 'artifact_cart_coupon_input', true ),
                // Coupons.
                'couponStyle'            => Glimmr_AI_Settings::get( 'artifact_coupon_style', 'ticket' ),
                'couponShowExpiry'       => Glimmr_AI_Settings::get( 'artifact_coupon_show_expiry', true ),
                'couponApplyButton'      => Glimmr_AI_Settings::get( 'artifact_coupon_apply_button', true ),
                // Carousel.
                'carouselItemsVisible'   => Glimmr_AI_Settings::get( 'artifact_carousel_items_visible', 3 ),
                'carouselAutoScroll'     => Glimmr_AI_Settings::get( 'artifact_carousel_auto_scroll', false ),
                'carouselShowReason'     => Glimmr_AI_Settings::get( 'artifact_carousel_show_reason', true ),
                // Account.
                'accountShowLoyalty'     => Glimmr_AI_Settings::get( 'artifact_account_show_loyalty', true ),
                'accountMaskEmail'       => Glimmr_AI_Settings::get( 'artifact_account_mask_email', true ),
                // Knowledge.
                'knowledgeShowSources'   => Glimmr_AI_Settings::get( 'artifact_knowledge_show_sources', true ),
                'knowledgeMaxSources'    => Glimmr_AI_Settings::get( 'artifact_knowledge_max_sources', 3 ),
                'knowledgeShowConfidence' => Glimmr_AI_Settings::get( 'artifact_knowledge_show_confidence', true ),
                'knowledgeShowCategory'  => Glimmr_AI_Settings::get( 'artifact_knowledge_show_category', true ),
                'knowledgeCollapsibleSources' => Glimmr_AI_Settings::get( 'artifact_knowledge_collapsible_sources', true ),
            ),

            // Attribute label translations.
            'attributeTranslations' => $this->get_attribute_translations(),
        );

        // Sanitize quick replies to ensure no malicious content.
        if ( ! empty( $safe_widget_settings['quickReplies'] ) && is_array( $safe_widget_settings['quickReplies'] ) ) {
            $safe_widget_settings['quickReplies'] = array_map(
                function( $reply ) {
                    return array(
                        'text'   => esc_html( $reply['text'] ?? '' ),
                        'action' => esc_html( $reply['action'] ?? '' ),
                    );
                },
                $safe_widget_settings['quickReplies']
            );
        }

        // Build final config with safe settings plus runtime data.
        $config = $safe_widget_settings;

        // Add API endpoints (safe - these are public REST endpoints).
        $config['apiEndpoint'] = rest_url( 'glimmr-ai/v1/chat/message' );
        $config['streamEndpoint'] = rest_url( 'glimmr-ai/v1/chat/stream' );
        $config['historyEndpoint'] = rest_url( 'glimmr-ai/v1/chat/history' );
        $config['flagEndpoint'] = rest_url( 'glimmr-ai/v1/chat/flag' );
        $config['cartAddEndpoint'] = rest_url( 'glimmr-ai/v1/cart/add' );
        $config['nonce'] = wp_create_nonce( 'wp_rest' );
        $config['storeApiNonce'] = wp_create_nonce( 'wc_store_api' );

        // Enable streaming for real-time status updates.
        $config['streamingEnabled'] = true;

        // Add user context (safe - no sensitive data).
        $config['isLoggedIn'] = is_user_logged_in();
        $config['userName'] = '';

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            $config['userName'] = esc_html( $user->display_name ?: $user->user_login );
        }

        // Add cart info if WooCommerce is active (safe - public data).
        if ( class_exists( 'WooCommerce' ) && WC()->cart ) {
            $config['cartCount'] = WC()->cart->get_cart_contents_count();
            $config['cartTotal'] = WC()->cart->get_cart_total();
        }

        return $config;
    }

    /**
     * Render the chat widget container.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_chat_widget() {
        // Only render if widget should be displayed.
        if ( ! Glimmr_AI_Settings::should_display_widget() ) {
            return;
        }

        // Output widget container - the actual widget is rendered by JavaScript.
        ?>
        <div id="glimmr-ai-chat-widget" class="glimmr-ai-widget-container"></div>
        <?php
    }

    /**
     * Get attribute translations for the widget.
     *
     * Converts the array of {key, label} objects to a simple key => label map.
     *
     * @since 1.0.0
     * @return array Associative array of attribute key => display label.
     */
    private function get_attribute_translations() {
        // Default translations.
        $defaults = array(
            'pa_color'     => 'Color',
            'pa_colour'    => 'Color',
            'pa_size'      => 'Size',
            'pa_material'  => 'Material',
            'pa_style'     => 'Style',
            'pa_brand'     => 'Brand',
            'pa_weight'    => 'Weight',
            'pa_length'    => 'Length',
            'pa_width'     => 'Width',
            'pa_height'    => 'Height',
            'pa_pattern'   => 'Pattern',
            'pa_fabric'    => 'Fabric',
            'pa_fit'       => 'Fit',
            'pa_gender'    => 'Gender',
            'pa_age-group' => 'Age Group',
            'pa_capacity'  => 'Capacity',
            'pa_voltage'   => 'Voltage',
            'pa_wattage'   => 'Wattage',
            'pa_finish'    => 'Finish',
            'pa_shape'     => 'Shape',
            'pa_scent'     => 'Scent',
            'pa_flavor'    => 'Flavor',
            'pa_flavour'   => 'Flavor',
            'pa_model'     => 'Model',
            'pa_edition'   => 'Edition',
            'pa_type'      => 'Type',
            'pa_variant'   => 'Variant',
            'pa_pack-size' => 'Pack Size',
            'pa_quantity'  => 'Quantity',
        );

        // Get custom translations from settings.
        $custom_translations = Glimmr_AI_Settings::get( 'attribute_translations', array() );

        // Convert array format to key => label map if needed.
        if ( ! empty( $custom_translations ) && is_array( $custom_translations ) ) {
            // Check if it's in the new format [{key, label}, ...].
            if ( isset( $custom_translations[0] ) && is_array( $custom_translations[0] ) ) {
                $translations = array();
                foreach ( $custom_translations as $item ) {
                    if ( ! empty( $item['key'] ) && ! empty( $item['label'] ) ) {
                        $translations[ strtolower( $item['key'] ) ] = $item['label'];
                    }
                }
                return $translations;
            }
            // Already in key => label format.
            return $custom_translations;
        }

        return $defaults;
    }
}
