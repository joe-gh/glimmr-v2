<?php
/**
 * Tool Result Summarizer
 *
 * Compresses tool results for conversation history while preserving essential data.
 * This reduces context token usage when building API requests.
 *
 * Note: Original tool results are stored in the database unchanged.
 * Summarization only happens when building context for API calls.
 *
 * @package Glimmr_AI
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Summarizer
 *
 * Provides static methods to compress verbose tool outputs for context efficiency.
 */
class Glimmr_AI_Tool_Summarizer {

    /**
     * Maximum length for truncated text fields.
     *
     * @var int
     */
    const MAX_TEXT_LENGTH = 200;

    /**
     * Maximum number of products to keep in summaries.
     *
     * @var int
     */
    const MAX_PRODUCTS = 10;

    /**
     * Maximum number of reviews to keep in summaries.
     *
     * @var int
     */
    const MAX_REVIEWS = 5;

    /**
     * Maximum length for review content truncation.
     *
     * @var int
     */
    const MAX_REVIEW_LENGTH = 100;

    /**
     * Maximum number of orders to keep in summaries.
     *
     * @var int
     */
    const MAX_ORDERS = 5;

    /**
     * Maximum number of cart items to keep in summaries.
     *
     * @var int
     */
    const MAX_CART_ITEMS = 10;

    /**
     * Maximum rows to keep from SQL results.
     *
     * @var int
     */
    const MAX_SQL_ROWS = 10;

    /**
     * Main entry point - summarize any tool result.
     *
     * Routes to tool-specific summarizer based on tool name.
     *
     * @param string $tool_name   The name of the tool.
     * @param array  $tool_result The tool result data.
     * @return array Summarized tool result.
     */
    public static function summarize( $tool_name, $tool_result ) {
        if ( ! empty( $tool_result['_summarized'] ) ) {
            return $tool_result;
        }

        // Route to tool-specific summarizer.
        $method = 'summarize_' . str_replace( '-', '_', $tool_name );

        if ( method_exists( self::class, $method ) ) {
            return self::$method( $tool_result );
        }

        // Fall back to generic summarizer.
        return self::summarize_generic( $tool_result );
    }

    // =========================================================================
    // Product Tools
    // =========================================================================

    /**
     * Summarize query_products tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_query_products( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Summarize products array if present.
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            $data['products'] = self::summarize_products_array( $data['products'] );
            $data['count']    = count( $data['products'] );
        }

        // Keep mode for context.
        // Strip verbose metadata.
        unset( $data['query_metadata'] );

        return self::mark_summarized( $result );
    }

    /**
     * Summarize recommendations tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_recommendations( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Summarize products, keeping reason_for_match.
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            $data['products'] = self::summarize_products_array( $data['products'], true );
            $data['count']    = count( $data['products'] );
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize select_products tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_select_products( $result ) {
        return self::summarize_query_products( $result );
    }

    /**
     * Summarize resolve_product tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_resolve_product( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Strip image URLs, keep essential product info.
        unset( $data['image_url'], $data['images'], $data['gallery'] );

        // Truncate description.
        if ( isset( $data['description'] ) ) {
            $data['description'] = self::clean_text( $data['description'], 100 );
        }

        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Cart Tools
    // =========================================================================

    /**
     * Summarize view_cart tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_view_cart( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Strip URLs (regenerable).
        unset( $data['cart_url'], $data['checkout_url'] );

        // Summarize cart items.
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            $data['items'] = self::summarize_cart_items( $data['items'] );
            $data['item_count'] = count( $data['items'] );
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize add_to_cart tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_add_to_cart( $result ) {
        return self::summarize_view_cart( $result );
    }

    /**
     * Summarize update_cart tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_update_cart( $result ) {
        return self::summarize_view_cart( $result );
    }

    /**
     * Summarize apply_coupon tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_apply_coupon( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Strip URLs, keep coupon info.
        unset( $data['cart_url'], $data['checkout_url'] );

        // Keep code and discount amount.
        $summary = array(
            'coupon_code'   => $data['coupon_code'] ?? '',
            'discount'      => $data['discount'] ?? $data['discount_amount'] ?? '',
            'applied'       => $data['applied'] ?? true,
        );

        $result['data'] = $summary;

        return self::mark_summarized( $result );
    }

    /**
     * Summarize coupon_lookup tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_coupon_lookup( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // If coupons array, summarize each.
        if ( isset( $data['coupons'] ) && is_array( $data['coupons'] ) ) {
            $data['coupons'] = array_map( function( $coupon ) {
                return array(
                    'code'          => $coupon['code'] ?? '',
                    'discount_text' => $coupon['discount_text'] ?? $coupon['discount'] ?? '',
                    'valid'         => $coupon['valid'] ?? true,
                );
            }, array_slice( $data['coupons'], 0, 5 ) );
        }

        // Strip verbose restrictions.
        unset( $data['restrictions'], $data['usage_limits'], $data['product_restrictions'] );

        return self::mark_summarized( $result );
    }

    /**
     * Summarize checkout_link tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_checkout_link( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Keep only essential checkout info (URLs regenerable).
        $summary = array(
            'item_count' => $data['item_count'] ?? $data['items_count'] ?? 0,
            'total'      => $data['total'] ?? $data['cart_total'] ?? '',
        );

        $result['data'] = $summary;

        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Order Tools
    // =========================================================================

    /**
     * Summarize order_status tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_order_status( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Strip URLs (regenerable from order ID).
        unset( $data['view_url'], $data['tracking_url'], $data['order_url'] );

        // Keep tracking number but strip URL.
        if ( isset( $data['tracking'] ) && is_array( $data['tracking'] ) ) {
            $data['tracking'] = array(
                'number'  => $data['tracking']['number'] ?? '',
                'carrier' => $data['tracking']['carrier'] ?? '',
            );
        }

        // Summarize items list.
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            $data['items'] = array_map( function( $item ) {
                return array(
                    'name'     => $item['name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                );
            }, array_slice( $data['items'], 0, 5 ) );
            $data['item_count'] = count( $data['items'] );
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize order_history tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_order_history( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Summarize orders array - preserve key metadata the AI needs for follow-ups.
        if ( isset( $data['orders'] ) && is_array( $data['orders'] ) ) {
            $data['orders'] = array_map( function( $order ) {
                $summary = array(
                    'id'             => $order['id'] ?? $order['order_id'] ?? 0,
                    'number'         => $order['number'] ?? $order['id'] ?? 0,
                    'status'         => $order['status'] ?? '',
                    'status_label'   => $order['status_label'] ?? '',
                    'total'          => $order['total'] ?? '',
                    'date'           => $order['date'] ?? $order['date_created'] ?? '',
                    'item_count'     => $order['item_count'] ?? count( $order['items'] ?? array() ),
                );

                // Preserve item names/quantities for AI context (strip images/URLs).
                if ( ! empty( $order['items'] ) && is_array( $order['items'] ) ) {
                    $summary['items'] = array_map( function( $item ) {
                        return array(
                            'name'     => $item['name'] ?? '',
                            'quantity' => $item['quantity'] ?? 1,
                            'total'    => $item['total'] ?? '',
                        );
                    }, array_slice( $order['items'], 0, 10 ) );
                }

                // Preserve tracking and shipping info.
                if ( ! empty( $order['tracking_number'] ) ) {
                    $summary['tracking_number'] = $order['tracking_number'];
                }
                if ( ! empty( $order['shipping_method'] ) ) {
                    $summary['shipping_method'] = $order['shipping_method'];
                }
                if ( ! empty( $order['payment_method'] ) ) {
                    $summary['payment_method'] = $order['payment_method'];
                }

                return $summary;
            }, array_slice( $data['orders'], 0, self::MAX_ORDERS ) );
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize reorder tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_reorder( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Keep summary info, strip verbose item details.
        if ( isset( $data['items_added'] ) && is_array( $data['items_added'] ) ) {
            $data['items_added'] = array_map( function( $item ) {
                return array(
                    'name'     => $item['name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                );
            }, array_slice( $data['items_added'], 0, 10 ) );
            $data['added_count'] = count( $data['items_added'] );
        }

        if ( isset( $data['unavailable'] ) && is_array( $data['unavailable'] ) ) {
            $data['unavailable'] = array_map( function( $item ) {
                return $item['name'] ?? $item;
            }, array_slice( $data['unavailable'], 0, 5 ) );
        }

        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Review Tools
    // =========================================================================

    /**
     * Summarize get_reviews tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_get_reviews( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Summarize reviews array.
        if ( isset( $data['reviews'] ) && is_array( $data['reviews'] ) ) {
            $data['reviews'] = array_map( function( $review ) {
                return array(
                    'content'  => self::clean_text( $review['content'] ?? '', self::MAX_REVIEW_LENGTH ),
                    'rating'   => $review['rating'] ?? 0,
                    'verified' => $review['verified'] ?? false,
                );
            }, array_slice( $data['reviews'], 0, self::MAX_REVIEWS ) );
        }

        // Keep rating breakdown if present.
        // Strip date_relative, author details.

        return self::mark_summarized( $result );
    }

    /**
     * Summarize summarize_reviews tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_summarize_reviews( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Keep summary, strip raw reviews.
        unset( $data['raw_reviews'], $data['reviews'] );

        // Truncate summary if very long.
        if ( isset( $data['summary'] ) && strlen( $data['summary'] ) > 500 ) {
            $data['summary'] = self::clean_text( $data['summary'], 500 );
        }

        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Support & Info Tools
    // =========================================================================

    /**
     * Summarize contact_request tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_contact_request( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Keep only essential info.
        $summary = array(
            'request_id' => $data['request_id'] ?? '',
            'status'     => $data['status'] ?? 'submitted',
        );

        $result['data'] = $summary;

        return self::mark_summarized( $result );
    }

    /**
     * Summarize check_gift_card_balance tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_check_gift_card_balance( $result ) {
        // Already minimal.
        return self::mark_summarized( $result );
    }

    /**
     * Summarize track_package tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_track_package( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Strip tracking URL (regenerable from carrier + number).
        unset( $data['tracking_url'] );

        // Keep carrier and number.
        $summary = array(
            'tracking_number' => $data['tracking_number'] ?? '',
            'carrier'         => $data['carrier'] ?? '',
            'status'          => $data['status'] ?? '',
        );

        $result['data'] = $summary;

        return self::mark_summarized( $result );
    }

    /**
     * Summarize account_info tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_account_info( $result ) {
        // Already PII-masked, keep as is.
        return self::mark_summarized( $result );
    }

    /**
     * Summarize site_knowledge tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_site_knowledge( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Truncate policy text.
        if ( isset( $data['information'] ) && is_array( $data['information'] ) ) {
            if ( isset( $data['information']['policy_text'] ) ) {
                $data['information']['policy_summary'] = self::clean_text(
                    $data['information']['policy_text'],
                    self::MAX_TEXT_LENGTH
                );
                unset( $data['information']['policy_text'] );
            }

            // Truncate any long text fields.
            foreach ( $data['information'] as $key => $value ) {
                if ( is_string( $value ) && strlen( $value ) > self::MAX_TEXT_LENGTH ) {
                    $data['information'][ $key ] = self::clean_text( $value, self::MAX_TEXT_LENGTH );
                }
            }
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize navigate_to_page tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_navigate_to_page( $result ) {
        // Already minimal.
        return self::mark_summarized( $result );
    }

    /**
     * Summarize text_answer tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_text_answer( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Truncate very long text answers.
        if ( isset( $data['answer'] ) && strlen( $data['answer'] ) > 500 ) {
            $data['answer'] = self::clean_text( $data['answer'], 500 );
        }

        return self::mark_summarized( $result );
    }

    /**
     * Summarize sql_readonly tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_sql_readonly( $result ) {
        if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
            return self::mark_summarized( $result );
        }

        $data = &$result['data'];

        // Keep column names, truncate results.
        if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
            $total_rows = count( $data['results'] );
            $data['results'] = array_slice( $data['results'], 0, self::MAX_SQL_ROWS );
            $data['rows_returned'] = count( $data['results'] );
            $data['rows_truncated'] = $total_rows > self::MAX_SQL_ROWS;
        }

        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Resolver Tools (Already Minimal)
    // =========================================================================

    /**
     * Summarize resolve_variation tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_resolve_variation( $result ) {
        // Already minimal.
        return self::mark_summarized( $result );
    }

    /**
     * Summarize resolve_order tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_resolve_order( $result ) {
        // Already minimal.
        return self::mark_summarized( $result );
    }

    /**
     * Summarize resolve_cart_item tool result.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_resolve_cart_item( $result ) {
        // Already minimal.
        return self::mark_summarized( $result );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Generic summarizer for unknown tools.
     *
     * Removes common verbose fields.
     *
     * @param array $result The tool result.
     * @return array Summarized result.
     */
    private static function summarize_generic( $result ) {
        // Fields to always strip.
        $strip_fields = array(
            'image_url',
            'image',
            'images',
            'gallery',
            'cart_url',
            'checkout_url',
            'view_url',
            'tracking_url',
            'description',
            'long_description',
            'short_description',
        );

        // Strip from top level.
        foreach ( $strip_fields as $field ) {
            unset( $result[ $field ] );
        }

        // Strip from data array if present.
        if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
            foreach ( $strip_fields as $field ) {
                unset( $result['data'][ $field ] );
            }
        }

        return self::mark_summarized( $result );
    }

    /**
     * Strip HTML tags and normalize whitespace, with truncation.
     *
     * @param string $text       The text to clean.
     * @param int    $max_length Maximum length (default: MAX_TEXT_LENGTH).
     * @return string Cleaned and truncated text.
     */
    private static function clean_text( $text, $max_length = self::MAX_TEXT_LENGTH ) {
        // Strip HTML tags.
        $text = wp_strip_all_tags( $text );

        // Normalize whitespace.
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( $text );

        // Truncate if needed.
        if ( strlen( $text ) > $max_length ) {
            $text = substr( $text, 0, $max_length ) . '...';
        }

        return $text;
    }

    /**
     * Summarize a products array - keep essential fields only.
     *
     * @param array $products         Array of product data.
     * @param bool  $keep_match_reason Whether to keep reason_for_match field.
     * @return array Summarized products array.
     */
    private static function summarize_products_array( $products, $keep_match_reason = false ) {
        $summarized = array();

        foreach ( array_slice( $products, 0, self::MAX_PRODUCTS ) as $product ) {
            $summary = array(
                'id'       => $product['id'] ?? 0,
                'name'     => $product['name'] ?? '',
                'price'    => $product['price_display'] ?? $product['price'] ?? '',
                'in_stock' => $product['in_stock'] ?? $product['stock_status'] === 'instock',
            );

            // Keep match reason for recommendations.
            if ( $keep_match_reason && isset( $product['reason_for_match'] ) ) {
                $summary['reason_for_match'] = self::clean_text( $product['reason_for_match'], 100 );
            }

            $summarized[] = $summary;
        }

        return $summarized;
    }

    /**
     * Summarize cart items array.
     *
     * @param array $items Cart items array.
     * @return array Summarized cart items.
     */
    private static function summarize_cart_items( $items ) {
        $summarized = array();

        foreach ( array_slice( $items, 0, self::MAX_CART_ITEMS ) as $item ) {
            $summarized[] = array(
                'product_id' => $item['product_id'] ?? 0,
                'name'       => $item['name'] ?? '',
                'quantity'   => $item['quantity'] ?? 1,
                'line_total' => $item['line_total'] ?? $item['total'] ?? '',
            );
        }

        return $summarized;
    }

    /**
     * Mark a result as summarized.
     *
     * @param array $result The result to mark.
     * @return array Result with _summarized flag.
     */
    private static function mark_summarized( $result ) {
        $result['_summarized'] = true;
        return $result;
    }

    /**
     * Get the tool name from a tool call ID by searching messages.
     *
     * @param string $tool_call_id The tool call ID to find.
     * @param array  $messages     Array of conversation messages.
     * @return string The tool name or empty string if not found.
     */
    public static function get_tool_name_for_call_id( $tool_call_id, $messages ) {
        if ( empty( $tool_call_id ) || empty( $messages ) ) {
            return '';
        }

        // Search backwards through messages for assistant message with matching tool call.
        foreach ( array_reverse( $messages ) as $msg ) {
            if ( 'assistant' !== ( $msg['role'] ?? '' ) ) {
                continue;
            }

            $tool_calls = $msg['tool_calls'] ?? array();
            if ( ! is_array( $tool_calls ) ) {
                continue;
            }

            foreach ( $tool_calls as $call ) {
                $call_id = $call['call_id'] ?? $call['id'] ?? '';
                if ( $call_id === $tool_call_id ) {
                    return $call['name'] ?? $call['function']['name'] ?? '';
                }
            }
        }

        return '';
    }
}
