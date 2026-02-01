<?php
/**
 * Variation Resolver Tool
 *
 * Resolves variation attributes to specific variation IDs.
 * Handles incomplete attribute specifications gracefully.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Resolve_Variation
 *
 * Resolver tool that maps variation attributes (size, color, etc.)
 * to concrete variation IDs, identifying missing required attributes.
 */
class Glimmr_AI_Tool_Resolve_Variation extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'resolve_variation';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Resolve variation attributes (like size and color) to a specific variation ID for a variable product. Use before add_to_cart when user specifies attributes instead of variation_id.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'product_id' => array(
			'type'        => 'integer',
			'required'    => true,
			'description' => 'The parent product ID (must be a variable product)',
		),
		'attributes' => array(
			'type'                 => 'object',
			'description'          => 'Requested attributes as key-value pairs (e.g., {"size": "large", "color": "blue"})',
			'additionalProperties' => array( 'type' => 'string' ),
		),
		'user_phrase' => array(
			'type'        => 'string',
			'description' => 'Raw user phrase like "blue large" as fallback for attribute extraction',
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

		$product_id = $this->get_int_arg( $arguments, 'product_id', 0 );
		if ( ! $product_id ) {
			return $this->format_validation_error(
				'missing_required',
				'product_id',
				__( 'Required field "product_id" is missing.', 'glimmr-ai' )
			);
		}

		// Get and validate product.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $this->format_outcome(
				'not_found',
				array( 'product_id' => $product_id ),
				sprintf( __( 'Product with ID %d not found.', 'glimmr-ai' ), $product_id )
			);
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return $this->format_outcome(
				'invalid_product_type',
				array(
					'product_id'   => $product_id,
					'product_type' => $product->get_type(),
				),
				sprintf( __( 'Product "%s" is not a variable product. Use add_to_cart directly.', 'glimmr-ai' ), $product->get_name() )
			);
		}

		// Get requested attributes.
		$requested_attributes = $arguments['attributes'] ?? array();
		$user_phrase = $this->get_string_arg( $arguments, 'user_phrase', '' );

		// If no attributes provided but user_phrase given, try to extract.
		if ( empty( $requested_attributes ) && ! empty( $user_phrase ) ) {
			$requested_attributes = $this->extract_attributes_from_phrase( $product, $user_phrase );
		}

		// Get available variations and attributes.
		$variation_attributes = $product->get_variation_attributes();
		$available_variations = $product->get_available_variations();

		// Normalize requested attributes.
		$normalized = $this->normalize_attributes( $requested_attributes, $variation_attributes );

		// Find matching variation.
		$match_result = $this->find_matching_variation( $product, $normalized, $available_variations );

		if ( $match_result['variation_id'] ) {
			// Exact match found.
			return $this->format_outcome(
				'resolved',
				array(
					'variation_id'        => $match_result['variation_id'],
					'resolved_attributes' => $match_result['attributes'],
					'product_id'          => $product_id,
					'product_name'        => $product->get_name(),
					'variation_price'     => $match_result['price'],
					'in_stock'            => $match_result['in_stock'],
				),
				sprintf(
					__( 'Resolved to variation %d: %s', 'glimmr-ai' ),
					$match_result['variation_id'],
					$this->format_attributes_string( $match_result['attributes'] )
				)
			);
		}

		// No exact match - determine what's missing.
		$missing_attributes = $this->get_missing_attributes( $normalized, $variation_attributes );

		if ( ! empty( $missing_attributes ) ) {
			// Attributes are missing.
			$available_options = array();
			foreach ( $missing_attributes as $attr_name ) {
				$options = $variation_attributes[ $attr_name ] ?? array();
				$available_options[ $attr_name ] = $this->get_available_options_for_partial( $product, $normalized, $attr_name );
			}

			return $this->format_outcome(
				'needs_selection',
				array(
					'product_id'          => $product_id,
					'product_name'        => $product->get_name(),
					'provided_attributes' => $normalized,
					'missing_attributes'  => $missing_attributes,
					'available_options'   => $available_options,
				),
				$this->build_selection_suggestion( $missing_attributes, $available_options )
			);
		}

		// All attributes provided but no matching variation exists.
		return $this->format_outcome(
			'invalid_combination',
			array(
				'product_id'            => $product_id,
				'product_name'          => $product->get_name(),
				'requested_attributes'  => $normalized,
				'valid_combinations'    => $this->get_valid_combinations( $available_variations, 5 ),
			),
			__( 'This attribute combination is not available. Please choose from the valid combinations.', 'glimmr-ai' )
		);
	}

	/**
	 * Normalize attribute keys and values.
	 *
	 * @param array $requested  Requested attributes.
	 * @param array $available  Available variation attributes.
	 * @return array Normalized attributes.
	 */
	private function normalize_attributes( $requested, $available ) {
		$normalized = array();

		foreach ( $requested as $key => $value ) {
			$key_lower = strtolower( trim( $key ) );
			$value_lower = strtolower( trim( $value ) );

			// Try to match to available attribute names.
			foreach ( $available as $attr_name => $options ) {
				$attr_clean = str_replace( 'pa_', '', $attr_name );
				$attr_clean_lower = strtolower( $attr_clean );

				// Check if key matches attribute name.
				if ( $key_lower === $attr_clean_lower ||
					$key_lower === strtolower( $attr_name ) ||
					$key_lower === str_replace( '_', ' ', $attr_clean_lower ) ||
					$key_lower === str_replace( '-', ' ', $attr_clean_lower ) ) {

					// Normalize value to match available options.
					$matched_value = $this->match_attribute_value( $value_lower, $options );
					if ( $matched_value !== null ) {
						$normalized[ $attr_name ] = $matched_value;
					}
					break;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Match attribute value to available options.
	 *
	 * @param string $value   The value to match (lowercase).
	 * @param array  $options Available options.
	 * @return string|null Matched option or null.
	 */
	private function match_attribute_value( $value, $options ) {
		// Exact match.
		foreach ( $options as $option ) {
			if ( strtolower( $option ) === $value ) {
				return $option;
			}
		}

		// Partial match.
		foreach ( $options as $option ) {
			if ( strpos( strtolower( $option ), $value ) !== false ||
				strpos( $value, strtolower( $option ) ) !== false ) {
				return $option;
			}
		}

		// Slug match.
		foreach ( $options as $option ) {
			if ( sanitize_title( $option ) === sanitize_title( $value ) ) {
				return $option;
			}
		}

		return null;
	}

	/**
	 * Find matching variation.
	 *
	 * @param WC_Product_Variable $product    The product.
	 * @param array               $normalized Normalized attributes.
	 * @param array               $variations Available variations.
	 * @return array Match result.
	 */
	private function find_matching_variation( $product, $normalized, $variations ) {
		if ( empty( $normalized ) ) {
			return array(
				'variation_id' => null,
				'attributes'   => array(),
				'price'        => null,
				'in_stock'     => null,
			);
		}

		// Convert to the format wc_get_matching_variation expects.
		$match_attributes = array();
		foreach ( $normalized as $key => $value ) {
			$match_attributes[ 'attribute_' . $key ] = $value;
		}

		$variation_id = $product->get_matching_variation( $match_attributes );

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				return array(
					'variation_id' => $variation_id,
					'attributes'   => $variation->get_attributes(),
					'price'        => $variation->get_price(),
					'in_stock'     => $variation->is_in_stock(),
				);
			}
		}

		return array(
			'variation_id' => null,
			'attributes'   => array(),
			'price'        => null,
			'in_stock'     => null,
		);
	}

	/**
	 * Get missing attributes.
	 *
	 * @param array $normalized Normalized provided attributes.
	 * @param array $available  All available variation attributes.
	 * @return array List of missing attribute names.
	 */
	private function get_missing_attributes( $normalized, $available ) {
		$missing = array();

		foreach ( $available as $attr_name => $options ) {
			if ( ! isset( $normalized[ $attr_name ] ) && ! empty( $options ) ) {
				$missing[] = $attr_name;
			}
		}

		return $missing;
	}

	/**
	 * Get available options for a specific attribute given partial selection.
	 *
	 * @param WC_Product_Variable $product      The product.
	 * @param array               $selected     Already selected attributes.
	 * @param string              $target_attr  The attribute to get options for.
	 * @return array Available options.
	 */
	private function get_available_options_for_partial( $product, $selected, $target_attr ) {
		$variations = $product->get_available_variations();
		$available = array();

		foreach ( $variations as $variation ) {
			// Check if this variation matches selected attributes.
			$matches = true;
			foreach ( $selected as $key => $value ) {
				$attr_key = 'attribute_' . $key;
				if ( isset( $variation['attributes'][ $attr_key ] ) ) {
					$var_value = $variation['attributes'][ $attr_key ];
					// Empty means "any".
					if ( ! empty( $var_value ) && strtolower( $var_value ) !== strtolower( $value ) ) {
						$matches = false;
						break;
					}
				}
			}

			if ( $matches ) {
				$target_key = 'attribute_' . $target_attr;
				if ( isset( $variation['attributes'][ $target_key ] ) ) {
					$val = $variation['attributes'][ $target_key ];
					if ( ! empty( $val ) && ! in_array( $val, $available, true ) ) {
						$available[] = $val;
					}
				}
			}
		}

		// If no constraints found, return all options for the attribute.
		if ( empty( $available ) ) {
			$all_attrs = $product->get_variation_attributes();
			if ( isset( $all_attrs[ $target_attr ] ) ) {
				return $all_attrs[ $target_attr ];
			}
		}

		return $available;
	}

	/**
	 * Get valid attribute combinations.
	 *
	 * @param array $variations Available variations.
	 * @param int   $limit      Maximum to return.
	 * @return array Valid combinations.
	 */
	private function get_valid_combinations( $variations, $limit ) {
		$combinations = array();

		foreach ( array_slice( $variations, 0, $limit ) as $variation ) {
			$attrs = array();
			foreach ( $variation['attributes'] as $key => $value ) {
				$clean_key = str_replace( 'attribute_', '', $key );
				$clean_key = str_replace( 'pa_', '', $clean_key );
				$attrs[ $clean_key ] = $value;
			}

			$var_product = wc_get_product( $variation['variation_id'] );
			$combinations[] = array(
				'variation_id' => $variation['variation_id'],
				'attributes'   => $attrs,
				'price'        => $var_product ? $var_product->get_price() : $variation['display_price'],
				'in_stock'     => $variation['is_in_stock'],
			);
		}

		return $combinations;
	}

	/**
	 * Extract attributes from a user phrase.
	 *
	 * @param WC_Product $product     The product.
	 * @param string     $user_phrase The phrase to extract from.
	 * @return array Extracted attributes.
	 */
	private function extract_attributes_from_phrase( $product, $user_phrase ) {
		$extracted = array();
		$phrase_lower = strtolower( $user_phrase );
		$variation_attributes = $product->get_variation_attributes();

		foreach ( $variation_attributes as $attr_name => $options ) {
			foreach ( $options as $option ) {
				$option_lower = strtolower( $option );
				// Check if option appears in phrase.
				if ( strpos( $phrase_lower, $option_lower ) !== false ) {
					$clean_key = str_replace( 'pa_', '', $attr_name );
					$extracted[ $clean_key ] = $option;
					break; // Take first match for this attribute.
				}
			}
		}

		return $extracted;
	}

	/**
	 * Build selection suggestion message.
	 *
	 * @param array $missing  Missing attributes.
	 * @param array $options  Available options per attribute.
	 * @return string Suggestion message.
	 */
	private function build_selection_suggestion( $missing, $options ) {
		$parts = array();

		foreach ( $missing as $attr_name ) {
			$clean_name = str_replace( 'pa_', '', $attr_name );
			$clean_name = str_replace( '_', ' ', $clean_name );
			$clean_name = ucfirst( $clean_name );

			if ( isset( $options[ $attr_name ] ) && ! empty( $options[ $attr_name ] ) ) {
				$opts = implode( ', ', array_slice( $options[ $attr_name ], 0, 6 ) );
				$parts[] = sprintf( '%s (%s)', $clean_name, $opts );
			} else {
				$parts[] = $clean_name;
			}
		}

		return sprintf(
			__( 'Please select: %s', 'glimmr-ai' ),
			implode( '; ', $parts )
		);
	}

	/**
	 * Format attributes as a readable string.
	 *
	 * @param array $attributes Attributes array.
	 * @return string Formatted string.
	 */
	private function format_attributes_string( $attributes ) {
		$parts = array();
		foreach ( $attributes as $key => $value ) {
			$clean_key = str_replace( 'pa_', '', $key );
			$clean_key = str_replace( '_', ' ', $clean_key );
			$parts[] = ucfirst( $clean_key ) . ': ' . $value;
		}
		return implode( ', ', $parts );
	}
}
