<?php
/**
 * Select Products Tool
 *
 * Hydrates full product data for products selected from candidates.
 * Used in the candidates + signals pattern where query_products returns
 * minimal candidate data, and the LLM calls this tool with selected IDs.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Select_Products
 *
 * Retrieves full product details for selected product IDs after
 * the LLM has reviewed candidates and made a selection.
 */
class Glimmr_AI_Tool_Select_Products extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'select_products';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Retrieve full product details for products you have selected from candidates. Call this after reviewing product_candidates to get complete information for display.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'product_ids' => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer' ),
			'description' => 'Array of product IDs selected from candidates (max 8)',
			'required'    => true,
		),
		'include_variations' => array(
			'type'        => 'boolean',
			'description' => 'Include variation details for variable products (default: true)',
		),
	);

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result with full product data.
	 */
	public function execute( $arguments ) {
		$wc_check = $this->require_wc();
		if ( $wc_check ) {
			return $wc_check;
		}

		// Get and validate product IDs.
		$product_ids = $this->get_array_arg( $arguments, 'product_ids', array() );

		if ( empty( $product_ids ) ) {
			return $this->format_validation_error(
				'missing_required',
				'product_ids',
				__( 'product_ids array is required.', 'glimmr-ai' )
			);
		}

		// Sanitize and limit to 8 products.
		$product_ids = array_map( 'intval', $product_ids );
		$product_ids = array_filter( $product_ids, function( $id ) {
			return $id > 0;
		} );
		$product_ids = array_slice( array_unique( $product_ids ), 0, 8 );

		if ( empty( $product_ids ) ) {
			return $this->format_validation_error(
				'invalid_value',
				'product_ids',
				__( 'No valid product IDs provided.', 'glimmr-ai' )
			);
		}

		$include_variations = $this->get_bool_arg( $arguments, 'include_variations', true );

		$this->log( 'Selecting products for display', array(
			'product_ids'        => $product_ids,
			'include_variations' => $include_variations,
		) );

		// Hydrate full product data.
		$products = array();
		$not_found = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				$not_found[] = $product_id;
				continue;
			}

			$formatted = $this->format_product_full( $product, $include_variations );
			// Filter out null values from failed formatting.
			if ( null !== $formatted ) {
				$products[] = $formatted;
			} else {
				$this->log( 'Product formatting returned null', array(
					'product_id' => $product_id,
				) );
				$not_found[] = $product_id;
			}
		}

		if ( empty( $products ) ) {
			return $this->format_error(
				'no_products_found',
				sprintf(
					__( 'None of the selected products could be found. IDs: %s', 'glimmr-ai' ),
					implode( ', ', $product_ids )
				)
			);
		}

		// Log any products that weren't found.
		if ( ! empty( $not_found ) ) {
			$this->log( 'Some selected products not found', array(
				'not_found_ids' => $not_found,
			) );
		}

		return $this->format_outcome(
			'product_search',
			array(
				'products'      => $products,
				'count'         => count( $products ),
				'selected_ids'  => $product_ids,
				'not_found_ids' => $not_found,
			),
			sprintf(
				_n(
					'Found %d product matching your selection.',
					'Found %d products matching your selection.',
					count( $products ),
					'glimmr-ai'
				),
				count( $products )
			)
		);
	}

	/**
	 * Format product with full details for display.
	 *
	 * @param WC_Product $product            Product object.
	 * @param bool       $include_variations Include variation data.
	 * @return array|null Full product data or null on failure.
	 */
	private function format_product_full( $product, $include_variations = true ) {
		// Start with base product data from parent class.
		$data = $this->format_product( $product );

		if ( ! $data ) {
			return null;
		}

		// Add detailed fields.
		$data['description']       = wp_strip_all_tags( $product->get_description() );
		$data['short_description'] = wp_strip_all_tags( $product->get_short_description() );
		$data['sku']               = $product->get_sku();
		$data['weight']            = $product->get_weight();
		$data['dimensions']        = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);

		// Gallery images.
		$gallery_ids = $product->get_gallery_image_ids();
		$data['gallery'] = array();
		foreach ( array_slice( $gallery_ids, 0, 5 ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$data['gallery'][] = $url;
			}
		}

		// Attributes.
		$data['attributes'] = array();
		foreach ( $product->get_attributes() as $attr_name => $attr ) {
			if ( $attr instanceof \WC_Product_Attribute ) {
				if ( $attr->is_taxonomy() ) {
					$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
					$data['attributes'][ wc_attribute_label( $attr->get_name() ) ] = $terms;
				} else {
					$data['attributes'][ wc_attribute_label( $attr->get_name() ) ] = $attr->get_options();
				}
			}
		}

		// Variations for variable products.
		if ( $include_variations && $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();
			$data['variations'] = array();
			$data['available_options'] = array(
				'colors' => array(),
				'sizes'  => array(),
			);

			// Track color swatches by name to avoid duplicates.
			$color_swatches = array();

			foreach ( array_slice( $variations, 0, 20 ) as $variation ) {
				$var_product = wc_get_product( $variation['variation_id'] );
				if ( ! $var_product ) {
					continue;
				}

				$var_data = array(
					'variation_id'   => $variation['variation_id'],
					'attributes'     => array(),
					'price'          => $this->format_price( $var_product->get_price() ),
					'price_raw'      => (float) $var_product->get_price(),
					'regular_price'  => $this->format_price( $var_product->get_regular_price() ),
					'in_stock'       => $var_product->is_in_stock(),
					'stock_quantity' => $var_product->get_stock_quantity(),
					'sku'            => $var_product->get_sku(),
				);

				// Add sale price if on sale.
				if ( $var_product->is_on_sale() ) {
					$var_data['sale_price'] = $this->format_price( $var_product->get_sale_price() );
					$var_data['on_sale'] = true;
				}

				// Format attributes with human-readable labels.
				foreach ( $variation['attributes'] as $attr_key => $attr_value ) {
					$clean_name = str_replace( 'attribute_', '', $attr_key );
					$label = wc_attribute_label( $clean_name );

					// Get the display value (term name for taxonomies).
					$display_value = $attr_value;
					$swatch_image = null;
					if ( taxonomy_exists( $clean_name ) ) {
						$term = get_term_by( 'slug', $attr_value, $clean_name );
						if ( $term ) {
							$display_value = $term->name;
							// Get swatch image from term meta (cfvsw_image from Color Filter Variation Swatches plugin).
							$swatch_image = get_term_meta( $term->term_id, 'cfvsw_image', true );
						}
					}

					$var_data['attributes'][ $label ] = $display_value;

					// Track available options for quick reference.
					$label_lower = strtolower( $label );
					if ( strpos( $label_lower, 'color' ) !== false || strpos( $label_lower, 'colour' ) !== false ) {
						// Track color with swatch image.
						if ( ! isset( $color_swatches[ $display_value ] ) ) {
							$color_swatches[ $display_value ] = $swatch_image;
						}
					} elseif ( strpos( $label_lower, 'size' ) !== false ) {
						if ( ! in_array( $display_value, $data['available_options']['sizes'], true ) ) {
							$data['available_options']['sizes'][] = $display_value;
						}
					}
				}

				$data['variations'][] = $var_data;
			}

			// Build colors array with swatch images.
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

		// Tags.
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		$data['tags'] = is_array( $tags ) ? $tags : array();

		return $data;
	}
}
