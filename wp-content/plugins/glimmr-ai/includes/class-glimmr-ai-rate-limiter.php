<?php
/**
 * Rate Limiter
 *
 * Implements rate limiting for API requests to control costs
 * and prevent abuse.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Rate_Limiter
 *
 * Handles:
 * - Per-user/session rate limiting
 * - Daily/monthly token budgets
 * - Request counting with sliding windows
 */
class Glimmr_AI_Rate_Limiter {

    /**
     * Database instance.
     *
     * @var Glimmr_AI_Database
     */
    private $database;

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    private $settings;

    /**
     * Default window duration in seconds (1 hour).
     *
     * @var int
     */
    private const DEFAULT_WINDOW_DURATION = 3600;

    /**
     * Configured window duration.
     *
     * @var int
     */
    private $window_duration;

    /**
     * Rate limit configuration.
     *
     * @var array
     */
    private $rate_config;

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_Settings $settings Settings instance.
     */
    public function __construct( $database, $settings ) {
        $this->database = $database;
        $this->settings = $settings;

        // Load rate limit configuration from settings.
        $this->rate_config     = Glimmr_AI_Settings::get_rate_limit_config();
        $this->window_duration = $this->rate_config['window_seconds'] ?? self::DEFAULT_WINDOW_DURATION;
    }

    /**
     * Get the current site ID for multisite isolation.
     *
     * @return int
     */
    private function get_site_id() {
        return Glimmr_AI_Database::get_current_site_id();
    }

    /**
     * Check if request is allowed.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type (user, ip, session).
     * @return array Result with 'allowed' boolean and details.
     */
    public function check( $identifier, $identifier_type = 'user' ) {
        // Get applicable limit.
        $limit = $this->get_limit( $identifier_type );

        // Get current usage.
        $usage = $this->get_usage( $identifier, $identifier_type );

        $allowed = $usage['request_count'] < $limit;

        return array(
            'allowed'         => $allowed,
            'limit'           => $limit,
            'current'         => $usage['request_count'],
            'remaining'       => max( 0, $limit - $usage['request_count'] ),
            'reset_at'        => $usage['window_end'],
            'tokens_used'     => $usage['tokens_used'],
        );
    }

    /**
     * Atomically check and record a request.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to prevent race conditions.
     * This is the preferred method for rate limiting.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type (user, ip, session).
     * @param int    $tokens_used     Tokens used in this request.
     * @return array Result with 'allowed' boolean and new count.
     */
    public function check_and_record( $identifier, $identifier_type = 'user', $tokens_used = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $window_start = $this->get_window_start();
        $limit = $this->get_limit( $identifier_type );
        $site_id = $this->get_site_id();

        // Use atomic INSERT ... ON DUPLICATE KEY UPDATE.
        // This prevents race conditions by doing check and increment in one operation.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (site_id, identifier, identifier_type, request_count, tokens_used, window_start)
                 VALUES (%d, %s, %s, 1, %d, %s)
                 ON DUPLICATE KEY UPDATE
                 request_count = request_count + 1,
                 tokens_used = tokens_used + %d",
                $site_id,
                $identifier,
                $identifier_type,
                $tokens_used,
                $window_start,
                $tokens_used
            )
        );

        // Check for database errors - if rate limiting fails, allow the request but log it.
        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Rate limiter database error during check_and_record',
                array(
                    'identifier'      => $identifier,
                    'identifier_type' => $identifier_type,
                    'db_error'        => $wpdb->last_error,
                ),
                'rate_limiter'
            );
            // Fail open - allow the request but with degraded tracking.
            return array(
                'allowed'   => true,
                'limit'     => $limit,
                'current'   => 0,
                'remaining' => $limit,
                'reset_at'  => date( 'Y-m-d H:i:s', strtotime( $window_start ) + $this->window_duration ),
                'error'     => true,
            );
        }

        // Get the updated count.
        $current_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT request_count FROM {$table}
                 WHERE site_id = %d
                 AND identifier = %s
                 AND identifier_type = %s
                 AND window_start = %s",
                $site_id,
                $identifier,
                $identifier_type,
                $window_start
            )
        );

        // Record global usage for budget tracking.
        $this->record_global_usage( $tokens_used );

        $allowed = $current_count <= $limit;

        // Audit log: Record rate limit violations for SOC 2 compliance.
        if ( ! $allowed && class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_rate_limit_violation(
                $identifier,
                $identifier_type,
                $limit,
                $current_count
            );
        }

        return array(
            'allowed'   => $allowed,
            'limit'     => $limit,
            'current'   => $current_count,
            'remaining' => max( 0, $limit - $current_count ),
            'reset_at'  => date( 'Y-m-d H:i:s', strtotime( $window_start ) + $this->window_duration ),
        );
    }

    /**
     * Record a request.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type (user, ip, session).
     * @param int    $tokens_used     Tokens used in this request.
     * @return bool Success.
     */
    public function record( $identifier, $identifier_type = 'user', $tokens_used = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $window_start = $this->get_window_start();
        $site_id = $this->get_site_id();

        // Use atomic INSERT ... ON DUPLICATE KEY UPDATE.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (site_id, identifier, identifier_type, request_count, tokens_used, window_start)
                 VALUES (%d, %s, %s, 1, %d, %s)
                 ON DUPLICATE KEY UPDATE
                 request_count = request_count + 1,
                 tokens_used = tokens_used + %d",
                $site_id,
                $identifier,
                $identifier_type,
                $tokens_used,
                $window_start,
                $tokens_used
            )
        );

        // Check for database errors.
        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Rate limiter database error during record',
                array(
                    'identifier'      => $identifier,
                    'identifier_type' => $identifier_type,
                    'db_error'        => $wpdb->last_error,
                ),
                'rate_limiter'
            );
            return false;
        }

        // Record global usage for budget tracking.
        $this->record_global_usage( $tokens_used );

        return true;
    }

    /**
     * Get usage for identifier.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type.
     * @return array Usage data.
     */
    public function get_usage( $identifier, $identifier_type = 'user' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $window_start = $this->get_window_start();
        $site_id = $this->get_site_id();

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT request_count, tokens_used
                 FROM {$table}
                 WHERE site_id = %d
                 AND identifier = %s
                 AND identifier_type = %s
                 AND window_start = %s",
                $site_id,
                $identifier,
                $identifier_type,
                $window_start
            ),
            ARRAY_A
        );

        return array(
            'request_count' => (int) ( $result['request_count'] ?? 0 ),
            'tokens_used'   => (int) ( $result['tokens_used'] ?? 0 ),
            'window_start'  => $window_start,
            'window_end'    => date( 'Y-m-d H:i:s', strtotime( $window_start ) + $this->window_duration ),
        );
    }

    /**
     * Get rate limit based on identifier type.
     *
     * @param string $identifier_type Type (user, ip, session).
     * @return int Requests per hour.
     */
    private function get_limit( $identifier_type ) {
        if ( 'user' === $identifier_type ) {
            return (int) $this->settings->get( 'rate_limit_authenticated', 100 );
        }

        return (int) $this->settings->get( 'rate_limit_anonymous', 20 );
    }

    /**
     * Get current window start time.
     *
     * @return string MySQL datetime.
     */
    private function get_window_start() {
        $now = time();
        $window = floor( $now / $this->window_duration ) * $this->window_duration;
        return date( 'Y-m-d H:i:s', $window );
    }

    // =========================================================================
    // Global Token Budgets
    // =========================================================================

    /**
     * Record usage for global budget tracking.
     *
     * S15: Atomic Rate Limiting - Uses INSERT...ON DUPLICATE KEY UPDATE
     * to prevent race conditions in token budget tracking.
     *
     * @param int $tokens_used Tokens used.
     */
    private function record_global_usage( $tokens_used ) {
        if ( $tokens_used <= 0 ) {
            return;
        }

        global $wpdb;

        $table   = $wpdb->prefix . 'glimmr_ai_token_budgets';
        $site_id = Glimmr_AI_Database::get_current_site_id();
        $today   = gmdate( 'Y-m-d' );
        $month   = gmdate( 'Y-m' );

        // S15: Atomic upsert for daily usage.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (site_id, date_key, budget_type, tokens_used)
                 VALUES (%d, %s, 'daily', %d)
                 ON DUPLICATE KEY UPDATE tokens_used = tokens_used + %d",
                $site_id,
                $today,
                $tokens_used,
                $tokens_used
            )
        );

        // S15: Atomic upsert for monthly usage.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (site_id, date_key, budget_type, tokens_used)
                 VALUES (%d, %s, 'monthly', %d)
                 ON DUPLICATE KEY UPDATE tokens_used = tokens_used + %d",
                $site_id,
                $month,
                $tokens_used,
                $tokens_used
            )
        );
    }

    /**
     * Check if within daily token budget.
     *
     * S15: Reads from atomic token_budgets table.
     *
     * @return array Budget status.
     */
    public function check_daily_budget() {
        global $wpdb;

        $limit   = (int) $this->settings->get( 'daily_token_limit', 100000 );
        $table   = $wpdb->prefix . 'glimmr_ai_token_budgets';
        $site_id = Glimmr_AI_Database::get_current_site_id();
        $today   = gmdate( 'Y-m-d' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $used = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tokens_used FROM {$table} WHERE site_id = %d AND date_key = %s AND budget_type = 'daily'",
                $site_id,
                $today
            )
        );

        return array(
            'allowed'   => $used < $limit,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => max( 0, $limit - $used ),
            'percent'   => $limit > 0 ? round( ( $used / $limit ) * 100, 1 ) : 0,
        );
    }

    /**
     * Check if within monthly token budget.
     *
     * S15: Reads from atomic token_budgets table.
     *
     * @return array Budget status.
     */
    public function check_monthly_budget() {
        global $wpdb;

        $limit   = (int) $this->settings->get( 'monthly_token_limit', 2000000 );
        $table   = $wpdb->prefix . 'glimmr_ai_token_budgets';
        $site_id = Glimmr_AI_Database::get_current_site_id();
        $month   = gmdate( 'Y-m' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $used = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tokens_used FROM {$table} WHERE site_id = %d AND date_key = %s AND budget_type = 'monthly'",
                $site_id,
                $month
            )
        );

        return array(
            'allowed'   => $used < $limit,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => max( 0, $limit - $used ),
            'percent'   => $limit > 0 ? round( ( $used / $limit ) * 100, 1 ) : 0,
        );
    }

    /**
     * Check all budgets and rate limits.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type.
     * @return array Combined status.
     */
    public function check_all( $identifier, $identifier_type = 'user' ) {
        $rate_limit = $this->check( $identifier, $identifier_type );
        $daily_budget = $this->check_daily_budget();
        $monthly_budget = $this->check_monthly_budget();

        $allowed = $rate_limit['allowed'] && $daily_budget['allowed'] && $monthly_budget['allowed'];

        $reason = '';
        if ( ! $rate_limit['allowed'] ) {
            $reason = 'rate_limit';
        } elseif ( ! $daily_budget['allowed'] ) {
            $reason = 'daily_budget';
        } elseif ( ! $monthly_budget['allowed'] ) {
            $reason = 'monthly_budget';
        }

        return array(
            'allowed'        => $allowed,
            'reason'         => $reason,
            'rate_limit'     => $rate_limit,
            'daily_budget'   => $daily_budget,
            'monthly_budget' => $monthly_budget,
        );
    }

    /**
     * Get user-friendly error message for rate limit.
     *
     * @param string $reason Limit reason.
     * @param array  $status Full status array.
     * @return string Error message.
     */
    public function get_error_message( $reason, $status = array() ) {
        switch ( $reason ) {
            case 'rate_limit':
                $reset = isset( $status['rate_limit']['reset_at'] )
                    ? human_time_diff( time(), strtotime( $status['rate_limit']['reset_at'] ) )
                    : 'soon';
                return sprintf(
                    __( 'You\'ve reached the message limit. Please try again in %s.', 'glimmr-ai' ),
                    $reset
                );

            case 'daily_budget':
                return __( 'Our AI assistant is taking a break for the day. Please try again tomorrow.', 'glimmr-ai' );

            case 'monthly_budget':
                return __( 'Our AI assistant has reached its monthly limit. Please try again next month or contact support.', 'glimmr-ai' );

            default:
                return __( 'Service temporarily unavailable. Please try again later.', 'glimmr-ai' );
        }
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Clean up old rate limit records.
     *
     * @return int Records deleted.
     */
    public function cleanup() {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $cutoff = date( 'Y-m-d H:i:s', time() - ( 24 * 3600 ) ); // 24 hours ago.

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE window_start < %s",
                $cutoff
            )
        );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Rate limiter cleanup failed',
                array( 'db_error' => $wpdb->last_error, 'cutoff' => $cutoff ),
                'rate_limiter'
            );
            return 0;
        }

        return $result;
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    /**
     * Get usage statistics.
     *
     * @return array Statistics.
     */
    public function get_stats() {
        $daily = $this->check_daily_budget();
        $monthly = $this->check_monthly_budget();

        // Use configurable cost estimation.
        $cost_per_token = ( $this->rate_config['token_cost_million'] ?? 5 ) / 1000000;

        return array(
            'daily' => array(
                'used'           => $daily['used'],
                'limit'          => $daily['limit'],
                'remaining'      => $daily['remaining'],
                'percent'        => $daily['percent'],
                'estimated_cost' => round( $daily['used'] * $cost_per_token, 2 ),
            ),
            'monthly' => array(
                'used'           => $monthly['used'],
                'limit'          => $monthly['limit'],
                'remaining'      => $monthly['remaining'],
                'percent'        => $monthly['percent'],
                'estimated_cost' => round( $monthly['used'] * $cost_per_token, 2 ),
            ),
        );
    }

    /**
     * Get historical usage data.
     *
     * @param int $days Number of days.
     * @return array Daily usage.
     */
    public function get_historical_usage( $days = 30 ) {
        $usage = array();

        for ( $i = 0; $i < $days; $i++ ) {
            $date = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $key = 'glimmr_ai_tokens_' . $date;
            $tokens = (int) get_transient( $key );

            $usage[] = array(
                'date'   => $date,
                'tokens' => $tokens,
                'cost'   => round( $tokens * 0.000005, 2 ),
            );
        }

        return array_reverse( $usage );
    }

    /**
     * Reset rate limits for identifier.
     *
     * @param string $identifier      User identifier.
     * @param string $identifier_type Type.
     * @return bool Success.
     */
    public function reset( $identifier, $identifier_type = 'user' ) {
        global $wpdb;

        return $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_rate_limits',
            array(
                'site_id'         => $this->get_site_id(),
                'identifier'      => $identifier,
                'identifier_type' => $identifier_type,
            ),
            array( '%d', '%s', '%s' )
        ) !== false;
    }

    // =========================================================================
    // Convenience Methods
    // =========================================================================

    /**
     * Simple check if request is allowed (convenience wrapper).
     *
     * Auto-detects identifier type based on whether user is logged in.
     *
     * @param string $identifier User identifier.
     * @return bool True if allowed, false if rate limited.
     */
    public function check_limit( $identifier ) {
        $identifier_type = is_user_logged_in() ? 'user' : 'ip';
        $result = $this->check_all( $identifier, $identifier_type );
        return $result['allowed'];
    }

    /**
     * Record a request (convenience wrapper).
     *
     * @param string $identifier User identifier.
     * @param int    $tokens     Tokens used (optional).
     * @return bool Success.
     */
    public function record_request( $identifier, $tokens = 0 ) {
        $identifier_type = is_user_logged_in() ? 'user' : 'ip';
        return $this->record( $identifier, $identifier_type, $tokens );
    }

    /**
     * Get seconds until rate limit resets.
     *
     * @param string $identifier User identifier.
     * @return int Seconds until reset.
     */
    public function get_retry_after( $identifier ) {
        $identifier_type = is_user_logged_in() ? 'user' : 'ip';
        $usage = $this->get_usage( $identifier, $identifier_type );

        if ( empty( $usage['window_end'] ) ) {
            return $this->window_duration;
        }

        $reset_time = strtotime( $usage['window_end'] );
        $now = time();

        return max( 0, $reset_time - $now );
    }
}
