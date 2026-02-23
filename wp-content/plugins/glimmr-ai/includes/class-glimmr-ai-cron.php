<?php
/**
 * Cron Handler
 *
 * Manages scheduled tasks for product sync, knowledge sync,
 * and cleanup operations.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Cron
 *
 * Handles:
 * - Scheduled product sync
 * - Scheduled knowledge sync
 * - Conversation cleanup
 * - Rate limit cleanup
 */
class Glimmr_AI_Cron {

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    private $settings;

    /**
     * Cron hook prefix.
     *
     * @var string
     */
    private const HOOK_PREFIX = 'glimmr_ai_cron_';

    /**
     * Available cron hooks.
     *
     * @var array
     */
    private const HOOKS = array(
        'product_sync',
        'knowledge_sync',
        'cleanup',
    );

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     */
    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    /**
     * Initialize cron hooks.
     */
    public function init() {
        // Register actions.
        add_action( self::HOOK_PREFIX . 'product_sync', array( $this, 'run_product_sync' ) );
        add_action( self::HOOK_PREFIX . 'knowledge_sync', array( $this, 'run_knowledge_sync' ) );
        add_action( self::HOOK_PREFIX . 'cleanup', array( $this, 'run_cleanup' ) );

        // Check if schedules need updating.
        add_action( 'init', array( $this, 'maybe_update_schedules' ) );
    }

    /**
     * Schedule all cron events on activation.
     */
    public function schedule_events() {
        // Get configured times.
        $product_sync_time = $this->settings->get( 'product_sync_schedule', '03:00' );
        $knowledge_sync_time = $this->settings->get( 'knowledge_sync_schedule', '03:30' );

        // Product sync - only schedule if auto-sync is explicitly enabled (default false).
        $product_auto_sync = $this->settings->get( 'product_auto_sync_enabled', false );
        if ( $product_auto_sync ) {
            $this->schedule_daily_event( 'product_sync', $product_sync_time );
        } else {
            // Unschedule if disabled.
            $existing = wp_next_scheduled( self::HOOK_PREFIX . 'product_sync' );
            if ( $existing ) {
                wp_unschedule_event( $existing, self::HOOK_PREFIX . 'product_sync' );
            }
        }

        // Knowledge sync - only schedule if auto-sync is explicitly enabled (default false).
        $knowledge_auto_sync = $this->settings->get( 'knowledge_auto_sync_enabled', false );
        if ( $knowledge_auto_sync ) {
            $this->schedule_daily_event( 'knowledge_sync', $knowledge_sync_time );
        } else {
            // Unschedule if disabled.
            $existing = wp_next_scheduled( self::HOOK_PREFIX . 'knowledge_sync' );
            if ( $existing ) {
                wp_unschedule_event( $existing, self::HOOK_PREFIX . 'knowledge_sync' );
            }
        }

        // Cleanup - run twice daily.
        if ( ! wp_next_scheduled( self::HOOK_PREFIX . 'cleanup' ) ) {
            wp_schedule_event( time(), 'twicedaily', self::HOOK_PREFIX . 'cleanup' );
        }
    }

    /**
     * Unschedule all cron events on deactivation.
     */
    public function unschedule_events() {
        foreach ( self::HOOKS as $hook ) {
            $timestamp = wp_next_scheduled( self::HOOK_PREFIX . $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::HOOK_PREFIX . $hook );
            }
        }
    }

    /**
     * Schedule a daily event at a specific time.
     *
     * @param string $hook Event hook suffix.
     * @param string $time Time in HH:MM format.
     */
    private function schedule_daily_event( $hook, $time ) {
        $full_hook = self::HOOK_PREFIX . $hook;

        // Get next occurrence of this time.
        $timestamp = $this->get_next_occurrence( $time );

        // Check if already scheduled.
        $existing = wp_next_scheduled( $full_hook );
        if ( $existing ) {
            // Check if time changed.
            $existing_time = date( 'H:i', $existing );
            if ( $existing_time === $time ) {
                return; // Already scheduled at correct time.
            }
            // Unschedule old event.
            wp_unschedule_event( $existing, $full_hook );
        }

        wp_schedule_event( $timestamp, 'daily', $full_hook );
    }

    /**
     * Get timestamp for next occurrence of a time.
     *
     * @param string $time Time in HH:MM format.
     * @return int Timestamp.
     */
    private function get_next_occurrence( $time ) {
        $timezone = wp_timezone();
        $now = new DateTime( 'now', $timezone );
        $target = DateTime::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $timezone );

        if ( $target === false ) {
            // Fallback: schedule 24 hours from now if time parsing fails.
            return $now->getTimestamp() + DAY_IN_SECONDS;
        }

        // If time has passed today, schedule for tomorrow.
        if ( $target <= $now ) {
            $target->modify( '+1 day' );
        }

        return $target->getTimestamp();
    }

    /**
     * Check if schedules need updating (when settings change).
     */
    public function maybe_update_schedules() {
        $last_schedule_hash = get_option( 'glimmr_ai_schedule_hash', '' );

        $schedule_json = wp_json_encode( array(
            'product_auto_sync_enabled'   => $this->settings->get( 'product_auto_sync_enabled', false ),
            'product_sync_schedule'       => $this->settings->get( 'product_sync_schedule' ),
            'knowledge_auto_sync_enabled' => $this->settings->get( 'knowledge_auto_sync_enabled', false ),
            'knowledge_sync_schedule'     => $this->settings->get( 'knowledge_sync_schedule' ),
        ) );
        $current_hash = md5( $schedule_json !== false ? $schedule_json : '' );

        if ( $last_schedule_hash !== $current_hash ) {
            $this->unschedule_events();
            $this->schedule_events();
            update_option( 'glimmr_ai_schedule_hash', $current_hash );
        }
    }

    // =========================================================================
    // Cron Handlers
    // =========================================================================

    /**
     * Run product sync.
     */
    public function run_product_sync() {
        // Check if auto-sync is enabled (disabled by default).
        if ( ! $this->settings->get( 'product_auto_sync_enabled', false ) ) {
            return;
        }

        try {
            // Get instances.
            $database = new Glimmr_AI_Database();
            $indexer = new Glimmr_AI_Product_Indexer( $database, $this->settings );

            // Run incremental sync.
            $result = $indexer->incremental_sync();

            // Log result.
            $this->log_cron_run( 'product_sync', $result );

            // Sync to vector store if enabled.
            // Use the dedicated get_api_key() method which handles decryption.
            if ( Glimmr_AI_Settings::get_api_key() && $this->settings->get( 'openai_vector_store_id' ) ) {
                $openai = new Glimmr_AI_OpenAI( $this->settings );
                $vector_store = new Glimmr_AI_Vector_Store( $openai, $database, $this->settings );

                $vector_result = $vector_store->sync_products();

                $this->log_cron_run( 'product_vector_sync', $vector_result );
            }
        } catch ( Exception $e ) {
            Glimmr_AI_Logger::error(
                'Product sync cron task failed with exception',
                array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ),
                'cron'
            );
        }
    }

    /**
     * Run knowledge sync.
     */
    public function run_knowledge_sync() {
        // Check if auto-sync is enabled (disabled by default).
        if ( ! $this->settings->get( 'knowledge_auto_sync_enabled', false ) ) {
            return;
        }

        // Check if API is configured.
        // Use the dedicated get_api_key() method which handles decryption.
        if ( ! Glimmr_AI_Settings::get_api_key() || ! $this->settings->get( 'openai_vector_store_id' ) ) {
            return;
        }

        try {
            $database = new Glimmr_AI_Database();
            $openai = new Glimmr_AI_OpenAI( $this->settings );
            $vector_store = new Glimmr_AI_Vector_Store( $openai, $database, $this->settings );

            $result = $vector_store->sync_knowledge();

            $this->log_cron_run( 'knowledge_sync', $result );
        } catch ( Exception $e ) {
            Glimmr_AI_Logger::error(
                'Knowledge sync cron task failed with exception',
                array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ),
                'cron'
            );
        }
    }

    /**
     * Run cleanup tasks.
     */
    public function run_cleanup() {
        try {
            $database = new Glimmr_AI_Database();
            $results = array();

            // Cleanup expired conversations.
            $conversation = new Glimmr_AI_Conversation(
                $database,
                $this->settings,
                new Glimmr_AI_OpenAI( $this->settings )
            );
            $results['conversations_cleaned'] = $conversation->cleanup_expired();

            // Cleanup rate limit records.
            $rate_limiter = new Glimmr_AI_Rate_Limiter( $database, $this->settings );
            $results['rate_limits_cleaned'] = $rate_limiter->cleanup();

            // Cleanup old sync logs (keep 30 days).
            $results['sync_logs_cleaned'] = $this->cleanup_sync_logs( 30 );

            // Cleanup old analytics (based on retention setting).
            $retention_days = (int) $this->settings->get( 'data_retention_days', 365 );
            $results['analytics_cleaned'] = $this->cleanup_analytics( $retention_days );

            // Cleanup old audit log entries (365 days default for SOC 2 compliance).
            $audit_retention_days = (int) $this->settings->get( 'audit_log_retention_days', 365 );
            $results['audit_log_cleaned'] = Glimmr_AI_Database::cleanup_audit_log( $audit_retention_days );

            $this->log_cron_run( 'cleanup', $results );
        } catch ( Exception $e ) {
            Glimmr_AI_Logger::error(
                'Cron cleanup task failed with exception',
                array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ),
                'cron'
            );
        }
    }

    /**
     * Cleanup old sync logs.
     *
     * @param int $days Days to keep.
     * @return int Deleted count.
     */
    private function cleanup_sync_logs( $days ) {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}glimmr_ai_sync_log
                 WHERE started_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Failed to cleanup sync logs',
                array( 'db_error' => $wpdb->last_error, 'days' => $days ),
                'cron'
            );
            return 0;
        }

        return $result;
    }

    /**
     * Cleanup old analytics data.
     *
     * @param int $days Days to keep.
     * @return int Deleted count.
     */
    private function cleanup_analytics( $days ) {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}glimmr_ai_analytics
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Failed to cleanup analytics',
                array( 'db_error' => $wpdb->last_error, 'days' => $days ),
                'cron'
            );
            return 0;
        }

        return $result;
    }

    /**
     * Log cron run for debugging.
     *
     * @param string $task   Task name.
     * @param mixed  $result Task result.
     */
    private function log_cron_run( $task, $result ) {
        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::debug(
                sprintf( 'Cron task %s completed', $task ),
                array( 'result' => $result ),
                'cron'
            );
        }

        // Store last run info.
        update_option( 'glimmr_ai_last_cron_' . $task, array(
            'time'   => current_time( 'mysql' ),
            'result' => $result,
        ) );
    }

    // =========================================================================
    // Manual Triggers
    // =========================================================================

    /**
     * Manually trigger product sync.
     *
     * @param bool $full Full sync or incremental.
     * @return array Results.
     */
    public function trigger_product_sync( $full = false ) {
        $database = new Glimmr_AI_Database();
        $indexer = new Glimmr_AI_Product_Indexer( $database, $this->settings );

        if ( $full ) {
            return $indexer->full_sync( true );
        }

        return $indexer->incremental_sync();
    }

    /**
     * Manually trigger knowledge sync.
     *
     * @param bool $full Full sync or incremental.
     * @return array Results.
     */
    public function trigger_knowledge_sync( $full = false ) {
        $database = new Glimmr_AI_Database();
        $openai = new Glimmr_AI_OpenAI( $this->settings );
        $vector_store = new Glimmr_AI_Vector_Store( $openai, $database, $this->settings );

        return $vector_store->sync_knowledge( $full );
    }

    /**
     * Manually trigger vector store sync for products.
     *
     * @param bool $full Full sync or incremental.
     * @return array Results.
     */
    public function trigger_vector_sync( $full = false ) {
        $database = new Glimmr_AI_Database();
        $openai = new Glimmr_AI_OpenAI( $this->settings );
        $vector_store = new Glimmr_AI_Vector_Store( $openai, $database, $this->settings );

        return $vector_store->sync_products( $full );
    }

    // =========================================================================
    // Status
    // =========================================================================

    /**
     * Get cron status.
     *
     * @return array Status info.
     */
    public function get_status() {
        $status = array(
            'schedules' => array(),
            'last_runs' => array(),
        );

        foreach ( self::HOOKS as $hook ) {
            $full_hook = self::HOOK_PREFIX . $hook;
            $next = wp_next_scheduled( $full_hook );

            $status['schedules'][ $hook ] = array(
                'scheduled' => (bool) $next,
                'next_run'  => $next ? date( 'Y-m-d H:i:s', $next ) : null,
            );

            $last_run = get_option( 'glimmr_ai_last_cron_' . $hook );
            $status['last_runs'][ $hook ] = $last_run ?: null;
        }

        return $status;
    }

    /**
     * Get sync history.
     *
     * @param string $type  Sync type (products, knowledge, full).
     * @param int    $limit Number of records.
     * @return array Sync history.
     */
    public function get_sync_history( $type = null, $limit = 20 ) {
        global $wpdb;

        $where = '';
        $params = array();

        if ( $type ) {
            $where = 'WHERE sync_type = %s';
            $params[] = $type;
        }

        $params[] = $limit;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}glimmr_ai_sync_log
                 {$where}
                 ORDER BY started_at DESC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );
    }
}
