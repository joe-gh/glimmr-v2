<?php
/**
 * License CRUD and activation logic.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Manager
 *
 * Core business logic for license management: creation, activation,
 * deactivation, validation, and status changes.
 */
class Glimmr_Licensing_Manager {

    /**
     * Plan site limits mapping.
     *
     * @var array
     */
    const PLAN_LIMITS = array(
        'plan_1'         => 1,
        'plan_10'        => 10,
        'plan_100'       => 100,
        'plan_unlimited' => 0, // 0 = unlimited.
    );

    /**
     * Create a new license.
     *
     * @param array $args {
     *     License arguments.
     *     @type string      $customer_email  Required.
     *     @type string      $customer_name   Required.
     *     @type string      $plan            Plan identifier (default: plan_1).
     *     @type int|null    $order_id        WooCommerce order ID.
     *     @type int|null    $subscription_id WooCommerce subscription ID.
     *     @type string|null $expiry_date     Expiry date (Y-m-d H:i:s) or null for lifetime.
     * }
     * @return array|WP_Error License data on success, WP_Error on failure.
     */
    public function create_license( $args ) {
        global $wpdb;

        $defaults = array(
            'customer_email'  => '',
            'customer_name'   => '',
            'plan'            => 'plan_1',
            'order_id'        => null,
            'subscription_id' => null,
            'expiry_date'     => null,
        );
        $args = wp_parse_args( $args, $defaults );

        if ( empty( $args['customer_email'] ) || empty( $args['customer_name'] ) ) {
            return new WP_Error( 'missing_fields', __( 'Customer email and name are required.', 'glimmr-licensing' ) );
        }

        if ( ! is_email( $args['customer_email'] ) ) {
            return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'glimmr-licensing' ) );
        }

        $plan       = sanitize_text_field( $args['plan'] );
        $site_limit = self::PLAN_LIMITS[ $plan ] ?? 1;

        // Generate a unique license key.
        $license_key = Glimmr_Licensing_Key_Generator::generate();

        // Ensure uniqueness (extremely unlikely collision but check anyway).
        $max_attempts = 5;
        $key_unique   = false;
        for ( $i = 0; $i < $max_attempts; $i++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses WHERE license_key = %s",
                $license_key
            ) );
            if ( '0' === $exists ) {
                $key_unique = true;
                break;
            }
            $license_key = Glimmr_Licensing_Key_Generator::generate();
        }

        if ( ! $key_unique ) {
            return new WP_Error( 'key_collision', __( 'Failed to generate unique license key. Please try again.', 'glimmr-licensing' ) );
        }

        // Build data array, handling NULLable columns properly.
        $data    = array(
            'license_key'    => $license_key,
            'customer_email' => sanitize_email( $args['customer_email'] ),
            'customer_name'  => sanitize_text_field( $args['customer_name'] ),
            'plan'           => $plan,
            'site_limit'     => $site_limit,
            'status'         => 'active',
        );
        $formats = array( '%s', '%s', '%s', '%s', '%d', '%s' );

        // NULLable integer columns — only include if non-null so they get NULL in the DB.
        if ( ! empty( $args['order_id'] ) ) {
            $data['order_id'] = absint( $args['order_id'] );
            $formats[]        = '%d';
        }
        if ( ! empty( $args['subscription_id'] ) ) {
            $data['subscription_id'] = absint( $args['subscription_id'] );
            $formats[]               = '%d';
        }
        // NULLable datetime column — validate format.
        if ( ! empty( $args['expiry_date'] ) ) {
            $expiry_ts = strtotime( $args['expiry_date'] );
            if ( false === $expiry_ts || $expiry_ts < 0 ) {
                return new WP_Error( 'invalid_date', __( 'Invalid expiry date format.', 'glimmr-licensing' ) );
            }
            $data['expiry_date'] = gmdate( 'Y-m-d H:i:s', $expiry_ts );
            $formats[]           = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert( $wpdb->prefix . 'glimmr_licenses', $data, $formats );

        if ( false === $inserted ) {
            return new WP_Error( 'db_error', __( 'Failed to create license.', 'glimmr-licensing' ) );
        }

        $license_id = $wpdb->insert_id;

        $this->log_action( $license_id, 'created', array(
            'plan'       => $plan,
            'site_limit' => $site_limit,
            'order_id'   => $args['order_id'],
        ) );

        return array(
            'id'          => $license_id,
            'license_key' => $license_key,
            'plan'        => $plan,
            'site_limit'  => $site_limit,
            'status'      => 'active',
            'expiry_date' => isset( $data['expiry_date'] ) ? $data['expiry_date'] : null,
        );
    }

    /**
     * Get a license by its key with timing-safe verification.
     *
     * Retrieves by key prefix (first segment), then verifies the full
     * key using hash_equals() to prevent timing-based enumeration.
     *
     * @param string $license_key The license key.
     * @return object|null License row or null.
     */
    public function get_license_by_key( $license_key ) {
        global $wpdb;

        // Extract the first segment (prefix + first group, e.g., "GLMR-XXXX") for lookup.
        $parts = explode( '-', $license_key );
        if ( count( $parts ) < 2 ) {
            return null;
        }
        $prefix = $parts[0] . '-' . $parts[1];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_licenses WHERE license_key LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        ) );

        if ( empty( $candidates ) ) {
            return null;
        }

        // Timing-safe comparison against all candidates.
        foreach ( $candidates as $candidate ) {
            if ( hash_equals( $candidate->license_key, $license_key ) ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Get a license by ID.
     *
     * @param int $license_id License ID.
     * @return object|null License row or null.
     */
    public function get_license( $license_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_licenses WHERE id = %d",
            $license_id
        ) );
    }

    /**
     * Activate a license on a site.
     *
     * If the site is already activated for this license, returns the
     * existing activation (idempotent).
     *
     * @param string $license_key License key.
     * @param string $site_url    Site URL.
     * @param string $site_name   Site name.
     * @param array  $environment Environment data.
     * @param string $ip_address  Client IP.
     * @return array|WP_Error Activation result or error.
     */
    public function activate( $license_key, $site_url, $site_name, $environment = array(), $ip_address = '' ) {
        global $wpdb;

        $license = $this->get_license_by_key( $license_key );

        if ( ! $license ) {
            return new WP_Error( 'invalid_key', __( 'License key not found.', 'glimmr-licensing' ) );
        }

        if ( 'expired' === $license->status ) {
            return new WP_Error( 'expired', __( 'License has expired.', 'glimmr-licensing' ) );
        }

        if ( 'suspended' === $license->status ) {
            return new WP_Error( 'suspended', __( 'License has been suspended.', 'glimmr-licensing' ) );
        }

        if ( 'cancelled' === $license->status ) {
            return new WP_Error( 'cancelled', __( 'License has been cancelled.', 'glimmr-licensing' ) );
        }

        if ( 'active' !== $license->status ) {
            return new WP_Error( 'invalid_status', __( 'License is not active.', 'glimmr-licensing' ) );
        }

        // Check expiry date.
        if ( $license->expiry_date && strtotime( $license->expiry_date ) < time() ) {
            // Mark as expired.
            $this->update_license_status( $license->id, 'expired' );
            return new WP_Error( 'expired', __( 'License has expired.', 'glimmr-licensing' ) );
        }

        // Normalize site URL.
        $site_url = $this->normalize_url( $site_url );

        // Check for existing activation on this site.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_activations
             WHERE license_id = %d AND site_url = %s AND status = 'active'",
            $license->id,
            $site_url
        ) );

        if ( $existing ) {
            // Update last validated timestamp and environment.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'glimmr_activations',
                array(
                    'last_validated_at' => current_time( 'mysql', true ),
                    'environment'       => wp_json_encode( $environment ),
                    'ip_address'        => $ip_address,
                ),
                array( 'id' => $existing->id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            $active_count = $this->get_active_activation_count( $license->id );

            return array(
                'success'          => true,
                'activation_id'    => $existing->activation_id,
                'plan'             => $license->plan,
                'site_limit'       => (int) $license->site_limit,
                'activations_used' => $active_count,
                'expiry'           => $license->expiry_date ? gmdate( 'c', strtotime( $license->expiry_date ) ) : null,
            );
        }

        // Use a transaction with row-level lock to prevent race conditions.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( 'START TRANSACTION' );

        // Lock the license row to serialize concurrent activation requests.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}glimmr_licenses WHERE id = %d FOR UPDATE",
            $license->id
        ) );

        // Check activation limit under lock.
        $active_count = $this->get_active_activation_count( $license->id );
        if ( (int) $license->site_limit > 0 && $active_count >= (int) $license->site_limit ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'limit_reached', sprintf(
                /* translators: 1: current count, 2: limit */
                __( 'Activation limit reached (%1$d of %2$d sites used).', 'glimmr-licensing' ),
                $active_count,
                $license->site_limit
            ) );
        }

        // Create new activation.
        $activation_id = wp_generate_uuid4();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'glimmr_activations',
            array(
                'license_id'    => $license->id,
                'activation_id' => $activation_id,
                'site_url'      => $site_url,
                'site_name'     => sanitize_text_field( $site_name ),
                'ip_address'    => $ip_address,
                'environment'   => wp_json_encode( $environment ),
                'status'        => 'active',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_error', __( 'Failed to create activation.', 'glimmr-licensing' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( 'COMMIT' );

        $this->log_action( $license->id, 'activated', array(
            'site_url'      => $site_url,
            'activation_id' => $activation_id,
        ), $ip_address );

        return array(
            'success'          => true,
            'activation_id'    => $activation_id,
            'plan'             => $license->plan,
            'site_limit'       => (int) $license->site_limit,
            'activations_used' => $active_count + 1,
            'expiry'           => $license->expiry_date ? gmdate( 'c', strtotime( $license->expiry_date ) ) : null,
        );
    }

    /**
     * Deactivate a license from a site.
     *
     * @param string $license_key   License key.
     * @param string $activation_id Activation UUID.
     * @param string $site_url      Site URL.
     * @param string $ip_address    Client IP.
     * @return array|WP_Error Result or error.
     */
    public function deactivate( $license_key, $activation_id, $site_url, $ip_address = '' ) {
        global $wpdb;

        $license = $this->get_license_by_key( $license_key );
        if ( ! $license ) {
            return new WP_Error( 'invalid_key', __( 'License key not found.', 'glimmr-licensing' ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $wpdb->prefix . 'glimmr_activations',
            array( 'status' => 'deactivated' ),
            array(
                'license_id'    => $license->id,
                'activation_id' => sanitize_text_field( $activation_id ),
                'status'        => 'active',
            ),
            array( '%s' ),
            array( '%d', '%s', '%s' )
        );

        if ( 0 === $updated ) {
            return new WP_Error( 'not_found', __( 'Activation not found or already deactivated.', 'glimmr-licensing' ) );
        }

        $this->log_action( $license->id, 'deactivated', array(
            'site_url'      => $site_url,
            'activation_id' => $activation_id,
        ), $ip_address );

        return array(
            'success' => true,
            'message' => __( 'Site deactivated successfully.', 'glimmr-licensing' ),
        );
    }

    /**
     * Validate a license activation.
     *
     * @param string $license_key   License key.
     * @param string $activation_id Activation UUID.
     * @param string $site_url      Site URL.
     * @param string $ip_address    Client IP.
     * @return array Validation result.
     */
    public function validate( $license_key, $activation_id, $site_url, $ip_address = '' ) {
        global $wpdb;

        $license = $this->get_license_by_key( $license_key );
        if ( ! $license ) {
            return array( 'valid' => false, 'error' => 'invalid_key', 'message' => __( 'License key not found.', 'glimmr-licensing' ) );
        }

        // Check expiry.
        if ( $license->expiry_date && strtotime( $license->expiry_date ) < time() ) {
            if ( 'active' === $license->status ) {
                $this->update_license_status( $license->id, 'expired' );
            }
            return array( 'valid' => false, 'error' => 'expired', 'message' => __( 'License has expired.', 'glimmr-licensing' ) );
        }

        if ( 'active' !== $license->status ) {
            return array( 'valid' => false, 'error' => $license->status, 'message' => sprintf(
                /* translators: %s: license status */
                __( 'License is %s.', 'glimmr-licensing' ),
                $license->status
            ) );
        }

        // Check the specific activation.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $activation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_activations
             WHERE license_id = %d AND activation_id = %s",
            $license->id,
            sanitize_text_field( $activation_id )
        ) );

        if ( ! $activation ) {
            return array( 'valid' => false, 'error' => 'activation_not_found', 'message' => __( 'Activation not found.', 'glimmr-licensing' ) );
        }

        if ( 'active' !== $activation->status ) {
            return array( 'valid' => false, 'error' => 'deactivated', 'message' => __( 'This activation has been deactivated.', 'glimmr-licensing' ) );
        }

        // Update last_validated_at.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $wpdb->prefix . 'glimmr_activations',
            array( 'last_validated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $activation->id ),
            array( '%s' ),
            array( '%d' )
        );

        $this->log_action( $license->id, 'validated', array(
            'site_url'      => $site_url,
            'activation_id' => $activation_id,
        ), $ip_address );

        $active_count = $this->get_active_activation_count( $license->id );

        return array(
            'valid'            => true,
            'plan'             => $license->plan,
            'site_limit'       => (int) $license->site_limit,
            'activations_used' => $active_count,
            'expiry'           => $license->expiry_date ? gmdate( 'c', strtotime( $license->expiry_date ) ) : null,
        );
    }

    /**
     * Update a license's status.
     *
     * @param int    $license_id License ID.
     * @param string $status     New status.
     * @return bool True on success.
     */
    public function update_license_status( $license_id, $status ) {
        global $wpdb;

        $allowed = array( 'active', 'expired', 'cancelled', 'suspended' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $wpdb->prefix . 'glimmr_licenses',
            array( 'status' => $status ),
            array( 'id' => $license_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $updated ) {
            $this->log_action( $license_id, $status, array() );

            // When a license is deactivated, mark all its active site activations as deactivated too.
            if ( in_array( $status, array( 'suspended', 'cancelled', 'expired' ), true ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $wpdb->prefix . 'glimmr_activations',
                    array( 'status' => 'deactivated' ),
                    array( 'license_id' => $license_id, 'status' => 'active' ),
                    array( '%s' ),
                    array( '%d', '%s' )
                );
            }
        }

        return (bool) $updated;
    }

    /**
     * Extend a license's expiry date.
     *
     * @param int    $license_id  License ID.
     * @param string $expiry_date New expiry date (Y-m-d H:i:s).
     * @return bool True on success.
     */
    public function extend_expiry( $license_id, $expiry_date ) {
        global $wpdb;

        // Validate expiry date format.
        $expiry_ts = strtotime( $expiry_date );
        if ( false === $expiry_ts || $expiry_ts < 0 ) {
            return false;
        }
        $expiry_date = gmdate( 'Y-m-d H:i:s', $expiry_ts );

        // Only reactivate if the license is currently expired — do not override
        // manual admin actions like suspension or cancellation.
        $license = $this->get_license( $license_id );
        if ( ! $license ) {
            return false;
        }

        $data    = array( 'expiry_date' => $expiry_date );
        $formats = array( '%s' );

        if ( 'expired' === $license->status ) {
            $data['status'] = 'active';
            $formats[]      = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $wpdb->prefix . 'glimmr_licenses',
            $data,
            array( 'id' => $license_id ),
            $formats,
            array( '%d' )
        );

        if ( $updated ) {
            $this->log_action( $license_id, 'expiry_extended', array( 'new_expiry' => $expiry_date ) );
        }

        return (bool) $updated;
    }

    /**
     * Update a license's editable fields.
     *
     * Allows admins to modify customer info, plan, site limit, and expiry.
     *
     * @param int   $license_id License ID.
     * @param array $args {
     *     Fields to update.
     *     @type string      $customer_name  Customer name.
     *     @type string      $customer_email Customer email.
     *     @type string      $plan           Plan identifier.
     *     @type int|null    $site_limit     Custom site limit override (null = use plan default).
     *     @type string|null $expiry_date    Expiry date (Y-m-d) or empty/null for lifetime.
     * }
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function update_license( $license_id, $args ) {
        global $wpdb;

        $license = $this->get_license( $license_id );
        if ( ! $license ) {
            return new WP_Error( 'not_found', __( 'License not found.', 'glimmr-licensing' ) );
        }

        $data    = array();
        $formats = array();
        $changes = array();

        // Customer name.
        if ( isset( $args['customer_name'] ) && '' !== $args['customer_name'] ) {
            $data['customer_name'] = sanitize_text_field( $args['customer_name'] );
            $formats[]             = '%s';
            $changes['customer_name'] = $data['customer_name'];
        }

        // Customer email.
        if ( isset( $args['customer_email'] ) && '' !== $args['customer_email'] ) {
            if ( ! is_email( $args['customer_email'] ) ) {
                return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'glimmr-licensing' ) );
            }
            $data['customer_email'] = sanitize_email( $args['customer_email'] );
            $formats[]              = '%s';
            $changes['customer_email'] = $data['customer_email'];
        }

        // Plan.
        if ( isset( $args['plan'] ) && '' !== $args['plan'] ) {
            $plan = sanitize_text_field( $args['plan'] );
            if ( ! array_key_exists( $plan, self::PLAN_LIMITS ) ) {
                return new WP_Error( 'invalid_plan', __( 'Invalid plan.', 'glimmr-licensing' ) );
            }
            $data['plan'] = $plan;
            $formats[]    = '%s';
            $changes['plan'] = $plan;

            // Update site_limit to match plan default unless a custom override is provided.
            if ( ! isset( $args['site_limit'] ) ) {
                $data['site_limit'] = self::PLAN_LIMITS[ $plan ];
                $formats[]          = '%d';
                $changes['site_limit'] = $data['site_limit'];
            }
        }

        // Custom site limit override.
        if ( isset( $args['site_limit'] ) ) {
            $data['site_limit'] = absint( $args['site_limit'] );
            $formats[]          = '%d';
            $changes['site_limit'] = $data['site_limit'];
        }

        // Expiry date — empty means lifetime (NULL).
        if ( array_key_exists( 'expiry_date', $args ) ) {
            if ( empty( $args['expiry_date'] ) ) {
                // Set to NULL for lifetime.
                $data['expiry_date'] = null;
                $formats[]           = null; // Handled via raw query below.
                $changes['expiry_date'] = 'lifetime';
            } else {
                $expiry_ts = strtotime( $args['expiry_date'] );
                if ( false === $expiry_ts || $expiry_ts < 0 ) {
                    return new WP_Error( 'invalid_date', __( 'Invalid expiry date.', 'glimmr-licensing' ) );
                }
                $data['expiry_date'] = gmdate( 'Y-m-d H:i:s', $expiry_ts );
                $formats[]           = '%s';
                $changes['expiry_date'] = $data['expiry_date'];
            }
        }

        if ( empty( $data ) ) {
            return new WP_Error( 'no_changes', __( 'No fields to update.', 'glimmr-licensing' ) );
        }

        // Handle NULL expiry_date specially since wpdb->update can't set NULL via format.
        if ( array_key_exists( 'expiry_date', $data ) && null === $data['expiry_date'] ) {
            // Remove expiry_date from the regular update and handle separately.
            unset( $data['expiry_date'] );
            // Remove the null format entry.
            $formats = array_values( array_filter( $formats, function( $f ) { return null !== $f; } ) );

            // Set expiry_date to NULL directly.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}glimmr_licenses SET expiry_date = NULL WHERE id = %d",
                $license_id
            ) );
        }

        // Update remaining fields if any.
        if ( ! empty( $data ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $updated = $wpdb->update(
                $wpdb->prefix . 'glimmr_licenses',
                $data,
                array( 'id' => $license_id ),
                $formats,
                array( '%d' )
            );

            if ( false === $updated ) {
                return new WP_Error( 'db_error', __( 'Failed to update license.', 'glimmr-licensing' ) );
            }
        }

        $this->log_action( $license_id, 'updated', $changes );

        return true;
    }

    /**
     * Get the number of active activations for a license.
     *
     * @param int $license_id License ID.
     * @return int Active activation count.
     */
    public function get_active_activation_count( $license_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_activations
             WHERE license_id = %d AND status = 'active'",
            $license_id
        ) );
    }

    /**
     * Get all activations for a license.
     *
     * @param int $license_id License ID.
     * @return array Activation rows.
     */
    public function get_activations( $license_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_activations
             WHERE license_id = %d ORDER BY activated_at DESC",
            $license_id
        ) );
    }

    /**
     * Deactivate a specific activation by its ID (admin action).
     *
     * @param int $activation_row_id The activations table row ID.
     * @return bool True on success.
     */
    public function deactivate_activation( $activation_row_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $activation = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_activations WHERE id = %d",
            $activation_row_id
        ) );

        if ( ! $activation ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $wpdb->prefix . 'glimmr_activations',
            array( 'status' => 'deactivated' ),
            array( 'id' => $activation_row_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $updated ) {
            $this->log_action( $activation->license_id, 'deactivated', array(
                'site_url'      => $activation->site_url,
                'activation_id' => $activation->activation_id,
                'admin_action'  => true,
            ) );
        }

        return (bool) $updated;
    }

    /**
     * Get licenses with optional filtering.
     *
     * @param array $args {
     *     Query arguments.
     *     @type string $status  Filter by status.
     *     @type string $plan    Filter by plan.
     *     @type string $search  Search by email or license key.
     *     @type int    $per_page Items per page.
     *     @type int    $page     Page number.
     *     @type string $orderby  Column to order by.
     *     @type string $order    ASC or DESC.
     * }
     * @return array{ items: array, total: int }
     */
    public function get_licenses( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'   => '',
            'plan'     => '',
            'search'   => '',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'l.status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['plan'] ) ) {
            $where[]  = 'l.plan = %s';
            $values[] = sanitize_text_field( $args['plan'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(l.customer_email LIKE %s OR l.license_key LIKE %s)';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode( ' AND ', $where );

        // Whitelist orderby column.
        $allowed_orderby = array( 'created_at', 'customer_email', 'plan', 'status', 'expiry_date' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = absint( $args['per_page'] );
        $offset   = ( absint( $args['page'] ) - 1 ) * $per_page;

        // Get total count.
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses l WHERE {$where_clause}";
        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Get items.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $query = "SELECT l.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_activations a
                     WHERE a.license_id = l.id AND a.status = 'active') AS active_sites
                  FROM {$wpdb->prefix}glimmr_licenses l
                  WHERE {$where_clause}
                  ORDER BY l.{$orderby} {$order}
                  LIMIT %d OFFSET %d";

        $all_values   = array_merge( $values, array( $per_page, $offset ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $items = $wpdb->get_results( $wpdb->prepare( $query, ...$all_values ) );

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    /**
     * Delete a license and all its activations.
     *
     * @param int $license_id License ID.
     * @return bool True on success.
     */
    public function delete_license( $license_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . 'glimmr_activations', array( 'license_id' => $license_id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . 'glimmr_license_logs', array( 'license_id' => $license_id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete( $wpdb->prefix . 'glimmr_licenses', array( 'id' => $license_id ), array( '%d' ) );

        return (bool) $deleted;
    }

    /**
     * Expire any licenses past their expiry date.
     *
     * Called by daily cron.
     *
     * @return int Number of licenses expired.
     */
    public function expire_past_due_licenses() {
        global $wpdb;

        // Find licenses past their expiry date.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $expired_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}glimmr_licenses
             WHERE status = %s AND expiry_date IS NOT NULL AND expiry_date < NOW()",
            'active'
        ) );

        if ( empty( $expired_ids ) ) {
            return 0;
        }

        // Expire each individually so log entries are created.
        foreach ( $expired_ids as $id ) {
            $this->update_license_status( (int) $id, 'expired' );
        }

        return count( $expired_ids );
    }

    /**
     * Get activity log entries for a license.
     *
     * @param int $license_id License ID.
     * @param int $limit      Number of entries.
     * @return array Log entries.
     */
    public function get_logs( $license_id, $limit = 50 ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_license_logs
             WHERE license_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $license_id,
            $limit
        ) );
    }

    /**
     * Get dashboard stats.
     *
     * @return array Stats array.
     */
    public function get_stats() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses WHERE status = 'active'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses WHERE status = 'expired'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $suspended = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_licenses WHERE status = 'suspended'" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total_activations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_activations WHERE status = 'active'" );

        return array(
            'total'             => $total,
            'active'            => $active,
            'expired'           => $expired,
            'suspended'         => $suspended,
            'total_activations' => $total_activations,
        );
    }

    /**
     * Get the first license by order ID.
     *
     * For orders with multiple licenses, use get_licenses_by_order() instead.
     *
     * @param int $order_id WooCommerce order ID.
     * @return object|null License row or null.
     */
    public function get_license_by_order( $order_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_licenses WHERE order_id = %d LIMIT 1",
            $order_id
        ) );
    }

    /**
     * Get all licenses for an order.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array License rows.
     */
    public function get_licenses_by_order( $order_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_licenses WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ) );
    }

    /**
     * Get licenses by customer email.
     *
     * @param string $email Customer email.
     * @return array License rows.
     */
    public function get_licenses_by_email( $email ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}glimmr_licenses WHERE customer_email = %s ORDER BY created_at DESC",
            sanitize_email( $email )
        ) );
    }

    /**
     * Log an action for a license.
     *
     * @param int    $license_id License ID (use 0 for dev key actions).
     * @param string $action     Action name.
     * @param array  $details    Context data.
     * @param string $ip_address Client IP.
     * @return void
     */
    public function log_action( $license_id, $action, $details = array(), $ip_address = '' ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'glimmr_license_logs',
            array(
                'license_id' => $license_id,
                'action'     => sanitize_text_field( $action ),
                'details'    => wp_json_encode( $details ),
                'ip_address' => sanitize_text_field( $ip_address ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Normalize a URL for consistent comparison.
     *
     * Strips trailing slashes, scheme, and www prefix.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    private function normalize_url( $url ) {
        $url = strtolower( trim( $url ) );
        $url = untrailingslashit( $url );
        // Normalize scheme.
        $url = preg_replace( '#^https?://#', 'https://', $url );
        // Strip www prefix so www.example.com and example.com are treated as the same site.
        $url = preg_replace( '#^(https://)www\.#', '$1', $url );
        return $url;
    }
}
