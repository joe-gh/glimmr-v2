<?php
/**
 * REST API endpoints for Glimmr AI.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_REST_API
 *
 * Handles all REST API endpoints for the chat widget and admin.
 */
class Glimmr_AI_REST_API {

    /**
     * API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'glimmr-ai/v1';

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_routes() {
        // S12: Message length validation callback to prevent token abuse.
        $message_validate_callback = function( $value ) {
            $max_length = Glimmr_AI_Settings::get( 'max_message_length', 4000 );
            if ( strlen( $value ) > $max_length ) {
                return new WP_Error(
                    'message_too_long',
                    sprintf(
                        /* translators: %d: maximum message length */
                        __( 'Message exceeds maximum length of %d characters.', 'glimmr-ai' ),
                        $max_length
                    )
                );
            }
            return true;
        };

        // Chat endpoints.
        register_rest_route(
            self::NAMESPACE,
            '/chat/message',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_chat_message' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'conversation_id' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'message'         => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                        'validate_callback' => $message_validate_callback,
                    ),
                ),
            )
        );

        // Streaming chat endpoint using Server-Sent Events.
        register_rest_route(
            self::NAMESPACE,
            '/chat/stream',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_chat_stream' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'conversation_id' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'message'         => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                        'validate_callback' => $message_validate_callback,
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/chat/history/(?P<conversation_id>[a-zA-Z0-9_-]+)',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_chat_history' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'conversation_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        // Alternative POST endpoint for history (more secure - keeps conversation_id out of URL/logs).
        register_rest_route(
            self::NAMESPACE,
            '/chat/history',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'get_chat_history_post' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'conversation_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/chat/flag',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'flag_message' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'conversation_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'message_id'      => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'issue_type'      => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'feedback'        => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ),
                ),
            )
        );

        // GDPR consent tracking endpoint.
        register_rest_route(
            self::NAMESPACE,
            '/chat/consent',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_consent' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
                'args'                => array(
                    'action'          => array(
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => array( 'granted', 'revoked' ),
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'conversation_id' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        // Cart endpoints (for internal use by AI tools).
        register_rest_route(
            self::NAMESPACE,
            '/cart/add',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'add_to_cart' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/cart/view',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'view_cart' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/cart/update',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'update_cart' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/cart/coupon',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'apply_coupon' ),
                'permission_callback' => array( $this, 'chat_permissions_check' ),
            )
        );

        // Admin endpoints.
        register_rest_route(
            self::NAMESPACE,
            '/admin/settings',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'get_settings' ),
                    'permission_callback' => array( $this, 'admin_permissions_check' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'update_settings' ),
                    'permission_callback' => array( $this, 'admin_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/admin/analytics',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_analytics' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/admin/conversations',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_conversations' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/admin/knowledge/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'trigger_knowledge_sync' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            )
        );
    }

    /**
     * Check permissions for chat endpoints.
     *
     * @return bool|WP_Error
     */
    public function chat_permissions_check() {
        // Chat is available to all users, but rate limited.
        // Rate limiting will be checked in the actual handlers.
        return true;
    }

    /**
     * Check permissions for admin endpoints.
     *
     * @return bool|WP_Error
     */
    public function admin_permissions_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to access this resource.', 'glimmr-ai' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /**
     * Handle incoming chat message.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_chat_message( $request ) {
        // License check — reject if not licensed.
        if ( class_exists( 'Glimmr_AI_License' ) && ! Glimmr_AI_License::get_instance()->is_licensed() ) {
            return new WP_Error( 'not_licensed', __( 'Plugin is not licensed.', 'glimmr-ai' ), array( 'status' => 403 ) );
        }

        $start_time      = microtime( true );
        $conversation_id = $request->get_param( 'conversation_id' );
        $message         = $request->get_param( 'message' );
        $context         = $this->sanitize_context( $request->get_param( 'context' ) ?: array() );

        // Check rate limit using the full rate limiter.
        $rate_limit_result = $this->check_rate_limit_full();
        if ( is_wp_error( $rate_limit_result ) ) {
            Glimmr_AI_Logger::warning(
                'Rate limit exceeded',
                array( 'identifier' => $this->get_rate_limit_identifier() ),
                'api'
            );
            return $rate_limit_result;
        }

        // S13: Content moderation check (v1.7.0).
        if ( Glimmr_AI_Moderation::is_enabled() ) {
            $moderation = new Glimmr_AI_Moderation();
            $check = $moderation->check_message( $message );

            if ( $check['flagged'] ) {
                // Track moderation event (without message content for privacy).
                Glimmr_AI_Analytics::track(
                    'message_moderated',
                    array(
                        'categories'      => $check['categories'],
                        'conversation_id' => $conversation_id ?: 'new',
                    )
                );

                return new WP_Error(
                    'content_moderated',
                    $check['message'],
                    array( 'status' => 400 )
                );
            }
        }

        try {
            // Get or create conversation.
            $is_new_conversation = empty( $conversation_id );

            if ( $is_new_conversation ) {
                // S15: Rate limit conversation creation to prevent abuse.
                if ( ! $this->check_conversation_creation_rate_limit() ) {
                    return new WP_Error(
                        'rate_limit_exceeded',
                        __( 'Too many conversations created. Please wait before starting a new conversation.', 'glimmr-ai' ),
                        array( 'status' => 429 )
                    );
                }

                $conversation_id = Glimmr_AI_Database::insert_conversation(
                    array(
                        'user_id'    => get_current_user_id() ?: null,
                        'session_id' => $this->get_session_id(),
                        'metadata'   => array(
                            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                            'referer'    => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
                        ),
                    )
                );

                if ( ! $conversation_id ) {
                    throw new Exception( 'Failed to create conversation' );
                }

                // Track conversation start (non-blocking).
                try {
                    Glimmr_AI_Analytics::track_conversation_start( $conversation_id, $context );
                    // Store attribution for conversion tracking.
                    Glimmr_AI_Analytics::set_attribution_conversation_id( $conversation_id );
                } catch ( Exception $e ) {
                    Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
                }
            }

            // Verify conversation exists and is active.
            $conversation = Glimmr_AI_Database::get_conversation( $conversation_id );
            if ( ! $conversation || 'expired' === $conversation->status ) {
                // S9: Server-generated IDs only - never accept client-supplied conversation IDs.
                // Generate a new server-side UUID for security.
                $new_conversation_id = Glimmr_AI_Database::insert_conversation(
                    array(
                        // Do NOT use frontend's conversation_id - always generate server-side.
                        'user_id'    => get_current_user_id() ?: null,
                        'session_id' => $this->get_session_id(),
                    )
                );
                if ( $new_conversation_id ) {
                    $conversation_id = $new_conversation_id;
                }
                // Track conversation start (non-blocking).
                try {
                    Glimmr_AI_Analytics::track_conversation_start( $conversation_id, $context );
                } catch ( Exception $e ) {
                    Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
                }
            } else {
                // S1: Validate conversation ownership.
                if ( ! $this->validate_conversation_ownership( $conversation ) ) {
                    return new WP_Error(
                        'forbidden',
                        __( 'You do not have permission to access this conversation.', 'glimmr-ai' ),
                        array( 'status' => 403 )
                    );
                }
            }

            // Store user message.
            $message_id = Glimmr_AI_Database::insert_message(
                array(
                    'conversation_id' => $conversation_id,
                    'role'            => 'user',
                    'content'         => $message,
                )
            );

            if ( false === $message_id ) {
                Glimmr_AI_Logger::error(
                    'Failed to store user message',
                    array( 'conversation_id' => $conversation_id ),
                    'api'
                );
                // Continue anyway - don't block the user from getting a response.
            }

            // Track message sent (non-blocking - log failures but don't fail request).
            try {
                Glimmr_AI_Analytics::track_message_sent( $conversation_id, strlen( $message ) );
            } catch ( Exception $e ) {
                Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
            }

            // Get response from AI.
            $ai_response   = $this->get_ai_response( $message, $conversation_id, $context );
            $response_time = microtime( true ) - $start_time;

            // Handle both old string format and new array format.
            if ( is_array( $ai_response ) ) {
                $response_text = $ai_response['content'] ?? '';
                $artifacts     = $ai_response['artifacts'] ?? array();
            } else {
                $response_text = $ai_response;
                $artifacts     = array();
            }

            // NOTE: Assistant message is stored by process_message() which handles the full agent loop.
            // Do NOT store it again here to avoid duplicates.

            // Track message received (non-blocking).
            try {
                Glimmr_AI_Analytics::track_message_received( $conversation_id, 0, $response_time );
            } catch ( Exception $e ) {
                Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
            }

            Glimmr_AI_Logger::debug(
                'Chat message processed',
                array(
                    'conversation_id' => $conversation_id,
                    'response_time'   => round( $response_time, 3 ),
                    'artifacts_count' => count( $artifacts ),
                ),
                'api'
            );

            return rest_ensure_response(
                array(
                    'conversation_id' => $conversation_id,
                    'response'        => $response_text,
                    'artifacts'       => $artifacts,
                )
            );

        } catch ( Throwable $e ) {
            // Catch both Exception and Error (PHP 7+) to prevent HTML error pages.
            Glimmr_AI_Logger::error(
                'Chat message error: ' . $e->getMessage(),
                array(
                    'conversation_id' => $conversation_id,
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                ),
                'api'
            );

            // Return fallback response.
            $fallback = $this->get_fallback_response();

            return rest_ensure_response(
                array(
                    'conversation_id' => $conversation_id,
                    'response'        => $fallback,
                    'error'           => true,
                )
            );
        }
    }

    /**
     * Get chat history for a conversation.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_chat_history( $request ) {
        $conversation_id = $request->get_param( 'conversation_id' );

        $conversation = Glimmr_AI_Database::get_conversation( $conversation_id );

        if ( ! $conversation ) {
            return new WP_Error(
                'not_found',
                __( 'Conversation not found.', 'glimmr-ai' ),
                array( 'status' => 404 )
            );
        }

        // S1: Validate conversation ownership.
        if ( ! $this->validate_conversation_ownership( $conversation ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to access this conversation.', 'glimmr-ai' ),
                array( 'status' => 403 )
            );
        }

        // S12: Check conversation age against retention limit.
        $retention_days = Glimmr_AI_Settings::get( 'conversation_history_retention_days', 30 );
        if ( $retention_days > 0 && ! empty( $conversation->created_at ) ) {
            $created_time = strtotime( $conversation->created_at );
            $retention_seconds = $retention_days * DAY_IN_SECONDS;
            $age = time() - $created_time;

            if ( $age > $retention_seconds ) {
                // Conversation has expired - return empty history.
                return rest_ensure_response(
                    array(
                        'messages' => array(),
                        'expired'  => true,
                        'message'  => __( 'This conversation history has expired.', 'glimmr-ai' ),
                    )
                );
            }
        }

        $messages = Glimmr_AI_Database::get_messages( $conversation_id );

        // DEBUG: Log raw messages from database.
        Glimmr_AI_Logger::info(
            'get_chat_history: Raw messages from DB',
            array(
                'conversation_id' => $conversation_id,
                'total_messages'  => count( $messages ),
            ),
            'api'
        );

        // Deduplicate messages - some older conversations may have duplicates due to a bug.
        // Duplicates have same role, content, and timestamp but different IDs.
        $seen_hashes     = array();
        $deduped_messages = array();
        $duplicates_removed = 0;

        foreach ( $messages as $msg ) {
            // Create a hash of role + content + timestamp to identify duplicates.
            $hash = md5( ( $msg->role ?? '' ) . ( $msg->content ?? '' ) . ( $msg->created_at ?? '' ) );

            if ( isset( $seen_hashes[ $hash ] ) ) {
                // This is a duplicate - skip it.
                $duplicates_removed++;
                continue;
            }

            $seen_hashes[ $hash ] = true;
            $deduped_messages[] = $msg;
        }

        if ( $duplicates_removed > 0 ) {
            Glimmr_AI_Logger::info(
                'get_chat_history: Removed duplicates',
                array(
                    'duplicates_removed' => $duplicates_removed,
                    'original_count'     => count( $messages ),
                    'deduped_count'      => count( $deduped_messages ),
                ),
                'api'
            );
        }

        $messages = $deduped_messages;

        // Parse JSON fields and reconstruct artifacts.
        $tool_calls_map    = array(); // Maps tool_call_id to tool name.
        $pending_artifacts = array(); // Artifacts waiting to be attached.
        $processed_messages = array();
        $last_assistant_idx = -1; // Track index of last assistant message for artifact attachment.

        foreach ( $messages as $msg ) {
            // Parse JSON fields.
            if ( ! empty( $msg->tool_calls ) ) {
                $msg->tool_calls = json_decode( $msg->tool_calls, true );
                // Build map of call_id to tool name for artifact reconstruction.
                if ( is_array( $msg->tool_calls ) ) {
                    foreach ( $msg->tool_calls as $call ) {
                        if ( isset( $call['call_id'] ) && isset( $call['name'] ) ) {
                            $tool_calls_map[ $call['call_id'] ] = $call['name'];
                        }
                    }
                }
            }
            if ( ! empty( $msg->tool_results ) ) {
                $msg->tool_results = json_decode( $msg->tool_results, true );
            }

            // Handle tool messages - convert to artifacts.
            if ( 'tool' === $msg->role ) {
                $tool_call_id = $msg->tool_results['tool_call_id'] ?? null;
                $tool_name    = $tool_call_id ? ( $tool_calls_map[ $tool_call_id ] ?? null ) : null;

                if ( $tool_name && ! empty( $msg->content ) ) {
                    $artifact = $this->tool_content_to_artifact( $tool_name, $msg->content );
                    if ( $artifact ) {
                        $pending_artifacts[] = $artifact;
                    }
                }
                // Skip tool messages in output - they're internal.
                continue;
            }

            // When we hit a user message and have pending artifacts, attach to last assistant.
            if ( 'user' === $msg->role && ! empty( $pending_artifacts ) && $last_assistant_idx >= 0 ) {
                if ( ! isset( $processed_messages[ $last_assistant_idx ]->artifacts ) ) {
                    $processed_messages[ $last_assistant_idx ]->artifacts = array();
                }
                $processed_messages[ $last_assistant_idx ]->artifacts = array_merge(
                    $processed_messages[ $last_assistant_idx ]->artifacts,
                    $pending_artifacts
                );
                $pending_artifacts = array();
            }

            // Filter out internal assistant messages (those with tool_calls are orchestration, not user-facing).
            if ( 'assistant' === $msg->role ) {
                // Skip assistant messages that have tool_calls - they're internal orchestration.
                // Only show assistant messages that have actual content for the user.
                if ( ! empty( $msg->tool_calls ) ) {
                    // This is an internal message (the AI deciding to call a tool).
                    // Don't add to processed_messages - artifacts will attach to the next real assistant message.
                    continue;
                }

                // Only show assistant messages with actual content.
                if ( empty( $msg->content ) || trim( $msg->content ) === '' ) {
                    continue;
                }

                // This is a user-facing assistant message - attach any pending artifacts.
                if ( ! empty( $pending_artifacts ) ) {
                    $msg->artifacts = $pending_artifacts;
                    $pending_artifacts = array();
                }

                $processed_messages[] = $msg;
                $last_assistant_idx = count( $processed_messages ) - 1;
            } else {
                // User message.
                $processed_messages[] = $msg;
            }
        }

        // If there are remaining artifacts at end of messages, attach to last assistant.
        if ( ! empty( $pending_artifacts ) && $last_assistant_idx >= 0 ) {
            if ( ! isset( $processed_messages[ $last_assistant_idx ]->artifacts ) ) {
                $processed_messages[ $last_assistant_idx ]->artifacts = array();
            }
            $processed_messages[ $last_assistant_idx ]->artifacts = array_merge(
                $processed_messages[ $last_assistant_idx ]->artifacts,
                $pending_artifacts
            );
        }

        return rest_ensure_response(
            array(
                'messages'     => $processed_messages,
                'status'       => $conversation->status,
                'can_continue' => 'active' === $conversation->status,
            )
        );
    }

    /**
     * Get chat history via POST (more secure alternative).
     *
     * Keeps conversation_id out of URL and server logs.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_chat_history_post( $request ) {
        // Delegate to the existing GET handler - same logic applies.
        return $this->get_chat_history( $request );
    }

    /**
     * Flag a message or conversation.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function flag_message( $request ) {
        $conversation_id = $request->get_param( 'conversation_id' );
        $message_id      = $request->get_param( 'message_id' );
        $issue_type      = $request->get_param( 'issue_type' );
        $feedback        = $request->get_param( 'feedback' );

        // Verify conversation exists.
        $conversation = Glimmr_AI_Database::get_conversation( $conversation_id );
        if ( ! $conversation ) {
            return new WP_Error(
                'not_found',
                __( 'Conversation not found.', 'glimmr-ai' ),
                array( 'status' => 404 )
            );
        }

        // Verify ownership - only the conversation owner can flag messages.
        if ( ! $this->validate_conversation_ownership( $conversation ) ) {
            return new WP_Error(
                'forbidden',
                __( 'You do not have permission to flag this conversation.', 'glimmr-ai' ),
                array( 'status' => 403 )
            );
        }

        // Validate issue type against allowed values.
        $allowed_issue_types = array( 'incorrect', 'inappropriate', 'unhelpful', 'other' );
        if ( ! empty( $issue_type ) && ! in_array( $issue_type, $allowed_issue_types, true ) ) {
            $issue_type = 'other';
        }

        // Rate limit flagging to prevent abuse (max 10 flags per hour per session).
        if ( ! $this->check_flag_rate_limit() ) {
            return new WP_Error(
                'rate_limit',
                __( 'Too many flag requests. Please try again later.', 'glimmr-ai' ),
                array( 'status' => 429 )
            );
        }

        $result = Glimmr_AI_Database::insert_flagged_issue(
            array(
                'conversation_id' => $conversation_id,
                'message_id'      => $message_id,
                'issue_type'      => $issue_type,
                'user_feedback'   => $feedback,
            )
        );

        if ( ! $result ) {
            return new WP_Error(
                'flag_error',
                __( 'Failed to flag message.', 'glimmr-ai' ),
                array( 'status' => 500 )
            );
        }

        // Track analytics event.
        Glimmr_AI_Database::insert_analytics_event(
            'message_flagged',
            array(
                'issue_type' => $issue_type,
            ),
            $conversation_id
        );

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * Check rate limit for flagging requests.
     *
     * S15: Atomic Rate Limiting - Uses INSERT...ON DUPLICATE KEY UPDATE
     * to prevent TOCTOU race conditions.
     *
     * Prevents abuse of the flagging system.
     *
     * @return bool True if within limit, false if exceeded.
     */
    private function check_flag_rate_limit() {
        global $wpdb;

        $identifier   = $this->get_rate_limit_identifier();
        $table        = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $window_start = gmdate( 'Y-m-d H:00:00' ); // Hourly window.

        // S15: Atomic check and increment using INSERT...ON DUPLICATE KEY UPDATE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (identifier, identifier_type, request_count, window_start)
                 VALUES (%s, 'flag', 1, %s)
                 ON DUPLICATE KEY UPDATE request_count = request_count + 1",
                $identifier,
                $window_start
            )
        );

        // Get current count after the atomic increment.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT request_count FROM {$table}
                 WHERE identifier = %s AND identifier_type = 'flag' AND window_start = %s",
                $identifier,
                $window_start
            )
        );

        // Allow up to 10 flags per hour.
        return $count <= 10;
    }

    /**
     * Check rate limit for conversation creation.
     *
     * S15: Atomic Rate Limiting - Prevents abuse of conversation creation.
     * Limits to 10 new conversations per hour per user/IP.
     *
     * @return bool True if within limit, false if exceeded.
     */
    private function check_conversation_creation_rate_limit() {
        global $wpdb;

        $identifier   = $this->get_rate_limit_identifier();
        $table        = $wpdb->prefix . 'glimmr_ai_rate_limits';
        $window_start = gmdate( 'Y-m-d H:00:00' ); // Hourly window.

        // S15: Atomic check and increment using INSERT...ON DUPLICATE KEY UPDATE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (identifier, identifier_type, request_count, window_start)
                 VALUES (%s, 'conversation_create', 1, %s)
                 ON DUPLICATE KEY UPDATE request_count = request_count + 1",
                $identifier,
                $window_start
            )
        );

        // Get current count after the atomic increment.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT request_count FROM {$table}
                 WHERE identifier = %s AND identifier_type = 'conversation_create' AND window_start = %s",
                $identifier,
                $window_start
            )
        );

        // Allow up to 10 new conversations per hour.
        return $count <= 10;
    }

    /**
     * Handle GDPR consent tracking.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function handle_consent( $request ) {
        $action          = $request->get_param( 'action' );
        $conversation_id = $request->get_param( 'conversation_id' );

        // Determine event type based on action.
        $event_type = 'granted' === $action ? 'gdpr_consent_granted' : 'gdpr_consent_revoked';

        // Track the consent event.
        Glimmr_AI_Analytics::track(
            $event_type,
            array(
                'user_id'    => get_current_user_id() ?: null,
                'session_id' => $this->get_session_id(),
                'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                    ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
                    : '',
            ),
            $conversation_id
        );

        // Log the consent action.
        Glimmr_AI_Logger::info(
            sprintf( 'GDPR consent %s', $action ),
            array(
                'conversation_id' => $conversation_id,
                'user_id'         => get_current_user_id(),
            ),
            'gdpr'
        );

        // If consent was revoked, handle data deletion if configured.
        if ( 'revoked' === $action && $conversation_id ) {
            $glimmr_ai = Glimmr_AI::get_instance();
            $settings  = $glimmr_ai->get_settings();

            // S9: Log GDPR audit trail for revocation.
            $this->log_gdpr_audit(
                'consent_revoked',
                $conversation_id,
                array(
                    'delete_on_revoke' => $settings->get( 'gdpr_delete_on_revoke', false ),
                )
            );

            // Check if we should delete data on revocation.
            if ( $settings->get( 'gdpr_delete_on_revoke', false ) ) {
                // Delete conversation and messages.
                global $wpdb;

                $messages_table = Glimmr_AI_Database::get_table_name( 'messages' );
                $conversations_table = Glimmr_AI_Database::get_table_name( 'conversations' );

                // Count records before deletion for audit.
                $message_count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$messages_table} WHERE conversation_id = %s",
                        $conversation_id
                    )
                );

                // Delete messages.
                $messages_result = $wpdb->delete(
                    $messages_table,
                    array( 'conversation_id' => $conversation_id ),
                    array( '%s' )
                );

                // Delete conversation.
                $conversation_result = $wpdb->delete(
                    $conversations_table,
                    array( 'conversation_id' => $conversation_id ),
                    array( '%s' )
                );

                // Verify deletions succeeded - GDPR compliance requires confirmation.
                $deletion_failed = false;
                if ( false === $messages_result && (int) $message_count > 0 ) {
                    Glimmr_AI_Logger::error(
                        'GDPR deletion failed: messages not deleted',
                        array(
                            'conversation_id' => $conversation_id,
                            'db_error'        => $wpdb->last_error,
                        ),
                        'gdpr'
                    );
                    $deletion_failed = true;
                }
                if ( false === $conversation_result ) {
                    Glimmr_AI_Logger::error(
                        'GDPR deletion failed: conversation not deleted',
                        array(
                            'conversation_id' => $conversation_id,
                            'db_error'        => $wpdb->last_error,
                        ),
                        'gdpr'
                    );
                    $deletion_failed = true;
                }

                if ( $deletion_failed ) {
                    // Log the failure but still mark consent as revoked.
                    $this->log_gdpr_audit(
                        'deletion_failed',
                        $conversation_id,
                        array(
                            'messages_deleted' => false === $messages_result ? 0 : (int) $messages_result,
                            'reason'           => 'consent_revocation',
                            'error'            => $wpdb->last_error,
                        )
                    );
                } else {
                    // S9: Log successful deletion in GDPR audit trail.
                    $this->log_gdpr_audit(
                        'data_deleted',
                        $conversation_id,
                        array(
                            'messages_deleted' => (int) $message_count,
                            'reason'           => 'consent_revocation',
                        )
                    );

                    Glimmr_AI_Logger::info(
                        'Deleted conversation data after consent revocation',
                        array(
                            'conversation_id'  => $conversation_id,
                            'messages_deleted' => (int) $message_count,
                        ),
                        'gdpr'
                    );
                }
            }
        } elseif ( 'granted' === $action ) {
            // S9: Log GDPR audit trail for consent grant.
            $this->log_gdpr_audit(
                'consent_granted',
                $conversation_id,
                array()
            );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'action'  => $action,
            )
        );
    }

    /**
     * Add item to cart.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function add_to_cart( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wc_not_active', 'WooCommerce is not active.', array( 'status' => 400 ) );
        }

        $product_id   = absint( $request->get_param( 'product_id' ) );
        $quantity     = absint( $request->get_param( 'quantity' ) ) ?: 1;
        $variation_id = absint( $request->get_param( 'variation_id' ) );
        $variation    = $request->get_param( 'variation' );

        // Sanitize variation attributes.
        $variation_attrs = array();
        if ( is_array( $variation ) ) {
            foreach ( $variation as $key => $value ) {
                $variation_attrs[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
            }
        }

        // Ensure WC cart is initialized.
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        // Add to cart with variation attributes for proper cart item matching.
        $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_attrs );

        if ( ! $result ) {
            // Get the last WC notice for more helpful error message.
            $notices = wc_get_notices( 'error' );
            wc_clear_notices();
            $error_message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ?? '' ) : __( 'Failed to add item to cart.', 'glimmr-ai' );

            return new WP_Error(
                'add_to_cart_error',
                $error_message,
                array( 'status' => 400 )
            );
        }

        // Get cart fragments for minicart refresh.
        $fragments = array();
        if ( function_exists( 'wc_get_cart_fragment_refresh_data' ) ) {
            $fragments = wc_get_cart_fragment_refresh_data();
        }

        return rest_ensure_response(
            array(
                'success'    => true,
                'cart_key'   => $result,
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_total' => WC()->cart->get_cart_total(),
                'fragments'  => $fragments,
                'cart_hash'  => WC()->cart->get_cart_hash(),
            )
        );
    }

    /**
     * View cart contents.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function view_cart( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wc_not_active', 'WooCommerce is not active.', array( 'status' => 400 ) );
        }

        // Ensure WC cart is initialized.
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        $cart_items = array();

        foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
            $product = $cart_item['data'];

            $cart_items[] = array(
                'key'          => $cart_key,
                'product_id'   => $cart_item['product_id'],
                'variation_id' => $cart_item['variation_id'],
                'name'         => $product->get_name(),
                'quantity'     => $cart_item['quantity'],
                'price'        => wc_get_price_to_display( $product ),
                'subtotal'     => $cart_item['line_subtotal'],
                'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
            );
        }

        return rest_ensure_response(
            array(
                'items'      => $cart_items,
                'count'      => WC()->cart->get_cart_contents_count(),
                'subtotal'   => WC()->cart->get_cart_subtotal(),
                'total'      => WC()->cart->get_cart_total(),
                'coupon'     => WC()->cart->get_applied_coupons(),
            )
        );
    }

    /**
     * Update cart item.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_cart( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wc_not_active', 'WooCommerce is not active.', array( 'status' => 400 ) );
        }

        $cart_item_key = sanitize_text_field( $request->get_param( 'cart_item_key' ) );
        $quantity      = absint( $request->get_param( 'quantity' ) );

        // Ensure WC cart is initialized.
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( 0 === $quantity ) {
            $result = WC()->cart->remove_cart_item( $cart_item_key );
        } else {
            $result = WC()->cart->set_quantity( $cart_item_key, $quantity );
        }

        if ( ! $result ) {
            return new WP_Error(
                'update_cart_error',
                __( 'Failed to update cart.', 'glimmr-ai' ),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response(
            array(
                'success'    => true,
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_total' => WC()->cart->get_cart_total(),
            )
        );
    }

    /**
     * Apply coupon to cart.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function apply_coupon( $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wc_not_active', 'WooCommerce is not active.', array( 'status' => 400 ) );
        }

        $coupon_code = sanitize_text_field( $request->get_param( 'coupon_code' ) );

        // S6: Check coupon visibility settings before applying.
        if ( ! $this->is_coupon_visible( $coupon_code ) ) {
            return new WP_Error(
                'coupon_not_available',
                __( 'This coupon is not available.', 'glimmr-ai' ),
                array( 'status' => 400 )
            );
        }

        // Ensure WC cart is initialized.
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        $result = WC()->cart->apply_coupon( $coupon_code );

        if ( ! $result ) {
            return new WP_Error(
                'coupon_error',
                __( 'Failed to apply coupon.', 'glimmr-ai' ),
                array( 'status' => 400 )
            );
        }

        return rest_ensure_response(
            array(
                'success'         => true,
                'discount_total'  => WC()->cart->get_discount_total(),
                'cart_total'      => WC()->cart->get_cart_total(),
            )
        );
    }

    /**
     * S6: Check if a coupon is visible/available based on settings.
     *
     * @param string $coupon_code The coupon code.
     * @return bool True if coupon is visible/available.
     */
    private function is_coupon_visible( $coupon_code ) {
        $glimmr_ai   = Glimmr_AI::get_instance();
        $settings    = $glimmr_ai->get_settings();
        $visibility  = $settings->get( 'coupon_visibility', 'public' );

        // If public, all valid coupons are available.
        if ( 'public' === $visibility ) {
            return true;
        }

        // If whitelist mode, check if coupon is in the visible list.
        if ( 'whitelist' === $visibility ) {
            $visible_coupons = $settings->get( 'visible_coupons', array() );
            if ( empty( $visible_coupons ) ) {
                return true; // If no whitelist configured, allow all.
            }
            return in_array( strtolower( $coupon_code ), array_map( 'strtolower', $visible_coupons ), true );
        }

        // If private, no coupons can be applied via chat.
        if ( 'private' === $visibility ) {
            return false;
        }

        return true;
    }

    /**
     * Get settings (admin endpoint).
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function get_settings( $request ) {
        return rest_ensure_response(
            array(
                'site_settings'    => $this->filter_sensitive_settings( Glimmr_AI_Settings::get_all() ),
                'network_settings' => $this->filter_sensitive_settings( Glimmr_AI_Settings::get_network() ),
                'is_multisite'     => is_multisite(),
            )
        );
    }

    /**
     * Filter sensitive settings from REST API responses.
     *
     * Removes or masks API keys, encrypted values, and other sensitive data
     * that should not be exposed to the frontend.
     *
     * @param array $settings The settings array.
     * @return array Filtered settings.
     */
    private function filter_sensitive_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return $settings;
        }

        // Keys to completely remove.
        $remove_keys = array(
            'openai_api_key_encrypted',
            'openai_api_key',
        );

        // Keys to mask (show that they're set but not the value).
        $mask_keys = array(
            'openai_vector_store_id',
        );

        foreach ( $remove_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                // Indicate if key is configured without exposing value.
                $settings[ $key . '_configured' ] = ! empty( $settings[ $key ] );
                unset( $settings[ $key ] );
            }
        }

        foreach ( $mask_keys as $key ) {
            if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
                // Show partial value for identification.
                $value = $settings[ $key ];
                $settings[ $key ] = substr( $value, 0, 8 ) . '...' . substr( $value, -4 );
            }
        }

        return $settings;
    }

    /**
     * Update settings (admin endpoint).
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings( $request ) {
        $settings = $request->get_json_params();

        if ( empty( $settings ) ) {
            return new WP_Error(
                'invalid_settings',
                __( 'Invalid settings data.', 'glimmr-ai' ),
                array( 'status' => 400 )
            );
        }

        $result = Glimmr_AI_Settings::update( $settings );

        if ( ! $result ) {
            return new WP_Error(
                'save_error',
                __( 'Failed to save settings.', 'glimmr-ai' ),
                array( 'status' => 500 )
            );
        }

        return rest_ensure_response(
            array(
                'success'  => true,
                'settings' => $this->filter_sensitive_settings( Glimmr_AI_Settings::get_all() ),
            )
        );
    }

    /**
     * Get analytics data (admin endpoint).
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function get_analytics( $request ) {
        $period = sanitize_text_field( $request->get_param( 'period' ) ) ?: 'month';

        // Get summary from Analytics class.
        $summary = Glimmr_AI_Analytics::get_summary( $period );

        // Get additional metrics.
        $tool_usage   = Glimmr_AI_Analytics::get_tool_usage( $period );
        $daily_counts = Glimmr_AI_Analytics::get_daily_counts( $period );

        // Get conversion stats from Conversion Tracker.
        $conversion_stats = Glimmr_AI_Conversion_Tracker::get_conversion_stats( $period );

        // Get flagged issues count.
        // S8: Site Isolation - Filter by site_id for multisite.
        global $wpdb;
        $flagged_table = Glimmr_AI_Database::get_table_name( 'flagged_issues' );
        $site_id       = Glimmr_AI_Database::get_current_site_id();
        $flagged_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$flagged_table} WHERE site_id = %d AND status = %s",
                $site_id,
                'new'
            )
        );

        return rest_ensure_response(
            array(
                'summary'          => $summary,
                'tool_usage'       => $tool_usage,
                'daily_counts'     => $daily_counts,
                'conversions'      => $conversion_stats,
                'flagged_count'    => $flagged_count,
                'period'           => $period,
            )
        );
    }

    /**
     * Get conversations list (admin endpoint).
     *
     * S8: Site Isolation - All queries filtered by site_id to prevent cross-site data access.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function get_conversations( $request ) {
        global $wpdb;

        $page     = absint( $request->get_param( 'page' ) ) ?: 1;
        $per_page = absint( $request->get_param( 'per_page' ) ) ?: 20;
        $status   = sanitize_text_field( $request->get_param( 'status' ) );
        $offset   = ( $page - 1 ) * $per_page;

        $table_name = Glimmr_AI_Database::get_table_name( 'conversations' );

        // S8: Site Isolation - Get current site ID for multisite filtering.
        $site_id = Glimmr_AI_Database::get_current_site_id();

        // S2: Always use prepared statements.
        // S8: Always filter by site_id to prevent cross-site data leakage.
        if ( ! empty( $status ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE site_id = %d AND status = %s",
                    $site_id,
                    $status
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE site_id = %d",
                    $site_id
                )
            );
        }

        // S2: Build main query with all parameters prepared.
        // S8: Always filter by site_id to prevent cross-site data leakage.
        if ( ! empty( $status ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $conversations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE site_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $site_id,
                    $status,
                    $per_page,
                    $offset
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $conversations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE site_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $site_id,
                    $per_page,
                    $offset
                )
            );
        }

        return rest_ensure_response(
            array(
                'conversations' => $conversations,
                'total'         => (int) $total,
                'page'          => $page,
                'per_page'      => $per_page,
            )
        );
    }

    /**
     * Trigger knowledge sync (admin endpoint).
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response
     */
    public function trigger_knowledge_sync( $request ) {
        // Trigger the sync action.
        do_action( 'glimmr_ai_knowledge_sync' );

        return rest_ensure_response(
            array(
                'success' => true,
                'message' => __( 'Knowledge sync triggered.', 'glimmr-ai' ),
            )
        );
    }

    /**
     * Check rate limit for current request.
     *
     * @return true|WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit() {
        $identifier      = $this->get_rate_limit_identifier();
        $identifier_type = is_user_logged_in() ? 'user' : 'ip';
        $limit           = Glimmr_AI_Settings::get_rate_limit();

        $result = Glimmr_AI_Database::check_rate_limit( $identifier, $identifier_type, $limit );

        if ( ! $result['allowed'] ) {
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Rate limit exceeded. Please try again later.', 'glimmr-ai' ),
                array( 'status' => 429 )
            );
        }

        return true;
    }

    /**
     * Get rate limit identifier for current user/request.
     *
     * @return string
     */
    private function get_rate_limit_identifier() {
        if ( is_user_logged_in() ) {
            return (string) get_current_user_id();
        }

        // Hash the IP for privacy.
        $ip = $this->get_client_ip();
        return wp_hash( $ip );
    }

    /**
     * Get client IP address.
     *
     * S8: Only trust forwarded headers from trusted proxies.
     * Priority: Sucuri header > X-Forwarded-For > HTTP_CLIENT_IP > REMOTE_ADDR
     *
     * @return string
     */
    private function get_client_ip() {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        // S8: Check if request is from a trusted proxy before using forwarded headers.
        if ( $this->is_trusted_proxy( $remote_addr ) ) {
            // Priority 1: Sucuri WAF header (most reliable when using Sucuri).
            if ( ! empty( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) ) {
                $sucuri_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) );
                if ( filter_var( $sucuri_ip, FILTER_VALIDATE_IP ) ) {
                    return $sucuri_ip;
                }
            }

            // Priority 2: X-Forwarded-For (standard proxy header).
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
                // Take the leftmost IP (original client).
                if ( strpos( $forwarded, ',' ) !== false ) {
                    $ip = trim( explode( ',', $forwarded )[0] );
                } else {
                    $ip = $forwarded;
                }
                // Validate it's a valid IP.
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }

            // Priority 3: HTTP_CLIENT_IP (less common).
            if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                $client_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
                if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) ) {
                    return $client_ip;
                }
            }
        }

        return $remote_addr;
    }

    /**
     * S8: Check if an IP is a trusted proxy.
     *
     * @param string $ip The IP to check.
     * @return bool True if trusted proxy.
     */
    private function is_trusted_proxy( $ip ) {
        // Get trusted proxies from settings, fallback to common proxy ranges.
        $glimmr_ai = Glimmr_AI::get_instance();
        $settings  = $glimmr_ai->get_settings();
        $trusted   = $settings->get( 'trusted_proxies', array() );

        // Default trusted proxy ranges (CloudFlare, Sucuri, local, etc.).
        $default_trusted = array(
            '127.0.0.1',           // Localhost.
            '::1',                 // Localhost IPv6.
            '10.0.0.0/8',          // Private class A.
            '172.16.0.0/12',       // Private class B.
            '192.168.0.0/16',      // Private class C.
            // CloudFlare ranges (update periodically).
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // Sucuri WAF ranges (update periodically from https://sucuri.net/ip-info/).
            '192.88.134.0/23',
            '185.93.228.0/22',
            '66.248.200.0/22',
            '208.109.0.0/22',
            '2a02:fe80::/29',
        );

        $all_trusted = array_merge( $default_trusted, $trusted );

        foreach ( $all_trusted as $range ) {
            if ( $this->ip_in_range( $ip, $range ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is in a CIDR range.
     *
     * @param string $ip    The IP address.
     * @param string $range The CIDR range or single IP.
     * @return bool
     */
    private function ip_in_range( $ip, $range ) {
        if ( strpos( $range, '/' ) === false ) {
            return $ip === $range;
        }

        list( $subnet, $bits ) = explode( '/', $range );

        // Handle IPv6.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            // Simple exact match for IPv6 for now.
            return strpos( $ip, $subnet ) === 0;
        }

        // IPv4 CIDR check.
        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );

        if ( $ip_long === false || $subnet_long === false ) {
            return false;
        }

        $mask = -1 << ( 32 - (int) $bits );

        return ( $ip_long & $mask ) === ( $subnet_long & $mask );
    }

    /**
     * Get session ID for conversation binding.
     *
     * Uses WooCommerce session if available, otherwise creates a secure
     * session token combining IP hash, user agent, and a random component.
     *
     * @return string Session identifier.
     */
    private function get_session_id() {
        // For logged-in users, session binding happens via user_id, so session_id is supplementary.
        if ( is_user_logged_in() ) {
            return 'user_' . get_current_user_id();
        }

        // Try WooCommerce session first.
        if ( class_exists( 'WooCommerce' ) && WC()->session ) {
            $wc_session = WC()->session->get_customer_id();
            if ( ! empty( $wc_session ) ) {
                return 'wc_' . $wc_session;
            }
        }

        // Fall back to cookie-based session with fingerprint validation.
        return $this->get_or_create_anonymous_session();
    }

    /**
     * Get or create anonymous session with fingerprint validation.
     *
     * @return string Session identifier.
     */
    private function get_or_create_anonymous_session() {
        $cookie_name = 'glimmr_ai_session';
        $session_id = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( $_COOKIE[ $cookie_name ] ) : '';

        // Validate existing session.
        if ( ! empty( $session_id ) ) {
            // Verify the session token includes correct fingerprint.
            if ( $this->validate_session_fingerprint( $session_id ) ) {
                return $session_id;
            }
            // Invalid session - create new one.
            $session_id = '';
        }

        // Create new session.
        if ( empty( $session_id ) ) {
            $session_id = $this->create_anonymous_session();

            // Set secure cookie (only via PHP, since this runs in REST API context).
            $this->set_session_cookie( $cookie_name, $session_id );
        }

        return $session_id;
    }

    /**
     * Create a new anonymous session identifier.
     *
     * Includes fingerprint hash for validation.
     *
     * @return string Session identifier.
     */
    private function create_anonymous_session() {
        // Random component.
        $random = wp_generate_password( 16, false );

        // Fingerprint components (IP hash + partial user agent hash).
        $fingerprint = $this->get_session_fingerprint();

        // Combine: random_fingerprint
        return $random . '_' . $fingerprint;
    }

    /**
     * Get session fingerprint for validation.
     *
     * Uses IP hash and user agent hash for binding.
     *
     * @return string Fingerprint hash.
     */
    private function get_session_fingerprint() {
        $ip = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        // Use only the first part of user agent to handle minor updates.
        $ua_prefix = substr( $user_agent, 0, 50 );

        // Create fingerprint hash.
        return substr( hash( 'sha256', $ip . $ua_prefix . wp_salt( 'auth' ) ), 0, 16 );
    }

    /**
     * Validate session fingerprint.
     *
     * @param string $session_id Session ID to validate.
     * @return bool True if fingerprint matches.
     */
    private function validate_session_fingerprint( $session_id ) {
        // Extract fingerprint from session ID.
        $parts = explode( '_', $session_id );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        $stored_fingerprint = $parts[1];
        $current_fingerprint = $this->get_session_fingerprint();

        // Use timing-safe comparison.
        return hash_equals( $stored_fingerprint, $current_fingerprint );
    }

    /**
     * Set session cookie with secure flags.
     *
     * @param string $name  Cookie name.
     * @param string $value Cookie value.
     */
    private function set_session_cookie( $name, $value ) {
        $secure = is_ssl();
        $expires = time() + ( 30 * DAY_IN_SECONDS );

        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( $name, $value, array(
                'expires'  => $expires,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ) );
        } else {
            setcookie( $name, $value, $expires, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
        }
    }

    /**
     * S1: Validate that the current user/session owns the conversation.
     *
     * @param object $conversation The conversation object.
     * @return bool True if the user owns the conversation, false otherwise.
     */
    private function validate_conversation_ownership( $conversation ) {
        // Admins can access any conversation.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        $current_user_id = get_current_user_id();
        $current_session = $this->get_session_id();

        // If user is logged in, check user_id match.
        if ( $current_user_id > 0 ) {
            // Allow if conversation belongs to this user.
            if ( (int) $conversation->user_id === $current_user_id ) {
                return true;
            }
            // Don't allow logged-in users to access anonymous conversations.
            return false;
        }

        // For anonymous users, check session_id match.
        if ( ! empty( $current_session ) && ! empty( $conversation->session_id ) ) {
            return $conversation->session_id === $current_session;
        }

        // If no session and no user, only allow if conversation has no owner.
        return empty( $conversation->user_id ) && empty( $conversation->session_id );
    }

    /**
     * Convert tool message content to an artifact for display.
     *
     * Used when reconstructing artifacts from conversation history.
     *
     * @param string $tool_name Tool name.
     * @param string $content   Tool output content (JSON string).
     * @return array|null Artifact array or null if not displayable.
     */
    private function tool_content_to_artifact( $tool_name, $content ) {
        // Parse JSON content.
        $data = is_string( $content ) ? json_decode( $content, true ) : $content;

        if ( ! $data || ! isset( $data['success'] ) || ! $data['success'] ) {
            return null;
        }

        // Check for cart_action status - these are handled specially by frontend.
        // Cart-mutating tools (add_to_cart, update_cart, apply_coupon) return cart_action
        // intents that the frontend executes via WooCommerce Store API.
        // format_outcome() uses 'status' field for the outcome type.
        // When loading from history, mark as already executed (historical actions are complete).
        if ( isset( $data['status'] ) && 'cart_action' === $data['status'] ) {
            $action_data = $data['data'] ?? array();
            // Mark as already executed since this is from history
            $action_data['executed'] = true;
            $action_data['historical'] = true;
            return array(
                'type' => 'cart_action',
                'data' => $action_data,
            );
        }

        // Check for navigating status - navigation tool returns ui_action for frontend.
        // The frontend will use the ui_action to navigate to the page.
        if ( isset( $data['status'] ) && 'navigating' === $data['status'] ) {
            $nav_data = $data['data'] ?? array();
            // Add the suggestion text for display
            $nav_data['suggestion'] = $data['suggestion'] ?? '';
            return array(
                'type' => 'navigating',
                'data' => $nav_data,
            );
        }

        // Map tool names to artifact types.
        $type_map = array(
            'query_products'    => 'query_products',
            'select_products'   => 'query_products',
            'sql_readonly'      => 'sql_results',
            'order_status'      => 'order_status',
            'order_history'     => 'order_history',
            'view_cart'         => 'cart',
            'add_to_cart'       => 'cart',
            'update_cart'       => 'cart',
            'checkout_link'     => 'checkout',
            'coupon_lookup'     => 'coupon',
            'apply_coupon'      => 'coupon',
            'recommendations'   => 'recommendations',
            'account_info'      => 'account_info',
            'site_knowledge'    => 'site_knowledge',
        );

        $artifact_type = $type_map[ $tool_name ] ?? null;

        if ( ! $artifact_type ) {
            return null;
        }

        // Handle different data structures.
        $artifact_data = $data['data'] ?? array();

        // For product tools, wrap products array and decode HTML entities in prices.
        if ( in_array( $tool_name, array( 'query_products', 'select_products' ), true ) && is_array( $artifact_data ) && ! isset( $artifact_data['products'] ) ) {
            $artifact_data = array(
                'products' => $this->decode_product_prices( $artifact_data ),
                'total'    => count( $artifact_data ),
            );
        } elseif ( isset( $artifact_data['products'] ) ) {
            $artifact_data['products'] = $this->decode_product_prices( $artifact_data['products'] );
        }

        // For query_products, determine sub-type based on mode.
        // Mode is inside $artifact_data (i.e., $data['data']), not at the top level.
        if ( 'query_products' === $tool_name && isset( $artifact_data['mode'] ) ) {
            switch ( $artifact_data['mode'] ) {
                case 'compare':
                    $artifact_type = 'product_comparison';
                    break;
                case 'stock_check':
                    $artifact_type = 'stock_check';
                    break;
                case 'details':
                    $artifact_type = 'product_details';
                    break;
                // 'search' stays as 'query_products'.
            }
        }

        // Decode prices in cart data.
        if ( in_array( $artifact_type, array( 'cart', 'checkout' ), true ) ) {
            $artifact_data = $this->decode_cart_prices( $artifact_data );
        }

        return array(
            'type' => $artifact_type,
            'data' => $artifact_data,
        );
    }

    /**
     * Decode HTML entities in product prices.
     *
     * @param array $products Array of products.
     * @return array Products with decoded prices.
     */
    private function decode_product_prices( $products ) {
        if ( ! is_array( $products ) ) {
            return $products;
        }

        foreach ( $products as &$product ) {
            if ( isset( $product['price'] ) ) {
                $product['price'] = html_entity_decode( $product['price'], ENT_QUOTES, 'UTF-8' );
            }
            if ( isset( $product['regular_price'] ) ) {
                $product['regular_price'] = html_entity_decode( $product['regular_price'], ENT_QUOTES, 'UTF-8' );
            }
            if ( isset( $product['sale_price'] ) ) {
                $product['sale_price'] = html_entity_decode( $product['sale_price'], ENT_QUOTES, 'UTF-8' );
            }
        }

        return $products;
    }

    /**
     * Decode HTML entities in cart prices.
     *
     * @param array $cart_data Cart data.
     * @return array Cart data with decoded prices.
     */
    private function decode_cart_prices( $cart_data ) {
        if ( ! is_array( $cart_data ) ) {
            return $cart_data;
        }

        // Decode cart totals.
        $price_fields = array( 'subtotal', 'total', 'discount_total', 'shipping_total', 'tax_total' );
        foreach ( $price_fields as $field ) {
            if ( isset( $cart_data[ $field ] ) ) {
                $cart_data[ $field ] = html_entity_decode( $cart_data[ $field ], ENT_QUOTES, 'UTF-8' );
            }
        }

        // Decode item prices.
        if ( isset( $cart_data['items'] ) && is_array( $cart_data['items'] ) ) {
            foreach ( $cart_data['items'] as &$item ) {
                if ( isset( $item['price'] ) ) {
                    $item['price'] = html_entity_decode( $item['price'], ENT_QUOTES, 'UTF-8' );
                }
                if ( isset( $item['line_total'] ) ) {
                    $item['line_total'] = html_entity_decode( $item['line_total'], ENT_QUOTES, 'UTF-8' );
                }
            }
        }

        return $cart_data;
    }

    /**
     * Full rate limit check using Rate Limiter class.
     *
     * Uses atomic check-and-record to prevent race conditions.
     *
     * @return true|WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit_full() {
        $glimmr_ai       = Glimmr_AI::get_instance();
        $rate_limiter    = $glimmr_ai->get_rate_limiter();
        $identifier      = $this->get_rate_limit_identifier();
        $identifier_type = is_user_logged_in() ? 'user' : 'ip';

        // Atomic check and record to prevent race conditions.
        $result = $rate_limiter->check_and_record( $identifier, $identifier_type, 0 );

        if ( ! $result['allowed'] ) {
            $retry_after = $rate_limiter->get_retry_after( $identifier );
            $reset_time  = time() + $retry_after;

            // Improved error message with reset time.
            $message = sprintf(
                /* translators: %s: time until rate limit resets (e.g., "5 minutes") */
                __( 'You have exceeded the message limit. Please try again in %s.', 'glimmr-ai' ),
                $this->format_time_remaining( $retry_after )
            );

            return new WP_Error(
                'rate_limit_exceeded',
                $message,
                array(
                    'status'      => 429,
                    'retry_after' => $retry_after,
                    'reset_at'    => gmdate( 'c', $reset_time ),
                    'limit'       => $result['limit'],
                    'current'     => $result['current'],
                )
            );
        }

        return true;
    }

    /**
     * Format remaining time in human-readable format.
     *
     * @param int $seconds Seconds remaining.
     * @return string Formatted time string.
     */
    private function format_time_remaining( $seconds ) {
        if ( $seconds < 60 ) {
            return sprintf(
                /* translators: %d: number of seconds */
                _n( '%d second', '%d seconds', $seconds, 'glimmr-ai' ),
                $seconds
            );
        }

        $minutes = ceil( $seconds / 60 );

        if ( $minutes < 60 ) {
            return sprintf(
                /* translators: %d: number of minutes */
                _n( '%d minute', '%d minutes', $minutes, 'glimmr-ai' ),
                $minutes
            );
        }

        $hours = ceil( $minutes / 60 );

        return sprintf(
            /* translators: %d: number of hours */
            _n( '%d hour', '%d hours', $hours, 'glimmr-ai' ),
            $hours
        );
    }

    /**
     * S9: Log GDPR-related actions to audit trail.
     *
     * @param string $action          The GDPR action (consent_granted, consent_revoked, data_deleted, etc.).
     * @param string $conversation_id The conversation ID.
     * @param array  $details         Additional details.
     * @return void
     */
    private function log_gdpr_audit( $action, $conversation_id, $details = array() ) {
        $audit_data = array_merge(
            array(
                'action'          => $action,
                'conversation_id' => $conversation_id,
                'user_id'         => get_current_user_id() ?: null,
                'session_id'      => $this->get_session_id(),
                'ip_hash'         => wp_hash( $this->get_client_ip() . wp_salt() ),
                'timestamp'       => current_time( 'mysql' ),
            ),
            $details
        );

        // Store in analytics table with special event type.
        Glimmr_AI_Database::insert_analytics_event(
            'gdpr_audit_' . $action,
            $audit_data,
            $conversation_id
        );

        // Also log to file for compliance.
        Glimmr_AI_Logger::info(
            sprintf( 'GDPR Audit: %s', $action ),
            $audit_data,
            'gdpr'
        );
    }

    /**
     * Handle streaming chat message using Server-Sent Events.
     *
     * @param WP_REST_Request $request The request object.
     * @return void Outputs SSE stream directly.
     */
    public function handle_chat_stream( $request ) {
        // License check — reject if not licensed.
        if ( class_exists( 'Glimmr_AI_License' ) && ! Glimmr_AI_License::get_instance()->is_licensed() ) {
            return new WP_Error( 'not_licensed', __( 'Plugin is not licensed.', 'glimmr-ai' ), array( 'status' => 403 ) );
        }

        $conversation_id = $request->get_param( 'conversation_id' );
        $message         = $request->get_param( 'message' );
        $context         = $this->sanitize_context( $request->get_param( 'context' ) ?: array() );

        // Check rate limit.
        $rate_limit_result = $this->check_rate_limit_full();
        if ( is_wp_error( $rate_limit_result ) ) {
            $this->send_sse_error( $rate_limit_result->get_error_message() );
            return;
        }

        // S13: Content moderation check (v1.7.0).
        if ( Glimmr_AI_Moderation::is_enabled() ) {
            $moderation = new Glimmr_AI_Moderation();
            $check = $moderation->check_message( $message );

            if ( $check['flagged'] ) {
                // Track moderation event (without message content for privacy).
                Glimmr_AI_Analytics::track(
                    'message_moderated',
                    array(
                        'categories'      => $check['categories'],
                        'conversation_id' => $conversation_id ?: 'new',
                    )
                );

                // Return error as JSON before SSE headers are set.
                wp_send_json_error(
                    array( 'message' => $check['message'] ),
                    400
                );
                return;
            }
        }

        // Create/verify conversation BEFORE setting SSE headers so the
        // attribution cookie can be sent (setcookie requires headers not yet sent).
        $is_new_conversation = empty( $conversation_id );
        $send_init_event     = false;

        try {
            if ( $is_new_conversation ) {
                // S15: Rate limit conversation creation to prevent abuse.
                if ( ! $this->check_conversation_creation_rate_limit() ) {
                    wp_send_json_error(
                        array( 'message' => __( 'Too many conversations created. Please wait before starting a new conversation.', 'glimmr-ai' ) ),
                        429
                    );
                    return;
                }

                $conversation_id = Glimmr_AI_Database::insert_conversation(
                    array(
                        'user_id'    => get_current_user_id() ?: null,
                        'session_id' => $this->get_session_id(),
                    )
                );

                if ( ! $conversation_id ) {
                    wp_send_json_error(
                        array( 'message' => 'Failed to create conversation' ),
                        500
                    );
                    return;
                }

                $send_init_event = true;

                // Track conversation start and set attribution cookie/session.
                try {
                    Glimmr_AI_Analytics::track_conversation_start( $conversation_id, $context );
                    Glimmr_AI_Analytics::set_attribution_conversation_id( $conversation_id );
                } catch ( Exception $e ) {
                    Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
                }
            } else {
                // Verify conversation exists and is valid.
                $conversation = Glimmr_AI_Database::get_conversation( $conversation_id );
                if ( ! $conversation || 'expired' === $conversation->status ) {
                    $conversation_id = Glimmr_AI_Database::insert_conversation(
                        array(
                            'user_id'    => get_current_user_id() ?: null,
                            'session_id' => $this->get_session_id(),
                        )
                    );
                    $send_init_event = true;

                    // Set attribution for the new conversation.
                    try {
                        Glimmr_AI_Analytics::track_conversation_start( $conversation_id, $context );
                        Glimmr_AI_Analytics::set_attribution_conversation_id( $conversation_id );
                    } catch ( Exception $e ) {
                        Glimmr_AI_Logger::debug( 'Analytics tracking failed', array( 'error' => $e->getMessage() ), 'api' );
                    }
                } elseif ( ! $this->validate_conversation_ownership( $conversation ) ) {
                    wp_send_json_error(
                        array( 'message' => 'You do not have permission to access this conversation.' ),
                        403
                    );
                    return;
                } else {
                    // Existing valid conversation — refresh attribution cookie/session
                    // to ensure it persists through checkout.
                    Glimmr_AI_Analytics::set_attribution_conversation_id( $conversation_id );
                }
            }
        } catch ( Throwable $e ) {
            Glimmr_AI_Logger::error( 'Conversation setup error: ' . $e->getMessage(), array(), 'api' );
            wp_send_json_error(
                array( 'message' => 'An error occurred. Please try again.' ),
                500
            );
            return;
        }

        // Set SSE headers AFTER conversation setup and cookie are set.
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' ); // Disable nginx buffering.

        // Disable output buffering.
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        try {
            // Send init event now that SSE headers are set.
            if ( $send_init_event ) {
                $this->send_sse_event( 'init', array( 'conversation_id' => $conversation_id ) );
            }

            // NOTE: User message is stored by process_message_streaming().
            // Do NOT store it here to avoid duplicates.

            // Get streaming response from AI.
            $this->stream_ai_response( $message, $conversation_id, $context );

        } catch ( Throwable $e ) {
            Glimmr_AI_Logger::error( 'Stream error: ' . $e->getMessage(), array(), 'api' );
            $this->send_sse_error( 'An error occurred. Please try again.' );
        }

        // Send done event.
        $this->send_sse_event( 'done', array( 'conversation_id' => $conversation_id ) );
        exit;
    }

    /**
     * Stream AI response using SSE.
     *
     * @param string $message         The user message.
     * @param string $conversation_id The conversation ID.
     * @param array  $context         Additional context.
     */
    private function stream_ai_response( $message, $conversation_id, $context = array() ) {
        Glimmr_AI_Logger::info( 'stream_ai_response called', array(
            'conversation_id' => $conversation_id,
            'message_length'  => strlen( $message ),
        ), 'api' );

        $glimmr_ai = Glimmr_AI::get_instance();
        $api_key   = Glimmr_AI_Settings::get_api_key();

        if ( empty( $api_key ) ) {
            Glimmr_AI_Logger::warning( 'No API key configured, using fallback', array(), 'api' );
            $this->send_sse_event( 'content', array( 'text' => $this->get_fallback_response() ) );
            return;
        }

        try {
            $conversation_manager = $glimmr_ai->get_conversation_manager();

            // Use streaming callback to emit SSE content events.
            $full_response   = '';
            $stream_callback = function( $chunk ) use ( &$full_response ) {
                $full_response .= $chunk;
                $this->send_sse_event( 'content', array( 'text' => $chunk ) );
            };

            // Use status callback to emit SSE status events.
            $status_callback = function( $status_type, $status_message ) {
                $this->send_sse_event( 'status', array(
                    'type'    => $status_type,
                    'message' => $status_message,
                ) );
            };

            // Use artifact callback to emit complete artifacts as SSE events.
            $artifact_callback = function( $artifact ) {
                $this->send_sse_event( 'artifact', $artifact );
            };

            // Get response with streaming, status updates, and artifacts.
            $response = $conversation_manager->process_message_streaming(
                $conversation_id,
                $message,
                $context,
                $stream_callback,
                $status_callback,
                $artifact_callback
            );

            // Log what we got back.
            Glimmr_AI_Logger::info( 'process_message_streaming returned', array(
                'is_wp_error'     => is_wp_error( $response ),
                'response_type'   => gettype( $response ),
                'full_response'   => strlen( $full_response ) . ' chars',
            ), 'api' );

            // If streaming wasn't available, fall back to regular response.
            if ( is_string( $response ) && empty( $full_response ) ) {
                $this->send_sse_event( 'content', array( 'text' => $response ) );
                $full_response = $response;
            }

            // NOTE: Assistant message is stored by process_message_streaming().
            // Do NOT store it again here to avoid duplicates.

        } catch ( Exception $e ) {
            Glimmr_AI_Logger::exception( $e, 'api', array( 'conversation_id' => $conversation_id ) );
            $this->send_sse_event( 'content', array( 'text' => $this->get_fallback_response() ) );
        }
    }

    /**
     * Send SSE event.
     *
     * @param string $event Event name.
     * @param array  $data  Event data.
     */
    private function send_sse_event( $event, $data ) {
        echo "event: {$event}\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
        flush();
    }

    /**
     * Send SSE error event.
     *
     * @param string $message Error message.
     */
    private function send_sse_error( $message ) {
        $this->send_sse_event( 'error', array( 'message' => $message ) );
        exit;
    }

    /**
     * Get AI response for a message.
     *
     * Uses OpenAI integration when available, falls back to placeholder otherwise.
     *
     * @param string $message         The user message.
     * @param string $conversation_id The conversation ID.
     * @param array  $context         Additional context.
     * @return string The AI response.
     */
    private function get_ai_response( $message, $conversation_id, $context = array() ) {
        $glimmr_ai = Glimmr_AI::get_instance();

        if ( ! $glimmr_ai ) {
            Glimmr_AI_Logger::error(
                'Glimmr_AI instance is null',
                array( 'conversation_id' => $conversation_id ),
                'api'
            );
            return $this->get_fallback_response();
        }

        // Check if OpenAI is configured.
        // Use the dedicated get_api_key() method which handles decryption.
        $api_key = Glimmr_AI_Settings::get_api_key();

        if ( empty( $api_key ) ) {
            Glimmr_AI_Logger::warning(
                'OpenAI API key not configured - using fallback response',
                array( 'conversation_id' => $conversation_id ),
                'api'
            );
            return $this->get_fallback_response();
        }

        try {
            // Get conversation manager and process the message.
            $conversation_manager = $glimmr_ai->get_conversation_manager();

            if ( ! $conversation_manager ) {
                Glimmr_AI_Logger::error(
                    'Conversation manager is null',
                    array( 'conversation_id' => $conversation_id ),
                    'api'
                );
                return $this->get_fallback_response();
            }

            $response = $conversation_manager->process_message( $conversation_id, $message, $context );

            if ( is_wp_error( $response ) ) {
                Glimmr_AI_Logger::error(
                    'AI response error: ' . $response->get_error_message(),
                    array( 'conversation_id' => $conversation_id ),
                    'api'
                );
                return $this->get_fallback_response();
            }

            return $response;

        } catch ( Exception $e ) {
            Glimmr_AI_Logger::exception( $e, 'api', array( 'conversation_id' => $conversation_id ) );
            return $this->get_fallback_response();
        }
    }

    /**
     * Sanitize context data from frontend.
     *
     * Validates and sanitizes the context array to prevent injection attacks.
     *
     * @param array $context Raw context from request.
     * @return array Sanitized context.
     */
    private function sanitize_context( $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }

        $sanitized = array();

        // Page URL - validate and sanitize.
        if ( isset( $context['page_url'] ) ) {
            $url = esc_url_raw( $context['page_url'] );
            // Only allow http/https URLs from the same domain.
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $url_host  = wp_parse_url( $url, PHP_URL_HOST );
            if ( $url_host === $site_host ) {
                $sanitized['page_url'] = $url;
            }
        }

        // Page title - sanitize as text with UTF-8 safe truncation.
        if ( isset( $context['page_title'] ) ) {
            $sanitized['page_title'] = sanitize_text_field(
                mb_substr( $context['page_title'], 0, 200, 'UTF-8' )
            );
        }

        // Cart count - must be bounded integer.
        if ( isset( $context['cart_count'] ) ) {
            $sanitized['cart_count'] = min( absint( $context['cart_count'] ), 1000 );
        }

        // Is logged in - must be boolean.
        if ( isset( $context['is_logged_in'] ) ) {
            $sanitized['is_logged_in'] = (bool) $context['is_logged_in'];
        }

        // Product ID - must be bounded positive integer.
        if ( isset( $context['product_id'] ) ) {
            $id = absint( $context['product_id'] );
            $sanitized['product_id'] = ( $id > 0 && $id < PHP_INT_MAX ) ? $id : 0;
        }

        // Category - sanitize as text with UTF-8 safe truncation.
        if ( isset( $context['category'] ) ) {
            $sanitized['category'] = sanitize_text_field(
                mb_substr( $context['category'], 0, 100, 'UTF-8' )
            );
        }

        return $sanitized;
    }

    /**
     * Get fallback response when AI fails.
     *
     * @return string The fallback response message.
     */
    private function get_fallback_response() {
        $glimmr_ai = Glimmr_AI::get_instance();
        $settings  = $glimmr_ai->get_settings();

        $fallback = $settings->get( 'fallback_response' );

        if ( empty( $fallback ) ) {
            $fallback = __(
                "I apologize, but I'm having trouble processing your request right now. Please try again in a moment, or contact our support team for assistance.",
                'glimmr-ai'
            );
        }

        return $fallback;
    }
}
