<?php
/**
 * Coupon Lookup Tool
 *
 * Searches for and retrieves coupon information based on visibility settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Coupon_Lookup
 *
 * Finds coupons that can be shared with customers based on
 * admin-configured visibility rules.
 */
class Glimmr_AI_Tool_Coupon_Lookup extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'coupon_lookup';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Find available discount coupons and promotions. Returns coupon codes with their discount amounts and any usage restrictions.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        // New nested filters object (v2 format).
        'filters' => array(
            'type'        => 'object',
            'description' => 'Structured filters for coupon search',
            'properties'  => array(
                'types' => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'percent', 'fixed_cart', 'fixed_product', 'free_shipping' ),
                    ),
                    'description' => 'Coupon types to include (empty = all)',
                ),
                'product_ids' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                    'description' => 'Find coupons valid for these products',
                ),
                'category_ids' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                    'description' => 'Find coupons valid for these categories',
                ),
                'min_percent_off' => array(
                    'type'        => 'number',
                    'description' => 'Minimum percentage discount (for percent type)',
                ),
                'min_amount_off' => array(
                    'type'        => 'number',
                    'description' => 'Minimum fixed amount discount',
                ),
                'free_shipping_only' => array(
                    'type'        => 'boolean',
                    'description' => 'Only show coupons with free shipping',
                ),
            ),
        ),
        // Legacy parameters (backward compatibility).
        'type' => array(
            'type'        => 'string',
            'description' => 'DEPRECATED: Use filters.types instead',
            'enum'        => array( 'all', 'percent', 'fixed_cart', 'fixed_product', 'free_shipping' ),
        ),
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'DEPRECATED: Use filters.product_ids instead',
        ),
        'category' => array(
            'type'        => 'string',
            'description' => 'DEPRECATED: Use filters.category_ids instead',
        ),
        'min_discount' => array(
            'type'        => 'number',
            'description' => 'DEPRECATED: Use filters.min_percent_off or min_amount_off instead',
        ),
    );

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

        // Check coupon visibility settings.
        $visibility = Glimmr_AI_Settings::get( 'coupon_visibility', 'public' );

        if ( 'none' === $visibility ) {
            return $this->format_outcome(
                'disabled',
                array(),
                __( 'Coupon information is not available through the assistant.', 'glimmr-ai' )
            );
        }

        // Extract filters (supports both v2 and legacy format).
        $filters = $this->extract_filters( $arguments );

        // Get visible coupons.
        $coupons = $this->get_visible_coupons( $visibility );

        if ( empty( $coupons ) ) {
            return $this->format_outcome(
                'no_coupons',
                array( 'filters' => $filters ),
                __( 'No coupons are currently available.', 'glimmr-ai' )
            );
        }

        // Filter coupons.
        $filtered = array();
        foreach ( $coupons as $coupon ) {
            if ( ! $this->coupon_matches_filters( $coupon, $filters ) ) {
                continue;
            }

            // Check if coupon is valid.
            if ( ! $this->is_coupon_valid( $coupon ) ) {
                continue;
            }

            $filtered[] = $this->format_coupon( $coupon );
        }

        if ( empty( $filtered ) ) {
            return $this->format_outcome(
                'no_matches',
                array( 'filters' => $filters ),
                __( 'No coupons match your criteria. Try removing some filters to see more coupons.', 'glimmr-ai' )
            );
        }

        return $this->format_outcome(
            'found',
            array(
                'coupons' => $filtered,
                'count'   => count( $filtered ),
                'filters' => $filters,
            ),
            sprintf(
                _n( 'Found %d coupon.', 'Found %d coupons.', count( $filtered ), 'glimmr-ai' ),
                count( $filtered )
            )
        );
    }

    /**
     * Extract filters from arguments (supports both v1 and v2 format).
     *
     * @param array $arguments Tool arguments.
     * @return array Normalized filters.
     */
    private function extract_filters( $arguments ) {
        // New v2 format: nested filters object.
        if ( isset( $arguments['filters'] ) && is_array( $arguments['filters'] ) ) {
            $f = $arguments['filters'];
            return array(
                'types'              => $f['types'] ?? array(),
                'product_ids'        => $f['product_ids'] ?? array(),
                'category_ids'       => $f['category_ids'] ?? array(),
                'min_percent_off'    => $f['min_percent_off'] ?? 0,
                'min_amount_off'     => $f['min_amount_off'] ?? 0,
                'free_shipping_only' => $f['free_shipping_only'] ?? false,
            );
        }

        // Legacy v1 format: flat parameters.
        $type = $this->get_string_arg( $arguments, 'type', 'all' );
        $product_id = $this->get_int_arg( $arguments, 'product_id' );
        $category = $this->get_string_arg( $arguments, 'category' );
        $min_discount = $this->get_float_arg( $arguments, 'min_discount' );

        $filters = array(
            'types'              => array(),
            'product_ids'        => array(),
            'category_ids'       => array(),
            'min_percent_off'    => 0,
            'min_amount_off'     => 0,
            'free_shipping_only' => false,
        );

        // Map legacy type to types array.
        if ( ! empty( $type ) && 'all' !== $type ) {
            $filters['types'] = array( $type );
        }

        // Map legacy product_id.
        if ( $product_id > 0 ) {
            $filters['product_ids'] = array( $product_id );
        }

        // Map legacy category (name/slug) to category_ids.
        if ( ! empty( $category ) ) {
            $term = get_term_by( 'slug', $category, 'product_cat' );
            if ( ! $term ) {
                $term = get_term_by( 'name', $category, 'product_cat' );
            }
            if ( $term ) {
                $filters['category_ids'] = array( $term->term_id );
            }
        }

        // Map legacy min_discount (applies to both percent and amount).
        if ( $min_discount > 0 ) {
            $filters['min_percent_off'] = $min_discount;
            $filters['min_amount_off'] = $min_discount;
        }

        return $filters;
    }

    /**
     * Check if a coupon matches the given filters.
     *
     * @param WC_Coupon $coupon  Coupon object.
     * @param array     $filters Normalized filters.
     * @return bool Whether coupon matches.
     */
    private function coupon_matches_filters( $coupon, $filters ) {
        // Type filter.
        if ( ! empty( $filters['types'] ) ) {
            if ( ! in_array( $coupon->get_discount_type(), $filters['types'], true ) ) {
                return false;
            }
        }

        // Free shipping filter.
        if ( $filters['free_shipping_only'] && ! $coupon->get_free_shipping() ) {
            return false;
        }

        // Minimum discount filters.
        $coupon_type = $coupon->get_discount_type();
        $coupon_amount = $coupon->get_amount();

        if ( 'percent' === $coupon_type && $filters['min_percent_off'] > 0 ) {
            if ( $coupon_amount < $filters['min_percent_off'] ) {
                return false;
            }
        }

        if ( in_array( $coupon_type, array( 'fixed_cart', 'fixed_product' ), true ) && $filters['min_amount_off'] > 0 ) {
            if ( $coupon_amount < $filters['min_amount_off'] ) {
                return false;
            }
        }

        // Product filter.
        if ( ! empty( $filters['product_ids'] ) ) {
            $matches_any_product = false;
            foreach ( $filters['product_ids'] as $product_id ) {
                if ( $this->coupon_applies_to_product( $coupon, $product_id ) ) {
                    $matches_any_product = true;
                    break;
                }
            }
            if ( ! $matches_any_product ) {
                return false;
            }
        }

        // Category filter.
        if ( ! empty( $filters['category_ids'] ) ) {
            $matches_any_category = false;
            foreach ( $filters['category_ids'] as $category_id ) {
                if ( $this->coupon_applies_to_category_id( $coupon, $category_id ) ) {
                    $matches_any_category = true;
                    break;
                }
            }
            if ( ! $matches_any_category ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if coupon applies to a specific category ID.
     *
     * @param WC_Coupon $coupon      Coupon object.
     * @param int       $category_id Category ID.
     * @return bool Whether coupon applies.
     */
    private function coupon_applies_to_category_id( $coupon, $category_id ) {
        $coupon_cats = $coupon->get_product_categories();
        $excluded_cats = $coupon->get_excluded_product_categories();

        // Check if category is excluded.
        if ( in_array( $category_id, $excluded_cats, true ) ) {
            return false;
        }

        // If specific categories are set, check if this one is included.
        if ( ! empty( $coupon_cats ) ) {
            return in_array( $category_id, $coupon_cats, true );
        }

        // No category restrictions, applies to all.
        return true;
    }

    /**
     * Get visible coupons based on visibility setting.
     *
     * @param string $visibility Visibility setting.
     * @return WC_Coupon[] Array of coupon objects.
     */
    private function get_visible_coupons( $visibility ) {
        $coupons = array();

        if ( 'public' === $visibility ) {
            // Get all published coupons.
            $coupon_posts = get_posts( array(
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'posts_per_page' => 20,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ) );

            foreach ( $coupon_posts as $post ) {
                $coupons[] = new WC_Coupon( $post->ID );
            }
        } elseif ( 'specific' === $visibility ) {
            // Get only specifically allowed coupons.
            $allowed_ids = Glimmr_AI_Settings::get( 'visible_coupons', array() );

            if ( ! empty( $allowed_ids ) ) {
                foreach ( $allowed_ids as $coupon_id ) {
                    $coupon = new WC_Coupon( $coupon_id );
                    if ( $coupon->get_id() > 0 ) {
                        $coupons[] = $coupon;
                    }
                }
            }
        }

        return $coupons;
    }

    /**
     * Check if coupon applies to a specific product.
     *
     * @param WC_Coupon $coupon     Coupon object.
     * @param int       $product_id Product ID.
     * @return bool Whether coupon applies.
     */
    private function coupon_applies_to_product( $coupon, $product_id ) {
        $product_ids = $coupon->get_product_ids();
        $excluded_ids = $coupon->get_excluded_product_ids();

        // Check if product is excluded.
        if ( in_array( $product_id, $excluded_ids, true ) ) {
            return false;
        }

        // If specific products are set, check if this one is included.
        if ( ! empty( $product_ids ) ) {
            return in_array( $product_id, $product_ids, true );
        }

        // Check categories.
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $product_cats = $product->get_category_ids();
        $coupon_cats = $coupon->get_product_categories();
        $excluded_cats = $coupon->get_excluded_product_categories();

        // Check if any product category is excluded.
        if ( ! empty( $excluded_cats ) && array_intersect( $product_cats, $excluded_cats ) ) {
            return false;
        }

        // If specific categories are set, check if product is in one.
        if ( ! empty( $coupon_cats ) ) {
            return ! empty( array_intersect( $product_cats, $coupon_cats ) );
        }

        return true;
    }

    /**
     * Check if coupon is currently valid.
     *
     * @param WC_Coupon $coupon Coupon object.
     * @return bool Whether coupon is valid.
     */
    private function is_coupon_valid( $coupon ) {
        // Check expiry.
        $expiry = $coupon->get_date_expires();
        if ( $expiry && $expiry->getTimestamp() < time() ) {
            return false;
        }

        // Check usage limit.
        $usage_limit = $coupon->get_usage_limit();
        $usage_count = $coupon->get_usage_count();
        if ( $usage_limit > 0 && $usage_count >= $usage_limit ) {
            return false;
        }

        return true;
    }

    /**
     * Format coupon for output.
     *
     * @param WC_Coupon $coupon Coupon object.
     * @return array Formatted coupon data.
     */
    private function format_coupon( $coupon ) {
        $data = array(
            'code'          => $coupon->get_code(),
            'type'          => $coupon->get_discount_type(),
            'amount'        => $coupon->get_amount(),
            'description'   => $coupon->get_description(),
            'free_shipping' => $coupon->get_free_shipping(),
        );

        // Format discount description.
        switch ( $coupon->get_discount_type() ) {
            case 'percent':
                $data['discount_text'] = $coupon->get_amount() . '% off';
                break;
            case 'fixed_cart':
                $data['discount_text'] = $this->format_price( $coupon->get_amount() ) . ' off your order';
                break;
            case 'fixed_product':
                $data['discount_text'] = $this->format_price( $coupon->get_amount() ) . ' off per item';
                break;
            default:
                $data['discount_text'] = '';
        }

        // Add restrictions.
        $restrictions = array();

        $min_amount = $coupon->get_minimum_amount();
        if ( $min_amount > 0 ) {
            $restrictions[] = sprintf(
                __( 'Minimum order: %s', 'glimmr-ai' ),
                $this->format_price( $min_amount )
            );
        }

        $max_amount = $coupon->get_maximum_amount();
        if ( $max_amount > 0 ) {
            $restrictions[] = sprintf(
                __( 'Maximum order: %s', 'glimmr-ai' ),
                $this->format_price( $max_amount )
            );
        }

        $expiry = $coupon->get_date_expires();
        if ( $expiry ) {
            $restrictions[] = sprintf(
                __( 'Expires: %s', 'glimmr-ai' ),
                $expiry->date_i18n( get_option( 'date_format' ) )
            );
        }

        $product_cats = $coupon->get_product_categories();
        if ( ! empty( $product_cats ) ) {
            $cat_names = array();
            foreach ( $product_cats as $cat_id ) {
                $term = get_term( $cat_id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $cat_names[] = $term->name;
                }
            }
            if ( ! empty( $cat_names ) ) {
                $restrictions[] = sprintf(
                    __( 'Valid for: %s', 'glimmr-ai' ),
                    implode( ', ', $cat_names )
                );
            }
        }

        if ( $coupon->get_individual_use() ) {
            $restrictions[] = __( 'Cannot be combined with other coupons', 'glimmr-ai' );
        }

        if ( ! empty( $restrictions ) ) {
            $data['restrictions'] = $restrictions;
        }

        return $data;
    }
}
