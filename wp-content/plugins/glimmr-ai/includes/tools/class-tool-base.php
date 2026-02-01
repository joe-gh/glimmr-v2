<?php
/**
 * Abstract Tool Base Class
 *
 * Base class for all AI tools. Provides common functionality
 * and defines the interface for tool implementations.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract Class Glimmr_AI_Tool_Base
 *
 * All AI tools must extend this class and implement the required methods.
 */
abstract class Glimmr_AI_Tool_Base {

    /**
     * Standard error codes for structured responses.
     *
     * These codes help the AI agent understand how to handle errors:
     * - VALIDATION_FAILED: Bad parameters, agent should fix and retry
     * - NEEDS_INFO: Missing context, agent should ask user then retry
     * - NOT_ALLOWED: Capability boundary, agent should explain limitation
     * - NOT_FOUND: Entity doesn't exist, agent should inform user
     * - AMBIGUOUS: Multiple matches, agent should ask user to clarify
     */
    const ERROR_VALIDATION_FAILED = 'VALIDATION_FAILED';
    const ERROR_NEEDS_INFO        = 'NEEDS_INFO';
    const ERROR_NOT_ALLOWED       = 'NOT_ALLOWED';
    const ERROR_NOT_FOUND         = 'NOT_FOUND';
    const ERROR_AMBIGUOUS         = 'AMBIGUOUS';

    /**
     * Tool name (unique identifier).
     *
     * @var string
     */
    protected $name;

    /**
     * Tool description for the AI.
     *
     * @var string
     */
    protected $description;

    /**
     * Tool parameters schema.
     *
     * @var array
     */
    protected $parameters = array();

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    protected $settings;

    /**
     * Database instance.
     *
     * @var Glimmr_AI_Database
     */
    protected $database;

    /**
     * Current user ID.
     *
     * @var int
     */
    protected $user_id;

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @param Glimmr_AI_Database $database Database instance.
     */
    public function __construct( $settings = null, $database = null ) {
        $this->settings = $settings ?? new Glimmr_AI_Settings();
        $this->database = $database ?? new Glimmr_AI_Database();
        $this->user_id  = get_current_user_id();
    }

    /**
     * Get tool name.
     *
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Get tool description.
     *
     * @return string
     */
    public function get_description() {
        return $this->description;
    }

    /**
     * Check if tool is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return Glimmr_AI_Settings::is_tool_enabled( $this->name );
    }

    /**
     * Get tool definition for OpenAI API.
     *
     * @return array Tool definition in OpenAI format.
     */
    public function get_definition() {
        $definition = array(
            'type' => 'function',
            'function' => array(
                'name'        => $this->name,
                'description' => $this->description,
            ),
        );

        if ( ! empty( $this->parameters ) ) {
            // Process schema recursively to handle nested objects.
            $processed = $this->process_schema_node( $this->parameters, true );

            $definition['function']['parameters'] = $processed;
        } else {
            // OpenAI requires parameters to be a valid JSON Schema object, even when empty.
            // Use stdClass to ensure it serializes to {} not [].
            $definition['function']['parameters'] = array(
                'type'       => 'object',
                'properties' => new stdClass(),
            );
        }

        return $definition;
    }

    /**
     * Process a schema node recursively for OpenAI function definitions.
     *
     * Handles nested objects, extracts 'required' arrays at each level,
     * and cleans internal flags.
     *
     * @param array $schema   The schema to process.
     * @param bool  $is_root  Whether this is the root level.
     * @return array Processed schema for OpenAI.
     */
    protected function process_schema_node( $schema, $is_root = false ) {
        if ( $is_root ) {
            // Root level: wrap parameters in object schema.
            $properties = array();
            $required = array();

            foreach ( $schema as $name => $field_schema ) {
                // Check if this field is required.
                if ( ! empty( $field_schema['required'] ) ) {
                    $required[] = $name;
                }

                // Process the field schema (removes 'required', handles nested objects).
                $properties[ $name ] = $this->process_schema_node( $field_schema, false );
            }

            $result = array(
                'type'                 => 'object',
                'properties'           => ! empty( $properties ) ? $properties : new stdClass(),
                'additionalProperties' => false,
            );

            if ( ! empty( $required ) ) {
                $result['required'] = $required;
            }

            return $result;
        }

        // Non-root: process individual field schema.
        $processed = array();

        foreach ( $schema as $key => $value ) {
            // Skip our internal 'required' flag (it's extracted at parent level).
            if ( 'required' === $key ) {
                continue;
            }

            // Handle nested properties (for object types).
            if ( 'properties' === $key && is_array( $value ) ) {
                $nested_properties = array();
                $nested_required = array();

                foreach ( $value as $prop_name => $prop_schema ) {
                    if ( ! empty( $prop_schema['required'] ) ) {
                        $nested_required[] = $prop_name;
                    }
                    $nested_properties[ $prop_name ] = $this->process_schema_node( $prop_schema, false );
                }

                $processed['properties'] = ! empty( $nested_properties ) ? $nested_properties : new stdClass();

                if ( ! empty( $nested_required ) ) {
                    $processed['required'] = $nested_required;
                }
            }
            // Handle 'items' for array types (recursive processing).
            elseif ( 'items' === $key && is_array( $value ) ) {
                $processed['items'] = $this->process_schema_node( $value, false );
            }
            // Pass through other schema properties as-is.
            else {
                $processed[ $key ] = $value;
            }
        }

        // Ensure object types with no properties still serialize to {}.
        if ( isset( $processed['type'] ) && 'object' === $processed['type'] && ! isset( $processed['properties'] ) ) {
            $processed['properties'] = new stdClass();
        }

        return $processed;
    }

    /**
     * Get required parameters.
     *
     * @return array List of required parameter names.
     */
    protected function get_required_parameters() {
        $required = array();
        foreach ( $this->parameters as $name => $schema ) {
            if ( ! empty( $schema['required'] ) ) {
                $required[] = $name;
            }
        }
        return $required;
    }

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments from AI.
     * @return array|string Tool result.
     */
    abstract public function execute( $arguments );

    /**
     * Format result for AI consumption.
     *
     * @param mixed $data   Result data.
     * @param bool  $success Whether the operation was successful.
     * @param string $message Optional message.
     * @return array Formatted result.
     */
    protected function format_result( $data, $success = true, $message = '' ) {
        return array(
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        );
    }

    /**
     * Format error result.
     *
     * @param string $message Error message.
     * @param string $code    Error code.
     * @return array Error result.
     */
    protected function format_error( $message, $code = 'error' ) {
        return array(
            'success' => false,
            'error'   => $code,
            'message' => $message,
        );
    }

    /**
     * Format structured error with actionable guidance for the AI agent.
     *
     * Use this for errors that need to guide the agent's next action.
     * Standard error codes help the agent understand how to handle each case.
     *
     * Error Codes:
     * - VALIDATION_FAILED: Bad parameters, agent should fix and retry
     * - NEEDS_INFO: Missing context, agent should ask user then retry
     * - NOT_ALLOWED: Capability boundary, agent should explain limitation
     * - NOT_FOUND: Entity doesn't exist, agent should inform user
     * - AMBIGUOUS: Multiple matches, agent should ask user to clarify
     *
     * @param string $code    Error code constant (use self::ERROR_* constants).
     * @param string $message Human-readable error message.
     * @param array  $context Optional context with hints, missing fields, etc.
     *                        - 'missing'       => array of missing field names
     *                        - 'hints'         => array of hints for fixing the error
     *                        - 'retry'         => bool whether retry is allowed (default true)
     *                        - 'allowed'       => array of allowed values (for validation errors)
     *                        - 'alternatives'  => array of alternatives to suggest
     * @return array Structured error response.
     */
    protected function format_structured_error( $code, $message, $context = array() ) {
        $response = array(
            'success'        => false,
            'error_code'     => $code,
            'error_message'  => $message,
            'retry_allowed'  => $context['retry'] ?? true,
        );

        // Add missing fields if provided.
        if ( ! empty( $context['missing'] ) ) {
            $response['missing_fields'] = (array) $context['missing'];
        }

        // Add hints for the agent.
        if ( ! empty( $context['hints'] ) ) {
            $response['hints'] = (array) $context['hints'];
        }

        // Add allowed values for validation errors.
        if ( ! empty( $context['allowed'] ) ) {
            $response['allowed_values'] = (array) $context['allowed'];
        }

        // Add alternatives for AMBIGUOUS errors.
        if ( ! empty( $context['alternatives'] ) ) {
            $response['alternatives'] = (array) $context['alternatives'];
        }

        // Add field path for validation errors.
        if ( ! empty( $context['field'] ) ) {
            $response['field'] = $context['field'];
        }

        return $response;
    }

    /**
     * Format NEEDS_INFO error for missing user context.
     *
     * Convenience method for common "need more information" errors.
     *
     * @param array  $missing_fields Fields that need to be provided.
     * @param string $message        Custom message or auto-generated.
     * @param array  $hints          Hints for gathering the information.
     * @return array Structured error response.
     */
    protected function format_needs_info( $missing_fields, $message = '', $hints = array() ) {
        if ( empty( $message ) ) {
            $fields = implode( ', ', $missing_fields );
            $message = sprintf(
                __( 'I need more information to help you. Please provide: %s', 'glimmr-ai' ),
                $fields
            );
        }

        return $this->format_structured_error(
            self::ERROR_NEEDS_INFO,
            $message,
            array(
                'missing' => $missing_fields,
                'hints'   => $hints,
                'retry'   => true,
            )
        );
    }

    /**
     * Format NOT_ALLOWED error for capability boundaries.
     *
     * Use when the action is not permitted (e.g., cancel order, process refund).
     *
     * @param string $message     What action is not allowed.
     * @param string $alternative What the user should do instead.
     * @return array Structured error response.
     */
    protected function format_not_allowed( $message, $alternative = '' ) {
        $hints = array();
        if ( ! empty( $alternative ) ) {
            $hints[] = $alternative;
        }

        return $this->format_structured_error(
            self::ERROR_NOT_ALLOWED,
            $message,
            array(
                'hints' => $hints,
                'retry' => false,
            )
        );
    }

    /**
     * Format NOT_FOUND error for missing entities.
     *
     * @param string $entity_type What was being looked for (e.g., 'product', 'order').
     * @param mixed  $identifier  The identifier that wasn't found.
     * @param array  $hints       Suggestions for the user.
     * @return array Structured error response.
     */
    protected function format_not_found( $entity_type, $identifier = '', $hints = array() ) {
        $message = $identifier
            ? sprintf( __( '%s "%s" was not found.', 'glimmr-ai' ), ucfirst( $entity_type ), $identifier )
            : sprintf( __( 'The requested %s was not found.', 'glimmr-ai' ), $entity_type );

        return $this->format_structured_error(
            self::ERROR_NOT_FOUND,
            $message,
            array(
                'hints' => $hints,
                'retry' => false,
            )
        );
    }

    /**
     * Format AMBIGUOUS error when multiple matches exist.
     *
     * @param string $message      What is ambiguous.
     * @param array  $alternatives The options to choose from.
     * @return array Structured error response.
     */
    protected function format_ambiguous( $message, $alternatives = array() ) {
        return $this->format_structured_error(
            self::ERROR_AMBIGUOUS,
            $message,
            array(
                'alternatives' => $alternatives,
                'hints'        => array( __( 'Please specify which one you mean.', 'glimmr-ai' ) ),
                'retry'        => true,
            )
        );
    }

    /**
     * Check if user is logged in.
     *
     * @return bool
     */
    protected function is_user_logged_in() {
        return $this->user_id > 0;
    }

    /**
     * Require user to be logged in.
     *
     * @return array|null Error array if not logged in, null otherwise.
     */
    protected function require_login() {
        if ( ! $this->is_user_logged_in() ) {
            return $this->format_error(
                __( 'You need to be logged in to use this feature.', 'glimmr-ai' ),
                'login_required'
            );
        }
        return null;
    }

    /**
     * Get WooCommerce instance.
     *
     * @return WooCommerce|null
     */
    protected function wc() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return null;
        }
        return WC();
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool
     */
    protected function is_wc_active() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Require WooCommerce to be active.
     *
     * @return array|null Error array if not active, null otherwise.
     */
    protected function require_wc() {
        if ( ! $this->is_wc_active() ) {
            return $this->format_error(
                __( 'WooCommerce is not available.', 'glimmr-ai' ),
                'wc_not_active'
            );
        }
        return null;
    }

    /**
     * Format price for display.
     *
     * @param float $price Price value.
     * @return string Formatted price.
     */
    protected function format_price( $price ) {
        if ( function_exists( 'wc_price' ) ) {
            // Strip HTML tags and decode HTML entities (e.g., &#36; → $).
            return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
        }
        return '$' . number_format( (float) $price, 2 );
    }

    /**
     * Get product data in a consistent format.
     *
     * @param WC_Product $product Product object.
     * @return array Product data.
     */
    protected function format_product( $product ) {
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return null;
        }

        // Handle pricing for variable products.
        // Variable products return 0 from get_price(), so we need to get the variation price range.
        $price_display = '';
        $price_raw     = 0.0;
        $price_min     = 0.0;
        $price_max     = 0.0;

        if ( $product->is_type( 'variable' ) ) {
            $min_price = $product->get_variation_price( 'min', true );
            $max_price = $product->get_variation_price( 'max', true );
            $price_min = (float) $min_price;
            $price_max = (float) $max_price;
            $price_raw = $price_min; // Use min as the "base" price.

            if ( $price_min === $price_max || $price_max === 0.0 ) {
                // All variations same price or only one variation.
                $price_display = $this->format_price( $min_price );
            } else {
                // Price range.
                $price_display = $this->format_price( $min_price ) . ' – ' . $this->format_price( $max_price );
            }
        } else {
            // Simple/other product types.
            $price_display = $this->format_price( $product->get_price() );
            $price_raw     = (float) $product->get_price();
            $price_min     = $price_raw;
            $price_max     = $price_raw;
        }

        $data = array(
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'type'              => $product->get_type(),
            'sku'               => $product->get_sku(),
            'price'             => $price_display,
            'price_raw'         => $price_raw,
            'price_min'         => $price_min,
            'price_max'         => $price_max,
            'regular_price'     => $this->format_price( $product->get_regular_price() ),
            'on_sale'           => $product->is_on_sale(),
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'in_stock'          => $product->is_in_stock(),
            'short_description' => wp_strip_all_tags( $product->get_short_description() ),
            'url'               => $product->get_permalink(),
            'image'             => wp_get_attachment_url( $product->get_image_id() ) ?: null,
            'rating'            => $product->get_average_rating(),
            'review_count'      => $product->get_review_count(),
        );

        // Add sale price if on sale.
        if ( $product->is_on_sale() ) {
            $data['sale_price'] = $this->format_price( $product->get_sale_price() );
        }

        // Add categories.
        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        $data['categories'] = is_array( $categories ) ? $categories : array();

        return $data;
    }

    /**
     * Format order data.
     *
     * @param WC_Order $order Order object.
     * @param bool     $include_items Whether to include line items.
     * @return array Order data.
     */
    protected function format_order( $order, $include_items = false ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return null;
        }

        $data = array(
            'id'             => $order->get_id(),
            'order_number'   => $order->get_order_number(),
            'status'         => $order->get_status(),
            'status_label'   => wc_get_order_status_name( $order->get_status() ),
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : null,
            'total'          => $this->format_price( $order->get_total() ),
            'total_raw'      => (float) $order->get_total(),
            'payment_method' => $order->get_payment_method_title(),
            'shipping_method' => $order->get_shipping_method(),
        );

        // Add tracking if available.
        $tracking = $order->get_meta( '_tracking_number' );
        if ( $tracking ) {
            $data['tracking_number'] = $tracking;
            $data['tracking_url']    = $order->get_meta( '_tracking_url' );
        }

        // Add items if requested.
        if ( $include_items ) {
            $data['items'] = array();
            foreach ( $order->get_items() as $item ) {
                $data['items'][] = array(
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total'    => $this->format_price( $item->get_total() ),
                );
            }
        }

        return $data;
    }

    /**
     * Sanitize and validate string argument.
     *
     * @param array  $arguments All arguments.
     * @param string $key       Argument key.
     * @param string $default   Default value.
     * @return string Sanitized value.
     */
    protected function get_string_arg( $arguments, $key, $default = '' ) {
        return isset( $arguments[ $key ] ) ? sanitize_text_field( $arguments[ $key ] ) : $default;
    }

    /**
     * Sanitize and validate integer argument.
     *
     * @param array  $arguments All arguments.
     * @param string $key       Argument key.
     * @param int    $default   Default value.
     * @return int Sanitized value.
     */
    protected function get_int_arg( $arguments, $key, $default = 0 ) {
        return isset( $arguments[ $key ] ) ? absint( $arguments[ $key ] ) : $default;
    }

    /**
     * Sanitize and validate float argument.
     *
     * @param array  $arguments All arguments.
     * @param string $key       Argument key.
     * @param float  $default   Default value.
     * @return float Sanitized value.
     */
    protected function get_float_arg( $arguments, $key, $default = 0.0 ) {
        return isset( $arguments[ $key ] ) ? (float) $arguments[ $key ] : $default;
    }

    /**
     * Sanitize and validate boolean argument.
     *
     * @param array  $arguments All arguments.
     * @param string $key       Argument key.
     * @param bool   $default   Default value.
     * @return bool Sanitized value.
     */
    protected function get_bool_arg( $arguments, $key, $default = false ) {
        return isset( $arguments[ $key ] ) ? (bool) $arguments[ $key ] : $default;
    }

    /**
     * Sanitize and validate array argument.
     *
     * @param array  $arguments All arguments.
     * @param string $key       Argument key.
     * @param array  $default   Default value.
     * @return array Sanitized value.
     */
    protected function get_array_arg( $arguments, $key, $default = array() ) {
        return isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ? $arguments[ $key ] : $default;
    }

    /**
     * Get a nested argument value using dot notation.
     *
     * @param array  $arguments All arguments.
     * @param string $path      Dot-notation path (e.g., "compare.product_ids").
     * @param mixed  $default   Default value if not found.
     * @return mixed The nested value or default.
     */
    protected function get_nested_arg( $arguments, $path, $default = null ) {
        $keys = explode( '.', $path );
        $value = $arguments;

        foreach ( $keys as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return $default;
            }
            $value = $value[ $key ];
        }

        return $value;
    }

    /**
     * Require a nested argument value, returning validation error if missing.
     *
     * @param array  $arguments All arguments.
     * @param string $path      Dot-notation path (e.g., "compare.product_ids").
     * @param string $type      Expected type ('string', 'integer', 'array', 'object', 'boolean', 'any').
     * @return mixed|array The value if valid, or validation error array.
     */
    protected function require_nested_arg( $arguments, $path, $type = 'any' ) {
        $value = $this->get_nested_arg( $arguments, $path );

        if ( null === $value ) {
            return $this->format_validation_error(
                'missing_required',
                $path,
                sprintf( __( 'Required field "%s" is missing.', 'glimmr-ai' ), $path )
            );
        }

        // Type validation.
        $valid = true;
        switch ( $type ) {
            case 'string':
                $valid = is_string( $value );
                break;
            case 'integer':
                $valid = is_int( $value ) || ( is_numeric( $value ) && (int) $value == $value );
                break;
            case 'array':
                $valid = is_array( $value ) && ! $this->is_assoc_array( $value );
                break;
            case 'object':
                $valid = is_array( $value ) && $this->is_assoc_array( $value );
                break;
            case 'boolean':
                $valid = is_bool( $value );
                break;
            case 'number':
                $valid = is_numeric( $value );
                break;
            case 'any':
            default:
                $valid = true;
                break;
        }

        if ( ! $valid ) {
            return $this->format_validation_error(
                'invalid_type',
                $path,
                sprintf( __( 'Field "%s" must be of type %s.', 'glimmr-ai' ), $path, $type )
            );
        }

        return $value;
    }

    /**
     * Check if array is associative (object-like) vs indexed (list-like).
     *
     * @param array $arr Array to check.
     * @return bool True if associative.
     */
    private function is_assoc_array( $arr ) {
        if ( empty( $arr ) ) {
            return true; // Empty arrays are considered objects.
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    /**
     * Format a validation error with path-aware details.
     *
     * @param string $code        Error code (e.g., 'missing_required', 'invalid_type').
     * @param string $field_path  Dot-notation path to the field (e.g., "compare.product_ids").
     * @param string $message     Human-readable error message.
     * @param array  $suggestions Optional suggestions for fixing the error.
     * @return array Structured validation error.
     */
    protected function format_validation_error( $code, $field_path, $message, $suggestions = array() ) {
        return array(
            'success'     => false,
            'error'       => $code,
            'field'       => $field_path,
            'message'     => $message,
            'suggestions' => $suggestions,
        );
    }

    /**
     * Format a structured outcome response for control flow.
     *
     * Used when the tool needs to guide the AI's next action (e.g., ask for variation selection).
     *
     * @param string $status     Outcome status (e.g., 'success', 'needs_variation_selection', 'needs_disambiguation').
     * @param array  $data       Outcome data.
     * @param string $suggestion Optional suggestion for the AI/user.
     * @return array Structured outcome response.
     */
    protected function format_outcome( $status, $data = array(), $suggestion = '' ) {
        $success_statuses = array( 'success', 'added', 'updated', 'removed', 'resolved', 'verified', 'cart_action', 'product_search' );

        return array(
            'success'    => in_array( $status, $success_statuses, true ),
            'status'     => $status,
            'data'       => $data,
            'suggestion' => $suggestion,
        );
    }

    /**
     * Log tool execution for debugging.
     *
     * @param string $message Log message.
     * @param array  $data    Additional data.
     */
    protected function log( $message, $data = array() ) {
        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::debug(
                sprintf( '[Tool: %s] %s', $this->name, $message ),
                $data,
                'tools'
            );
        }
    }
}
