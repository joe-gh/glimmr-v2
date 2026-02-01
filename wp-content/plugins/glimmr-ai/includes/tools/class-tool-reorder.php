<?php
/**
 * Reorder Tool
 *
 * Allows customers to quickly reorder all items from a previous order.
 *
 * @package Glimmr_AI
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Reorder
 *
 * Adds all items from a previous order to the customer's cart.
 */
class Glimmr_AI_Tool_Reorder extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'reorder';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Quickly reorder all items from a previous order. Adds all purchasable, in-stock items from the specified order to the cart. Requires customer to be logged in.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'order_id' => array(
            'type'        => 'integer',
            'description' => 'The WooCommerce order ID to reorder from',
            'required'    => true,
        ),
        'replace_cart' => array(
            'type'        => 'boolean',
            'description' => 'If true, clears the cart before adding items. Default: false (adds to existing cart)',
            'required'    => false,
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

        $login_check = $this->require_login();
        if ( $login_check ) {
            return $login_check;
        }

        $order_id     = $this->get_int_arg( $arguments, 'order_id' );
        $replace_cart = $this->get_bool_arg( $arguments, 'replace_cart', false );

        if ( $order_id <= 0 ) {
            return $this->format_validation_error(
                'missing_required',
                'order_id',
                __( 'Please specify which order you would like to reorder from.', 'glimmr-ai' )
            );
        }

        // Get the order.
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return $this->format_outcome(
                'not_found',
                array( 'order_id' => $order_id ),
                __( 'Order not found. Please check the order number and try again.', 'glimmr-ai' )
            );
        }

        // Verify ownership - customer must own this order.
        if ( (int) $order->get_customer_id() !== $this->user_id ) {
            // S12: Generic error to prevent order enumeration.
            return $this->format_outcome(
                'not_found',
                array( 'order_id' => $order_id ),
                __( 'Order not found. Please check the order number and try again.', 'glimmr-ai' )
            );
        }

        // Get order items and check availability.
        $items = $order->get_items();
        if ( empty( $items ) ) {
            return $this->format_outcome(
                'empty_order',
                array( 'order_id' => $order_id ),
                __( 'This order has no items to reorder.', 'glimmr-ai' )
            );
        }

        // Analyze items for reorder capability.
        $reorderable_items = array();
        $unavailable_items = array();

        foreach ( $items as $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity     = $item->get_quantity();

            // Get the actual product (variation if applicable).
            $product = $variation_id ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

            if ( ! $product ) {
                $unavailable_items[] = array(
                    'name'   => $item->get_name(),
                    'reason' => __( 'Product no longer exists', 'glimmr-ai' ),
                );
                continue;
            }

            if ( ! $product->is_purchasable() ) {
                $unavailable_items[] = array(
                    'name'   => $product->get_name(),
                    'reason' => __( 'No longer available for purchase', 'glimmr-ai' ),
                );
                continue;
            }

            if ( ! $product->is_in_stock() ) {
                $unavailable_items[] = array(
                    'name'   => $product->get_name(),
                    'reason' => __( 'Out of stock', 'glimmr-ai' ),
                );
                continue;
            }

            // Check stock quantity if managed.
            $available_qty = $quantity;
            if ( $product->managing_stock() ) {
                $stock_qty = $product->get_stock_quantity();
                if ( $stock_qty < $quantity ) {
                    $available_qty = $stock_qty;
                    if ( $available_qty <= 0 ) {
                        $unavailable_items[] = array(
                            'name'   => $product->get_name(),
                            'reason' => __( 'Out of stock', 'glimmr-ai' ),
                        );
                        continue;
                    }
                }
            }

            // Get variation attributes if applicable.
            $variation_attrs = array();
            if ( $variation_id && $product->is_type( 'variation' ) ) {
                $variation_attrs = $product->get_variation_attributes();
            }

            $reorderable_items[] = array(
                'product_id'      => $product_id,
                'variation_id'    => $variation_id ?: null,
                'quantity'        => $available_qty,
                'original_qty'    => $quantity,
                'reduced'         => $available_qty < $quantity,
                'name'            => $product->get_name(),
                'price'           => $this->format_price( $product->get_price() ),
                'line_total'      => $this->format_price( $product->get_price() * $available_qty ),
                'attributes'      => $variation_attrs,
                'image'           => wp_get_attachment_url( $product->get_image_id() ) ?: wc_placeholder_img_src(),
            );
        }

        // If nothing can be reordered.
        if ( empty( $reorderable_items ) ) {
            return $this->format_outcome(
                'nothing_available',
                array(
                    'order_id'          => $order_id,
                    'unavailable_items' => $unavailable_items,
                ),
                __( 'None of the items from this order are currently available for reorder.', 'glimmr-ai' )
            );
        }

        // Calculate totals.
        $total_items = 0;
        $total_price = 0;
        foreach ( $reorderable_items as $item ) {
            $total_items += $item['quantity'];
            $total_price += floatval( str_replace( array( '$', ',', '£', '€' ), '', $item['line_total'] ) );
        }

        // Return cart_action intent for frontend execution.
        $date_created = $order->get_date_created();
        $order_date = $date_created ? $date_created->format( 'M j, Y' ) : '';

        return $this->format_outcome(
            'cart_action',
            array(
                'action'            => 'reorder',
                'order_id'          => $order_id,
                'order_number'      => $order->get_order_number(),
                'order_date'        => $order_date,
                'replace_cart'      => $replace_cart,
                'items'             => $reorderable_items,
                'unavailable_items' => $unavailable_items,
                'summary'           => array(
                    'total_items'       => $total_items,
                    'total_products'    => count( $reorderable_items ),
                    'estimated_total'   => $this->format_price( $total_price ),
                    'has_unavailable'   => ! empty( $unavailable_items ),
                    'unavailable_count' => count( $unavailable_items ),
                ),
                'cart_url'          => wc_get_cart_url(),
                'checkout_url'      => wc_get_checkout_url(),
            ),
            $this->build_reorder_message( $reorderable_items, $unavailable_items, $order )
        );
    }

    /**
     * Build a friendly message for the reorder response.
     *
     * @param array    $reorderable  Reorderable items.
     * @param array    $unavailable  Unavailable items.
     * @param WC_Order $order        The original order.
     * @return string Message.
     */
    private function build_reorder_message( $reorderable, $unavailable, $order ) {
        $item_count = 0;
        foreach ( $reorderable as $item ) {
            $item_count += $item['quantity'];
        }

        $message = sprintf(
            _n(
                'Adding %d item from order #%s to your cart...',
                'Adding %d items from order #%s to your cart...',
                $item_count,
                'glimmr-ai'
            ),
            $item_count,
            $order->get_order_number()
        );

        if ( ! empty( $unavailable ) ) {
            $message .= ' ' . sprintf(
                _n(
                    'Note: %d item is no longer available.',
                    'Note: %d items are no longer available.',
                    count( $unavailable ),
                    'glimmr-ai'
                ),
                count( $unavailable )
            );
        }

        return $message;
    }
}
