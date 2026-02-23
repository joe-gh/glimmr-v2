<?php
/**
 * SEO Plugin Integration for Glimmr AI.
 *
 * Integrates with Yoast SEO and Rank Math to add FAQ schema
 * from knowledge base entries on product pages.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_SEO
 *
 * Handles SEO plugin integration for FAQ schema and sitemap.
 */
class Glimmr_AI_SEO {

    /**
     * Singleton instance.
     *
     * @var Glimmr_AI_SEO|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Glimmr_AI_SEO
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'init', array( $this, 'init_seo_integration' ) );
    }

    /**
     * Initialize SEO integration based on active plugins.
     *
     * @return void
     */
    public function init_seo_integration() {
        // Check if SEO integration is enabled.
        if ( ! Glimmr_AI_Settings::get( 'seo_integration_enabled', false ) ) {
            return;
        }

        // Check if knowledge base indexing is enabled.
        if ( ! Glimmr_AI_Settings::get( 'seo_index_knowledge', true ) ) {
            return;
        }

        // Add FAQ schema to product pages.
        if ( Glimmr_AI_Settings::get( 'seo_faq_schema', true ) ) {
            add_action( 'wp_head', array( $this, 'output_faq_schema' ), 5 );
        }

        // Yoast SEO integration.
        if ( defined( 'WPSEO_VERSION' ) ) {
            $this->init_yoast_integration();
        }

        // Rank Math integration.
        if ( class_exists( 'RankMath' ) ) {
            $this->init_rankmath_integration();
        }
    }

    /**
     * Initialize Yoast SEO specific hooks.
     *
     * @return void
     */
    private function init_yoast_integration() {
        // Filter Yoast's graph output to include our FAQ schema.
        add_filter( 'wpseo_schema_graph_pieces', array( $this, 'add_yoast_faq_piece' ), 10, 2 );
    }

    /**
     * Initialize Rank Math specific hooks.
     *
     * @return void
     */
    private function init_rankmath_integration() {
        // Filter Rank Math's schema output.
        add_filter( 'rank_math/json_ld', array( $this, 'add_rankmath_faq_schema' ), 99, 2 );
    }

    /**
     * Add FAQ piece to Yoast schema graph.
     *
     * @param array                 $pieces  Schema pieces.
     * @param WPSEO_Schema_Context $context Schema context.
     * @return array Modified pieces.
     */
    public function add_yoast_faq_piece( $pieces, $context ) {
        if ( ! is_singular( 'product' ) ) {
            return $pieces;
        }

        $faqs = $this->get_product_faqs( get_the_ID() );
        if ( empty( $faqs ) ) {
            return $pieces;
        }

        $pieces[] = new Glimmr_AI_Yoast_FAQ_Piece( $faqs, $context );
        return $pieces;
    }

    /**
     * Add FAQ schema to Rank Math output.
     *
     * @param array $data   Schema data.
     * @param mixed $jsonld JSON-LD instance.
     * @return array Modified schema data.
     */
    public function add_rankmath_faq_schema( $data, $jsonld ) {
        if ( ! is_singular( 'product' ) ) {
            return $data;
        }

        $faqs = $this->get_product_faqs( get_the_ID() );
        if ( empty( $faqs ) ) {
            return $data;
        }

        // Check if FAQPage already exists in schema.
        $has_faq = false;
        foreach ( $data as $key => $schema ) {
            if ( isset( $schema['@type'] ) && $schema['@type'] === 'FAQPage' ) {
                $has_faq = true;
                // Merge our FAQs into existing FAQPage.
                foreach ( $faqs as $faq ) {
                    $data[ $key ]['mainEntity'][] = array(
                        '@type'          => 'Question',
                        'name'           => $faq['question'],
                        'acceptedAnswer' => array(
                            '@type' => 'Answer',
                            'text'  => $faq['answer'],
                        ),
                    );
                }
                break;
            }
        }

        // If no existing FAQPage, add one.
        if ( ! $has_faq ) {
            $faq_schema = array(
                '@type'      => 'FAQPage',
                'mainEntity' => array(),
            );

            foreach ( $faqs as $faq ) {
                $faq_schema['mainEntity'][] = array(
                    '@type'          => 'Question',
                    'name'           => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $faq['answer'],
                    ),
                );
            }

            $data['glimmr_faq'] = $faq_schema;
        }

        return $data;
    }

    /**
     * Output FAQ schema in wp_head for sites without SEO plugins.
     *
     * @return void
     */
    public function output_faq_schema() {
        // Skip if Yoast or Rank Math are handling schema.
        if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
            return;
        }

        if ( ! is_singular( 'product' ) ) {
            return;
        }

        $faqs = $this->get_product_faqs( get_the_ID() );
        if ( empty( $faqs ) ) {
            return;
        }

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ( $faqs as $faq ) {
            $schema['mainEntity'][] = array(
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $faq['answer'],
                ),
            );
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    /**
     * Get FAQs related to a product from knowledge base.
     *
     * Queries the knowledge base for FAQ-type entries that are
     * either product-specific or general (applicable to all products).
     *
     * @param int $product_id Product ID.
     * @return array Array of FAQs with 'question' and 'answer' keys.
     */
    private function get_product_faqs( $product_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';

        // Check if table exists.
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ) );

        if ( ! $table_exists ) {
            return array();
        }

        // Get FAQs that apply to this product or are general (no product restriction).
        // Knowledge entries can have a 'product_ids' field with comma-separated IDs.
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, content
             FROM {$table}
             WHERE type = 'faq'
             AND status = 'active'
             AND (
                 product_ids IS NULL
                 OR product_ids = ''
                 OR FIND_IN_SET(%d, product_ids) > 0
             )
             ORDER BY priority DESC, created_at DESC
             LIMIT 10",
            $product_id
        ), ARRAY_A );

        if ( empty( $results ) ) {
            return array();
        }

        $faqs = array();
        foreach ( $results as $row ) {
            $faqs[] = array(
                'question' => wp_strip_all_tags( $row['title'] ),
                'answer'   => wp_strip_all_tags( $row['content'] ),
            );
        }

        return $faqs;
    }
}

/**
 * Yoast SEO FAQ Schema Piece.
 *
 * Implements Yoast's Abstract_Schema_Piece to inject FAQPage data
 * from the Glimmr AI knowledge base into the Yoast schema graph.
 *
 * @since 1.10.0
 */
if ( class_exists( 'Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece' ) ) {

    // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
    class Glimmr_AI_Yoast_FAQ_Piece extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {

        /**
         * FAQ data.
         *
         * @var array
         */
        private $faqs;

        /**
         * Constructor.
         *
         * @param array $faqs    Array of FAQ entries with 'question' and 'answer' keys.
         * @param mixed $context Yoast schema context.
         */
        public function __construct( $faqs, $context ) {
            $this->faqs    = $faqs;
            $this->context = $context;
        }

        /**
         * Determine if this piece should be output.
         *
         * @return bool
         */
        public function is_needed() {
            return ! empty( $this->faqs );
        }

        /**
         * Generate the FAQ schema data.
         *
         * @return array Schema data.
         */
        public function generate() {
            $main_entity = array();

            foreach ( $this->faqs as $faq ) {
                $main_entity[] = array(
                    '@type'          => 'Question',
                    'name'           => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => $faq['answer'],
                    ),
                );
            }

            return array(
                '@type'      => 'FAQPage',
                '@id'        => $this->context->canonical . '#faq',
                'mainEntity' => $main_entity,
            );
        }
    }

} elseif ( ! class_exists( 'Glimmr_AI_Yoast_FAQ_Piece' ) ) {
    /**
     * Stub class when Yoast is not active.
     *
     * Prevents fatal errors if the piece is instantiated without Yoast.
     */
    // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
    class Glimmr_AI_Yoast_FAQ_Piece {
        /**
         * Constructor stub.
         *
         * @param array $faqs    FAQ data.
         * @param mixed $context Schema context.
         */
        public function __construct( $faqs, $context ) {}
    }
}
