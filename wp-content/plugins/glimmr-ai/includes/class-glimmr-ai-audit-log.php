<?php
/**
 * Audit Log
 *
 * SOC 2 compliant audit logging for administrative access, configuration changes,
 * security events, and system operations. Writes to a dedicated audit_log table
 * with structured categories for compliance reporting.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 * @updated 1.11.0 Dedicated audit_log table, settings change tracking, security event logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Audit_Log
 *
 * Logs admin access to conversations, configuration changes, security events,
 * and system operations for SOC 2 compliance.
 *
 * Action categories:
 * - config:       Settings changes, tool enable/disable, prompt modifications
 * - data_access:  Viewing conversations, analytics, exporting data
 * - security:     Rate limit violations, auth failures, license events
 * - system:       Cron tasks, migrations, cleanup operations
 */
class Glimmr_AI_Audit_Log {

    // =========================================================================
    // Core Logging
    // =========================================================================

    /**
     * Log an admin action to the dedicated audit log table.
     *
     * @param string $action          Action name (e.g., 'view_conversation', 'settings_changed').
     * @param array  $context         Additional context data.
     * @param int    $admin_id        Admin user ID (default: current user).
     * @param string $action_category Category: 'config', 'data_access', 'security', 'system'.
     * @return bool Success.
     */
    public static function log( $action, $context = array(), $admin_id = null, $action_category = 'data_access' ) {
        if ( null === $admin_id ) {
            $admin_id = get_current_user_id();
        }

        // For admin actions, verify admin capability.
        if ( in_array( $action_category, array( 'config', 'data_access' ), true ) ) {
            if ( ! $admin_id || ! user_can( $admin_id, 'manage_options' ) ) {
                return false;
            }
        }

        $resource_type = null;
        $resource_id   = null;

        // Extract resource info from context.
        if ( isset( $context['conversation_id'] ) ) {
            $resource_type = 'conversation';
            $resource_id   = $context['conversation_id'];
        } elseif ( isset( $context['setting_key'] ) ) {
            $resource_type = 'setting';
            $resource_id   = $context['setting_key'];
        } elseif ( isset( $context['license_key'] ) ) {
            $resource_type = 'license';
            $resource_id   = '[redacted]';
        }

        $actor_type = 'admin';
        if ( in_array( $action_category, array( 'system' ), true ) && empty( $admin_id ) ) {
            $actor_type = 'system';
        }

        $result = Glimmr_AI_Database::insert_audit_log( array(
            'actor_type'      => $actor_type,
            'actor_id'        => $admin_id ?: null,
            'actor_ip_hash'   => self::get_ip_hash(),
            'action_category' => sanitize_key( $action_category ),
            'action'          => sanitize_key( $action ),
            'resource_type'   => $resource_type,
            'resource_id'     => $resource_id ? substr( $resource_id, 0, 256 ) : null,
            'details'         => $context,
        ) );

        // Also write to legacy analytics table for backwards compatibility.
        $legacy_entry = array(
            'action'       => sanitize_key( $action ),
            'admin_id'     => $admin_id,
            'admin_login'  => self::get_user_login( $admin_id ),
            'context'      => $context,
            'ip_hash'      => self::get_ip_hash(),
            'user_agent'   => self::get_user_agent_hash(),
            'timestamp'    => current_time( 'mysql' ),
            'site_id'      => get_current_blog_id(),
        );

        Glimmr_AI_Database::insert_analytics_event(
            'admin_audit',
            $legacy_entry,
            null
        );

        return (bool) $result;
    }

    // =========================================================================
    // Data Access Logging (existing methods)
    // =========================================================================

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
            array( 'conversation_id' => $conversation_id ),
            $admin_id,
            'data_access'
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
            array( 'conversation_id' => $conversation_id ),
            $admin_id,
            'data_access'
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
            $admin_id,
            'data_access'
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
            array( 'filters' => $filters ),
            $admin_id,
            'data_access'
        );
    }

    /**
     * Log data export.
     *
     * @param string $export_type Export type (csv, json).
     * @param array  $filters     Applied filters.
     * @param int    $admin_id    Admin user ID.
     * @return bool Success.
     */
    public static function log_data_export( $export_type, $filters = array(), $admin_id = null ) {
        return self::log(
            'data_export',
            array(
                'export_type' => $export_type,
                'filters'     => $filters,
            ),
            $admin_id,
            'data_access'
        );
    }

    // =========================================================================
    // Configuration Change Logging (new for SOC 2)
    // =========================================================================

    /**
     * Log a settings change.
     *
     * @param string $setting_key Setting key that changed.
     * @param mixed  $old_value   Previous value (will be redacted for sensitive keys).
     * @param mixed  $new_value   New value (will be redacted for sensitive keys).
     * @param int    $admin_id    Admin user ID.
     * @return bool Success.
     */
    public static function log_settings_change( $setting_key, $old_value = null, $new_value = null, $admin_id = null ) {
        $sensitive_keys = array(
            'openai_api_key',
            'openai_api_key_encrypted',
        );

        $context = array(
            'setting_key' => $setting_key,
        );

        if ( in_array( $setting_key, $sensitive_keys, true ) ) {
            $context['old_value'] = '[redacted]';
            $context['new_value'] = '[redacted]';
            $context['changed']   = true;
        } else {
            // For non-sensitive values, log the change but truncate long values.
            $context['old_value'] = self::truncate_value( $old_value );
            $context['new_value'] = self::truncate_value( $new_value );
        }

        return self::log( 'settings_changed', $context, $admin_id, 'config' );
    }

    /**
     * Log bulk settings changes (when multiple settings saved at once).
     *
     * @param array $changed_keys Array of setting keys that changed.
     * @param int   $admin_id     Admin user ID.
     * @return bool Success.
     */
    public static function log_bulk_settings_change( $changed_keys, $admin_id = null ) {
        return self::log(
            'settings_bulk_changed',
            array( 'changed_keys' => $changed_keys ),
            $admin_id,
            'config'
        );
    }

    /**
     * Log system prompt changes.
     *
     * @param bool $changed   Whether the prompt actually changed.
     * @param int  $admin_id  Admin user ID.
     * @return bool Success.
     */
    public static function log_prompt_change( $changed = true, $admin_id = null ) {
        return self::log(
            'system_prompt_changed',
            array( 'changed' => $changed ),
            $admin_id,
            'config'
        );
    }

    /**
     * Log tool enable/disable changes.
     *
     * @param array $tool_changes Array of tool_name => enabled (bool).
     * @param int   $admin_id     Admin user ID.
     * @return bool Success.
     */
    public static function log_tools_change( $tool_changes, $admin_id = null ) {
        return self::log(
            'tools_configuration_changed',
            array( 'tool_changes' => $tool_changes ),
            $admin_id,
            'config'
        );
    }

    /**
     * Log knowledge base changes.
     *
     * @param string $change_type Type: 'add', 'edit', 'delete', 'toggle', 'sync'.
     * @param array  $details     Change details.
     * @param int    $admin_id    Admin user ID.
     * @return bool Success.
     */
    public static function log_knowledge_change( $change_type, $details = array(), $admin_id = null ) {
        return self::log(
            'knowledge_' . sanitize_key( $change_type ),
            $details,
            $admin_id,
            'config'
        );
    }

    // =========================================================================
    // Security Event Logging (new for SOC 2)
    // =========================================================================

    /**
     * Log a rate limit violation.
     *
     * @param string $identifier      User/IP identifier (hashed).
     * @param string $identifier_type Type: 'user', 'ip', 'session'.
     * @param int    $limit           Configured limit.
     * @param int    $actual          Actual request count.
     * @return bool Success.
     */
    public static function log_rate_limit_violation( $identifier, $identifier_type, $limit, $actual ) {
        // Hash the identifier for privacy.
        $hashed_identifier = hash( 'sha256', $identifier . wp_salt( 'auth' ) );

        return self::log(
            'rate_limit_exceeded',
            array(
                'identifier_hash' => substr( $hashed_identifier, 0, 16 ),
                'identifier_type' => $identifier_type,
                'limit'           => $limit,
                'actual'          => $actual,
            ),
            0,
            'security'
        );
    }

    /**
     * Log an authentication/authorization failure.
     *
     * @param string $failure_type Type: 'order_verification', 'nonce', 'permission'.
     * @param array  $context      Additional context (never include PII).
     * @return bool Success.
     */
    public static function log_auth_failure( $failure_type, $context = array() ) {
        $context['failure_type'] = $failure_type;
        $context['ip_hash']      = self::get_ip_hash();

        return self::log(
            'auth_failure',
            $context,
            0,
            'security'
        );
    }

    /**
     * Log a license event.
     *
     * @param string $license_action Action: 'activate', 'deactivate', 'validation_failed', 'expired'.
     * @param array  $details        Event details (license key will be redacted).
     * @param int    $admin_id       Admin user ID.
     * @return bool Success.
     */
    public static function log_license_event( $license_action, $details = array(), $admin_id = null ) {
        // Redact license key if present.
        if ( isset( $details['license_key'] ) ) {
            $key = $details['license_key'];
            $details['license_key'] = strlen( $key ) > 8
                ? substr( $key, 0, 4 ) . '****' . substr( $key, -4 )
                : '[redacted]';
        }

        return self::log(
            'license_' . sanitize_key( $license_action ),
            $details,
            $admin_id,
            'security'
        );
    }

    /**
     * Log a content moderation event.
     *
     * @param string $result  Result: 'blocked', 'allowed', 'error'.
     * @param array  $context Context (never include the actual message content).
     * @return bool Success.
     */
    public static function log_moderation_event( $result, $context = array() ) {
        $context['result'] = $result;

        return self::log(
            'content_moderation',
            $context,
            0,
            'security'
        );
    }

    // =========================================================================
    // Query Methods
    // =========================================================================

    /**
     * Get audit log entries for a specific admin.
     *
     * @param int $admin_id Admin user ID.
     * @param int $limit    Number of entries.
     * @return array Audit entries.
     */
    public static function get_admin_activity( $admin_id, $limit = 50 ) {
        return Glimmr_AI_Database::get_audit_log( array(
            'actor_id' => $admin_id,
            'limit'    => $limit,
        ) );
    }

    /**
     * Get recent audit log entries.
     *
     * @param int $limit Number of entries.
     * @return array Audit entries.
     */
    public static function get_recent_activity( $limit = 100 ) {
        return Glimmr_AI_Database::get_audit_log( array(
            'limit' => $limit,
        ) );
    }

    /**
     * Get security events.
     *
     * @param int $limit Number of entries.
     * @return array Security audit entries.
     */
    public static function get_security_events( $limit = 100 ) {
        return Glimmr_AI_Database::get_audit_log( array(
            'action_category' => 'security',
            'limit'           => $limit,
        ) );
    }

    /**
     * Get configuration change history.
     *
     * @param int $limit Number of entries.
     * @return array Config change entries.
     */
    public static function get_config_changes( $limit = 100 ) {
        return Glimmr_AI_Database::get_audit_log( array(
            'action_category' => 'config',
            'limit'           => $limit,
        ) );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get user login by ID.
     *
     * @param int $user_id User ID.
     * @return string User login.
     */
    private static function get_user_login( $user_id ) {
        if ( empty( $user_id ) ) {
            return '';
        }
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
     * Truncate a value for safe logging.
     *
     * @param mixed $value Value to truncate.
     * @param int   $max   Maximum string length.
     * @return mixed Truncated value.
     */
    private static function truncate_value( $value, $max = 200 ) {
        if ( is_array( $value ) ) {
            $encoded = wp_json_encode( $value );
            if ( strlen( $encoded ) > $max ) {
                return substr( $encoded, 0, $max ) . '...[truncated]';
            }
            return $value;
        }

        if ( is_string( $value ) && strlen( $value ) > $max ) {
            return substr( $value, 0, $max ) . '...[truncated]';
        }

        return $value;
    }
}
