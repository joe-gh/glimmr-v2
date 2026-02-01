<?php
/**
 * Text Answer Tool
 *
 * Provides RAG-based text answers using OpenAI's file_search
 * against the vector store containing product and knowledge data.
 * Enhanced with citation support and confidence scoring.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Text_Answer
 *
 * Uses vector store file_search for semantic retrieval of relevant
 * information to answer user questions. Returns citations for verification.
 */
class Glimmr_AI_Tool_Text_Answer extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'text_answer';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Search knowledge base for GENERAL/STATIC information only (policies, FAQs, sizing guides, brand info). NOT authoritative for live price, stock, or availability. For product-specific details use query_products(mode=details).';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'query' => array(
            'type'        => 'string',
            'description' => 'The search query or question to answer',
            'required'    => true,
            'maxLength'   => 2000,
        ),
        // New structured context object (v2 format).
        'context' => array(
            'type'        => 'object',
            'description' => 'Structured context to focus the search',
            'properties'  => array(
                'topics' => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'products', 'shipping', 'returns', 'policies', 'faq', 'store_info', 'payments' ),
                    ),
                    'description' => 'Topics to search within',
                ),
                'sources' => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'knowledge_base', 'product_catalog', 'policies', 'custom' ),
                    ),
                    'description' => 'Source types to search',
                ),
            ),
            'additionalProperties' => false,
        ),
        // Legacy context string (backward compatibility).
        'context_hint' => array(
            'type'        => 'string',
            'description' => 'DEPRECATED: Use context.topics instead. Will be removed in v2.0.',
        ),
        'require_citations' => array(
            'type'        => 'boolean',
            'description' => 'Require source citations in response (default: true)',
        ),
    );

    /**
     * OpenAI client.
     *
     * @var Glimmr_AI_OpenAI
     */
    private $openai;

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_OpenAI   $openai   OpenAI client.
     */
    public function __construct( $settings = null, $database = null, $openai = null ) {
        parent::__construct( $settings, $database );
        $this->openai = $openai;
    }

    /**
     * Set OpenAI client.
     *
     * @param Glimmr_AI_OpenAI $openai OpenAI client.
     */
    public function set_openai( $openai ) {
        $this->openai = $openai;
    }

    /**
     * Execute the tool.
     *
     * This tool performs semantic search using vector store or local fallback.
     * Returns citations for verification when available.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result with citations.
     */
    public function execute( $arguments ) {
        $query             = $this->get_string_arg( $arguments, 'query' );
        $require_citations = $this->get_bool_arg( $arguments, 'require_citations', true );

        // Extract context - support both new and legacy format.
        $context = $this->extract_context( $arguments );

        if ( empty( $query ) ) {
            return $this->format_validation_error(
                'missing_required',
                'query',
                __( 'Please provide a search query.', 'glimmr-ai' )
            );
        }

        // Validate query length to prevent resource exhaustion (v1.7.0).
        $max_query_length = 2000;
        if ( strlen( $query ) > $max_query_length ) {
            return $this->format_validation_error(
                'query_too_long',
                'query',
                sprintf(
                    /* translators: %d: maximum query length */
                    __( 'Query exceeds maximum length of %d characters. Please make your question more concise.', 'glimmr-ai' ),
                    $max_query_length
                )
            );
        }

        // Check if vector store is configured.
        $vector_store_id = Glimmr_AI_Settings::get_vector_store_id();
        if ( empty( $vector_store_id ) ) {
            // Fall back to local search with citations.
            return $this->local_search_with_citations( $query, $context, $require_citations );
        }

        // Build search query with context.
        $search_query = $this->build_search_query( $query, $context );

        // For RAG queries, return formatted for file_search.
        return $this->format_outcome(
            'searching',
            array(
                'query'              => $search_query,
                'vector_store_id'    => $vector_store_id,
                'type'               => 'file_search',
                'require_citations'  => $require_citations,
                'context'            => $context,
            ),
            __( 'Searching knowledge base...', 'glimmr-ai' )
        );
    }

    /**
     * Extract context from arguments (supports both v1 and v2 format).
     *
     * @param array $arguments Tool arguments.
     * @return array Normalized context.
     */
    private function extract_context( $arguments ) {
        // New v2 format: structured context object.
        if ( isset( $arguments['context'] ) && is_array( $arguments['context'] ) ) {
            return array(
                'topics'  => $arguments['context']['topics'] ?? array(),
                'sources' => $arguments['context']['sources'] ?? array(),
            );
        }

        // Legacy v1 format: context string or context_hint.
        $legacy_context = $this->get_string_arg( $arguments, 'context' );
        if ( empty( $legacy_context ) ) {
            $legacy_context = $this->get_string_arg( $arguments, 'context_hint' );
        }

        if ( ! empty( $legacy_context ) ) {
            // Map legacy context string to topics.
            return array(
                'topics'  => $this->map_legacy_context( $legacy_context ),
                'sources' => array(),
            );
        }

        return array(
            'topics'  => array(),
            'sources' => array(),
        );
    }

    /**
     * Map legacy context string to topics array.
     *
     * @param string $context_string Legacy context string.
     * @return array Topics array.
     */
    private function map_legacy_context( $context_string ) {
        $context_lower = strtolower( $context_string );
        $topics = array();

        $mapping = array(
            'shipping'   => 'shipping',
            'delivery'   => 'shipping',
            'return'     => 'returns',
            'refund'     => 'returns',
            'exchange'   => 'returns',
            'policy'     => 'policies',
            'policies'   => 'policies',
            'faq'        => 'faq',
            'question'   => 'faq',
            'store'      => 'store_info',
            'contact'    => 'store_info',
            'hours'      => 'store_info',
            'location'   => 'store_info',
            'payment'    => 'payments',
            'pay'        => 'payments',
            'product'    => 'products',
        );

        foreach ( $mapping as $keyword => $topic ) {
            if ( strpos( $context_lower, $keyword ) !== false && ! in_array( $topic, $topics, true ) ) {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    /**
     * Build search query with context.
     *
     * @param string $query   Base query.
     * @param array  $context Context with topics/sources.
     * @return string Enhanced query.
     */
    private function build_search_query( $query, $context ) {
        $search_query = $query;

        if ( ! empty( $context['topics'] ) ) {
            $search_query .= ' (topics: ' . implode( ', ', $context['topics'] ) . ')';
        }

        return $search_query;
    }

    /**
     * Perform local search with citation support.
     *
     * Searches the local knowledge table when vector store is not available.
     * Returns results with citations for verification.
     *
     * @param string $query             Search query.
     * @param array  $context           Context with topics/sources.
     * @param bool   $require_citations Whether to require citations.
     * @return array Search results with citations.
     */
    private function local_search_with_citations( $query, $context, $require_citations ) {
        global $wpdb;

        $knowledge_table = $wpdb->prefix . 'glimmr_ai_knowledge';
        $site_id = get_current_blog_id();

        // Build WHERE conditions.
        $where_conditions = array( 'site_id = %d' );
        $where_values = array( $site_id );

        // Search term.
        $search_term = '%' . $wpdb->esc_like( $query ) . '%';
        $where_conditions[] = '(title LIKE %s OR content LIKE %s)';
        $where_values[] = $search_term;
        $where_values[] = $search_term;

        // Filter by topics if specified.
        if ( ! empty( $context['topics'] ) ) {
            $topic_placeholders = array_fill( 0, count( $context['topics'] ), '%s' );
            $where_conditions[] = 'type IN (' . implode( ', ', $topic_placeholders ) . ')';
            $where_values = array_merge( $where_values, $context['topics'] );
        }

        // Filter by sources if specified.
        if ( ! empty( $context['sources'] ) ) {
            $source_placeholders = array_fill( 0, count( $context['sources'] ), '%s' );
            $where_conditions[] = 'source_type IN (' . implode( ', ', $source_placeholders ) . ')';
            $where_values = array_merge( $where_values, $context['sources'] );
        }

        $where_sql = implode( ' AND ', $where_conditions );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, content, type, source_type, source_id
                 FROM {$knowledge_table}
                 WHERE {$where_sql}
                 ORDER BY id DESC
                 LIMIT 5",
                ...$where_values
            ),
            ARRAY_A
        );
        // phpcs:enable

        if ( empty( $results ) ) {
            return $this->format_outcome(
                'no_results',
                array(
                    'query'        => $query,
                    'context'      => $context,
                    'citations'    => array(),
                    'confidence'   => 0,
                    'missing_info' => $context['topics'] ?: array( 'general' ),
                ),
                __( 'No matching information found in the knowledge base.', 'glimmr-ai' ),
                $this->build_follow_up_suggestion( $context )
            );
        }

        // Format results with citations.
        $citations = array();
        $content_snippets = array();
        $confidence = $this->calculate_confidence( $results, $query );

        foreach ( $results as $result ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $result['content'] ), 100 );
            $content_snippets[] = $excerpt;

            if ( $require_citations ) {
                $citations[] = array(
                    'type'       => $result['source_type'] ?: 'knowledge_base',
                    'id'         => 'kb_' . $result['id'],
                    'source_id'  => $result['source_id'],
                    'title'      => $result['title'],
                    'excerpt'    => $excerpt,
                    'topic'      => $result['type'],
                );
            }
        }

        $missing_info = $this->identify_missing_info( $context, $results );

        return $this->format_outcome(
            'found',
            array(
                'query'               => $query,
                'result_count'        => count( $results ),
                'content'             => $content_snippets,
                'citations'           => $citations,
                'confidence'          => $confidence,
                'missing_info'        => $missing_info,
                'context'             => $context,
            ),
            sprintf( __( 'Found %d relevant results.', 'glimmr-ai' ), count( $results ) ),
            $confidence < 0.7 ? $this->build_follow_up_suggestion( $context ) : null
        );
    }

    /**
     * Calculate confidence score based on search quality.
     *
     * @param array  $results Search results.
     * @param string $query   Search query.
     * @return float Confidence score 0-1.
     */
    private function calculate_confidence( $results, $query ) {
        if ( empty( $results ) ) {
            return 0;
        }

        $query_words = array_filter( explode( ' ', strtolower( $query ) ) );
        $total_score = 0;

        foreach ( $results as $result ) {
            $title_lower = strtolower( $result['title'] );
            $content_lower = strtolower( $result['content'] );
            $result_score = 0;

            foreach ( $query_words as $word ) {
                if ( strlen( $word ) < 3 ) {
                    continue;
                }
                if ( strpos( $title_lower, $word ) !== false ) {
                    $result_score += 0.3;
                }
                if ( strpos( $content_lower, $word ) !== false ) {
                    $result_score += 0.2;
                }
            }

            // Cap per-result score at 1.0.
            $total_score += min( 1.0, $result_score );
        }

        // Average across results, cap at 1.0.
        $confidence = min( 1.0, $total_score / count( $results ) );

        // Boost confidence if multiple results found.
        if ( count( $results ) >= 3 ) {
            $confidence = min( 1.0, $confidence + 0.1 );
        }

        return round( $confidence, 2 );
    }

    /**
     * Identify missing information based on context.
     *
     * @param array $context Context with topics.
     * @param array $results Search results.
     * @return array Missing information topics.
     */
    private function identify_missing_info( $context, $results ) {
        if ( empty( $context['topics'] ) ) {
            return array();
        }

        $found_topics = array_unique( array_column( $results, 'type' ) );
        return array_diff( $context['topics'], $found_topics );
    }

    /**
     * Build follow-up suggestion based on context.
     *
     * @param array $context Context with topics.
     * @return string|null Follow-up suggestion.
     */
    private function build_follow_up_suggestion( $context ) {
        if ( empty( $context['topics'] ) ) {
            return __( 'Would you like me to search for something more specific?', 'glimmr-ai' );
        }

        $suggestions = array(
            'shipping' => __( 'Would you like to know about specific shipping destinations or methods?', 'glimmr-ai' ),
            'returns'  => __( 'What product are you asking about? Return policies may vary.', 'glimmr-ai' ),
            'policies' => __( 'Which policy would you like more details about?', 'glimmr-ai' ),
            'products' => __( 'What specific product are you interested in?', 'glimmr-ai' ),
        );

        foreach ( $context['topics'] as $topic ) {
            if ( isset( $suggestions[ $topic ] ) ) {
                return $suggestions[ $topic ];
            }
        }

        return null;
    }

    /**
     * Get OpenAI tool configuration for file_search.
     *
     * This returns the file_search tool configuration that should be
     * included in the OpenAI API request.
     *
     * @return array|null File search configuration or null if not available.
     */
    public function get_file_search_config() {
        $vector_store_id = Glimmr_AI_Settings::get_vector_store_id();

        if ( empty( $vector_store_id ) ) {
            return null;
        }

        return array(
            'type' => 'file_search',
            'file_search' => array(
                'vector_store_ids' => array( $vector_store_id ),
            ),
        );
    }
}
