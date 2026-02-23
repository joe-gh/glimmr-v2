<?php
/**
 * Product Indexer
 *
 * Manages the product index table for fast SQL-based product searches.
 * Syncs WooCommerce products to a denormalized index optimized for AI queries.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Product_Indexer
 *
 * Handles:
 * - Full product catalog indexing
 * - Incremental sync (changed products only)
 * - Include/exclude filters
 * - SQL-based product search
 */
class Glimmr_AI_Product_Indexer {

    /**
     * Default meta keys to include in search index.
     *
     * These common product meta fields will be searchable by default.
     * Admins can customize this list in settings, and developers can
     * filter it using 'glimmr_ai_searchable_meta_keys'.
     *
     * @var array
     */
    /**
     * Meta key patterns to EXCLUDE from indexing (minimal blocklist).
     *
     * We use a dynamic discovery approach - get ALL meta and only exclude
     * truly internal system data. This ensures we capture custom fields,
     * ACF fields, and any plugin-added fields automatically.
     *
     * Only block: edit locks, cache keys, tracking pixels, serialized internals.
     * Do NOT block: prices, weights, SEO fields, or anything potentially useful.
     *
     * @var array
     */
    const META_KEYS_BLOCKLIST_PATTERNS = array(
        // WordPress internal system fields
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_desired_post_slug',
        '_encloseme',
        '_pingme',
        // Transients and cache (pattern matching)
        '_transient',
        '_site_transient',
        // Tracking pixels / analytics IDs (not searchable text)
        '_fbp',
        '_fbc',
        '_ga',
        '_gtm',
        '_gla_',  // Google Listings & Ads
        // WooCommerce internal computed fields (already captured via API)
        '_product_version',
        // Yoast internal scores (keep the actual SEO content fields!)
        '_yoast_wpseo_linkdex',
        '_yoast_wpseo_content_score',
        '_yoast_wpseo_estimated-reading-time-minutes',
        '_yoast_wpseo_wordproof_timestamp',
        // Rank Math internal scores
        'rank_math_internal_links_processed',
        'rank_math_analytic_object_id',
        'rank_math_seo_score',
        // Page builder serialized data (not human-readable)
        '_elementor_data',
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_version',
        '_elementor_pro_version',
        '_elementor_css',
        '_wpb_vc_js_status',
        '_wpb_shortcodes_custom_css',
        // Publicize/sharing internals
        '_publicize',
        '_jetpack_dont_email',
    );

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
     * Batch size for indexing.
     *
     * @var int
     */
    private $batch_size = 100;

    /**
     * Pre-loaded taxonomy data cache.
     *
     * @var array
     */
    private $taxonomy_cache = array();

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_Settings $settings Settings instance.
     */
    public function __construct( $database, $settings ) {
        $this->database   = $database;
        $this->settings   = $settings;
        $this->batch_size = (int) $settings->get( 'product_sync_batch_size', 100 );
    }

    /**
     * Get the list of meta keys to include in search index.
     *
     * Uses dynamic discovery - gets ALL meta keys for a product and filters
     * out only the blocklisted internal system keys. This ensures we capture
     * custom fields, ACF fields, and any plugin-added fields automatically.
     *
     * @param int $product_id Product ID to discover meta keys for.
     * @return array Array of meta key strings to include.
     */
    public function get_searchable_meta_keys( $product_id ) {
        global $wpdb;

        // Get all meta keys for this specific product.
        $all_meta_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d",
                $product_id
            )
        );

        if ( empty( $all_meta_keys ) ) {
            return array();
        }

        // Filter out blocklisted keys.
        $meta_keys = array();
        foreach ( $all_meta_keys as $key ) {
            if ( ! $this->is_meta_key_blocklisted( $key ) ) {
                $meta_keys[] = $key;
            }
        }

        // Get admin-configured keys to explicitly exclude.
        $excluded_keys = $this->settings->get( 'searchable_meta_keys_excluded', array() );
        if ( ! empty( $excluded_keys ) ) {
            if ( is_string( $excluded_keys ) ) {
                $excluded_keys = array_map( 'trim', explode( ',', $excluded_keys ) );
            }
            $meta_keys = array_diff( $meta_keys, $excluded_keys );
        }

        // Remove duplicates and empty values.
        $meta_keys = array_unique( array_filter( $meta_keys ) );

        /**
         * Filter the meta keys included in product search indexing.
         *
         * @since 1.0.0
         *
         * @param array $meta_keys  Array of meta key strings.
         * @param int   $product_id The product ID being indexed.
         */
        return apply_filters( 'glimmr_ai_searchable_meta_keys', $meta_keys, $product_id );
    }

    /**
     * Check if a meta key should be blocklisted (excluded from indexing).
     *
     * Uses pattern matching to exclude internal system data while keeping
     * all potentially useful content like prices, weights, SEO fields, etc.
     *
     * @param string $meta_key The meta key to check.
     * @return bool True if the key should be excluded, false to include it.
     */
    private function is_meta_key_blocklisted( $meta_key ) {
        // Check exact matches and prefix patterns.
        foreach ( self::META_KEYS_BLOCKLIST_PATTERNS as $pattern ) {
            // Exact match.
            if ( $meta_key === $pattern ) {
                return true;
            }
            // Prefix match (for patterns like '_transient', '_elementor').
            if ( strpos( $meta_key, $pattern ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get meta values for a product to include in search text.
     *
     * Uses dynamic discovery to get ALL meta values for a product,
     * filtering out only blocklisted internal system keys. Safely
     * handles any data type (arrays, objects, serialized data) by
     * converting to searchable text.
     *
     * @param int $product_id Product ID.
     * @return array Array of searchable text values.
     */
    private function get_searchable_meta_values( $product_id ) {
        $meta_keys = $this->get_searchable_meta_keys( $product_id );
        $values = array();

        foreach ( $meta_keys as $key ) {
            try {
                $value = get_post_meta( $product_id, $key, true );

                if ( empty( $value ) ) {
                    continue;
                }

                // Convert any data type to searchable text.
                $text_value = $this->convert_meta_value_to_text( $value, $key );

                if ( ! empty( $text_value ) ) {
                    $values[] = $text_value;
                }
            } catch ( \Throwable $e ) {
                // Log but don't crash - skip this meta key.
                if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                    Glimmr_AI_Logger::warning(
                        'Failed to process meta value for indexing',
                        array(
                            'product_id' => $product_id,
                            'meta_key'   => $key,
                            'error'      => $e->getMessage(),
                        ),
                        'product-indexer'
                    );
                }
            }
        }

        return $values;
    }

    /**
     * Convert any meta value to searchable text.
     *
     * Handles strings, arrays, objects, serialized data, and nested
     * structures. Also handles ACF-specific field types like relationship
     * and image fields. Always returns a clean string or empty string.
     *
     * @param mixed  $value    The meta value (any type).
     * @param string $meta_key The meta key (for context in nested values).
     * @return string Searchable text representation.
     */
    private function convert_meta_value_to_text( $value, $meta_key = '' ) {
        // Handle null/empty.
        if ( $value === null || $value === '' || $value === false ) {
            return '';
        }

        // Handle scalar types (string, int, float, bool).
        if ( is_scalar( $value ) ) {
            // Skip ACF field reference keys (underscore-prefixed keys with "field_" values).
            if ( is_string( $value ) && strpos( $value, 'field_' ) === 0 && strpos( $meta_key, '_' ) === 0 ) {
                return '';
            }

            // Check if it's serialized data that wasn't unserialized.
            if ( is_string( $value ) && $this->is_serialized_string( $value ) ) {
                $unserialized = @unserialize( $value, array( 'allowed_classes' => false ) );
                if ( $unserialized !== false ) {
                    return $this->convert_meta_value_to_text( $unserialized, $meta_key );
                }
            }

            // Check if it's JSON.
            if ( is_string( $value ) && $this->is_json_string( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( $decoded !== null ) {
                    return $this->convert_meta_value_to_text( $decoded, $meta_key );
                }
            }

            // Check if it's a numeric ID that might be a post/attachment reference.
            // Handle ACF relationship/post object/image fields.
            if ( is_numeric( $value ) && (int) $value > 0 ) {
                $text = $this->try_get_referenced_content( (int) $value, $meta_key );
                if ( ! empty( $text ) ) {
                    return $text;
                }
            }

            // Regular string/number - clean it up.
            $clean = trim( wp_strip_all_tags( (string) $value ) );

            // Skip if it looks like binary/encoded data.
            if ( strlen( $clean ) > 0 && ! $this->looks_like_binary_data( $clean ) ) {
                return $clean;
            }

            return '';
        }

        // Handle arrays.
        if ( is_array( $value ) ) {
            return $this->convert_array_to_text( $value );
        }

        // Handle objects.
        if ( is_object( $value ) ) {
            // Try to convert to array first.
            if ( method_exists( $value, 'to_array' ) ) {
                return $this->convert_array_to_text( $value->to_array() );
            }

            // Cast to array.
            return $this->convert_array_to_text( (array) $value );
        }

        // Unknown type - return empty.
        return '';
    }

    /**
     * Convert an array to searchable text.
     *
     * Extracts all string values from nested arrays and combines them.
     * Skips numeric keys that might be internal IDs.
     *
     * @param array $array The array to convert.
     * @return string Combined text from array values.
     */
    private function convert_array_to_text( $array ) {
        $text_parts = array();

        foreach ( $array as $key => $value ) {
            // Skip array entries that look like internal IDs.
            if ( is_string( $key ) && in_array( strtolower( $key ), array( 'id', '_id', 'post_id', 'term_id' ), true ) ) {
                continue;
            }

            // Recursively convert nested values.
            $text = $this->convert_meta_value_to_text( $value, is_string( $key ) ? $key : '' );

            if ( ! empty( $text ) ) {
                $text_parts[] = $text;
            }
        }

        return implode( ' ', $text_parts );
    }

    /**
     * Check if a string looks like serialized PHP data.
     *
     * @param string $value The string to check.
     * @return bool True if it appears to be serialized.
     */
    private function is_serialized_string( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) < 4 ) {
            return false;
        }

        // Common serialization patterns.
        $first_two = substr( $value, 0, 2 );
        return in_array( $first_two, array( 'a:', 's:', 'O:', 'i:', 'd:', 'b:' ), true );
    }

    /**
     * Check if a string looks like JSON.
     *
     * @param string $value The string to check.
     * @return bool True if it appears to be JSON.
     */
    private function is_json_string( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) < 2 ) {
            return false;
        }

        $first_char = $value[0];
        $last_char  = $value[ strlen( $value ) - 1 ];

        // JSON arrays or objects.
        return ( $first_char === '[' && $last_char === ']' ) ||
               ( $first_char === '{' && $last_char === '}' );
    }

    /**
     * Check if text looks like binary or encoded data (not useful for search).
     *
     * @param string $text The text to check.
     * @return bool True if it looks like binary/encoded data.
     */
    private function looks_like_binary_data( $text ) {
        // Very short strings might be valid.
        if ( strlen( $text ) < 50 ) {
            return false;
        }

        // Check for high ratio of non-printable or unusual characters.
        $non_readable = preg_match_all( '/[^\x20-\x7E\n\r\t]/', $text );
        $ratio = $non_readable / strlen( $text );

        // If more than 20% non-readable, probably binary.
        if ( $ratio > 0.2 ) {
            return true;
        }

        // Check for base64-like patterns (long strings with only alphanumeric+/=).
        if ( preg_match( '/^[A-Za-z0-9+\/=]{100,}$/', $text ) ) {
            return true;
        }

        return false;
    }

    /**
     * Try to get useful text content from a referenced post/attachment ID.
     *
     * Handles ACF relationship, post object, image, and file fields by
     * fetching the actual content from the referenced post/attachment.
     *
     * @param int    $id       The post/attachment ID.
     * @param string $meta_key The meta key (for context on field type).
     * @return string The referenced content, or empty string if not useful.
     */
    private function try_get_referenced_content( $id, $meta_key = '' ) {
        // Skip IDs that are too small (likely not valid post IDs).
        if ( $id < 1 ) {
            return '';
        }

        // Get the post to determine type.
        $post = get_post( $id );
        if ( ! $post ) {
            return '';
        }

        // Handle attachments (images, files).
        if ( $post->post_type === 'attachment' ) {
            $parts = array();

            // Get the filename.
            $filename = basename( get_attached_file( $id ) );
            if ( $filename ) {
                // Remove extension for cleaner search.
                $parts[] = pathinfo( $filename, PATHINFO_FILENAME );
            }

            // Get alt text (often descriptive).
            $alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
            if ( $alt ) {
                $parts[] = $alt;
            }

            // Get the title if it's not just the filename.
            if ( $post->post_title && $post->post_title !== $filename ) {
                $parts[] = $post->post_title;
            }

            // Get caption.
            if ( $post->post_excerpt ) {
                $parts[] = $post->post_excerpt;
            }

            return implode( ' ', array_filter( $parts ) );
        }

        // Handle other post types (ACF relationship/post object fields).
        // Return the title as searchable text.
        if ( in_array( $post->post_type, array( 'product', 'page', 'post' ), true ) ) {
            return $post->post_title;
        }

        // For custom post types, return title if it seems useful.
        if ( ! empty( $post->post_title ) && $post->post_status === 'publish' ) {
            return $post->post_title;
        }

        return '';
    }

    /**
     * Get all taxonomies registered for products.
     *
     * Dynamically discovers all taxonomies that apply to the 'product'
     * post type, including custom taxonomies created by plugins/themes.
     *
     * @return array Array of taxonomy names.
     */
    private function get_product_taxonomies() {
        $taxonomies = get_object_taxonomies( 'product', 'names' );

        // Ensure we always have the core WooCommerce taxonomies.
        if ( ! in_array( 'product_cat', $taxonomies, true ) ) {
            $taxonomies[] = 'product_cat';
        }
        if ( ! in_array( 'product_tag', $taxonomies, true ) ) {
            $taxonomies[] = 'product_tag';
        }

        /**
         * Filter the taxonomies to include in product indexing.
         *
         * @since 1.0.0
         *
         * @param array $taxonomies Array of taxonomy names.
         */
        return apply_filters( 'glimmr_ai_product_taxonomies', $taxonomies );
    }

    /**
     * Batch-load taxonomy data for product IDs.
     *
     * Pre-loads all taxonomy terms for multiple products at once
     * to avoid N+1 queries in the indexing loop. Dynamically handles
     * all taxonomies registered for products, not just categories/tags.
     *
     * @param array $product_ids Array of product IDs.
     * @return void
     */
    private function preload_taxonomy_data( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;

        // Get all product taxonomies dynamically.
        $taxonomies = $this->get_product_taxonomies();

        // Clear previous cache.
        $this->taxonomy_cache = array();
        foreach ( $taxonomies as $taxonomy ) {
            $this->taxonomy_cache[ $taxonomy ] = array();
        }

        // Skip if no taxonomies.
        if ( empty( $taxonomies ) ) {
            return;
        }

        // Prepare placeholders for product IDs.
        $id_placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

        // Prepare placeholders for taxonomies.
        $tax_placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

        // Merge params: taxonomies first, then product IDs.
        $params = array_merge( $taxonomies, $product_ids );

        // Load all taxonomies in a single bulk query.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.object_id, tt.taxonomy, t.name
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                 WHERE tt.taxonomy IN ({$tax_placeholders})
                 AND tr.object_id IN ({$id_placeholders})",
                $params
            )
        );

        foreach ( $results as $row ) {
            $product_id = (int) $row->object_id;
            $taxonomy   = $row->taxonomy;

            if ( ! isset( $this->taxonomy_cache[ $taxonomy ][ $product_id ] ) ) {
                $this->taxonomy_cache[ $taxonomy ][ $product_id ] = array();
            }
            $this->taxonomy_cache[ $taxonomy ][ $product_id ][] = $row->name;
        }
    }

    /**
     * Get cached taxonomy data for a product.
     *
     * @param int    $product_id Product ID.
     * @param string $taxonomy   Taxonomy name (product_cat or product_tag).
     * @return array Array of term names.
     */
    private function get_cached_taxonomy( $product_id, $taxonomy ) {
        if ( isset( $this->taxonomy_cache[ $taxonomy ][ $product_id ] ) ) {
            return $this->taxonomy_cache[ $taxonomy ][ $product_id ];
        }

        // Fallback to direct query if not cached.
        $terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
        return is_array( $terms ) ? $terms : array();
    }

    /**
     * Initialize hooks for real-time sync.
     */
    public function init_hooks() {
        // Product save/update.
        add_action( 'woocommerce_update_product', array( $this, 'index_product' ), 10, 1 );
        add_action( 'woocommerce_new_product', array( $this, 'index_product' ), 10, 1 );

        // Product delete.
        add_action( 'woocommerce_delete_product', array( $this, 'remove_product' ), 10, 1 );
        add_action( 'woocommerce_trash_product', array( $this, 'remove_product' ), 10, 1 );

        // Variation updates - reindex the parent product.
        add_action( 'woocommerce_update_product_variation', array( $this, 'index_variation_parent' ), 10, 1 );
        add_action( 'woocommerce_new_product_variation', array( $this, 'index_variation_parent' ), 10, 1 );
        add_action( 'woocommerce_delete_product_variation', array( $this, 'index_variation_parent' ), 10, 1 );
    }

    /**
     * Reindex parent product when a variation is updated.
     *
     * @param int $variation_id Variation ID.
     */
    public function index_variation_parent( $variation_id ) {
        $variation = wc_get_product( $variation_id );
        if ( $variation && $variation->get_parent_id() ) {
            $this->index_product( $variation->get_parent_id(), true );
        }
    }

    // =========================================================================
    // Full Sync
    // =========================================================================

    /**
     * Run full product sync.
     *
     * @param bool $force Force re-index all products.
     * @return array Sync results.
     */
    public function full_sync( $force = false ) {
        global $wpdb;

        $start_time = microtime( true );
        $site_id = get_current_blog_id();

        // Log sync start.
        $sync_log_id = $this->log_sync_start( 'products', 'manual' );

        $results = array(
            'success' => true,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        // Get all published products.
        $product_ids = $this->get_all_product_ids();
        $results['total'] = count( $product_ids );

        // Get existing index IDs for deletion detection.
        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $existing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT product_id FROM {$table} WHERE site_id = %d",
                $site_id
            )
        );
        $existing_ids = array_map( 'intval', $existing_ids );

        // Process in batches.
        $batches = array_chunk( $product_ids, $this->batch_size );
        $processed_ids = array();

        foreach ( $batches as $batch ) {
            // Preload taxonomy data for this batch to avoid N+1 queries.
            $this->preload_taxonomy_data( $batch );

            foreach ( $batch as $product_id ) {
                $result = $this->index_product( $product_id, $force );
                $processed_ids[] = $product_id;

                if ( 'created' === $result ) {
                    $results['created']++;
                } elseif ( 'updated' === $result ) {
                    $results['updated']++;
                } elseif ( 'skipped' === $result ) {
                    $results['skipped']++;
                } elseif ( 'error' === $result ) {
                    $results['errors']++;
                }
            }
        }

        // Remove products no longer in catalog.
        $deleted_ids = array_diff( $existing_ids, $processed_ids );
        foreach ( $deleted_ids as $deleted_id ) {
            $this->remove_product( $deleted_id );
            $results['deleted']++;
        }

        // Log sync completion.
        $this->log_sync_complete( $sync_log_id, $results );

        $results['duration'] = round( microtime( true ) - $start_time, 2 );

        return $results;
    }

    /**
     * Run incremental sync (changed products only).
     *
     * @return array Sync results.
     */
    public function incremental_sync() {
        global $wpdb;

        $start_time = microtime( true );
        $site_id = get_current_blog_id();

        $sync_log_id = $this->log_sync_start( 'products', 'schedule' );

        $results = array(
            'success' => true,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors'  => 0,
        );

        // Get last sync time.
        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $last_sync = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(last_synced_at) FROM {$table} WHERE site_id = %d",
                $site_id
            )
        );

        if ( ! $last_sync ) {
            // No previous sync, run full sync.
            return $this->full_sync();
        }

        // Get products modified since last sync.
        $modified_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('product', 'product_variation')
                 AND post_status = 'publish'
                 AND post_modified > %s",
                $last_sync
            )
        );

        if ( empty( $modified_ids ) ) {
            $results['message'] = __( 'No products modified since last sync.', 'glimmr-ai' );
            $this->log_sync_complete( $sync_log_id, $results );
            return $results;
        }

        $results['total'] = count( $modified_ids );

        // Process in batches.
        $batches = array_chunk( $modified_ids, $this->batch_size );

        foreach ( $batches as $batch ) {
            // Preload taxonomy data for this batch.
            $this->preload_taxonomy_data( $batch );

            foreach ( $batch as $product_id ) {
                $result = $this->index_product( $product_id, true );

                if ( 'created' === $result ) {
                    $results['created']++;
                } elseif ( 'updated' === $result ) {
                    $results['updated']++;
                } elseif ( 'skipped' === $result ) {
                    $results['skipped']++;
                } elseif ( 'error' === $result ) {
                    $results['errors']++;
                }
            }
        }

        // Check for deleted products.
        $deleted = $this->cleanup_deleted_products();
        $results['deleted'] = $deleted;

        $this->log_sync_complete( $sync_log_id, $results );

        $results['duration'] = round( microtime( true ) - $start_time, 2 );

        return $results;
    }

    // =========================================================================
    // Single Product Indexing
    // =========================================================================

    /**
     * Index a single product.
     *
     * @param int  $product_id WooCommerce product ID.
     * @param bool $force      Force update even if not changed.
     * @return string Result: 'created', 'updated', 'skipped', 'error'.
     */
    public function index_product( $product_id, $force = false ) {
        global $wpdb;

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 'error';
        }

        // Check if should be included.
        if ( ! $this->should_index_product( $product ) ) {
            // Remove if exists.
            $this->remove_product( $product_id );
            return 'skipped';
        }

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $site_id = get_current_blog_id();

        // Check if already indexed.
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, product_modified_at FROM {$table}
                 WHERE product_id = %d AND site_id = %d",
                $product_id,
                $site_id
            ),
            ARRAY_A
        );

        // Get product modified time.
        $post = get_post( $product_id );
        $modified_at = $post ? $post->post_modified : current_time( 'mysql' );

        // Skip if not changed and not forcing.
        if ( $existing && ! $force ) {
            if ( $existing['product_modified_at'] === $modified_at ) {
                return 'skipped';
            }
        }

        // Build index data.
        $data = $this->build_product_data( $product );
        $data['site_id'] = $site_id;
        $data['product_modified_at'] = $modified_at;
        $data['last_synced_at'] = current_time( 'mysql' );
        $data['updated_at'] = current_time( 'mysql' );

        if ( $existing ) {
            // Update.
            $result = $wpdb->update( $table, $data, array( 'id' => $existing['id'] ) );

            // Check if update succeeded.
            if ( false === $result || ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::error(
                    'Product index update failed',
                    array(
                        'product_id' => $product_id,
                        'index_id'   => $existing['id'],
                        'db_error'   => $wpdb->last_error,
                    ),
                    'indexer'
                );
                return 'error';
            }

            return 'updated';
        } else {
            // Insert.
            $data['created_at'] = current_time( 'mysql' );
            $result = $wpdb->insert( $table, $data );

            // Check if insert succeeded.
            if ( false === $result || ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::error(
                    'Product index insert failed',
                    array(
                        'product_id' => $product_id,
                        'db_error'   => $wpdb->last_error,
                    ),
                    'indexer'
                );
                return 'error';
            }

            return 'created';
        }
    }

    /**
     * Build product data for index.
     *
     * For variable products, aggregates data from all variations including:
     * - Available colors/sizes/custom attributes
     * - Min/max prices across variations
     * - Stock status (has_stock if any variation in stock)
     * - All variation SKUs for search
     *
     * @param WC_Product $product Product object.
     * @return array Index data.
     */
    private function build_product_data( $product ) {
        $data = array(
            'product_id'        => $product->get_id(),
            'parent_id'         => $product->get_parent_id(),
            'product_type'      => $product->get_type(),
            'sku'               => $product->get_sku(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price'             => $product->get_price(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'is_featured'       => $product->is_featured() ? 1 : 0,
            'is_on_sale'        => $product->is_on_sale() ? 1 : 0,
            'is_virtual'        => $product->is_virtual() ? 1 : 0,
            'is_downloadable'   => $product->is_downloadable() ? 1 : 0,
            'average_rating'    => $product->get_average_rating(),
            'review_count'      => $product->get_review_count(),
            'permalink'         => $product->get_permalink(),
            'include_in_index'  => 1,
            // New variation aggregation fields.
            'min_variation_price' => null,
            'max_variation_price' => null,
            'has_stock'           => $product->get_stock_status() === 'instock' ? 1 : 0,
            'variation_count'     => 0,
            'available_colors'    => null,
            'available_sizes'     => null,
            'custom_attributes'   => null,
            'variation_skus'      => null,
        );

        // Categories (use cached if available).
        $categories = $this->get_cached_taxonomy( $product->get_id(), 'product_cat' );
        $data['categories'] = wp_json_encode( $categories );

        // Tags (use cached if available).
        $tags = $this->get_cached_taxonomy( $product->get_id(), 'product_tag' );
        $data['tags'] = wp_json_encode( $tags );

        // Get ALL custom taxonomy terms for this product (beyond just categories/tags).
        $all_taxonomy_terms = array();
        $all_taxonomy_terms = array_merge( $all_taxonomy_terms, $categories, $tags );

        // Get custom taxonomies (excluding product_cat and product_tag which we already have).
        $custom_taxonomies = $this->get_product_taxonomies();
        foreach ( $custom_taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
                continue; // Already captured above.
            }
            $custom_terms = $this->get_cached_taxonomy( $product->get_id(), $taxonomy );
            if ( ! empty( $custom_terms ) ) {
                $all_taxonomy_terms = array_merge( $all_taxonomy_terms, $custom_terms );
            }
        }

        // Attributes from parent product.
        $attributes = array();
        $attribute_values = array();
        foreach ( $product->get_attributes() as $attr_name => $attr ) {
            if ( is_object( $attr ) ) {
                $options = $attr->get_options();
                $attributes[ $attr_name ] = $options;
                // Collect attribute values for search_text.
                if ( is_array( $options ) ) {
                    foreach ( $options as $opt ) {
                        if ( is_numeric( $opt ) ) {
                            // Term ID - get term name.
                            $term = get_term( $opt );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $attribute_values[] = $term->name;
                            }
                        } else {
                            $attribute_values[] = $opt;
                        }
                    }
                }
            }
        }
        $data['attributes'] = wp_json_encode( $attributes );

        // Image.
        $image_id = $product->get_image_id();
        $data['image_url'] = $image_id ? wp_get_attachment_url( $image_id ) : '';

        // Weight and dimensions.
        $data['weight'] = $product->get_weight();
        $data['dimensions'] = wp_json_encode( array(
            'length' => $product->get_length(),
            'width'  => $product->get_width(),
            'height' => $product->get_height(),
        ) );

        // Get custom meta values for search (brand, material, etc.).
        $meta_values = $this->get_searchable_meta_values( $product->get_id() );

        // Initialize variation-specific data.
        $variation_skus = array();
        $variation_attribute_values = array();

        // Process variations for variable products.
        if ( $product->is_type( 'variable' ) ) {
            $variation_data = $this->aggregate_variation_data( $product );

            $data['min_variation_price'] = $variation_data['min_price'];
            $data['max_variation_price'] = $variation_data['max_price'];
            $data['has_stock']           = $variation_data['has_stock'] ? 1 : 0;
            $data['variation_count']     = $variation_data['count'];
            $data['available_colors']    = ! empty( $variation_data['colors'] ) ? wp_json_encode( $variation_data['colors'] ) : null;
            $data['available_sizes']     = ! empty( $variation_data['sizes'] ) ? wp_json_encode( $variation_data['sizes'] ) : null;
            $data['custom_attributes']   = ! empty( $variation_data['custom'] ) ? wp_json_encode( $variation_data['custom'] ) : null;
            $data['variation_skus']      = ! empty( $variation_data['skus'] ) ? implode( ' ', $variation_data['skus'] ) : null;

            // Add variation SKUs and attribute values for search.
            $variation_skus = $variation_data['skus'];
            $variation_attribute_values = $variation_data['all_attribute_values'];

            // Index variations to the product_variations table.
            $this->index_variations( $product->get_id(), $variation_data['variations'] );
        }

        // Build search_text: concatenate searchable terms for FULLTEXT index.
        // Name is repeated for boosting. Includes SKU, all taxonomies, attributes, and custom meta.
        $search_parts = array(
            $product->get_name(),                          // Name (primary)
            $product->get_name(),                          // Name repeated for boost
            $product->get_sku(),                           // SKU
            implode( ' ', $all_taxonomy_terms ),           // All taxonomy terms (categories, tags, custom)
            implode( ' ', $attribute_values ),             // Parent attribute values
            implode( ' ', $variation_attribute_values ),   // Variation attribute values
            implode( ' ', $variation_skus ),               // All variation SKUs
            implode( ' ', $meta_values ),                  // Custom meta (dynamically discovered)
        );
        $data['search_text'] = implode( ' ', array_filter( $search_parts ) );

        return $data;
    }

    /**
     * Aggregate variation data for a variable product.
     *
     * @param WC_Product_Variable $product Variable product.
     * @return array Aggregated variation data.
     */
    private function aggregate_variation_data( $product ) {
        $data = array(
            'min_price'            => null,
            'max_price'            => null,
            'has_stock'            => false,
            'count'                => 0,
            'colors'               => array(),
            'sizes'                => array(),
            'custom'               => array(),
            'skus'                 => array(),
            'all_attribute_values' => array(),
            'variations'           => array(),
        );

        // Common attribute names for colors and sizes (lowercase for matching).
        $color_attrs = array( 'color', 'colour', 'pa_color', 'pa_colour' );
        $size_attrs  = array( 'size', 'pa_size', 'dimensions' );

        $variations = $product->get_available_variations();
        $data['count'] = count( $variations );

        $prices = array();

        foreach ( $variations as $variation_data ) {
            $variation = wc_get_product( $variation_data['variation_id'] );
            if ( ! $variation ) {
                continue;
            }

            // Collect variation for indexing.
            $var_record = array(
                'variation_id'  => $variation->get_id(),
                'sku'           => $variation->get_sku(),
                'price'         => $variation->get_price(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price'    => $variation->get_sale_price(),
                'stock_status'  => $variation->get_stock_status(),
                'stock_quantity'=> $variation->get_stock_quantity(),
                'is_on_sale'    => $variation->is_on_sale() ? 1 : 0,
                'image_url'     => '',
                'attributes'    => array(),
            );

            // Variation image.
            $image_id = $variation->get_image_id();
            if ( $image_id ) {
                $var_record['image_url'] = wp_get_attachment_url( $image_id );
            }

            // Collect prices for min/max.
            $price = $variation->get_price();
            if ( $price !== '' && $price !== null ) {
                $prices[] = (float) $price;
            }

            // Check stock.
            if ( $variation->is_in_stock() ) {
                $data['has_stock'] = true;
            }

            // Collect SKU.
            $sku = $variation->get_sku();
            if ( ! empty( $sku ) ) {
                $data['skus'][] = $sku;
            }

            // Process attributes.
            $attrs = $variation->get_attributes();
            foreach ( $attrs as $attr_name => $attr_value ) {
                if ( empty( $attr_value ) ) {
                    continue;
                }

                // Get the display name for the attribute value.
                $display_value = $attr_value;
                $taxonomy = str_replace( 'attribute_', '', $attr_name );

                // If it's a taxonomy attribute, get the term name.
                if ( taxonomy_exists( $taxonomy ) ) {
                    $term = get_term_by( 'slug', $attr_value, $taxonomy );
                    if ( $term ) {
                        $display_value = $term->name;
                    }
                }

                // Store for variation record.
                $clean_attr_name = str_replace( array( 'attribute_', 'pa_' ), '', $attr_name );
                $var_record['attributes'][ $clean_attr_name ] = $display_value;

                // Add to searchable values.
                $data['all_attribute_values'][] = $display_value;

                // Categorize into color, size, or custom.
                $attr_lower = strtolower( $attr_name );
                if ( $this->is_color_attribute( $attr_lower ) ) {
                    if ( ! in_array( $display_value, $data['colors'], true ) ) {
                        $data['colors'][] = $display_value;
                    }
                } elseif ( $this->is_size_attribute( $attr_lower ) ) {
                    if ( ! in_array( $display_value, $data['sizes'], true ) ) {
                        $data['sizes'][] = $display_value;
                    }
                } else {
                    // Custom attribute.
                    if ( ! isset( $data['custom'][ $clean_attr_name ] ) ) {
                        $data['custom'][ $clean_attr_name ] = array();
                    }
                    if ( ! in_array( $display_value, $data['custom'][ $clean_attr_name ], true ) ) {
                        $data['custom'][ $clean_attr_name ][] = $display_value;
                    }
                }
            }

            $data['variations'][] = $var_record;
        }

        // Calculate min/max prices.
        if ( ! empty( $prices ) ) {
            $data['min_price'] = min( $prices );
            $data['max_price'] = max( $prices );
        }

        // Remove duplicates from attribute values.
        $data['all_attribute_values'] = array_unique( $data['all_attribute_values'] );
        $data['skus'] = array_unique( $data['skus'] );

        return $data;
    }

    /**
     * Check if attribute name is a color attribute.
     *
     * @param string $attr_name Attribute name (lowercase).
     * @return bool
     */
    private function is_color_attribute( $attr_name ) {
        $color_patterns = array( 'color', 'colour', 'pa_color', 'pa_colour' );
        foreach ( $color_patterns as $pattern ) {
            if ( strpos( $attr_name, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if attribute name is a size attribute.
     *
     * @param string $attr_name Attribute name (lowercase).
     * @return bool
     */
    private function is_size_attribute( $attr_name ) {
        $size_patterns = array( 'size', 'pa_size' );
        foreach ( $size_patterns as $pattern ) {
            if ( strpos( $attr_name, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Index variations to the product_variations table.
     *
     * @param int   $parent_id  Parent product ID.
     * @param array $variations Array of variation data from aggregate_variation_data().
     */
    private function index_variations( $parent_id, $variations ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_product_variations';
        $site_id = get_current_blog_id();

        // Delete existing variations for this parent.
        $wpdb->delete(
            $table,
            array(
                'parent_id' => $parent_id,
                'site_id'   => $site_id,
            ),
            array( '%d', '%d' )
        );

        // Insert new variations.
        foreach ( $variations as $var ) {
            $wpdb->insert(
                $table,
                array(
                    'site_id'        => $site_id,
                    'variation_id'   => $var['variation_id'],
                    'parent_id'      => $parent_id,
                    'sku'            => $var['sku'],
                    'attributes'     => wp_json_encode( $var['attributes'] ),
                    'price'          => $var['price'],
                    'regular_price'  => $var['regular_price'],
                    'sale_price'     => $var['sale_price'],
                    'stock_status'   => $var['stock_status'],
                    'stock_quantity' => $var['stock_quantity'],
                    'image_url'      => $var['image_url'],
                    'is_on_sale'     => $var['is_on_sale'],
                    'created_at'     => current_time( 'mysql' ),
                    'updated_at'     => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Check if product should be indexed.
     *
     * @param WC_Product $product Product object.
     * @return bool
     */
    private function should_index_product( $product ) {
        // Skip variations - they are indexed via the product_variations table.
        if ( $product->is_type( 'variation' ) ) {
            return false;
        }

        // Must be published.
        if ( 'publish' !== $product->get_status() ) {
            return false;
        }

        // Check include/exclude settings.
        $mode = $this->settings->get( 'product_index_mode', 'all' );

        if ( 'all' === $mode ) {
            // Check exclusions.
            $exclude_ids = $this->settings->get( 'product_exclude_ids', array() );
            if ( in_array( $product->get_id(), $exclude_ids, true ) ) {
                return false;
            }

            $exclude_cats = $this->settings->get( 'product_exclude_categories', array() );
            if ( ! empty( $exclude_cats ) ) {
                $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
                if ( array_intersect( $product_cats, $exclude_cats ) ) {
                    return false;
                }
            }

            return true;
        }

        if ( 'include' === $mode ) {
            // Only include specific products/categories.
            $include_ids = $this->settings->get( 'product_include_ids', array() );
            if ( in_array( $product->get_id(), $include_ids, true ) ) {
                return true;
            }

            $include_cats = $this->settings->get( 'product_include_categories', array() );
            if ( ! empty( $include_cats ) ) {
                $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
                if ( array_intersect( $product_cats, $include_cats ) ) {
                    return true;
                }
            }

            return false;
        }

        if ( 'exclude' === $mode ) {
            // Exclude specific products/categories.
            $exclude_ids = $this->settings->get( 'product_exclude_ids', array() );
            if ( in_array( $product->get_id(), $exclude_ids, true ) ) {
                return false;
            }

            $exclude_cats = $this->settings->get( 'product_exclude_categories', array() );
            if ( ! empty( $exclude_cats ) ) {
                $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
                if ( array_intersect( $product_cats, $exclude_cats ) ) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * Remove product from index.
     *
     * Also removes associated variations from the product_variations table.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public function remove_product( $product_id ) {
        global $wpdb;

        $site_id = get_current_blog_id();

        // Remove from product_index.
        $result = $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_product_index',
            array(
                'product_id' => $product_id,
                'site_id'    => $site_id,
            ),
            array( '%d', '%d' )
        ) !== false;

        // Also remove any variations for this parent.
        $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_product_variations',
            array(
                'parent_id' => $product_id,
                'site_id'   => $site_id,
            ),
            array( '%d', '%d' )
        );

        return $result;
    }

    // =========================================================================
    // Product Search (SQL-based)
    // =========================================================================

    /**
     * Search products using SQL.
     *
     * Implements a tiered search strategy:
     * 1. Exact SKU match (highest priority)
     * 2. FULLTEXT search across name, sku, search_text, short_description
     * 3. LIKE-based fallback if FULLTEXT returns no results
     *
     * @param array $args Search arguments.
     * @return array Products.
     */
    public function search( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'query'         => '',
            'category'      => null,
            'min_price'     => null,
            'max_price'     => null,
            'on_sale'       => null,
            'in_stock'      => null,
            'featured'      => null,
            'attributes'    => array(),
            'orderby'       => 'relevance',
            'order'         => 'DESC',
            'limit'         => 10,
            'offset'        => 0,
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $site_id = get_current_blog_id();

        // Build base WHERE conditions (filters that apply to all search types).
        $base_where = array( 'site_id = %d', 'include_in_index = 1' );
        $base_params = array( $site_id );

        // Price range - only apply if actually set (not 0).
        if ( ! empty( $args['min_price'] ) && (float) $args['min_price'] > 0 ) {
            $base_where[] = "price >= %f";
            $base_params[] = (float) $args['min_price'];
        }
        if ( ! empty( $args['max_price'] ) && (float) $args['max_price'] > 0 ) {
            $base_where[] = "price <= %f";
            $base_params[] = (float) $args['max_price'];
        }

        // On sale - only filter when explicitly TRUE.
        if ( $args['on_sale'] === true ) {
            $base_where[] = "is_on_sale = 1";
        }

        // In stock - only filter when explicitly set.
        if ( $args['in_stock'] === false ) {
            $base_where[] = "stock_status != 'instock'";
        }

        // Featured - only filter when explicitly TRUE.
        if ( $args['featured'] === true ) {
            $base_where[] = "is_featured = 1";
        }

        // Attributes.
        foreach ( $args['attributes'] as $attr_name => $attr_value ) {
            $base_where[] = "JSON_CONTAINS(attributes, %s, %s)";
            $base_params[] = wp_json_encode( $attr_value );
            $base_params[] = '$.' . $attr_name;
        }

        // Category filter.
        if ( ! empty( $args['category'] ) ) {
            $base_where[] = "LOWER(categories) LIKE %s";
            $base_params[] = '%' . $wpdb->esc_like( strtolower( $args['category'] ) ) . '%';
        }

        $base_where_clause = implode( ' AND ', $base_where );

        // Order by clause - use whitelist to prevent SQL injection.
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $allowed_orderby = array(
            'relevance'  => 'relevance_score',
            'price'      => 'price',
            'price_asc'  => 'price',
            'price_desc' => 'price',
            'name'       => 'name',
            'rating'     => 'average_rating',
            'date'       => 'created_at',
            'popularity' => 'review_count',
        );

        // Determine orderby column (default to id if not allowed).
        $orderby_key = $args['orderby'] ?? 'relevance';
        $orderby_column = $allowed_orderby[ $orderby_key ] ?? 'id';

        // Handle special price ordering.
        if ( 'price_asc' === $orderby_key ) {
            $order = 'ASC';
        } elseif ( 'price_desc' === $orderby_key ) {
            $order = 'DESC';
        }

        // For relevance, we compute the score in SELECT (below) - otherwise just order by column.
        // IMPORTANT: relevance_score only exists when we have a FULLTEXT search query.
        // If there's no query, we must fall back to a column that actually exists.
        $use_relevance_score = ( 'relevance_score' === $orderby_column && ! empty( $args['query'] ) );

        // When relevance was requested but there's no text query, fall back to 'id' or 'name'
        if ( 'relevance_score' === $orderby_column && empty( $args['query'] ) ) {
            $orderby_column = 'id';
        }

        $orderby = $use_relevance_score ? "relevance_score {$order}" : "{$orderby_column} {$order}";

        $results = array();

        // =====================================================================
        // TIER 1: Exact SKU match (highest priority)
        // =====================================================================
        if ( ! empty( $args['query'] ) ) {
            $sku_query = trim( $args['query'] );
            $sku_where = $base_where;
            $sku_where[] = 'sku = %s';
            $sku_params = array_merge( $base_params, array( $sku_query ) );

            $sku_where_clause = implode( ' AND ', $sku_where );

            // Build SELECT clause - add relevance score if ordering by relevance.
            if ( $use_relevance_score ) {
                $sku_select = "SELECT *, MATCH(name, sku, search_text, short_description, variation_skus) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance_score";
                $sku_params = array_merge( array( $args['query'] ), $sku_params );
            } else {
                $sku_select = 'SELECT *';
            }

            $sku_params[] = (int) $args['limit'];
            $sku_params[] = (int) $args['offset'];

            $sku_sql = "{$sku_select} FROM {$table} WHERE {$sku_where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
            $prepared = $wpdb->prepare( $sku_sql, $sku_params );
            $results = $wpdb->get_results( $prepared, ARRAY_A );

            // If we found an exact SKU match, return it immediately.
            if ( ! empty( $results ) ) {
                return $this->decode_product_json_fields( $results );
            }
        }

        // =====================================================================
        // TIER 2: FULLTEXT search (primary search method)
        // =====================================================================
        if ( ! empty( $args['query'] ) ) {
            $ft_where = $base_where;
            $ft_where[] = 'MATCH(name, sku, search_text, short_description, variation_skus) AGAINST(%s IN NATURAL LANGUAGE MODE)';

            // Build SELECT clause with relevance score for proper ordering.
            // The MATCH condition is in WHERE, but we also add it to SELECT for ordering.
            if ( $use_relevance_score ) {
                $ft_select = "SELECT *, MATCH(name, sku, search_text, short_description, variation_skus) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance_score";
                $ft_params = array_merge( array( $args['query'] ), $base_params, array( $args['query'] ) );
            } else {
                $ft_select = 'SELECT *';
                $ft_params = array_merge( $base_params, array( $args['query'] ) );
            }

            $ft_params[] = (int) $args['limit'];
            $ft_params[] = (int) $args['offset'];

            $ft_where_clause = implode( ' AND ', $ft_where );
            $ft_sql = "{$ft_select} FROM {$table} WHERE {$ft_where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
            $prepared = $wpdb->prepare( $ft_sql, $ft_params );
            $results = $wpdb->get_results( $prepared, ARRAY_A );

            // Log SQL errors.
            if ( $wpdb->last_error && class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::error(
                    'Product FULLTEXT search SQL error',
                    array( 'error' => $wpdb->last_error, 'args' => $args ),
                    'product-indexer'
                );
            }

            // If FULLTEXT found results, return them.
            if ( ! empty( $results ) ) {
                return $this->decode_product_json_fields( $results );
            }

            // =================================================================
            // TIER 3: LIKE-based fallback (for short queries or no FULLTEXT matches)
            // =================================================================
            // FULLTEXT has minimum word length requirements and may not match
            // partial terms. Fall back to LIKE for broader matching.
            $like_where = $base_where;
            $search_term = '%' . $wpdb->esc_like( $args['query'] ) . '%';
            $like_where[] = "(name LIKE %s OR sku LIKE %s OR search_text LIKE %s OR short_description LIKE %s)";
            $like_params = array_merge( $base_params, array( $search_term, $search_term, $search_term, $search_term ) );
            $like_params[] = (int) $args['limit'];
            $like_params[] = (int) $args['offset'];

            $like_where_clause = implode( ' AND ', $like_where );
            // For LIKE search, order by name match priority.
            $like_orderby = "CASE WHEN name LIKE %s THEN 0 WHEN sku LIKE %s THEN 1 ELSE 2 END ASC, name ASC";
            $like_sql = "SELECT * FROM {$table} WHERE {$like_where_clause} ORDER BY {$like_orderby} LIMIT %d OFFSET %d";
            // Add order params before limit/offset.
            $like_params_with_order = array_merge(
                $base_params,
                array( $search_term, $search_term, $search_term, $search_term ),
                array( $search_term, $search_term ),
                array( (int) $args['limit'], (int) $args['offset'] )
            );
            $prepared = $wpdb->prepare( $like_sql, $like_params_with_order );
            $results = $wpdb->get_results( $prepared, ARRAY_A );

            // Log SQL errors.
            if ( $wpdb->last_error && class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::error(
                    'Product LIKE fallback search SQL error',
                    array( 'error' => $wpdb->last_error, 'args' => $args ),
                    'product-indexer'
                );
            }

            return $this->decode_product_json_fields( $results );
        }

        // =====================================================================
        // No query - return filtered products by other criteria
        // =====================================================================
        $params = array_merge( $base_params, array( (int) $args['limit'], (int) $args['offset'] ) );
        $sql = "SELECT * FROM {$table} WHERE {$base_where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );
        $results = $wpdb->get_results( $prepared, ARRAY_A );

        // Log SQL errors.
        if ( $wpdb->last_error && class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::error(
                'Product search SQL error',
                array( 'error' => $wpdb->last_error, 'args' => $args ),
                'product-indexer'
            );
        }

        return $this->decode_product_json_fields( $results );
    }

    /**
     * Decode JSON fields in product results.
     *
     * @param array $results Array of product rows.
     * @return array Products with decoded JSON fields.
     */
    private function decode_product_json_fields( $results ) {
        foreach ( $results as &$product ) {
            $product['categories'] = json_decode( $product['categories'] ?? '[]', true );
            $product['tags'] = json_decode( $product['tags'] ?? '[]', true );
            $product['attributes'] = json_decode( $product['attributes'] ?? '{}', true );
            $product['dimensions'] = json_decode( $product['dimensions'] ?? '{}', true );
            $product['available_colors'] = json_decode( $product['available_colors'] ?? '[]', true );
            $product['available_sizes'] = json_decode( $product['available_sizes'] ?? '[]', true );
            $product['custom_attributes'] = json_decode( $product['custom_attributes'] ?? '{}', true );
        }
        return $results;
    }

    /**
     * Get variations for a product.
     *
     * @param int   $parent_id    Parent product ID.
     * @param array $filters      Optional filters (color, size, in_stock).
     * @return array Array of variation data.
     */
    public function get_variations( $parent_id, $filters = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_product_variations';
        $site_id = get_current_blog_id();

        $where = array( 'parent_id = %d', 'site_id = %d' );
        $params = array( $parent_id, $site_id );

        // Filter by stock status.
        if ( isset( $filters['in_stock'] ) && $filters['in_stock'] === true ) {
            $where[] = "stock_status = 'instock'";
        }

        // Filter by color.
        if ( ! empty( $filters['color'] ) ) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.color')) = %s";
            $params[] = $filters['color'];
        }

        // Filter by size.
        if ( ! empty( $filters['size'] ) ) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.size')) = %s";
            $params[] = $filters['size'];
        }

        // Generic attribute filter.
        if ( ! empty( $filters['attributes'] ) && is_array( $filters['attributes'] ) ) {
            foreach ( $filters['attributes'] as $attr_name => $attr_value ) {
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(attributes, %s)) = %s";
                $params[] = '$.' . $attr_name;
                $params[] = $attr_value;
            }
        }

        $where_clause = implode( ' AND ', $where );

        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY price ASC";
        $prepared = $wpdb->prepare( $sql, $params );
        $results = $wpdb->get_results( $prepared, ARRAY_A );

        // Decode JSON attributes.
        foreach ( $results as &$var ) {
            $var['attributes'] = json_decode( $var['attributes'] ?? '{}', true );
        }

        return $results;
    }

    /**
     * Search products with variation filtering.
     *
     * Finds products that have variations matching specific criteria.
     * For example: "black shirts in 4XL that are in stock".
     *
     * @param array $args Search arguments including variation_filters.
     * @return array Products matching criteria.
     */
    public function search_with_variations( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'query'             => '',
            'category'          => null,
            'min_price'         => null,
            'max_price'         => null,
            'variation_color'   => null,
            'variation_size'    => null,
            'variation_in_stock'=> null,
            'variation_attributes' => array(),
            'orderby'           => 'relevance',
            'order'             => 'DESC',
            'limit'             => 10,
            'offset'            => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        $product_table = $wpdb->prefix . 'glimmr_ai_product_index';
        $variation_table = $wpdb->prefix . 'glimmr_ai_product_variations';
        $site_id = get_current_blog_id();

        // Check if we need to join with variations table.
        $needs_variation_join = ! empty( $args['variation_color'] )
            || ! empty( $args['variation_size'] )
            || $args['variation_in_stock'] === true
            || ! empty( $args['variation_attributes'] );

        if ( ! $needs_variation_join ) {
            // No variation filters - use regular search.
            return $this->search( $args );
        }

        // Build variation-aware query.
        $select = "SELECT DISTINCT p.*";
        $from = "FROM {$product_table} p";
        $join = "INNER JOIN {$variation_table} v ON v.parent_id = p.product_id AND v.site_id = p.site_id";

        $where = array( 'p.site_id = %d', 'p.include_in_index = 1' );
        $params = array( $site_id );

        // Text search on product.
        if ( ! empty( $args['query'] ) ) {
            $where[] = "MATCH(p.name, p.sku, p.search_text, p.short_description, p.variation_skus) AGAINST(%s IN NATURAL LANGUAGE MODE)";
            $params[] = $args['query'];
        }

        // Category filter on product.
        if ( ! empty( $args['category'] ) ) {
            $where[] = "LOWER(p.categories) LIKE %s";
            $params[] = '%' . $wpdb->esc_like( strtolower( $args['category'] ) ) . '%';
        }

        // Price filters (use variation price if filtering by variation).
        if ( ! empty( $args['min_price'] ) && (float) $args['min_price'] > 0 ) {
            $where[] = "v.price >= %f";
            $params[] = (float) $args['min_price'];
        }
        if ( ! empty( $args['max_price'] ) && (float) $args['max_price'] > 0 ) {
            $where[] = "v.price <= %f";
            $params[] = (float) $args['max_price'];
        }

        // Variation color filter.
        if ( ! empty( $args['variation_color'] ) ) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(v.attributes, '$.color')) = %s";
            $params[] = $args['variation_color'];
        }

        // Variation size filter.
        if ( ! empty( $args['variation_size'] ) ) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(v.attributes, '$.size')) = %s";
            $params[] = $args['variation_size'];
        }

        // Variation stock filter.
        if ( $args['variation_in_stock'] === true ) {
            $where[] = "v.stock_status = 'instock'";
        }

        // Generic variation attribute filters.
        foreach ( $args['variation_attributes'] as $attr_name => $attr_value ) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(v.attributes, %s)) = %s";
            $params[] = '$.' . $attr_name;
            $params[] = $attr_value;
        }

        $where_clause = implode( ' AND ', $where );

        // Order by - use whitelist to prevent SQL injection.
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $allowed_orderby = array(
            'relevance' => 'relevance_score',
            'price'     => 'p.min_variation_price',
            'name'      => 'p.name',
            'rating'    => 'p.average_rating',
        );

        $orderby_key = $args['orderby'] ?? 'relevance';
        $orderby_column = $allowed_orderby[ $orderby_key ] ?? 'p.id';

        // For relevance ordering, compute score in SELECT.
        // IMPORTANT: relevance_score only exists when we have a FULLTEXT search query.
        // If there's no query, we must fall back to a column that actually exists.
        $use_relevance = ( 'relevance_score' === $orderby_column && ! empty( $args['query'] ) );

        // When relevance was requested but there's no text query, fall back to 'p.id'
        if ( 'relevance_score' === $orderby_column && empty( $args['query'] ) ) {
            $orderby_column = 'p.id';
        }

        $orderby = $use_relevance ? "relevance_score {$order}" : "{$orderby_column} {$order}";

        // Build SELECT clause with relevance score if needed.
        if ( $use_relevance ) {
            $select = "SELECT DISTINCT p.*, MATCH(p.name, p.sku, p.search_text, p.short_description, p.variation_skus) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance_score";
            // Prepend the query for the relevance score calculation.
            array_unshift( $params, $args['query'] );
        }

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        $sql = "{$select} {$from} {$join} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $prepared = $wpdb->prepare( $sql, $params );
        $results = $wpdb->get_results( $prepared, ARRAY_A );

        if ( $wpdb->last_error && class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::error(
                'Variation-aware search SQL error',
                array( 'error' => $wpdb->last_error, 'args' => $args ),
                'product-indexer'
            );
        }

        return $this->decode_product_json_fields( $results );
    }

    /**
     * Get product by ID from index.
     *
     * @param int  $product_id       Product ID.
     * @param bool $include_variations Whether to include variation data.
     * @return array|null Product data.
     */
    public function get_product( $product_id, $include_variations = false ) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}glimmr_ai_product_index
                 WHERE product_id = %d AND site_id = %d",
                $product_id,
                get_current_blog_id()
            ),
            ARRAY_A
        );

        if ( $result ) {
            $result['categories'] = json_decode( $result['categories'] ?? '[]', true );
            $result['tags'] = json_decode( $result['tags'] ?? '[]', true );
            $result['attributes'] = json_decode( $result['attributes'] ?? '{}', true );
            $result['dimensions'] = json_decode( $result['dimensions'] ?? '{}', true );
            $result['available_colors'] = json_decode( $result['available_colors'] ?? '[]', true );
            $result['available_sizes'] = json_decode( $result['available_sizes'] ?? '[]', true );
            $result['custom_attributes'] = json_decode( $result['custom_attributes'] ?? '{}', true );

            // Include variations if requested.
            if ( $include_variations && $result['product_type'] === 'variable' ) {
                $result['variations'] = $this->get_variations( $product_id );
            }
        }

        return $result;
    }

    // =========================================================================
    // Lexical Signals for Candidate Selection
    // =========================================================================

    /**
     * Compute lexical relevance signals for products against a query.
     *
     * Used by the candidates + signals pattern to help the LLM select
     * the most relevant products from semantic search results.
     *
     * @param array  $product_ids Array of product IDs to score.
     * @param string $query       The user's search query.
     * @return array Associative array: product_id => signals array.
     */
    public function compute_lexical_signals( $product_ids, $query ) {
        global $wpdb;

        if ( empty( $product_ids ) || empty( $query ) ) {
            return array();
        }

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $site_id = get_current_blog_id();

        // Sanitize product IDs.
        $product_ids = array_map( 'intval', $product_ids );
        $id_placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

        // Get FULLTEXT relevance scores for all products at once.
        $params = array_merge(
            array( $query ),
            array( $site_id ),
            $product_ids
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, name, sku, short_description,
                        MATCH(name, sku, search_text, short_description, variation_skus)
                        AGAINST(%s IN NATURAL LANGUAGE MODE) AS fulltext_score
                 FROM {$table}
                 WHERE site_id = %d AND product_id IN ({$id_placeholders})",
                $params
            ),
            ARRAY_A
        );

        if ( empty( $results ) ) {
            return array();
        }

        // Find max score for normalization.
        $max_score = 0;
        foreach ( $results as $row ) {
            if ( (float) $row['fulltext_score'] > $max_score ) {
                $max_score = (float) $row['fulltext_score'];
            }
        }

        // Tokenize query into searchable terms.
        $query_terms = $this->tokenize_query( $query );
        $query_lower = strtolower( $query );

        $signals = array();

        foreach ( $results as $row ) {
            $product_id = (int) $row['product_id'];
            $name = $row['name'] ?? '';
            $name_lower = strtolower( $name );
            $sku = $row['sku'] ?? '';
            $short_desc = $row['short_description'] ?? '';

            // Normalize lexical score (0-1).
            $lexical_score = $max_score > 0
                ? round( (float) $row['fulltext_score'] / $max_score, 3 )
                : 0;

            // Find which query terms match in the product name.
            $matched_terms = $this->find_matched_terms( $query_terms, $name );

            // Check if title contains the main query terms.
            $title_contains_query = $this->title_contains_query( $query_terms, $name_lower );

            // Check for exact or near-exact match.
            $exact_match = $this->is_exact_match( $query_lower, $name_lower );

            // Check SKU match.
            $sku_match = ! empty( $sku ) && stripos( $query, $sku ) !== false;

            $signals[ $product_id ] = array(
                'lexical_score'        => $lexical_score,
                'matched_terms'        => $matched_terms,
                'title_contains_query' => $title_contains_query,
                'exact_match'          => $exact_match,
                'sku_match'            => $sku_match,
                'match_ratio'          => count( $query_terms ) > 0
                    ? round( count( $matched_terms ) / count( $query_terms ), 2 )
                    : 0,
            );
        }

        return $signals;
    }

    /**
     * Tokenize a query into meaningful search terms.
     *
     * Removes common stop words and short terms.
     *
     * @param string $query The search query.
     * @return array Array of lowercase query terms.
     */
    private function tokenize_query( $query ) {
        // Common stop words to ignore.
        $stop_words = array(
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'can', 'this', 'that',
            'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me',
            'my', 'your', 'show', 'find', 'get', 'want', 'looking', 'need', 'like',
            'some', 'any', 'please', 'thanks',
        );

        // Split query into words.
        $words = preg_split( '/[\s\-_,\.]+/', strtolower( $query ) );
        $words = array_filter( $words, function( $word ) use ( $stop_words ) {
            // Keep words that are 2+ chars and not stop words.
            return strlen( $word ) >= 2 && ! in_array( $word, $stop_words, true );
        } );

        return array_values( $words );
    }

    /**
     * Find which query terms appear in a product name.
     *
     * @param array  $query_terms Array of query terms.
     * @param string $name        Product name.
     * @return array Array of matched terms.
     */
    private function find_matched_terms( $query_terms, $name ) {
        $name_lower = strtolower( $name );
        $matched = array();

        foreach ( $query_terms as $term ) {
            if ( stripos( $name_lower, $term ) !== false ) {
                $matched[] = $term;
            }
        }

        return $matched;
    }

    /**
     * Check if the product title contains the main query terms.
     *
     * Returns true if at least 50% of query terms appear in the title,
     * or if any term is 4+ characters and matches.
     *
     * @param array  $query_terms Array of query terms.
     * @param string $name_lower  Lowercase product name.
     * @return bool True if title contains query terms.
     */
    private function title_contains_query( $query_terms, $name_lower ) {
        if ( empty( $query_terms ) ) {
            return false;
        }

        $matched_count = 0;
        $significant_match = false;

        foreach ( $query_terms as $term ) {
            if ( stripos( $name_lower, $term ) !== false ) {
                $matched_count++;
                // A significant term (4+ chars) matching is important.
                if ( strlen( $term ) >= 4 ) {
                    $significant_match = true;
                }
            }
        }

        // Return true if 50%+ terms match OR any significant term matches.
        $match_ratio = $matched_count / count( $query_terms );
        return $match_ratio >= 0.5 || $significant_match;
    }

    /**
     * Check if query is an exact or near-exact match for product name.
     *
     * @param string $query_lower Lowercase query.
     * @param string $name_lower  Lowercase product name.
     * @return bool True if exact or near-exact match.
     */
    private function is_exact_match( $query_lower, $name_lower ) {
        // Exact match.
        if ( $query_lower === $name_lower ) {
            return true;
        }

        // Query is contained entirely within product name.
        if ( stripos( $name_lower, $query_lower ) !== false ) {
            return true;
        }

        // Product name is contained entirely within query.
        if ( stripos( $query_lower, $name_lower ) !== false ) {
            return true;
        }

        // Check similarity (for typos, slight variations).
        similar_text( $query_lower, $name_lower, $percent );
        if ( $percent >= 80 ) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get all product IDs to index.
     *
     * Only returns parent products (simple, variable, grouped, external).
     * Variations are indexed via the product_variations table.
     *
     * @return array Product IDs.
     */
    private function get_all_product_ids() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_status = 'publish'"
        );
    }

    /**
     * Clean up products that no longer exist.
     *
     * @return int Deleted count.
     */
    private function cleanup_deleted_products() {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $site_id = get_current_blog_id();

        // Find indexed products that no longer exist.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE idx FROM {$table} idx
                 LEFT JOIN {$wpdb->posts} p ON idx.product_id = p.ID
                 WHERE idx.site_id = %d
                 AND (p.ID IS NULL OR p.post_status != 'publish')",
                $site_id
            )
        );

        return (int) $deleted;
    }

    /**
     * Log sync start.
     *
     * @param string $type       Sync type.
     * @param string $triggered_by How triggered.
     * @return int Log ID, or 0 if insert failed.
     */
    private function log_sync_start( $type, $triggered_by ) {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'glimmr_ai_sync_log',
            array(
                'site_id'       => get_current_blog_id(),
                'sync_type'     => $type,
                'status'        => 'running',
                'started_at'    => current_time( 'mysql' ),
                'triggered_by'  => $triggered_by,
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::warning(
                'Failed to create sync log entry',
                array(
                    'sync_type'    => $type,
                    'triggered_by' => $triggered_by,
                    'db_error'     => $wpdb->last_error,
                ),
                'indexer'
            );
            return 0;
        }

        return $wpdb->insert_id;
    }

    /**
     * Log sync completion.
     *
     * @param int   $log_id  Log ID.
     * @param array $results Sync results.
     */
    private function log_sync_complete( $log_id, $results ) {
        // Skip if no valid log ID (log_sync_start may have failed).
        if ( $log_id <= 0 ) {
            return;
        }

        global $wpdb;

        $update_result = $wpdb->update(
            $wpdb->prefix . 'glimmr_ai_sync_log',
            array(
                'status'          => $results['success'] ? 'completed' : 'failed',
                'items_processed' => ( $results['created'] ?? 0 ) + ( $results['updated'] ?? 0 ) + ( $results['skipped'] ?? 0 ),
                'items_total'     => $results['total'] ?? 0,
                'items_created'   => $results['created'] ?? 0,
                'items_updated'   => $results['updated'] ?? 0,
                'items_deleted'   => $results['deleted'] ?? 0,
                'items_errored'   => $results['errors'] ?? 0,
                'completed_at'    => current_time( 'mysql' ),
            ),
            array( 'id' => $log_id )
        );

        if ( false === $update_result || ! empty( $wpdb->last_error ) ) {
            Glimmr_AI_Logger::warning(
                'Failed to update sync log completion',
                array(
                    'log_id'   => $log_id,
                    'db_error' => $wpdb->last_error,
                ),
                'indexer'
            );
        }
    }

    /**
     * Get index statistics.
     *
     * @return array Stats.
     */
    public function get_stats() {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $site_id = get_current_blog_id();

        $stats = array(
            'total_indexed'  => 0,
            'total_products' => 0,
            'by_type'        => array(),
            'by_status'      => array(),
            'last_sync'      => null,
        );

        // Indexed count.
        $stats['total_indexed'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE site_id = %d AND include_in_index = 1",
                $site_id
            )
        );

        // Total products in WooCommerce.
        $stats['total_products'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'"
        );

        // By product type.
        $by_type = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_type, COUNT(*) as count
                 FROM {$table}
                 WHERE site_id = %d AND include_in_index = 1
                 GROUP BY product_type",
                $site_id
            ),
            ARRAY_A
        );
        foreach ( $by_type as $row ) {
            $stats['by_type'][ $row['product_type'] ] = (int) $row['count'];
        }

        // By stock status.
        $by_status = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT stock_status, COUNT(*) as count
                 FROM {$table}
                 WHERE site_id = %d AND include_in_index = 1
                 GROUP BY stock_status",
                $site_id
            ),
            ARRAY_A
        );
        foreach ( $by_status as $row ) {
            $stats['by_status'][ $row['stock_status'] ] = (int) $row['count'];
        }

        // Last sync.
        $stats['last_sync'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(last_synced_at) FROM {$table} WHERE site_id = %d",
                $site_id
            )
        );

        return $stats;
    }
}
