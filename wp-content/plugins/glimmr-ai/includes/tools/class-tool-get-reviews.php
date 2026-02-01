<?php
/**
 * Get Reviews Tool
 *
 * Retrieves product reviews with ratings and verification status.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Get_Reviews
 *
 * Retrieves product reviews from WooCommerce with filtering and sorting options.
 */
class Glimmr_AI_Tool_Get_Reviews extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'get_reviews';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get product reviews with ratings and verification status. Returns reviews sorted by specified criteria with rating breakdown.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'Product ID to get reviews for',
            'required'    => true,
        ),
        'limit' => array(
            'type'        => 'integer',
            'description' => 'Maximum number of reviews to return (default 10, max 50)',
        ),
        'rating_filter' => array(
            'type'        => 'integer',
            'description' => 'Filter by star rating (1-5). Only returns reviews with this exact rating.',
        ),
        'sort' => array(
            'type'        => 'string',
            'description' => 'Sort order for reviews',
            'enum'        => array( 'newest', 'oldest', 'highest_rated', 'lowest_rated' ),
        ),
    );

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
        $limit = $this->get_int_arg( $arguments, 'limit', 10 );
        $rating_filter = $this->get_int_arg( $arguments, 'rating_filter' );
        $sort = $this->get_string_arg( $arguments, 'sort', 'newest' );

        // Validate product_id.
        if ( empty( $product_id ) ) {
            return $this->format_error(
                'missing_product_id',
                __( 'Please provide a product ID to get reviews for.', 'glimmr-ai' )
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

        // Enforce limits.
        $limit = min( max( 1, $limit ), 50 );

        // Validate rating filter.
        if ( ! empty( $rating_filter ) && ( $rating_filter < 1 || $rating_filter > 5 ) ) {
            $rating_filter = null;
        }

        // Get reviews.
        $reviews_data = $this->get_product_reviews( $product_id, $limit, $rating_filter, $sort );

        if ( empty( $reviews_data['reviews'] ) ) {
            $message = __( 'This product has no reviews yet.', 'glimmr-ai' );
            if ( ! empty( $rating_filter ) ) {
                $message = sprintf(
                    /* translators: %d: star rating */
                    __( 'This product has no %d-star reviews.', 'glimmr-ai' ),
                    $rating_filter
                );
            }

            return $this->format_outcome(
                'no_reviews',
                array(
                    'product_id'   => $product_id,
                    'product_name' => $product->get_name(),
                    'rating_filter' => $rating_filter,
                ),
                $message
            );
        }

        return $this->format_outcome(
            'reviews_found',
            array(
                'product_id'       => $product_id,
                'product_name'     => $product->get_name(),
                'average_rating'   => $product->get_average_rating(),
                'total_reviews'    => $product->get_review_count(),
                'rating_breakdown' => $reviews_data['breakdown'],
                'reviews'          => $reviews_data['reviews'],
                'filters_applied'  => array(
                    'rating_filter' => $rating_filter,
                    'sort'          => $sort,
                    'limit'         => $limit,
                ),
            ),
            sprintf(
                /* translators: 1: number of reviews, 2: product name */
                _n( 'Found %1$d review for %2$s.', 'Found %1$d reviews for %2$s.', count( $reviews_data['reviews'] ), 'glimmr-ai' ),
                count( $reviews_data['reviews'] ),
                $product->get_name()
            )
        );
    }

    /**
     * Get product reviews with filtering and sorting.
     *
     * @param int      $product_id    Product ID.
     * @param int      $limit         Maximum reviews to return.
     * @param int|null $rating_filter Filter by specific rating.
     * @param string   $sort          Sort order.
     * @return array Reviews data with breakdown.
     */
    private function get_product_reviews( $product_id, $limit, $rating_filter, $sort ) {
        // Build query args.
        $args = array(
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => $limit,
        );

        // Apply rating filter via meta query.
        if ( ! empty( $rating_filter ) ) {
            $args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => 'rating',
                    'value'   => $rating_filter,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            );
        }

        // Apply sorting.
        switch ( $sort ) {
            case 'oldest':
                $args['orderby'] = 'comment_date';
                $args['order'] = 'ASC';
                break;
            case 'highest_rated':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                $args['order'] = 'DESC';
                break;
            case 'lowest_rated':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                $args['order'] = 'ASC';
                break;
            case 'newest':
            default:
                $args['orderby'] = 'comment_date';
                $args['order'] = 'DESC';
                break;
        }

        // Get reviews.
        $comments = get_comments( $args );

        // Format reviews.
        $reviews = array();
        foreach ( $comments as $comment ) {
            $reviews[] = $this->format_review( $comment );
        }

        // Get rating breakdown (all ratings, not filtered).
        $breakdown = $this->get_rating_breakdown( $product_id );

        return array(
            'reviews'   => $reviews,
            'breakdown' => $breakdown,
        );
    }

    /**
     * Format a single review for output.
     *
     * @param WP_Comment $comment The comment/review object.
     * @return array Formatted review data.
     */
    private function format_review( $comment ) {
        $rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
        $verified = (bool) get_comment_meta( $comment->comment_ID, 'verified', true );

        return array(
            'id'            => (int) $comment->comment_ID,
            'author'        => $comment->comment_author,
            'rating'        => $rating,
            'stars'         => $this->format_stars( $rating ),
            'verified'      => $verified,
            'verified_text' => $verified ? __( 'Verified Purchase', 'glimmr-ai' ) : '',
            'content'       => wp_strip_all_tags( $comment->comment_content ),
            'date'          => $comment->comment_date,
            'date_relative' => human_time_diff( strtotime( $comment->comment_date ), time() ) . ' ' . __( 'ago', 'glimmr-ai' ),
        );
    }

    /**
     * Get rating breakdown for a product.
     *
     * @param int $product_id Product ID.
     * @return array Rating breakdown with counts and percentages.
     */
    private function get_rating_breakdown( $product_id ) {
        global $wpdb;

        // Get count for each rating level.
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

        // Initialize breakdown.
        $breakdown = array(
            5 => array( 'count' => 0, 'percentage' => 0 ),
            4 => array( 'count' => 0, 'percentage' => 0 ),
            3 => array( 'count' => 0, 'percentage' => 0 ),
            2 => array( 'count' => 0, 'percentage' => 0 ),
            1 => array( 'count' => 0, 'percentage' => 0 ),
        );

        // Populate counts.
        $total = 0;
        foreach ( $results as $row ) {
            $rating = (int) $row['rating'];
            $count = (int) $row['count'];
            if ( isset( $breakdown[ $rating ] ) ) {
                $breakdown[ $rating ]['count'] = $count;
                $total += $count;
            }
        }

        // Calculate percentages.
        if ( $total > 0 ) {
            foreach ( $breakdown as $rating => &$data ) {
                $data['percentage'] = round( ( $data['count'] / $total ) * 100 );
            }
        }

        return array(
            'total'   => $total,
            'ratings' => $breakdown,
        );
    }

    /**
     * Format rating as star characters.
     *
     * @param int $rating Rating value (1-5).
     * @return string Star representation.
     */
    private function format_stars( $rating ) {
        $filled = str_repeat( '★', $rating );
        $empty = str_repeat( '☆', 5 - $rating );
        return $filled . $empty;
    }
}
