<?php
/**
 * Tool Registry
 *
 * Manages registration and execution of all AI tools.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Registry
 *
 * Central registry for all AI tools. Handles tool loading,
 * registration, and execution.
 */
class Glimmr_AI_Tool_Registry {

    /**
     * Registered tools.
     *
     * @var Glimmr_AI_Tool_Base[]
     */
    private $tools = array();

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    private $settings;

    /**
     * Database instance.
     *
     * @var Glimmr_AI_Database
     */
    private $database;

    /**
     * OpenAI client.
     *
     * @var Glimmr_AI_OpenAI
     */
    private $openai;

    /**
     * Singleton instance.
     *
     * @var Glimmr_AI_Tool_Registry|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_OpenAI   $openai   OpenAI client.
     * @return Glimmr_AI_Tool_Registry
     */
    public static function get_instance( $settings = null, $database = null, $openai = null ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $settings, $database, $openai );
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_OpenAI   $openai   OpenAI client.
     */
    private function __construct( $settings = null, $database = null, $openai = null ) {
        $this->settings = $settings ?? new Glimmr_AI_Settings();
        $this->database = $database ?? new Glimmr_AI_Database();
        $this->openai   = $openai;

        $this->load_tools();
        $this->register_default_tools();
    }

    /**
     * Load tool class files.
     */
    private function load_tools() {
        $tools_dir = GLIMMR_AI_PLUGIN_DIR . 'includes/tools/';

        // Base class must be loaded first.
        require_once $tools_dir . 'class-tool-base.php';

        // Load all other tool files.
        $tool_files = array(
            // Information tools.
            'class-tool-text-answer.php',
            'class-tool-site-knowledge.php',

            // Product tools.
            'class-tool-query-products.php',
            'class-tool-select-products.php',
            'class-tool-recommendations.php',

            // Resolver tools.
            'class-tool-resolve-product.php',
            'class-tool-resolve-variation.php',
            'class-tool-resolve-cart-item.php',
            'class-tool-resolve-order.php',

            // Coupon tools.
            'class-tool-coupon-lookup.php',
            'class-tool-apply-coupon.php',

            // Order tools.
            'class-tool-order-status.php',
            'class-tool-order-history.php',
            'class-tool-reorder.php',

            // Cart tools.
            'class-tool-add-to-cart.php',
            'class-tool-view-cart.php',
            'class-tool-update-cart.php',
            'class-tool-checkout-link.php',

            // Account tools.
            'class-tool-account-info.php',

            // Navigation tool.
            'class-tool-navigate.php',

            // Advanced tools.
            'class-tool-sql-readonly.php',

            // Gift card and tracking tools.
            'class-tool-check-gift-card-balance.php',
            'class-tool-track-package.php',

            // Review tools.
            'class-tool-get-reviews.php',
            'class-tool-summarize-reviews.php',

            // Contact tool.
            'class-tool-contact-request.php',
        );

        foreach ( $tool_files as $file ) {
            $path = $tools_dir . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    }

    /**
     * Register default tools.
     */
    private function register_default_tools() {
        // Text answer tool (needs OpenAI client).
        $text_answer = new Glimmr_AI_Tool_Text_Answer( $this->settings, $this->database, $this->openai );
        $this->register( $text_answer );

        // Site knowledge tool.
        $this->register( new Glimmr_AI_Tool_Site_Knowledge( $this->settings, $this->database ) );

        // Product tools (unified).
        $this->register( new Glimmr_AI_Tool_Query_Products( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Select_Products( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Recommendations( $this->settings, $this->database ) );

        // Resolver tools (bridge ambiguity before actions).
        $this->register( new Glimmr_AI_Tool_Resolve_Product( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Resolve_Variation( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Resolve_Cart_Item( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Resolve_Order( $this->settings, $this->database ) );

        // Coupon tools.
        $this->register( new Glimmr_AI_Tool_Coupon_Lookup( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Apply_Coupon( $this->settings, $this->database ) );

        // Order tools.
        $this->register( new Glimmr_AI_Tool_Order_Status( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Order_History( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Reorder( $this->settings, $this->database ) );

        // Cart tools.
        $this->register( new Glimmr_AI_Tool_Add_To_Cart( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_View_Cart( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Update_Cart( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Checkout_Link( $this->settings, $this->database ) );

        // Account tool.
        $this->register( new Glimmr_AI_Tool_Account_Info( $this->settings, $this->database ) );

        // Navigation tool.
        $this->register( new Glimmr_AI_Tool_Navigate( $this->settings, $this->database ) );

        // Advanced tools.
        $this->register( new Glimmr_AI_Tool_SQL_Readonly( $this->settings, $this->database ) );

        // Gift card and tracking tools.
        $this->register( new Glimmr_AI_Tool_Check_Gift_Card_Balance( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Track_Package( $this->settings, $this->database ) );

        // Review tools.
        $this->register( new Glimmr_AI_Tool_Get_Reviews( $this->settings, $this->database ) );
        $this->register( new Glimmr_AI_Tool_Summarize_Reviews( $this->settings, $this->database ) );

        // Contact tool.
        $this->register( new Glimmr_AI_Tool_Contact_Request( $this->settings, $this->database ) );

        /**
         * Fires after default tools are registered.
         *
         * @param Glimmr_AI_Tool_Registry $registry The tool registry.
         */
        do_action( 'glimmr_ai_register_tools', $this );
    }

    /**
     * Set OpenAI client.
     *
     * Used for tools that need the OpenAI client (like text_answer).
     *
     * @param Glimmr_AI_OpenAI $openai OpenAI client.
     */
    public function set_openai( $openai ) {
        $this->openai = $openai;

        // Update text_answer tool.
        if ( isset( $this->tools['text_answer'] ) && method_exists( $this->tools['text_answer'], 'set_openai' ) ) {
            $this->tools['text_answer']->set_openai( $openai );
        }
    }

    /**
     * Register a tool.
     *
     * @param Glimmr_AI_Tool_Base $tool Tool instance.
     * @return bool Whether registration was successful.
     */
    public function register( Glimmr_AI_Tool_Base $tool ) {
        $name = $tool->get_name();

        if ( isset( $this->tools[ $name ] ) ) {
            // Tool already registered.
            return false;
        }

        $this->tools[ $name ] = $tool;
        return true;
    }

    /**
     * Unregister a tool.
     *
     * @param string $name Tool name.
     * @return bool Whether unregistration was successful.
     */
    public function unregister( $name ) {
        if ( ! isset( $this->tools[ $name ] ) ) {
            return false;
        }

        unset( $this->tools[ $name ] );
        return true;
    }

    /**
     * Get a tool by name.
     *
     * @param string $name Tool name.
     * @return Glimmr_AI_Tool_Base|null Tool instance or null.
     */
    public function get( $name ) {
        return $this->tools[ $name ] ?? null;
    }

    /**
     * Check if a tool is registered.
     *
     * @param string $name Tool name.
     * @return bool Whether tool is registered.
     */
    public function has( $name ) {
        return isset( $this->tools[ $name ] );
    }

    /**
     * Get all registered tools.
     *
     * @return Glimmr_AI_Tool_Base[] Array of tools.
     */
    public function get_all() {
        return $this->tools;
    }

    /**
     * Get all enabled tools.
     *
     * @return Glimmr_AI_Tool_Base[] Array of enabled tools.
     */
    public function get_enabled() {
        return array_filter( $this->tools, function( $tool ) {
            return $tool->is_enabled();
        } );
    }

    /**
     * Get tool definitions for OpenAI API.
     *
     * Returns tool definitions in the format required by
     * the OpenAI API for function calling.
     *
     * @param bool $enabled_only Only include enabled tools.
     * @return array Tool definitions.
     */
    public function get_definitions( $enabled_only = true ) {
        $tools = $enabled_only ? $this->get_enabled() : $this->tools;
        $definitions = array();

        foreach ( $tools as $tool ) {
            $definitions[] = $tool->get_definition();
        }

        return $definitions;
    }

    /**
     * Get file_search tool configuration if available.
     *
     * This is used to include the file_search tool for RAG.
     *
     * @return array|null File search config or null.
     */
    public function get_file_search_config() {
        $text_answer = $this->get( 'text_answer' );
        if ( $text_answer && method_exists( $text_answer, 'get_file_search_config' ) ) {
            return $text_answer->get_file_search_config();
        }
        return null;
    }

    /**
     * Execute a tool by name.
     *
     * @param string $name      Tool name.
     * @param array  $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $name, $arguments = array() ) {
        $tool = $this->get( $name );

        if ( ! $tool ) {
            return array(
                'success' => false,
                'error'   => 'tool_not_found',
                'message' => sprintf( __( 'Tool "%s" not found.', 'glimmr-ai' ), $name ),
            );
        }

        if ( ! $tool->is_enabled() ) {
            return array(
                'success' => false,
                'error'   => 'tool_disabled',
                'message' => sprintf( __( 'Tool "%s" is currently disabled.', 'glimmr-ai' ), $name ),
            );
        }

        try {
            /**
             * Fires before a tool is executed.
             *
             * @param string $name      Tool name.
             * @param array  $arguments Tool arguments.
             */
            do_action( 'glimmr_ai_before_tool_execute', $name, $arguments );

            $result = $tool->execute( $arguments );

            // Normalize string results to array format.
            if ( is_string( $result ) ) {
                $result = array( 'message' => $result );
            }

            /**
             * Fires after a tool is executed.
             *
             * @param string $name      Tool name.
             * @param array  $arguments Tool arguments.
             * @param array  $result    Tool result.
             */
            do_action( 'glimmr_ai_after_tool_execute', $name, $arguments, $result );

            return $result;

        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'error'   => 'execution_error',
                'message' => $e->getMessage(),
            );
        }
    }

    /**
     * Execute multiple tool calls.
     *
     * @param array $tool_calls Array of tool calls from OpenAI.
     * @return array Array of results keyed by tool_call_id.
     */
    public function execute_batch( $tool_calls ) {
        $results = array();

        foreach ( $tool_calls as $call ) {
            $tool_name = $call['function']['name'] ?? '';
            $arguments = json_decode( $call['function']['arguments'] ?? '{}', true );
            $call_id   = $call['id'] ?? uniqid( 'tool_' );

            $result = $this->execute( $tool_name, $arguments );

            $results[ $call_id ] = array(
                'tool_name' => $tool_name,
                'result'    => $result,
            );
        }

        return $results;
    }

    /**
     * Get tool names grouped by category.
     *
     * @return array Categorized tool names.
     */
    public function get_categorized_tools() {
        return array(
            'information' => array(
                'text_answer'    => __( 'Text Answer (RAG)', 'glimmr-ai' ),
                'site_knowledge' => __( 'Site Knowledge', 'glimmr-ai' ),
            ),
            'products' => array(
                'query_products'   => __( 'Query Products (Unified)', 'glimmr-ai' ),
                'select_products'  => __( 'Select Products (Candidate Hydration)', 'glimmr-ai' ),
                'recommendations'  => __( 'Recommendations', 'glimmr-ai' ),
            ),
            'resolvers' => array(
                'resolve_product'   => __( 'Resolve Product Name', 'glimmr-ai' ),
                'resolve_variation' => __( 'Resolve Variation', 'glimmr-ai' ),
                'resolve_cart_item' => __( 'Resolve Cart Item', 'glimmr-ai' ),
                'resolve_order'     => __( 'Resolve Order', 'glimmr-ai' ),
            ),
            'cart' => array(
                'add_to_cart'   => __( 'Add to Cart', 'glimmr-ai' ),
                'view_cart'     => __( 'View Cart', 'glimmr-ai' ),
                'update_cart'   => __( 'Update Cart', 'glimmr-ai' ),
                'apply_coupon'  => __( 'Apply Coupon', 'glimmr-ai' ),
                'checkout_link' => __( 'Checkout Link', 'glimmr-ai' ),
            ),
            'coupons' => array(
                'coupon_lookup' => __( 'Coupon Lookup', 'glimmr-ai' ),
            ),
            'orders' => array(
                'order_status'  => __( 'Order Status', 'glimmr-ai' ),
                'order_history' => __( 'Order History', 'glimmr-ai' ),
                'reorder'       => __( 'Quick Reorder', 'glimmr-ai' ),
                'track_package' => __( 'Track Package', 'glimmr-ai' ),
            ),
            'account' => array(
                'account_info'            => __( 'Account Info', 'glimmr-ai' ),
                'check_gift_card_balance' => __( 'Check Gift Card Balance', 'glimmr-ai' ),
            ),
            'reviews' => array(
                'get_reviews'      => __( 'Get Reviews', 'glimmr-ai' ),
                'summarize_reviews' => __( 'Summarize Reviews', 'glimmr-ai' ),
            ),
            'support' => array(
                'contact_request' => __( 'Contact Request', 'glimmr-ai' ),
            ),
            'navigation' => array(
                'navigate_to_page' => __( 'Navigate to Page', 'glimmr-ai' ),
            ),
            'advanced' => array(
                'sql_readonly' => __( 'SQL Readonly (Escape Hatch)', 'glimmr-ai' ),
            ),
        );
    }

    /**
     * Reset the singleton instance.
     *
     * Primarily for testing purposes.
     */
    public static function reset() {
        self::$instance = null;
    }
}
