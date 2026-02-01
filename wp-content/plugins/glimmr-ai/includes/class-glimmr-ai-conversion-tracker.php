<?php
/**
 * Conversion Tracker
 *
 * Tracks conversions from chat interactions to purchases.
 * Hooks into WooCommerce events for attribution.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Conversion_Tracker
 *
 * Handles:
 * - WooCommerce event hooks
 * - Conversion attribution
 * - Order tracking
 */
class Glimmr_AI_Conversion_Tracker {

    /**
     * Initialize the tracker.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Register WooCommerce hooks.
     */
    private function init_hooks() {
        // Only register if WooCommerce is active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Add to cart tracking.
        add_action( 'woocommerce_add_to_cart', array( $this, 'track_add_to_cart' ), 10, 6 );

        // Checkout started.
        add_action( 'woocommerce_before_checkout_form', array( $this, 'track_checkout_started' ) );

        // Order completed (payment received).
        add_action( 'woocommerce_payment_complete', array( $this, 'track_order_completed' ) );

        // Fallback for non-payment orders (e.g., COD).
        add_action( 'woocommerce_thankyou', array( $this, 'track_order_thankyou' ) );

        // Store conversation ID in order meta.
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_attribution_to_order' ), 10, 2 );

        // Show attribution in admin order details.
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_attribution' ) );
    }

    /**
     * Track add to cart event.
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product ID.
     * @param int    $quantity      Quantity.
     * @param int    $variation_id  Variation ID.
     * @param array  $variation     Variation data.
     * @param array  $cart_item_data Cart item data.
     */
    public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $conversation_id = Glimmr_AI_Analytics::get_attribution_conversation_id();

        if ( ! $conversation_id ) {
            return;
        }

        $actual_product_id = $variation_id ? $variation_id : $product_id;

        Glimmr_AI_Analytics::track_add_to_cart( $conversation_id, $actual_product_id, $quantity );
    }

    /**
     * Track checkout started.
     */
    public function track_checkout_started() {
        $conversation_id = Glimmr_AI_Analytics::get_attribution_conversation_id();

        if ( ! $conversation_id ) {
            return;
        }

        // Only track once per session.
        $tracked_key = 'glimmr_ai_checkout_tracked_' . $conversation_id;
        if ( WC()->session && WC()->session->get( $tracked_key ) ) {
            return;
        }

        Glimmr_AI_Analytics::track(
            Glimmr_AI_Analytics::EVENT_CHECKOUT_STARTED,
            array(
                'cart_total' => WC()->cart ? WC()->cart->get_total( 'edit' ) : 0,
                'item_count' => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
            ),
            $conversation_id
        );

        if ( WC()->session ) {
            WC()->session->set( $tracked_key, true );
        }
    }

    /**
     * Track order completed (payment received).
     *
     * @param int $order_id Order ID.
     */
    public function track_order_completed( $order_id ) {
        $this->process_order_conversion( $order_id );
    }

    /**
     * Track order on thank you page (fallback).
     *
     * @param int $order_id Order ID.
     */
    public function track_order_thankyou( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        // Check if already tracked (HPOS-compatible).
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_glimmr_ai_conversion_tracked' ) ) {
            return;
        }

        $this->process_order_conversion( $order_id );
    }

    /**
     * Process order conversion.
     *
     * @param int $order_id Order ID.
     */
    private function process_order_conversion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if already tracked.
        if ( $order->get_meta( '_glimmr_ai_conversion_tracked' ) ) {
            return;
        }

        // Get conversation ID from order meta or session.
        $conversation_id = $order->get_meta( '_glimmr_ai_conversation_id' );

        if ( ! $conversation_id ) {
            $conversation_id = Glimmr_AI_Analytics::get_attribution_conversation_id();
        }

        if ( ! $conversation_id ) {
            return;
        }

        // Track the conversion.
        Glimmr_AI_Analytics::track_conversion(
            $order_id,
            $conversation_id,
            (float) $order->get_total()
        );

        // Track products purchased.
        $items = $order->get_items();
        if ( is_array( $items ) || is_iterable( $items ) ) {
            foreach ( $items as $item ) {
                Glimmr_AI_Analytics::track(
                    'product_purchased',
                    array(
                        'product_id' => $item->get_product_id(),
                        'quantity'   => $item->get_quantity(),
                        'total'      => $item->get_total(),
                    ),
                    $conversation_id
                );
            }
        }

        // Mark as tracked.
        $order->update_meta_data( '_glimmr_ai_conversion_tracked', true );
        $save_result = $order->save();

        // Log if save failed to help debug duplicate tracking issues.
        if ( ! $save_result ) {
            Glimmr_AI_Logger::warning(
                'Failed to save conversion tracking meta to order',
                array(
                    'order_id'        => $order_id,
                    'conversation_id' => $conversation_id,
                ),
                'conversion'
            );
        }

        // Clear attribution.
        Glimmr_AI_Analytics::clear_attribution();
    }

    /**
     * Save attribution to order during checkout.
     *
     * @param WC_Order $order Order object.
     * @param array    $data  Checkout data.
     */
    public function save_attribution_to_order( $order, $data ) {
        $conversation_id = Glimmr_AI_Analytics::get_attribution_conversation_id();

        if ( $conversation_id ) {
            $order->update_meta_data( '_glimmr_ai_conversation_id', $conversation_id );
            $order->update_meta_data( '_glimmr_ai_attributed', 'yes' );
        }
    }

    /**
     * Display attribution in admin order details.
     *
     * @param WC_Order $order Order object.
     */
    public function display_order_attribution( $order ) {
        $conversation_id = $order->get_meta( '_glimmr_ai_conversation_id' );
        $attributed = $order->get_meta( '_glimmr_ai_attributed' );

        if ( ! $attributed ) {
            return;
        }

        ?>
        <div class="glimmr-ai-attribution" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-left: 3px solid #4F46E5;">
            <h4 style="margin: 0 0 5px 0; color: #4F46E5;">
                <span class="dashicons dashicons-format-chat" style="margin-right: 5px;"></span>
                AI Assistant Attribution
            </h4>
            <p style="margin: 0; font-size: 13px; color: #666;">
                This order was influenced by the AI Shopping Assistant.
            </p>
            <?php if ( $conversation_id ) : ?>
                <p style="margin: 5px 0 0 0; font-size: 12px;">
                    <strong>Conversation:</strong>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=glimmr-ai-conversations&conversation=' . $conversation_id ) ); ?>">
                        <?php echo esc_html( substr( $conversation_id, 0, 20 ) . '...' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Reporting
    // =========================================================================

    /**
     * Get conversion statistics.
     *
     * @param string $period Period (today, week, month, year).
     * @return array Conversion stats.
     */
    public static function get_conversion_stats( $period = 'month' ) {
        $summary = Glimmr_AI_Analytics::get_summary( $period );

        // Calculate additional metrics.
        $cart_to_purchase = $summary['add_to_carts'] > 0
            ? round( ( $summary['conversions'] / $summary['add_to_carts'] ) * 100, 1 )
            : 0;

        $avg_order_value = $summary['conversions'] > 0
            ? round( $summary['revenue'] / $summary['conversions'], 2 )
            : 0;

        return array(
            'conversations'         => $summary['conversations'],
            'add_to_carts'          => $summary['add_to_carts'],
            'conversions'           => $summary['conversions'],
            'revenue'               => $summary['revenue'],
            'conversion_rate'       => $summary['conversion_rate'],
            'cart_to_purchase_rate' => $cart_to_purchase,
            'avg_order_value'       => $avg_order_value,
        );
    }

    /**
     * Get attributed orders.
     *
     * @param int    $limit  Number of orders.
     * @param string $period Period.
     * @return array Orders with attribution.
     */
    public static function get_attributed_orders( $limit = 20, $period = 'month' ) {
        $args = array(
            'limit'      => $limit,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => '_glimmr_ai_attributed',
            'meta_value' => 'yes',
        );

        // Add date filter.
        switch ( $period ) {
            case 'today':
                $args['date_created'] = '>=' . gmdate( 'Y-m-d' );
                break;
            case 'week':
                $args['date_created'] = '>=' . gmdate( 'Y-m-d', strtotime( '-7 days' ) );
                break;
            case 'month':
                $args['date_created'] = '>=' . gmdate( 'Y-m-d', strtotime( '-30 days' ) );
                break;
        }

        $orders = wc_get_orders( $args );

        $results = array();
        foreach ( $orders as $order ) {
            $date_created = $order->get_date_created();
            $results[] = array(
                'order_id'        => $order->get_id(),
                'order_number'    => $order->get_order_number(),
                'status'          => $order->get_status(),
                'total'           => $order->get_total(),
                'date'            => $date_created ? $date_created->format( 'Y-m-d H:i:s' ) : null,
                'customer'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'conversation_id' => $order->get_meta( '_glimmr_ai_conversation_id' ),
            );
        }

        return $results;
    }

    /**
     * Get revenue by conversation.
     *
     * @param string $conversation_id Conversation ID.
     * @return array Revenue data.
     */
    public static function get_conversation_revenue( $conversation_id ) {
        $args = array(
            'limit'      => -1,
            'meta_key'   => '_glimmr_ai_conversation_id',
            'meta_value' => $conversation_id,
            'status'     => array( 'completed', 'processing' ),
        );

        $orders = wc_get_orders( $args );

        $total_revenue = 0;
        $order_count = 0;

        foreach ( $orders as $order ) {
            $total_revenue += (float) $order->get_total();
            $order_count++;
        }

        return array(
            'order_count'   => $order_count,
            'total_revenue' => $total_revenue,
        );
    }
}
