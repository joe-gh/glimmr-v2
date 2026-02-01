<?php
/**
 * OpenAI Moderation API Integration
 *
 * Filters user messages through OpenAI's Moderation API to block
 * inappropriate content before processing. This helps prevent abuse
 * and ensures the AI assistant maintains professional interactions.
 *
 * @package Glimmr_AI
 * @since 1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Moderation
 *
 * Provides moderation checking via OpenAI's Moderation API.
 * This is a defensive measure to filter out harmful content
 * before it reaches the AI assistant.
 */
class Glimmr_AI_Moderation {

	/**
	 * OpenAI Moderation API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/moderations';

	/**
	 * HTTP client instance.
	 *
	 * @var Glimmr_AI_HTTP_Client
	 */
	private $http_client;

	/**
	 * Constructor.
	 *
	 * @param Glimmr_AI_HTTP_Client|null $http_client Optional HTTP client instance.
	 */
	public function __construct( $http_client = null ) {
		$this->http_client = $http_client ?: Glimmr_AI_HTTP_Client::for_quick_requests();
	}

	/**
	 * Check message against OpenAI Moderation API.
	 *
	 * @param string $message User message to check.
	 * @return array {
	 *     @type bool   $flagged    Whether message was flagged.
	 *     @type array  $categories Flagged categories.
	 *     @type string $message    User-friendly error if flagged.
	 * }
	 */
	public function check_message( $message ) {
		// Skip if moderation is disabled.
		if ( ! Glimmr_AI_Settings::get( 'moderation_enabled', true ) ) {
			return array( 'flagged' => false );
		}

		$api_key = Glimmr_AI_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			// No API key - skip moderation (fail open).
			Glimmr_AI_Logger::debug(
				'Moderation skipped: No API key configured',
				array(),
				'moderation'
			);
			return array( 'flagged' => false );
		}

		// Skip very short messages (greetings, etc.).
		if ( strlen( $message ) < 5 ) {
			return array( 'flagged' => false );
		}

		try {
			$response = $this->http_client->post(
				self::API_ENDPOINT,
				array(
					'input' => $message,
				),
				array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				)
			);

			// Handle WP_Error.
			if ( is_wp_error( $response ) ) {
				Glimmr_AI_Logger::warning(
					'Moderation API error',
					array( 'error' => $response->get_error_message() ),
					'moderation'
				);
				// Fail open - allow message through on API error.
				return array( 'flagged' => false );
			}

			// Check for successful response.
			if ( empty( $response['success'] ) || empty( $response['data'] ) ) {
				Glimmr_AI_Logger::warning(
					'Moderation API unexpected response',
					array( 'response' => $response ),
					'moderation'
				);
				return array( 'flagged' => false );
			}

			// Validate response structure before accessing nested keys.
			$data = $response['data'];
			if ( ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) || empty( $data['results'][0] ) ) {
				Glimmr_AI_Logger::warning(
					'Moderation API response missing expected structure',
					array(
						'has_data'    => is_array( $data ),
						'has_results' => isset( $data['results'] ),
					),
					'moderation'
				);
				return array( 'flagged' => false );
			}

			$results = $data['results'][0];
			$flagged = $results['flagged'] ?? false;

			if ( ! $flagged ) {
				return array( 'flagged' => false );
			}

			// Get flagged categories.
			$flagged_categories = array();
			$blocked_categories = $this->get_blocked_categories();

			foreach ( $results['categories'] ?? array() as $category => $is_flagged ) {
				if ( $is_flagged && in_array( $category, $blocked_categories, true ) ) {
					$flagged_categories[] = $category;
				}
			}

			// If flagged but not in our blocked list, allow through.
			if ( empty( $flagged_categories ) ) {
				return array( 'flagged' => false );
			}

			Glimmr_AI_Logger::info(
				'Message moderated',
				array(
					'categories'     => $flagged_categories,
					'message_length' => strlen( $message ),
					// Don't log actual message content for privacy.
				),
				'moderation'
			);

			return array(
				'flagged'    => true,
				'categories' => $flagged_categories,
				'message'    => $this->get_user_message( $flagged_categories ),
			);

		} catch ( Throwable $e ) {
			Glimmr_AI_Logger::error(
				'Moderation check exception',
				array(
					'error' => $e->getMessage(),
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
				),
				'moderation'
			);
			// Fail open - allow message through on exception.
			return array( 'flagged' => false );
		}
	}

	/**
	 * Get list of categories to block.
	 *
	 * These are the OpenAI moderation categories that will result
	 * in a message being blocked.
	 *
	 * @return array Categories to block.
	 */
	public function get_blocked_categories() {
		/**
		 * Filter the moderation categories to block.
		 *
		 * @param array $categories Categories to block.
		 */
		return apply_filters(
			'glimmr_ai_moderation_categories',
			array(
				'hate',
				'hate/threatening',
				'harassment',
				'harassment/threatening',
				'self-harm',
				'self-harm/intent',
				'self-harm/instructions',
				'sexual',
				'sexual/minors',
				'violence',
				'violence/graphic',
			)
		);
	}

	/**
	 * Get user-friendly message for blocked content.
	 *
	 * @param array $categories Flagged categories.
	 * @return string User message.
	 */
	private function get_user_message( $categories ) {
		/**
		 * Filter the moderation rejection message.
		 *
		 * @param string $message    Default message.
		 * @param array  $categories Flagged categories.
		 */
		return apply_filters(
			'glimmr_ai_moderation_message',
			__( "I can't help with that request. Please ask about our products or services.", 'glimmr-ai' ),
			$categories
		);
	}

	/**
	 * Check if moderation is enabled.
	 *
	 * @return bool Whether moderation is enabled.
	 */
	public static function is_enabled() {
		return (bool) Glimmr_AI_Settings::get( 'moderation_enabled', true );
	}
}
