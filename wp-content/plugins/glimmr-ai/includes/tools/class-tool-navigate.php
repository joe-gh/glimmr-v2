<?php
/**
 * Navigate Tool
 *
 * Provides navigation to internal site pages. Use when user says
 * "take me to", "go to", "open the page", etc.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Navigate
 *
 * Navigates user to internal pages. Supports navigation by:
 * - Direct URL (from conversation context)
 * - Page type (checkout, cart, my-account, product, order, category, page)
 * - Page/product/order ID for specific resources
 *
 * Security: Only allows navigation to internal URLs (same site).
 */
class Glimmr_AI_Tool_Navigate extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'navigate_to_page';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Navigate the user to a page on this site. Use when user says "take me to", "go to", "open that page", "show me the page", etc. Can navigate by URL (from earlier in conversation) or by page type. Only works for internal site pages.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'url' => array(
            'type'        => 'string',
            'description' => 'The URL to navigate to (must be on this site). Use URLs from earlier in the conversation.',
            'maxLength'   => 500,
        ),
        'page_type' => array(
            'type'        => 'string',
            'description' => 'Type of page to navigate to. Use when you know the page type but not the exact URL.',
            'enum'        => array(
                'checkout',
                'cart',
                'my-account',
                'orders',
                'addresses',
                'account-details',
                'payment-methods',
                'downloads',
                'product',
                'order',
                'category',
                'page',
                'shop',
                'home',
            ),
        ),
        'page_id' => array(
            'type'        => 'integer',
            'description' => 'ID for specific pages: product ID for "product", order ID for "order", category ID for "category", or WordPress page ID for "page".',
            'minimum'     => 1,
        ),
        'page_slug' => array(
            'type'        => 'string',
            'description' => 'Slug for finding pages by name (e.g., "return-policy", "faq", "contact"). Alternative to page_id.',
            'maxLength'   => 100,
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $url       = $this->get_string_arg( $arguments, 'url', '' );
        $page_type = $this->get_string_arg( $arguments, 'page_type', '' );
        $page_id   = $this->get_int_arg( $arguments, 'page_id', 0 );
        $page_slug = $this->get_string_arg( $arguments, 'page_slug', '' );

        // If direct URL provided, validate and use it.
        if ( ! empty( $url ) ) {
            return $this->navigate_to_url( $url );
        }

        // If page_type provided, build the URL.
        if ( ! empty( $page_type ) ) {
            return $this->navigate_by_type( $page_type, $page_id, $page_slug );
        }

        // If only page_slug provided, try to find the page.
        if ( ! empty( $page_slug ) ) {
            return $this->navigate_by_slug( $page_slug );
        }

        return $this->format_validation_error(
            'missing_parameter',
            'url or page_type',
            __( 'Please provide either a URL or a page_type to navigate to.', 'glimmr-ai' )
        );
    }

    /**
     * Navigate to a direct URL.
     *
     * @param string $url The URL to navigate to.
     * @return array Tool result.
     */
    private function navigate_to_url( $url ) {
        // Validate URL is internal.
        if ( ! $this->is_internal_url( $url ) ) {
            return $this->format_outcome(
                'invalid_url',
                array(
                    'url'    => $url,
                    'reason' => 'external',
                ),
                __( 'Cannot navigate to external URLs. I can only navigate to pages on this site.', 'glimmr-ai' )
            );
        }

        // Clean up the URL.
        $url = esc_url( $url );

        return $this->build_navigation_response( $url, 'direct', __( 'Opening page...', 'glimmr-ai' ) );
    }

    /**
     * Navigate by page type.
     *
     * @param string $page_type Type of page.
     * @param int    $page_id   Optional page/product/order ID.
     * @param string $page_slug Optional page slug.
     * @return array Tool result.
     */
    private function navigate_by_type( $page_type, $page_id, $page_slug ) {
        $url   = '';
        $label = '';

        switch ( $page_type ) {
            // WooCommerce pages.
            case 'checkout':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_checkout_url();
                $label = __( 'Opening checkout page...', 'glimmr-ai' );
                break;

            case 'cart':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_cart_url();
                $label = __( 'Opening cart page...', 'glimmr-ai' );
                break;

            case 'shop':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $shop_page_id = wc_get_page_id( 'shop' );
                if ( $shop_page_id > 0 ) {
                    $url = get_permalink( $shop_page_id );
                }
                // Fallback to home/shop if get_permalink fails or no shop page.
                if ( empty( $url ) ) {
                    $url = home_url( '/shop/' );
                }
                $label = __( 'Opening shop page...', 'glimmr-ai' );
                break;

            case 'my-account':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'dashboard' );
                $label = __( 'Opening my account page...', 'glimmr-ai' );
                break;

            case 'orders':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'orders' );
                $label = __( 'Opening orders page...', 'glimmr-ai' );
                break;

            case 'addresses':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'edit-address' );
                $label = __( 'Opening addresses page...', 'glimmr-ai' );
                break;

            case 'account-details':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'edit-account' );
                $label = __( 'Opening account details page...', 'glimmr-ai' );
                break;

            case 'payment-methods':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'payment-methods' );
                $label = __( 'Opening payment methods page...', 'glimmr-ai' );
                break;

            case 'downloads':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $url   = wc_get_account_endpoint_url( 'downloads' );
                $label = __( 'Opening downloads page...', 'glimmr-ai' );
                break;

            case 'product':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                if ( $page_id <= 0 ) {
                    return $this->format_validation_error(
                        'missing_required',
                        'page_id',
                        __( 'Product ID is required to navigate to a product page.', 'glimmr-ai' )
                    );
                }
                $product = wc_get_product( $page_id );
                if ( ! $product ) {
                    return $this->format_outcome(
                        'not_found',
                        array( 'page_type' => 'product', 'page_id' => $page_id ),
                        sprintf( __( 'Product with ID %d not found.', 'glimmr-ai' ), $page_id )
                    );
                }
                $url   = $product->get_permalink();
                $label = sprintf( __( 'Opening %s product page...', 'glimmr-ai' ), $product->get_name() );
                break;

            case 'order':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                if ( $page_id <= 0 ) {
                    return $this->format_validation_error(
                        'missing_required',
                        'page_id',
                        __( 'Order ID is required to navigate to an order page.', 'glimmr-ai' )
                    );
                }
                $order = wc_get_order( $page_id );
                if ( ! $order ) {
                    return $this->format_outcome(
                        'not_found',
                        array( 'page_type' => 'order', 'page_id' => $page_id ),
                        sprintf( __( 'Order with ID %d not found.', 'glimmr-ai' ), $page_id )
                    );
                }
                // Verify user has access to this order.
                if ( ! $this->user_can_view_order( $order ) ) {
                    return $this->format_outcome(
                        'access_denied',
                        array( 'page_type' => 'order', 'page_id' => $page_id ),
                        __( 'You do not have permission to view this order.', 'glimmr-ai' )
                    );
                }
                $url   = $order->get_view_order_url();
                $label = sprintf( __( 'Opening order #%s page...', 'glimmr-ai' ), $order->get_order_number() );
                break;

            case 'category':
                if ( ! $this->is_wc_active() ) {
                    return $this->format_error( __( 'WooCommerce is not available.', 'glimmr-ai' ), 'wc_not_active' );
                }
                $term = null;
                if ( $page_id > 0 ) {
                    $term = get_term( $page_id, 'product_cat' );
                } elseif ( ! empty( $page_slug ) ) {
                    $term = get_term_by( 'slug', $page_slug, 'product_cat' );
                }
                if ( ! $term || is_wp_error( $term ) ) {
                    return $this->format_outcome(
                        'not_found',
                        array( 'page_type' => 'category', 'page_id' => $page_id, 'page_slug' => $page_slug ),
                        __( 'Category not found.', 'glimmr-ai' )
                    );
                }
                $url = get_term_link( $term );
                // get_term_link() can return WP_Error even after term validation.
                if ( is_wp_error( $url ) ) {
                    return $this->format_outcome(
                        'url_not_available',
                        array( 'page_type' => 'category', 'error' => $url->get_error_message() ),
                        __( 'Could not generate category URL.', 'glimmr-ai' )
                    );
                }
                $label = sprintf( __( 'Opening %s category page...', 'glimmr-ai' ), $term->name );
                break;

            case 'page':
                $page = null;
                if ( $page_id > 0 ) {
                    $page = get_post( $page_id );
                } elseif ( ! empty( $page_slug ) ) {
                    $page = get_page_by_path( $page_slug );
                }
                if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
                    return $this->format_outcome(
                        'not_found',
                        array( 'page_type' => 'page', 'page_id' => $page_id, 'page_slug' => $page_slug ),
                        __( 'Page not found.', 'glimmr-ai' )
                    );
                }
                $url = get_permalink( $page );
                if ( ! $url ) {
                    return $this->format_outcome(
                        'url_not_available',
                        array( 'page_type' => 'page', 'page_id' => $page->ID ),
                        __( 'Could not generate page URL.', 'glimmr-ai' )
                    );
                }
                $label = sprintf( __( 'Opening %s page...', 'glimmr-ai' ), $page->post_title );
                break;

            case 'home':
                $url   = home_url( '/' );
                $label = __( 'Opening home page...', 'glimmr-ai' );
                break;

            default:
                return $this->format_validation_error(
                    'invalid_page_type',
                    'page_type',
                    sprintf( __( 'Unknown page type: %s', 'glimmr-ai' ), $page_type )
                );
        }

        if ( empty( $url ) ) {
            return $this->format_outcome(
                'url_not_available',
                array( 'page_type' => $page_type ),
                __( 'Could not determine URL for this page type.', 'glimmr-ai' )
            );
        }

        return $this->build_navigation_response( $url, $page_type, $label );
    }

    /**
     * Navigate by page slug (find the page first).
     *
     * @param string $page_slug Page slug to find.
     * @return array Tool result.
     */
    private function navigate_by_slug( $page_slug ) {
        // Try to find a WordPress page with this slug.
        $page = get_page_by_path( $page_slug );

        if ( $page && 'publish' === $page->post_status ) {
            $url = get_permalink( $page );
            if ( ! $url ) {
                return $this->format_outcome(
                    'url_not_available',
                    array( 'page_slug' => $page_slug ),
                    __( 'Could not generate page URL.', 'glimmr-ai' )
                );
            }
            $label = sprintf( __( 'Opening %s page...', 'glimmr-ai' ), $page->post_title );
            return $this->build_navigation_response( $url, 'page', $label );
        }

        // Try to find a WooCommerce category.
        if ( $this->is_wc_active() ) {
            $term = get_term_by( 'slug', $page_slug, 'product_cat' );
            if ( $term ) {
                $url = get_term_link( $term );
                // get_term_link() can return WP_Error.
                if ( is_wp_error( $url ) ) {
                    return $this->format_outcome(
                        'url_not_available',
                        array( 'page_slug' => $page_slug, 'error' => $url->get_error_message() ),
                        __( 'Could not generate category URL.', 'glimmr-ai' )
                    );
                }
                $label = sprintf( __( 'Opening %s category page...', 'glimmr-ai' ), $term->name );
                return $this->build_navigation_response( $url, 'category', $label );
            }

            // Try product by slug.
            $product = get_page_by_path( $page_slug, OBJECT, 'product' );
            if ( $product ) {
                $wc_product = wc_get_product( $product->ID );
                if ( $wc_product ) {
                    $url   = $wc_product->get_permalink();
                    $label = sprintf( __( 'Opening %s product page...', 'glimmr-ai' ), $wc_product->get_name() );
                    return $this->build_navigation_response( $url, 'product', $label );
                }
            }
        }

        return $this->format_outcome(
            'not_found',
            array( 'page_slug' => $page_slug ),
            sprintf( __( 'Could not find a page with slug "%s".', 'glimmr-ai' ), $page_slug )
        );
    }

    /**
     * Build the navigation response with ui_action.
     *
     * @param string $url       The URL to navigate to.
     * @param string $page_type The type of page.
     * @param string $label     User-facing label.
     * @return array Tool result.
     */
    private function build_navigation_response( $url, $page_type, $label ) {
        return $this->format_outcome(
            'navigating',
            array(
                'url'       => $url,
                'page_type' => $page_type,
                'ui_action' => array(
                    'action' => 'open_url',
                    'url'    => $url,
                    'target' => '_self', // Same tab for internal navigation.
                ),
            ),
            $label
        );
    }

    /**
     * Check if a URL is internal (same site).
     *
     * @param string $url URL to check.
     * @return bool True if internal.
     */
    private function is_internal_url( $url ) {
        // Parse the URL.
        $parsed = wp_parse_url( $url );

        if ( ! $parsed ) {
            return false;
        }

        // If no host, it's a relative URL (internal).
        if ( empty( $parsed['host'] ) ) {
            return true;
        }

        // Compare against site URL.
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! is_string( $site_host ) ) {
            return false;
        }

        // Allow exact match or www variant.
        $url_host = strtolower( $parsed['host'] );
        $site_host = strtolower( $site_host );

        if ( $url_host === $site_host ) {
            return true;
        }

        // Check for www prefix variations.
        if ( 'www.' . $url_host === $site_host || $url_host === 'www.' . $site_host ) {
            return true;
        }

        return false;
    }

    /**
     * Check if current user can view an order.
     *
     * @param WC_Order $order Order object.
     * @return bool True if user can view.
     */
    private function user_can_view_order( $order ) {
        // Admins can view all orders.
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        // Order owner can view.
        if ( $this->user_id > 0 && $order->get_customer_id() === $this->user_id ) {
            return true;
        }

        // Check if logged-in user's email matches order email.
        if ( $this->user_id > 0 ) {
            $user = wp_get_current_user();
            if ( hash_equals( strtolower( $user->user_email ), strtolower( $order->get_billing_email() ) ) ) {
                return true;
            }
        }

        return false;
    }
}
