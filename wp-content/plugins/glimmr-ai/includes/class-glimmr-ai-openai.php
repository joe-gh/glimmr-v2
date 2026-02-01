<?php
/**
 * OpenAI API Client
 *
 * Handles all communication with OpenAI APIs including Responses API,
 * vector stores, file uploads, and thread management.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_OpenAI
 *
 * OpenAI API client with support for:
 * - Responses API (with file_search and function tools)
 * - Vector Stores (file_search)
 * - File uploads for RAG
 * - Automatic retry on transient failures
 */
class Glimmr_AI_OpenAI {

    /**
     * API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.openai.com/v1';

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    private $settings;

    /**
     * HTTP client instance.
     *
     * @var Glimmr_AI_HTTP_Client
     */
    private $http_client;

    /**
     * HTTP client for file uploads.
     *
     * @var Glimmr_AI_HTTP_Client
     */
    private $upload_client;

    /**
     * API key.
     *
     * @var string
     */
    private $api_key;

    /**
     * Default model.
     *
     * @var string
     */
    private $model;

    /**
     * Vector store ID.
     *
     * @var string
     */
    private $vector_store_id;

    /**
     * Last error message.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Last API response.
     *
     * @var array
     */
    private $last_response = array();

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Settings $settings Settings instance.
     */
    public function __construct( $settings ) {
        $this->settings        = $settings;
        // Use the dedicated get_api_key() method which handles decryption.
        $this->api_key         = Glimmr_AI_Settings::get_api_key();
        $this->model           = $settings->get( 'openai_model', 'gpt-4o' );
        $this->vector_store_id = $settings->get( 'openai_vector_store_id' );

        // Initialize HTTP clients with retry logic.
        $this->http_client   = Glimmr_AI_HTTP_Client::for_openai();
        $this->upload_client = Glimmr_AI_HTTP_Client::for_uploads();
    }

    /**
     * Check if API is configured.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Check if vector store is configured.
     *
     * @return bool
     */
    public function has_vector_store() {
        return ! empty( $this->vector_store_id );
    }

    /**
     * Get last error message.
     *
     * @return string
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Get last API response.
     *
     * @return array
     */
    public function get_last_response() {
        return $this->last_response;
    }

    /**
     * Get retry history from last request.
     *
     * @return array
     */
    public function get_retry_history() {
        return $this->http_client->get_retry_history();
    }

    /**
     * Get the default headers for OpenAI API requests.
     *
     * @return array
     */
    private function get_headers() {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
        );
    }

    /**
     * Make API request with automatic retry on transient failures.
     *
     * @param string $endpoint API endpoint.
     * @param array  $body     Request body.
     * @param string $method   HTTP method.
     * @param array  $options  Additional options for HTTP client.
     * @return array|WP_Error
     */
    private function request( $endpoint, $body = array(), $method = 'POST', $options = array() ) {
        if ( ! $this->is_configured() ) {
            $this->last_error = __( 'OpenAI API key not configured.', 'glimmr-ai' );
            return new WP_Error( 'api_not_configured', $this->last_error );
        }

        $url     = self::API_BASE . $endpoint;
        $headers = $this->get_headers();

        // Make the request using HTTP client with retry logic.
        $response = $this->http_client->request( $url, $method, $body, $headers, $options );

        // Handle WP_Error from HTTP client.
        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            $this->log_request_failure( $endpoint, $method, $response );
            return $response;
        }

        // Extract response data.
        $data = $response['data'] ?? array();
        $this->last_response = $data;

        // Log successful request with retry info if applicable.
        $attempts = $response['attempts'] ?? 1;
        if ( $attempts > 1 ) {
            $this->log_request_success_with_retries( $endpoint, $method, $attempts );
        }

        return $data;
    }

    /**
     * Log a failed request.
     *
     * @param string   $endpoint API endpoint.
     * @param string   $method   HTTP method.
     * @param WP_Error $error    Error object.
     */
    private function log_request_failure( $endpoint, $method, $error ) {
        if ( ! class_exists( 'Glimmr_AI_Logger' ) ) {
            return;
        }

        Glimmr_AI_Logger::error(
            sprintf(
                'OpenAI API request failed: [%s] %s',
                $error->get_error_code(),
                $error->get_error_message()
            ),
            array(
                'endpoint'      => $endpoint,
                'method'        => $method,
                'retry_history' => $this->http_client->get_retry_history(),
            ),
            'openai'
        );
    }

    /**
     * Log a successful request that required retries.
     *
     * @param string $endpoint API endpoint.
     * @param string $method   HTTP method.
     * @param int    $attempts Number of attempts.
     */
    private function log_request_success_with_retries( $endpoint, $method, $attempts ) {
        if ( ! class_exists( 'Glimmr_AI_Logger' ) ) {
            return;
        }

        Glimmr_AI_Logger::info(
            sprintf(
                'OpenAI API request succeeded after %d attempts',
                $attempts
            ),
            array(
                'endpoint'      => $endpoint,
                'method'        => $method,
                'retry_history' => $this->http_client->get_retry_history(),
            ),
            'openai'
        );
    }

    // =========================================================================
    // Responses API (Primary API for chat with tools and file_search)
    // =========================================================================

    /**
     * Create a response using the Responses API.
     *
     * This is the main method for chat interactions. It uses /v1/responses
     * which supports file_search (RAG) and function tools natively.
     *
     * @param array  $input          Array of input items (messages, tool outputs).
     * @param array  $tools          Array of tool definitions (functions).
     * @param string $system_prompt  System prompt (instructions).
     * @param array  $options        Additional options.
     * @return array|WP_Error Parsed response with 'content', 'function_calls', 'response_id', 'usage'.
     */
    public function create_response( $input, $tools = array(), $system_prompt = '', $options = array() ) {
        $model_id = $options['model'] ?? $this->model;
        $model_config = self::get_model_config( $model_id );

        // Fall back to defaults if model not found in config.
        if ( ! $model_config ) {
            $model_config = array(
                'max_output_tokens'  => 16384,
                'default_max_output' => 700,
                'temperature'        => array( 'supported' => true, 'recommended' => 0.3 ),
                'recommended_reasoning_effort' => null,
            );
        }

        $body = array(
            'model' => $model_id,
            'input' => $input,
            'store' => false,
        );

        // Add system prompt as instructions.
        if ( ! empty( $system_prompt ) ) {
            $body['instructions'] = $system_prompt;
        }

        // Handle max_tokens: use provided value, model default, or fallback.
        $max_tokens = $options['max_tokens'] ?? $model_config['default_max_output'];
        // Clamp to model's maximum output limit.
        if ( $max_tokens > $model_config['max_output_tokens'] ) {
            $max_tokens = $model_config['max_output_tokens'];
        }
        $body['max_output_tokens'] = (int) $max_tokens;

        // Handle temperature: only include if model supports it.
        if ( ! empty( $model_config['temperature']['supported'] ) ) {
            // Use provided temperature, or model's recommended, or 0.3 fallback.
            $temperature = $options['temperature'] ?? $model_config['temperature']['recommended'] ?? 0.3;
            // Clamp to model's min/max.
            $min_temp = $model_config['temperature']['min'] ?? 0.0;
            $max_temp = $model_config['temperature']['max'] ?? 2.0;
            $temperature = max( $min_temp, min( $max_temp, (float) $temperature ) );
            $body['temperature'] = $temperature;
        }

        // Handle reasoning effort: for GPT-5 and o-series models.
        $reasoning_config = $model_config['reasoning_effort'] ?? array();
        if ( ! empty( $reasoning_config['supported'] ) ) {
            // Priority: options > settings > model default.
            $reasoning_effort = $options['reasoning_effort']
                ?? $this->settings->get( 'reasoning_effort' )
                ?? $reasoning_config['default']
                ?? $model_config['recommended_reasoning_effort'];

            // Validate against model's available effort levels.
            $available_levels = $reasoning_config['available'] ?? array( 'low', 'medium', 'high' );
            if ( $reasoning_effort && in_array( $reasoning_effort, $available_levels, true ) ) {
                // 'none' means no reasoning - don't add the reasoning parameter.
                if ( $reasoning_effort !== 'none' ) {
                    $body['reasoning'] = array( 'effort' => $reasoning_effort );
                }
            }
        }

        // Build tools array.
        $api_tools = array();

        // Add file_search tool if vector store is configured.
        if ( $this->has_vector_store() && ( $options['use_file_search'] ?? true ) ) {
            $file_search_tool = array(
                'type'             => 'file_search',
                'vector_store_ids' => array( $this->vector_store_id ),
            );

            // Optional: limit results.
            if ( isset( $options['max_file_search_results'] ) ) {
                $file_search_tool['max_num_results'] = (int) $options['max_file_search_results'];
            }

            $api_tools[] = $file_search_tool;
        }

        // Add function tools.
        foreach ( $tools as $tool ) {
            // Convert from Chat Completions format to Responses API format if needed.
            if ( isset( $tool['type'] ) && $tool['type'] === 'function' && isset( $tool['function'] ) ) {
                // Chat Completions format: { type: 'function', function: { name, description, parameters } }
                // Responses API format: { type: 'function', name, description, parameters }
                $api_tools[] = array(
                    'type'        => 'function',
                    'name'        => $tool['function']['name'],
                    'description' => $tool['function']['description'] ?? '',
                    'parameters'  => $tool['function']['parameters'] ?? array( 'type' => 'object', 'properties' => new stdClass() ),
                );
            } else {
                // Already in correct format or other tool type.
                $api_tools[] = $tool;
            }
        }

        if ( ! empty( $api_tools ) ) {
            $body['tools'] = $api_tools;
        }

        // Include file_search results for debugging (optional).
        if ( $this->has_vector_store() && ( $options['include_file_search_results'] ?? false ) ) {
            $body['include'] = array( 'file_search_call.results' );
        }

        // Add previous response ID for multi-turn conversations.
        if ( ! empty( $options['previous_response_id'] ) ) {
            $body['previous_response_id'] = $options['previous_response_id'];
        }

        // DEBUG: Log full request body.
        $this->log_api_request( '/responses', $body );

        // Use longer timeout for responses (reasoning models may take longer).
        $base_timeout = $model_config['is_reasoning_model'] ?? false ? 120 : 90;
        $request_options = array(
            'base_timeout' => $base_timeout,
            'max_timeout'  => 180,
        );

        $response = $this->request( '/responses', $body, 'POST', $request_options );

        // DEBUG: Log full raw response.
        $this->log_api_response( $response );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_responses_api_response( $response );
    }

    /**
     * Create a response with Structured Outputs (JSON Schema).
     *
     * This method uses OpenAI's Structured Outputs feature to guarantee
     * the AI response matches a specific JSON schema. Used by the slot-filling
     * agent architecture to enforce controller output format.
     *
     * @param array  $input         Array of input items (messages, tool outputs).
     * @param array  $tools         Array of tool definitions (functions).
     * @param string $system_prompt System prompt (instructions).
     * @param array  $schema        JSON Schema for structured output.
     * @param array  $options       Additional options.
     * @return array|WP_Error Parsed response with 'content' (parsed JSON), 'response_id', 'usage'.
     */
    public function create_response_structured( $input, $tools = array(), $system_prompt = '', $schema = array(), $options = array() ) {
        $model_id = $options['model'] ?? $this->model;
        $model_config = self::get_model_config( $model_id );

        // Fall back to defaults if model not found in config.
        if ( ! $model_config ) {
            $model_config = array(
                'max_output_tokens'  => 16384,
                'default_max_output' => 1000,
                'temperature'        => array( 'supported' => true, 'recommended' => 0.2 ),
                'recommended_reasoning_effort' => null,
            );
        }

        $body = array(
            'model' => $model_id,
            'input' => $input,
            'store' => false,
        );

        // Add system prompt as instructions.
        if ( ! empty( $system_prompt ) ) {
            $body['instructions'] = $system_prompt;
        }

        // Add Structured Outputs format - this is the key addition.
        // Format for Responses API: text.format = { type, name, strict, schema }
        // Note: name must be at format level per API error, not nested in json_schema
        if ( ! empty( $schema ) ) {
            $body['text'] = array(
                'format' => array(
                    'type'   => 'json_schema',
                    'name'   => $schema['name'] ?? 'controller_output',
                    'strict' => $schema['strict'] ?? true,
                    'schema' => $schema['schema'] ?? $schema,
                ),
            );
        }

        // Handle max_tokens: use higher default for structured output (may need to output full JSON).
        // Structured output with complex schemas needs significantly more tokens than regular responses.
        $requested_tokens = $options['max_tokens'] ?? null;
        $default_tokens   = max( $model_config['default_max_output'], 3000 );
        $max_tokens       = $requested_tokens ?? $default_tokens;
        // Clamp to model's maximum output limit.
        if ( $max_tokens > $model_config['max_output_tokens'] ) {
            $max_tokens = $model_config['max_output_tokens'];
        }
        $body['max_output_tokens'] = (int) $max_tokens;

        // Log the token limit for debugging.
        Glimmr_AI_Logger::debug(
            'Structured output token limit',
            array(
                'model'              => $model_id,
                'max_output_tokens'  => $max_tokens,
                'source'             => $requested_tokens ? 'options' : 'default',
                'model_max'          => $model_config['max_output_tokens'],
            ),
            'openai'
        );

        // Handle temperature: lower for structured output (more deterministic).
        if ( ! empty( $model_config['temperature']['supported'] ) ) {
            $temperature = $options['temperature'] ?? 0.2; // Lower default for structured.
            $min_temp = $model_config['temperature']['min'] ?? 0.0;
            $max_temp = $model_config['temperature']['max'] ?? 2.0;
            $temperature = max( $min_temp, min( $max_temp, (float) $temperature ) );
            $body['temperature'] = $temperature;
        }

        // Handle reasoning effort for GPT-5 and o-series models.
        $reasoning_config = $model_config['reasoning_effort'] ?? array();
        if ( ! empty( $reasoning_config['supported'] ) ) {
            // Priority: options > settings > model default.
            $reasoning_effort = $options['reasoning_effort']
                ?? $this->settings->get( 'reasoning_effort' )
                ?? $reasoning_config['default']
                ?? $model_config['recommended_reasoning_effort'];

            // Validate against model's available effort levels.
            $available_levels = $reasoning_config['available'] ?? array( 'low', 'medium', 'high' );
            if ( $reasoning_effort && in_array( $reasoning_effort, $available_levels, true ) ) {
                // 'none' means no reasoning - don't add the reasoning parameter.
                if ( $reasoning_effort !== 'none' ) {
                    $body['reasoning'] = array( 'effort' => $reasoning_effort );
                }
            }
        }

        // NOTE: For slot-filling architecture, we do NOT pass tools to the API.
        // The AI outputs controller JSON with tool_call info, and we execute tools server-side.
        // Tool definitions are documented in the system prompt instead.
        //
        // Only add file_search if explicitly requested (for RAG scenarios).
        if ( $this->has_vector_store() && ( $options['use_file_search'] ?? false ) ) {
            $body['tools'] = array(
                array(
                    'type'             => 'file_search',
                    'vector_store_ids' => array( $this->vector_store_id ),
                    'max_num_results'  => $options['max_file_search_results'] ?? 5,
                ),
            );
        }
        // Function tools are NOT passed - the AI outputs tool calls via controller JSON.

        // Add previous response ID for multi-turn conversations.
        if ( ! empty( $options['previous_response_id'] ) ) {
            $body['previous_response_id'] = $options['previous_response_id'];
        }

        // DEBUG: Log full request body.
        $this->log_api_request( '/responses (structured)', $body );

        // Use longer timeout for responses.
        $base_timeout = $model_config['is_reasoning_model'] ?? false ? 120 : 90;
        $request_options = array(
            'base_timeout' => $base_timeout,
            'max_timeout'  => 180,
        );

        $response = $this->request( '/responses', $body, 'POST', $request_options );

        // DEBUG: Log full raw response.
        $this->log_api_response( $response );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_structured_response( $response );
    }

    /**
     * Parse Structured Outputs response.
     *
     * Extracts JSON content from structured output and parses it.
     *
     * @param array $response Raw API response.
     * @return array Parsed response with 'content' as parsed JSON.
     */
    private function parse_structured_response( $response ) {
        $result = array(
            'response_id'    => $response['id'] ?? '',
            'content'        => null,
            'raw_content'    => '',
            'function_calls' => array(),
            'usage'          => array(
                'input_tokens'  => 0,
                'output_tokens' => 0,
                'total_tokens'  => 0,
            ),
        );

        // Parse output items.
        $output = $response['output'] ?? array();
        foreach ( $output as $item ) {
            $type = $item['type'] ?? '';

            switch ( $type ) {
                case 'message':
                    // Extract text content from message (should be JSON).
                    if ( ( $item['role'] ?? '' ) === 'assistant' ) {
                        $content_parts = $item['content'] ?? array();
                        foreach ( $content_parts as $part ) {
                            if ( ( $part['type'] ?? '' ) === 'output_text' ) {
                                $result['raw_content'] .= $part['text'] ?? '';
                            }
                        }
                    }
                    break;

                case 'function_call':
                    // Extract function call (shouldn't happen with structured output, but handle it).
                    $result['function_calls'][] = array(
                        'call_id'   => $item['call_id'] ?? '',
                        'name'      => $item['name'] ?? '',
                        'arguments' => json_decode( $item['arguments'] ?? '{}', true ),
                    );
                    break;
            }
        }

        // Parse the JSON content.
        if ( ! empty( $result['raw_content'] ) ) {
            $parsed = json_decode( $result['raw_content'], true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $result['content'] = $parsed;
            } else {
                // Try to extract first JSON object if model returned concatenated objects (e.g., `{...}{...}`).
                $first_json = $this->extract_first_json_object( $result['raw_content'] );
                if ( $first_json !== null ) {
                    if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                        Glimmr_AI_Logger::info(
                            'Recovered first JSON object from concatenated output',
                            array( 'original_length' => strlen( $result['raw_content'] ) ),
                            'openai'
                        );
                    }
                    $result['content'] = $first_json;
                } else {
                    // Log parse error but don't fail.
                    if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                        Glimmr_AI_Logger::warning(
                            'Failed to parse structured output JSON',
                            array(
                                'error'       => json_last_error_msg(),
                                'raw_content' => substr( $result['raw_content'], 0, 500 ),
                            ),
                            'openai'
                        );
                    }
                    // Return raw content as fallback.
                    $result['content'] = $result['raw_content'];
                }
            }
        }

        // Parse usage.
        if ( isset( $response['usage'] ) ) {
            $result['usage'] = array(
                'input_tokens'  => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens'  => $response['usage']['total_tokens'] ?? 0,
            );
        }

        return $result;
    }

    /**
     * Create a streaming response using the Responses API.
     *
     * Similar to create_response() but uses streaming mode.
     * Calls the callback with each text chunk as it arrives.
     *
     * @param array    $input          Array of input items (messages, tool outputs).
     * @param array    $tools          Array of tool definitions (functions).
     * @param string   $system_prompt  System prompt (instructions).
     * @param callable $stream_callback Callback for streaming: fn(string $chunk) => void.
     * @param array    $options        Additional options.
     * @return array|WP_Error Parsed response with 'content', 'function_calls', 'response_id', 'usage'.
     */
    public function create_response_streaming( $input, $tools = array(), $system_prompt = '', $stream_callback = null, $options = array() ) {
        if ( ! $this->is_configured() ) {
            $this->last_error = __( 'OpenAI API key not configured.', 'glimmr-ai' );
            return new WP_Error( 'api_not_configured', $this->last_error );
        }

        $model_id = $options['model'] ?? $this->model;
        $model_config = self::get_model_config( $model_id );

        // Fall back to defaults if model not found in config.
        if ( ! $model_config ) {
            $model_config = array(
                'max_output_tokens'  => 16384,
                'default_max_output' => 700,
                'temperature'        => array( 'supported' => true, 'recommended' => 0.3 ),
                'recommended_reasoning_effort' => null,
            );
        }

        $body = array(
            'model'  => $model_id,
            'input'  => $input,
            'store'  => false,
            'stream' => true,
        );

        // Add system prompt as instructions.
        if ( ! empty( $system_prompt ) ) {
            $body['instructions'] = $system_prompt;
        }

        // Handle max_tokens.
        $max_tokens = $options['max_tokens'] ?? $model_config['default_max_output'];
        if ( $max_tokens > $model_config['max_output_tokens'] ) {
            $max_tokens = $model_config['max_output_tokens'];
        }
        $body['max_output_tokens'] = (int) $max_tokens;

        // Handle temperature.
        if ( ! empty( $model_config['temperature']['supported'] ) ) {
            $temperature = $options['temperature'] ?? $model_config['temperature']['recommended'] ?? 0.3;
            $min_temp = $model_config['temperature']['min'] ?? 0.0;
            $max_temp = $model_config['temperature']['max'] ?? 2.0;
            $temperature = max( $min_temp, min( $max_temp, (float) $temperature ) );
            $body['temperature'] = $temperature;
        }

        // Handle reasoning effort for GPT-5 and o-series models.
        $reasoning_config = $model_config['reasoning_effort'] ?? array();
        if ( ! empty( $reasoning_config['supported'] ) ) {
            // Priority: options > settings > model default.
            $reasoning_effort = $options['reasoning_effort']
                ?? $this->settings->get( 'reasoning_effort' )
                ?? $reasoning_config['default']
                ?? $model_config['recommended_reasoning_effort'];

            // Validate against model's available effort levels.
            $available_levels = $reasoning_config['available'] ?? array( 'low', 'medium', 'high' );
            if ( $reasoning_effort && in_array( $reasoning_effort, $available_levels, true ) ) {
                // 'none' means no reasoning - don't add the reasoning parameter.
                if ( $reasoning_effort !== 'none' ) {
                    $body['reasoning'] = array( 'effort' => $reasoning_effort );
                }
            }
        }

        // Build tools array.
        $api_tools = array();

        // Add file_search tool if vector store is configured.
        if ( $this->has_vector_store() && ( $options['use_file_search'] ?? true ) ) {
            $file_search_tool = array(
                'type'             => 'file_search',
                'vector_store_ids' => array( $this->vector_store_id ),
            );
            if ( isset( $options['max_file_search_results'] ) ) {
                $file_search_tool['max_num_results'] = (int) $options['max_file_search_results'];
            }
            $api_tools[] = $file_search_tool;
        }

        // Add function tools.
        foreach ( $tools as $tool ) {
            if ( isset( $tool['type'] ) && $tool['type'] === 'function' && isset( $tool['function'] ) ) {
                $api_tools[] = array(
                    'type'        => 'function',
                    'name'        => $tool['function']['name'],
                    'description' => $tool['function']['description'] ?? '',
                    'parameters'  => $tool['function']['parameters'] ?? array( 'type' => 'object', 'properties' => new stdClass() ),
                );
            } else {
                $api_tools[] = $tool;
            }
        }

        if ( ! empty( $api_tools ) ) {
            $body['tools'] = $api_tools;
        }

        // Add previous response ID for multi-turn conversations.
        if ( ! empty( $options['previous_response_id'] ) ) {
            $body['previous_response_id'] = $options['previous_response_id'];
        }

        // Make streaming request.
        return $this->request_streaming( '/responses', $body, $stream_callback );
    }

    /**
     * Make streaming API request using cURL.
     *
     * Uses cURL directly instead of WordPress HTTP API because wp_remote_post
     * doesn't properly handle Server-Sent Events (SSE) streaming responses.
     *
     * @param string   $endpoint        API endpoint.
     * @param array    $body            Request body.
     * @param callable $stream_callback Callback for text chunks.
     * @return array|WP_Error Parsed response.
     */
    private function request_streaming( $endpoint, $body, $stream_callback = null ) {
        $url = self::API_BASE . $endpoint;

        // Log the request.
        Glimmr_AI_Logger::info( 'OpenAI streaming request to ' . $endpoint . ' with model ' . ( $body['model'] ?? 'unknown' ), array(), 'openai' );

        // Accumulated result.
        $result = array(
            'response_id'    => '',
            'content'        => '',
            'function_calls' => array(),
            'file_search'    => array(),
            'usage'          => array(
                'input_tokens'  => 0,
                'output_tokens' => 0,
                'total_tokens'  => 0,
            ),
        );

        // State for parsing SSE events.
        $current_function_call = null;
        $buffer                = '';

        // Maximum buffer size to prevent unbounded memory growth (1MB).
        $max_buffer_size = 1024 * 1024;

        // Create the write callback that processes SSE data as it streams.
        $write_callback = function( $curl, $data ) use ( &$result, &$current_function_call, &$buffer, $stream_callback, $max_buffer_size ) {
            $buffer .= $data;

            // Safety check: prevent buffer from growing too large.
            if ( strlen( $buffer ) > $max_buffer_size ) {
                Glimmr_AI_Logger::error(
                    'Streaming buffer exceeded maximum size',
                    array( 'buffer_size' => strlen( $buffer ), 'max_size' => $max_buffer_size ),
                    'openai'
                );
                // Clear buffer and return 0 to stop cURL.
                $buffer = '';
                return 0;
            }

            // Process complete lines from the buffer.
            while ( ( $newline_pos = strpos( $buffer, "\n" ) ) !== false ) {
                $line   = substr( $buffer, 0, $newline_pos );
                $buffer = substr( $buffer, $newline_pos + 1 );
                $line   = trim( $line );

                // Skip empty lines and comments.
                if ( empty( $line ) || strpos( $line, ':' ) === 0 ) {
                    continue;
                }

                // Parse data lines.
                if ( strpos( $line, 'data: ' ) === 0 ) {
                    $data_content = substr( $line, 6 );

                    // Check for stream end.
                    if ( $data_content === '[DONE]' ) {
                        continue;
                    }

                    $event = json_decode( $data_content, true );
                    if ( null === $event ) {
                        // Log decode failure at debug level (can be noisy).
                        if ( json_last_error() !== JSON_ERROR_NONE ) {
                            Glimmr_AI_Logger::debug(
                                'Failed to decode streaming event',
                                array(
                                    'error'   => json_last_error_msg(),
                                    'content' => substr( $data_content, 0, 100 ),
                                ),
                                'openai'
                            );
                        }
                        continue;
                    }

                    // Extract response ID.
                    if ( ! empty( $event['id'] ) ) {
                        $result['response_id'] = $event['id'];
                    }

                    // Process based on event type.
                    $event_type = $event['type'] ?? '';

                    switch ( $event_type ) {
                        case 'response.output_text.delta':
                            // Text content delta.
                            $text_chunk = $event['delta'] ?? '';
                            if ( ! empty( $text_chunk ) ) {
                                $result['content'] .= $text_chunk;
                                if ( is_callable( $stream_callback ) ) {
                                    try {
                                        call_user_func( $stream_callback, $text_chunk );
                                    } catch ( Exception $e ) {
                                        // Log but don't break streaming - connection may have closed.
                                        Glimmr_AI_Logger::debug(
                                            'Stream callback exception',
                                            array( 'error' => $e->getMessage() ),
                                            'openai'
                                        );
                                    }
                                }
                            }
                            break;

                        case 'response.function_call_arguments.delta':
                            // Function call arguments being streamed.
                            if ( $current_function_call && isset( $event['delta'] ) ) {
                                $current_function_call['arguments'] .= $event['delta'];
                            }
                            break;

                        case 'response.output_item.added':
                            // New output item - could be function call.
                            if ( isset( $event['item']['type'] ) && $event['item']['type'] === 'function_call' ) {
                                $current_function_call = array(
                                    'call_id'   => $event['item']['call_id'] ?? '',
                                    'name'      => $event['item']['name'] ?? '',
                                    'arguments' => '',
                                );
                            }
                            break;

                        case 'response.output_item.done':
                            // Output item complete - finalize function call if present.
                            if ( isset( $event['item']['type'] ) && $event['item']['type'] === 'function_call' && $current_function_call ) {
                                $current_function_call['arguments'] = json_decode(
                                    $current_function_call['arguments'] ?: '{}',
                                    true
                                );
                                $result['function_calls'][] = $current_function_call;
                                $current_function_call      = null;
                            }
                            break;

                        case 'response.done':
                            // Final event with usage info.
                            if ( isset( $event['response']['usage'] ) ) {
                                $result['usage'] = array(
                                    'input_tokens'  => $event['response']['usage']['input_tokens'] ?? 0,
                                    'output_tokens' => $event['response']['usage']['output_tokens'] ?? 0,
                                    'total_tokens'  => $event['response']['usage']['total_tokens'] ?? 0,
                                );
                            }
                            // Also extract function calls from the final response if not captured during streaming.
                            if ( empty( $result['function_calls'] ) && isset( $event['response']['output'] ) ) {
                                foreach ( $event['response']['output'] as $output_item ) {
                                    if ( isset( $output_item['type'] ) && $output_item['type'] === 'function_call' ) {
                                        $result['function_calls'][] = array(
                                            'call_id'   => $output_item['call_id'] ?? '',
                                            'name'      => $output_item['name'] ?? '',
                                            'arguments' => is_string( $output_item['arguments'] ?? '' )
                                                ? json_decode( $output_item['arguments'], true )
                                                : ( $output_item['arguments'] ?? array() ),
                                        );
                                    }
                                }
                            }
                            break;
                    }
                }
            }

            // Return the length of data processed (required by cURL).
            return strlen( $data );
        };

        // Initialize cURL.
        $ch = curl_init();

        curl_setopt_array( $ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => $write_callback,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ) );

        // Execute the request.
        $curl_result = curl_exec( $ch );
        $http_code   = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error  = curl_error( $ch );

        curl_close( $ch );

        // Log the response.
        Glimmr_AI_Logger::info( 'OpenAI streaming response: status=' . $http_code . ', content_length=' . strlen( $result['content'] ), array(
            'has_function_calls' => ! empty( $result['function_calls'] ),
            'function_count'     => count( $result['function_calls'] ),
        ), 'openai' );

        // Handle cURL errors.
        if ( $curl_result === false ) {
            $this->last_error = 'cURL error: ' . $curl_error;
            Glimmr_AI_Logger::error( 'OpenAI cURL error: ' . $curl_error, array(), 'openai' );
            return new WP_Error( 'curl_error', $this->last_error );
        }

        // Handle HTTP errors.
        if ( $http_code >= 400 ) {
            // Try to get error from accumulated content.
            $error_data = json_decode( $result['content'], true );
            $error_msg  = $error_data['error']['message'] ?? 'API request failed with status ' . $http_code;
            $error_type = $error_data['error']['type'] ?? 'unknown';
            $error_code = $error_data['error']['code'] ?? null;
            $this->last_error = $error_msg;
            Glimmr_AI_Logger::error(
                'OpenAI API error: ' . $error_msg,
                array(
                    'status'     => $http_code,
                    'error_type' => $error_type,
                    'error_code' => $error_code,
                    'raw_body'   => substr( $result['content'] ?? '', 0, 500 ),
                ),
                'openai'
            );
            return new WP_Error( 'api_error', $error_msg );
        }

        $this->last_response = $result;
        return $result;
    }

    /**
     * Parse Responses API response.
     *
     * Extracts text content, function calls, and usage from the response.
     *
     * @param array $response Raw API response.
     * @return array Parsed response.
     */
    private function parse_responses_api_response( $response ) {
        $result = array(
            'response_id'    => $response['id'] ?? '',
            'content'        => '',
            'function_calls' => array(),
            'file_search'    => array(),
            'usage'          => array(
                'input_tokens'  => 0,
                'output_tokens' => 0,
                'total_tokens'  => 0,
            ),
        );

        // Parse output items.
        $output = $response['output'] ?? array();
        foreach ( $output as $item ) {
            $type = $item['type'] ?? '';

            switch ( $type ) {
                case 'message':
                    // Extract text content from message.
                    if ( ( $item['role'] ?? '' ) === 'assistant' ) {
                        $content_parts = $item['content'] ?? array();
                        foreach ( $content_parts as $part ) {
                            if ( ( $part['type'] ?? '' ) === 'output_text' ) {
                                $result['content'] .= $part['text'] ?? '';
                            }
                        }
                    }
                    break;

                case 'function_call':
                    // Extract function call.
                    $result['function_calls'][] = array(
                        'call_id'   => $item['call_id'] ?? '',
                        'name'      => $item['name'] ?? '',
                        'arguments' => json_decode( $item['arguments'] ?? '{}', true ),
                    );
                    break;

                case 'file_search_call':
                    // Store file search info.
                    $result['file_search'][] = array(
                        'id'      => $item['id'] ?? '',
                        'status'  => $item['status'] ?? '',
                        'queries' => $item['queries'] ?? array(),
                        'results' => $item['results'] ?? null,
                    );
                    break;
            }
        }

        // Parse usage.
        if ( isset( $response['usage'] ) ) {
            $result['usage'] = array(
                'input_tokens'  => $response['usage']['input_tokens'] ?? 0,
                'output_tokens' => $response['usage']['output_tokens'] ?? 0,
                'total_tokens'  => $response['usage']['total_tokens'] ?? 0,
            );
        }

        return $result;
    }

    /**
     * Execute function calls and get follow-up response.
     *
     * When the AI returns function_call items, execute them and send
     * the results back to get a final text response.
     *
     * @param array    $original_input   Original input array.
     * @param array    $function_calls   Function calls from AI response.
     * @param callable $executor         Function to execute tool calls: fn($name, $args) => result.
     * @param array    $tools            Tool definitions.
     * @param string   $system_prompt    System prompt.
     * @param array    $options          Additional options (including previous_response_id).
     * @return array|WP_Error
     */
    public function execute_function_calls_and_respond( $original_input, $function_calls, $executor, $tools, $system_prompt = '', $options = array() ) {
        // Build new input with function call outputs.
        $new_input = $original_input;

        foreach ( $function_calls as $call ) {
            $name      = $call['name'] ?? '';
            $arguments = $call['arguments'] ?? array();
            $call_id   = $call['call_id'] ?? '';

            // Execute the function.
            $result = call_user_func( $executor, $name, $arguments );

            // Format result as string (JSON if array).
            $output = is_string( $result ) ? $result : wp_json_encode( $result );

            // Add function_call_output to input.
            $new_input[] = array(
                'type'    => 'function_call_output',
                'call_id' => $call_id,
                'output'  => $output,
            );
        }

        // Get follow-up response.
        return $this->create_response( $new_input, $tools, $system_prompt, $options );
    }

    /**
     * Convert chat messages to Responses API input format.
     *
     * Converts from: [{ role: 'user', content: '...' }, ...]
     * To Responses API input format.
     *
     * Includes validation to detect corrupt conversation history (orphan tool outputs
     * without matching function calls) which would cause OpenAI 400 errors.
     *
     * @param array $messages Chat messages array.
     * @return array Input array for Responses API.
     */
    public function messages_to_input( $messages ) {
        $input           = array();
        $known_call_ids  = array(); // Track function_call call_ids for validation.
        $last_user_msg   = null;    // Keep track of most recent user message for fallback.

        foreach ( $messages as $message ) {
            $role    = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            // Skip system messages (handled via instructions).
            if ( $role === 'system' ) {
                continue;
            }

            // Track last user message for fallback if history is corrupt.
            if ( $role === 'user' && ! empty( $content ) ) {
                $last_user_msg = array(
                    'role'    => 'user',
                    'content' => $content,
                );
            }

            // Handle assistant messages with tool calls.
            // We need to include function_call items so function_call_output can reference them.
            if ( $role === 'assistant' && ! empty( $message['tool_calls'] ) ) {
                // First, add any text content from the assistant.
                if ( ! empty( $content ) ) {
                    $input[] = array(
                        'role'    => 'assistant',
                        'content' => $content,
                    );
                }

                // Then add each function_call as a separate input item.
                foreach ( $message['tool_calls'] as $idx => $tool_call ) {
                    $tool_name = $tool_call['name'] ?? '';
                    $tool_args = is_array( $tool_call['arguments'] )
                        ? wp_json_encode( $tool_call['arguments'] )
                        : ( $tool_call['arguments'] ?? '{}' );

                    // Ensure we have a valid call_id (generate one if missing from old data).
                    $call_id = ! empty( $tool_call['call_id'] )
                        ? $tool_call['call_id']
                        : 'call_' . substr( md5( $tool_name . $tool_args . $idx ), 0, 16 );

                    // Track this call_id so we can validate tool outputs reference it.
                    $known_call_ids[ $call_id ] = true;

                    $input[] = array(
                        'type'      => 'function_call',
                        'call_id'   => $call_id,
                        'name'      => $tool_name,
                        'arguments' => $tool_args,
                    );
                }
                continue;
            }

            // Handle tool results.
            if ( $role === 'tool' ) {
                // Ensure we have a valid call_id for tool outputs.
                $tool_call_id = ! empty( $message['tool_call_id'] )
                    ? $message['tool_call_id']
                    : 'call_' . substr( md5( $content ), 0, 16 );

                // Validate: Does this tool output have a matching function_call?
                if ( ! isset( $known_call_ids[ $tool_call_id ] ) ) {
                    // Orphan tool output detected - conversation history is corrupt.
                    // This would cause OpenAI 400 error. Fall back to just the last user message.
                    Glimmr_AI_Logger::warning(
                        'Corrupt conversation history detected: orphan function_call_output without matching function_call',
                        array(
                            'orphan_call_id'  => $tool_call_id,
                            'known_call_ids'  => array_keys( $known_call_ids ),
                            'message_count'   => count( $messages ),
                            'input_count'     => count( $input ),
                        ),
                        'openai'
                    );

                    // Return only the last user message to start fresh.
                    if ( $last_user_msg ) {
                        return array( $last_user_msg );
                    }

                    // No user message found - return empty (should not happen).
                    return array();
                }

                $input[] = array(
                    'type'    => 'function_call_output',
                    'call_id' => $tool_call_id,
                    'output'  => $content,
                );
                continue;
            }

            // Regular message.
            $input[] = array(
                'role'    => $role,
                'content' => $content,
            );
        }

        return $input;
    }

    // =========================================================================
    // Debug Logging
    // =========================================================================

    /**
     * Log the full API request for debugging.
     *
     * @param string $endpoint API endpoint.
     * @param array  $body     Request body.
     */
    private function log_api_request( $endpoint, $body ) {
        // Create a copy for logging (truncate very long content).
        $log_body = $body;

        // Truncate long input content.
        if ( isset( $log_body['input'] ) && is_array( $log_body['input'] ) ) {
            foreach ( $log_body['input'] as $i => $item ) {
                if ( isset( $item['content'] ) && is_string( $item['content'] ) && strlen( $item['content'] ) > 2000 ) {
                    $log_body['input'][ $i ]['content'] = substr( $item['content'], 0, 2000 ) . '... [TRUNCATED - ' . strlen( $item['content'] ) . ' chars total]';
                }
            }
        }

        // Truncate long instructions.
        if ( isset( $log_body['instructions'] ) && strlen( $log_body['instructions'] ) > 2000 ) {
            $log_body['instructions'] = substr( $log_body['instructions'], 0, 2000 ) . '... [TRUNCATED]';
        }

        // Get model config info for logging.
        $model_id = $body['model'] ?? 'unknown';
        $model_config = self::get_model_config( $model_id );
        $model_info = array(
            'id'                 => $model_id,
            'temperature'        => $body['temperature'] ?? 'not set (model does not support)',
            'max_output_tokens'  => $body['max_output_tokens'] ?? 'not set',
            'reasoning_effort'   => isset( $body['reasoning'] ) ? $body['reasoning']['effort'] : 'not applicable',
            'supports_temp'      => $model_config ? ( $model_config['temperature']['supported'] ?? false ) : 'unknown',
        );

        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::debug(
                '=== OPENAI API REQUEST ===',
                array(
                    'endpoint'         => $endpoint,
                    'model_settings'   => $model_info,
                    'input_count'      => count( $body['input'] ?? array() ),
                    'tools_count'      => count( $body['tools'] ?? array() ),
                    'has_vector_store' => isset( $body['tools'] ) && $this->array_contains_type( $body['tools'], 'file_search' ),
                    'full_request'     => $log_body,
                ),
                'openai-debug'
            );
        }

    }

    /**
     * Check if array contains item with specific type.
     *
     * @param array  $items Array of items.
     * @param string $type  Type to find.
     * @return bool
     */
    private function array_contains_type( $items, $type ) {
        foreach ( $items as $item ) {
            if ( ( $item['type'] ?? '' ) === $type ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log the full API response for debugging.
     *
     * @param array|WP_Error $response API response.
     */
    private function log_api_response( $response ) {
        if ( is_wp_error( $response ) ) {
            $log_data = array(
                'error_code'    => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'error_data'    => $response->get_error_data(),
            );

            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::error(
                    'OpenAI API error',
                    $log_data,
                    'openai'
                );
            }
            return;
        }

        // Create a copy for logging.
        $log_response = $response;

        // Truncate long content in output.
        if ( isset( $log_response['output'] ) && is_array( $log_response['output'] ) ) {
            foreach ( $log_response['output'] as $i => $item ) {
                if ( isset( $item['content'] ) && is_array( $item['content'] ) ) {
                    foreach ( $item['content'] as $j => $part ) {
                        if ( isset( $part['text'] ) && strlen( $part['text'] ) > 2000 ) {
                            $log_response['output'][ $i ]['content'][ $j ]['text'] = substr( $part['text'], 0, 2000 ) . '... [TRUNCATED]';
                        }
                    }
                }
            }
        }

        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::debug(
                'OpenAI API response',
                array(
                    'id'               => $response['id'] ?? 'none',
                    'model'            => $response['model'] ?? 'unknown',
                    'status'           => $response['status'] ?? 'unknown',
                    'usage'            => $response['usage'] ?? array(),
                    'output_count'     => count( $response['output'] ?? array() ),
                    'has_function_calls' => $this->array_contains_type( $response['output'] ?? array(), 'function_call' ),
                    'has_file_search'  => $this->array_contains_type( $response['output'] ?? array(), 'file_search_call' ),
                ),
                'openai'
            );
        }
    }

    // =========================================================================
    // Vector Store Operations
    // =========================================================================

    /**
     * Create a vector store.
     *
     * @param string $name Vector store name.
     * @return array|WP_Error
     */
    public function create_vector_store( $name ) {
        return $this->request( '/vector_stores', array(
            'name' => $name,
        ) );
    }

    /**
     * Get vector store details.
     *
     * @param string $vector_store_id Vector store ID.
     * @return array|WP_Error
     */
    public function get_vector_store( $vector_store_id = null ) {
        $id = $vector_store_id ?? $this->vector_store_id;
        if ( empty( $id ) ) {
            return new WP_Error( 'no_vector_store', __( 'No vector store ID provided.', 'glimmr-ai' ) );
        }
        return $this->request( '/vector_stores/' . $id, array(), 'GET' );
    }

    /**
     * Delete a vector store.
     *
     * @param string $vector_store_id Vector store ID.
     * @return array|WP_Error
     */
    public function delete_vector_store( $vector_store_id ) {
        return $this->request( '/vector_stores/' . $vector_store_id, array(), 'DELETE' );
    }

    /**
     * List files in vector store.
     *
     * @param string $vector_store_id Vector store ID.
     * @return array|WP_Error
     */
    public function list_vector_store_files( $vector_store_id = null, $limit = 100, $after = null ) {
        $id = $vector_store_id ?? $this->vector_store_id;
        $params = array( 'limit' => $limit );
        if ( $after ) {
            $params['after'] = $after;
        }
        $query = http_build_query( $params );
        return $this->request( '/vector_stores/' . $id . '/files?' . $query, array(), 'GET' );
    }

    /**
     * List all files in vector store with pagination.
     *
     * @param string|null $vector_store_id Vector store ID.
     * @return array All file objects.
     */
    public function list_all_vector_store_files( $vector_store_id = null ) {
        $all_files = array();
        $after = null;

        do {
            $response = $this->list_vector_store_files( $vector_store_id, 100, $after );
            if ( is_wp_error( $response ) ) {
                break;
            }

            $files = $response['data'] ?? array();
            $all_files = array_merge( $all_files, $files );

            // Check for more pages.
            $has_more = $response['has_more'] ?? false;
            if ( $has_more && ! empty( $files ) ) {
                $last_file = end( $files );
                $after = $last_file['id'] ?? null;
            } else {
                $after = null;
            }
        } while ( $after );

        return $all_files;
    }

    /**
     * Search the vector store for relevant documents.
     *
     * Uses a minimal Responses API call with file_search to retrieve matching documents.
     * Extracts product IDs from filenames (format: product-{id}-{slug}.json).
     *
     * @param string $query   Search query.
     * @param array  $options Search options (max_results, filter).
     * @return array Array of results with product_id, score, and filename.
     */
    public function search_vector_store( $query, $options = array() ) {
        if ( ! $this->has_vector_store() ) {
            return array();
        }

        $max_results = $options['max_results'] ?? 20;

        $file_search_config = array(
            'type'             => 'file_search',
            'vector_store_ids' => array( $this->vector_store_id ),
            'max_num_results'  => $max_results,
        );

        // Add metadata filters if provided.
        if ( ! empty( $options['filters'] ) ) {
            $file_search_config['filters'] = $options['filters'];
        }

        // Use a minimal Responses API call with file_search to retrieve documents.
        // We use gpt-4o-mini for speed and low cost since we only need retrieval.
        $body = array(
            'model' => 'gpt-4o-mini',
            'input' => array(
                array(
                    'role'    => 'user',
                    'content' => sprintf( 'Find products matching: %s', $query ),
                ),
            ),
            'tools' => array( $file_search_config ),
            // Force file_search to be called.
            'tool_choice'       => 'required',
            'max_output_tokens' => 100, // Minimal - we just want the search results.
            'store'             => false,
            // Include file search results in the response.
            'include'           => array( 'file_search_call.results' ),
        );

        $response = $this->request( '/responses', $body, 'POST' );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::warning(
                    'Vector store search failed',
                    array( 'error' => $response->get_error_message() ),
                    'openai'
                );
            }
            return array();
        }

        return $this->parse_file_search_results( $response );
    }

    /**
     * Parse file search results from Responses API output.
     *
     * Extracts product IDs from filenames matching pattern: product-{id}-{slug}.json
     *
     * @param array $response Raw API response.
     * @return array Array of results with product_id, score, and filename.
     */
    private function parse_file_search_results( $response ) {
        $results = array();

        $output = $response['output'] ?? array();
        if ( empty( $output ) ) {
            return $results;
        }

        foreach ( $output as $item ) {
            $type = $item['type'] ?? '';

            if ( $type === 'file_search_call' && ! empty( $item['results'] ) ) {
                foreach ( $item['results'] as $result ) {
                    $filename = $result['filename'] ?? '';

                    // Extract product ID from filename format: product-{id}-{slug}.json
                    if ( preg_match( '/^product-(\d+)-/', $filename, $matches ) ) {
                        $results[] = array(
                            'product_id' => (int) $matches[1],
                            'score'      => $result['score'] ?? 0,
                            'filename'   => $filename,
                        );
                    }
                }
            }
        }

        // Sort by score descending.
        usort( $results, function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        return $results;
    }

    /**
     * Extract the first valid JSON object from a string.
     *
     * Handles edge cases where the model returns concatenated JSON objects
     * like `{...}{...}` instead of a single object.
     *
     * @param string $content Raw content that may contain multiple JSON objects.
     * @return array|null Parsed first JSON object or null if extraction fails.
     */
    private function extract_first_json_object( $content ) {
        $content = trim( $content );

        // Must start with opening brace.
        if ( empty( $content ) || $content[0] !== '{' ) {
            return null;
        }

        // Track brace depth to find matching close.
        $depth = 0;
        $in_string = false;
        $escape_next = false;
        $length = strlen( $content );

        for ( $i = 0; $i < $length; $i++ ) {
            $char = $content[ $i ];

            if ( $escape_next ) {
                $escape_next = false;
                continue;
            }

            if ( $char === '\\' && $in_string ) {
                $escape_next = true;
                continue;
            }

            if ( $char === '"' ) {
                $in_string = ! $in_string;
                continue;
            }

            if ( $in_string ) {
                continue;
            }

            if ( $char === '{' ) {
                $depth++;
            } elseif ( $char === '}' ) {
                $depth--;
                if ( $depth === 0 ) {
                    // Found the matching close brace - extract and parse.
                    $first_object = substr( $content, 0, $i + 1 );
                    $parsed = json_decode( $first_object, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        return $parsed;
                    }
                    return null;
                }
            }
        }

        return null;
    }

    // =========================================================================
    // File Operations
    // =========================================================================

    /**
     * Upload a file for vector store.
     *
     * Uses multipart form upload with retry logic.
     *
     * @param string $content  File content.
     * @param string $filename Filename.
     * @param string $purpose  File purpose (default: assistants).
     * @return array|WP_Error
     */
    public function upload_file( $content, $filename, $purpose = 'assistants' ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'api_not_configured', __( 'OpenAI API key not configured.', 'glimmr-ai' ) );
        }

        $url = self::API_BASE . '/files';

        // Use multipart upload with retry logic.
        $response = $this->upload_client->multipart(
            $url,
            array( 'purpose' => $purpose ),
            array(
                'file' => array(
                    'filename' => $filename,
                    'content'  => $content,
                    'type'     => 'application/json',
                ),
            ),
            array(
                'Authorization' => 'Bearer ' . $this->api_key,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->last_error = $response->get_error_message();
            return $response;
        }

        $data = $response['data'] ?? array();

        // Log success with retry info if applicable.
        $attempts = $response['attempts'] ?? 1;
        if ( $attempts > 1 && class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::info(
                sprintf( 'File upload succeeded after %d attempts', $attempts ),
                array( 'filename' => $filename ),
                'openai'
            );
        }

        return $data;
    }

    /**
     * Add file to vector store.
     *
     * @param string $file_id         OpenAI file ID.
     * @param string $vector_store_id Vector store ID.
     * @param array  $attributes      Optional metadata attributes for filtering.
     * @return array|WP_Error
     */
    public function add_file_to_vector_store( $file_id, $vector_store_id = null, $attributes = array() ) {
        $store_id = $vector_store_id ?? $this->vector_store_id;
        $body = array( 'file_id' => $file_id );

        if ( ! empty( $attributes ) ) {
            $body['attributes'] = $attributes;
        }

        return $this->request( '/vector_stores/' . $store_id . '/files', $body );
    }

    /**
     * Remove file from vector store.
     *
     * @param string $file_id         OpenAI file ID.
     * @param string $vector_store_id Vector store ID.
     * @return array|WP_Error
     */
    public function remove_file_from_vector_store( $file_id, $vector_store_id = null ) {
        $store_id = $vector_store_id ?? $this->vector_store_id;
        return $this->request( '/vector_stores/' . $store_id . '/files/' . $file_id, array(), 'DELETE' );
    }

    /**
     * Delete a file.
     *
     * @param string $file_id OpenAI file ID.
     * @return array|WP_Error
     */
    public function delete_file( $file_id ) {
        return $this->request( '/files/' . $file_id, array(), 'DELETE' );
    }

    /**
     * Get file status.
     *
     * @param string $file_id OpenAI file ID.
     * @return array|WP_Error
     */
    public function get_file( $file_id ) {
        return $this->request( '/files/' . $file_id, array(), 'GET' );
    }

    // =========================================================================
    // Batch File Upload
    // =========================================================================

    /**
     * Upload content to vector store (upload file and add to store).
     *
     * @param string $content     Content to upload.
     * @param string $filename    Filename.
     * @param string $old_file_id Previous file ID to remove.
     * @param array  $attributes  Optional metadata attributes for filtering.
     * @return array|WP_Error Contains 'file_id' on success.
     */
    public function sync_to_vector_store( $content, $filename, $old_file_id = null, $attributes = array() ) {
        // Remove old file if provided.
        if ( ! empty( $old_file_id ) ) {
            $this->remove_file_from_vector_store( $old_file_id );
            $this->delete_file( $old_file_id );
        }

        // Upload new file.
        $upload = $this->upload_file( $content, $filename );
        if ( is_wp_error( $upload ) ) {
            return $upload;
        }

        $file_id = $upload['id'] ?? '';
        if ( empty( $file_id ) ) {
            return new WP_Error( 'no_file_id', __( 'File upload did not return an ID.', 'glimmr-ai' ) );
        }

        // Add to vector store with attributes.
        $add_result = $this->add_file_to_vector_store( $file_id, null, $attributes );
        if ( is_wp_error( $add_result ) ) {
            // Clean up uploaded file if we can't add to store.
            $this->delete_file( $file_id );
            return $add_result;
        }

        return array(
            'file_id' => $file_id,
            'status'  => $add_result['status'] ?? 'pending',
        );
    }

    // =========================================================================
    // Token Counting (Estimation)
    // =========================================================================

    /**
     * Estimate token count for text.
     *
     * Uses rough estimation: ~4 characters per token for English.
     *
     * @param string $text Text to estimate.
     * @return int Estimated token count.
     */
    public function estimate_tokens( $text ) {
        return (int) ceil( strlen( $text ) / 4 );
    }

    /**
     * Estimate tokens for input array.
     *
     * @param array $input Input array.
     * @return int Estimated total tokens.
     */
    public function estimate_input_tokens( $input ) {
        $total = 0;
        foreach ( $input as $item ) {
            $content = $item['content'] ?? '';
            if ( is_array( $content ) ) {
                $content = wp_json_encode( $content );
            }
            $total += $this->estimate_tokens( (string) $content );
            $total += 4; // Overhead per item.
        }
        return $total;
    }

    // =========================================================================
    // Model Information & Configuration
    // =========================================================================

    /**
     * Get available models for dropdown display.
     *
     * @return array List of model IDs => labels.
     */
    public function get_available_models() {
        return array(
            // GPT-5 Series (Next-Gen / Agentic).
            'gpt-5.2'      => 'GPT-5.2 (Latest – Most Capable)',
            'gpt-5.1'      => 'GPT-5.1 (Improved Reasoning)',
            'gpt-5'        => 'GPT-5 (Next-Gen, Agentic)',
            'gpt-5-mini'   => 'GPT-5 Mini (Faster, Lower Cost)',
            'gpt-5-nano'   => 'GPT-5 Nano (Ultra-Fast, Minimal)',

            // GPT-4.1 Series (Best Overall / Stable).
            'gpt-4.1'      => 'GPT-4.1 (Best Overall – Recommended)',
            'gpt-4.1-mini' => 'GPT-4.1 Mini (Faster, Cheaper)',
            'gpt-4.1-nano' => 'GPT-4.1 Nano (Low Cost, Short Responses)',

            // GPT-4o Series (Fast, Conversational, Multimodal).
            'gpt-4o'       => 'GPT-4o (Fast, Multimodal)',
            'gpt-4o-mini'  => 'GPT-4o Mini (Fastest & Cheapest)',

            // Reasoning-Focused Models (Advanced).
            'o4-mini'      => 'o4-mini (Advanced Reasoning)',
            'o3-mini'      => 'o3-mini (Lightweight Reasoning)',

            // Legacy / Transitional.
            'gpt-4-turbo'  => 'GPT-4 Turbo (Legacy)',
            'gpt-4'        => 'GPT-4 (Legacy)',
        );
    }

    /**
     * Get detailed configuration for a specific model.
     *
     * Returns limits, capabilities, and recommended settings.
     *
     * @param string $model_id Model ID.
     * @return array|null Model configuration or null if not found.
     */
    public static function get_model_config( $model_id ) {
        $models = array(
            'gpt-5.2' => array(
                'id'                 => 'gpt-5.2',
                'context_window'     => 400000,
                'max_output_tokens'  => 128000,
                'default_max_output' => 700,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array(
                    'supported'   => false,
                    'min'         => null,
                    'max'         => null,
                    'recommended' => null,
                ),
                'reasoning_effort' => array(
                    'supported' => true,
                    'available' => array( 'none', 'low', 'medium', 'high', 'xhigh' ),
                    'default'   => 'low',
                ),
                'recommended_reasoning_effort' => 'low',
                'pricing' => array( 'input_per_1m' => 1.75, 'output_per_1m' => 14.00 ),
            ),

            'gpt-5.1' => array(
                'id'                 => 'gpt-5.1',
                'context_window'     => 400000,
                'max_output_tokens'  => 128000,
                'default_max_output' => 650,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort' => array(
                    'supported' => true,
                    'available' => array( 'none', 'low', 'medium', 'high' ),
                    'default'   => 'low',
                ),
                'recommended_reasoning_effort' => 'low',
                'pricing' => array( 'input_per_1m' => 1.25, 'output_per_1m' => 10.00 ),
            ),

            'gpt-5' => array(
                'id'                 => 'gpt-5',
                'context_window'     => 400000,
                'max_output_tokens'  => 128000,
                'default_max_output' => 650,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort' => array(
                    'supported' => true,
                    'available' => array( 'none', 'low', 'medium', 'high' ),
                    'default'   => 'low',
                ),
                'recommended_reasoning_effort' => 'low',
                'pricing' => array( 'input_per_1m' => 1.25, 'output_per_1m' => 10.00 ),
            ),

            'gpt-5-mini' => array(
                'id'                 => 'gpt-5-mini',
                'context_window'     => 400000,
                'max_output_tokens'  => 128000,
                'default_max_output' => 550,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort' => array(
                    'supported' => true,
                    'available' => array( 'low', 'medium' ),
                    'default'   => 'low',
                ),
                'recommended_reasoning_effort' => 'low',
                'pricing' => array( 'input_per_1m' => 0.25, 'output_per_1m' => 2.00 ),
            ),

            'gpt-5-nano' => array(
                'id'                 => 'gpt-5-nano',
                'context_window'     => 400000,
                'max_output_tokens'  => 128000,
                'default_max_output' => 450,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort' => array(
                    'supported' => true,
                    'available' => array( 'low' ),
                    'default'   => 'low',
                ),
                'recommended_reasoning_effort' => 'low',
                'pricing' => array( 'input_per_1m' => 0.05, 'output_per_1m' => 0.40 ),
            ),

            'gpt-4.1' => array(
                'id'                 => 'gpt-4.1',
                'context_window'     => 1047576,
                'max_output_tokens'  => 32768,
                'default_max_output' => 700,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.3 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 2.00, 'output_per_1m' => 8.00 ),
            ),

            'gpt-4.1-mini' => array(
                'id'                 => 'gpt-4.1-mini',
                'context_window'     => 1047576,
                'max_output_tokens'  => 32768,
                'default_max_output' => 600,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.3 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 0.40, 'output_per_1m' => 1.60 ),
            ),

            'gpt-4.1-nano' => array(
                'id'                 => 'gpt-4.1-nano',
                'context_window'     => 1047576,
                'max_output_tokens'  => 32768,
                'default_max_output' => 450,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.25 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 0.10, 'output_per_1m' => 0.40 ),
            ),

            'gpt-4o' => array(
                'id'                 => 'gpt-4o',
                'context_window'     => 128000,
                'max_output_tokens'  => 16384,
                'default_max_output' => 650,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.35 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 2.50, 'output_per_1m' => 10.00 ),
            ),

            'gpt-4o-mini' => array(
                'id'                 => 'gpt-4o-mini',
                'context_window'     => 128000,
                'max_output_tokens'  => 16384,
                'default_max_output' => 500,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.35 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 0.15, 'output_per_1m' => 0.60 ),
            ),

            'o4-mini' => array(
                'id'                 => 'o4-mini',
                'context_window'     => 200000,
                'max_output_tokens'  => 100000,
                'default_max_output' => 600,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => true,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort'   => array( 'supported' => true, 'available' => array( 'low', 'medium', 'high' ), 'default' => 'medium' ),
                'recommended_reasoning_effort' => 'medium',
                'pricing' => array( 'input_per_1m' => 1.10, 'output_per_1m' => 4.40 ),
            ),

            'o3-mini' => array(
                'id'                 => 'o3-mini',
                'context_window'     => 200000,
                'max_output_tokens'  => 100000,
                'default_max_output' => 600,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => false,
                'is_reasoning_model' => true,
                'temperature'        => array( 'supported' => false, 'min' => null, 'max' => null, 'recommended' => null ),
                'reasoning_effort'   => array( 'supported' => true, 'available' => array( 'low', 'medium', 'high' ), 'default' => 'medium' ),
                'recommended_reasoning_effort' => 'medium',
                'pricing' => array( 'input_per_1m' => 1.10, 'output_per_1m' => 4.40 ),
            ),

            'gpt-4-turbo' => array(
                'id'                 => 'gpt-4-turbo',
                'context_window'     => 128000,
                'max_output_tokens'  => 4096,
                'default_max_output' => 500,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => true,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.35 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 10.00, 'output_per_1m' => 30.00 ),
            ),

            'gpt-4' => array(
                'id'                 => 'gpt-4',
                'context_window'     => 8192,
                'max_output_tokens'  => 8192,
                'default_max_output' => 450,
                'supports_tools'     => true,
                'supports_file_search' => true,
                'supports_vision'    => false,
                'is_reasoning_model' => false,
                'temperature'        => array( 'supported' => true, 'min' => 0.0, 'max' => 2.0, 'recommended' => 0.3 ),
                'reasoning_effort'   => array( 'supported' => false, 'available' => array(), 'default' => null ),
                'recommended_reasoning_effort' => null,
                'pricing' => array( 'input_per_1m' => 30.00, 'output_per_1m' => 60.00 ),
            ),
        );

        return isset( $models[ $model_id ] ) ? $models[ $model_id ] : null;
    }

    /**
     * Get all model configurations.
     *
     * @return array All model configurations.
     */
    public static function get_all_model_configs() {
        $model_ids = array(
            'gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
            'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
            'gpt-4o', 'gpt-4o-mini',
            'o4-mini', 'o3-mini',
            'gpt-4-turbo', 'gpt-4',
        );

        $configs = array();
        foreach ( $model_ids as $id ) {
            $configs[ $id ] = self::get_model_config( $id );
        }

        return $configs;
    }

    /**
     * Get recommended model for a use case.
     *
     * @param string $use_case One of: 'quality', 'value', 'cost', 'speed'.
     * @return string Model ID.
     */
    public static function get_recommended_model( $use_case = 'value' ) {
        switch ( $use_case ) {
            case 'quality':
                return 'gpt-5.2';
            case 'value':
                return 'gpt-5-mini';
            case 'cost':
                return 'gpt-5-nano';
            case 'speed':
                return 'gpt-5-nano';
            default:
                return 'gpt-5-mini';
        }
    }

    /**
     * Estimate cost for a request based on model and token usage.
     *
     * @param string $model_id      Model ID.
     * @param int    $input_tokens  Number of input tokens.
     * @param int    $output_tokens Number of output tokens.
     * @return array Cost breakdown with 'input_cost', 'output_cost', 'total_cost'.
     */
    public static function estimate_cost( $model_id, $input_tokens, $output_tokens ) {
        $config = self::get_model_config( $model_id );

        if ( ! $config || ! isset( $config['pricing'] ) ) {
            return array(
                'input_cost'  => 0.0,
                'output_cost' => 0.0,
                'total_cost'  => 0.0,
                'currency'    => 'USD',
            );
        }

        $input_cost  = ( $input_tokens / 1000000 ) * $config['pricing']['input_per_1m'];
        $output_cost = ( $output_tokens / 1000000 ) * $config['pricing']['output_per_1m'];

        return array(
            'input_cost'  => round( $input_cost, 6 ),
            'output_cost' => round( $output_cost, 6 ),
            'total_cost'  => round( $input_cost + $output_cost, 6 ),
            'currency'    => 'USD',
        );
    }

    /**
     * Get effective settings summary for a model.
     *
     * Useful for debugging and admin display.
     *
     * @param string $model_id Model ID.
     * @param array  $options  Override options.
     * @return array Effective settings.
     */
    public static function get_effective_settings( $model_id, $options = array() ) {
        $config = self::get_model_config( $model_id );

        if ( ! $config ) {
            return array(
                'model'          => $model_id,
                'status'         => 'unknown_model',
                'error'          => 'Model configuration not found.',
            );
        }

        $settings = array(
            'model'               => $model_id,
            'context_window'      => $config['context_window'],
            'max_output_tokens'   => $config['max_output_tokens'],
            'supports_tools'      => $config['supports_tools'],
            'supports_file_search' => $config['supports_file_search'],
            'supports_vision'     => $config['supports_vision'],
            'is_reasoning_model'  => $config['is_reasoning_model'] ?? false,
        );

        // Effective max tokens.
        $requested_max = $options['max_tokens'] ?? $config['default_max_output'];
        $settings['effective_max_tokens'] = min( $requested_max, $config['max_output_tokens'] );

        // Temperature.
        if ( ! empty( $config['temperature']['supported'] ) ) {
            $temp = $options['temperature'] ?? $config['temperature']['recommended'] ?? 0.3;
            $settings['temperature'] = array(
                'value'       => $temp,
                'recommended' => $config['temperature']['recommended'],
                'range'       => sprintf( '%.1f - %.1f', $config['temperature']['min'], $config['temperature']['max'] ),
            );
        } else {
            $settings['temperature'] = array(
                'value'   => null,
                'note'    => 'Temperature not supported for this model.',
            );
        }

        // Reasoning effort.
        if ( ! empty( $config['recommended_reasoning_effort'] ) ) {
            $settings['reasoning_effort'] = array(
                'value'       => $options['reasoning_effort'] ?? $config['recommended_reasoning_effort'],
                'recommended' => $config['recommended_reasoning_effort'],
                'options'     => array( 'low', 'medium', 'high' ),
            );
        } else {
            $settings['reasoning_effort'] = null;
        }

        // Pricing.
        if ( isset( $config['pricing'] ) ) {
            $settings['pricing'] = array(
                'input_per_1m_tokens'  => '$' . number_format( $config['pricing']['input_per_1m'], 2 ),
                'output_per_1m_tokens' => '$' . number_format( $config['pricing']['output_per_1m'], 2 ),
            );
        }

        return $settings;
    }

    /**
     * Check if a model supports a specific feature.
     *
     * @param string $model_id Model ID.
     * @param string $feature  Feature name: 'tools', 'file_search', 'vision', 'temperature', 'reasoning'.
     * @return bool
     */
    public static function model_supports( $model_id, $feature ) {
        $config = self::get_model_config( $model_id );

        if ( ! $config ) {
            return false;
        }

        switch ( $feature ) {
            case 'tools':
                return ! empty( $config['supports_tools'] );
            case 'file_search':
                return ! empty( $config['supports_file_search'] );
            case 'vision':
                return ! empty( $config['supports_vision'] );
            case 'temperature':
                return ! empty( $config['temperature']['supported'] );
            case 'reasoning':
                return ! empty( $config['recommended_reasoning_effort'] );
            default:
                return false;
        }
    }

    /**
     * Test API connection.
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function test_connection() {
        $response = $this->request( '/models', array(), 'GET' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return true;
    }
}
