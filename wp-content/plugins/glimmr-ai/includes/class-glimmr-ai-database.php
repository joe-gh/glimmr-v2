<?php
/**
 * Database operations and table management for Glimmr AI.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Database
 *
 * Handles all database operations including table creation, upgrades, and queries.
 */
class Glimmr_AI_Database {

    /**
     * Database version for migrations.
     *
     * Bump this to force upgrade and ensure FULLTEXT index exists.
     *
     * @var string
     */
    const DB_VERSION = '1.9.0';

    /**
     * Option name for storing database version.
     *
     * @var string
     */
    const DB_VERSION_OPTION = 'glimmr_ai_db_version';

    /**
     * Get the table name with WordPress prefix.
     *
     * @param string $table_name The base table name without prefix.
     * @return string The full table name with WordPress prefix.
     */
    public static function get_table_name( $table_name ) {
        global $wpdb;
        return $wpdb->prefix . GLIMMR_AI_TABLE_PREFIX . $table_name;
    }

    /**
     * Create all database tables.
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Create all tables.
        self::create_conversations_table( $charset_collate );
        self::create_messages_table( $charset_collate );
        self::create_flagged_issues_table( $charset_collate );
        self::create_analytics_table( $charset_collate );
        self::create_knowledge_table( $charset_collate );
        self::create_rate_limits_table( $charset_collate );
        self::create_token_budgets_table( $charset_collate );
        self::create_product_index_table( $charset_collate );
        self::create_product_variations_table( $charset_collate );
        self::create_sync_log_table( $charset_collate );
        self::create_contact_requests_table( $charset_collate );
        self::create_contact_responses_table( $charset_collate );

        // Update database version.
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Create conversations table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_conversations_table( $charset_collate ) {
        $table_name = self::get_table_name( 'conversations' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            conversation_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            session_id VARCHAR(64) NULL,
            openai_thread_id VARCHAR(64) NULL,
            status VARCHAR(20) DEFAULT 'active',
            message_count INT UNSIGNED DEFAULT 0,
            last_message_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            metadata JSON NULL,
            UNIQUE KEY idx_conversation_id (conversation_id),
            KEY idx_site_id (site_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_expires_at (expires_at),
            KEY idx_user_status (user_id, status),
            KEY idx_session_status (session_id, status),
            KEY idx_site_status (site_id, status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create messages table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_messages_table( $charset_collate ) {
        $table_name = self::get_table_name( 'messages' );
        $conversations_table = self::get_table_name( 'conversations' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            conversation_id VARCHAR(64) NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            tool_calls JSON NULL,
            tool_results JSON NULL,
            tokens_used INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_site_id (site_id),
            KEY idx_conversation_id (conversation_id),
            KEY idx_created_at (created_at),
            KEY idx_conv_created (conversation_id, created_at),
            KEY idx_site_conv (site_id, conversation_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create flagged issues table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_flagged_issues_table( $charset_collate ) {
        $table_name = self::get_table_name( 'flagged_issues' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            conversation_id VARCHAR(64) NOT NULL,
            message_id BIGINT UNSIGNED NULL,
            issue_type VARCHAR(50) NULL,
            user_feedback TEXT NULL,
            status VARCHAR(20) DEFAULT 'new',
            admin_notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            KEY idx_site_id (site_id),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_conversation_id (conversation_id),
            KEY idx_site_status (site_id, status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create analytics table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_analytics_table( $charset_collate ) {
        $table_name = self::get_table_name( 'analytics' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            event_type VARCHAR(50) NOT NULL,
            conversation_id VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            properties JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_site_id (site_id),
            KEY idx_event_type (event_type),
            KEY idx_created_at (created_at),
            KEY idx_conversation_id (conversation_id),
            KEY idx_user_id (user_id),
            KEY idx_site_event (site_id, event_type)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create knowledge table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_knowledge_table( $charset_collate ) {
        $table_name = self::get_table_name( 'knowledge' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            type VARCHAR(20) NOT NULL,
            source_id BIGINT UNSIGNED NULL,
            source_type VARCHAR(50) NULL,
            title VARCHAR(255) NULL,
            content LONGTEXT NOT NULL,
            vector_file_id VARCHAR(64) NULL,
            sync_status VARCHAR(20) DEFAULT 'pending',
            last_synced_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_site_id (site_id),
            KEY idx_type (type),
            KEY idx_sync_status (sync_status),
            KEY idx_source (source_id, source_type)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create rate limits table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_rate_limits_table( $charset_collate ) {
        $table_name = self::get_table_name( 'rate_limits' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            identifier VARCHAR(64) NOT NULL,
            identifier_type VARCHAR(20) NOT NULL,
            request_count INT UNSIGNED DEFAULT 0,
            tokens_used INT UNSIGNED DEFAULT 0,
            window_start DATETIME NOT NULL,
            UNIQUE KEY idx_site_identifier_window (site_id, identifier, identifier_type, window_start),
            KEY idx_site_id (site_id),
            KEY idx_window_start (window_start),
            KEY idx_cleanup (identifier_type, window_start)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create token budgets table for atomic token tracking.
     *
     * S15: Atomic Rate Limiting - Uses INSERT...ON DUPLICATE KEY UPDATE
     * to prevent race conditions in token budget tracking.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_token_budgets_table( $charset_collate ) {
        $table_name = self::get_table_name( 'token_budgets' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            date_key VARCHAR(10) NOT NULL,
            budget_type VARCHAR(20) NOT NULL DEFAULT 'daily',
            tokens_used BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_site_date_type (site_id, date_key, budget_type),
            KEY idx_date_key (date_key),
            KEY idx_budget_type (budget_type)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create product index table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_product_index_table( $charset_collate ) {
        $table_name = self::get_table_name( 'product_index' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            product_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            product_type VARCHAR(50) NOT NULL,
            sku VARCHAR(100) NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT NULL,
            short_description TEXT NULL,
            price DECIMAL(10,2) NULL,
            regular_price DECIMAL(10,2) NULL,
            sale_price DECIMAL(10,2) NULL,
            min_variation_price DECIMAL(10,2) NULL,
            max_variation_price DECIMAL(10,2) NULL,
            stock_status VARCHAR(20) NULL,
            stock_quantity INT NULL,
            has_stock TINYINT(1) DEFAULT 1,
            variation_count INT UNSIGNED DEFAULT 0,
            categories JSON NULL,
            tags JSON NULL,
            attributes JSON NULL,
            available_colors JSON NULL,
            available_sizes JSON NULL,
            custom_attributes JSON NULL,
            variation_skus TEXT NULL,
            image_url VARCHAR(500) NULL,
            permalink VARCHAR(500) NULL,
            average_rating DECIMAL(3,2) NULL,
            review_count INT UNSIGNED DEFAULT 0,
            is_featured TINYINT(1) DEFAULT 0,
            is_on_sale TINYINT(1) DEFAULT 0,
            is_virtual TINYINT(1) DEFAULT 0,
            is_downloadable TINYINT(1) DEFAULT 0,
            weight VARCHAR(20) NULL,
            dimensions JSON NULL,
            search_text TEXT NULL,
            include_in_index TINYINT(1) DEFAULT 1,
            vector_file_id VARCHAR(64) NULL,
            last_synced_at DATETIME NULL,
            product_modified_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_product_site (product_id, site_id),
            KEY idx_site_id (site_id),
            KEY idx_sku (sku),
            KEY idx_product_type (product_type),
            KEY idx_price (price),
            KEY idx_stock_status (stock_status),
            KEY idx_is_on_sale (is_on_sale),
            KEY idx_include_in_index (include_in_index),
            KEY idx_last_synced (last_synced_at),
            KEY idx_has_stock (has_stock),
            KEY idx_filter (site_id, include_in_index, price),
            KEY idx_stock_filter (site_id, include_in_index, stock_status)
        ) {$charset_collate};";

        dbDelta( $sql );

        // dbDelta doesn't handle FULLTEXT indexes properly, so we create it separately.
        // First check if the index already exists.
        self::ensure_fulltext_index( $table_name );
    }

    /**
     * Ensure FULLTEXT index exists on product_index table.
     *
     * dbDelta() doesn't properly create FULLTEXT indexes, so this method
     * creates it separately using ALTER TABLE.
     *
     * @param string $table_name The table name.
     * @return void
     */
    private static function ensure_fulltext_index( $table_name ) {
        global $wpdb;

        // Check if FULLTEXT index already exists.
        $index_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = %s
                 AND table_name = %s
                 AND index_name = 'idx_search'
                 AND index_type = 'FULLTEXT'",
                DB_NAME,
                $table_name
            )
        );

        if ( ! $index_exists ) {
            // Create the FULLTEXT index.
            // Suppress errors in case of partial index state.
            $wpdb->suppress_errors( true );

            // Try to drop any existing non-FULLTEXT index with same name first.
            $wpdb->query( "ALTER TABLE {$table_name} DROP INDEX idx_search" );

            // Create the FULLTEXT index.
            $result = $wpdb->query(
                "ALTER TABLE {$table_name} ADD FULLTEXT idx_search (name, sku, search_text, short_description, variation_skus)"
            );

            $wpdb->suppress_errors( false );

            if ( $result === false && class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::error(
                    'Failed to create FULLTEXT index on product_index table',
                    array( 'error' => $wpdb->last_error ),
                    'database'
                );
            } elseif ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Created FULLTEXT index on product_index table', array(), 'database' );
            }
        }
    }

    /**
     * Create product variations table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_product_variations_table( $charset_collate ) {
        $table_name = self::get_table_name( 'product_variations' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            variation_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(100) NULL,
            attributes JSON NOT NULL,
            price DECIMAL(10,2) NULL,
            regular_price DECIMAL(10,2) NULL,
            sale_price DECIMAL(10,2) NULL,
            stock_status VARCHAR(20) NULL,
            stock_quantity INT NULL,
            image_url VARCHAR(500) NULL,
            is_on_sale TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_variation_site (variation_id, site_id),
            KEY idx_parent_id (parent_id),
            KEY idx_site_id (site_id),
            KEY idx_sku (sku),
            KEY idx_stock_status (stock_status),
            KEY idx_parent_stock (parent_id, stock_status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create sync log table.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_sync_log_table( $charset_collate ) {
        $table_name = self::get_table_name( 'sync_log' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            sync_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            items_processed INT UNSIGNED DEFAULT 0,
            items_total INT UNSIGNED DEFAULT 0,
            items_created INT UNSIGNED DEFAULT 0,
            items_updated INT UNSIGNED DEFAULT 0,
            items_deleted INT UNSIGNED DEFAULT 0,
            items_errored INT UNSIGNED DEFAULT 0,
            error_details JSON NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            triggered_by VARCHAR(20) NOT NULL,
            KEY idx_site_id (site_id),
            KEY idx_sync_type (sync_type),
            KEY idx_status (status),
            KEY idx_started_at (started_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create contact requests table.
     *
     * Stores customer contact requests submitted via the AI assistant.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_contact_requests_table( $charset_collate ) {
        $table_name = self::get_table_name( 'contact_requests' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            request_id VARCHAR(64) NOT NULL,
            conversation_id VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) NULL,
            subject VARCHAR(255) NOT NULL,
            category VARCHAR(50) DEFAULT 'general',
            message LONGTEXT NOT NULL,
            conversation_context LONGTEXT NULL,
            order_id BIGINT UNSIGNED NULL,
            product_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) DEFAULT 'new',
            priority VARCHAR(20) DEFAULT 'normal',
            assigned_to BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            UNIQUE KEY idx_request_id (request_id),
            KEY idx_site_id (site_id),
            KEY idx_status (status),
            KEY idx_category (category),
            KEY idx_user_id (user_id),
            KEY idx_created_at (created_at),
            KEY idx_conversation_id (conversation_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Create contact responses table.
     *
     * Stores admin responses to customer contact requests.
     *
     * @param string $charset_collate The charset collation.
     * @return void
     */
    private static function create_contact_responses_table( $charset_collate ) {
        $table_name = self::get_table_name( 'contact_responses' );

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT UNSIGNED DEFAULT 1,
            request_id VARCHAR(64) NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            response_text LONGTEXT NOT NULL,
            email_sent TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_request_id (request_id),
            KEY idx_site_id (site_id),
            KEY idx_admin_id (admin_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Drop all plugin tables.
     *
     * @return void
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            'messages',
            'flagged_issues',
            'analytics',
            'knowledge',
            'rate_limits',
            'product_variations',
            'product_index',
            'sync_log',
            'contact_responses',
            'contact_requests',
            'conversations', // Drop conversations last due to foreign key references.
        );

        foreach ( $tables as $table ) {
            $table_name = self::get_table_name( $table );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        }

        delete_option( self::DB_VERSION_OPTION );
    }

    /**
     * Check if database needs upgrade.
     *
     * @return bool
     */
    public static function needs_upgrade() {
        $current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );
        return version_compare( $current_version, self::DB_VERSION, '<' );
    }

    /**
     * Upgrade database if needed.
     *
     * @return void
     */
    public static function maybe_upgrade() {
        if ( ! self::needs_upgrade() ) {
            return;
        }

        $current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

        // Run version-specific migrations before create_tables.
        self::run_migrations( $current_version );

        // Run dbDelta for any new columns/tables.
        self::create_tables();
    }

    /**
     * Run version-specific migrations.
     *
     * @param string $from_version Current installed version.
     * @return void
     */
    private static function run_migrations( $from_version ) {
        global $wpdb;

        // Migration to 1.2.0: Add search_text column and update FULLTEXT index.
        if ( version_compare( $from_version, '1.2.0', '<' ) ) {
            $table = self::get_table_name( 'product_index' );

            // Check if search_text column exists.
            $column_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'search_text'",
                    DB_NAME,
                    $table
                )
            );

            if ( ! $column_exists ) {
                // Add search_text column.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN search_text TEXT NULL AFTER dimensions" );
            }

            // Check if sku index exists (we're adding it).
            $sku_index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_sku'",
                    DB_NAME,
                    $table
                )
            );

            if ( ! $sku_index_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_sku (sku)" );
            }

            // FULLTEXT index will be created/updated by ensure_fulltext_index() in create_tables().
            // The old migration code tried to DROP/ADD here but that was unreliable.

            // Log migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.2.0: Added search_text column', array(), 'database' );
            }
        }

        // Migration to 1.3.0: Add variation aggregation columns and create variations table.
        if ( version_compare( $from_version, '1.3.0', '<' ) ) {
            $table = self::get_table_name( 'product_index' );

            // Add new columns for variation aggregation.
            $new_columns = array(
                'min_variation_price' => 'DECIMAL(10,2) NULL AFTER sale_price',
                'max_variation_price' => 'DECIMAL(10,2) NULL AFTER min_variation_price',
                'has_stock'           => 'TINYINT(1) DEFAULT 1 AFTER stock_quantity',
                'variation_count'     => 'INT UNSIGNED DEFAULT 0 AFTER has_stock',
                'available_colors'    => 'JSON NULL AFTER attributes',
                'available_sizes'     => 'JSON NULL AFTER available_colors',
                'custom_attributes'   => 'JSON NULL AFTER available_sizes',
                'variation_skus'      => 'TEXT NULL AFTER custom_attributes',
            );

            foreach ( $new_columns as $column => $definition ) {
                $column_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        DB_NAME,
                        $table,
                        $column
                    )
                );

                if ( ! $column_exists ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
                }
            }

            // Add has_stock index.
            $has_stock_index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_has_stock'",
                    DB_NAME,
                    $table
                )
            );

            if ( ! $has_stock_index_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_has_stock (has_stock)" );
            }

            // FULLTEXT index will be created/updated by ensure_fulltext_index() in create_tables().
            // The old migration code tried to DROP/ADD here but that was unreliable.

            // Delete existing variation rows from product_index (we're moving to parent-only).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query( "DELETE FROM {$table} WHERE product_type = 'variation'" );

            // Log migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.3.0: Added variation aggregation columns and variations table', array(), 'database' );
            }
        }

        // Migration to 1.4.0: Add site_id column to conversations, messages, flagged_issues, analytics tables.
        if ( version_compare( $from_version, '1.4.0', '<' ) ) {
            $tables_to_update = array(
                'conversations'  => self::get_table_name( 'conversations' ),
                'messages'       => self::get_table_name( 'messages' ),
                'flagged_issues' => self::get_table_name( 'flagged_issues' ),
                'analytics'      => self::get_table_name( 'analytics' ),
            );

            foreach ( $tables_to_update as $table_key => $table ) {
                // Check if site_id column exists.
                $column_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'site_id'",
                        DB_NAME,
                        $table
                    )
                );

                if ( ! $column_exists ) {
                    // Add site_id column after id.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                    $wpdb->query( "ALTER TABLE {$table} ADD COLUMN site_id BIGINT UNSIGNED DEFAULT 1 AFTER id" );

                    // Add site_id index.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                    $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_site_id (site_id)" );
                }
            }

            // Add composite index for site + status on conversations.
            $site_status_index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_site_status'",
                    DB_NAME,
                    $tables_to_update['conversations']
                )
            );

            if ( ! $site_status_index_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$tables_to_update['conversations']} ADD KEY idx_site_status (site_id, status)" );
            }

            // Log migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.4.0: Added site_id column to conversations, messages, flagged_issues, analytics tables', array(), 'database' );
            }
        }

        // Migration to 1.4.1: Ensure FULLTEXT index exists.
        // dbDelta doesn't properly handle FULLTEXT indexes, so this migration ensures
        // it gets created via the new ensure_fulltext_index() method.
        if ( version_compare( $from_version, '1.4.1', '<' ) ) {
            // The FULLTEXT index is created by ensure_fulltext_index() which is called
            // by create_product_index_table() which is called by create_tables().
            // Just log this migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.4.1: Ensured FULLTEXT index on product_index table', array(), 'database' );
            }
        }

        // Migration to 1.5.0: Add performance indexes for analytics and rate_limits tables.
        if ( version_compare( $from_version, '1.5.0', '<' ) ) {
            $analytics_table    = self::get_table_name( 'analytics' );
            $rate_limits_table  = self::get_table_name( 'rate_limits' );

            // 1. Analytics: Compound index for time-based event queries (dashboard, revenue reports).
            $idx_site_event_date_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_site_event_date'",
                    DB_NAME,
                    $analytics_table
                )
            );

            if ( ! $idx_site_event_date_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$analytics_table} ADD KEY idx_site_event_date (site_id, event_type, created_at)" );
            }

            // 2. Analytics: Compound index for conversation revenue lookups.
            $idx_conv_event_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_conv_event'",
                    DB_NAME,
                    $analytics_table
                )
            );

            if ( ! $idx_conv_event_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$analytics_table} ADD KEY idx_conv_event (conversation_id, event_type)" );
            }

            // 3. Rate limits: Index for cleanup query performance.
            $idx_window_cleanup_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_window_cleanup'",
                    DB_NAME,
                    $rate_limits_table
                )
            );

            if ( ! $idx_window_cleanup_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$rate_limits_table} ADD KEY idx_window_cleanup (window_start)" );
            }

            // Log migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.5.0: Added performance indexes for analytics and rate_limits tables', array(), 'database' );
            }
        }

        // Migration to 1.6.0: Add contact_requests table.
        if ( version_compare( $from_version, '1.6.0', '<' ) ) {
            // The contact_requests table is created by create_contact_requests_table() which is called
            // by create_tables(). This migration just logs the addition.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.6.0: Added contact_requests table', array(), 'database' );
            }
        }

        // Migration to 1.7.0: Add contact_responses table.
        if ( version_compare( $from_version, '1.7.0', '<' ) ) {
            // The contact_responses table is created by create_contact_responses_table() which is called
            // by create_tables(). This migration just logs the addition.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.7.0: Added contact_responses table', array(), 'database' );
            }
        }

        // Migration to 1.9.0: Add site_id column to rate_limits table for multisite isolation.
        if ( version_compare( $from_version, '1.9.0', '<' ) ) {
            $rate_limits_table = self::get_table_name( 'rate_limits' );

            // Check if site_id column already exists.
            $column_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'site_id'",
                    DB_NAME,
                    $rate_limits_table
                )
            );

            if ( ! $column_exists ) {
                // Add site_id column (DEFAULT 1 for existing rows).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$rate_limits_table} ADD COLUMN site_id BIGINT UNSIGNED DEFAULT 1 AFTER id" );

                // Drop old unique key.
                $old_key_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_identifier_window'",
                        DB_NAME,
                        $rate_limits_table
                    )
                );

                if ( $old_key_exists ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                    $wpdb->query( "ALTER TABLE {$rate_limits_table} DROP KEY idx_identifier_window" );
                }

                // Add new unique key including site_id.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$rate_limits_table} ADD UNIQUE KEY idx_site_identifier_window (site_id, identifier, identifier_type, window_start)" );

                // Add site_id index.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( "ALTER TABLE {$rate_limits_table} ADD KEY idx_site_id (site_id)" );
            }

            // Log migration.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::info( 'Database migrated to 1.9.0: Added site_id to rate_limits table for multisite isolation', array(), 'database' );
            }
        }
    }

    /**
     * Get current site ID for multisite support.
     *
     * @return int
     */
    public static function get_current_site_id() {
        return is_multisite() ? get_current_blog_id() : 1;
    }

    /**
     * Insert a new conversation.
     *
     * @param array $data Conversation data.
     * @return int|false The conversation ID on success, false on failure.
     */
    public static function insert_conversation( $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'conversations' );

        $defaults = array(
            'site_id'         => self::get_current_site_id(),
            'conversation_id' => wp_generate_uuid4(),
            'user_id'         => get_current_user_id() ?: null,
            'session_id'      => null,
            'status'          => 'active',
            'message_count'   => 0,
            'created_at'      => current_time( 'mysql' ),
            'expires_at'      => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
            'metadata'        => null,
        );

        $data = wp_parse_args( $data, $defaults );

        if ( is_array( $data['metadata'] ) ) {
            $data['metadata'] = wp_json_encode( $data['metadata'] );
        }

        $result = $wpdb->insert( $table_name, $data );

        return $result ? $data['conversation_id'] : false;
    }

    /**
     * Get a conversation by ID.
     *
     * S8: Site isolation - always filter by site_id in multisite to prevent cross-site data access.
     *
     * @param string $conversation_id The conversation ID.
     * @return object|null The conversation object or null.
     */
    public static function get_conversation( $conversation_id ) {
        global $wpdb;

        $table_name = self::get_table_name( 'conversations' );
        $site_id    = self::get_current_site_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE conversation_id = %s AND site_id = %d",
                $conversation_id,
                $site_id
            )
        );
    }

    /**
     * Insert a message.
     *
     * @param array $data Message data.
     * @return int|false The message ID on success, false on failure.
     */
    public static function insert_message( $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'messages' );

        $defaults = array(
            'site_id'         => self::get_current_site_id(),
            'conversation_id' => '',
            'role'            => 'user',
            'content'         => '',
            'tool_calls'      => null,
            'tool_results'    => null,
            'tokens_used'     => 0,
            'created_at'      => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        // S10: PII masking - mask sensitive data in user messages before storage.
        // This prevents exposure of emails, phones, credit cards in stored conversation history.
        if ( 'user' === $data['role'] && ! empty( $data['content'] ) && class_exists( 'Glimmr_AI_PII_Masker' ) ) {
            $data['content'] = Glimmr_AI_PII_Masker::mask_text( $data['content'] );
        }

        // JSON encode arrays.
        if ( is_array( $data['tool_calls'] ) ) {
            $data['tool_calls'] = wp_json_encode( $data['tool_calls'] );
        }
        if ( is_array( $data['tool_results'] ) ) {
            $data['tool_results'] = wp_json_encode( $data['tool_results'] );
        }

        $result = $wpdb->insert( $table_name, $data );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Failed to insert message',
                array(
                    'conversation_id' => $data['conversation_id'],
                    'role'            => $data['role'],
                    'db_error'        => $wpdb->last_error,
                ),
                'database'
            );
            return false;
        }

        // Update conversation message count and last message time.
        $conversations_table = self::get_table_name( 'conversations' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $update_result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$conversations_table} SET message_count = message_count + 1, last_message_at = %s WHERE conversation_id = %s",
                $data['created_at'],
                $data['conversation_id']
            )
        );

        if ( false === $update_result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::warning(
                'Failed to update conversation message count',
                array(
                    'conversation_id' => $data['conversation_id'],
                    'db_error'        => $wpdb->last_error,
                ),
                'database'
            );
            // Don't fail the insert - message was saved successfully.
        }

        return $wpdb->insert_id;
    }

    /**
     * Get messages for a conversation.
     *
     * S8: Site isolation - filter by site_id to prevent cross-site message access.
     *
     * @param string $conversation_id The conversation ID.
     * @param int    $limit           Optional. Number of messages to retrieve. Default 50.
     * @param int    $offset          Optional. Offset for pagination. Default 0.
     * @return array Array of message objects.
     */
    public static function get_messages( $conversation_id, $limit = 50, $offset = 0 ) {
        global $wpdb;

        $table_name = self::get_table_name( 'messages' );
        $site_id    = self::get_current_site_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE conversation_id = %s AND site_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
                $conversation_id,
                $site_id,
                $limit,
                $offset
            )
        );

        // Ensure we always return an array, even on error.
        if ( null === $results || ! empty( $wpdb->last_error ) ) {
            if ( ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::error(
                    'Failed to get messages',
                    array(
                        'conversation_id' => $conversation_id,
                        'db_error'        => $wpdb->last_error,
                    ),
                    'database'
                );
            }
            return array();
        }

        return $results;
    }

    /**
     * Insert an analytics event.
     *
     * @param string $event_type      The event type.
     * @param array  $properties      Optional. Event properties.
     * @param string $conversation_id Optional. Associated conversation ID.
     * @return int|false The event ID on success, false on failure.
     */
    public static function insert_analytics_event( $event_type, $properties = array(), $conversation_id = null ) {
        global $wpdb;

        $table_name = self::get_table_name( 'analytics' );

        $data = array(
            'site_id'         => self::get_current_site_id(),
            'event_type'      => $event_type,
            'conversation_id' => $conversation_id,
            'user_id'         => get_current_user_id() ?: null,
            'properties'      => wp_json_encode( $properties ),
            'created_at'      => current_time( 'mysql' ),
        );

        $result = $wpdb->insert( $table_name, $data );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Failed to insert analytics event',
                array(
                    'event_type'      => $event_type,
                    'conversation_id' => $conversation_id,
                    'db_error'        => $wpdb->last_error,
                ),
                'database'
            );
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Insert a flagged issue.
     *
     * @param array $data Issue data.
     * @return int|false The issue ID on success, false on failure.
     */
    public static function insert_flagged_issue( $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'flagged_issues' );

        $defaults = array(
            'site_id'         => self::get_current_site_id(),
            'conversation_id' => '',
            'message_id'      => null,
            'issue_type'      => null,
            'user_feedback'   => null,
            'status'          => 'new',
            'created_at'      => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert( $table_name, $data );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::error(
                'Failed to insert flagged issue',
                array(
                    'conversation_id' => $data['conversation_id'],
                    'issue_type'      => $data['issue_type'],
                    'db_error'        => $wpdb->last_error,
                ),
                'database'
            );
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get flagged issues with optional filtering.
     *
     * @since 1.9.0
     * @param array $args Query arguments.
     * @return array Array of flagged issue objects.
     */
    public static function get_flagged_issues( $args = array() ) {
        global $wpdb;

        $table_name = self::get_table_name( 'flagged_issues' );
        $site_id    = self::get_current_site_id();

        $defaults = array(
            'status'  => null,
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where  = array( 'site_id = %d' );
        $values = array( $site_id );

        // Status filter.
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize orderby.
        $allowed_orderby = array( 'created_at', 'status', 'issue_type' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $values[] = intval( $args['limit'] );
        $values[] = intval( $args['offset'] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $values
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql );
    }

    /**
     * Count flagged issues with optional filtering.
     *
     * @since 1.9.0
     * @param array $args Query arguments.
     * @return int Count of flagged issues.
     */
    public static function count_flagged_issues( $args = array() ) {
        global $wpdb;

        $table_name = self::get_table_name( 'flagged_issues' );
        $site_id    = self::get_current_site_id();

        $where  = array( 'site_id = %d' );
        $values = array( $site_id );

        // Status filter.
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}",
            $values
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Flag a conversation with an issue.
     *
     * @since 1.9.0
     * @param string $conversation_id The conversation ID.
     * @param string $issue_type      The type of issue.
     * @param string $feedback        User feedback/description.
     * @return int|false The issue ID on success, false on failure.
     */
    public static function flag_conversation( $conversation_id, $issue_type, $feedback = '' ) {
        return self::insert_flagged_issue( array(
            'conversation_id' => $conversation_id,
            'issue_type'      => $issue_type,
            'user_feedback'   => $feedback,
            'status'          => 'new',
        ) );
    }

    /**
     * Resolve a flagged issue.
     *
     * @since 1.9.0
     * @param int    $issue_id    The issue ID.
     * @param string $status      The new status (resolved, dismissed, in_progress).
     * @param string $admin_notes Optional admin notes.
     * @return bool True on success, false on failure.
     */
    public static function resolve_flagged_issue( $issue_id, $status = 'resolved', $admin_notes = '' ) {
        global $wpdb;

        $table_name = self::get_table_name( 'flagged_issues' );
        $site_id    = self::get_current_site_id();

        $data = array(
            'status'      => $status,
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => get_current_user_id(),
        );

        if ( ! empty( $admin_notes ) ) {
            $data['admin_notes'] = $admin_notes;
        }

        $result = $wpdb->update(
            $table_name,
            $data,
            array(
                'id'      => $issue_id,
                'site_id' => $site_id,
            ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d', '%d' )
        );

        if ( false === $result ) {
            Glimmr_AI_Logger::error(
                'Failed to resolve flagged issue',
                array(
                    'issue_id' => $issue_id,
                    'status'   => $status,
                    'db_error' => $wpdb->last_error,
                ),
                'database'
            );
            return false;
        }

        return true;
    }

    /**
     * Update product index entry.
     *
     * @param int   $product_id The WooCommerce product ID.
     * @param array $data       Product data to update/insert.
     * @return bool Success or failure.
     */
    public static function upsert_product_index( $product_id, $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'product_index' );
        $site_id    = self::get_current_site_id();

        // JSON encode array fields.
        $json_fields = array( 'categories', 'tags', 'attributes', 'dimensions' );
        foreach ( $json_fields as $field ) {
            if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
                $data[ $field ] = wp_json_encode( $data[ $field ] );
            }
        }

        // Check if product exists.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE product_id = %d AND site_id = %d",
                $product_id,
                $site_id
            )
        );

        $data['product_id'] = $product_id;
        $data['site_id']    = $site_id;
        $data['updated_at'] = current_time( 'mysql' );

        if ( $existing ) {
            return $wpdb->update(
                $table_name,
                $data,
                array(
                    'product_id' => $product_id,
                    'site_id'    => $site_id,
                )
            ) !== false;
        } else {
            $data['created_at'] = current_time( 'mysql' );
            return $wpdb->insert( $table_name, $data ) !== false;
        }
    }

    /**
     * Search products in the index.
     *
     * @param array $args Search arguments.
     * @return array Array of product results.
     */
    public static function search_products( $args = array() ) {
        global $wpdb;

        $table_name = self::get_table_name( 'product_index' );
        $site_id    = self::get_current_site_id();

        $defaults = array(
            'search'       => '',
            'category'     => '',
            'min_price'    => null,
            'max_price'    => null,
            'stock_status' => '',
            'on_sale'      => null,
            'limit'        => 10,
            'offset'       => 0,
            'orderby'      => 'name',
            'order'        => 'ASC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where   = array( 'site_id = %d', 'include_in_index = 1' );
        $values  = array( $site_id );

        // Full-text search.
        if ( ! empty( $args['search'] ) ) {
            $where[]  = 'MATCH(name, description, short_description) AGAINST(%s IN NATURAL LANGUAGE MODE)';
            $values[] = $args['search'];
        }

        // Category filter (JSON search).
        if ( ! empty( $args['category'] ) ) {
            $where[]  = 'JSON_CONTAINS(categories, %s)';
            $values[] = wp_json_encode( $args['category'] );
        }

        // Price filters.
        if ( null !== $args['min_price'] ) {
            $where[]  = 'price >= %f';
            $values[] = floatval( $args['min_price'] );
        }
        if ( null !== $args['max_price'] ) {
            $where[]  = 'price <= %f';
            $values[] = floatval( $args['max_price'] );
        }

        // Stock status.
        if ( ! empty( $args['stock_status'] ) ) {
            $where[]  = 'stock_status = %s';
            $values[] = $args['stock_status'];
        }

        // On sale.
        if ( null !== $args['on_sale'] ) {
            $where[]  = 'is_on_sale = %d';
            $values[] = $args['on_sale'] ? 1 : 0;
        }

        $where_clause = implode( ' AND ', $where );

        // Sanitize orderby.
        $allowed_orderby = array( 'name', 'price', 'average_rating', 'created_at' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
        $order           = strtoupper( $args['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $values[] = intval( $args['limit'] );
        $values[] = intval( $args['offset'] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $values
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql );
    }

    /**
     * Log a sync operation.
     *
     * @param array $data Sync log data.
     * @return int|false The log ID on success, false on failure.
     */
    public static function insert_sync_log( $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'sync_log' );

        $defaults = array(
            'site_id'         => self::get_current_site_id(),
            'sync_type'       => 'products',
            'status'          => 'running',
            'items_processed' => 0,
            'items_total'     => 0,
            'items_created'   => 0,
            'items_updated'   => 0,
            'items_deleted'   => 0,
            'items_errored'   => 0,
            'started_at'      => current_time( 'mysql' ),
            'triggered_by'    => 'manual',
        );

        $data = wp_parse_args( $data, $defaults );

        if ( isset( $data['error_details'] ) && is_array( $data['error_details'] ) ) {
            $data['error_details'] = wp_json_encode( $data['error_details'] );
        }

        $result = $wpdb->insert( $table_name, $data );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a sync log entry.
     *
     * @param int   $log_id The log ID.
     * @param array $data   Data to update.
     * @return bool Success or failure.
     */
    public static function update_sync_log( $log_id, $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'sync_log' );

        if ( isset( $data['error_details'] ) && is_array( $data['error_details'] ) ) {
            $data['error_details'] = wp_json_encode( $data['error_details'] );
        }

        return $wpdb->update(
            $table_name,
            $data,
            array( 'id' => $log_id )
        ) !== false;
    }

    /**
     * Check and update rate limit.
     *
     * @param string $identifier      The identifier (user ID or hashed IP).
     * @param string $identifier_type The type of identifier (user, ip, session).
     * @param int    $limit           The rate limit.
     * @param int    $window_seconds  The time window in seconds.
     * @return array Array with 'allowed' (bool) and 'remaining' (int).
     */
    public static function check_rate_limit( $identifier, $identifier_type, $limit, $window_seconds = 3600 ) {
        global $wpdb;

        $table_name   = self::get_table_name( 'rate_limits' );
        $window_start = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );

        // Get current count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $current = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE identifier = %s AND identifier_type = %s AND window_start >= %s",
                $identifier,
                $identifier_type,
                $window_start
            )
        );

        if ( $current ) {
            $remaining = max( 0, $limit - $current->request_count );
            $allowed   = $remaining > 0;

            if ( $allowed ) {
                // Increment count.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table_name} SET request_count = request_count + 1 WHERE id = %d",
                        $current->id
                    )
                );
            }

            return array(
                'allowed'   => $allowed,
                'remaining' => $remaining - ( $allowed ? 1 : 0 ),
            );
        }

        // Create new rate limit entry.
        $wpdb->insert(
            $table_name,
            array(
                'identifier'      => $identifier,
                'identifier_type' => $identifier_type,
                'request_count'   => 1,
                'window_start'    => current_time( 'mysql' ),
            )
        );

        return array(
            'allowed'   => true,
            'remaining' => $limit - 1,
        );
    }

    /**
     * Clean up expired rate limit entries.
     *
     * @return int Number of deleted entries.
     */
    public static function cleanup_rate_limits() {
        global $wpdb;

        $table_name = self::get_table_name( 'rate_limits' );
        $threshold  = gmdate( 'Y-m-d H:i:s', time() - 7200 ); // 2 hours ago.

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE window_start < %s",
                $threshold
            )
        );
    }

    /**
     * Clean up expired conversations.
     *
     * @param int|null $site_id Optional. Site ID to filter by. Null for all sites.
     * @return int Number of updated conversations.
     */
    public static function expire_old_conversations( $site_id = null ) {
        global $wpdb;

        $table_name = self::get_table_name( 'conversations' );

        if ( null === $site_id ) {
            // Expire across all sites (for network cron).
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name} SET status = 'expired' WHERE status = 'active' AND expires_at < %s",
                    current_time( 'mysql' )
                )
            );
        }

        // Expire only for specific site.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table_name} SET status = 'expired' WHERE site_id = %d AND status = 'active' AND expires_at < %s",
                $site_id,
                current_time( 'mysql' )
            )
        );
    }

    /**
     * Get conversations with optional site filtering.
     *
     * For network admins, can retrieve conversations across all sites.
     *
     * @param array $args Query arguments.
     *                    - site_id: int|null Site ID to filter by. Null for all sites (network admin).
     *                    - status: string|null Filter by status.
     *                    - user_id: int|null Filter by user ID.
     *                    - limit: int Number of conversations to return.
     *                    - offset: int Offset for pagination.
     *                    - orderby: string Column to order by.
     *                    - order: string ASC or DESC.
     * @return array Array of conversation objects.
     */
    public static function get_conversations( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id' => self::get_current_site_id(),
            'status'  => null,
            'user_id' => null,
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'conversations' );

        $where = array();
        $params = array();

        // Site filter - null means all sites (network admin view).
        if ( null !== $args['site_id'] ) {
            $where[] = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        // Status filter.
        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        // User filter.
        if ( null !== $args['user_id'] ) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Whitelist orderby columns to prevent SQL injection.
        $allowed_orderby = array( 'created_at', 'last_message_at', 'message_count', 'site_id', 'status' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM {$table_name} {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $prepared );
    }

    /**
     * Count conversations with optional site filtering.
     *
     * @param array $args Query arguments (same as get_conversations but without pagination).
     * @return int Number of conversations.
     */
    public static function count_conversations( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id' => self::get_current_site_id(),
            'status'  => null,
            'user_id' => null,
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'conversations' );

        $where = array();
        $params = array();

        if ( null !== $args['site_id'] ) {
            $where[] = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        if ( null !== $args['user_id'] ) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";

        if ( ! empty( $params ) ) {
            $prepared = $wpdb->prepare( $sql, $params );
            return (int) $wpdb->get_var( $prepared );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get analytics with optional site filtering.
     *
     * @param array $args Query arguments.
     *                    - site_id: int|null Site ID to filter by. Null for all sites.
     *                    - event_type: string|null Filter by event type.
     *                    - start_date: string|null Start date (Y-m-d H:i:s).
     *                    - end_date: string|null End date (Y-m-d H:i:s).
     *                    - limit: int Number of events to return.
     *                    - offset: int Offset for pagination.
     * @return array Array of analytics event objects.
     */
    public static function get_analytics( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id'    => self::get_current_site_id(),
            'event_type' => null,
            'start_date' => null,
            'end_date'   => null,
            'limit'      => 100,
            'offset'     => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'analytics' );

        $where = array();
        $params = array();

        if ( null !== $args['site_id'] ) {
            $where[] = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        if ( ! empty( $args['event_type'] ) ) {
            $where[] = 'event_type = %s';
            $params[] = $args['event_type'];
        }

        if ( ! empty( $args['start_date'] ) ) {
            $where[] = 'created_at >= %s';
            $params[] = $args['start_date'];
        }

        if ( ! empty( $args['end_date'] ) ) {
            $where[] = 'created_at <= %s';
            $params[] = $args['end_date'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $prepared );
    }

    /**
     * Get analytics summary with optional site filtering.
     *
     * Groups events by type and counts them.
     *
     * @param array $args Query arguments (same as get_analytics but without pagination).
     * @return array Array of event type => count.
     */
    public static function get_analytics_summary( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id'    => self::get_current_site_id(),
            'start_date' => null,
            'end_date'   => null,
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'analytics' );

        $where = array();
        $params = array();

        if ( null !== $args['site_id'] ) {
            $where[] = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        if ( ! empty( $args['start_date'] ) ) {
            $where[] = 'created_at >= %s';
            $params[] = $args['start_date'];
        }

        if ( ! empty( $args['end_date'] ) ) {
            $where[] = 'created_at <= %s';
            $params[] = $args['end_date'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT event_type, COUNT(*) as count FROM {$table_name} {$where_clause} GROUP BY event_type ORDER BY count DESC";

        if ( ! empty( $params ) ) {
            $prepared = $wpdb->prepare( $sql, $params );
            $results = $wpdb->get_results( $prepared, ARRAY_A );
        } else {
            $results = $wpdb->get_results( $sql, ARRAY_A );
        }

        $summary = array();
        foreach ( $results as $row ) {
            $summary[ $row['event_type'] ] = (int) $row['count'];
        }

        return $summary;
    }

    /**
     * Get list of all sites with conversations (for network admin).
     *
     * @return array Array of site objects with conversation counts.
     */
    public static function get_sites_with_conversations() {
        global $wpdb;

        if ( ! is_multisite() ) {
            return array();
        }

        $table_name = self::get_table_name( 'conversations' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            "SELECT site_id, COUNT(*) as conversation_count
             FROM {$table_name}
             GROUP BY site_id
             ORDER BY conversation_count DESC"
        );

        // Enrich with site details.
        foreach ( $results as &$row ) {
            $blog_details = get_blog_details( $row->site_id );
            if ( $blog_details ) {
                $row->blogname = $blog_details->blogname;
                $row->siteurl = $blog_details->siteurl;
            } else {
                $row->blogname = sprintf( __( 'Site %d', 'glimmr-ai' ), $row->site_id );
                $row->siteurl = '';
            }
        }

        return $results;
    }

    /**
     * Check if current user can view network-level data.
     *
     * @return bool True if user has network admin capabilities.
     */
    public static function can_view_network_data() {
        if ( ! is_multisite() ) {
            return false;
        }

        return is_super_admin();
    }

    // =========================================================================
    // Contact Request Methods
    // =========================================================================

    /**
     * Get contact requests with filtering and pagination.
     *
     * S8: Site isolation - filter by site_id to prevent cross-site data access.
     *
     * @param array $args Query arguments.
     *                    - site_id: int|null Site ID to filter by. Null for all sites (network admin).
     *                    - status: string|null Filter by status (new, in_progress, resolved).
     *                    - category: string|null Filter by category.
     *                    - priority: string|null Filter by priority.
     *                    - search: string|null Search in name, email, subject.
     *                    - date_from: string|null Start date (Y-m-d).
     *                    - date_to: string|null End date (Y-m-d).
     *                    - limit: int Number of requests to return.
     *                    - offset: int Offset for pagination.
     *                    - orderby: string Column to order by.
     *                    - order: string ASC or DESC.
     * @return array Array of contact request objects.
     */
    public static function get_contact_requests( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id'   => self::get_current_site_id(),
            'status'    => null,
            'category'  => null,
            'priority'  => null,
            'search'    => null,
            'date_from' => null,
            'date_to'   => null,
            'limit'     => 20,
            'offset'    => 0,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'contact_requests' );

        $where  = array();
        $params = array();

        // Site filter - null means all sites (network admin view).
        if ( null !== $args['site_id'] ) {
            $where[]  = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        // Status filter.
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        // Category filter.
        if ( ! empty( $args['category'] ) ) {
            $where[]  = 'category = %s';
            $params[] = $args['category'];
        }

        // Priority filter.
        if ( ! empty( $args['priority'] ) ) {
            $where[]  = 'priority = %s';
            $params[] = $args['priority'];
        }

        // Search filter.
        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]     = '(name LIKE %s OR email LIKE %s OR subject LIKE %s OR request_id LIKE %s)';
            $params[]    = $search_term;
            $params[]    = $search_term;
            $params[]    = $search_term;
            $params[]    = $search_term;
        }

        // Date filters.
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'DATE(created_at) >= %s';
            $params[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'DATE(created_at) <= %s';
            $params[] = $args['date_to'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Whitelist orderby columns to prevent SQL injection.
        $allowed_orderby = array( 'created_at', 'updated_at', 'status', 'priority', 'category', 'name' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql      = "SELECT * FROM {$table_name} {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $prepared );
    }

    /**
     * Count contact requests with optional filtering.
     *
     * @param array $args Query arguments (same as get_contact_requests but without pagination).
     * @return int Number of contact requests.
     */
    public static function count_contact_requests( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id'   => self::get_current_site_id(),
            'status'    => null,
            'category'  => null,
            'priority'  => null,
            'search'    => null,
            'date_from' => null,
            'date_to'   => null,
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'contact_requests' );

        $where  = array();
        $params = array();

        if ( null !== $args['site_id'] ) {
            $where[]  = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['category'] ) ) {
            $where[]  = 'category = %s';
            $params[] = $args['category'];
        }

        if ( ! empty( $args['priority'] ) ) {
            $where[]  = 'priority = %s';
            $params[] = $args['priority'];
        }

        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]     = '(name LIKE %s OR email LIKE %s OR subject LIKE %s OR request_id LIKE %s)';
            $params[]    = $search_term;
            $params[]    = $search_term;
            $params[]    = $search_term;
            $params[]    = $search_term;
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'DATE(created_at) >= %s';
            $params[] = $args['date_from'];
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'DATE(created_at) <= %s';
            $params[] = $args['date_to'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM {$table_name} {$where_clause}";

        if ( ! empty( $params ) ) {
            $prepared = $wpdb->prepare( $sql, $params );
            return (int) $wpdb->get_var( $prepared );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get a single contact request by request_id.
     *
     * S8: Site isolation - filter by site_id to prevent cross-site data access.
     *
     * @param string $request_id The contact request ID (CR-XXXXXXXX format).
     * @return object|null The contact request object or null.
     */
    public static function get_contact_request( $request_id ) {
        global $wpdb;

        $table_name = self::get_table_name( 'contact_requests' );
        $site_id    = self::get_current_site_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE request_id = %s AND site_id = %d",
                $request_id,
                $site_id
            )
        );
    }

    /**
     * Update a contact request.
     *
     * S8: Site isolation - ensures update only affects requests on current site.
     *
     * @param string $request_id The contact request ID (CR-XXXXXXXX format).
     * @param array  $data       Data to update.
     * @return bool Success or failure.
     */
    public static function update_contact_request( $request_id, $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'contact_requests' );
        $site_id    = self::get_current_site_id();

        // Set updated_at timestamp.
        $data['updated_at'] = current_time( 'mysql' );

        // If status is being set to resolved, set resolved_at.
        if ( isset( $data['status'] ) && 'resolved' === $data['status'] && ! isset( $data['resolved_at'] ) ) {
            $data['resolved_at'] = current_time( 'mysql' );
        }

        return $wpdb->update(
            $table_name,
            $data,
            array(
                'request_id' => $request_id,
                'site_id'    => $site_id,
            )
        ) !== false;
    }

    /**
     * Insert a contact response.
     *
     * @param array $data Response data.
     * @return int|false The response ID on success, false on failure.
     */
    public static function insert_contact_response( $data ) {
        global $wpdb;

        $table_name = self::get_table_name( 'contact_responses' );

        $defaults = array(
            'site_id'       => self::get_current_site_id(),
            'request_id'    => '',
            'admin_id'      => get_current_user_id(),
            'response_text' => '',
            'email_sent'    => 1,
            'created_at'    => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert( $table_name, $data );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::error(
                    'Failed to insert contact response',
                    array(
                        'request_id' => $data['request_id'],
                        'db_error'   => $wpdb->last_error,
                    ),
                    'database'
                );
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get responses for a contact request.
     *
     * S8: Site isolation - filter by site_id.
     *
     * @param string $request_id The contact request ID.
     * @return array Array of response objects with admin user details.
     */
    public static function get_contact_responses( $request_id ) {
        global $wpdb;

        $table_name = self::get_table_name( 'contact_responses' );
        $site_id    = self::get_current_site_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE request_id = %s AND site_id = %d ORDER BY created_at ASC",
                $request_id,
                $site_id
            )
        );

        // Enrich with admin user details.
        foreach ( $results as &$row ) {
            $admin_user = get_userdata( $row->admin_id );
            if ( $admin_user ) {
                $row->admin_name  = $admin_user->display_name;
                $row->admin_email = $admin_user->user_email;
            } else {
                $row->admin_name  = sprintf( __( 'Admin #%d', 'glimmr-ai' ), $row->admin_id );
                $row->admin_email = '';
            }
        }

        return $results;
    }

    /**
     * Get contact request statistics.
     *
     * @param array $args Query arguments.
     * @return array Statistics array.
     */
    public static function get_contact_request_stats( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'site_id' => self::get_current_site_id(),
        );

        $args = wp_parse_args( $args, $defaults );

        $table_name = self::get_table_name( 'contact_requests' );

        $where  = array();
        $params = array();

        if ( null !== $args['site_id'] ) {
            $where[]  = 'site_id = %d';
            $params[] = (int) $args['site_id'];
        }

        $where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Get counts by status.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT status, COUNT(*) as count FROM {$table_name} {$where_clause} GROUP BY status";

        if ( ! empty( $params ) ) {
            $prepared = $wpdb->prepare( $sql, $params );
            $results  = $wpdb->get_results( $prepared, ARRAY_A );
        } else {
            $results = $wpdb->get_results( $sql, ARRAY_A );
        }

        $stats = array(
            'total'       => 0,
            'new'         => 0,
            'in_progress' => 0,
            'resolved'    => 0,
        );

        foreach ( $results as $row ) {
            $stats[ $row['status'] ] = (int) $row['count'];
            $stats['total']         += (int) $row['count'];
        }

        return $stats;
    }
}
