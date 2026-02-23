<?php
/**
 * Vector Store Sync Manager
 *
 * Manages synchronization of products and knowledge to OpenAI vector store
 * for RAG-based retrieval.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Vector_Store
 *
 * Handles:
 * - Product data → Vector store sync
 * - Knowledge base → Vector store sync
 * - File management and cleanup
 * - Batch processing for large catalogs
 */
class Glimmr_AI_Vector_Store {

    /**
     * OpenAI client.
     *
     * @var Glimmr_AI_OpenAI
     */
    private $openai;

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
     * Batch size for file uploads.
     *
     * @var int
     */
    private $batch_size = 50;

    /**
     * Constructor.
     *
     * @param Glimmr_AI_OpenAI   $openai   OpenAI client.
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_Settings $settings Settings instance.
     */
    public function __construct( $openai, $database, $settings ) {
        $this->openai   = $openai;
        $this->database = $database;
        $this->settings = $settings;
    }

    /**
     * Check if vector store is ready.
     *
     * @return bool
     */
    public function is_ready() {
        return $this->openai->is_configured() && $this->openai->has_vector_store();
    }

    /**
     * Initialize or get vector store.
     *
     * Creates a new vector store if one doesn't exist.
     *
     * @return string|WP_Error Vector store ID or error.
     */
    public function initialize_vector_store() {
        $existing_id = $this->settings->get( 'openai_vector_store_id' );

        if ( ! empty( $existing_id ) ) {
            // Verify it exists.
            $store = $this->openai->get_vector_store( $existing_id );
            if ( ! is_wp_error( $store ) ) {
                return $existing_id;
            }
        }

        // Create new vector store.
        $site_name = get_bloginfo( 'name' );
        $store = $this->openai->create_vector_store( sprintf(
            'Glimmr AI - %s (%d)',
            $site_name,
            get_current_blog_id()
        ) );

        if ( is_wp_error( $store ) ) {
            return $store;
        }

        $store_id = $store['id'] ?? '';
        if ( empty( $store_id ) ) {
            return new WP_Error( 'no_store_id', __( 'Failed to create vector store.', 'glimmr-ai' ) );
        }

        // Save the ID.
        $this->settings->set( 'openai_vector_store_id', $store_id );

        return $store_id;
    }

    // =========================================================================
    // Product Sync
    // =========================================================================

    /**
     * Sync products to vector store.
     *
     * Syncs products from the product index table to OpenAI.
     *
     * @param bool $full_sync Whether to force full sync.
     * @return array Sync results.
     */
    public function sync_products( $full_sync = false ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'success' => false,
                'error'   => __( 'Vector store not configured.', 'glimmr-ai' ),
            );
        }

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $results = array(
            'success'  => true,
            'synced'   => 0,
            'errors'   => 0,
            'skipped'  => 0,
            'details'  => array(),
        );

        // Get products that need syncing.
        if ( $full_sync ) {
            $products = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE include_in_index = 1 AND site_id = %d",
                    get_current_blog_id()
                ),
                ARRAY_A
            );
        } else {
            // Only products where vector_file_id is null or product was updated after last sync.
            $products = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE include_in_index = 1
                     AND site_id = %d
                     AND (vector_file_id IS NULL OR product_modified_at > last_synced_at)",
                    get_current_blog_id()
                ),
                ARRAY_A
            );
        }

        if ( empty( $products ) ) {
            return array(
                'success' => true,
                'synced'  => 0,
                'message' => __( 'No products need syncing.', 'glimmr-ai' ),
            );
        }

        // Process in batches.
        $batches = array_chunk( $products, $this->batch_size );

        foreach ( $batches as $batch ) {
            $batch_result = $this->sync_product_batch( $batch );
            $results['synced']  += $batch_result['synced'];
            $results['errors']  += $batch_result['errors'];
            $results['details'] = array_merge( $results['details'], $batch_result['details'] );
        }

        return $results;
    }

    /**
     * Sync a batch of products.
     *
     * @param array $products Array of product data from index table.
     * @return array Batch results.
     */
    private function sync_product_batch( $products ) {
        global $wpdb;

        $results = array(
            'synced'  => 0,
            'errors'  => 0,
            'details' => array(),
        );

        $table = $wpdb->prefix . 'glimmr_ai_product_index';

        foreach ( $products as $product ) {
            $content = $this->format_product_for_vector_store( $product );
            // Format: product-{id}-{slug}.json enables ID extraction from search results
            $filename = sprintf(
                'product-%d-%s.json',
                $product['product_id'],
                sanitize_title( $product['name'] )
            );

            $attributes = $this->build_file_attributes( $product );
            $sync_result = $this->openai->sync_to_vector_store(
                $content,
                $filename,
                $product['vector_file_id'] ?? null,
                $attributes
            );

            if ( is_wp_error( $sync_result ) ) {
                $results['errors']++;
                $results['details'][] = array(
                    'product_id' => $product['product_id'],
                    'error'      => $sync_result->get_error_message(),
                );
                continue;
            }

            // Update the index record.
            $update_result = $wpdb->update(
                $table,
                array(
                    'vector_file_id' => $sync_result['file_id'],
                    'last_synced_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $product['id'] ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            // Check if database update succeeded.
            if ( false === $update_result || ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::error(
                    'Vector store sync succeeded but database update failed',
                    array(
                        'product_id'     => $product['product_id'],
                        'vector_file_id' => $sync_result['file_id'],
                        'db_error'       => $wpdb->last_error,
                    ),
                    'vector_store'
                );
                $results['errors']++;
                $results['details'][] = array(
                    'product_id' => $product['product_id'],
                    'error'      => 'Synced to vector store but failed to update local database',
                );
                continue;
            }

            $results['synced']++;
        }

        return $results;
    }

    /**
     * Build file attributes for vector store metadata filtering.
     *
     * Returns an associative array of key => value pairs for OpenAI
     * vector store file attributes. Used for metadata filtering during
     * file_search queries (e.g., price range, stock status, on_sale).
     *
     * OpenAI allows max 16 attribute keys per file. Values must be
     * strings or numbers (no arrays/booleans).
     *
     * @since 1.9.0
     * @param array $product Product data from index table.
     * @return array Associative array of attribute key => value.
     */
    private function build_file_attributes( $product ) {
        $attrs = array();

        // Price attributes.
        if ( 'variable' === ( $product['product_type'] ?? 'simple' ) ) {
            $min = $product['min_variation_price'] ?? $product['price'] ?? 0;
            $max = $product['max_variation_price'] ?? $product['price'] ?? 0;
            $attrs['price'] = (float) $min;
            $attrs['max_price'] = (float) $max;
        } else {
            $price = (float) ( $product['price'] ?? 0 );
            $attrs['price'] = $price;
            $attrs['max_price'] = $price;
        }

        // Regular price for discount detection.
        $attrs['regular_price'] = (float) ( $product['regular_price'] ?? $attrs['price'] );

        // Stock & sale.
        $attrs['stock_status'] = $product['stock_status'] ?? 'instock';
        $attrs['on_sale'] = ! empty( $product['is_on_sale'] ) ? 'true' : 'false';
        $attrs['featured'] = ! empty( $product['is_featured'] ) ? 'true' : 'false';
        $attrs['product_type'] = $product['product_type'] ?? 'simple';

        // Rating & reviews.
        $attrs['rating'] = (float) ( $product['average_rating'] ?? 0 );
        $attrs['review_count'] = (int) ( $product['review_count'] ?? 0 );

        // Sales count (from WC product meta for popularity).
        $wc_product = wc_get_product( $product['product_id'] );
        $attrs['total_sales'] = $wc_product ? (int) $wc_product->get_total_sales() : 0;

        // Date created as unix timestamp for "newest" sorting.
        if ( $wc_product && $wc_product->get_date_created() ) {
            $attrs['date_created'] = $wc_product->get_date_created()->getTimestamp();
        } else {
            $attrs['date_created'] = 0;
        }

        // Add custom attributes from admin settings.
        $custom_attrs = $this->get_custom_filter_attributes( $product );
        foreach ( $custom_attrs as $key => $value ) {
            $attrs[ $key ] = $value;
        }

        return $attrs;
    }

    /**
     * Get custom filter attributes from admin-configured mappings.
     *
     * Reads the vector_store_custom_attributes setting and resolves
     * product meta values for each mapping. Max 5 custom attributes.
     *
     * @since 1.9.0
     * @param array $product Product data from index table.
     * @return array Associative array of custom attribute key => value.
     */
    private function get_custom_filter_attributes( $product ) {
        $custom = array();
        $mappings = $this->settings->get( 'vector_store_custom_attributes', array() );

        if ( empty( $mappings ) || ! is_array( $mappings ) ) {
            return $custom;
        }

        $wc_product = wc_get_product( $product['product_id'] );
        if ( ! $wc_product ) {
            return $custom;
        }

        foreach ( $mappings as $mapping ) {
            $meta_key = $mapping['meta_key'] ?? '';
            $attr_key = $mapping['attribute_key'] ?? '';
            $type = $mapping['type'] ?? 'string';

            if ( empty( $meta_key ) || empty( $attr_key ) ) {
                continue;
            }

            // Sanitize attribute key (alphanumeric + underscore only).
            $attr_key = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $attr_key ) );

            $value = $wc_product->get_meta( $meta_key );
            if ( empty( $value ) ) {
                continue;
            }

            if ( 'number' === $type ) {
                $custom[ $attr_key ] = (float) $value;
            } else {
                $custom[ $attr_key ] = mb_substr( (string) $value, 0, 512, 'UTF-8' );
            }
        }

        return $custom;
    }

    /**
     * Format product data for vector store.
     *
     * Creates a rich JSON document optimized for RAG retrieval.
     *
     * @param array $product Product data from index.
     * @return string JSON content.
     */
    private function format_product_for_vector_store( $product ) {
        // Decode JSON fields.
        $categories       = json_decode( $product['categories'] ?? '[]', true ) ?: array();
        $tags             = json_decode( $product['tags'] ?? '[]', true ) ?: array();
        $attributes       = json_decode( $product['attributes'] ?? '{}', true ) ?: array();
        $dimensions       = json_decode( $product['dimensions'] ?? '{}', true ) ?: array();
        $available_colors = json_decode( $product['available_colors'] ?? '[]', true ) ?: array();
        $available_sizes  = json_decode( $product['available_sizes'] ?? '[]', true ) ?: array();
        $custom_attrs     = json_decode( $product['custom_attributes'] ?? '{}', true ) ?: array();

        $data = array(
            'type'              => 'product',
            'product_type'      => $product['product_type'] ?? 'simple',
            'id'                => (int) $product['product_id'],
            'sku'               => $product['sku'] ?? '',
            'name'              => $product['name'],
            'description'       => $this->clean_text( $product['description'] ),
            'short_description' => $this->clean_text( $product['short_description'] ),
            'price'             => (float) $product['price'],
            'regular_price'     => (float) $product['regular_price'],
            'sale_price'        => (float) $product['sale_price'],
            'on_sale'           => (bool) $product['is_on_sale'],
            'stock_status'      => $product['stock_status'],
            'stock_quantity'    => (int) $product['stock_quantity'],
            'categories'        => $categories,
            'tags'              => $tags,
            'attributes'        => $attributes,
            'featured'          => (bool) $product['is_featured'],
            'rating'            => (float) $product['average_rating'],
            'review_count'      => (int) $product['review_count'],
            'url'               => $product['permalink'],
            'image'             => $product['image_url'],
            'weight'            => $product['weight'] ?? '',
            'dimensions'        => $dimensions,
        );

        // Add variation data for variable products.
        if ( 'variable' === $data['product_type'] ) {
            $data['variations'] = array(
                'count'          => (int) ( $product['variation_count'] ?? 0 ),
                'min_price'      => $product['min_variation_price'] ? (float) $product['min_variation_price'] : null,
                'max_price'      => $product['max_variation_price'] ? (float) $product['max_variation_price'] : null,
                'colors'         => $available_colors,
                'sizes'          => $available_sizes,
                'custom_options' => $custom_attrs,
                'skus'           => array_filter( explode( ' ', $product['variation_skus'] ?? '' ) ),
            );

            // Price range string for display.
            if ( $data['variations']['min_price'] && $data['variations']['max_price'] ) {
                if ( $data['variations']['min_price'] !== $data['variations']['max_price'] ) {
                    $data['price_range'] = sprintf(
                        '$%.2f - $%.2f',
                        $data['variations']['min_price'],
                        $data['variations']['max_price']
                    );
                }
            }
        }

        // Build comprehensive searchable text for semantic matching.
        $search_parts = array(
            $product['name'],
            $this->clean_text( $product['short_description'] ),
            $this->clean_text( $product['description'] ),
        );

        // Add categories and tags.
        if ( ! empty( $categories ) ) {
            $search_parts[] = 'Categories: ' . implode( ', ', $categories );
        }
        if ( ! empty( $tags ) ) {
            $search_parts[] = 'Tags: ' . implode( ', ', $tags );
        }

        // Add attribute values for searchability.
        foreach ( $attributes as $attr_name => $attr_values ) {
            if ( is_array( $attr_values ) && ! empty( $attr_values ) ) {
                $attr_label = ucfirst( str_replace( array( 'pa_', '_', '-' ), array( '', ' ', ' ' ), $attr_name ) );
                $search_parts[] = $attr_label . ': ' . implode( ', ', $attr_values );
            }
        }

        // Add variation options.
        if ( ! empty( $available_colors ) ) {
            $search_parts[] = 'Available colors: ' . implode( ', ', $available_colors );
        }
        if ( ! empty( $available_sizes ) ) {
            $search_parts[] = 'Available sizes: ' . implode( ', ', $available_sizes );
        }

        // Add custom attributes (brand, material, etc.).
        foreach ( $custom_attrs as $attr_name => $attr_values ) {
            if ( is_array( $attr_values ) && ! empty( $attr_values ) ) {
                $attr_label = ucfirst( str_replace( array( '_', '-' ), ' ', $attr_name ) );
                $search_parts[] = $attr_label . ': ' . implode( ', ', $attr_values );
            }
        }

        // Add variation SKUs.
        if ( ! empty( $product['variation_skus'] ) ) {
            $search_parts[] = 'SKUs: ' . $product['sku'] . ' ' . $product['variation_skus'];
        } elseif ( ! empty( $product['sku'] ) ) {
            $search_parts[] = 'SKU: ' . $product['sku'];
        }

        // Include the dynamically discovered meta from product index.
        // This contains all custom meta, ACF fields, SEO content, etc.
        if ( ! empty( $product['search_text'] ) ) {
            // The search_text field already includes name, SKU, categories, tags,
            // attributes, and all dynamically discovered meta values.
            $search_parts[] = $product['search_text'];
        }

        $data['searchable_text'] = implode( '. ', array_filter( $search_parts ) );

        return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    /**
     * Clean text for indexing.
     *
     * @param string $text Raw text.
     * @return string Cleaned text.
     */
    private function clean_text( $text ) {
        // Strip HTML.
        $text = wp_strip_all_tags( $text );
        // Decode entities.
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        // Normalize whitespace.
        $normalized = preg_replace( '/\s+/', ' ', $text );
        // preg_replace returns null on error.
        $text = ( null !== $normalized ) ? $normalized : $text;
        return trim( $text );
    }

    /**
     * Sync a batch of products by product IDs.
     *
     * Public method that accepts product IDs and syncs them to the vector store.
     * Used by the AJAX handler for progress-tracked batch syncing.
     *
     * @param array $product_ids Array of WooCommerce product IDs to sync.
     * @return array Results with 'synced', 'errors', and 'error_messages'.
     */
    public function sync_products_batch( $product_ids ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'synced'         => 0,
                'errors'         => count( $product_ids ),
                'error_messages' => array( __( 'Vector store not configured.', 'glimmr-ai' ) ),
            );
        }

        if ( empty( $product_ids ) ) {
            return array(
                'synced'         => 0,
                'errors'         => 0,
                'error_messages' => array(),
            );
        }

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $results = array(
            'synced'         => 0,
            'errors'         => 0,
            'error_messages' => array(),
        );

        // Get product data from index table.
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE product_id IN ($placeholders)",
                $product_ids
            ),
            ARRAY_A
        );

        if ( empty( $products ) ) {
            return array(
                'synced'         => 0,
                'errors'         => count( $product_ids ),
                'error_messages' => array( __( 'No products found in index for the given IDs.', 'glimmr-ai' ) ),
            );
        }

        foreach ( $products as $product ) {
            $content = $this->format_product_for_vector_store( $product );
            // Format: product-{id}-{slug}.json enables ID extraction from search results.
            $filename = sprintf(
                'product-%d-%s.json',
                $product['product_id'],
                sanitize_title( $product['name'] )
            );

            $attributes = $this->build_file_attributes( $product );
            $sync_result = $this->openai->sync_to_vector_store(
                $content,
                $filename,
                $product['vector_file_id'] ?? null,
                $attributes
            );

            if ( is_wp_error( $sync_result ) ) {
                $results['errors']++;
                $results['error_messages'][] = sprintf(
                    /* translators: 1: product name, 2: error message */
                    __( 'Product "%1$s": %2$s', 'glimmr-ai' ),
                    $product['name'],
                    $sync_result->get_error_message()
                );
                continue;
            }

            // Update the index record.
            $update_result = $wpdb->update(
                $table,
                array(
                    'vector_file_id' => $sync_result['file_id'],
                    'last_synced_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $product['id'] ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            // Check if database update succeeded.
            if ( false === $update_result || ! empty( $wpdb->last_error ) ) {
                Glimmr_AI_Logger::error(
                    'Vector store batch sync succeeded but database update failed',
                    array(
                        'product_id'     => $product['product_id'],
                        'vector_file_id' => $sync_result['file_id'],
                        'db_error'       => $wpdb->last_error,
                    ),
                    'vector_store'
                );
                $results['errors']++;
                $results['error_messages'][] = sprintf(
                    /* translators: 1: product name */
                    __( 'Product "%1$s": Synced to vector store but failed to update local database', 'glimmr-ai' ),
                    $product['name']
                );
                continue;
            }

            $results['synced']++;
        }

        return $results;
    }

    /**
     * Remove a product from vector store.
     *
     * Only updates the database if the API operation succeeds.
     *
     * @param int $product_id WooCommerce product ID.
     * @return bool Success.
     */
    public function remove_product( $product_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $record = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d", $product_id ),
            ARRAY_A
        );

        if ( ! $record || empty( $record['vector_file_id'] ) ) {
            return true;
        }

        // Remove from vector store - check for success before updating DB.
        $remove_result = $this->openai->remove_file_from_vector_store( $record['vector_file_id'] );
        if ( is_wp_error( $remove_result ) ) {
            // API failed - don't update database, keep in sync.
            return false;
        }

        // Delete the file from OpenAI storage (best effort, don't fail on this).
        $this->openai->delete_file( $record['vector_file_id'] );

        // Only update database after API success.
        $wpdb->update(
            $table,
            array( 'vector_file_id' => null ),
            array( 'id' => $record['id'] )
        );

        return true;
    }

    /**
     * Purge ALL product files from the vector store.
     *
     * Uses file IDs stored in our database to delete from OpenAI.
     * Only clears database entries for files that were successfully deleted.
     *
     * @return array Results with deleted count, errors, and details.
     */
    public function purge_all_products() {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'success' => false,
                'error'   => __( 'Vector store not configured.', 'glimmr-ai' ),
                'deleted' => 0,
                'errors'  => 0,
            );
        }

        $results = array(
            'success' => true,
            'deleted' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        // Get all product file IDs from our database.
        $table = $wpdb->prefix . 'glimmr_ai_product_index';
        $file_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT vector_file_id FROM {$table} WHERE site_id = %d AND vector_file_id IS NOT NULL AND vector_file_id != ''",
                get_current_blog_id()
            )
        );

        $deleted_file_ids = array();

        foreach ( $file_ids as $file_id ) {
            // Remove from vector store.
            $remove_result = $this->openai->remove_file_from_vector_store( $file_id );
            if ( is_wp_error( $remove_result ) ) {
                $results['errors']++;
                $results['details'][] = array(
                    'file_id' => $file_id,
                    'error'   => $remove_result->get_error_message(),
                );
                // Don't add to deleted list - keep DB in sync.
                continue;
            }

            // Delete the file from OpenAI storage (best effort).
            $this->openai->delete_file( $file_id );
            $results['deleted']++;
            $deleted_file_ids[] = $file_id;
        }

        // Only clear vector_file_id for files that were successfully deleted.
        if ( ! empty( $deleted_file_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $deleted_file_ids ), '%s' ) );
            $query_args = array_merge( $deleted_file_ids, array( get_current_blog_id() ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET vector_file_id = NULL, last_synced_at = NULL WHERE vector_file_id IN ($placeholders) AND site_id = %d",
                    $query_args
                )
            );
        }

        return $results;
    }

    /**
     * Purge ALL knowledge files from the vector store.
     *
     * Uses file IDs stored in our database to delete from OpenAI.
     * Only clears database entries for files that were successfully deleted.
     *
     * @return array Results with deleted count, errors, and details.
     */
    public function purge_all_knowledge() {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'success' => false,
                'error'   => __( 'Vector store not configured.', 'glimmr-ai' ),
                'deleted' => 0,
                'errors'  => 0,
            );
        }

        $results = array(
            'success' => true,
            'deleted' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        // Get all knowledge file IDs from our database.
        $table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $file_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT vector_file_id FROM {$table} WHERE site_id = %d AND vector_file_id IS NOT NULL AND vector_file_id != ''",
                get_current_blog_id()
            )
        );

        $deleted_file_ids = array();

        foreach ( $file_ids as $file_id ) {
            // Remove from vector store.
            $remove_result = $this->openai->remove_file_from_vector_store( $file_id );
            if ( is_wp_error( $remove_result ) ) {
                $results['errors']++;
                $results['details'][] = array(
                    'file_id' => $file_id,
                    'error'   => $remove_result->get_error_message(),
                );
                // Don't add to deleted list - keep DB in sync.
                continue;
            }

            // Delete the file from OpenAI storage (best effort).
            $this->openai->delete_file( $file_id );
            $results['deleted']++;
            $deleted_file_ids[] = $file_id;
        }

        // Only clear vector_file_id for files that were successfully deleted.
        if ( ! empty( $deleted_file_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $deleted_file_ids ), '%s' ) );
            $query_args = array_merge( $deleted_file_ids, array( get_current_blog_id() ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET vector_file_id = NULL, sync_status = 'pending' WHERE vector_file_id IN ($placeholders) AND site_id = %d",
                    $query_args
                )
            );
        }

        return $results;
    }

    /**
     * Purge EVERYTHING from the vector store (products + knowledge).
     *
     * @return array Combined results.
     */
    public function purge_everything() {
        $product_results = $this->purge_all_products();
        $knowledge_results = $this->purge_all_knowledge();

        return array(
            'success'          => $product_results['success'] && $knowledge_results['success'],
            'products_deleted' => $product_results['deleted'],
            'products_errors'  => $product_results['errors'],
            'knowledge_deleted' => $knowledge_results['deleted'],
            'knowledge_errors' => $knowledge_results['errors'],
            'total_deleted'    => $product_results['deleted'] + $knowledge_results['deleted'],
            'total_errors'     => $product_results['errors'] + $knowledge_results['errors'],
        );
    }

    /**
     * Purge ALL files directly from the vector store via API.
     *
     * This method bypasses the database entirely - it lists all files in the
     * vector store from OpenAI and deletes them one by one. Use this when
     * the database is out of sync with the vector store.
     *
     * After deleting from OpenAI, it also clears vector_file_id from both
     * product_index and knowledge tables to reset the sync state.
     *
     * @return array Results with deleted count, errors, and file details.
     */
    public function purge_vector_store_direct() {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'success' => false,
                'error'   => __( 'Vector store not configured.', 'glimmr-ai' ),
                'deleted' => 0,
                'errors'  => 0,
            );
        }

        $results = array(
            'success' => true,
            'deleted' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        // Get all files directly from OpenAI vector store API.
        $files = $this->openai->list_all_vector_store_files();

        if ( empty( $files ) ) {
            // No files in vector store - just reset database.
            $this->reset_all_vector_file_ids();
            return array(
                'success' => true,
                'deleted' => 0,
                'errors'  => 0,
                'message' => __( 'Vector store was already empty.', 'glimmr-ai' ),
            );
        }

        // Delete each file.
        foreach ( $files as $file ) {
            $file_id = $file['id'] ?? '';
            if ( empty( $file_id ) ) {
                continue;
            }

            // Remove from vector store.
            $remove_result = $this->openai->remove_file_from_vector_store( $file_id );
            if ( is_wp_error( $remove_result ) ) {
                $results['errors']++;
                $results['details'][] = array(
                    'file_id' => $file_id,
                    'action'  => 'remove_from_store',
                    'error'   => $remove_result->get_error_message(),
                );
                continue;
            }

            // Delete the file from OpenAI storage.
            $delete_result = $this->openai->delete_file( $file_id );
            if ( is_wp_error( $delete_result ) ) {
                // File was removed from store but not deleted - log but count as success.
                $results['details'][] = array(
                    'file_id' => $file_id,
                    'action'  => 'delete_file',
                    'warning' => $delete_result->get_error_message(),
                );
            }

            $results['deleted']++;
        }

        // Reset all vector_file_id values in database to reflect the purge.
        $this->reset_all_vector_file_ids();

        return $results;
    }

    /**
     * Reset all vector_file_id values in product_index and knowledge tables.
     *
     * Used after a direct purge to bring database back in sync.
     */
    private function reset_all_vector_file_ids() {
        global $wpdb;

        $site_id = get_current_blog_id();

        // Clear product index.
        $product_table = $wpdb->prefix . 'glimmr_ai_product_index';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$product_table} SET vector_file_id = NULL, last_synced_at = NULL WHERE site_id = %d",
                $site_id
            )
        );

        // Clear knowledge table.
        $knowledge_table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$knowledge_table} SET vector_file_id = NULL, sync_status = 'pending' WHERE site_id = %d",
                $site_id
            )
        );
    }

    // =========================================================================
    // Knowledge Base Sync
    // =========================================================================

    /**
     * Sync knowledge base to vector store.
     *
     * @param bool $full_sync Force full sync.
     * @return array Sync results.
     */
    public function sync_knowledge( $full_sync = false ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return array(
                'success' => false,
                'error'   => __( 'Vector store not configured.', 'glimmr-ai' ),
            );
        }

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $results = array(
            'success' => true,
            'synced'  => 0,
            'errors'  => 0,
            'details' => array(),
        );

        // Get knowledge items that need syncing.
        if ( $full_sync ) {
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE site_id = %d",
                    get_current_blog_id()
                ),
                ARRAY_A
            );
        } else {
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE site_id = %d
                     AND (vector_file_id IS NULL OR sync_status = 'pending')",
                    get_current_blog_id()
                ),
                ARRAY_A
            );
        }

        if ( empty( $items ) ) {
            return array(
                'success' => true,
                'synced'  => 0,
                'message' => __( 'No knowledge items need syncing.', 'glimmr-ai' ),
            );
        }

        foreach ( $items as $item ) {
            $result = $this->sync_knowledge_item( $item );
            if ( $result ) {
                $results['synced']++;
            } else {
                $results['errors']++;
                $results['details'][] = array(
                    'id'    => $item['id'],
                    'title' => $item['title'],
                    'error' => $this->openai->get_last_error(),
                );
            }
        }

        return $results;
    }

    /**
     * Sync a single knowledge item.
     *
     * @param array $item Knowledge item data.
     * @return bool Success.
     */
    private function sync_knowledge_item( $item ) {
        global $wpdb;

        $content = $this->format_knowledge_for_vector_store( $item );
        $filename = sprintf( 'knowledge_%d.md', $item['id'] );

        $sync_result = $this->openai->sync_to_vector_store(
            $content,
            $filename,
            $item['vector_file_id'] ?? null
        );

        if ( is_wp_error( $sync_result ) ) {
            $wpdb->update(
                $wpdb->prefix . 'glimmr_ai_knowledge',
                array( 'sync_status' => 'error' ),
                array( 'id' => $item['id'] )
            );
            return false;
        }

        $wpdb->update(
            $wpdb->prefix . 'glimmr_ai_knowledge',
            array(
                'vector_file_id' => $sync_result['file_id'],
                'sync_status'    => 'synced',
                'last_synced_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $item['id'] )
        );

        return true;
    }

    /**
     * Sync a single knowledge item by ID.
     *
     * Public wrapper for sync_knowledge_item that fetches the item by ID.
     *
     * @param int $id Knowledge item ID.
     * @return bool Success.
     */
    public function sync_single_knowledge( $id ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return false;
        }

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $item  = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $item ) {
            return false;
        }

        return $this->sync_knowledge_item( $item );
    }

    /**
     * Format knowledge item for vector store.
     *
     * @param array $item Knowledge item.
     * @return string Markdown content.
     */
    private function format_knowledge_for_vector_store( $item ) {
        $content = "# " . ( $item['title'] ?? 'Knowledge' ) . "\n\n";
        $content .= "Type: " . $item['type'] . "\n\n";

        if ( ! empty( $item['source_type'] ) ) {
            $content .= "Source: " . $item['source_type'] . "\n\n";
        }

        $content .= "---\n\n";
        $content .= $this->clean_text( $item['content'] );

        return $content;
    }

    /**
     * Add or update a knowledge item.
     *
     * @param array $data Knowledge data.
     * @return int|WP_Error Knowledge ID or error.
     */
    public function save_knowledge( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';

        // Sanitize title and content before storage.
        $sanitized_title   = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
        $sanitized_content = isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '';

        $record = array(
            'site_id'     => get_current_blog_id(),
            'type'        => $data['type'] ?? 'custom',
            'source_id'   => $data['source_id'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'title'       => $sanitized_title,
            'content'     => $sanitized_content,
            'sync_status' => 'pending',
            'updated_at'  => current_time( 'mysql' ),
        );

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $record, array( 'id' => $data['id'] ) );
            return $data['id'];
        } else {
            $record['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $record );
            return $wpdb->insert_id;
        }
    }

    /**
     * Add a new knowledge item with immediate sync to vector store.
     *
     * Only saves to database if the vector store sync succeeds.
     * This keeps the database in sync with the vector store.
     *
     * @param array $data Knowledge data (title, content, type, etc.).
     * @return array|WP_Error Result with 'id' and 'file_id' on success, or error.
     */
    public function add_knowledge_with_sync( $data ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return new WP_Error( 'not_ready', __( 'Vector store not configured.', 'glimmr-ai' ) );
        }

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';

        // Build the content for vector store.
        $item = array(
            'title'       => $data['title'] ?? '',
            'content'     => $data['content'] ?? '',
            'type'        => $data['type'] ?? 'custom',
            'source_type' => $data['source_type'] ?? null,
        );

        $content  = $this->format_knowledge_for_vector_store( $item );
        $filename = sprintf( 'knowledge_new_%d.md', time() ); // Temp filename until we get ID.

        // Sync to vector store FIRST.
        $sync_result = $this->openai->sync_to_vector_store( $content, $filename );

        if ( is_wp_error( $sync_result ) ) {
            return $sync_result;
        }

        // Vector store succeeded - now save to database.
        $record = array(
            'site_id'        => get_current_blog_id(),
            'type'           => $data['type'] ?? 'custom',
            'source_id'      => $data['source_id'] ?? null,
            'source_type'    => $data['source_type'] ?? null,
            'title'          => $data['title'] ?? '',
            'content'        => $data['content'] ?? '',
            'vector_file_id' => $sync_result['file_id'],
            'sync_status'    => 'synced',
            'last_synced_at' => current_time( 'mysql' ),
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        );

        $wpdb->insert( $table, $record );
        $id = $wpdb->insert_id;

        if ( ! $id ) {
            // DB insert failed - clean up vector store file.
            $this->openai->remove_file_from_vector_store( $sync_result['file_id'] );
            $this->openai->delete_file( $sync_result['file_id'] );
            return new WP_Error( 'db_error', __( 'Failed to save to database.', 'glimmr-ai' ) );
        }

        return array(
            'id'      => $id,
            'file_id' => $sync_result['file_id'],
        );
    }

    /**
     * Update a knowledge item with immediate sync to vector store.
     *
     * Only updates the database if the vector store sync succeeds.
     * Handles remove + add pattern for already-synced items.
     *
     * @param int   $id   Knowledge item ID.
     * @param array $data Updated data (title, content).
     * @return array|WP_Error Result with 'id' and 'file_id' on success, or error.
     */
    public function update_knowledge_with_sync( $id, $data ) {
        global $wpdb;

        if ( ! $this->is_ready() ) {
            return new WP_Error( 'not_ready', __( 'Vector store not configured.', 'glimmr-ai' ) );
        }

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';

        // Get existing record.
        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $existing ) {
            return new WP_Error( 'not_found', __( 'Knowledge item not found.', 'glimmr-ai' ) );
        }

        // Build updated content for vector store.
        $item = array(
            'title'       => $data['title'] ?? $existing['title'],
            'content'     => $data['content'] ?? $existing['content'],
            'type'        => $existing['type'],
            'source_type' => $existing['source_type'],
        );

        $content  = $this->format_knowledge_for_vector_store( $item );
        $filename = sprintf( 'knowledge_%d.md', $id );

        // Sync to vector store FIRST (handles remove + add via old_file_id).
        $sync_result = $this->openai->sync_to_vector_store(
            $content,
            $filename,
            $existing['vector_file_id'] ?? null // Old file to remove.
        );

        if ( is_wp_error( $sync_result ) ) {
            return $sync_result;
        }

        // Vector store succeeded - now update database.
        $result = $wpdb->update(
            $table,
            array(
                'title'          => $data['title'] ?? $existing['title'],
                'content'        => $data['content'] ?? $existing['content'],
                'vector_file_id' => $sync_result['file_id'],
                'sync_status'    => 'synced',
                'last_synced_at' => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $result ) {
            // DB update failed - we have orphaned file in vector store now.
            // Log this but don't fail - the data is technically synced.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::warning(
                    'DB update failed after vector store sync',
                    array( 'id' => $id, 'file_id' => $sync_result['file_id'] ),
                    'vector_store'
                );
            }
        }

        return array(
            'id'      => $id,
            'file_id' => $sync_result['file_id'],
        );
    }

    /**
     * Delete a knowledge item.
     *
     * Only deletes from database if the vector store API operation succeeds.
     *
     * @param int $id Knowledge ID.
     * @return bool Success.
     */
    public function delete_knowledge( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $item = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $item ) {
            return false;
        }

        // Remove from vector store if synced.
        if ( ! empty( $item['vector_file_id'] ) ) {
            $remove_result = $this->openai->remove_file_from_vector_store( $item['vector_file_id'] );
            if ( is_wp_error( $remove_result ) ) {
                // API failed - don't delete from database, keep in sync.
                return false;
            }
            // Delete from OpenAI storage (best effort).
            $this->openai->delete_file( $item['vector_file_id'] );
        }

        // Only delete from database after API success (or if not synced).
        $wpdb->delete( $table, array( 'id' => $id ) );

        return true;
    }

    // =========================================================================
    // Import from WordPress Content
    // =========================================================================

    /**
     * Import pages/posts as knowledge.
     *
     * @param array $post_ids Post IDs to import.
     * @return array Results.
     */
    public function import_posts_as_knowledge( $post_ids ) {
        $results = array(
            'imported' => 0,
            'errors'   => 0,
        );

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $results['errors']++;
                continue;
            }

            $result = $this->save_knowledge( array(
                'type'        => $post->post_type === 'page' ? 'page' : 'post_type',
                'source_id'   => $post->ID,
                'source_type' => $post->post_type,
                'title'       => $post->post_title,
                'content'     => $post->post_content,
            ) );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
            } else {
                $results['imported']++;
            }
        }

        return $results;
    }

    // =========================================================================
    // Vector Store Statistics
    // =========================================================================

    /**
     * Get vector store statistics.
     *
     * @return array Statistics.
     */
    public function get_stats() {
        global $wpdb;

        $product_table = $wpdb->prefix . 'glimmr_ai_product_index';
        $knowledge_table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $site_id = get_current_blog_id();

        $stats = array(
            'products' => array(
                'total'   => 0,
                'synced'  => 0,
                'pending' => 0,
            ),
            'knowledge' => array(
                'total'   => 0,
                'synced'  => 0,
                'pending' => 0,
                'error'   => 0,
            ),
            'vector_store' => null,
        );

        // Product stats.
        $stats['products']['total'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$product_table} WHERE site_id = %d AND include_in_index = 1",
                $site_id
            )
        );
        $stats['products']['synced'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$product_table}
                 WHERE site_id = %d AND include_in_index = 1 AND vector_file_id IS NOT NULL",
                $site_id
            )
        );
        $stats['products']['pending'] = $stats['products']['total'] - $stats['products']['synced'];

        // Knowledge stats.
        $stats['knowledge']['total'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$knowledge_table} WHERE site_id = %d", $site_id )
        );
        $stats['knowledge']['synced'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE site_id = %d AND sync_status = 'synced'",
                $site_id
            )
        );
        $stats['knowledge']['pending'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE site_id = %d AND sync_status = 'pending'",
                $site_id
            )
        );
        $stats['knowledge']['error'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$knowledge_table} WHERE site_id = %d AND sync_status = 'error'",
                $site_id
            )
        );

        // Vector store info from OpenAI.
        if ( $this->is_ready() ) {
            $store = $this->openai->get_vector_store();
            if ( ! is_wp_error( $store ) ) {
                $stats['vector_store'] = array(
                    'id'          => $store['id'] ?? '',
                    'name'        => $store['name'] ?? '',
                    'file_counts' => $store['file_counts'] ?? array(),
                    'status'      => $store['status'] ?? '',
                );
            }
        }

        return $stats;
    }
}
