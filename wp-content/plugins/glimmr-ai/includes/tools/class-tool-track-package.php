<?php
/**
 * Track Package Tool
 *
 * Provides package tracking with direct carrier URLs.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Track_Package
 *
 * Retrieves tracking information from orders or direct tracking numbers
 * and provides carrier tracking URLs.
 */
class Glimmr_AI_Tool_Track_Package extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'track_package';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Track a package by order ID or tracking number. Returns tracking information with carrier tracking URL. Requires either order_id (logged-in user only) or tracking_number.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'order_id' => array(
            'type'        => 'integer',
            'description' => 'Order ID to get tracking information for (logged-in users only, validates ownership)',
        ),
        'tracking_number' => array(
            'type'        => 'string',
            'description' => 'Direct tracking number to look up (when order_id is not available)',
        ),
        'carrier' => array(
            'type'        => 'string',
            'description' => 'Carrier name if known (usps, ups, fedex, dhl, etc.). If not provided, will attempt to detect from tracking number format.',
            'enum'        => array( 'usps', 'ups', 'fedex', 'dhl', 'ontrac', 'lasership', 'amazon' ),
        ),
    );

    /**
     * Tracking meta keys to search in order meta.
     *
     * @var array
     */
    private $tracking_meta_keys = array(
        '_wc_shipment_tracking_items',
        '_aftership_tracking_number',
        '_tracking_number',
        'tracking_number',
        '_shipment_tracking_number',
        '_easypost_tracking_code',
        '_shippo_tracking_number',
        '_shiprocket_tracking_number',
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

        $order_id = $this->get_int_arg( $arguments, 'order_id' );
        $tracking_number = $this->get_string_arg( $arguments, 'tracking_number' );
        $carrier_hint = $this->get_string_arg( $arguments, 'carrier' );

        // Validate: at least one of order_id or tracking_number required.
        if ( empty( $order_id ) && empty( $tracking_number ) ) {
            return $this->format_error(
                'missing_parameter',
                __( 'Please provide either an order ID or a tracking number to track the package.', 'glimmr-ai' )
            );
        }

        // If order_id provided, get tracking from order.
        if ( ! empty( $order_id ) ) {
            return $this->track_from_order( $order_id, $carrier_hint );
        }

        // Direct tracking number provided.
        return $this->track_from_number( $tracking_number, $carrier_hint );
    }

    /**
     * Get tracking information from an order.
     *
     * @param int    $order_id     The order ID.
     * @param string $carrier_hint Optional carrier hint.
     * @return array Tool result.
     */
    private function track_from_order( $order_id, $carrier_hint ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return $this->format_outcome(
                'order_not_found',
                array( 'order_id' => $order_id ),
                __( 'Order not found. Please check the order number and try again.', 'glimmr-ai' )
            );
        }

        // S1: Verify order ownership (only user's own orders).
        $current_user_id = get_current_user_id();
        if ( $current_user_id <= 0 ) {
            return $this->format_outcome(
                'login_required',
                array( 'order_id' => $order_id ),
                __( 'Please log in to track your order, or provide the tracking number directly.', 'glimmr-ai' )
            );
        }

        $order_user_id = $order->get_customer_id();
        if ( $order_user_id !== $current_user_id ) {
            // S12: Generic error to prevent order enumeration.
            return $this->format_outcome(
                'order_not_found',
                array( 'order_id' => $order_id ),
                __( 'Order not found. Please check the order number and try again.', 'glimmr-ai' )
            );
        }

        // Search all meta for tracking information.
        $tracking_data = $this->extract_tracking_from_order( $order, $carrier_hint );

        if ( empty( $tracking_data ) ) {
            // Check order status to provide helpful message.
            $status = $order->get_status();
            $message = __( 'Tracking information is not yet available for this order.', 'glimmr-ai' );

            if ( in_array( $status, array( 'pending', 'on-hold', 'processing' ), true ) ) {
                $message = __( 'Your order is still being processed. Tracking information will be available once your order ships.', 'glimmr-ai' );
            }

            return $this->format_outcome(
                'no_tracking',
                array(
                    'order_id'     => $order_id,
                    'order_number' => $order->get_order_number(),
                    'order_status' => wc_get_order_status_name( $status ),
                ),
                $message
            );
        }

        return $this->format_tracking_result( $tracking_data, $order );
    }

    /**
     * Get tracking information from a direct tracking number.
     *
     * @param string $tracking_number The tracking number.
     * @param string $carrier_hint    Optional carrier hint.
     * @return array Tool result.
     */
    private function track_from_number( $tracking_number, $carrier_hint ) {
        // Clean up tracking number.
        $tracking_number = preg_replace( '/\s+/', '', trim( $tracking_number ) );

        if ( empty( $tracking_number ) ) {
            return $this->format_error(
                'invalid_tracking_number',
                __( 'Please provide a valid tracking number.', 'glimmr-ai' )
            );
        }

        // Detect carrier from tracking number if not provided.
        $carrier = $carrier_hint;
        if ( empty( $carrier ) ) {
            $carrier = $this->detect_carrier_from_tracking( $tracking_number );
        }

        // Generate tracking URL.
        $tracking_url = $this->generate_tracking_url( $carrier, $tracking_number );

        return $this->format_outcome(
            'tracking_found',
            array(
                'tracking_number' => $tracking_number,
                'carrier'         => $this->format_carrier_name( $carrier ),
                'carrier_code'    => $carrier,
                'tracking_url'    => $tracking_url,
                'ui_action'       => array(
                    'action'  => 'open_url',
                    'url'     => $tracking_url,
                    'target'  => '_blank',
                    'carrier' => $this->format_carrier_name( $carrier ),
                ),
            ),
            sprintf(
                /* translators: %s: carrier name */
                __( 'Here is your tracking link for %s.', 'glimmr-ai' ),
                $this->format_carrier_name( $carrier )
            )
        );
    }

    /**
     * Extract tracking information from order meta.
     *
     * @param WC_Order $order        The order object.
     * @param string   $carrier_hint Optional carrier hint.
     * @return array|null Tracking data or null if not found.
     */
    private function extract_tracking_from_order( $order, $carrier_hint ) {
        // 1. Check WooCommerce Shipment Tracking plugin format (array structure).
        $wc_tracking = $order->get_meta( '_wc_shipment_tracking_items' );
        if ( is_array( $wc_tracking ) && isset( $wc_tracking[0] ) ) {
            $tracking = $wc_tracking[0];
            $tracking_number = $tracking['tracking_number'] ?? '';
            $carrier = $tracking['tracking_provider'] ?? $tracking['custom_tracking_provider'] ?? $carrier_hint;
            $tracking_url = $tracking['custom_tracking_link'] ?? '';

            if ( ! empty( $tracking_number ) ) {
                if ( empty( $tracking_url ) ) {
                    $tracking_url = $this->generate_tracking_url( $carrier, $tracking_number );
                }

                return array(
                    'tracking_number' => $tracking_number,
                    'carrier'         => $carrier,
                    'tracking_url'    => $tracking_url,
                );
            }
        }

        // 2. Search common tracking meta keys.
        foreach ( $this->tracking_meta_keys as $meta_key ) {
            $value = $order->get_meta( $meta_key );

            if ( ! empty( $value ) && is_string( $value ) ) {
                // Found a tracking number.
                $carrier = $carrier_hint;
                if ( empty( $carrier ) ) {
                    // Try to get carrier from related meta.
                    $carrier = $this->get_carrier_from_order_meta( $order );
                    if ( empty( $carrier ) ) {
                        $carrier = $this->detect_carrier_from_tracking( $value );
                    }
                }

                return array(
                    'tracking_number' => $value,
                    'carrier'         => $carrier,
                    'tracking_url'    => $this->generate_tracking_url( $carrier, $value ),
                );
            }
        }

        // 3. Pattern search: look for any meta key containing "tracking".
        $all_meta = $order->get_meta_data();
        foreach ( $all_meta as $meta ) {
            $key = $meta->key;
            $value = $meta->value;

            // Skip internal/private meta we've already checked.
            if ( in_array( $key, $this->tracking_meta_keys, true ) ) {
                continue;
            }

            // Look for tracking-related meta keys.
            if ( stripos( $key, 'tracking' ) !== false || stripos( $key, 'shipment' ) !== false ) {
                // Handle serialized arrays.
                if ( is_array( $value ) ) {
                    $tracking_number = $this->extract_tracking_from_array( $value );
                    if ( $tracking_number ) {
                        $carrier = $carrier_hint ?: $this->detect_carrier_from_tracking( $tracking_number );
                        return array(
                            'tracking_number' => $tracking_number,
                            'carrier'         => $carrier,
                            'tracking_url'    => $this->generate_tracking_url( $carrier, $tracking_number ),
                        );
                    }
                } elseif ( is_string( $value ) && $this->looks_like_tracking_number( $value ) ) {
                    $carrier = $carrier_hint ?: $this->detect_carrier_from_tracking( $value );
                    return array(
                        'tracking_number' => $value,
                        'carrier'         => $carrier,
                        'tracking_url'    => $this->generate_tracking_url( $carrier, $value ),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Extract tracking number from array structure.
     *
     * @param array $data Array data that may contain tracking number.
     * @return string|null Tracking number or null.
     */
    private function extract_tracking_from_array( $data ) {
        // Common keys for tracking numbers in arrays.
        $tracking_keys = array(
            'tracking_number',
            'tracking_code',
            'number',
            'code',
            'trackingNumber',
            'TrackingNumber',
        );

        foreach ( $tracking_keys as $key ) {
            if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && ! empty( $data[ $key ] ) ) {
                return $data[ $key ];
            }
        }

        // Check first element if it's a nested array (like shipment tracking items).
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return $this->extract_tracking_from_array( $data[0] );
        }

        return null;
    }

    /**
     * Get carrier from order meta.
     *
     * @param WC_Order $order The order object.
     * @return string|null Carrier name or null.
     */
    private function get_carrier_from_order_meta( $order ) {
        $carrier_keys = array(
            '_tracking_provider',
            '_shipping_carrier',
            'tracking_carrier',
            '_carrier',
            'carrier',
        );

        foreach ( $carrier_keys as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) && is_string( $value ) ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Check if a string looks like a tracking number.
     *
     * @param string $value The value to check.
     * @return bool Whether it looks like a tracking number.
     */
    private function looks_like_tracking_number( $value ) {
        // Tracking numbers are typically 10-30 alphanumeric characters.
        $clean = preg_replace( '/\s+/', '', $value );
        return preg_match( '/^[A-Z0-9]{10,35}$/i', $clean ) === 1;
    }

    /**
     * Detect carrier from tracking number pattern.
     *
     * @param string $tracking_number The tracking number.
     * @return string Detected carrier code or 'unknown'.
     */
    private function detect_carrier_from_tracking( $tracking_number ) {
        $tracking = strtoupper( preg_replace( '/\s+/', '', $tracking_number ) );

        // USPS patterns.
        // 22-digit numbers starting with 94 or 93.
        if ( preg_match( '/^9[234]\d{20}$/', $tracking ) ) {
            return 'usps';
        }
        // 13-character international (2 letters + 9 digits + 2 letters).
        if ( preg_match( '/^[A-Z]{2}\d{9}[A-Z]{2}$/', $tracking ) ) {
            return 'usps';
        }

        // UPS patterns.
        // 1Z followed by 16 alphanumeric characters.
        if ( preg_match( '/^1Z[A-Z0-9]{16}$/i', $tracking ) ) {
            return 'ups';
        }

        // FedEx patterns.
        // 12, 14, 15, 20, or 22 digit numbers.
        if ( preg_match( '/^\d{12}$/', $tracking ) ||
             preg_match( '/^\d{14}$/', $tracking ) ||
             preg_match( '/^\d{15}$/', $tracking ) ||
             preg_match( '/^\d{20}$/', $tracking ) ||
             preg_match( '/^\d{22}$/', $tracking ) ) {
            return 'fedex';
        }
        // Door tag numbers: DT + 12 digits.
        if ( preg_match( '/^DT\d{12}$/i', $tracking ) ) {
            return 'fedex';
        }

        // DHL patterns.
        // 10-digit numbers.
        if ( preg_match( '/^\d{10}$/', $tracking ) ) {
            return 'dhl';
        }
        // JD + 18 digits (DHL eCommerce).
        if ( preg_match( '/^JD\d{18}$/i', $tracking ) ) {
            return 'dhl';
        }

        // OnTrac pattern.
        // C + 14 digits or D + 14 digits.
        if ( preg_match( '/^[CD]\d{14}$/', $tracking ) ) {
            return 'ontrac';
        }

        // LaserShip pattern.
        // LS + 9 digits, or LX + 10-12 alphanumeric.
        if ( preg_match( '/^L[SX][A-Z0-9]{9,12}$/i', $tracking ) ) {
            return 'lasership';
        }

        // Amazon Logistics.
        // TBA + alphanumeric.
        if ( preg_match( '/^TBA[A-Z0-9]+$/i', $tracking ) ) {
            return 'amazon';
        }

        // Default to unknown - will use Google search fallback.
        return 'unknown';
    }

    /**
     * Generate tracking URL for carrier.
     *
     * @param string $carrier         Carrier code.
     * @param string $tracking_number Tracking number.
     * @return string Tracking URL.
     */
    private function generate_tracking_url( $carrier, $tracking_number ) {
        $carrier_lower = strtolower( trim( $carrier ) );
        $tracking_encoded = rawurlencode( $tracking_number );

        // Carrier URL templates.
        $carrier_urls = array(
            'usps'       => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tracking_encoded,
            'ups'        => 'https://www.ups.com/track?tracknum=' . $tracking_encoded,
            'fedex'      => 'https://www.fedex.com/fedextrack/?trknbr=' . $tracking_encoded,
            'dhl'        => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id=' . $tracking_encoded,
            'ontrac'     => 'https://www.ontrac.com/trackingdetail.asp?tracking=' . $tracking_encoded,
            'lasership'  => 'https://www.lasership.com/track/' . $tracking_encoded,
            'amazon'     => 'https://www.amazon.com/progress-tracker/package/?itemId=' . $tracking_encoded,
        );

        // Allow extending carrier URLs via filter.
        $carrier_urls = apply_filters( 'glimmr_ai_carrier_tracking_urls', $carrier_urls, $tracking_number );

        // Check for carrier match.
        if ( isset( $carrier_urls[ $carrier_lower ] ) ) {
            return $carrier_urls[ $carrier_lower ];
        }

        // Check for partial match in carrier name.
        foreach ( $carrier_urls as $key => $url ) {
            if ( stripos( $carrier_lower, $key ) !== false ) {
                return $url;
            }
        }

        // Fallback: Google search.
        return 'https://www.google.com/search?q=' . $tracking_encoded . '+tracking';
    }

    /**
     * Format carrier name for display.
     *
     * @param string $carrier Carrier code.
     * @return string Formatted carrier name.
     */
    private function format_carrier_name( $carrier ) {
        $names = array(
            'usps'      => 'USPS',
            'ups'       => 'UPS',
            'fedex'     => 'FedEx',
            'dhl'       => 'DHL',
            'ontrac'    => 'OnTrac',
            'lasership' => 'LaserShip',
            'amazon'    => 'Amazon Logistics',
            'unknown'   => 'Carrier',
        );

        $carrier_lower = strtolower( $carrier );
        return $names[ $carrier_lower ] ?? ucfirst( $carrier );
    }

    /**
     * Format tracking result for output.
     *
     * @param array         $tracking_data Tracking data array.
     * @param WC_Order|null $order         Optional order object for context.
     * @return array Tool result.
     */
    private function format_tracking_result( $tracking_data, $order = null ) {
        $carrier = $tracking_data['carrier'];
        $carrier_name = $this->format_carrier_name( $carrier );

        $data = array(
            'tracking_number' => $tracking_data['tracking_number'],
            'carrier'         => $carrier_name,
            'carrier_code'    => strtolower( $carrier ),
            'tracking_url'    => $tracking_data['tracking_url'],
            'ui_action'       => array(
                'action'  => 'open_url',
                'url'     => $tracking_data['tracking_url'],
                'target'  => '_blank',
                'carrier' => $carrier_name,
            ),
        );

        // Add order context if available.
        if ( $order ) {
            $data['order_id'] = $order->get_id();
            $data['order_number'] = $order->get_order_number();
            $data['order_status'] = wc_get_order_status_name( $order->get_status() );
        }

        return $this->format_outcome(
            'tracking_found',
            $data,
            sprintf(
                /* translators: %s: carrier name */
                __( 'Here is your tracking link for %s.', 'glimmr-ai' ),
                $carrier_name
            )
        );
    }
}
