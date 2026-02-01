<?php
/**
 * Focus Frame
 *
 * Typed entity tracking for pronoun and reference resolution.
 * Tracks what entities are "in context" for the current conversation turn.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Focus_Frame
 *
 * Provides structured entity tracking for LLM reference resolution:
 * - primary_product: Singular reference ("it", "that", "this product")
 * - product_list: Plural reference ("these", "those", "the products")
 * - last_order: Order context ("the order", "my order")
 * - cart_items: Cart item context
 *
 * Generates a Resolution Pack prompt that instructs the LLM to only use
 * IDs from the available entities - preventing hallucinated entity IDs.
 */
class Glimmr_AI_Focus_Frame {

	/**
	 * Maximum age in seconds before focus is considered stale.
	 */
	const STALE_THRESHOLD = 1800; // 30 minutes

	/**
	 * Maximum products in the list.
	 */
	const MAX_PRODUCT_LIST = 5;

	/**
	 * Maximum cart items to track.
	 */
	const MAX_CART_ITEMS = 10;

	/**
	 * Primary product ID for singular references ("it", "that").
	 *
	 * @var int|null
	 */
	private $primary_product = null;

	/**
	 * Product list for plural references ("these", "those").
	 * Array of product IDs, max 5.
	 *
	 * @var array
	 */
	private $product_list = array();

	/**
	 * Last order ID for order references.
	 *
	 * @var int|null
	 */
	private $last_order = null;

	/**
	 * Cart items for cart references.
	 * Array of cart item keys => product info.
	 *
	 * @var array
	 */
	private $cart_items = array();

	/**
	 * Last entity type that was updated.
	 * Used for disambiguation when multiple types match.
	 *
	 * @var string|null 'product'|'order'|'cart_item'
	 */
	private $last_updated_type = null;

	/**
	 * Timestamp of last update.
	 *
	 * @var int|null
	 */
	private $updated_at = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->updated_at = time();
	}

	// =========================================================================
	// Tool Result Processing
	// =========================================================================

	/**
	 * Update focus frame from a tool result.
	 *
	 * Maps tool names to the appropriate focus updates.
	 *
	 * @param string $tool_name Tool that was executed.
	 * @param array  $result    Tool result data.
	 */
	public function update_from_tool_result( $tool_name, $result ) {
		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return;
		}

		$data = $result['data'] ?? array();

		switch ( $tool_name ) {
			case 'query_products':
				$this->process_query_products_result( $data );
				break;

			case 'select_products':
				$this->process_select_products_result( $data );
				break;

			case 'recommendations':
				$this->process_recommendations_result( $data );
				break;

			case 'order_status':
				$this->process_order_status_result( $data );
				break;

			case 'order_history':
				$this->process_order_history_result( $data );
				break;

			case 'view_cart':
				$this->process_view_cart_result( $data );
				break;

			case 'add_to_cart':
				$this->process_add_to_cart_result( $result );
				break;
		}
	}

	/**
	 * Process query_products result.
	 *
	 * Sets primary_product to first result, product_list to all results.
	 *
	 * @param array $data Tool result data.
	 */
	private function process_query_products_result( $data ) {
		$products = $data['products'] ?? array();

		if ( empty( $products ) ) {
			return;
		}

		// Extract product IDs.
		$product_ids = array();
		foreach ( $products as $product ) {
			if ( isset( $product['id'] ) ) {
				$product_ids[] = (int) $product['id'];
			}
		}

		if ( empty( $product_ids ) ) {
			return;
		}

		// Set primary to first, list to all.
		$this->primary_product = $product_ids[0];
		$this->product_list = array_slice( $product_ids, 0, self::MAX_PRODUCT_LIST );
		$this->last_updated_type = 'product';
		$this->updated_at = time();
	}

	/**
	 * Process select_products result.
	 *
	 * @param array $data Tool result data.
	 */
	private function process_select_products_result( $data ) {
		// Same structure as query_products.
		$this->process_query_products_result( $data );
	}

	/**
	 * Process recommendations result.
	 *
	 * Only updates product_list, not primary (recommendations are options).
	 *
	 * @param array $data Tool result data.
	 */
	private function process_recommendations_result( $data ) {
		$products = $data['products'] ?? $data['recommendations'] ?? array();

		if ( empty( $products ) ) {
			return;
		}

		$product_ids = array();
		foreach ( $products as $product ) {
			if ( isset( $product['id'] ) ) {
				$product_ids[] = (int) $product['id'];
			}
		}

		if ( ! empty( $product_ids ) ) {
			$this->product_list = array_slice( $product_ids, 0, self::MAX_PRODUCT_LIST );
			$this->last_updated_type = 'product';
			$this->updated_at = time();
		}
	}

	/**
	 * Process order_status result.
	 *
	 * @param array $data Tool result data.
	 */
	private function process_order_status_result( $data ) {
		$order_id = $data['order_id'] ?? $data['id'] ?? null;

		if ( $order_id ) {
			$this->last_order = (int) $order_id;
			$this->last_updated_type = 'order';
			$this->updated_at = time();
		}
	}

	/**
	 * Process order_history result.
	 *
	 * Sets last_order to the most recent order.
	 *
	 * @param array $data Tool result data.
	 */
	private function process_order_history_result( $data ) {
		$orders = $data['orders'] ?? array();

		if ( ! empty( $orders ) && isset( $orders[0]['id'] ) ) {
			$this->last_order = (int) $orders[0]['id'];
			$this->last_updated_type = 'order';
			$this->updated_at = time();
		}
	}

	/**
	 * Process view_cart result.
	 *
	 * @param array $data Tool result data.
	 */
	private function process_view_cart_result( $data ) {
		$items = $data['items'] ?? array();
		$this->cart_items = array();

		foreach ( array_slice( $items, 0, self::MAX_CART_ITEMS ) as $item ) {
			if ( isset( $item['key'] ) ) {
				$this->cart_items[ $item['key'] ] = array(
					'product_id'   => $item['product_id'] ?? 0,
					'product_name' => $item['name'] ?? 'Unknown',
					'quantity'     => $item['quantity'] ?? 1,
				);
			}
		}

		if ( ! empty( $this->cart_items ) ) {
			$this->last_updated_type = 'cart_item';
			$this->updated_at = time();
		}
	}

	/**
	 * Process add_to_cart result.
	 *
	 * Sets primary_product to the added product.
	 *
	 * @param array $result Full tool result (includes cart_action data).
	 */
	private function process_add_to_cart_result( $result ) {
		// add_to_cart returns cart_action format.
		$data = $result['data'] ?? array();
		$product_id = $data['product_id'] ?? null;

		if ( $product_id ) {
			$this->primary_product = (int) $product_id;
			$this->last_updated_type = 'product';
			$this->updated_at = time();
		}
	}

	// =========================================================================
	// Resolution Pack Generation
	// =========================================================================

	/**
	 * Generate the Resolution Pack prompt for LLM injection.
	 *
	 * This prompt lists all available entities and provides resolution rules.
	 *
	 * @return string Resolution Pack prompt text.
	 */
	public function get_resolution_pack_prompt() {
		// Check staleness.
		if ( $this->is_stale() ) {
			return '';
		}

		$sections = array();

		// Header.
		$sections[] = '---';
		$sections[] = '## Entity Resolution Pack';
		$sections[] = '';
		$sections[] = 'CRITICAL: When resolving pronouns ("it", "that", "these", etc.), you MUST use IDs from this pack.';
		$sections[] = 'Never guess or invent entity IDs.';

		// Products section.
		$product_section = $this->build_products_section();
		if ( ! empty( $product_section ) ) {
			$sections[] = '';
			$sections[] = '### Products in Focus';
			$sections = array_merge( $sections, $product_section );
		}

		// Order section.
		if ( $this->last_order ) {
			$order_card = Glimmr_AI_Entity_Card::order( $this->last_order );
			if ( $order_card ) {
				$sections[] = '';
				$sections[] = '### Last Order';
				$sections[] = '- ' . Glimmr_AI_Entity_Card::format_order_line( $order_card );
			}
		}

		// Cart items section.
		if ( ! empty( $this->cart_items ) ) {
			$sections[] = '';
			$sections[] = '### Cart Items';
			foreach ( $this->cart_items as $key => $item ) {
				$sections[] = sprintf(
					'- Key: %s | %s x%d | Product ID: %d',
					$key,
					$item['product_name'],
					$item['quantity'],
					$item['product_id']
				);
			}
		}

		// Resolution rules.
		$rules = $this->build_resolution_rules();
		if ( ! empty( $rules ) ) {
			$sections[] = '';
			$sections[] = '### Resolution Rules';
			$sections = array_merge( $sections, $rules );
		}

		// Declaration instruction.
		$sections[] = '';
		$sections[] = 'When you call a tool with an entity ID, declare your resolution in resolved_references.';
		$sections[] = 'If a reference is ambiguous, ask for clarification with the available options.';
		$sections[] = '---';

		return implode( "\n", $sections );
	}

	/**
	 * Build the products section of the Resolution Pack.
	 *
	 * @return array Lines for the products section.
	 */
	private function build_products_section() {
		$lines = array();

		// Primary product.
		if ( $this->primary_product ) {
			$primary_card = Glimmr_AI_Entity_Card::product( $this->primary_product );
			if ( $primary_card ) {
				$lines[] = '';
				$lines[] = 'PRIMARY (for "it", "that", "this product"):';
				$lines[] = '- ' . Glimmr_AI_Entity_Card::format_product_line( $primary_card );
			}
		}

		// Product list (excluding primary to avoid duplication).
		$list_without_primary = array_filter(
			$this->product_list,
			function( $id ) {
				return $id !== $this->primary_product;
			}
		);

		if ( ! empty( $list_without_primary ) || count( $this->product_list ) > 1 ) {
			$lines[] = '';
			$lines[] = 'LIST (for "these", "those", "the products"):';

			foreach ( $this->product_list as $product_id ) {
				$card = Glimmr_AI_Entity_Card::product( $product_id );
				if ( $card ) {
					$lines[] = '- ' . Glimmr_AI_Entity_Card::format_product_line( $card );
				}
			}
		}

		return $lines;
	}

	/**
	 * Build resolution rules based on current focus state.
	 *
	 * @return array Lines for the resolution rules section.
	 */
	private function build_resolution_rules() {
		$rules = array();

		if ( $this->primary_product ) {
			$rules[] = sprintf(
				'1. "it", "that", "this" → PRIMARY product (ID: %d)',
				$this->primary_product
			);
		}

		if ( ! empty( $this->product_list ) ) {
			$rule_num = empty( $rules ) ? 1 : 2;
			$rules[] = sprintf(
				'%d. "these", "those" → LIST product IDs [%s]',
				$rule_num,
				implode( ', ', $this->product_list )
			);
		}

		if ( $this->last_order ) {
			$rule_num = count( $rules ) + 1;
			$rules[] = sprintf(
				'%d. "my order", "the order" → Last Order (ID: %d)',
				$rule_num,
				$this->last_order
			);
		}

		if ( ! empty( $this->cart_items ) ) {
			$rule_num = count( $rules ) + 1;
			$rules[] = sprintf(
				'%d. Cart items → Reference by key',
				$rule_num
			);
		}

		return $rules;
	}

	// =========================================================================
	// Validation Helpers
	// =========================================================================

	/**
	 * Check if focus frame has any entities.
	 *
	 * @return bool True if any entities are in focus.
	 */
	public function has_entities() {
		if ( $this->is_stale() ) {
			return false;
		}

		return $this->primary_product !== null
			|| ! empty( $this->product_list )
			|| $this->last_order !== null
			|| ! empty( $this->cart_items );
	}

	/**
	 * Check if a specific entity ID is available.
	 *
	 * @param string    $type Entity type: 'product', 'order', 'cart_item'.
	 * @param int|string $id  Entity ID or cart item key.
	 * @return bool True if entity is available.
	 */
	public function has_entity( $type, $id ) {
		if ( $this->is_stale() ) {
			return false;
		}

		switch ( $type ) {
			case 'product':
				return $this->primary_product === (int) $id
					|| in_array( (int) $id, $this->product_list, true );

			case 'order':
				return $this->last_order === (int) $id;

			case 'cart_item':
				return isset( $this->cart_items[ $id ] );

			default:
				return false;
		}
	}

	/**
	 * Get all available IDs for a given entity type.
	 *
	 * @param string $type Entity type: 'product', 'order', 'cart_item'.
	 * @return array Available IDs/keys.
	 */
	public function get_available_ids( $type ) {
		if ( $this->is_stale() ) {
			return array();
		}

		switch ( $type ) {
			case 'product':
				$ids = $this->product_list;
				if ( $this->primary_product && ! in_array( $this->primary_product, $ids, true ) ) {
					array_unshift( $ids, $this->primary_product );
				}
				return $ids;

			case 'order':
				return $this->last_order ? array( $this->last_order ) : array();

			case 'cart_item':
				return array_keys( $this->cart_items );

			default:
				return array();
		}
	}

	/**
	 * Check if focus frame is stale (expired).
	 *
	 * @return bool True if stale.
	 */
	public function is_stale() {
		if ( ! $this->updated_at ) {
			return true;
		}

		return ( time() - $this->updated_at ) > self::STALE_THRESHOLD;
	}

	// =========================================================================
	// Getters
	// =========================================================================

	/**
	 * Get the primary product ID.
	 *
	 * @return int|null Primary product ID or null.
	 */
	public function get_primary_product() {
		return $this->is_stale() ? null : $this->primary_product;
	}

	/**
	 * Get the product list.
	 *
	 * @return array Product IDs.
	 */
	public function get_product_list() {
		return $this->is_stale() ? array() : $this->product_list;
	}

	/**
	 * Get the last order ID.
	 *
	 * @return int|null Order ID or null.
	 */
	public function get_last_order() {
		return $this->is_stale() ? null : $this->last_order;
	}

	/**
	 * Get the cart items.
	 *
	 * @return array Cart items keyed by cart item key.
	 */
	public function get_cart_items() {
		return $this->is_stale() ? array() : $this->cart_items;
	}

	/**
	 * Get the last updated entity type.
	 *
	 * @return string|null Entity type or null.
	 */
	public function get_last_updated_type() {
		return $this->last_updated_type;
	}

	// =========================================================================
	// Manual Setters (for workspace integration)
	// =========================================================================

	/**
	 * Set the primary product.
	 *
	 * @param int|null $product_id Product ID.
	 */
	public function set_primary_product( $product_id ) {
		$this->primary_product = $product_id ? (int) $product_id : null;
		if ( $product_id ) {
			$this->last_updated_type = 'product';
			$this->updated_at = time();
		}
	}

	/**
	 * Set the product list.
	 *
	 * @param array $product_ids Array of product IDs.
	 */
	public function set_product_list( $product_ids ) {
		$this->product_list = array_slice(
			array_map( 'absint', (array) $product_ids ),
			0,
			self::MAX_PRODUCT_LIST
		);
		if ( ! empty( $this->product_list ) ) {
			$this->last_updated_type = 'product';
			$this->updated_at = time();
		}
	}

	/**
	 * Set the last order.
	 *
	 * @param int|null $order_id Order ID.
	 */
	public function set_last_order( $order_id ) {
		$this->last_order = $order_id ? (int) $order_id : null;
		if ( $order_id ) {
			$this->last_updated_type = 'order';
			$this->updated_at = time();
		}
	}

	/**
	 * Clear all focus state.
	 */
	public function clear() {
		$this->primary_product = null;
		$this->product_list = array();
		$this->last_order = null;
		$this->cart_items = array();
		$this->last_updated_type = null;
		$this->updated_at = null;
	}

	// =========================================================================
	// Serialization
	// =========================================================================

	/**
	 * Convert focus frame to array for storage.
	 *
	 * @return array Serializable array.
	 */
	public function to_array() {
		return array(
			'primary_product'   => $this->primary_product,
			'product_list'      => $this->product_list,
			'last_order'        => $this->last_order,
			'cart_items'        => $this->cart_items,
			'last_updated_type' => $this->last_updated_type,
			'updated_at'        => $this->updated_at,
		);
	}

	/**
	 * Create focus frame from stored array.
	 *
	 * @param array $data Stored array from to_array().
	 * @return self Focus frame instance.
	 */
	public static function from_array( $data ) {
		$frame = new self();

		if ( ! is_array( $data ) ) {
			return $frame;
		}

		$frame->primary_product   = isset( $data['primary_product'] ) ? (int) $data['primary_product'] : null;
		$frame->product_list      = isset( $data['product_list'] ) && is_array( $data['product_list'] )
			? array_map( 'absint', $data['product_list'] )
			: array();
		$frame->last_order        = isset( $data['last_order'] ) ? (int) $data['last_order'] : null;
		$frame->cart_items        = isset( $data['cart_items'] ) && is_array( $data['cart_items'] )
			? $data['cart_items']
			: array();
		$frame->last_updated_type = $data['last_updated_type'] ?? null;
		$frame->updated_at        = isset( $data['updated_at'] ) ? (int) $data['updated_at'] : null;

		return $frame;
	}
}
