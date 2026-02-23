<?php
/**
 * Summarize Reviews Tool
 *
 * AI-powered review summarization and Q&A. Provides review data for the main LLM to synthesize.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Summarize_Reviews
 *
 * Retrieves and formats product reviews for AI synthesis.
 * The main agent uses this data to answer questions about reviews.
 */
class Glimmr_AI_Tool_Summarize_Reviews extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'summarize_reviews';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get review data for answering questions about product reviews. Returns formatted reviews for synthesis. Use when customer asks questions like "Is it true to size?", "How is the quality?", "What do people say about...?".';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'Product ID to analyze reviews for',
            'required'    => true,
        ),
        'question' => array(
            'type'        => 'string',
            'description' => 'Specific question to answer from reviews (e.g., "Is it true to size?", "How durable is it?")',
        ),
        'aspect' => array(
            'type'        => 'string',
            'description' => 'Specific aspect to focus on when summarizing',
            'enum'        => array( 'quality', 'sizing', 'durability', 'value', 'shipping', 'overall' ),
        ),
    );

    /**
     * Maximum reviews to fetch for analysis.
     *
     * @var int
     */
    const MAX_REVIEWS_FOR_ANALYSIS = 100;

    /**
     * Maximum reviews to include in response for context.
     *
     * @var int
     */
    const MAX_REVIEWS_IN_RESPONSE = 30;

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $wc_check = $this->require_wc();
        if ( $wc_check ) {
            return $wc_check;
        }

        $product_id = $this->get_int_arg( $arguments, 'product_id' );
        $question = $this->get_string_arg( $arguments, 'question' );
        $aspect = $this->get_string_arg( $arguments, 'aspect' );

        // Validate product_id.
        if ( empty( $product_id ) ) {
            return $this->format_error(
                'missing_product_id',
                __( 'Please provide a product ID to analyze reviews for.', 'glimmr-ai' )
            );
        }

        // Validate product exists.
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return $this->format_outcome(
                'product_not_found',
                array( 'product_id' => $product_id ),
                __( 'Product not found. Please check the product ID and try again.', 'glimmr-ai' )
            );
        }

        // Get reviews for analysis.
        $reviews_data = $this->get_reviews_for_analysis( $product_id, $aspect );

        if ( empty( $reviews_data['reviews'] ) ) {
            return $this->format_outcome(
                'no_reviews',
                array(
                    'product_id'   => $product_id,
                    'product_name' => $product->get_name(),
                    'question'     => $question,
                    'aspect'       => $aspect,
                ),
                __( 'This product has no reviews to analyze. Consider looking at similar products or checking back later for reviews.', 'glimmr-ai' )
            );
        }

        // Build synthesis instructions for the main agent.
        $synthesis_instruction = $this->build_synthesis_instruction( $question, $aspect );

        return $this->format_outcome(
            'reviews_ready',
            array(
                'product_id'             => $product_id,
                'product_name'           => $product->get_name(),
                'average_rating'         => $product->get_average_rating(),
                'total_reviews'          => $product->get_review_count(),
                'reviews_analyzed'       => count( $reviews_data['reviews'] ),
                'rating_breakdown'       => $reviews_data['breakdown'],
                'question'               => $question,
                'aspect'                 => $aspect,
                'reviews'                => $reviews_data['reviews'],
                'synthesis_instruction'  => $synthesis_instruction,
            ),
            sprintf(
                /* translators: 1: number of reviews, 2: product name */
                __( 'Analyzing %1$d reviews for %2$s.', 'glimmr-ai' ),
                count( $reviews_data['reviews'] ),
                $product->get_name()
            )
        );
    }

    /**
     * Get reviews formatted for analysis.
     *
     * @param int         $product_id Product ID.
     * @param string|null $aspect     Optional aspect to filter/prioritize.
     * @return array Reviews data.
     */
    private function get_reviews_for_analysis( $product_id, $aspect = null ) {
        // Get all reviews (up to max).
        $args = array(
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => self::MAX_REVIEWS_FOR_ANALYSIS,
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        );

        $comments = get_comments( $args );

        // Format reviews for analysis.
        $reviews = array();
        $aspect_keywords = $this->get_aspect_keywords( $aspect );

        if ( ! is_array( $comments ) ) {
            $comments = array();
        }
        foreach ( $comments as $comment ) {
            if ( ! $comment instanceof \WP_Comment ) {
                continue;
            }
            $formatted = $this->format_review_for_analysis( $comment );

            // If aspect filtering, prioritize relevant reviews.
            if ( ! empty( $aspect_keywords ) ) {
                $content_lower = strtolower( $formatted['content'] );
                $is_relevant = false;
                foreach ( $aspect_keywords as $keyword ) {
                    if ( stripos( $content_lower, $keyword ) !== false ) {
                        $is_relevant = true;
                        $formatted['relevant_to_aspect'] = true;
                        break;
                    }
                }
            }

            $reviews[] = $formatted;
        }

        // If aspect filtering, sort relevant reviews first.
        if ( ! empty( $aspect_keywords ) ) {
            usort( $reviews, function( $a, $b ) {
                $a_relevant = $a['relevant_to_aspect'] ?? false;
                $b_relevant = $b['relevant_to_aspect'] ?? false;
                if ( $a_relevant === $b_relevant ) {
                    return 0;
                }
                return $a_relevant ? -1 : 1;
            });
        }

        // Limit to response size.
        $reviews = array_slice( $reviews, 0, self::MAX_REVIEWS_IN_RESPONSE );

        // Get rating breakdown.
        $breakdown = $this->get_rating_breakdown( $product_id );

        return array(
            'reviews'   => $reviews,
            'breakdown' => $breakdown,
        );
    }

    /**
     * Format a single review for AI analysis.
     *
     * @param WP_Comment $comment The review comment.
     * @return array Formatted review.
     */
    private function format_review_for_analysis( $comment ) {
        $comment_id = (int) $comment->comment_ID;
        $rating = (int) get_comment_meta( $comment_id, 'rating', true );
        $verified = (bool) get_comment_meta( $comment_id, 'verified', true );
        $content = wp_strip_all_tags( $comment->comment_content );

        // Create compact format for LLM analysis.
        $prefix = '[' . $rating . '★]';
        if ( $verified ) {
            $prefix .= ' [Verified]';
        }

        return array(
            'rating'       => $rating,
            'verified'     => $verified,
            'content'      => $content,
            'formatted'    => $prefix . ' ' . $content,
            'date'         => $comment->comment_date,
        );
    }

    /**
     * Get keywords associated with an aspect.
     *
     * @param string|null $aspect The aspect name.
     * @return array Keywords to look for.
     */
    private function get_aspect_keywords( $aspect ) {
        if ( empty( $aspect ) ) {
            return array();
        }

        $aspect_keywords = array(
            'quality'    => array( 'quality', 'material', 'construction', 'well made', 'well-made', 'craftsmanship', 'feels', 'fabric', 'build' ),
            'sizing'     => array( 'size', 'sizing', 'fit', 'fits', 'true to size', 'runs small', 'runs large', 'tight', 'loose', 'length', 'measurement' ),
            'durability' => array( 'durable', 'durability', 'lasted', 'lasts', 'holds up', 'wear', 'worn', 'broke', 'broken', 'sturdy', 'falling apart' ),
            'value'      => array( 'value', 'price', 'worth', 'money', 'expensive', 'cheap', 'affordable', 'overpriced', 'deal', 'bargain' ),
            'shipping'   => array( 'shipping', 'delivery', 'arrived', 'package', 'packaging', 'fast', 'slow', 'damaged', 'box', 'shipped' ),
            'overall'    => array(), // No filtering for overall.
        );

        return $aspect_keywords[ $aspect ] ?? array();
    }

    /**
     * Build synthesis instruction for the main LLM.
     *
     * @param string|null $question User's specific question.
     * @param string|null $aspect   Aspect to focus on.
     * @return string Instruction for synthesis.
     */
    private function build_synthesis_instruction( $question, $aspect ) {
        $instruction = 'Based on the reviews provided above, ';

        if ( ! empty( $question ) ) {
            $instruction .= 'answer this question: "' . $question . '". ';
            $instruction .= 'Cite specific reviews when possible and note if verified purchases support the answer.';
        } elseif ( ! empty( $aspect ) ) {
            $aspect_focus = array(
                'quality'    => 'summarize what customers say about the quality and materials',
                'sizing'     => 'summarize the sizing feedback - whether it runs true to size, small, or large',
                'durability' => 'summarize how durable customers find the product over time',
                'value'      => 'summarize whether customers feel they got good value for the price',
                'shipping'   => 'summarize the shipping and packaging experience',
                'overall'    => 'provide an overall summary of customer sentiment',
            );

            $instruction .= $aspect_focus[ $aspect ] ?? 'provide a helpful summary';
            $instruction .= '. Note any patterns between ratings and feedback.';
        } else {
            $instruction .= 'provide a balanced summary of customer feedback. ';
            $instruction .= 'Highlight both positive aspects and any concerns mentioned. Note the verified purchase feedback.';
        }

        return $instruction;
    }

    /**
     * Get rating breakdown for a product.
     *
     * @param int $product_id Product ID.
     * @return array Rating breakdown.
     */
    private function get_rating_breakdown( $product_id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cm.meta_value as rating, COUNT(*) as count
                FROM {$wpdb->comments} c
                INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
                WHERE c.comment_post_ID = %d
                AND c.comment_approved = '1'
                AND c.comment_type = 'review'
                AND cm.meta_key = 'rating'
                GROUP BY cm.meta_value
                ORDER BY cm.meta_value DESC",
                $product_id
            ),
            ARRAY_A
        );

        $breakdown = array(
            5 => 0,
            4 => 0,
            3 => 0,
            2 => 0,
            1 => 0,
        );

        $total = 0;
        foreach ( $results as $row ) {
            $rating = (int) $row['rating'];
            $count = (int) $row['count'];
            if ( isset( $breakdown[ $rating ] ) ) {
                $breakdown[ $rating ] = $count;
                $total += $count;
            }
        }

        // Calculate verified purchase count.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $verified_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT c.comment_ID)
                FROM {$wpdb->comments} c
                INNER JOIN {$wpdb->commentmeta} cm ON c.comment_ID = cm.comment_ID
                WHERE c.comment_post_ID = %d
                AND c.comment_approved = '1'
                AND c.comment_type = 'review'
                AND cm.meta_key = 'verified'
                AND cm.meta_value = '1'",
                $product_id
            )
        );

        return array(
            'total'           => $total,
            'verified_count'  => (int) $verified_count,
            'by_rating'       => $breakdown,
        );
    }
}
