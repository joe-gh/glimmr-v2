<?php
/**
 * Reference Validator
 *
 * Validates entity references in tool calls against the Focus Frame.
 * Prevents hallucinated entity IDs by gating tool execution.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Reference_Validator
 *
 * Validates that entity IDs used in tool calls exist in the Focus Frame.
 * If an ID is not in the Resolution Pack, the validator returns a
 * clarification request instead of allowing the tool to execute with
 * potentially hallucinated IDs.
 */
class Glimmr_AI_Reference_Validator {

	/**
	 * Tools that accept product IDs.
	 */
	const PRODUCT_ID_TOOLS = array(
		'add_to_cart',
		'select_products',
		'resolve_variation',
	);

	/**
	 * Tools that accept order IDs.
	 */
	const ORDER_ID_TOOLS = array(
		'order_status',
		'reorder',
	);

	/**
	 * Paths to extract IDs from tool arguments.
	 *
	 * Format: tool_name => array of dot-notation paths.
	 */
	const ID_PATHS = array(
		'add_to_cart'       => array( 'product_id' ),
		'select_products'   => array( 'product_ids' ),
		'order_status'      => array( 'lookup.order_id', 'order_id' ),
		'reorder'           => array( 'order_id' ),
		'resolve_variation' => array( 'product_id' ),
		'query_products'    => array(
			'details.product_id',
			'compare.product_ids',
			'stock_check.product_ids',
		),
	);

	/**
	 * Tools that are exempt from validation.
	 *
	 * These tools search/discover entities rather than reference them.
	 */
	const EXEMPT_TOOLS = array(
		'view_cart',
		'coupon_lookup',
		'apply_coupon',
		'checkout_link',
		'account_info',
		'site_knowledge',
		'text_answer',
		'order_history',
		'recommendations',
		'navigate_to_page',
	);

	/**
	 * Validate entity references in a tool call.
	 *
	 * @param string               $tool_name Tool name.
	 * @param array                $arguments Tool arguments.
	 * @param Glimmr_AI_Focus_Frame $frame    Focus frame with available entities.
	 * @param string               $user_message Optional user message for explicit ID detection.
	 * @return Glimmr_AI_Validation_Result Validation result.
	 */
	public function validate( $tool_name, $arguments, Glimmr_AI_Focus_Frame $frame, $user_message = '' ) {
		$result = new Glimmr_AI_Validation_Result();

		// Skip validation for exempt tools.
		if ( in_array( $tool_name, self::EXEMPT_TOOLS, true ) ) {
			return $result;
		}

		// Skip validation if this tool isn't in our ID_PATHS.
		if ( ! isset( self::ID_PATHS[ $tool_name ] ) ) {
			return $result;
		}

		// Check each path for this tool.
		foreach ( self::ID_PATHS[ $tool_name ] as $path ) {
			$ids = $this->extract_ids( $arguments, $path );

			if ( empty( $ids ) ) {
				continue;
			}

			// Determine entity type from path.
			$type = $this->path_to_entity_type( $path );

			foreach ( $ids as $id ) {
				// Allow if user explicitly mentioned the ID in their message.
				if ( $this->is_explicit_id( $user_message, $id ) ) {
					continue;
				}

				// Check if ID exists in focus frame.
				if ( ! $frame->has_entity( $type, $id ) ) {
					$result->add_invalid(
						$type,
						$id,
						$path,
						$frame->get_available_ids( $type )
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Build a clarification response for invalid references.
	 *
	 * @param Glimmr_AI_Validation_Result $result Validation result.
	 * @param Glimmr_AI_Focus_Frame       $frame  Focus frame for alternatives.
	 * @return array Clarification response with 'message' and 'alternatives'.
	 */
	public function build_clarification( Glimmr_AI_Validation_Result $result, Glimmr_AI_Focus_Frame $frame ) {
		$invalid_refs = $result->get_invalid_refs();

		if ( empty( $invalid_refs ) ) {
			return array(
				'message'      => 'How can I help you?',
				'alternatives' => array(),
			);
		}

		// Group by type.
		$by_type = array();
		foreach ( $invalid_refs as $ref ) {
			$type = $ref['type'];
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array();
			}
			$by_type[ $type ][] = $ref;
		}

		// Build message.
		$messages = array();
		$alternatives = array();

		foreach ( $by_type as $type => $refs ) {
			$available = $refs[0]['available'] ?? array();

			switch ( $type ) {
				case 'product':
					if ( empty( $available ) ) {
						$messages[] = "I'm not sure which product you're referring to. Could you search for it first or tell me the product name?";
					} else {
						// Get product names for better UX.
						$product_options = $this->get_product_options( $available );
						if ( count( $product_options ) === 1 ) {
							$messages[] = sprintf(
								"Did you mean the %s?",
								$product_options[0]['name']
							);
						} else {
							$messages[] = "Which product did you mean?";
							$alternatives['products'] = $product_options;
						}
					}
					break;

				case 'order':
					if ( empty( $available ) ) {
						$messages[] = "I don't have any orders in context. Could you provide your order number?";
					} else {
						$order_options = $this->get_order_options( $available );
						$messages[] = "Which order did you mean?";
						$alternatives['orders'] = $order_options;
					}
					break;

				case 'cart_item':
					if ( empty( $available ) ) {
						$messages[] = "I don't see any items in your cart context. Would you like me to show your cart first?";
					} else {
						$messages[] = "Which cart item did you mean?";
						$cart_items = $frame->get_cart_items();
						$alternatives['cart_items'] = array_values( $cart_items );
					}
					break;
			}
		}

		return array(
			'message'      => implode( ' ', $messages ),
			'alternatives' => $alternatives,
		);
	}

	/**
	 * Extract IDs from arguments using dot notation path.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $path      Dot notation path (e.g., "lookup.order_id").
	 * @return array Array of IDs (always array, even for single values).
	 */
	private function extract_ids( $arguments, $path ) {
		$parts = explode( '.', $path );
		$value = $arguments;

		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! isset( $value[ $part ] ) ) {
				return array();
			}
			$value = $value[ $part ];
		}

		// Normalize to array.
		if ( is_array( $value ) ) {
			return array_map( 'absint', $value );
		}

		return array( absint( $value ) );
	}

	/**
	 * Determine entity type from path.
	 *
	 * @param string $path Argument path.
	 * @return string Entity type: 'product', 'order', or 'cart_item'.
	 */
	private function path_to_entity_type( $path ) {
		if ( strpos( $path, 'order' ) !== false ) {
			return 'order';
		}

		if ( strpos( $path, 'cart' ) !== false ) {
			return 'cart_item';
		}

		return 'product';
	}

	/**
	 * Check if user explicitly mentioned the ID in their message.
	 *
	 * Allows explicit IDs (e.g., "add product 596 to cart") to bypass validation.
	 *
	 * @param string $user_message User's message.
	 * @param int    $id           ID to check.
	 * @return bool True if ID was explicitly mentioned.
	 */
	private function is_explicit_id( $user_message, $id ) {
		if ( empty( $user_message ) || ! $id ) {
			return false;
		}

		// Check for explicit ID patterns:
		// - "product 596"
		// - "ID 596"
		// - "#596"
		// - "order 12345"
		$patterns = array(
			'/\bproduct\s*#?\s*' . $id . '\b/i',
			'/\bID\s*#?\s*' . $id . '\b/i',
			'/\border\s*#?\s*' . $id . '\b/i',
			'/\b#' . $id . '\b/',
			'/\[ADD_TO_CART:' . $id . '\]/',
			'/\[VIEW_DETAILS:' . $id . '\]/',
			'/\[TRACK:' . $id . '\]/',
			'/\[REORDER:' . $id . '\]/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $user_message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get product options with names for display.
	 *
	 * @param array $product_ids Product IDs.
	 * @return array Array of ['id' => X, 'name' => Y].
	 */
	private function get_product_options( $product_ids ) {
		$options = array();

		foreach ( $product_ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$options[] = array(
					'id'   => $id,
					'name' => $product->get_name(),
				);
			}
		}

		return $options;
	}

	/**
	 * Get order options with details for display.
	 *
	 * @param array $order_ids Order IDs.
	 * @return array Array of order info.
	 */
	private function get_order_options( $order_ids ) {
		$options = array();

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$date = $order->get_date_created();
				$options[] = array(
					'id'     => $id,
					'number' => $order->get_order_number(),
					'date'   => $date ? $date->format( 'M j, Y' ) : '',
					'total'  => $order->get_total(),
				);
			}
		}

		return $options;
	}
}

/**
 * Class Glimmr_AI_Validation_Result
 *
 * Holds the result of reference validation.
 */
class Glimmr_AI_Validation_Result {

	/**
	 * Whether all references are valid.
	 *
	 * @var bool
	 */
	private $valid = true;

	/**
	 * Invalid references found.
	 *
	 * @var array
	 */
	private $invalid_refs = array();

	/**
	 * Check if validation passed.
	 *
	 * @return bool True if all references are valid.
	 */
	public function is_valid() {
		return $this->valid;
	}

	/**
	 * Add an invalid reference.
	 *
	 * @param string $type      Entity type.
	 * @param int    $id        Invalid ID.
	 * @param string $path      Argument path where ID was found.
	 * @param array  $available Available IDs in focus frame.
	 */
	public function add_invalid( $type, $id, $path, $available ) {
		$this->valid = false;
		$this->invalid_refs[] = array(
			'type'      => $type,
			'id'        => $id,
			'path'      => $path,
			'available' => $available,
		);
	}

	/**
	 * Get all invalid references.
	 *
	 * @return array Invalid references.
	 */
	public function get_invalid_refs() {
		return $this->invalid_refs;
	}

	/**
	 * Get count of invalid references.
	 *
	 * @return int Count.
	 */
	public function get_invalid_count() {
		return count( $this->invalid_refs );
	}
}
