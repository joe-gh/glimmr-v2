<?php
/**
 * Order History Tool
 *
 * Retrieves order history for logged-in customers.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Order_History
 *
 * Provides access to customer's past orders with filtering options.
 */
class Glimmr_AI_Tool_Order_History extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'order_history';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get the order history for a logged-in customer. Returns a list of past orders with status, totals, and dates. Requires the customer to be logged in.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'status' => array(
            'type'        => 'string',
            'description' => 'Filter by order status: all, processing, completed, on-hold, cancelled',
            'required'    => false,
            'enum'        => array( 'all', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded' ),
        ),
        'limit' => array(
            'type'        => 'integer',
            'description' => 'Maximum number of orders to return (default: 5)',
            'required'    => false,
        ),
        'date_range' => array(
            'type'        => 'string',
            'description' => 'Filter by time period: all, last_month, last_3_months, last_6_months, last_year',
            'required'    => false,
            'enum'        => array( 'all', 'last_month', 'last_3_months', 'last_6_months', 'last_year' ),
        ),
        'include_items' => array(
            'type'        => 'boolean',
            'description' => 'Include line items in the response',
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

        $status        = $this->get_string_arg( $arguments, 'status', 'all' );
        $limit         = min( $this->get_int_arg( $arguments, 'limit', 5 ), 20 );
        $date_range    = $this->get_string_arg( $arguments, 'date_range', 'all' );

        // Always include items - the frontend needs them for display.
        $include_items = true;

        // Build query args.
        $query_args = array(
            'customer_id' => $this->user_id,
            'limit'       => $limit,
            'orderby'     => 'date',
            'order'       => 'DESC',
        );

        // Status filter.
        if ( 'all' !== $status ) {
            $query_args['status'] = $status;
        } else {
            // Exclude drafts and trashed.
            $query_args['status'] = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
        }

        // Date range filter.
        $date_after = $this->get_date_range_start( $date_range );
        if ( $date_after ) {
            $query_args['date_after'] = $date_after;
        }

        // Get orders.
        $orders = wc_get_orders( $query_args );

        if ( empty( $orders ) ) {
            $message = 'all' === $status
                ? __( 'You don\'t have any orders yet.', 'glimmr-ai' )
                : sprintf( __( 'No orders found with status: %s', 'glimmr-ai' ), $status );

            return $this->format_result(
                array(),
                true,
                $message
            );
        }

        // Format orders.
        $formatted_orders = array();
        foreach ( $orders as $order ) {
            $formatted_orders[] = $this->format_order( $order, $include_items );
        }

        // Add summary.
        $summary = $this->get_order_summary();

        return $this->format_result(
            array(
                'orders'  => $formatted_orders,
                'summary' => $summary,
            ),
            true,
            sprintf(
                _n( 'Found %d order.', 'Found %d orders.', count( $formatted_orders ), 'glimmr-ai' ),
                count( $formatted_orders )
            )
        );
    }

    /**
     * Get start date for date range filter.
     *
     * @param string $date_range Date range string.
     * @return string|null Date string or null.
     */
    private function get_date_range_start( $date_range ) {
        switch ( $date_range ) {
            case 'last_month':
                return date( 'Y-m-d', strtotime( '-1 month' ) );
            case 'last_3_months':
                return date( 'Y-m-d', strtotime( '-3 months' ) );
            case 'last_6_months':
                return date( 'Y-m-d', strtotime( '-6 months' ) );
            case 'last_year':
                return date( 'Y-m-d', strtotime( '-1 year' ) );
            default:
                return null;
        }
    }

    /**
     * Get order summary statistics.
     *
     * @return array Summary data.
     */
    private function get_order_summary() {
        $orders = wc_get_orders( array(
            'customer_id' => $this->user_id,
            'status'      => array( 'processing', 'completed' ),
            'limit'       => -1,
        ) );

        // Ensure we have an array (wc_get_orders should always return array, but defensive).
        if ( ! is_array( $orders ) ) {
            $orders = array();
        }

        $total_orders = 0;
        $total_spent  = 0;
        $processing   = 0;
        $completed    = 0;

        foreach ( $orders as $order ) {
            // Validate order is a valid WC_Order object.
            if ( ! $order instanceof WC_Order ) {
                continue;
            }

            $total_orders++;
            $total_spent += $order->get_total();

            // Count by status.
            $status = $order->get_status();
            if ( 'processing' === $status ) {
                $processing++;
            } elseif ( 'completed' === $status ) {
                $completed++;
            }
        }

        return array(
            'total_orders'      => $total_orders,
            'total_spent'       => $this->format_price( $total_spent ),
            'orders_processing' => $processing,
            'orders_completed'  => $completed,
        );
    }

    /**
     * Format order for output.
     *
     * Override parent method to add more details.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $include_items Whether to include items.
     * @return array Formatted order data.
     */
    protected function format_order( $order, $include_items = false ) {
        $data = parent::format_order( $order, $include_items );

        if ( null === $data ) {
            return null;
        }

        // Add view order URL.
        $data['view_url'] = $order->get_view_order_url();

        // Add item count.
        $data['item_count'] = $order->get_item_count();

        // Add order age.
        $created = $order->get_date_created();
        if ( $created ) {
            $data['order_age'] = human_time_diff( $created->getTimestamp(), current_time( 'timestamp' ) ) . ' ago';
        }

        // Add reorder capability.
        $can_reorder = true;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                $can_reorder = false;
                break;
            }
        }
        $data['can_reorder'] = $can_reorder;

        return $data;
    }
}
