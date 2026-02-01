<?php
/**
 * Analytics Tracking
 *
 * Tracks events, conversions, and usage metrics for the AI assistant.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Analytics
 *
 * Handles:
 * - Event tracking
 * - Conversion attribution
 * - Usage metrics
 * - Reporting queries
 */
class Glimmr_AI_Analytics {

    /**
     * Event types.
     */
    const EVENT_CONVERSATION_START   = 'conversation_start';
    const EVENT_MESSAGE_SENT         = 'message_sent';
    const EVENT_MESSAGE_RECEIVED     = 'message_received';
    const EVENT_TOOL_CALLED          = 'tool_called';
    const EVENT_PRODUCT_SHOWN        = 'product_shown';
    const EVENT_PRODUCT_CLICKED      = 'product_clicked';
    const EVENT_ADD_TO_CART          = 'add_to_cart';
    const EVENT_CHECKOUT_STARTED     = 'checkout_started';
    const EVENT_ORDER_COMPLETED      = 'order_completed';
    const EVENT_WIDGET_OPENED        = 'widget_opened';
    const EVENT_WIDGET_CLOSED        = 'widget_closed';
    const EVENT_MESSAGE_FLAGGED      = 'message_flagged';
    const EVENT_GDPR_CONSENT         = 'gdpr_consent';
    const EVENT_ERROR                = 'error';

    // Proactive engagement events.
    const EVENT_PROACTIVE_TRIGGER           = 'proactive_trigger';
    const EVENT_ABANDONED_CART_TRIGGER      = 'abandoned_cart_trigger';
    const EVENT_ABANDONED_CART_RECOVERED    = 'abandoned_cart_recovered';
    const EVENT_IDLE_ENGAGEMENT_TRIGGER     = 'idle_engagement_trigger';
    const EVENT_IDLE_ENGAGEMENT_CONVERTED   = 'idle_engagement_converted';

    /**
     * Track an event.
     *
     * @param string      $event_type      Event type constant.
     * @param array       $properties      Event properties.
     * @param string|null $conversation_id Associated conversation ID.
     * @return int|false Event ID or false on failure.
     */
    public static function track( $event_type, $properties = array(), $conversation_id = null ) {
        // Add common properties.
        $properties = array_merge(
            array(
                'timestamp'  => current_time( 'mysql' ),
                'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                'url'        => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
            ),
            $properties
        );

        return Glimmr_AI_Database::insert_analytics_event( $event_type, $properties, $conversation_id );
    }

    /**
     * Track conversation start.
     *
     * @param string $conversation_id Conversation ID.
     * @param array  $context         Context data (page, user info, etc.).
     * @return int|false
     */
    public static function track_conversation_start( $conversation_id, $context = array() ) {
        return self::track(
            self::EVENT_CONVERSATION_START,
            array(
                'context'   => $context,
                'is_guest'  => ! is_user_logged_in(),
                'page_type' => self::get_page_type(),
            ),
            $conversation_id
        );
    }

    /**
     * Track message sent by user.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $message_length  Message character count.
     * @return int|false
     */
    public static function track_message_sent( $conversation_id, $message_length = 0 ) {
        return self::track(
            self::EVENT_MESSAGE_SENT,
            array(
                'message_length' => $message_length,
            ),
            $conversation_id
        );
    }

    /**
     * Track message received from AI.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $tokens_used     Tokens used for this response.
     * @param float  $response_time   Response time in seconds.
     * @return int|false
     */
    public static function track_message_received( $conversation_id, $tokens_used = 0, $response_time = 0 ) {
        return self::track(
            self::EVENT_MESSAGE_RECEIVED,
            array(
                'tokens_used'   => $tokens_used,
                'response_time' => round( $response_time, 3 ),
            ),
            $conversation_id
        );
    }

    /**
     * Track tool call.
     *
     * @param string $conversation_id Conversation ID.
     * @param string $tool_name       Tool name.
     * @param bool   $success         Whether the tool call succeeded.
     * @param array  $params          Tool parameters (sanitized).
     * @return int|false
     */
    public static function track_tool_call( $conversation_id, $tool_name, $success = true, $params = array() ) {
        return self::track(
            self::EVENT_TOOL_CALLED,
            array(
                'tool_name' => $tool_name,
                'success'   => $success,
                'params'    => $params,
            ),
            $conversation_id
        );
    }

    /**
     * Track product shown in chat.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $product_id      Product ID.
     * @return int|false
     */
    public static function track_product_shown( $conversation_id, $product_id ) {
        return self::track(
            self::EVENT_PRODUCT_SHOWN,
            array(
                'product_id' => $product_id,
            ),
            $conversation_id
        );
    }

    /**
     * Track add to cart from chat.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $product_id      Product ID.
     * @param int    $quantity        Quantity added.
     * @return int|false
     */
    public static function track_add_to_cart( $conversation_id, $product_id, $quantity = 1 ) {
        return self::track(
            self::EVENT_ADD_TO_CART,
            array(
                'product_id' => $product_id,
                'quantity'   => $quantity,
            ),
            $conversation_id
        );
    }

    /**
     * Track order completion with attribution.
     *
     * @param int    $order_id        WooCommerce order ID.
     * @param string $conversation_id Last conversation ID.
     * @param float  $order_total     Order total.
     * @return int|false
     */
    public static function track_conversion( $order_id, $conversation_id, $order_total = 0 ) {
        return self::track(
            self::EVENT_ORDER_COMPLETED,
            array(
                'order_id'    => $order_id,
                'order_total' => $order_total,
                'attributed'  => true,
            ),
            $conversation_id
        );
    }

    /**
     * Track error.
     *
     * @param string      $error_type      Error type/code.
     * @param string      $error_message   Error message.
     * @param string|null $conversation_id Conversation ID if applicable.
     * @return int|false
     */
    public static function track_error( $error_type, $error_message, $conversation_id = null ) {
        return self::track(
            self::EVENT_ERROR,
            array(
                'error_type'    => $error_type,
                'error_message' => $error_message,
            ),
            $conversation_id
        );
    }

    // =========================================================================
    // Proactive Engagement Tracking
    // =========================================================================

    /**
     * Track proactive trigger event.
     *
     * @param string $trigger_type    Type of trigger (time, exit, scroll, abandonedCart, idleEngagement).
     * @param string $page_type       Page type where trigger fired.
     * @param array  $additional_data Additional event data.
     * @return int|false
     */
    public static function track_proactive_trigger( $trigger_type, $page_type = '', $additional_data = array() ) {
        $event_type = self::EVENT_PROACTIVE_TRIGGER;

        // Use specific event types for cart and idle triggers.
        if ( 'abandonedCart' === $trigger_type ) {
            $event_type = self::EVENT_ABANDONED_CART_TRIGGER;
        } elseif ( 'idleEngagement' === $trigger_type ) {
            $event_type = self::EVENT_IDLE_ENGAGEMENT_TRIGGER;
        }

        return self::track(
            $event_type,
            array_merge(
                array(
                    'trigger_type' => $trigger_type,
                    'page_type'    => $page_type,
                ),
                $additional_data
            )
        );
    }

    /**
     * Track abandoned cart recovery (order placed after trigger).
     *
     * @param int    $order_id        WooCommerce order ID.
     * @param string $conversation_id Conversation ID where trigger fired.
     * @param float  $order_total     Order total.
     * @return int|false
     */
    public static function track_abandoned_cart_recovered( $order_id, $conversation_id, $order_total = 0 ) {
        return self::track(
            self::EVENT_ABANDONED_CART_RECOVERED,
            array(
                'order_id'    => $order_id,
                'order_total' => $order_total,
            ),
            $conversation_id
        );
    }

    /**
     * Track idle engagement conversion (add to cart or order after trigger).
     *
     * @param string $conversion_type Type of conversion (add_to_cart, order_completed).
     * @param string $conversation_id Conversation ID where trigger fired.
     * @param array  $additional_data Additional event data.
     * @return int|false
     */
    public static function track_idle_engagement_converted( $conversion_type, $conversation_id, $additional_data = array() ) {
        return self::track(
            self::EVENT_IDLE_ENGAGEMENT_CONVERTED,
            array_merge(
                array(
                    'conversion_type' => $conversion_type,
                ),
                $additional_data
            ),
            $conversation_id
        );
    }

    // =========================================================================
    // Reporting / Aggregation
    // =========================================================================

    /**
     * Get analytics summary for a period.
     *
     * @param string $period Period (today, week, month, year).
     * @return array Summary data.
     */
    public static function get_summary( $period = 'week' ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $date_filter = self::get_date_filter( $period );

        // Helper function to safely get count and log errors.
        $safe_get_var = function ( $query ) use ( $wpdb ) {
            $result = $wpdb->get_var( $query );
            if ( ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::warning(
                    'Analytics query failed',
                    array( 'db_error' => $wpdb->last_error ),
                    'analytics'
                );
                return 0;
            }
            return (int) ( $result ?? 0 );
        };

        // Conversation count.
        $conversations = $safe_get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT conversation_id) FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_CONVERSATION_START,
                $date_filter
            )
        );

        // Message count.
        $messages = $safe_get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_MESSAGE_SENT,
                $date_filter
            )
        );

        // Tool calls.
        $tool_calls = $safe_get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_TOOL_CALLED,
                $date_filter
            )
        );

        // Conversions.
        $conversions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COUNT(*) as count, SUM(JSON_EXTRACT(properties, '$.order_total')) as revenue
                 FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_ORDER_COMPLETED,
                $date_filter
            ),
            ARRAY_A
        );

        // Handle conversion query errors or empty results.
        if ( ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::warning(
                'Analytics conversions query failed',
                array( 'db_error' => $wpdb->last_error ),
                'analytics'
            );
            $conversions = array( array( 'count' => 0, 'revenue' => 0 ) );
        } elseif ( empty( $conversions ) || ! is_array( $conversions ) || ! isset( $conversions[0] ) ) {
            $conversions = array( array( 'count' => 0, 'revenue' => 0 ) );
        }

        $conversion_count = (int) ( $conversions[0]['count'] ?? 0 );
        $conversion_revenue = (float) ( $conversions[0]['revenue'] ?? 0 );

        // Add to cart events.
        $add_to_carts = $safe_get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_ADD_TO_CART,
                $date_filter
            )
        );

        // Errors.
        $errors = $safe_get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE event_type = %s AND created_at >= %s",
                self::EVENT_ERROR,
                $date_filter
            )
        );

        // Calculate averages (with safe division).
        $avg_messages = $conversations > 0 ? round( $messages / $conversations, 1 ) : 0;
        $conversion_rate = $conversations > 0 ? round( ( $conversion_count / $conversations ) * 100, 1 ) : 0;

        return array(
            'period'              => $period,
            'conversations'       => $conversations,
            'messages'            => $messages,
            'avg_messages'        => $avg_messages,
            'tool_calls'          => $tool_calls,
            'add_to_carts'        => $add_to_carts,
            'conversions'         => $conversion_count,
            'revenue'             => $conversion_revenue,
            'conversion_rate'     => $conversion_rate,
            'errors'              => $errors,
        );
    }

    /**
     * Get tool usage breakdown.
     *
     * @param string $period Period.
     * @return array Tool usage counts.
     */
    public static function get_tool_usage( $period = 'week' ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $date_filter = self::get_date_filter( $period );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tool_name')) as tool_name,
                    COUNT(*) as count,
                    SUM(CASE WHEN JSON_EXTRACT(properties, '$.success') = true THEN 1 ELSE 0 END) as success_count
                 FROM {$table}
                 WHERE event_type = %s AND created_at >= %s
                 GROUP BY tool_name
                 ORDER BY count DESC",
                self::EVENT_TOOL_CALLED,
                $date_filter
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get daily event counts for charting.
     *
     * @param string $period     Period.
     * @param string $event_type Event type to count.
     * @return array Daily counts.
     */
    public static function get_daily_counts( $period = 'week', $event_type = null ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $date_filter = self::get_date_filter( $period );

        $where = "created_at >= %s";
        $values = array( $date_filter );

        if ( $event_type ) {
            $where .= " AND event_type = %s";
            $values[] = $event_type;
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as count
                 FROM {$table}
                 WHERE {$where}
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC",
                ...$values
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get recent conversations with metrics.
     *
     * @param int $limit Number of conversations.
     * @return array Recent conversations.
     */
    public static function get_recent_conversations( $limit = 10 ) {
        global $wpdb;

        $conv_table = Glimmr_AI_Database::get_table_name( 'conversations' );
        $analytics_table = Glimmr_AI_Database::get_table_name( 'analytics' );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    c.conversation_id,
                    c.user_id,
                    c.status,
                    c.message_count,
                    c.created_at,
                    c.last_message_at,
                    (SELECT COUNT(*) FROM {$analytics_table} a
                     WHERE a.conversation_id = c.conversation_id
                     AND a.event_type = %s) as add_to_cart_count,
                    (SELECT COUNT(*) FROM {$analytics_table} a
                     WHERE a.conversation_id = c.conversation_id
                     AND a.event_type = %s) as converted
                 FROM {$conv_table} c
                 ORDER BY c.created_at DESC
                 LIMIT %d",
                self::EVENT_ADD_TO_CART,
                self::EVENT_ORDER_COMPLETED,
                $limit
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get popular questions/intents.
     *
     * @param string $period Period.
     * @param int    $limit  Number of results.
     * @return array Popular queries.
     */
    public static function get_popular_queries( $period = 'week', $limit = 10 ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $date_filter = self::get_date_filter( $period );

        // Get first messages of conversations (user intents).
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tool_name')) as intent,
                    COUNT(*) as count
                 FROM {$table}
                 WHERE event_type = %s
                 AND created_at >= %s
                 AND JSON_EXTRACT(properties, '$.tool_name') IS NOT NULL
                 GROUP BY intent
                 ORDER BY count DESC
                 LIMIT %d",
                self::EVENT_TOOL_CALLED,
                $date_filter,
                $limit
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    // =========================================================================
    // Conversion Attribution
    // =========================================================================

    /**
     * Get conversation ID for attribution from session/cookies.
     *
     * @return string|null Last conversation ID.
     */
    public static function get_attribution_conversation_id() {
        // Check if stored in session.
        if ( function_exists( 'WC' ) && WC()->session ) {
            $conv_id = WC()->session->get( 'glimmr_ai_conversation_id' );
            if ( $conv_id ) {
                return $conv_id;
            }
        }

        // Fallback to cookie.
        if ( isset( $_COOKIE['glimmr_ai_conversation'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['glimmr_ai_conversation'] ) );
        }

        return null;
    }

    /**
     * Store conversation ID for attribution.
     *
     * @param string $conversation_id Conversation ID.
     */
    public static function set_attribution_conversation_id( $conversation_id ) {
        // Store in WC session if available.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'glimmr_ai_conversation_id', $conversation_id );
        }

        // Also set cookie for 30 days with SameSite attribute.
        if ( ! headers_sent() ) {
            setcookie(
                'glimmr_ai_conversation',
                $conversation_id,
                array(
                    'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
                    'path'     => COOKIEPATH,
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly'  => true,
                    'samesite' => 'Lax',
                )
            );
        }
    }

    /**
     * Clear attribution after order.
     */
    public static function clear_attribution() {
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'glimmr_ai_conversation_id', null );
        }

        if ( ! headers_sent() ) {
            setcookie(
                'glimmr_ai_conversation',
                '',
                array(
                    'expires'  => time() - 3600,
                    'path'     => COOKIEPATH,
                    'domain'   => COOKIE_DOMAIN,
                    'secure'   => is_ssl(),
                    'httponly'  => true,
                    'samesite' => 'Lax',
                )
            );
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get SQL date filter for period.
     *
     * @param string $period Period name.
     * @return string MySQL datetime.
     */
    private static function get_date_filter( $period ) {
        switch ( $period ) {
            case 'day':
            case 'today':
                return gmdate( 'Y-m-d 00:00:00' );
            case 'week':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
            case 'month':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
            case '6months':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-6 months' ) );
            case 'year':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
            case '2years':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) );
            case '5years':
                return gmdate( 'Y-m-d H:i:s', strtotime( '-5 years' ) );
            case 'all':
                return '1970-01-01 00:00:00'; // Beginning of Unix time
            default:
                return gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
        }
    }

    /**
     * Get current page type for context.
     *
     * @return string Page type.
     */
    private static function get_page_type() {
        if ( function_exists( 'is_product' ) && is_product() ) {
            return 'product';
        }
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            return 'cart';
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            return 'checkout';
        }
        if ( function_exists( 'is_shop' ) && is_shop() ) {
            return 'shop';
        }
        if ( is_front_page() ) {
            return 'home';
        }
        if ( is_page() ) {
            return 'page';
        }
        return 'other';
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Clean up old analytics events.
     *
     * @param int $days_to_keep Days of data to retain.
     * @return int Rows deleted.
     */
    public static function cleanup( $days_to_keep = 365 ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_to_keep} days" ) );

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            )
        );
    }
}
