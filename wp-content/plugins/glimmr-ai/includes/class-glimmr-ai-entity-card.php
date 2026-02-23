<?php
/**
 * Entity Card Utility
 *
 * Provides compact representations of entities (products, orders, cart items)
 * for injection into LLM context during reference resolution.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Entity_Card
 *
 * Utility class for creating compact entity representations
 * suitable for LLM context injection.
 */
class Glimmr_AI_Entity_Card {

	/**
	 * Create a product entity card.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return array|null Product card data or null if invalid.
	 */
	public static function product( $product ) {
		if ( is_int( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product || ! ( $product instanceof WC_Product ) ) {
			return null;
		}

		return array(
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'price'    => wc_price( $product->get_price() ),
			'price_raw' => (float) $product->get_price(),
			'type'     => $product->get_type(),
			'in_stock' => $product->is_in_stock(),
		);
	}

	/**
	 * Create an order entity card.
	 *
	 * @param WC_Order|int $order Order object or ID.
	 * @return array|null Order card data or null if invalid.
	 */
	public static function order( $order ) {
		if ( is_int( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			return null;
		}

		$date_created = $order->get_date_created();

		return array(
			'id'     => $order->get_id(),
			'number' => $order->get_order_number(),
			'date'   => $date_created ? $date_created->format( 'M j, Y' ) : '',
			'status' => wc_get_order_status_name( $order->get_status() ),
			'total'  => wc_price( $order->get_total() ),
		);
	}

	/**
	 * Create a cart item entity card.
	 *
	 * @param string $key  Cart item key.
	 * @param array  $item Cart item data.
	 * @return array|null Cart item card data or null if invalid.
	 */
	public static function cart_item( $key, $item ) {
		if ( empty( $key ) ) {
			return null;
		}

		$product = isset( $item['data'] ) ? $item['data'] : null;
		$product_name = $product ? $product->get_name() : 'Unknown Product';

		return array(
			'key'          => $key,
			'product_name' => $product_name,
			'quantity'     => (int) ( $item['quantity'] ?? 1 ),
			'product_id'   => (int) ( $item['product_id'] ?? 0 ),
			'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
		);
	}

	/**
	 * Format a product card as a single line for LLM context.
	 *
	 * Format: ID: 596 | CloudSoft Premium Hoodie | $59.99 | variable | in stock
	 *
	 * @param array $card Product card data from product().
	 * @return string Formatted single-line representation.
	 */
	public static function format_product_line( $card ) {
		if ( ! isset( $card['id'] ) ) {
			return '';
		}

		$stock = ! empty( $card['in_stock'] ) ? 'in stock' : 'out of stock';
		$price = isset( $card['price'] ) ? wp_strip_all_tags( $card['price'] ) : '$0.00';

		return sprintf(
			'ID: %d | %s | %s | %s | %s',
			$card['id'],
			$card['name'] ?? 'Unknown',
			$price,
			$card['type'] ?? 'simple',
			$stock
		);
	}

	/**
	 * Format an order card as a single line for LLM context.
	 *
	 * Format: Order ID: 789 | #789 | Jan 15, 2025 | Completed | $150.00
	 *
	 * @param array $card Order card data from order().
	 * @return string Formatted single-line representation.
	 */
	public static function format_order_line( $card ) {
		if ( ! isset( $card['id'] ) ) {
			return '';
		}

		$total = isset( $card['total'] ) ? wp_strip_all_tags( $card['total'] ) : '$0.00';

		return sprintf(
			'Order ID: %d | #%s | %s | %s | %s',
			$card['id'],
			$card['number'] ?? $card['id'],
			$card['date'] ?? 'Unknown date',
			$card['status'] ?? 'Unknown',
			$total
		);
	}

	/**
	 * Format a cart item card as a single line for LLM context.
	 *
	 * Format: Key: abc123 | CloudSoft Hoodie x2 | Product ID: 596
	 *
	 * @param array $card Cart item card data from cart_item().
	 * @return string Formatted single-line representation.
	 */
	public static function format_cart_item_line( $card ) {
		if ( ! isset( $card['key'] ) ) {
			return '';
		}

		return sprintf(
			'Key: %s | %s x%d | Product ID: %d',
			$card['key'],
			$card['product_name'] ?? 'Unknown',
			$card['quantity'] ?? 1,
			$card['product_id'] ?? 0
		);
	}

	/**
	 * Create multiple product cards from an array of IDs.
	 *
	 * @param array $product_ids Array of product IDs.
	 * @param int   $limit       Maximum cards to create (default 5).
	 * @return array Array of product cards.
	 */
	public static function products_from_ids( $product_ids, $limit = 5 ) {
		$cards = array();
		$ids = array_slice( array_map( 'absint', $product_ids ), 0, $limit );

		foreach ( $ids as $id ) {
			$card = self::product( $id );
			if ( $card ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Create multiple order cards from an array of IDs.
	 *
	 * @param array $order_ids Array of order IDs.
	 * @param int   $limit     Maximum cards to create (default 3).
	 * @return array Array of order cards.
	 */
	public static function orders_from_ids( $order_ids, $limit = 3 ) {
		$cards = array();
		$ids = array_slice( array_map( 'absint', $order_ids ), 0, $limit );

		foreach ( $ids as $id ) {
			$card = self::order( $id );
			if ( $card ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Format multiple product cards as lines.
	 *
	 * @param array $cards Array of product cards.
	 * @return array Array of formatted lines.
	 */
	public static function format_product_lines( $cards ) {
		$lines = array();

		foreach ( $cards as $card ) {
			$line = self::format_product_line( $card );
			if ( ! empty( $line ) ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}
}
