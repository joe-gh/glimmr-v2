<?php
/**
 * Database schema and migration for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Database
 *
 * Manages the 3 database tables: licenses, activations, license_logs.
 */
class Glimmr_Licensing_Database {

    /**
     * Current database schema version.
     *
     * @var string
     */
    const DB_VERSION = '1.0.0';

    /**
     * Option key for stored database version.
     *
     * @var string
     */
    const DB_VERSION_OPTION = 'glimmr_licensing_db_version';

    /**
     * Create or update database tables.
     *
     * Uses dbDelta for idempotent schema management.
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $licenses_table    = $wpdb->prefix . 'glimmr_licenses';
        $activations_table = $wpdb->prefix . 'glimmr_activations';
        $logs_table        = $wpdb->prefix . 'glimmr_license_logs';

        $sql = "CREATE TABLE {$licenses_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(25) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            subscription_id BIGINT UNSIGNED NULL,
            plan VARCHAR(20) NOT NULL DEFAULT 'plan_1',
            site_limit INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expiry_date DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_license_key (license_key),
            KEY idx_customer_email (customer_email),
            KEY idx_status (status),
            KEY idx_order_id (order_id)
        ) {$charset_collate};

        CREATE TABLE {$activations_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL,
            activation_id VARCHAR(36) NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) DEFAULT '',
            ip_address VARCHAR(45) DEFAULT '',
            environment TEXT NULL,
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_validated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            PRIMARY KEY  (id),
            UNIQUE KEY idx_activation_id (activation_id),
            KEY idx_license_id (license_id),
            KEY idx_site_url (site_url(191)),
            KEY idx_status (status),
            UNIQUE KEY idx_license_site (license_id, site_url(191))
        ) {$charset_collate};

        CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_license_id (license_id),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Drop all plugin tables (for uninstall).
     *
     * @return void
     */
    public static function drop_tables() {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_license_logs" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_activations" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_licenses" );
        // phpcs:enable

        delete_option( self::DB_VERSION_OPTION );
    }

    /**
     * Check if schema needs migration and run if necessary.
     *
     * @return void
     */
    public static function maybe_migrate() {
        $installed = get_option( self::DB_VERSION_OPTION, '0' );
        if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
            self::create_tables();
        }
    }
}
