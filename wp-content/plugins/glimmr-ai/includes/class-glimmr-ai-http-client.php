<?php
/**
 * HTTP Client with Retry Logic
 *
 * Reusable HTTP client for external API requests with automatic retry
 * on transient failures, exponential backoff, and comprehensive error handling.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_HTTP_Client
 *
 * Provides robust HTTP request handling with:
 * - Automatic retry on transient failures
 * - Exponential backoff between retries
 * - Configurable timeouts and attempt limits
 * - Error classification (retryable vs permanent)
 * - Request/response logging
 */
class Glimmr_AI_HTTP_Client {

    /**
     * Default configuration.
     *
     * @var array
     */
    private $default_config = array(
        'max_attempts'       => 3,
        'base_timeout'       => 30,
        'max_timeout'        => 120,
        'backoff_multiplier' => 2,
        'initial_delay'      => 1,
        'max_delay'          => 30,
        'retry_on_timeout'   => true,
        'retry_on_5xx'       => true,
        'retry_on_429'       => true,
    );

    /**
     * Current configuration.
     *
     * @var array
     */
    private $config;

    /**
     * Last request details for debugging.
     *
     * @var array
     */
    private $last_request = array();

    /**
     * Last response details for debugging.
     *
     * @var array
     */
    private $last_response = array();

    /**
     * Retry history for the last request.
     *
     * @var array
     */
    private $retry_history = array();

    /**
     * HTTP status codes that indicate retryable errors.
     *
     * @var array
     */
    private const RETRYABLE_STATUS_CODES = array(
        408, // Request Timeout
        429, // Too Many Requests (Rate Limited)
        500, // Internal Server Error
        502, // Bad Gateway
        503, // Service Unavailable
        504, // Gateway Timeout
        520, // Cloudflare: Unknown Error
        521, // Cloudflare: Web Server Is Down
        522, // Cloudflare: Connection Timed Out
        523, // Cloudflare: Origin Is Unreachable
        524, // Cloudflare: A Timeout Occurred
    );

    /**
     * WP_Error codes that indicate retryable errors.
     *
     * @var array
     */
    private const RETRYABLE_WP_ERRORS = array(
        'http_request_failed',
        'http_request_timeout',
        'request_timeout',
        'connect_error',
        'ssl_connect_error',
    );

    /**
     * Constructor.
     *
     * @param array $config Optional configuration overrides.
     */
    public function __construct( $config = array() ) {
        $this->config = wp_parse_args( $config, $this->default_config );
    }

    /**
     * Make an HTTP GET request with retry logic.
     *
     * @param string $url     Request URL.
     * @param array  $headers Request headers.
     * @param array  $options Additional options.
     * @return array|WP_Error Response data or error.
     */
    public function get( $url, $headers = array(), $options = array() ) {
        return $this->request( $url, 'GET', array(), $headers, $options );
    }

    /**
     * Make an HTTP POST request with retry logic.
     *
     * @param string $url     Request URL.
     * @param mixed  $body    Request body.
     * @param array  $headers Request headers.
     * @param array  $options Additional options.
     * @return array|WP_Error Response data or error.
     */
    public function post( $url, $body = array(), $headers = array(), $options = array() ) {
        return $this->request( $url, 'POST', $body, $headers, $options );
    }

    /**
     * Make an HTTP DELETE request with retry logic.
     *
     * @param string $url     Request URL.
     * @param array  $headers Request headers.
     * @param array  $options Additional options.
     * @return array|WP_Error Response data or error.
     */
    public function delete( $url, $headers = array(), $options = array() ) {
        return $this->request( $url, 'DELETE', array(), $headers, $options );
    }

    /**
     * Make an HTTP request with retry logic.
     *
     * @param string $url     Request URL.
     * @param string $method  HTTP method.
     * @param mixed  $body    Request body.
     * @param array  $headers Request headers.
     * @param array  $options Additional options (can override config).
     * @return array|WP_Error Response data or error.
     */
    public function request( $url, $method = 'GET', $body = array(), $headers = array(), $options = array() ) {
        // Merge options with config.
        $config = wp_parse_args( $options, $this->config );

        // Reset tracking.
        $this->retry_history = array();
        $this->last_request  = array(
            'url'     => $url,
            'method'  => $method,
            'headers' => $headers,
            'body'    => $body,
            'config'  => $config,
        );

        $attempt       = 0;
        $last_error    = null;
        $current_delay = $config['initial_delay'];

        while ( $attempt < $config['max_attempts'] ) {
            $attempt++;

            // Calculate timeout with potential increase for retries.
            $timeout = min(
                $config['base_timeout'] * pow( $config['backoff_multiplier'], $attempt - 1 ),
                $config['max_timeout']
            );

            // Build request arguments.
            $args = array(
                'method'  => $method,
                'timeout' => $timeout,
                'headers' => $headers,
            );

            // Add body for non-GET requests.
            if ( ! empty( $body ) && 'GET' !== $method ) {
                if ( is_array( $body ) && $this->is_json_content_type( $headers ) ) {
                    $args['body'] = wp_json_encode( $body );
                } else {
                    $args['body'] = $body;
                }
            }

            // Log attempt.
            $attempt_start = microtime( true );

            // Make the request.
            $response = wp_remote_request( $url, $args );

            // Calculate duration.
            $duration = round( ( microtime( true ) - $attempt_start ) * 1000 );

            // Record attempt in history.
            $this->retry_history[] = array(
                'attempt'  => $attempt,
                'duration' => $duration,
                'timeout'  => $timeout,
            );

            // Handle WP_Error (connection failures, timeouts, etc.).
            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                $error_code = $response->get_error_code();

                $error_code_str = (string) $error_code;
                $this->retry_history[ $attempt - 1 ]['error']     = $error_code;
                $this->retry_history[ $attempt - 1 ]['message']   = $response->get_error_message();
                $this->retry_history[ $attempt - 1 ]['retryable'] = $this->is_retryable_wp_error( $error_code_str, $config );

                $this->log_attempt( $attempt, $config['max_attempts'], $error_code, $response->get_error_message() );

                // Check if we should retry.
                if ( $this->is_retryable_wp_error( $error_code_str, $config ) && $attempt < $config['max_attempts'] ) {
                    $this->sleep_with_backoff( $current_delay );
                    $current_delay = min( $current_delay * $config['backoff_multiplier'], $config['max_delay'] );
                    continue;
                }

                // Non-retryable or max attempts reached.
                $this->last_response = array(
                    'error'    => true,
                    'code'     => $error_code,
                    'message'  => $response->get_error_message(),
                    'attempts' => $attempt,
                );

                return $response;
            }

            // Parse response.
            $status_code   = (int) wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );
            $response_data = json_decode( $response_body, true );

            // Check for JSON decode errors.
            if ( null === $response_data && json_last_error() !== JSON_ERROR_NONE ) {
                Glimmr_AI_Logger::warning(
                    'HTTP response JSON decode failed',
                    array(
                        'error'        => json_last_error_msg(),
                        'status_code'  => $status_code,
                        'body_preview' => substr( $response_body, 0, 500 ),
                    ),
                    'http'
                );
                // Initialize as empty array to prevent null errors.
                $response_data = array();
            }

            $this->retry_history[ $attempt - 1 ]['status_code'] = $status_code;

            // Success - return the response.
            if ( $status_code >= 200 && $status_code < 300 ) {
                $this->last_response = array(
                    'success'     => true,
                    'status_code' => $status_code,
                    'data'        => $response_data,
                    'attempts'    => $attempt,
                    'raw'         => $response,
                );

                return array(
                    'success'     => true,
                    'status_code' => $status_code,
                    'data'        => $response_data,
                    'attempts'    => $attempt,
                    'raw'         => $response,
                );
            }

            // Error response - check if retryable.
            $error_message = $response_data['error']['message']
                ?? $response_data['message']
                ?? wp_remote_retrieve_response_message( $response );

            $this->retry_history[ $attempt - 1 ]['error']     = $status_code;
            $this->retry_history[ $attempt - 1 ]['message']   = $error_message;
            $this->retry_history[ $attempt - 1 ]['retryable'] = $this->is_retryable_status( $status_code, $config );

            $this->log_attempt( $attempt, $config['max_attempts'], $status_code, $error_message );

            // Handle rate limiting with Retry-After header.
            if ( 429 === $status_code && $config['retry_on_429'] ) {
                $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
                if ( $retry_after && is_numeric( $retry_after ) ) {
                    $current_delay = min( (int) $retry_after, $config['max_delay'] );
                }
            }

            // Check if we should retry.
            if ( $this->is_retryable_status( $status_code, $config ) && $attempt < $config['max_attempts'] ) {
                $this->sleep_with_backoff( $current_delay );
                $current_delay = min( $current_delay * $config['backoff_multiplier'], $config['max_delay'] );
                continue;
            }

            // Non-retryable error or max attempts reached.
            $this->last_response = array(
                'success'     => false,
                'status_code' => $status_code,
                'data'        => $response_data,
                'message'     => $error_message,
                'attempts'    => $attempt,
                'raw'         => $response,
            );

            return new WP_Error(
                'http_error_' . $status_code,
                $error_message,
                array(
                    'status'   => $status_code,
                    'response' => $response_data,
                    'attempts' => $attempt,
                )
            );
        }

        // Should not reach here, but handle edge case.
        return $last_error ?? new WP_Error(
            'max_attempts_exceeded',
            __( 'Maximum retry attempts exceeded.', 'glimmr-ai' ),
            array( 'attempts' => $attempt )
        );
    }

    /**
     * Make a multipart form request (for file uploads) with retry logic.
     *
     * @param string $url      Request URL.
     * @param array  $fields   Form fields.
     * @param array  $files    Files array: [ 'field_name' => [ 'filename' => '...', 'content' => '...', 'type' => '...' ] ].
     * @param array  $headers  Additional headers.
     * @param array  $options  Additional options.
     * @return array|WP_Error Response data or error.
     */
    public function multipart( $url, $fields = array(), $files = array(), $headers = array(), $options = array() ) {
        $boundary = wp_generate_password( 24, false );

        // Build multipart body.
        $body = '';

        // Add regular fields.
        foreach ( $fields as $name => $value ) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        // Add files.
        foreach ( $files as $field_name => $file ) {
            $filename     = $file['filename'] ?? 'file';
            $content      = $file['content'] ?? '';
            $content_type = $file['type'] ?? 'application/octet-stream';

            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $filename . '"' . "\r\n";
            $body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
            $body .= $content . "\r\n";
        }

        $body .= '--' . $boundary . '--' . "\r\n";

        // Set content type header.
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

        // Use longer timeout for file uploads.
        $options = wp_parse_args( $options, array(
            'base_timeout' => 120,
            'max_timeout'  => 300,
        ) );

        return $this->request( $url, 'POST', $body, $headers, $options );
    }

    /**
     * Check if a WP_Error code indicates a retryable error.
     *
     * @param string $error_code Error code.
     * @param array  $config     Request configuration.
     * @return bool
     */
    private function is_retryable_wp_error( $error_code, $config ) {
        // Check for timeout-related errors.
        if ( $config['retry_on_timeout'] ) {
            $timeout_patterns = array( 'timeout', 'timed_out', 'timed out' );
            foreach ( $timeout_patterns as $pattern ) {
                if ( stripos( $error_code, $pattern ) !== false ) {
                    return true;
                }
            }
        }

        return in_array( $error_code, self::RETRYABLE_WP_ERRORS, true );
    }

    /**
     * Check if an HTTP status code indicates a retryable error.
     *
     * @param int   $status_code HTTP status code.
     * @param array $config      Request configuration.
     * @return bool
     */
    private function is_retryable_status( $status_code, $config ) {
        // Rate limiting.
        if ( 429 === $status_code && $config['retry_on_429'] ) {
            return true;
        }

        // Server errors.
        if ( $status_code >= 500 && $config['retry_on_5xx'] ) {
            return in_array( $status_code, self::RETRYABLE_STATUS_CODES, true );
        }

        // Request timeout.
        if ( 408 === $status_code && $config['retry_on_timeout'] ) {
            return true;
        }

        return false;
    }

    /**
     * Check if headers indicate JSON content type.
     *
     * @param array $headers Request headers.
     * @return bool
     */
    private function is_json_content_type( $headers ) {
        foreach ( $headers as $key => $value ) {
            if ( strtolower( $key ) === 'content-type' && stripos( $value, 'application/json' ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sleep with backoff delay.
     *
     * @param float $seconds Seconds to sleep.
     */
    private function sleep_with_backoff( $seconds ) {
        // Add jitter to prevent thundering herd.
        $jitter  = $seconds * 0.1 * ( mt_rand() / mt_getrandmax() );
        $delay   = $seconds + $jitter;

        // Use usleep for sub-second precision.
        usleep( (int) ( $delay * 1000000 ) );
    }

    /**
     * Log a request attempt.
     *
     * @param int    $attempt      Current attempt number.
     * @param int    $max_attempts Maximum attempts.
     * @param mixed  $error_code   Error code or status.
     * @param string $message      Error message.
     */
    private function log_attempt( $attempt, $max_attempts, $error_code, $message ) {
        if ( ! class_exists( 'Glimmr_AI_Logger' ) ) {
            return;
        }

        $will_retry = $attempt < $max_attempts;
        $level      = $will_retry ? 'warning' : 'error';

        Glimmr_AI_Logger::log(
            $level,
            sprintf(
                'HTTP request failed (attempt %d/%d): [%s] %s%s',
                $attempt,
                $max_attempts,
                $error_code,
                $message,
                $will_retry ? ' - will retry' : ' - giving up'
            ),
            array(
                'url'     => $this->last_request['url'] ?? '',
                'method'  => $this->last_request['method'] ?? '',
                'attempt' => $attempt,
            ),
            'http'
        );
    }

    /**
     * Get the last request details.
     *
     * @return array
     */
    public function get_last_request() {
        return $this->last_request;
    }

    /**
     * Get the last response details.
     *
     * @return array
     */
    public function get_last_response() {
        return $this->last_response;
    }

    /**
     * Get the retry history for the last request.
     *
     * @return array
     */
    public function get_retry_history() {
        return $this->retry_history;
    }

    /**
     * Get the number of attempts for the last request.
     *
     * @return int
     */
    public function get_attempt_count() {
        return count( $this->retry_history );
    }

    /**
     * Create a preconfigured instance for OpenAI API requests.
     *
     * @return Glimmr_AI_HTTP_Client
     */
    public static function for_openai() {
        // Use settings if available, otherwise fallback to defaults.
        if ( class_exists( 'Glimmr_AI_Settings' ) ) {
            return new self( Glimmr_AI_Settings::get_http_client_config() );
        }

        return new self( array(
            'max_attempts'       => 3,
            'base_timeout'       => 60,
            'max_timeout'        => 180,
            'backoff_multiplier' => 2,
            'initial_delay'      => 1,
            'max_delay'          => 60,
            'retry_on_timeout'   => true,
            'retry_on_5xx'       => true,
            'retry_on_429'       => true,
        ) );
    }

    /**
     * Create a preconfigured instance from settings.
     *
     * @return Glimmr_AI_HTTP_Client
     */
    public static function from_settings() {
        if ( class_exists( 'Glimmr_AI_Settings' ) ) {
            return new self( Glimmr_AI_Settings::get_http_client_config() );
        }
        return self::for_openai();
    }

    /**
     * Create a preconfigured instance for quick API calls.
     *
     * @return Glimmr_AI_HTTP_Client
     */
    public static function for_quick_requests() {
        return new self( array(
            'max_attempts'       => 2,
            'base_timeout'       => 15,
            'max_timeout'        => 30,
            'backoff_multiplier' => 1.5,
            'initial_delay'      => 0.5,
            'max_delay'          => 5,
            'retry_on_timeout'   => true,
            'retry_on_5xx'       => true,
            'retry_on_429'       => false,
        ) );
    }

    /**
     * Create a preconfigured instance for file uploads.
     *
     * @return Glimmr_AI_HTTP_Client
     */
    public static function for_uploads() {
        return new self( array(
            'max_attempts'       => 3,
            'base_timeout'       => 120,
            'max_timeout'        => 300,
            'backoff_multiplier' => 2,
            'initial_delay'      => 2,
            'max_delay'          => 60,
            'retry_on_timeout'   => true,
            'retry_on_5xx'       => true,
            'retry_on_429'       => true,
        ) );
    }
}
