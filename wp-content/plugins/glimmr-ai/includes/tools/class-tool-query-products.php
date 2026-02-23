<?php
/**
 * Unified Product Query Tool
 *
 * Merges product_lookup, stock_check, and product_compare into a single
 * tool with mode-specific nested objects.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Query_Products
 *
 * Unified product query tool with nested mode objects for search, compare,
 * details, and stock_check operations.
 */
class Glimmr_AI_Tool_Query_Products extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'query_products';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Search products, compare products, get product details, or check stock. Use the appropriate mode and nested object for your query.';

	/**
	 * Tool parameters with nested mode objects.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'mode' => array(
			'type'        => 'string',
			'enum'        => array( 'search', 'compare', 'details', 'stock_check', 'aggregate' ),
			'required'    => true,
			'description' => 'Query mode: search (find products), compare (side-by-side), details (single product), stock_check (availability), aggregate (COUNT/AVG/SUM/MIN/MAX)',
		),
		'search' => array(
			'type'        => 'object',
			'description' => 'Search parameters (required when mode=search)',
			'additionalProperties' => false,
			'properties'  => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Text search query',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Category name or slug to filter by',
				),
				'min_price' => array(
					'type'        => 'number',
					'description' => 'Minimum price filter',
				),
				'max_price' => array(
					'type'        => 'number',
					'description' => 'Maximum price filter',
				),
				'size' => array(
					'type'        => 'string',
					'description' => 'Size attribute filter',
				),
				'color' => array(
					'type'        => 'string',
					'description' => 'Color attribute filter',
				),
				'in_stock' => array(
					'type'        => 'boolean',
					'description' => 'Only show in-stock products (default: true)',
				),
				'on_sale' => array(
					'type'        => 'boolean',
					'description' => 'Only show products on sale',
				),
				'sort' => array(
					'type'        => 'string',
					'enum'        => array( 'relevance', 'price_asc', 'price_desc', 'rating', 'popularity', 'newest' ),
					'description' => 'Sort order (default: relevance)',
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Maximum results to return (default: 4, max: 20)',
					'minimum'     => 1,
					'maximum'     => 20,
				),
			),
		),
		'compare' => array(
			'type'        => 'object',
			'description' => 'Compare parameters (required when mode=compare)',
			'additionalProperties' => false,
			'properties'  => array(
				'product_ids' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Array of product IDs to compare (2-8 products)',
					'minItems'    => 2,
					'maxItems'    => 8,
				),
				'product_names' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Array of product names to compare (alternative to IDs)',
					'minItems'    => 2,
					'maxItems'    => 8,
				),
				'search_params' => array(
					'type'                 => 'object',
					'description'          => 'Search for products to compare (alternative to IDs/names). Finds products and compares them in one call.',
					'additionalProperties' => false,
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Text search query',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Category name or slug',
						),
						'sort' => array(
							'type'        => 'string',
							'enum'        => array( 'relevance', 'price_asc', 'price_desc', 'rating', 'popularity', 'newest' ),
							'description' => 'Sort order to select products (e.g., "rating" for top rated)',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Number of products to compare (2-8, default: 3)',
							'minimum'     => 2,
							'maximum'     => 8,
						),
					),
				),
				'strict' => array(
					'type'        => 'boolean',
					'description' => 'If true, error when products not found instead of partial results (default: true)',
				),
			),
		),
		'details' => array(
			'type'        => 'object',
			'description' => 'Details parameters (required when mode=details)',
			'additionalProperties' => false,
			'properties'  => array(
				'product_id' => array(
					'type'        => 'integer',
					'description' => 'Product ID to get details for',
					'required'    => true,
					'minimum'     => 1,
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => 'Include variation data for variable products (default: true)',
				),
				'auto_open_modal' => array(
					'type'        => 'boolean',
					'description' => 'If true, automatically opens the product detail modal in the UI. Use when user explicitly asks for details on a specific product (e.g., "tell me more about X", "show me details for X").',
				),
			),
		),
		'stock_check' => array(
			'type'        => 'object',
			'description' => 'Stock check parameters (required when mode=stock_check)',
			'additionalProperties' => false,
			'properties'  => array(
				'product_ids' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Array of product IDs to check stock for',
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => 'Include stock for all variations (default: false)',
				),
			),
		),
		'aggregate' => array(
			'type'        => 'object',
			'description' => 'Aggregation parameters (required when mode=aggregate). Use for catalog analytics like counting products or calculating averages.',
			'additionalProperties' => false,
			'properties'  => array(
				'function' => array(
					'type'        => 'string',
					'enum'        => array( 'COUNT', 'AVG', 'SUM', 'MIN', 'MAX' ),
					'required'    => true,
					'description' => 'Aggregation function to apply',
				),
				'column' => array(
					'type'        => 'string',
					'enum'        => array( 'price', 'regular_price', 'sale_price', 'average_rating', 'review_count', 'total_sales', 'stock_quantity' ),
					'description' => 'Column to aggregate (use * with COUNT for counting rows)',
				),
				'group_by' => array(
					'type'        => 'string',
					'enum'        => array( 'category', 'type', 'stock_status', 'on_sale', 'featured' ),
					'description' => 'Group results by this field',
				),
				'where' => array(
					'type'        => 'object',
					'description' => 'Filter conditions before aggregating',
					'additionalProperties' => false,
					'properties'  => array(
						'category' => array(
							'type'        => 'string',
							'description' => 'Filter by category slug',
						),
						'min_price' => array(
							'type'        => 'number',
							'description' => 'Minimum price filter',
						),
						'max_price' => array(
							'type'        => 'number',
							'description' => 'Maximum price filter',
						),
						'in_stock' => array(
							'type'        => 'boolean',
							'description' => 'Only include in-stock products',
						),
						'on_sale' => array(
							'type'        => 'boolean',
							'description' => 'Only include products on sale',
						),
						'featured' => array(
							'type'        => 'boolean',
							'description' => 'Only include featured products',
						),
					),
				),
			),
		),
	);

	/**
	 * Normalize parameters to canonical nested form.
	 *
	 * Accepts both flat parameters (legacy) and nested parameters (canonical).
	 * Converts flat parameters to nested form for the current mode.
	 *
	 * Example: {mode: "search", query: "hoodies"} becomes
	 *          {mode: "search", search: {query: "hoodies"}}
	 *
	 * @param array $params Tool parameters.
	 * @return array Normalized parameters with nested objects.
	 */
	private function normalize_params( $params ) {
		$mode = $params['mode'] ?? 'search';

		// If nested object already exists and is valid, params are already canonical.
		if ( isset( $params[ $mode ] ) && is_array( $params[ $mode ] ) ) {
			return $params;
		}

		// Define which flat fields belong to each mode's nested object.
		$flat_fields = array(
			'search'      => array( 'query', 'semantic', 'category', 'min_price', 'max_price', 'size', 'color', 'in_stock', 'on_sale', 'sort', 'limit' ),
			'compare'     => array( 'product_ids', 'product_names', 'search_params', 'strict' ),
			'details'     => array( 'product_id', 'auto_open_modal' ),
			'stock_check' => array( 'product_ids', 'product_id', 'query', 'variation_id', 'size', 'color' ),
			'aggregate'   => array( 'function', 'column', 'group_by', 'filters', 'limit' ),
		);

		// Collect flat params into nested object.
		$nested = array();
		$fields_for_mode = $flat_fields[ $mode ] ?? array();

		foreach ( $fields_for_mode as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$nested[ $field ] = $params[ $field ];
			}
		}

		// Only add nested object if we found flat params to migrate.
		if ( ! empty( $nested ) ) {
			$params[ $mode ] = $nested;
		}

		return $params;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	public function execute( $arguments ) {
		$wc_check = $this->require_wc();
		if ( $wc_check ) {
			return $wc_check;
		}

		// Normalize flat params to nested form for backwards compatibility.
		$arguments = $this->normalize_params( $arguments );

		// Validate mode.
		$mode = $this->get_string_arg( $arguments, 'mode', '' );
		if ( empty( $mode ) ) {
			return $this->format_validation_error(
				'missing_required',
				'mode',
				__( 'Required field "mode" is missing.', 'glimmr-ai' )
			);
		}

		// Dispatch to mode-specific handler.
		switch ( $mode ) {
			case 'search':
				return $this->execute_search( $arguments );

			case 'compare':
				return $this->execute_compare( $arguments );

			case 'details':
				return $this->execute_details( $arguments );

			case 'stock_check':
				return $this->execute_stock_check( $arguments );

			case 'aggregate':
				return $this->execute_aggregate( $arguments );

			default:
				return $this->format_validation_error(
					'invalid_enum',
					'mode',
					sprintf( __( 'Invalid mode "%s". Must be one of: search, compare, details, stock_check, aggregate', 'glimmr-ai' ), $mode ),
					array( 'allowed_values' => array( 'search', 'compare', 'details', 'stock_check', 'aggregate' ) )
				);
		}
	}

	/**
	 * Execute search mode.
	 *
	 * Uses semantic search with metadata filtering via OpenAI vector store.
	 * Price, stock, and sale constraints are applied as metadata filters
	 * at the vector store level, eliminating the need for SQL fallback.
	 *
	 * @since 1.9.0 Replaced hybrid semantic+SQL with pure semantic + metadata filtering.
	 * @param array $arguments Tool arguments.
	 * @return array Search results.
	 */
	private function execute_search( $arguments ) {
		$search_params = $this->get_nested_arg( $arguments, 'search', array() );

		if ( empty( $search_params ) || ! is_array( $search_params ) ) {
			return $this->format_validation_error(
				'missing_required',
				'search',
				__( 'mode=search requires the "search" object with query parameters.', 'glimmr-ai' )
			);
		}

		$query = $search_params['query'] ?? '';
		$category = $search_params['category'] ?? '';
		$limit = min( $search_params['limit'] ?? 4, 20 );

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			Glimmr_AI_Logger::debug(
				'query_products execute_search called',
				array(
					'query'         => $query,
					'category'      => $category,
					'limit'         => $limit,
					'search_params' => $search_params,
				),
				'tools'
			);
		}

		// Build semantic query from query + category.
		$semantic_query = $query;
		if ( empty( $semantic_query ) && ! empty( $category ) ) {
			$semantic_query = $category;
		}

		if ( empty( $semantic_query ) ) {
			return $this->format_validation_error(
				'missing_required', 'query',
				__( 'A search query or category is required.', 'glimmr-ai' )
			);
		}

		// Build params for semantic search.
		$semantic_params = $search_params;
		if ( empty( $search_params['query'] ) && ! empty( $category ) ) {
			$semantic_params['query'] = $category;
			unset( $semantic_params['category'] );
		}

		$semantic_result = $this->execute_semantic_search( $semantic_params, $limit );
		if ( ! empty( $semantic_result ) ) {
			return $semantic_result;
		}

		// No results — return empty instead of SQL fallback.
		return $this->format_outcome(
			'product_search',
			array(
				'products' => array(),
				'total'    => 0,
				'query'    => $semantic_query,
			),
			sprintf(
				__( 'No products found matching "%s".', 'glimmr-ai' ),
				$semantic_query
			)
		);
	}

	/**
	 * Build metadata filters for OpenAI vector store file_search.
	 *
	 * Translates search parameters (price range, stock status, on sale) into
	 * the filter format expected by the OpenAI file_search tool. Filters are
	 * applied at the vector store level before semantic ranking.
	 *
	 * @since 1.9.0
	 * @param array $search_params Search parameters from tool arguments.
	 * @return array|null Filter object for OpenAI, or null if no filters needed.
	 */
	private function build_metadata_filters( $search_params ) {
		$filters = array();

		// Stock filter (default: in-stock only).
		$in_stock = $search_params['in_stock'] ?? true;
		if ( $in_stock ) {
			$filters[] = array(
				'type'  => 'eq',
				'key'   => 'stock_status',
				'value' => 'instock',
			);
		}

		// Price filters.
		$min_price = floatval( $search_params['min_price'] ?? 0 );
		$max_price = floatval( $search_params['max_price'] ?? 0 );

		if ( $min_price > 0 ) {
			// Product's max_price must be >= user's min (variable products).
			$filters[] = array(
				'type'  => 'gte',
				'key'   => 'max_price',
				'value' => $min_price,
			);
		}

		if ( $max_price > 0 ) {
			// Product's min price must be <= user's max.
			$filters[] = array(
				'type'  => 'lte',
				'key'   => 'price',
				'value' => $max_price,
			);
		}

		// On sale filter.
		if ( ! empty( $search_params['on_sale'] ) ) {
			$filters[] = array(
				'type'  => 'eq',
				'key'   => 'on_sale',
				'value' => 'true',
			);
		}

		// No filters needed? Return null (no filter object).
		if ( empty( $filters ) ) {
			return null;
		}

		// Single filter or compound AND.
		if ( count( $filters ) === 1 ) {
			return $filters[0];
		}

		return array(
			'type'    => 'and',
			'filters' => $filters,
		);
	}

		/**
	 * Execute semantic search via vector store with metadata filtering.
	 *
	 * Uses the candidates + signals pattern:
	 * 1. Build metadata filters (price, stock, sale) from search params
	 * 2. Get top K results from vector store with filters applied
	 * 3. Compute lexical signals (term matching, title matching)
	 * 4. Return candidates with signals for LLM selection
	 *
	 * @since 1.9.0 Added metadata filtering support.
	 * @param array $search_params Search parameters.
	 * @param int   $limit         Result limit.
	 * @return array|null Search results or null if no matches.
	 */
	private function execute_semantic_search( $search_params, $limit ) {
		$query = $search_params['query'] ?? '';

		// Get OpenAI instance for vector store search.
		$glimmr_ai = Glimmr_AI::get_instance();
		$openai = $glimmr_ai->get_openai();

		if ( ! $openai->has_vector_store() ) {
			if ( class_exists( 'Glimmr_AI_Logger' ) ) {
				Glimmr_AI_Logger::warning( 'Semantic search: No vector store configured', array(), 'tools' );
			}
			return $this->format_outcome(
				'product_search',
				array( 'products' => array(), 'total' => 0 ),
				__( 'Product search is not available. Please configure the vector store in settings.', 'glimmr-ai' )
			);
		}

		// Get top K candidates (default 10, configurable).
		$top_k = apply_filters( 'glimmr_ai_semantic_top_k', 10 );

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			Glimmr_AI_Logger::debug(
				'Calling OpenAI search_vector_store',
				array( 'query' => $query, 'max_results' => $top_k ),
				'tools'
			);
		}

		// Build metadata filters for price, stock, sale constraints.
		$metadata_filters = $this->build_metadata_filters( $search_params );

		$search_options = array( 'max_results' => $top_k );
		if ( $metadata_filters ) {
			$search_options['filters'] = $metadata_filters;
		}

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			Glimmr_AI_Logger::debug(
				'Semantic search with metadata filters',
				array( 'query' => $query, 'filters' => $metadata_filters ),
				'tools'
			);
		}

		// Search vector store for semantically similar products with metadata filtering.
		$semantic_results = $openai->search_vector_store( $query, $search_options );

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			$result_count = count( $semantic_results );
			Glimmr_AI_Logger::debug(
				'OpenAI search_vector_store returned',
				array(
					'result_count' => $result_count,
					'results'      => array_slice( $semantic_results, 0, 3 ),
				),
				'tools'
			);
		}

		// Check for empty results.
		if ( empty( $semantic_results ) ) {
			return null;
		}

		// Extract product IDs with scores (dedupe while preserving score order).
		$product_scores = array();
		foreach ( $semantic_results as $result ) {
			$pid = $result['product_id'] ?? null;
			$score = $result['score'] ?? 0;
			if ( $pid && ! isset( $product_scores[ $pid ] ) ) {
				$product_scores[ $pid ] = $score;
			}
		}

		$product_ids = array_keys( $product_scores );
		if ( empty( $product_ids ) ) {
			if ( class_exists( 'Glimmr_AI_Logger' ) ) {
				Glimmr_AI_Logger::warning( 'Semantic search: No product IDs extracted from results', array(), 'tools' );
			}
			return null;
		}

		// Compute lexical signals using the product indexer.
		$lexical_signals = array();
		$product_indexer = $glimmr_ai->get_product_indexer();
		$lexical_signals = $product_indexer->compute_lexical_signals( $product_ids, $query );

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			Glimmr_AI_Logger::debug(
				'Computed lexical signals',
				array(
					'product_count'   => count( $product_ids ),
					'signals_count'   => count( $lexical_signals ),
					'sample_signals'  => array_slice( $lexical_signals, 0, 2, true ),
				),
				'tools'
			);
		}

		// Format candidates response for LLM selection.
		return $this->format_candidates_response(
			$product_ids,
			$product_scores,
			$lexical_signals,
			$query,
			$search_params
		);
	}

	/**
	 * Format candidates response with signals for LLM selection.
	 *
	 * Returns minimal product data + relevance signals so the LLM can
	 * intelligently select which products are truly relevant.
	 *
	 * @param array  $product_ids     Product IDs from semantic search.
	 * @param array  $product_scores  Product ID => semantic score mapping.
	 * @param array  $lexical_signals Product ID => lexical signals mapping.
	 * @param string $query           Original search query.
	 * @param array  $search_params   Search parameters.
	 * @return array|null Formatted candidates response or null if no candidates.
	 */
	private function format_candidates_response( $product_ids, $product_scores, $lexical_signals, $query, $search_params ) {
		$candidates = array();

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || $product->get_status() !== 'publish' ) {
				continue;
			}

			// Stock filtering is now handled by metadata filters at the vector store level.
			// Only the publish status check remains here.

			// Get signals for this product.
			$semantic_score = $product_scores[ $pid ] ?? 0;
			$signals = $lexical_signals[ $pid ] ?? array();

			$candidates[] = array(
				// Core identification.
				'product_id'          => $pid,
				'name'                => $product->get_name(),
				'type'                => $product->get_type(),

				// Price info (minimal).
				'price'               => $this->format_price( $product->get_price() ),
				'price_raw'           => (float) $product->get_price(),

				// Relevance signals for LLM.
				'semantic_score'      => round( $semantic_score, 3 ),
				'lexical_score'       => round( $signals['lexical_score'] ?? 0, 3 ),
				'matched_terms'       => $signals['matched_terms'] ?? array(),
				'title_contains_query' => $signals['title_contains_query'] ?? false,
				'exact_match'         => $signals['exact_match'] ?? false,
				'sku_match'           => $signals['sku_match'] ?? false,
				'match_ratio'         => round( $signals['match_ratio'] ?? 0, 2 ),

				// Brief context (for LLM to understand product).
				'short_desc'          => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 15, '...' ),
				'in_stock'            => $product->is_in_stock(),
			);
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		$in_stock = $search_params['in_stock'] ?? true;

		return $this->format_outcome(
			'product_candidates',
			array(
				'query'           => $query,
				'candidates'      => $candidates,
				'count'           => count( $candidates ),
				'search_method'   => 'semantic',
				'applied_filters' => array_filter( array(
					'query'     => $search_params['query'] ?? null,
					'category'  => $search_params['category'] ?? null,
					'min_price' => $search_params['min_price'] ?? null,
					'max_price' => $search_params['max_price'] ?? null,
					'in_stock'  => $in_stock,
					'on_sale'   => $search_params['on_sale'] ?? null,
				) ),
				'instructions'    => 'Review candidates and call select_products with product_ids of the most relevant products.',
			),
			sprintf(
				__( 'Found %d candidate products for "%s". Review the signals and select the most relevant ones.', 'glimmr-ai' ),
				count( $candidates ),
				$query
			)
		);
	}

	/**
	 * Execute compare mode.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Comparison results.
	 */
	private function execute_compare( $arguments ) {
		$compare_params = $this->get_nested_arg( $arguments, 'compare', array() );

		if ( empty( $compare_params ) || ! is_array( $compare_params ) ) {
			return $this->format_validation_error(
				'missing_required',
				'compare',
				__( 'mode=compare requires the "compare" object with product_ids, product_names, or search_params.', 'glimmr-ai' )
			);
		}

		$strict = $compare_params['strict'] ?? true;
		$product_ids = array();
		$not_found = array();
		$auto_selected_info = null;

		// Helper to check if product_ids has valid (non-zero) IDs.
		$has_valid_product_ids = ! empty( $compare_params['product_ids'] )
			&& is_array( $compare_params['product_ids'] )
			&& count( array_filter( $compare_params['product_ids'], function( $id ) {
				return is_numeric( $id ) && intval( $id ) > 0;
			} ) ) >= 2;

		// Helper to check if product_names has valid (non-empty) names.
		$has_valid_product_names = ! empty( $compare_params['product_names'] )
			&& is_array( $compare_params['product_names'] )
			&& count( array_filter( $compare_params['product_names'], function( $name ) {
				return is_string( $name ) && strlen( trim( $name ) ) > 0;
			} ) ) >= 2;

		// Helper to check if search_params has a meaningful query.
		$has_valid_search_params = ! empty( $compare_params['search_params'] )
			&& is_array( $compare_params['search_params'] )
			&& (
				! empty( trim( $compare_params['search_params']['query'] ?? '' ) )
				|| ! empty( trim( $compare_params['search_params']['category'] ?? '' ) )
			);

		if ( class_exists( 'Glimmr_AI_Logger' ) ) {
			Glimmr_AI_Logger::debug(
				'execute_compare: Determining comparison source',
				array(
					'has_valid_product_ids'    => $has_valid_product_ids,
					'has_valid_product_names'  => $has_valid_product_names,
					'has_valid_search_params'  => $has_valid_search_params,
					'raw_product_ids'          => $compare_params['product_ids'] ?? null,
					'raw_product_names'        => $compare_params['product_names'] ?? null,
					'raw_search_params'        => $compare_params['search_params'] ?? null,
				),
				'tools'
			);
		}

		// Priority: product_ids > product_names > search_params.
		// This ensures explicit IDs are used when provided, even if search_params exists with empty values.
		if ( $has_valid_product_ids ) {
			// Get product IDs from array.
			$product_ids = array_map( 'absint', $compare_params['product_ids'] );
			// Filter out zeros.
			$product_ids = array_filter( $product_ids, function( $id ) {
				return $id > 0;
			} );
			$product_ids = array_values( $product_ids );

			if ( class_exists( 'Glimmr_AI_Logger' ) ) {
				Glimmr_AI_Logger::debug(
					'execute_compare: Using product_ids path',
					array( 'product_ids' => $product_ids ),
					'tools'
				);
			}

		} elseif ( $has_valid_product_names ) {
			// Resolve product names to IDs.
			foreach ( $compare_params['product_names'] as $name ) {
				if ( ! is_string( $name ) || strlen( trim( $name ) ) === 0 ) {
					continue;
				}
				$resolved = $this->resolve_product_name( $name );
				if ( $resolved ) {
					$product_ids[] = $resolved;
				} else {
					$not_found[] = $name;
				}
			}

			if ( class_exists( 'Glimmr_AI_Logger' ) ) {
				Glimmr_AI_Logger::debug(
					'execute_compare: Using product_names path',
					array(
						'resolved_ids' => $product_ids,
						'not_found'    => $not_found,
					),
					'tools'
				);
			}

		} elseif ( $has_valid_search_params ) {
			// Handle search_params for search-then-compare (one-call pattern).
			$search_params = $compare_params['search_params'];
			$limit = min( max( $search_params['limit'] ?? 4, 2 ), 8 );

			if ( class_exists( 'Glimmr_AI_Logger' ) ) {
				Glimmr_AI_Logger::debug(
					'execute_compare: Using search_params path',
					array( 'search_params' => $search_params, 'limit' => $limit ),
					'tools'
				);
			}

			// Build search arguments.
			$search_args = array(
				'mode'   => 'search',
				'search' => array_merge( $search_params, array( 'limit' => $limit ) ),
			);

			// Run search.
			$search_result = $this->execute_search( $search_args );

			if ( ! $search_result['success'] || empty( $search_result['data']['products'] ) ) {
				return $this->format_outcome(
					'no_results',
					array( 'search_params' => $search_params ),
					__( 'No products found matching search criteria for comparison.', 'glimmr-ai' )
				);
			}

			// Extract product IDs from search results.
			$product_ids = array_column( $search_result['data']['products'], 'id' );

			// Track auto-selection for transparency.
			$auto_selected_info = array(
				'auto_selected'     => true,
				'search_params'     => $search_params,
				'selected_products' => array_map( function( $p ) {
					return array( 'id' => $p['id'], 'name' => $p['name'] );
				}, $search_result['data']['products'] ),
			);

		} else {
			return $this->format_validation_error(
				'missing_required',
				'compare.product_ids',
				__( 'compare requires either product_ids[] (with at least 2 valid IDs), product_names[] (with at least 2 names), or search_params (with a query or category).', 'glimmr-ai' )
			);
		}

		// Validate count.
		if ( count( $product_ids ) < 2 ) {
			if ( $strict && ! empty( $not_found ) ) {
				return $this->format_outcome(
					'needs_disambiguation',
					array(
						'not_found'   => $not_found,
						'found_count' => count( $product_ids ),
					),
					sprintf( __( 'Could not find products: %s. Please provide valid product IDs or names.', 'glimmr-ai' ), implode( ', ', $not_found ) )
				);
			}
			return $this->format_validation_error(
				'too_few_items',
				'compare.product_ids',
				__( 'compare requires at least 2 products.', 'glimmr-ai' )
			);
		}

		if ( count( $product_ids ) > 8 ) {
			$product_ids = array_slice( $product_ids, 0, 8 );
		}

		// Fetch products.
		$products = array();
		$missing_ids = array();

		foreach ( $product_ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->get_status() === 'publish' ) {
				$products[] = $this->format_product_for_compare( $product );
			} else {
				$missing_ids[] = $id;
			}
		}

		// Handle missing products in strict mode.
		if ( $strict && ! empty( $missing_ids ) ) {
			return $this->format_outcome(
				'needs_disambiguation',
				array(
					'not_found'   => $missing_ids,
					'found_count' => count( $products ),
				),
				sprintf( __( 'Products not found: %s', 'glimmr-ai' ), implode( ', ', $missing_ids ) )
			);
		}

		if ( count( $products ) < 2 ) {
			return $this->format_error( __( 'Not enough valid products found for comparison.', 'glimmr-ai' ) );
		}

		// Build comparison matrix.
		$comparison = $this->build_comparison_matrix( $products );

		// Build result data.
		$result_data = array(
			'mode'       => 'compare',
			'products'   => $products,
			'comparison' => $comparison,
			'count'      => count( $products ),
		);

		// Include auto-selection info if products were found via search.
		if ( $auto_selected_info ) {
			$result_data['auto_selection'] = $auto_selected_info;
		}

		return $this->format_outcome( 'success', $result_data );
	}

	/**
	 * Execute details mode.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Product details.
	 */
	private function execute_details( $arguments ) {
		$details_params = $this->get_nested_arg( $arguments, 'details', array() );

		if ( empty( $details_params ) || ! is_array( $details_params ) ) {
			return $this->format_validation_error(
				'missing_required',
				'details',
				__( 'mode=details requires the "details" object with product_id.', 'glimmr-ai' )
			);
		}

		$product_id = $details_params['product_id'] ?? null;
		if ( ! $product_id ) {
			return $this->format_validation_error(
				'missing_required',
				'details.product_id',
				__( 'details.product_id is required.', 'glimmr-ai' )
			);
		}

		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product || $product->get_status() !== 'publish' ) {
			return $this->format_outcome(
				'not_found',
				array( 'product_id' => $product_id ),
				sprintf( __( 'Product with ID %d not found.', 'glimmr-ai' ), $product_id )
			);
		}

		$include_variations = $details_params['include_variations'] ?? true;
		$auto_open_modal = ! empty( $details_params['auto_open_modal'] );
		$formatted = $this->format_product_detailed( $product, $include_variations );

		return $this->format_outcome(
			'success',
			array(
				'mode'            => 'details',
				'product'         => $formatted,
				'auto_open_modal' => $auto_open_modal,
			)
		);
	}

	/**
	 * Execute stock_check mode.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Stock information.
	 */
	private function execute_stock_check( $arguments ) {
		$stock_params = $this->get_nested_arg( $arguments, 'stock_check', array() );

		if ( empty( $stock_params ) || ! is_array( $stock_params ) ) {
			return $this->format_validation_error(
				'missing_required',
				'stock_check',
				__( 'mode=stock_check requires the "stock_check" object with product_ids.', 'glimmr-ai' )
			);
		}

		$product_ids = $stock_params['product_ids'] ?? array();
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return $this->format_validation_error(
				'missing_required',
				'stock_check.product_ids',
				__( 'stock_check.product_ids[] is required.', 'glimmr-ai' )
			);
		}

		$include_variations = $stock_params['include_variations'] ?? false;
		$stock_info = array();

		foreach ( $product_ids as $id ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product ) {
				$stock_info[] = array(
					'product_id' => $id,
					'found'      => false,
				);
				continue;
			}

			$info = array(
				'product_id'   => $id,
				'found'        => true,
				'name'         => $product->get_name(),
				'stock_status' => $product->get_stock_status(),
				'in_stock'     => $product->is_in_stock(),
				'manage_stock' => $product->get_manage_stock(),
			);

			if ( $product->get_manage_stock() ) {
				$info['stock_quantity'] = $product->get_stock_quantity();
				$info['backorders']     = $product->get_backorders();
			}

			// Include variation stock if requested and product is variable.
			if ( $include_variations && $product->is_type( 'variable' ) ) {
				$variations = $product->get_available_variations();
				$info['variations'] = array();

				foreach ( $variations as $variation ) {
					$var_product = wc_get_product( $variation['variation_id'] );
					if ( $var_product ) {
						$var_info = array(
							'variation_id' => $variation['variation_id'],
							'attributes'   => $variation['attributes'],
							'stock_status' => $var_product->get_stock_status(),
							'in_stock'     => $var_product->is_in_stock(),
						);

						if ( $var_product->get_manage_stock() ) {
							$var_info['stock_quantity'] = $var_product->get_stock_quantity();
						}

						$info['variations'][] = $var_info;
					}
				}
			}

			$stock_info[] = $info;
		}

		// Summary.
		$in_stock_count = count( array_filter( $stock_info, function( $item ) {
			return ! empty( $item['in_stock'] );
		} ) );

		return $this->format_outcome(
			'success',
			array(
				'mode'               => 'stock_check',
				'products'           => $stock_info,
				'count'              => count( $stock_info ),
				'in_stock_count'     => $in_stock_count,
				'out_of_stock_count' => count( $stock_info ) - $in_stock_count,
			)
		);
	}

	/**
	 * Resolve product name to ID.
	 *
	 * @param string $name Product name to resolve.
	 * @return int|null Product ID or null if not found.
	 */
	private function resolve_product_name( $name ) {
		global $wpdb;

		// Try exact match first.
		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
			AND post_title = %s
			LIMIT 1",
			$name
		) );

		if ( $product_id ) {
			return absint( $product_id );
		}

		// Try LIKE match.
		$product_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
			AND post_title LIKE %s
			ORDER BY LENGTH(post_title) ASC
			LIMIT 1",
			'%' . $wpdb->esc_like( $name ) . '%'
		) );

		return $product_id ? absint( $product_id ) : null;
	}

	/**
	 * Format product for comparison.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Formatted product data.
	 */
	private function format_product_for_compare( $product ) {
		$data = $this->format_product( $product );

		// Add comparison-specific fields.
		$data['attributes'] = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
				// Get the human-readable label (translates pa_color → Color).
				$label = wc_attribute_label( $attr_name, $product );
				$data['attributes'][ $label ] = $options;
			}
		}

		$data['dimensions'] = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
			'weight' => $product->get_weight(),
		);

		return $data;
	}

	/**
	 * Format product with full details.
	 *
	 * @param WC_Product $product           Product object.
	 * @param bool       $include_variations Include variation data.
	 * @return array Formatted product data.
	 */
	private function format_product_detailed( $product, $include_variations = true ) {
		$data = $this->format_product( $product );

		// Add detailed fields.
		$data['description']       = $product->get_description();
		$data['short_description'] = $product->get_short_description();
		$data['sku']               = $product->get_sku();
		$data['weight']            = $product->get_weight();
		$data['dimensions']        = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);

		// Gallery images - include main image first, then gallery images.
		$data['gallery'] = array();

		// Add main product image first.
		$main_image_id = $product->get_image_id();
		if ( $main_image_id ) {
			$main_url = wp_get_attachment_url( $main_image_id );
			if ( $main_url ) {
				$data['gallery'][] = $main_url;
			}
		}

		// Add gallery images.
		$gallery_ids = $product->get_gallery_image_ids();
		foreach ( $gallery_ids as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$data['gallery'][] = $url;
			}
		}

		// Attributes - use friendly labels (Color instead of pa_color).
		// For variable products, use variation attributes which are user-selectable.
		$data['attributes'] = array();
		if ( $product->is_type( 'variable' ) ) {
			// Use get_variation_attributes() for variable products - these are the selectable options.
			foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
				$label = wc_attribute_label( $attr_name, $product );
				$data['attributes'][ $label ] = $options;
			}
		} else {
			// For simple/other products, use regular attributes.
			foreach ( $product->get_attributes() as $attr_name => $attr ) {
				$label = wc_attribute_label( $attr_name, $product );
				if ( $attr->is_taxonomy() ) {
					$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
					$data['attributes'][ $label ] = $terms;
				} else {
					$data['attributes'][ $label ] = $attr->get_options();
				}
			}
		}

		// Variations for variable products.
		if ( $include_variations && $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();
			$data['variations'] = array();

			// Track color swatches for available_options.
			$color_swatches = array();
			$sizes          = array();

			foreach ( array_slice( $variations, 0, 20 ) as $variation ) {
				$var_product = wc_get_product( $variation['variation_id'] );
				if ( $var_product ) {
					// Convert variation attributes to use friendly labels and display values.
					// WooCommerce returns keys like "attribute_pa_color" => "heather-blue" (slug).
					// We need to convert to "Color" => "Heather Blue" for frontend matching.
					$friendly_attrs = array();
					foreach ( $variation['attributes'] as $attr_key => $attr_value ) {
						// Remove "attribute_" prefix to get the taxonomy name.
						$taxonomy = str_replace( 'attribute_', '', $attr_key );
						$label    = wc_attribute_label( $taxonomy, $product );

						// Convert slug to display name for taxonomy attributes.
						// Empty string means "Any" - keep it empty for matching.
						$display_value = $attr_value;
						$swatch_image  = null;
						if ( '' !== $attr_value && taxonomy_exists( $taxonomy ) ) {
							$term = get_term_by( 'slug', $attr_value, $taxonomy );
							if ( $term ) {
								$display_value = $term->name;
								// Get swatch image from term meta (cfvsw_image from Color Filter Variation Swatches plugin).
								$swatch_image = get_term_meta( $term->term_id, 'cfvsw_image', true );
							}
						}

						$friendly_attrs[ $label ] = $display_value;

						// Track available options for quick reference.
						$label_lower = strtolower( $label );
						if ( strpos( $label_lower, 'color' ) !== false || strpos( $label_lower, 'colour' ) !== false ) {
							// Track color with swatch image.
							if ( '' !== $display_value && ! isset( $color_swatches[ $display_value ] ) ) {
								$color_swatches[ $display_value ] = $swatch_image;
							}
						} elseif ( strpos( $label_lower, 'size' ) !== false ) {
							if ( '' !== $display_value && ! in_array( $display_value, $sizes, true ) ) {
								$sizes[] = $display_value;
							}
						}
					}

					$data['variations'][] = array(
						'id'             => $variation['variation_id'], // Use 'id' for consistency with frontend.
						'variation_id'   => $variation['variation_id'],
						'attributes'     => $friendly_attrs,
						'price'          => $var_product->get_price(),
						'regular_price'  => $var_product->get_regular_price(),
						'sale_price'     => $var_product->get_sale_price() ?: null,
						'in_stock'       => $var_product->is_in_stock(),
						'stock_status'   => $var_product->get_stock_status(),
						'stock_quantity' => $var_product->get_stock_quantity(),
						'sku'            => $var_product->get_sku(),
					);
				}
			}

			// Build available_options with color swatches.
			$data['available_options'] = array(
				'colors' => array(),
				'sizes'  => $sizes,
			);
			foreach ( $color_swatches as $color_name => $swatch_url ) {
				$data['available_options']['colors'][] = array(
					'name'   => $color_name,
					'swatch' => $swatch_url ?: null,
				);
			}

			// Remove empty available_options.
			if ( empty( $data['available_options']['colors'] ) && empty( $data['available_options']['sizes'] ) ) {
				unset( $data['available_options'] );
			}
		}

		// Categories and tags.
		$data['categories'] = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$data['tags']       = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );

		// Add recent reviews for modal display.
		$reviews_enabled   = Glimmr_AI_Settings::get( 'reviews_enabled', true );
		$reviews_count     = (int) Glimmr_AI_Settings::get( 'reviews_count', 3 );
		$reviews_min_rating = (int) Glimmr_AI_Settings::get( 'reviews_min_rating', 0 );

		if ( $reviews_enabled ) {
			$review_args = array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => $reviews_count,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			);

			// Filter by minimum rating if set.
			if ( $reviews_min_rating > 0 ) {
				$review_args['meta_query'] = array(
					array(
						'key'     => 'rating',
						'value'   => $reviews_min_rating,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				);
			}

			$reviews      = get_comments( $review_args );
			$data['reviews'] = array();

			if ( is_array( $reviews ) ) {
				foreach ( $reviews as $review ) {
					if ( ! $review instanceof \WP_Comment ) {
						continue;
					}
					$rating = get_comment_meta( (int) $review->comment_ID, 'rating', true );
					$data['reviews'][] = array(
						'author'   => $review->comment_author,
						'rating'   => (int) $rating,
						'text'     => wp_trim_words( $review->comment_content, 30 ),
						'date'     => $review->comment_date,
						'verified' => wc_review_is_from_verified_owner( $review->comment_ID ),
					);
				}
			}

			// Rating distribution for modal.
			$data['rating_counts'] = array();
			for ( $i = 1; $i <= 5; $i++ ) {
				$data['rating_counts'][ $i ] = $product->get_rating_count( $i );
			}
		}

		return $data;
	}

	/**
	 * Build comparison matrix highlighting differences.
	 *
	 * @param array $products Array of product data.
	 * @return array Comparison matrix.
	 */
	private function build_comparison_matrix( $products ) {
		$matrix = array(
			'price_range' => array(
				'min' => PHP_FLOAT_MAX,
				'max' => 0,
				'best_value' => null,
			),
			'rating_range' => array(
				'min' => 5,
				'max' => 0,
				'highest_rated' => null,
			),
		);

		foreach ( $products as $product ) {
			$price = floatval( $product['price'] ?? 0 );
			$rating = floatval( $product['average_rating'] ?? 0 );

			if ( $price > 0 ) {
				if ( $price < $matrix['price_range']['min'] ) {
					$matrix['price_range']['min'] = $price;
					$matrix['price_range']['best_value'] = $product['id'];
				}
				if ( $price > $matrix['price_range']['max'] ) {
					$matrix['price_range']['max'] = $price;
				}
			}

			if ( $rating > $matrix['rating_range']['max'] ) {
				$matrix['rating_range']['max'] = $rating;
				$matrix['rating_range']['highest_rated'] = $product['id'];
			}
			if ( $rating > 0 && $rating < $matrix['rating_range']['min'] ) {
				$matrix['rating_range']['min'] = $rating;
			}
		}

		// Reset if no valid values.
		if ( $matrix['price_range']['min'] === PHP_FLOAT_MAX ) {
			$matrix['price_range']['min'] = 0;
		}

		return $matrix;
	}

	/**
	 * Execute aggregate mode.
	 *
	 * Performs catalog analytics like counting products per category,
	 * calculating average prices, etc.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Aggregation results.
	 */
	private function execute_aggregate( $arguments ) {
		$agg_params = $this->get_nested_arg( $arguments, 'aggregate', array() );

		if ( empty( $agg_params ) || ! is_array( $agg_params ) ) {
			return $this->format_validation_error(
				'missing_required',
				'aggregate',
				__( 'mode=aggregate requires the "aggregate" object with function and optional filters.', 'glimmr-ai' )
			);
		}

		$function = strtoupper( $agg_params['function'] ?? '' );
		$column   = $agg_params['column'] ?? '*';
		$group_by = $agg_params['group_by'] ?? null;
		$where    = $agg_params['where'] ?? array();

		// Validate function.
		$allowed_functions = array( 'COUNT', 'AVG', 'SUM', 'MIN', 'MAX' );
		if ( ! in_array( $function, $allowed_functions, true ) ) {
			return $this->format_validation_error(
				'invalid_enum',
				'aggregate.function',
				sprintf( __( 'Invalid function "%s". Must be one of: %s', 'glimmr-ai' ), $function, implode( ', ', $allowed_functions ) ),
				array( 'allowed_values' => $allowed_functions )
			);
		}

		// Validate column for non-COUNT functions.
		$allowed_columns = array( 'price', 'regular_price', 'sale_price', 'average_rating', 'review_count', 'total_sales', 'stock_quantity', '*' );
		if ( $function !== 'COUNT' && ! in_array( $column, $allowed_columns, true ) ) {
			return $this->format_validation_error(
				'invalid_enum',
				'aggregate.column',
				sprintf( __( 'Invalid column "%s" for %s. Must be one of: %s', 'glimmr-ai' ), $column, $function, implode( ', ', $allowed_columns ) )
			);
		}

		// Build query args with upper bound for aggregate calculations.
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 10000, // Upper bound for aggregate calculations
			'fields'         => 'ids',
		);

		// Apply where filters.
		$meta_query = array();
		$tax_query  = array();

		if ( ! empty( $where['category'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => sanitize_title( $where['category'] ),
			);
		}

		if ( ! empty( $where['in_stock'] ) ) {
			$meta_query[] = array(
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			);
		}

		if ( ! empty( $where['on_sale'] ) ) {
			$query_args['post__in'] = wc_get_product_ids_on_sale();
			if ( empty( $query_args['post__in'] ) ) {
				$query_args['post__in'] = array( 0 );
			}
		}

		if ( ! empty( $where['featured'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => 'featured',
			);
		}

		if ( isset( $where['min_price'] ) && $where['min_price'] > 0 ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => floatval( $where['min_price'] ),
				'compare' => '>=',
				'type'    => 'DECIMAL',
			);
		}

		if ( isset( $where['max_price'] ) && $where['max_price'] > 0 ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => floatval( $where['max_price'] ),
				'compare' => '<=',
				'type'    => 'DECIMAL',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}
		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		// Execute query.
		$query       = new WP_Query( $query_args );
		$product_ids = $query->posts;

		if ( empty( $product_ids ) ) {
			return $this->format_outcome(
				'no_results',
				array(
					'function' => $function,
					'column'   => $column,
					'result'   => $function === 'COUNT' ? 0 : null,
				),
				__( 'No products match the specified filters.', 'glimmr-ai' )
			);
		}

		// If grouping, organize by group.
		if ( $group_by ) {
			return $this->execute_grouped_aggregate( $product_ids, $function, $column, $group_by );
		}

		// Single aggregate.
		$result = $this->calculate_aggregate( $product_ids, $function, $column );

		return $this->format_outcome(
			'success',
			array(
				'function'    => $function,
				'column'      => $column,
				'result'      => $result,
				'product_count' => count( $product_ids ),
				'filters'     => array_filter( $where ),
			),
			sprintf( __( '%s(%s) = %s across %d products.', 'glimmr-ai' ), $function, $column, $this->format_aggregate_result( $result, $function, $column ), count( $product_ids ) )
		);
	}

	/**
	 * Execute grouped aggregation.
	 *
	 * @param array  $product_ids Product IDs to aggregate.
	 * @param string $function    Aggregate function.
	 * @param string $column      Column to aggregate.
	 * @param string $group_by    Group by field.
	 * @return array Grouped results.
	 */
	private function execute_grouped_aggregate( $product_ids, $function, $column, $group_by ) {
		$groups = array();

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}

			// Determine group key.
			$group_key = $this->get_group_key( $product, $group_by );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array();
			}
			$groups[ $group_key ][] = $pid;
		}

		// Calculate aggregate for each group.
		$results = array();
		foreach ( $groups as $key => $group_pids ) {
			$value     = $this->calculate_aggregate( $group_pids, $function, $column );
			$results[] = array(
				$group_by => $key,
				'value'   => $value,
				'count'   => count( $group_pids ),
			);
		}

		// Sort by value descending.
		usort( $results, function( $a, $b ) {
			return $b['value'] <=> $a['value'];
		} );

		return $this->format_outcome(
			'success',
			array(
				'function'   => $function,
				'column'     => $column,
				'group_by'   => $group_by,
				'groups'     => $results,
				'group_count' => count( $results ),
			),
			sprintf( __( '%s(%s) grouped by %s: %d groups found.', 'glimmr-ai' ), $function, $column, $group_by, count( $results ) )
		);
	}

	/**
	 * Get group key for a product.
	 *
	 * @param WC_Product $product  Product object.
	 * @param string     $group_by Group by field.
	 * @return string Group key.
	 */
	private function get_group_key( $product, $group_by ) {
		switch ( $group_by ) {
			case 'category':
				$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				if ( is_wp_error( $cats ) || empty( $cats ) ) {
					return 'Uncategorized';
				}
				return $cats[0];

			case 'type':
				return $product->get_type();

			case 'stock_status':
				return $product->get_stock_status();

			case 'on_sale':
				return $product->is_on_sale() ? 'On Sale' : 'Regular Price';

			case 'featured':
				return $product->is_featured() ? 'Featured' : 'Not Featured';

			default:
				return 'Unknown';
		}
	}

	/**
	 * Calculate aggregate value for product IDs.
	 *
	 * @param array  $product_ids Product IDs.
	 * @param string $function    Aggregate function.
	 * @param string $column      Column to aggregate.
	 * @return mixed Aggregate value.
	 */
	private function calculate_aggregate( $product_ids, $function, $column ) {
		if ( $function === 'COUNT' ) {
			return count( $product_ids );
		}

		$values = array();
		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}

			$value = $this->get_column_value( $product, $column );
			if ( is_numeric( $value ) ) {
				$values[] = floatval( $value );
			}
		}

		if ( empty( $values ) ) {
			return null;
		}

		switch ( $function ) {
			case 'AVG':
				return round( array_sum( $values ) / count( $values ), 2 );
			case 'SUM':
				return round( array_sum( $values ), 2 );
			case 'MIN':
				return min( $values );
			case 'MAX':
				return max( $values );
			default:
				return null;
		}
	}

	/**
	 * Get column value from product.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $column  Column name.
	 * @return mixed Column value.
	 */
	private function get_column_value( $product, $column ) {
		switch ( $column ) {
			case 'price':
				return $product->get_price();
			case 'regular_price':
				return $product->get_regular_price();
			case 'sale_price':
				return $product->get_sale_price();
			case 'average_rating':
				return $product->get_average_rating();
			case 'review_count':
				return $product->get_review_count();
			case 'total_sales':
				return get_post_meta( $product->get_id(), 'total_sales', true );
			case 'stock_quantity':
				return $product->get_stock_quantity();
			default:
				return null;
		}
	}

	/**
	 * Format aggregate result for display.
	 *
	 * @param mixed  $result   Result value.
	 * @param string $function Function used.
	 * @param string $column   Column aggregated.
	 * @return string Formatted result.
	 */
	private function format_aggregate_result( $result, $function, $column ) {
		if ( $result === null ) {
			return 'N/A';
		}

		// Format prices with currency.
		if ( in_array( $column, array( 'price', 'regular_price', 'sale_price' ), true ) ) {
			return wc_price( $result );
		}

		// Format ratings with stars.
		if ( $column === 'average_rating' ) {
			return number_format( $result, 1 ) . '/5';
		}

		return number_format( $result, is_float( $result ) ? 2 : 0 );
	}
}
