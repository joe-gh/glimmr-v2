<?php
/**
 * Recommendations Tool
 *
 * Provides product recommendations based on various strategies.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Recommendations
 *
 * Generates product recommendations using WooCommerce data.
 */
class Glimmr_AI_Tool_Recommendations extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'recommendations';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get personalized product recommendations. Can suggest products based on what\'s in the cart, previous purchases, browsing history, or popular items.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'type' => array(
            'type'        => 'string',
            'description' => 'Type of recommendations: related (to a product), upsells, cross_sells, cart_based, popular, new_arrivals, on_sale, for_you',
            'required'    => true,
            'enum'        => array( 'related', 'upsells', 'cross_sells', 'cart_based', 'popular', 'new_arrivals', 'on_sale', 'for_you' ),
        ),
        // New nested seed object (v2 format).
        'seed' => array(
            'type'        => 'object',
            'description' => 'Seed data for recommendations (required for related/upsells/cross_sells)',
            'additionalProperties' => false,
            'properties'  => array(
                'product_ids' => array(
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                    'description' => 'Product IDs to base recommendations on',
                ),
                'category_id' => array(
                    'type'        => 'integer',
                    'description' => 'Category ID to filter recommendations',
                    'minimum'     => 1,
                ),
                'use_cart' => array(
                    'type'        => 'boolean',
                    'description' => 'Use current cart contents as seed (default: false)',
                ),
            ),
        ),
        // Legacy parameter (backward compatibility).
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'DEPRECATED: Use seed.product_ids instead. Will be removed in v2.0.',
            'minimum'     => 1,
        ),
        'category' => array(
            'type'        => 'string',
            'description' => 'Limit recommendations to a specific category (name or slug)',
        ),
        'limit' => array(
            'type'        => 'integer',
            'description' => 'Maximum number of recommendations (default: 4, max: 8)',
            'minimum'     => 1,
            'maximum'     => 8,
        ),
        'exclude_cart' => array(
            'type'        => 'boolean',
            'description' => 'Exclude products already in cart (default: true)',
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

        $type         = $this->get_string_arg( $arguments, 'type', 'popular' );
        $limit        = min( $this->get_int_arg( $arguments, 'limit', 4 ), 8 );
        $exclude_cart = $this->get_bool_arg( $arguments, 'exclude_cart', true );

        // Extract seed data (supports both v1 and v2 format).
        $seed = $this->extract_seed( $arguments );

        // Get category from either seed or legacy parameter.
        $category = $this->get_string_arg( $arguments, 'category' );
        if ( empty( $category ) && ! empty( $seed['category_id'] ) ) {
            $term = get_term( $seed['category_id'], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $category = $term->slug;
            }
        }

        // Validate seed for types that require it.
        $product_based_types = array( 'related', 'upsells', 'cross_sells' );
        if ( in_array( $type, $product_based_types, true ) ) {
            if ( empty( $seed['product_ids'] ) && empty( $seed['use_cart'] ) ) {
                return $this->format_outcome(
                    'needs_seed',
                    array(
                        'type'          => $type,
                        'required_seed' => array( 'product_ids', 'use_cart' ),
                    ),
                    sprintf(
                        __( 'The "%s" recommendation type requires seed.product_ids or seed.use_cart to be set.', 'glimmr-ai' ),
                        $type
                    )
                );
            }
        }

        // Get products to exclude (in cart).
        $exclude_ids = array();
        if ( $exclude_cart ) {
            $exclude_ids = $this->get_cart_product_ids();
        }

        // Get primary product ID from seed.
        $product_id = ! empty( $seed['product_ids'] ) ? $seed['product_ids'][0] : 0;

        // If use_cart is true and no product_ids, use first cart item.
        if ( $seed['use_cart'] && empty( $product_id ) ) {
            $cart_ids = $this->get_cart_product_ids();
            if ( ! empty( $cart_ids ) ) {
                $product_id = $cart_ids[0];
            }
        }

        // Get recommendations based on type.
        switch ( $type ) {
            case 'related':
                $products = $this->get_related_products( $product_id, $limit, $exclude_ids );
                $rec_type = __( 'Related products', 'glimmr-ai' );
                break;

            case 'upsells':
                $products = $this->get_upsell_products( $product_id, $limit, $exclude_ids );
                $rec_type = __( 'Upgrade options', 'glimmr-ai' );
                break;

            case 'cross_sells':
                $products = $this->get_cross_sell_products( $product_id, $limit, $exclude_ids );
                $rec_type = __( 'Frequently bought together', 'glimmr-ai' );
                break;

            case 'cart_based':
                $products = $this->get_cart_based_recommendations( $limit, $exclude_ids );
                $rec_type = __( 'Recommended for your cart', 'glimmr-ai' );
                break;

            case 'new_arrivals':
                $products = $this->get_new_arrivals( $limit, $category, $exclude_ids );
                $rec_type = __( 'New arrivals', 'glimmr-ai' );
                break;

            case 'on_sale':
                $products = $this->get_on_sale_products( $limit, $category, $exclude_ids );
                $rec_type = __( 'On sale now', 'glimmr-ai' );
                break;

            case 'for_you':
                $products = $this->get_personalized_recommendations( $limit, $exclude_ids );
                $rec_type = __( 'Recommended for you', 'glimmr-ai' );
                break;

            case 'popular':
            default:
                $products = $this->get_popular_products( $limit, $category, $exclude_ids );
                $rec_type = __( 'Popular products', 'glimmr-ai' );
                break;
        }

        if ( empty( $products ) ) {
            return $this->format_outcome(
                'no_recommendations',
                array(
                    'type'     => $type,
                    'seed'     => $seed,
                    'category' => $category,
                ),
                __( 'No recommendations available at this time.', 'glimmr-ai' ),
                __( 'Try a different recommendation type or remove category filters.', 'glimmr-ai' )
            );
        }

        // Format products.
        $formatted = array();
        foreach ( $products as $product ) {
            $formatted[] = $this->format_product( $product );
        }

        return $this->format_outcome(
            'found',
            array(
                'type'     => $type,
                'title'    => $rec_type,
                'seed'     => $seed,
                'products' => $formatted,
                'count'    => count( $formatted ),
            ),
            sprintf(
                __( '%s: Found %d products.', 'glimmr-ai' ),
                $rec_type,
                count( $formatted )
            )
        );
    }

    /**
     * Extract seed from arguments (supports both v1 and v2 format).
     *
     * @param array $arguments Tool arguments.
     * @return array Normalized seed.
     */
    private function extract_seed( $arguments ) {
        // New v2 format: nested seed object.
        if ( isset( $arguments['seed'] ) && is_array( $arguments['seed'] ) ) {
            return array(
                'product_ids' => $arguments['seed']['product_ids'] ?? array(),
                'category_id' => $arguments['seed']['category_id'] ?? null,
                'use_cart'    => $arguments['seed']['use_cart'] ?? false,
            );
        }

        // Legacy v1 format: flat product_id parameter.
        $product_id = $this->get_int_arg( $arguments, 'product_id' );

        return array(
            'product_ids' => $product_id ? array( $product_id ) : array(),
            'category_id' => null,
            'use_cart'    => false,
        );
    }

    /**
     * Get product IDs currently in cart.
     *
     * @return array Product IDs.
     */
    private function get_cart_product_ids() {
        $ids = array();

        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $ids[] = $item['product_id'];
                if ( ! empty( $item['variation_id'] ) ) {
                    $ids[] = $item['variation_id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Get related products.
     *
     * @param int   $product_id  Product ID.
     * @param int   $limit       Limit.
     * @param array $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_related_products( $product_id, $limit, $exclude_ids ) {
        if ( $product_id <= 0 ) {
            return array();
        }

        $related_ids = wc_get_related_products( $product_id, $limit + count( $exclude_ids ), $exclude_ids );

        $products = array();
        foreach ( array_slice( $related_ids, 0, $limit ) as $id ) {
            $product = wc_get_product( $id );
            if ( $product && $product->is_visible() ) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Get upsell products.
     *
     * @param int   $product_id  Product ID.
     * @param int   $limit       Limit.
     * @param array $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_upsell_products( $product_id, $limit, $exclude_ids ) {
        if ( $product_id <= 0 ) {
            return array();
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return array();
        }

        $upsell_ids = $product->get_upsell_ids();
        $upsell_ids = array_diff( $upsell_ids, $exclude_ids );

        $products = array();
        foreach ( array_slice( $upsell_ids, 0, $limit ) as $id ) {
            $up_product = wc_get_product( $id );
            if ( $up_product && $up_product->is_visible() && $up_product->is_in_stock() ) {
                $products[] = $up_product;
            }
        }

        return $products;
    }

    /**
     * Get cross-sell products.
     *
     * @param int   $product_id  Product ID.
     * @param int   $limit       Limit.
     * @param array $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_cross_sell_products( $product_id, $limit, $exclude_ids ) {
        if ( $product_id <= 0 ) {
            return array();
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return array();
        }

        $cross_sell_ids = $product->get_cross_sell_ids();
        $cross_sell_ids = array_diff( $cross_sell_ids, $exclude_ids );

        $products = array();
        foreach ( array_slice( $cross_sell_ids, 0, $limit ) as $id ) {
            $cs_product = wc_get_product( $id );
            if ( $cs_product && $cs_product->is_visible() && $cs_product->is_in_stock() ) {
                $products[] = $cs_product;
            }
        }

        return $products;
    }

    /**
     * Get recommendations based on cart contents.
     *
     * @param int   $limit       Limit.
     * @param array $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_cart_based_recommendations( $limit, $exclude_ids ) {
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return $this->get_popular_products( $limit, '', $exclude_ids );
        }

        // Get cross-sells from cart.
        $cross_sells = WC()->cart->get_cross_sells();
        $cross_sells = array_diff( $cross_sells, $exclude_ids );

        $products = array();
        foreach ( array_slice( $cross_sells, 0, $limit ) as $id ) {
            $product = wc_get_product( $id );
            if ( $product && $product->is_visible() && $product->is_in_stock() ) {
                $products[] = $product;
            }
        }

        // If not enough, add related products.
        if ( count( $products ) < $limit ) {
            $cart_items = WC()->cart->get_cart();
            $first_item = reset( $cart_items );
            if ( $first_item ) {
                $related = $this->get_related_products(
                    $first_item['product_id'],
                    $limit - count( $products ),
                    array_merge( $exclude_ids, wp_list_pluck( $products, 'id' ) )
                );
                $products = array_merge( $products, $related );
            }
        }

        return array_slice( $products, 0, $limit );
    }

    /**
     * Get popular products.
     *
     * @param int    $limit       Limit.
     * @param string $category    Category filter.
     * @param array  $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_popular_products( $limit, $category = '', $exclude_ids = array() ) {
        $args = array(
            'limit'      => $limit + count( $exclude_ids ),
            'status'     => 'publish',
            'stock_status' => 'instock',
            'orderby'    => 'popularity',
            'order'      => 'DESC',
            'exclude'    => $exclude_ids,
        );

        if ( ! empty( $category ) ) {
            $term = get_term_by( 'slug', $category, 'product_cat' );
            if ( ! $term ) {
                $term = get_term_by( 'name', $category, 'product_cat' );
            }
            if ( $term ) {
                $args['category'] = array( $term->slug );
            }
        }

        $products = wc_get_products( $args );

        return array_slice( $products, 0, $limit );
    }

    /**
     * Get new arrivals.
     *
     * @param int    $limit       Limit.
     * @param string $category    Category filter.
     * @param array  $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_new_arrivals( $limit, $category = '', $exclude_ids = array() ) {
        $args = array(
            'limit'        => $limit + count( $exclude_ids ),
            'status'       => 'publish',
            'stock_status' => 'instock',
            'orderby'      => 'date',
            'order'        => 'DESC',
            'exclude'      => $exclude_ids,
        );

        if ( ! empty( $category ) ) {
            $term = get_term_by( 'slug', $category, 'product_cat' );
            if ( ! $term ) {
                $term = get_term_by( 'name', $category, 'product_cat' );
            }
            if ( $term ) {
                $args['category'] = array( $term->slug );
            }
        }

        $products = wc_get_products( $args );

        return array_slice( $products, 0, $limit );
    }

    /**
     * Get products on sale.
     *
     * @param int    $limit       Limit.
     * @param string $category    Category filter.
     * @param array  $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_on_sale_products( $limit, $category = '', $exclude_ids = array() ) {
        $on_sale_ids = wc_get_product_ids_on_sale();
        $on_sale_ids = array_diff( $on_sale_ids, $exclude_ids );

        if ( empty( $on_sale_ids ) ) {
            return array();
        }

        $args = array(
            'include'      => $on_sale_ids,
            'limit'        => $limit,
            'status'       => 'publish',
            'stock_status' => 'instock',
            'orderby'      => 'popularity',
            'order'        => 'DESC',
        );

        if ( ! empty( $category ) ) {
            $term = get_term_by( 'slug', $category, 'product_cat' );
            if ( ! $term ) {
                $term = get_term_by( 'name', $category, 'product_cat' );
            }
            if ( $term ) {
                $args['category'] = array( $term->slug );
            }
        }

        return wc_get_products( $args );
    }

    /**
     * Get personalized recommendations for logged-in user.
     *
     * @param int   $limit       Limit.
     * @param array $exclude_ids Products to exclude.
     * @return WC_Product[] Products.
     */
    private function get_personalized_recommendations( $limit, $exclude_ids ) {
        if ( ! $this->is_user_logged_in() ) {
            return $this->get_popular_products( $limit, '', $exclude_ids );
        }

        // Get user's purchase history categories.
        $purchased_cats = $this->get_user_purchase_categories();

        if ( empty( $purchased_cats ) ) {
            return $this->get_popular_products( $limit, '', $exclude_ids );
        }

        // Get products from same categories they've purchased from.
        $args = array(
            'limit'        => $limit + count( $exclude_ids ),
            'status'       => 'publish',
            'stock_status' => 'instock',
            'orderby'      => 'popularity',
            'order'        => 'DESC',
            'exclude'      => $exclude_ids,
            'category'     => $purchased_cats,
        );

        $products = wc_get_products( $args );

        // If not enough, supplement with popular.
        if ( count( $products ) < $limit ) {
            $popular = $this->get_popular_products(
                $limit - count( $products ),
                '',
                array_merge( $exclude_ids, wp_list_pluck( $products, 'id' ) )
            );
            $products = array_merge( $products, $popular );
        }

        return array_slice( $products, 0, $limit );
    }

    /**
     * Get categories user has purchased from.
     *
     * @return array Category slugs.
     */
    private function get_user_purchase_categories() {
        $orders = wc_get_orders( array(
            'customer_id' => $this->user_id,
            'status'      => array( 'completed' ),
            'limit'       => 10,
        ) );

        $categories = array();

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( $product ) {
                    $cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
                    if ( is_array( $cats ) ) {
                        $categories = array_merge( $categories, $cats );
                    }
                }
            }
        }

        return array_unique( $categories );
    }
}
