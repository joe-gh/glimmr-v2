<?php
/**
 * Product Resolver Tool
 *
 * Resolves product names/queries to specific product IDs with confidence scores.
 * Bridges ambiguity between natural language and product IDs.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Resolve_Product
 *
 * Resolver tool that safely bridges ambiguous product references
 * to concrete product IDs before action tools are called.
 */
class Glimmr_AI_Tool_Resolve_Product extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'resolve_product';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Resolve a product name, partial name, or search phrase to specific product IDs with confidence scores. Use before compare or add_to_cart when you have a name instead of an ID.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'query' => array(
			'type'        => 'string',
			'required'    => true,
			'description' => 'Product name, partial name, or search phrase to resolve',
		),
		'category' => array(
			'type'        => 'string',
			'description' => 'Optional category to narrow search',
		),
		'limit' => array(
			'type'        => 'integer',
			'description' => 'Maximum candidates to return (default: 5, max: 10)',
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

		$query = $this->get_string_arg( $arguments, 'query', '' );
		if ( empty( $query ) ) {
			return $this->format_validation_error(
				'missing_required',
				'query',
				__( 'Required field "query" is missing.', 'glimmr-ai' )
			);
		}

		$category = $this->get_string_arg( $arguments, 'category', '' );
		$limit = min( $this->get_int_arg( $arguments, 'limit', 5 ), 10 );

		// Find matching products.
		$candidates = $this->find_candidates( $query, $category, $limit );

		if ( empty( $candidates ) ) {
			return $this->format_outcome(
				'not_found',
				array(
					'query'    => $query,
					'category' => $category,
				),
				sprintf( __( 'No products found matching "%s".', 'glimmr-ai' ), $query )
			);
		}

		// Determine resolution status.
		$top_candidate = $candidates[0];
		$status = 'ambiguous';

		// High confidence single match.
		if ( $top_candidate['confidence'] >= 0.9 ) {
			$status = 'resolved';
		} elseif ( count( $candidates ) === 1 && $top_candidate['confidence'] >= 0.7 ) {
			$status = 'resolved';
		}

		// Build suggestion based on status.
		$suggestion = '';
		if ( $status === 'ambiguous' ) {
			$names = array_map( function( $c ) {
				return sprintf( '"%s" (ID: %d)', $c['name'], $c['product_id'] );
			}, array_slice( $candidates, 0, 3 ) );
			$suggestion = sprintf(
				__( 'Found %d products. Did you mean: %s?', 'glimmr-ai' ),
				count( $candidates ),
				implode( ', ', $names )
			);
		} elseif ( $status === 'resolved' ) {
			$suggestion = sprintf(
				__( 'Resolved to "%s" (ID: %d)', 'glimmr-ai' ),
				$top_candidate['name'],
				$top_candidate['product_id']
			);
		}

		return $this->format_outcome(
			$status,
			array(
				'candidates'       => $candidates,
				'best_match'       => $status === 'resolved' ? $top_candidate : null,
				'query'            => $query,
				'category'         => $category,
				'candidate_count'  => count( $candidates ),
			),
			$suggestion
		);
	}

	/**
	 * Find candidate products matching the query.
	 *
	 * @param string $query    Search query.
	 * @param string $category Optional category filter.
	 * @param int    $limit    Maximum results.
	 * @return array Array of candidate matches with confidence scores.
	 */
	private function find_candidates( $query, $category, $limit ) {
		global $wpdb;

		$candidates = array();
		$query_lower = strtolower( trim( $query ) );

		// Step 1: Try exact title match.
		$exact_matches = $this->find_exact_matches( $query, $category );
		foreach ( $exact_matches as $product ) {
			$candidates[] = array(
				'product_id'   => $product->get_id(),
				'name'         => $product->get_name(),
				'confidence'   => 0.95,
				'match_reason' => 'exact_name',
				'price'        => $product->get_price(),
				'in_stock'     => $product->is_in_stock(),
			);
		}

		// Step 2: Try partial/fuzzy matches if needed.
		if ( count( $candidates ) < $limit ) {
			$partial_matches = $this->find_partial_matches( $query, $category, $limit - count( $candidates ) );
			foreach ( $partial_matches as $product ) {
				// Skip duplicates.
				$existing_ids = array_column( $candidates, 'product_id' );
				if ( in_array( $product->get_id(), $existing_ids, true ) ) {
					continue;
				}

				// Calculate confidence based on name similarity.
				$name_lower = strtolower( $product->get_name() );
				$confidence = $this->calculate_confidence( $query_lower, $name_lower );

				$candidates[] = array(
					'product_id'   => $product->get_id(),
					'name'         => $product->get_name(),
					'confidence'   => $confidence,
					'match_reason' => $this->get_match_reason( $query_lower, $name_lower ),
					'price'        => $product->get_price(),
					'in_stock'     => $product->is_in_stock(),
				);
			}
		}

		// Step 3: Try SKU match.
		if ( count( $candidates ) < $limit ) {
			$sku_match = wc_get_product_id_by_sku( $query );
			if ( $sku_match ) {
				$existing_ids = array_column( $candidates, 'product_id' );
				if ( ! in_array( $sku_match, $existing_ids, true ) ) {
					$product = wc_get_product( $sku_match );
					if ( $product && $product->get_status() === 'publish' ) {
						$candidates[] = array(
							'product_id'   => $product->get_id(),
							'name'         => $product->get_name(),
							'confidence'   => 0.98,
							'match_reason' => 'sku_match',
							'price'        => $product->get_price(),
							'in_stock'     => $product->is_in_stock(),
						);
					}
				}
			}
		}

		// Sort by confidence descending.
		usort( $candidates, function( $a, $b ) {
			return $b['confidence'] <=> $a['confidence'];
		} );

		return array_slice( $candidates, 0, $limit );
	}

	/**
	 * Find products with exact title match.
	 *
	 * @param string $query    Search query.
	 * @param string $category Optional category filter.
	 * @return array Array of WC_Product objects.
	 */
	private function find_exact_matches( $query, $category ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'title'          => $query,
		);

		if ( ! empty( $category ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_title( $category ),
				),
			);
		}

		$query_obj = new WP_Query( $args );
		$products = array();

		while ( $query_obj->have_posts() ) {
			$query_obj->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				$products[] = $product;
			}
		}
		wp_reset_postdata();

		return $products;
	}

	/**
	 * Find products with partial title match.
	 *
	 * @param string $query    Search query.
	 * @param string $category Optional category filter.
	 * @param int    $limit    Maximum results.
	 * @return array Array of WC_Product objects.
	 */
	private function find_partial_matches( $query, $category, $limit ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			's'              => $query,
		);

		if ( ! empty( $category ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_title( $category ),
				),
			);
		}

		$query_obj = new WP_Query( $args );
		$products = array();

		while ( $query_obj->have_posts() ) {
			$query_obj->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				$products[] = $product;
			}
		}
		wp_reset_postdata();

		return $products;
	}

	/**
	 * Calculate confidence score based on string similarity.
	 *
	 * @param string $query      The search query (lowercase).
	 * @param string $name_lower The product name (lowercase).
	 * @return float Confidence score 0-1.
	 */
	private function calculate_confidence( $query, $name_lower ) {
		// Exact match.
		if ( $query === $name_lower ) {
			return 0.95;
		}

		// Query is contained in name.
		if ( strpos( $name_lower, $query ) !== false ) {
			// Higher confidence if it's at the start.
			if ( strpos( $name_lower, $query ) === 0 ) {
				return 0.85;
			}
			return 0.75;
		}

		// Name contains query words.
		$query_words = explode( ' ', $query );
		$name_words = explode( ' ', $name_lower );
		$matches = 0;

		foreach ( $query_words as $qw ) {
			if ( strlen( $qw ) < 2 ) {
				continue;
			}
			foreach ( $name_words as $nw ) {
				if ( strpos( $nw, $qw ) !== false || strpos( $qw, $nw ) !== false ) {
					$matches++;
					break;
				}
			}
		}

		$match_ratio = $matches / count( $query_words );
		return min( 0.7, 0.4 + ( $match_ratio * 0.3 ) );
	}

	/**
	 * Get a human-readable match reason.
	 *
	 * @param string $query      The search query (lowercase).
	 * @param string $name_lower The product name (lowercase).
	 * @return string Match reason.
	 */
	private function get_match_reason( $query, $name_lower ) {
		if ( $query === $name_lower ) {
			return 'exact_name';
		}

		if ( strpos( $name_lower, $query ) === 0 ) {
			return 'starts_with';
		}

		if ( strpos( $name_lower, $query ) !== false ) {
			return 'contains_phrase';
		}

		return 'partial_match';
	}
}
