<?php
/**
 * Continuity State Manager
 *
 * Lightweight state tracking for entity focus across conversations.
 * Separate from workspace with longer TTL (24 hours vs 1 hour).
 *
 * Used for pronoun resolution ("it", "that", "this product") and
 * multi-step verification flows.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Continuity
 *
 * Tracks entity focus (products, orders, cart items) separately from
 * the heavy workspace state. Uses WordPress transients with 24-hour TTL.
 */
class Glimmr_AI_Continuity {

	/**
	 * Transient TTL in seconds (24 hours).
	 */
	const TTL = 86400;

	/**
	 * Maximum product IDs to track.
	 */
	const MAX_PRODUCTS = 5;

	/**
	 * Maximum cart item keys to track.
	 */
	const MAX_CART_ITEMS = 10;

	/**
	 * Get continuity state for a session.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return array Continuity state.
	 */
	public static function get( $session_id ) {
		$key = self::get_transient_key( $session_id );
		$state = get_transient( $key );

		if ( false === $state || ! is_array( $state ) ) {
			return self::get_default_state();
		}

		return $state;
	}

	/**
	 * Update continuity state for a session.
	 *
	 * Uses merge semantics - only provided keys are updated.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @param array  $updates    State updates to merge.
	 * @return void
	 */
	public static function update( $session_id, $updates ) {
		$state = self::get( $session_id );
		$state = array_merge( $state, $updates );
		$state['updated_at'] = time();

		$key = self::get_transient_key( $session_id );
		set_transient( $key, $state, self::TTL );
	}

	/**
	 * Set product focus (most recently discussed products).
	 *
	 * @param string $session_id  Session or conversation ID.
	 * @param array  $product_ids Array of product IDs (most recent first).
	 * @return void
	 */
	public static function set_product_focus( $session_id, $product_ids ) {
		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
		$product_ids = array_slice( $product_ids, 0, self::MAX_PRODUCTS );

		self::update( $session_id, array(
			'last_product_ids'   => $product_ids,
			'last_entity_type'   => 'product',
			'last_entity_update' => time(),
		) );
	}

	/**
	 * Add a single product to focus stack.
	 *
	 * Adds to front, removes duplicates, maintains max size.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @param int    $product_id Product ID to add.
	 * @return void
	 */
	public static function push_product_focus( $session_id, $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}

		$state = self::get( $session_id );
		$product_ids = $state['last_product_ids'] ?? array();

		// Add to front, remove duplicates.
		array_unshift( $product_ids, $product_id );
		$product_ids = array_unique( $product_ids );
		$product_ids = array_slice( $product_ids, 0, self::MAX_PRODUCTS );

		self::update( $session_id, array(
			'last_product_ids'   => $product_ids,
			'last_entity_type'   => 'product',
			'last_entity_update' => time(),
		) );
	}

	/**
	 * Set order focus (most recently discussed order).
	 *
	 * @param string $session_id Session or conversation ID.
	 * @param int    $order_id   Order ID.
	 * @return void
	 */
	public static function set_order_focus( $session_id, $order_id ) {
		self::update( $session_id, array(
			'last_order_id'      => absint( $order_id ),
			'last_entity_type'   => 'order',
			'last_entity_update' => time(),
		) );
	}

	/**
	 * Set cart item focus (most recently discussed cart items).
	 *
	 * @param string $session_id     Session or conversation ID.
	 * @param array  $cart_item_keys Array of cart item keys.
	 * @return void
	 */
	public static function set_cart_focus( $session_id, $cart_item_keys ) {
		$cart_item_keys = array_slice( (array) $cart_item_keys, 0, self::MAX_CART_ITEMS );

		self::update( $session_id, array(
			'last_cart_item_keys' => $cart_item_keys,
			'last_entity_type'    => 'cart_item',
			'last_entity_update'  => time(),
		) );
	}

	/**
	 * Set pending verification data for multi-step flows.
	 *
	 * Used when guest order lookup needs additional verification.
	 *
	 * @param string $session_id    Session or conversation ID.
	 * @param array  $verification  Pending verification data.
	 * @return void
	 */
	public static function set_pending_verification( $session_id, $verification ) {
		self::update( $session_id, array(
			'pending_verification' => $verification,
		) );
	}

	/**
	 * Clear pending verification.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return void
	 */
	public static function clear_pending_verification( $session_id ) {
		self::update( $session_id, array(
			'pending_verification' => null,
		) );
	}

	/**
	 * Get the focused product ID (first in stack).
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return int|null Product ID or null if none.
	 */
	public static function get_focused_product( $session_id ) {
		$state = self::get( $session_id );
		$products = $state['last_product_ids'] ?? array();
		return ! empty( $products ) ? (int) $products[0] : null;
	}

	/**
	 * Get all focused product IDs.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return array Array of product IDs.
	 */
	public static function get_focused_products( $session_id ) {
		$state = self::get( $session_id );
		return $state['last_product_ids'] ?? array();
	}

	/**
	 * Get the focused order ID.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return int|null Order ID or null if none.
	 */
	public static function get_focused_order( $session_id ) {
		$state = self::get( $session_id );
		$order_id = $state['last_order_id'] ?? null;
		return $order_id ? (int) $order_id : null;
	}

	/**
	 * Get the focused cart item keys.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return array Array of cart item keys.
	 */
	public static function get_focused_cart_items( $session_id ) {
		$state = self::get( $session_id );
		return $state['last_cart_item_keys'] ?? array();
	}

	/**
	 * Get pending verification data.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return array|null Pending verification data or null.
	 */
	public static function get_pending_verification( $session_id ) {
		$state = self::get( $session_id );
		return $state['pending_verification'] ?? null;
	}

	/**
	 * Get the last entity type that was focused.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return string|null Entity type ('product', 'order', 'cart_item') or null.
	 */
	public static function get_last_entity_type( $session_id ) {
		$state = self::get( $session_id );
		return $state['last_entity_type'] ?? null;
	}

	/**
	 * Resolve a pronoun reference based on context.
	 *
	 * @param string      $session_id Session or conversation ID.
	 * @param string|null $type       Expected entity type or null to use last type.
	 * @return mixed Entity ID/key or null if unresolvable.
	 */
	public static function resolve_reference( $session_id, $type = null ) {
		$state = self::get( $session_id );

		// If no type specified, use the last entity type.
		if ( null === $type ) {
			$type = $state['last_entity_type'] ?? 'product';
		}

		switch ( $type ) {
			case 'product':
				$products = $state['last_product_ids'] ?? array();
				return ! empty( $products ) ? (int) $products[0] : null;

			case 'order':
				$order_id = $state['last_order_id'] ?? null;
				return $order_id ? (int) $order_id : null;

			case 'cart_item':
				$cart_items = $state['last_cart_item_keys'] ?? array();
				return ! empty( $cart_items ) ? $cart_items[0] : null;

			default:
				return null;
		}
	}

	/**
	 * Check if a specific entity is in focus.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @param string $type       Entity type ('product', 'order', 'cart_item').
	 * @param mixed  $identifier Entity ID or key to check.
	 * @return bool True if entity is in focus.
	 */
	public static function is_in_focus( $session_id, $type, $identifier ) {
		$state = self::get( $session_id );

		switch ( $type ) {
			case 'product':
				$products = $state['last_product_ids'] ?? array();
				return in_array( absint( $identifier ), array_map( 'absint', $products ), true );

			case 'order':
				return absint( $state['last_order_id'] ?? 0 ) === absint( $identifier );

			case 'cart_item':
				$cart_items = $state['last_cart_item_keys'] ?? array();
				return in_array( $identifier, $cart_items, true );

			default:
				return false;
		}
	}

	/**
	 * Clear all continuity state for a session.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return void
	 */
	public static function clear( $session_id ) {
		$key = self::get_transient_key( $session_id );
		delete_transient( $key );
	}

	/**
	 * Get the transient key for a session.
	 *
	 * @param string $session_id Session or conversation ID.
	 * @return string Transient key.
	 */
	private static function get_transient_key( $session_id ) {
		return 'glimmr_continuity_' . md5( $session_id );
	}

	/**
	 * Get default state structure.
	 *
	 * @return array Default state.
	 */
	private static function get_default_state() {
		return array(
			'last_product_ids'     => array(),
			'last_order_id'        => null,
			'last_cart_item_keys'  => array(),
			'last_entity_type'     => null,
			'last_entity_update'   => null,
			'pending_verification' => null,
			'updated_at'           => null,
		);
	}
}
