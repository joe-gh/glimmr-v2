<?php
/**
 * Order Status Tool
 *
 * Checks the status of a specific order.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 * @since 1.1.0 Refactored with lookup + verify nested objects.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Order_Status
 *
 * Retrieves order status, tracking information, and estimated delivery.
 * Uses structured lookup and verification blocks for clarity.
 */
class Glimmr_AI_Tool_Order_Status extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'order_status';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Check order status by order number or ID. For guests, requires verify.email AND verify.zip to access order details. Returns status, tracking, and order info.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        // New nested lookup object (preferred).
        'lookup' => array(
            'type'                 => 'object',
            'description'          => 'Identifies the order to look up (at least one of order_number or order_id required)',
            'additionalProperties' => false,
            'properties'           => array(
                'order_number' => array(
                    'type'        => 'string',
                    'description' => 'Order number (e.g., "10492" or "#10492")',
                    'maxLength'   => 50,
                ),
                'order_id' => array(
                    'type'        => 'integer',
                    'description' => 'Order ID (alternative to order_number)',
                    'minimum'     => 1,
                ),
            ),
        ),
        // New nested verify object (for guest access).
        'verify' => array(
            'type'                 => 'object',
            'description'          => 'Verification credentials (required for guest access - both email AND zip required)',
            'additionalProperties' => false,
            'properties'           => array(
                'email' => array(
                    'type'        => 'string',
                    'description' => 'Email address used for the order (required for guest verification)',
                    'maxLength'   => 254,
                ),
                'zip' => array(
                    'type'        => 'string',
                    'description' => 'Billing zip/postal code (required for guest verification)',
                    'maxLength'   => 20,
                ),
            ),
        ),
        // Auto-track option for opening tracking URL.
        'auto_track' => array(
            'type'        => 'boolean',
            'description' => 'If true and a tracking URL is available, automatically opens the carrier tracking page in a new tab. Use when user says "track my order", "where is my package", etc.',
        ),
        // Legacy parameters for backward compatibility.
        'order_number' => array(
            'type'        => 'string',
            'description' => '[DEPRECATED] Use lookup.order_number instead',
        ),
        'email' => array(
            'type'        => 'string',
            'description' => '[DEPRECATED] Use verify.email instead',
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

        // Extract parameters with backward compatibility.
        $lookup = $this->extract_lookup( $arguments );
        $verify = $this->extract_verify( $arguments );

        // Validate lookup parameters.
        if ( empty( $lookup['order_number'] ) && empty( $lookup['order_id'] ) ) {
            return $this->format_validation_error(
                'missing_lookup',
                'lookup',
                __( 'Please provide lookup.order_number or lookup.order_id to identify the order.', 'glimmr-ai' )
            );
        }

        // Find the order.
        $order = $this->find_order( $lookup );

        if ( ! $order ) {
            // S12: Consistent error messages - same message whether order doesn't exist or verification fails.
            return $this->format_outcome(
                'order_not_found',
                array(
                    'lookup' => $lookup,
                ),
                __( 'Order not found or verification failed. Please check the order number and try again.', 'glimmr-ai' )
            );
        }

        // Check rate limit before verification.
        if ( ! $this->check_email_verification_rate_limit() ) {
            return $this->format_outcome(
                'rate_limited',
                array(
                    'lookup'       => $lookup,
                    'retry_after'  => '15 minutes',
                    'max_attempts' => 5,
                ),
                __( 'Too many verification attempts. Please wait 15 minutes and try again.', 'glimmr-ai' )
            );
        }

        // Verify access to order.
        $access_result = $this->verify_order_access( $order, $verify );

        if ( 'verified' !== $access_result['status'] ) {
            return $this->format_outcome(
                $access_result['status'],
                array(
                    'order_number'          => $order->get_order_number(),
                    'verification_method'   => $access_result['method'],
                    'required_verification' => $access_result['required'],
                ),
                $access_result['message']
            );
        }

        // Get order details.
        $data = $this->get_order_details( $order );
        $data['verification_method'] = $access_result['method'];

        // Check if auto_track is requested and we have a tracking URL.
        $auto_track = ! empty( $arguments['auto_track'] );
        if ( $auto_track && ! empty( $data['tracking']['tracking_url'] ) ) {
            $data['ui_action'] = array(
                'action'  => 'open_url',
                'url'     => $data['tracking']['tracking_url'],
                'target'  => '_blank',
                'carrier' => $data['tracking']['carrier'] ?? '',
            );
        }

        return $this->format_outcome(
            'verified',
            $data,
            sprintf(
                __( 'Order %s: %s', 'glimmr-ai' ),
                $order->get_order_number(),
                wc_get_order_status_name( $order->get_status() )
            )
        );
    }

    /**
     * Extract lookup parameters with backward compatibility.
     *
     * @param array $arguments Tool arguments.
     * @return array Lookup parameters.
     */
    private function extract_lookup( $arguments ) {
        // Check for new nested format.
        if ( isset( $arguments['lookup'] ) && is_array( $arguments['lookup'] ) ) {
            return array(
                'order_number' => $arguments['lookup']['order_number'] ?? '',
                'order_id'     => isset( $arguments['lookup']['order_id'] ) ? (int) $arguments['lookup']['order_id'] : 0,
            );
        }

        // Fall back to legacy flat parameter.
        return array(
            'order_number' => $this->get_string_arg( $arguments, 'order_number', '' ),
            'order_id'     => 0,
        );
    }

    /**
     * Extract verification parameters with backward compatibility.
     *
     * @param array $arguments Tool arguments.
     * @return array Verify parameters.
     */
    private function extract_verify( $arguments ) {
        // Check for new nested format.
        if ( isset( $arguments['verify'] ) && is_array( $arguments['verify'] ) ) {
            return array(
                'email' => $arguments['verify']['email'] ?? '',
                'zip'   => $arguments['verify']['zip'] ?? '',
            );
        }

        // Fall back to legacy flat parameter.
        return array(
            'email' => $this->get_string_arg( $arguments, 'email', '' ),
            'zip'   => '',
        );
    }

    /**
     * Find order by lookup parameters.
     *
     * @param array $lookup Lookup parameters with order_number and/or order_id.
     * @return WC_Order|null Order object or null.
     */
    private function find_order( $lookup ) {
        // Try direct ID lookup first if provided.
        if ( ! empty( $lookup['order_id'] ) ) {
            $order = wc_get_order( (int) $lookup['order_id'] );
            if ( $order ) {
                return $order;
            }
        }

        // Try order_number lookup.
        if ( ! empty( $lookup['order_number'] ) ) {
            $order_number = trim( $lookup['order_number'] );
            $sanitized = preg_replace( '/^#/', '', $order_number );
            // preg_replace returns null on error.
            $order_number = ( null !== $sanitized ) ? $sanitized : $order_number;

            // Try direct ID lookup (order_number might be the ID).
            $order = wc_get_order( $order_number );
            if ( $order ) {
                return $order;
            }

            // Search by order number meta (some plugins store custom order numbers).
            $orders = wc_get_orders( array(
                'limit'  => 1,
                'return' => 'objects',
                'meta_query' => array(
                    array(
                        'key'   => '_order_number',
                        'value' => $order_number,
                    ),
                ),
            ) );

            if ( ! empty( $orders ) ) {
                return $orders[0];
            }

            // Note: Removed expensive fallback that loaded 100 orders to iterate through.
            // The meta_query above handles custom order number plugins efficiently.
        }

        return null;
    }

    /**
     * Verify customer has access to this order.
     *
     * Uses timing-safe comparison and rate limiting to prevent enumeration.
     *
     * @param WC_Order $order  Order object.
     * @param array    $verify Verification credentials.
     * @return array Verification result with status, method, required, and message.
     */
    private function verify_order_access( $order, $verify ) {
        // If user is logged in and owns the order by customer ID.
        if ( $this->user_id > 0 ) {
            if ( $order->get_customer_id() === $this->user_id ) {
                return array(
                    'status'   => 'verified',
                    'method'   => 'logged_in_owner',
                    'required' => array(),
                    'message'  => '',
                );
            }

            // Check if logged-in user's email matches order email.
            $user = wp_get_current_user();
            if ( $user && hash_equals( strtolower( $user->user_email ), strtolower( $order->get_billing_email() ) ) ) {
                return array(
                    'status'   => 'verified',
                    'method'   => 'logged_in_email_match',
                    'required' => array(),
                    'message'  => '',
                );
            }
        }

        // S12: For anonymous/guest access, BOTH email AND zip verification are required.
        // This prevents email enumeration attacks by requiring a second factor.
        $email = ! empty( $verify['email'] ) ? sanitize_email( $verify['email'] ) : '';
        $zip = '';
        if ( ! empty( $verify['zip'] ) ) {
            $sanitized_zip = preg_replace( '/[^a-zA-Z0-9]/', '', $verify['zip'] );
            // preg_replace returns null on error.
            $zip = ( null !== $sanitized_zip ) ? $sanitized_zip : '';
        }

        // Check if both required verification fields are provided.
        if ( empty( $email ) || empty( $zip ) ) {
            return array(
                'status'   => 'needs_verification',
                'method'   => 'none',
                'required' => array( 'email', 'zip' ),
                'message'  => __( 'Please provide verify.email (the email address used for this order) AND verify.zip (the billing zip/postal code) to access order details.', 'glimmr-ai' ),
            );
        }

        // Record attempt BEFORE verification to prevent enumeration attacks.
        $this->record_verification_attempt();

        // Perform timing-safe email comparison.
        $order_email = $order->get_billing_email();
        $email_matches = hash_equals(
            strtolower( $order_email ),
            strtolower( $email )
        );

        // Perform timing-safe zip comparison.
        $order_postcode = $order->get_billing_postcode();
        $sanitized_order_zip = preg_replace( '/[^a-zA-Z0-9]/', '', $order_postcode );
        // preg_replace returns null on error.
        $order_zip = ( null !== $sanitized_order_zip ) ? $sanitized_order_zip : '';
        $zip_matches = hash_equals( strtolower( $order_zip ), strtolower( $zip ) );

        // S12: Consistent error messages - don't reveal which field failed to prevent enumeration.
        if ( ! $email_matches || ! $zip_matches ) {
            // Audit log: Failed guest order verification attempt.
            if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
                Glimmr_AI_Audit_Log::log_auth_failure( 'order_verification', array(
                    'order_id' => $order->get_id(),
                ) );
            }

            return array(
                'status'   => 'verification_failed',
                'method'   => 'email_zip',
                'required' => array( 'email', 'zip' ),
                'message'  => __( 'Order not found or verification failed. Please check the order number, email, and zip code and try again.', 'glimmr-ai' ),
            );
        }

        return array(
            'status'   => 'verified',
            'method'   => 'email_zip_match',
            'required' => array(),
            'message'  => '',
        );
    }

    /**
     * Check rate limit for email verification attempts.
     *
     * Prevents brute force email enumeration.
     *
     * @return bool True if within rate limit, false if exceeded.
     */
    private function check_email_verification_rate_limit() {
        $ip_hash = $this->get_client_ip_hash();
        $transient_key = 'glimmr_order_verify_' . $ip_hash;

        $attempts = (int) get_transient( $transient_key );

        // Allow 5 attempts per 15 minutes.
        return $attempts < 5;
    }

    /**
     * Record an email verification attempt.
     *
     * All attempts are counted (success or failure) to prevent enumeration attacks.
     */
    private function record_verification_attempt() {
        $ip_hash = $this->get_client_ip_hash();
        $transient_key = 'glimmr_order_verify_' . $ip_hash;

        $attempts = (int) get_transient( $transient_key );
        set_transient( $transient_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
    }

    /**
     * Get hashed client IP for rate limiting.
     *
     * @return string Hashed IP.
     */
    private function get_client_ip_hash() {
        $ip = '';

        // Priority: Sucuri > X-Forwarded-For > REMOTE_ADDR.
        if ( ! empty( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip = strpos( $forwarded, ',' ) !== false ? trim( explode( ',', $forwarded )[0] ) : $forwarded;
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return hash( 'sha256', $ip . wp_salt( 'auth' ) );
    }

    /**
     * Get detailed order information.
     *
     * @param WC_Order $order Order object.
     * @return array Order details.
     */
    private function get_order_details( $order ) {
        $data = array(
            'order_number'  => $order->get_order_number(),
            'status'        => $order->get_status(),
            'status_label'  => wc_get_order_status_name( $order->get_status() ),
            'date_created'  => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : null,
            'total'         => $this->format_price( $order->get_total() ),
        );

        // Add status description.
        $data['status_description'] = $this->get_status_description( $order->get_status() );

        // Add items summary with images and variation details.
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $item_data = array(
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total'    => $this->format_price( $item->get_total() ),
            );

            // Add product image.
            $product = $item->get_product();
            if ( $product ) {
                $image_id = $product->get_image_id();
                if ( $image_id ) {
                    $image_url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
                    if ( $image_url ) {
                        $item_data['image'] = $image_url;
                    }
                }
            }

            // Add variation details (e.g., "Size: L, Color: Navy").
            if ( $item instanceof WC_Order_Item_Product ) {
                $variation_info = array();
                $meta_data = $item->get_meta_data();
                foreach ( $meta_data as $meta ) {
                    $key = $meta->key;
                    // Skip internal/hidden meta keys.
                    if ( str_starts_with( $key, '_' ) ) {
                        continue;
                    }
                    $label = wc_attribute_label( $key );
                    $variation_info[] = $label . ': ' . $meta->value;
                }
                if ( ! empty( $variation_info ) ) {
                    $item_data['variation'] = implode( ', ', $variation_info );
                }
            }

            $items[] = $item_data;
        }
        $data['items'] = $items;

        // Add tracking information if available.
        $tracking = $this->get_tracking_info( $order );
        if ( $tracking ) {
            $data['tracking'] = $tracking;
        }

        // S11: Address privacy - only expose city/state/country, not full street address.
        if ( $order->has_shipping_address() ) {
            $city = $order->get_shipping_city();
            $state = $order->get_shipping_state();
            $country = $order->get_shipping_country();

            // Build masked address showing only city, state, country.
            $address_parts = array_filter( array( $city, $state, $country ) );
            $data['shipping_address'] = implode( ', ', $address_parts );
            $data['shipping_location'] = array(
                'city'    => $city,
                'state'   => $state,
                'country' => $country,
            );
        }

        // Add estimated delivery if available.
        $estimated = $this->get_estimated_delivery( $order );
        if ( $estimated ) {
            $data['estimated_delivery'] = $estimated;
        }

        // Add payment method.
        $data['payment_method'] = $order->get_payment_method_title();

        // Add customer-facing order notes only.
        // S12: Rely ONLY on WooCommerce's get_customer_order_notes() which filters by is_customer_note flag.
        // Do NOT use keyword-based filtering which gives false confidence and can be bypassed.
        $notes = $order->get_customer_order_notes();
        if ( ! empty( $notes ) ) {
            $data['updates'] = array();
            foreach ( array_slice( $notes, 0, 5 ) as $note ) {
                // Sanitize note content.
                $message = wp_strip_all_tags( $note->comment_content );
                // strtotime can return false on invalid date string.
                $note_timestamp = strtotime( $note->comment_date );
                $note_date = ( false !== $note_timestamp ) ? date_i18n( get_option( 'date_format' ), $note_timestamp ) : '';
                $data['updates'][] = array(
                    'date'    => $note_date,
                    'message' => $message,
                );
            }
        }

        return $data;
    }

    /**
     * Get human-readable status description.
     *
     * @param string $status Order status.
     * @return string Status description.
     */
    private function get_status_description( $status ) {
        $descriptions = array(
            'pending'        => __( 'Your order has been received and is awaiting payment.', 'glimmr-ai' ),
            'processing'     => __( 'Your payment has been received and your order is being prepared.', 'glimmr-ai' ),
            'on-hold'        => __( 'Your order is on hold pending additional information or payment verification.', 'glimmr-ai' ),
            'completed'      => __( 'Your order has been completed and delivered.', 'glimmr-ai' ),
            'cancelled'      => __( 'This order has been cancelled.', 'glimmr-ai' ),
            'refunded'       => __( 'This order has been refunded.', 'glimmr-ai' ),
            'failed'         => __( 'Payment for this order failed. Please try again or contact support.', 'glimmr-ai' ),
            'shipped'        => __( 'Your order has been shipped and is on its way.', 'glimmr-ai' ),
            'out-for-delivery' => __( 'Your order is out for delivery today.', 'glimmr-ai' ),
        );

        return $descriptions[ $status ] ?? __( 'Your order is being processed.', 'glimmr-ai' );
    }

    /**
     * Get tracking information from order.
     *
     * Checks multiple meta key sources for tracking data:
     * 1. Custom meta keys (configurable via filter or settings)
     * 2. WooCommerce Shipment Tracking plugin format
     * 3. Common tracking meta keys used by various plugins
     *
     * @param WC_Order $order Order object.
     * @return array|null Tracking info or null.
     */
    private function get_tracking_info( $order ) {
        // Allow complete override via filter for custom implementations.
        // Return array with 'carrier', 'tracking_number', 'tracking_url' keys, or null.
        $custom_tracking = apply_filters( 'glimmr_ai_order_tracking_info', null, $order );
        if ( is_array( $custom_tracking ) && ! empty( $custom_tracking['tracking_number'] ) ) {
            return $custom_tracking;
        }

        // Get configurable meta keys from settings (with defaults).
        $settings = Glimmr_AI::get_instance()->get_settings();
        $custom_keys = $this->get_tracking_meta_keys( $settings );

        // Get default carrier/URL settings for when meta fields aren't available.
        $default_carrier = $settings ? $settings->get( 'tracking_default_carrier', '' ) : '';
        $default_url_template = $settings ? $settings->get( 'tracking_default_url_template', '' ) : '';

        // Check custom/configured keys first.
        foreach ( $custom_keys['tracking_number'] as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) && is_string( $value ) ) {
                // Found tracking number, now get URL and carrier.
                $tracking_url = $this->get_first_meta( $order, $custom_keys['tracking_url'] );
                $carrier = $this->get_first_meta( $order, $custom_keys['carrier'] );

                // Fall back to default carrier if not found in meta.
                if ( empty( $carrier ) && ! empty( $default_carrier ) ) {
                    $carrier = $default_carrier;
                }

                // Try to generate tracking URL if not found.
                if ( empty( $tracking_url ) ) {
                    // First try custom URL template if configured.
                    if ( ! empty( $default_url_template ) ) {
                        $tracking_url = str_replace( '{tracking_number}', rawurlencode( $value ), $default_url_template );
                    } elseif ( ! empty( $carrier ) ) {
                        // Fall back to auto-generated URL based on carrier.
                        $tracking_url = $this->generate_tracking_url( $carrier, $value );
                    }
                }

                return array(
                    'carrier'         => $carrier ?: '',
                    'tracking_number' => $value,
                    'tracking_url'    => $tracking_url ?: '',
                );
            }
        }

        // Check WooCommerce Shipment Tracking plugin format (array structure).
        $wc_tracking = $order->get_meta( '_wc_shipment_tracking_items' );
        if ( is_array( $wc_tracking ) && isset( $wc_tracking[0] ) ) {
            $tracking = $wc_tracking[0];
            $tracking_url = $tracking['custom_tracking_link'] ?? '';

            // Auto-generate URL if custom link not set.
            if ( empty( $tracking_url ) && ! empty( $tracking['tracking_provider'] ) && ! empty( $tracking['tracking_number'] ) ) {
                $tracking_url = $this->generate_tracking_url( $tracking['tracking_provider'], $tracking['tracking_number'] );
            }

            return array(
                'carrier'         => $tracking['tracking_provider'] ?? $tracking['custom_tracking_provider'] ?? '',
                'tracking_number' => $tracking['tracking_number'] ?? '',
                'tracking_url'    => $tracking_url,
            );
        }

        return null;
    }

    /**
     * Get tracking meta keys configuration.
     *
     * Returns arrays of meta keys to check for tracking number, URL, and carrier.
     * Keys are checked in order; first non-empty value wins.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @return array Arrays of meta keys keyed by 'tracking_number', 'tracking_url', 'carrier'.
     */
    private function get_tracking_meta_keys( $settings ) {
        // Default meta keys used by common plugins.
        $defaults = array(
            'tracking_number' => array(
                '_tracking_number',
                'tracking_number',
                '_aftership_tracking_number',
            ),
            'tracking_url' => array(
                '_tracking_url',
                'tracking_url',
                '_tracking_link',
            ),
            'carrier' => array(
                '_tracking_provider',
                '_shipping_carrier',
                'tracking_carrier',
            ),
        );

        // Get custom keys from settings (comma-separated strings).
        $custom_number_keys = $settings ? $settings->get( 'tracking_meta_number', '' ) : '';
        $custom_url_keys = $settings ? $settings->get( 'tracking_meta_url', '' ) : '';
        $custom_carrier_keys = $settings ? $settings->get( 'tracking_meta_carrier', '' ) : '';

        // Parse custom keys and prepend to defaults (custom keys take priority).
        $result = $defaults;

        if ( ! empty( $custom_number_keys ) ) {
            $custom = array_filter( array_map( 'trim', explode( ',', $custom_number_keys ) ) );
            $result['tracking_number'] = array_merge( $custom, $defaults['tracking_number'] );
        }

        if ( ! empty( $custom_url_keys ) ) {
            $custom = array_filter( array_map( 'trim', explode( ',', $custom_url_keys ) ) );
            $result['tracking_url'] = array_merge( $custom, $defaults['tracking_url'] );
        }

        if ( ! empty( $custom_carrier_keys ) ) {
            $custom = array_filter( array_map( 'trim', explode( ',', $custom_carrier_keys ) ) );
            $result['carrier'] = array_merge( $custom, $defaults['carrier'] );
        }

        // Allow complete override via filter.
        return apply_filters( 'glimmr_ai_tracking_meta_keys', $result );
    }

    /**
     * Get first non-empty meta value from a list of keys.
     *
     * @param WC_Order $order Order object.
     * @param array    $keys  Meta keys to check.
     * @return string First non-empty value or empty string.
     */
    private function get_first_meta( $order, $keys ) {
        foreach ( $keys as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) && is_string( $value ) ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Generate tracking URL for known carriers.
     *
     * @param string $carrier         Carrier name or slug.
     * @param string $tracking_number Tracking number.
     * @return string Tracking URL or empty string.
     */
    private function generate_tracking_url( $carrier, $tracking_number ) {
        $carrier_lower = strtolower( trim( $carrier ) );
        $tracking_encoded = rawurlencode( $tracking_number );

        // Map carrier names/slugs to tracking URL templates.
        $carrier_urls = array(
            // USPS
            'usps'                    => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tracking_encoded,
            'united-states-postal-service' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $tracking_encoded,

            // UPS
            'ups'                     => 'https://www.ups.com/track?tracknum=' . $tracking_encoded,
            'united-parcel-service'   => 'https://www.ups.com/track?tracknum=' . $tracking_encoded,

            // FedEx
            'fedex'                   => 'https://www.fedex.com/fedextrack/?trknbr=' . $tracking_encoded,
            'federal-express'         => 'https://www.fedex.com/fedextrack/?trknbr=' . $tracking_encoded,

            // DHL
            'dhl'                     => 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tracking_encoded,
            'dhl-express'             => 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tracking_encoded,
            'dhl-ecommerce'           => 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tracking_encoded,

            // Amazon
            'amazon'                  => 'https://www.amazon.com/progress-tracker/package/?itemId=' . $tracking_encoded,
            'amazon-logistics'        => 'https://www.amazon.com/progress-tracker/package/?itemId=' . $tracking_encoded,

            // OnTrac
            'ontrac'                  => 'https://www.ontrac.com/trackingdetail.asp?tracking=' . $tracking_encoded,

            // LaserShip
            'lasership'               => 'https://www.lasership.com/track/' . $tracking_encoded,

            // Pitney Bowes
            'pitney-bowes'            => 'https://tracking.pb.com/unified-tracking/?trackingNumber=' . $tracking_encoded,
            'pitneybowes'             => 'https://tracking.pb.com/unified-tracking/?trackingNumber=' . $tracking_encoded,
        );

        // Allow extending carrier URLs via filter.
        $carrier_urls = apply_filters( 'glimmr_ai_carrier_tracking_urls', $carrier_urls, $tracking_number );

        // Check for exact match first.
        if ( isset( $carrier_urls[ $carrier_lower ] ) ) {
            return $carrier_urls[ $carrier_lower ];
        }

        // Check for partial match (e.g., "UPS Ground" contains "ups").
        foreach ( $carrier_urls as $key => $url ) {
            if ( strpos( $carrier_lower, $key ) !== false ) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Get estimated delivery date.
     *
     * @param WC_Order $order Order object.
     * @return string|null Estimated delivery or null.
     */
    private function get_estimated_delivery( $order ) {
        // Check common estimated delivery meta keys.
        $delivery_keys = array(
            '_estimated_delivery',
            '_delivery_date',
            '_wc_estimated_delivery_date',
            'estimated_delivery_date',
        );

        foreach ( $delivery_keys as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) ) {
                // Try to format as date.
                $timestamp = strtotime( $value );
                if ( $timestamp ) {
                    return date_i18n( get_option( 'date_format' ), $timestamp );
                }
                return $value;
            }
        }

        // Estimate based on status and shipping method.
        if ( in_array( $order->get_status(), array( 'processing', 'shipped' ), true ) ) {
            $shipping_methods = $order->get_shipping_methods();
            foreach ( $shipping_methods as $method ) {
                $method_title = strtolower( $method->get_method_title() );
                if ( strpos( $method_title, 'express' ) !== false || strpos( $method_title, 'overnight' ) !== false ) {
                    return __( '1-2 business days', 'glimmr-ai' );
                } elseif ( strpos( $method_title, 'priority' ) !== false ) {
                    return __( '2-3 business days', 'glimmr-ai' );
                }
            }
            return __( '3-7 business days', 'glimmr-ai' );
        }

        return null;
    }
}
