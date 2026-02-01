<?php
/**
 * Audit Log
 *
 * Tracks administrative access to customer data for security compliance.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Audit_Log
 *
 * Logs admin access to conversations and customer data.
 */
class Glimmr_AI_Audit_Log {

    /**
     * Log an admin action.
     *
     * @param string $action      Action type (e.g., 'view_conversation', 'view_history', 'access_analytics').
     * @param array  $context     Additional context data.
     * @param int    $admin_id    Admin user ID (default: current user).
     * @return bool Success.
     */
    public static function log( $action, $context = array(), $admin_id = null ) {
        if ( null === $admin_id ) {
            $admin_id = get_current_user_id();
        }

        // Only log for admin users.
        if ( ! $admin_id || ! user_can( $admin_id, 'manage_options' ) ) {
            return false;
        }

        $log_entry = array(
            'action'       => sanitize_key( $action ),
            'admin_id'     => $admin_id,
            'admin_login'  => self::get_user_login( $admin_id ),
            'context'      => $context,
            'ip_hash'      => self::get_ip_hash(),
            'user_agent'   => self::get_user_agent_hash(),
            'timestamp'    => current_time( 'mysql' ),
            'site_id'      => get_current_blog_id(),
        );

        // Store in analytics table with specific event type.
        return Glimmr_AI_Database::insert_analytics_event(
            'admin_audit',
            $log_entry,
            null // No conversation ID for general audits.
        );
    }

    /**
     * Log admin viewing a conversation.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $admin_id        Admin user ID.
     * @return bool Success.
     */
    public static function log_conversation_view( $conversation_id, $admin_id = null ) {
        return self::log(
            'view_conversation',
            array(
                'conversation_id' => $conversation_id,
            ),
            $admin_id
        );
    }

    /**
     * Log admin viewing conversation history.
     *
     * @param string $conversation_id Conversation ID.
     * @param int    $admin_id        Admin user ID.
     * @return bool Success.
     */
    public static function log_history_view( $conversation_id, $admin_id = null ) {
        return self::log(
            'view_conversation_history',
            array(
                'conversation_id' => $conversation_id,
            ),
            $admin_id
        );
    }

    /**
     * Log admin accessing analytics.
     *
     * @param string $period  Analytics period.
     * @param int    $site_id Site ID (for multisite).
     * @param int    $admin_id Admin user ID.
     * @return bool Success.
     */
    public static function log_analytics_access( $period, $site_id = null, $admin_id = null ) {
        return self::log(
            'access_analytics',
            array(
                'period'  => $period,
                'site_id' => $site_id,
            ),
            $admin_id
        );
    }

    /**
     * Log admin accessing conversation list.
     *
     * @param array $filters Applied filters.
     * @param int   $admin_id Admin user ID.
     * @return bool Success.
     */
    public static function log_conversations_list( $filters = array(), $admin_id = null ) {
        return self::log(
            'list_conversations',
            array(
                'filters' => $filters,
            ),
            $admin_id
        );
    }

    /**
     * Get user login by ID.
     *
     * @param int $user_id User ID.
     * @return string User login.
     */
    private static function get_user_login( $user_id ) {
        $user = get_userdata( $user_id );
        return $user ? $user->user_login : '';
    }

    /**
     * Get hashed client IP.
     *
     * @return string Hashed IP.
     */
    private static function get_ip_hash() {
        $ip = '';

        if ( ! empty( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip = strpos( $forwarded, ',' ) !== false ? trim( explode( ',', $forwarded )[0] ) : $forwarded;
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return hash( 'sha256', $ip . wp_salt( 'auth' ) );
    }

    /**
     * Get hashed user agent.
     *
     * @return string Hashed user agent.
     */
    private static function get_user_agent_hash() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        return hash( 'sha256', $ua . wp_salt( 'auth' ) );
    }

    /**
     * Get audit log entries for a specific admin.
     *
     * @param int $admin_id Admin user ID.
     * @param int $limit    Number of entries.
     * @return array Audit entries.
     */
    public static function get_admin_activity( $admin_id, $limit = 50 ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $site_id = get_current_blog_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT properties, created_at
                 FROM {$table}
                 WHERE site_id = %d
                 AND event_type = 'admin_audit'
                 AND user_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d",
                $site_id,
                $admin_id,
                $limit
            )
        );
    }

    /**
     * Get recent audit log entries.
     *
     * @param int $limit Number of entries.
     * @return array Audit entries.
     */
    public static function get_recent_activity( $limit = 100 ) {
        global $wpdb;

        $table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $site_id = get_current_blog_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT properties, created_at
                 FROM {$table}
                 WHERE site_id = %d
                 AND event_type = 'admin_audit'
                 ORDER BY created_at DESC
                 LIMIT %d",
                $site_id,
                $limit
            )
        );
    }
}
