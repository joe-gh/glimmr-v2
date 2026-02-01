<?php
/**
 * Cart Item Resolver Tool
 *
 * Resolves product/variation references to cart item keys.
 * Handles ambiguity when multiple items match.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Resolve_Cart_Item
 *
 * Resolver tool that maps product/variation references to cart item keys,
 * identifying when multiple items match and requiring disambiguation.
 */
class Glimmr_AI_Tool_Resolve_Cart_Item extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'resolve_cart_item';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Resolve product or variation reference to cart item key(s). Use before update_cart when user refers to product by name or ID instead of cart_item_key.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'product_id' => array(
			'type'        => 'integer',
			'description' => 'Product ID to find in cart',
			'minimum'     => 1,
		),
		'variation_id' => array(
			'type'        => 'integer',
			'description' => 'Variation ID for disambiguation (optional)',
			'minimum'     => 1,
		),
		'product_name' => array(
			'type'        => 'string',
			'description' => 'Product name as fallback (fuzzy matching)',
			'maxLength'   => 255,
		),
	);

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Resolution result.
	 */
	public function execute( $arguments ) {
		$wc_check = $this->require_wc();
		if ( $wc_check ) {
			return $wc_check;
		}

		$product_id   = $this->get_int_arg( $arguments, 'product_id', 0 );
		$variation_id = $this->get_int_arg( $arguments, 'variation_id', 0 );
		$product_name = $this->get_string_arg( $arguments, 'product_name', '' );

		// At least one identifier required.
		if ( ! $product_id && empty( $product_name ) ) {
			return $this->format_validation_error(
				'missing_identifier',
				'product_id|product_name',
				__( 'Either product_id or product_name is required.', 'glimmr-ai' )
			);
		}

		// Get cart.
		$cart = WC()->cart;
		if ( ! $cart ) {
			return $this->format_outcome(
				'cart_unavailable',
				array(),
				__( 'Shopping cart is not available.', 'glimmr-ai' )
			);
		}

		$cart_contents = $cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return $this->format_outcome(
				'cart_empty',
				array(),
				__( 'Your cart is empty.', 'glimmr-ai' )
			);
		}

		// Find matching items.
		$matches = $this->find_matches( $cart_contents, $product_id, $variation_id, $product_name );

		if ( empty( $matches ) ) {
			return $this->format_outcome(
				'not_in_cart',
				array(
					'searched_product_id'   => $product_id ?: null,
					'searched_variation_id' => $variation_id ?: null,
					'searched_name'         => $product_name ?: null,
					'cart_items'            => $this->get_cart_summary( $cart_contents ),
				),
				$this->build_not_found_suggestion( $product_id, $product_name, $cart_contents )
			);
		}

		if ( count( $matches ) === 1 ) {
			$match = $matches[0];
			return $this->format_outcome(
				'resolved',
				array(
					'cart_item_key' => $match['cart_item_key'],
					'product_id'    => $match['product_id'],
					'variation_id'  => $match['variation_id'],
					'product_name'  => $match['product_name'],
					'quantity'      => $match['quantity'],
					'line_total'    => $match['line_total'],
				),
				sprintf(
					__( 'Found "%s" in cart (qty: %d).', 'glimmr-ai' ),
					$match['product_name'],
					$match['quantity']
				)
			);
		}

		// Multiple matches - needs disambiguation.
		return $this->format_outcome(
			'multiple_matches',
			array(
				'match_count' => count( $matches ),
				'matches'     => $matches,
			),
			$this->build_disambiguation_suggestion( $matches )
		);
	}

	/**
	 * Find matching cart items.
	 *
	 * @param array  $cart_contents Cart contents.
	 * @param int    $product_id    Product ID to match.
	 * @param int    $variation_id  Variation ID to match.
	 * @param string $product_name  Product name to match (fuzzy).
	 * @return array Matching items.
	 */
	private function find_matches( $cart_contents, $product_id, $variation_id, $product_name ) {
		$matches = array();

		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			$item_product_id   = $cart_item['product_id'];
			$item_variation_id = $cart_item['variation_id'] ?? 0;

			// Get product for name matching.
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}

			$item_name = $product->get_name();
			$matched   = false;

			// Match by product ID.
			if ( $product_id && $item_product_id === $product_id ) {
				// If variation_id specified, must also match.
				if ( $variation_id ) {
					if ( $item_variation_id === $variation_id ) {
						$matched = true;
					}
				} else {
					$matched = true;
				}
			}

			// Match by variation ID directly.
			if ( ! $matched && $variation_id && $item_variation_id === $variation_id ) {
				$matched = true;
			}

			// Match by product name (fuzzy).
			if ( ! $matched && ! empty( $product_name ) ) {
				$similarity = $this->calculate_similarity( $product_name, $item_name );
				if ( $similarity >= 0.6 ) {
					$matched = true;
				}
			}

			if ( $matched ) {
				$matches[] = $this->format_cart_item( $cart_item_key, $cart_item, $product );
			}
		}

		return $matches;
	}

	/**
	 * Calculate string similarity.
	 *
	 * @param string $search Searched string.
	 * @param string $target Target string.
	 * @return float Similarity score 0-1.
	 */
	private function calculate_similarity( $search, $target ) {
		$search_lower = strtolower( trim( $search ) );
		$target_lower = strtolower( trim( $target ) );

		// Exact match.
		if ( $search_lower === $target_lower ) {
			return 1.0;
		}

		// Contains match.
		if ( strpos( $target_lower, $search_lower ) !== false ) {
			return 0.9;
		}

		// Reversed contains.
		if ( strpos( $search_lower, $target_lower ) !== false ) {
			return 0.85;
		}

		// Levenshtein similarity.
		$max_len = max( strlen( $search_lower ), strlen( $target_lower ) );
		if ( $max_len === 0 ) {
			return 0;
		}

		$distance = levenshtein( $search_lower, $target_lower );
		return 1 - ( $distance / $max_len );
	}

	/**
	 * Format a cart item for output.
	 *
	 * @param string     $cart_item_key Cart item key.
	 * @param array      $cart_item     Cart item data.
	 * @param WC_Product $product       Product object.
	 * @return array Formatted item.
	 */
	private function format_cart_item( $cart_item_key, $cart_item, $product ) {
		$variation_id   = $cart_item['variation_id'] ?? 0;
		$variation_desc = '';

		// Get variation description.
		if ( $variation_id && ! empty( $cart_item['variation'] ) ) {
			$attrs = array();
			foreach ( $cart_item['variation'] as $attr_key => $attr_val ) {
				$clean_key = str_replace( 'attribute_', '', $attr_key );
				$clean_key = str_replace( 'pa_', '', $clean_key );
				$clean_key = ucfirst( str_replace( '-', ' ', $clean_key ) );
				$attrs[]   = $clean_key . ': ' . ucfirst( $attr_val );
			}
			$variation_desc = implode( ', ', $attrs );
		}

		return array(
			'cart_item_key'  => $cart_item_key,
			'product_id'     => $cart_item['product_id'],
			'variation_id'   => $variation_id ?: null,
			'product_name'   => $product->get_name(),
			'variation_desc' => $variation_desc ?: null,
			'quantity'       => $cart_item['quantity'],
			'line_total'     => wc_price( $cart_item['line_total'] ),
		);
	}

	/**
	 * Get a summary of cart contents for context.
	 *
	 * @param array $cart_contents Cart contents.
	 * @return array Cart summary.
	 */
	private function get_cart_summary( $cart_contents ) {
		$summary = array();

		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}

			$summary[] = array(
				'cart_item_key' => $cart_item_key,
				'name'          => $product->get_name(),
				'quantity'      => $cart_item['quantity'],
			);
		}

		return $summary;
	}

	/**
	 * Build suggestion for not found case.
	 *
	 * @param int    $product_id    Searched product ID.
	 * @param string $product_name  Searched product name.
	 * @param array  $cart_contents Cart contents.
	 * @return string Suggestion message.
	 */
	private function build_not_found_suggestion( $product_id, $product_name, $cart_contents ) {
		$search_desc = $product_name ?: "product ID {$product_id}";
		$cart_count  = count( $cart_contents );

		if ( $cart_count === 0 ) {
			return sprintf(
				__( '"%s" is not in your cart. Your cart is empty.', 'glimmr-ai' ),
				$search_desc
			);
		}

		$item_names = array();
		foreach ( array_slice( $cart_contents, 0, 3 ) as $item ) {
			if ( isset( $item['data'] ) && $item['data'] ) {
				$item_names[] = $item['data']->get_name();
			}
		}

		$cart_desc = implode( ', ', $item_names );
		if ( $cart_count > 3 ) {
			$cart_desc .= sprintf( ' (+%d more)', $cart_count - 3 );
		}

		return sprintf(
			__( '"%s" is not in your cart. Current items: %s', 'glimmr-ai' ),
			$search_desc,
			$cart_desc
		);
	}

	/**
	 * Build suggestion for disambiguation.
	 *
	 * @param array $matches Matching items.
	 * @return string Suggestion message.
	 */
	private function build_disambiguation_suggestion( $matches ) {
		$options = array();

		foreach ( $matches as $i => $match ) {
			$desc = $match['product_name'];
			if ( ! empty( $match['variation_desc'] ) ) {
				$desc .= ' (' . $match['variation_desc'] . ')';
			}
			$desc .= ' - qty: ' . $match['quantity'];
			$options[] = ( $i + 1 ) . '. ' . $desc;
		}

		return sprintf(
			__( 'Found %d items. Which one? %s', 'glimmr-ai' ),
			count( $matches ),
			implode( ' | ', $options )
		);
	}
}
