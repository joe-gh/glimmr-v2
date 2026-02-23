<?php
/**
 * Conversation Manager
 *
 * Handles conversation lifecycle, message storage, and context management
 * including sliding window truncation for token limits.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Conversation
 *
 * Manages:
 * - Conversation creation and retrieval
 * - Message storage
 * - Sliding window context for token management
 * - Conversation expiration
 * - Flagging system
 */
class Glimmr_AI_Conversation {

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
     * OpenAI client.
     *
     * @var Glimmr_AI_OpenAI
     */
    private $openai;

    /**
     * Maximum messages in context window.
     *
     * @var int
     */
    private $max_messages;

    /**
     * Maximum tokens for context.
     *
     * @var int
     */
    private $max_context_tokens = 32000;

    /**
     * Context configuration from settings.
     *
     * @var array
     */
    private $context_config;

    /**
     * Tool configuration from settings.
     *
     * @var array
     */
    private $tool_config;

    /**
     * Cached messages during tool execution.
     *
     * @var array
     */
    private $cached_messages = array();

    /**
     * Constructor.
     *
     * @param Glimmr_AI_Database $database Database instance.
     * @param Glimmr_AI_Settings $settings Settings instance.
     * @param Glimmr_AI_OpenAI   $openai   OpenAI client.
     */
    public function __construct( $database, $settings, $openai ) {
        $this->database     = $database;
        $this->settings     = $settings;
        $this->openai       = $openai;
        $this->max_messages = (int) $settings->get( 'max_messages_per_conversation', 50 );

        // Load context and tool configuration from settings.
        $this->context_config     = Glimmr_AI_Settings::get_context_config();
        $this->tool_config        = Glimmr_AI_Settings::get_tool_config();
        $this->max_context_tokens = $this->context_config['max_context_tokens'];
    }

    // =========================================================================
    // Conversation Management
    // =========================================================================

    /**
     * Create a new conversation.
     *
     * @param int|null    $user_id    User ID (null for guests).
     * @param string|null $session_id WC session ID for guests.
     * @param array       $metadata   Additional metadata.
     * @return array Conversation data.
     */
    public function create( $user_id = null, $session_id = null, $metadata = array() ) {
        global $wpdb;

        $conversation_id = $this->generate_conversation_id();
        $expiry_days = (int) $this->settings->get( 'conversation_expiry_days', 30 );

        $data = array(
            'conversation_id' => $conversation_id,
            'user_id'         => $user_id,
            'session_id'      => $session_id,
            'status'          => 'active',
            'message_count'   => 0,
            'created_at'      => current_time( 'mysql' ),
            'expires_at'      => date( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) ),
            'metadata'        => wp_json_encode( $this->sanitize_metadata( $metadata ) ),
        );

        $result = $wpdb->insert(
            $wpdb->prefix . 'glimmr_ai_conversations',
            $data,
            array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            Glimmr_AI_Logger::error(
                'Failed to create conversation',
                array(
                    'conversation_id' => $conversation_id,
                    'db_error'        => $wpdb->last_error,
                ),
                'conversation'
            );
            return new WP_Error(
                'db_insert_failed',
                __( 'Failed to create conversation. Please try again.', 'glimmr-ai' )
            );
        }

        return array(
            'conversation_id' => $conversation_id,
            'status'          => 'active',
            'created_at'      => $data['created_at'],
        );
    }

    /**
     * Get conversation by ID.
     *
     * @param string $conversation_id Conversation UUID.
     * @return array|null Conversation data or null.
     */
    public function get( $conversation_id ) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}glimmr_ai_conversations WHERE conversation_id = %s",
                $conversation_id
            ),
            ARRAY_A
        );

        if ( $result && ! empty( $result['metadata'] ) ) {
            $decoded = json_decode( $result['metadata'], true );
            if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
                Glimmr_AI_Logger::warning(
                    'Failed to decode conversation metadata',
                    array( 'error' => json_last_error_msg(), 'conversation_id' => $conversation_id ),
                    'conversation'
                );
                $result['metadata'] = array();
            } else {
                $result['metadata'] = $decoded ?? array();
            }
        }

        return $result;
    }

    /**
     * Check if conversation exists and is active.
     *
     * @param string $conversation_id Conversation UUID.
     * @return bool
     */
    public function is_active( $conversation_id ) {
        $conversation = $this->get( $conversation_id );

        if ( ! $conversation ) {
            return false;
        }

        if ( 'active' !== $conversation['status'] ) {
            return false;
        }

        // Check expiration.
        if ( strtotime( $conversation['expires_at'] ) < time() ) {
            $this->update_status( $conversation_id, 'expired' );
            return false;
        }

        return true;
    }

    /**
     * Check if conversation can continue (under message limit).
     *
     * @param string $conversation_id Conversation UUID.
     * @return bool
     */
    public function can_continue( $conversation_id ) {
        $conversation = $this->get( $conversation_id );

        if ( ! $conversation ) {
            return false;
        }

        return $conversation['message_count'] < $this->max_messages;
    }

    /**
     * Update conversation status.
     *
     * @param string $conversation_id Conversation UUID.
     * @param string $status          New status.
     * @return bool
     */
    public function update_status( $conversation_id, $status ) {
        global $wpdb;

        return $wpdb->update(
            $wpdb->prefix . 'glimmr_ai_conversations',
            array( 'status' => $status ),
            array( 'conversation_id' => $conversation_id ),
            array( '%s' ),
            array( '%s' )
        ) !== false;
    }

    /**
     * Touch conversation (update last activity).
     *
     * @param string $conversation_id Conversation UUID.
     */
    private function touch( $conversation_id ) {
        global $wpdb;

        $expiry_days = (int) $this->settings->get( 'conversation_expiry_days', 30 );

        $result = $wpdb->update(
            $wpdb->prefix . 'glimmr_ai_conversations',
            array(
                'last_message_at' => current_time( 'mysql' ),
                'expires_at'      => date( 'Y-m-d H:i:s', strtotime( "+{$expiry_days} days" ) ),
            ),
            array( 'conversation_id' => $conversation_id )
        );

        if ( false === $result ) {
            Glimmr_AI_Logger::warning(
                'Failed to update conversation timestamp',
                array(
                    'conversation_id' => $conversation_id,
                    'db_error'        => $wpdb->last_error,
                ),
                'conversation'
            );
        }
    }

    // =========================================================================
    // Message Management
    // =========================================================================

    /**
     * Add a message to conversation.
     *
     * @param string $conversation_id Conversation UUID.
     * @param string $role            Message role (user, assistant, system, tool).
     * @param string $content         Message content.
     * @param array  $tool_calls      Tool calls (for assistant messages).
     * @param array  $tool_results    Tool results (for tool messages).
     * @param int    $tokens_used     Tokens used for this message.
     * @return int|false Message ID or false.
     */
    public function add_message( $conversation_id, $role, $content, $tool_calls = null, $tool_results = null, $tokens_used = 0 ) {
        global $wpdb;

        $data = array(
            'conversation_id' => $conversation_id,
            'role'            => $role,
            'content'         => $content,
            'tool_calls'      => $tool_calls ? wp_json_encode( $tool_calls ) : null,
            'tool_results'    => $tool_results ? wp_json_encode( $tool_results ) : null,
            'tokens_used'     => $tokens_used,
            'created_at'      => current_time( 'mysql' ),
        );

        $result = $wpdb->insert(
            $wpdb->prefix . 'glimmr_ai_messages',
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );

        if ( $result ) {
            // Update message count.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}glimmr_ai_conversations
                     SET message_count = message_count + 1
                     WHERE conversation_id = %s",
                    $conversation_id
                )
            );

            $this->touch( $conversation_id );

            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get messages for conversation.
     *
     * @param string $conversation_id Conversation UUID.
     * @param int    $limit           Maximum messages to return.
     * @param int    $offset          Offset for pagination.
     * @return array Messages.
     */
    public function get_messages( $conversation_id, $limit = 100, $offset = 0 ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}glimmr_ai_messages
                 WHERE conversation_id = %s
                 ORDER BY created_at ASC
                 LIMIT %d OFFSET %d",
                $conversation_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // Decode JSON fields.
        foreach ( $results as &$message ) {
            if ( ! empty( $message['tool_calls'] ) ) {
                $decoded = json_decode( $message['tool_calls'], true );
                if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
                    Glimmr_AI_Logger::warning(
                        'Failed to decode tool_calls',
                        array( 'error' => json_last_error_msg(), 'message_id' => $message['id'] ?? 'unknown' ),
                        'conversation'
                    );
                    $message['tool_calls'] = array();
                } else {
                    $message['tool_calls'] = $decoded ?? array();
                }
            }
            if ( ! empty( $message['tool_results'] ) ) {
                $decoded = json_decode( $message['tool_results'], true );
                if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
                    Glimmr_AI_Logger::warning(
                        'Failed to decode tool_results',
                        array( 'error' => json_last_error_msg(), 'message_id' => $message['id'] ?? 'unknown' ),
                        'conversation'
                    );
                    $message['tool_results'] = array();
                } else {
                    $message['tool_results'] = $decoded ?? array();
                }
            }
        }

        return $results;
    }

    /**
     * Get message count for conversation.
     *
     * @param string $conversation_id Conversation UUID.
     * @return int Message count.
     */
    public function get_message_count( $conversation_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_ai_messages WHERE conversation_id = %s",
                $conversation_id
            )
        );
    }

    // =========================================================================
    // Sliding Window Context Management
    // =========================================================================

    /**
     * Get messages formatted for OpenAI API with sliding window.
     *
     * Implements intelligent context truncation:
     * 1. Always keep the first system message
     * 2. Keep recent messages up to token limit
     * 3. Optionally summarize older messages
     *
     * @param string $conversation_id Conversation UUID.
     * @param string $system_prompt   System prompt to prepend.
     * @return array Messages formatted for API.
     */
    public function get_context_for_api( $conversation_id, $system_prompt = '' ) {
        $all_messages = $this->get_messages( $conversation_id );

        if ( empty( $all_messages ) ) {
            return array();
        }

        // Convert to API format.
        $formatted = array();
        foreach ( $all_messages as $msg ) {
            $api_msg = array(
                'role'    => $msg['role'],
                'content' => $msg['content'],
            );

            // Add tool calls for assistant messages.
            if ( 'assistant' === $msg['role'] && ! empty( $msg['tool_calls'] ) ) {
                $api_msg['tool_calls'] = $msg['tool_calls'];
                if ( empty( $api_msg['content'] ) ) {
                    $api_msg['content'] = null;
                }
            }

            // Tool result messages have special format.
            if ( 'tool' === $msg['role'] && ! empty( $msg['tool_results'] ) ) {
                $api_msg['tool_call_id'] = $msg['tool_results']['tool_call_id'] ?? '';

                // Summarize tool result for context efficiency.
                // Original data remains in database; this only affects API context.
                if ( class_exists( 'Glimmr_AI_Tool_Summarizer' ) && ! empty( $msg['content'] ) ) {
                    // Find the tool name from the paired assistant message.
                    $tool_name = Glimmr_AI_Tool_Summarizer::get_tool_name_for_call_id(
                        $api_msg['tool_call_id'],
                        $all_messages
                    );

                    // Parse and summarize the content.
                    $content = json_decode( $msg['content'], true );
                    if ( is_array( $content ) && ! empty( $tool_name ) ) {
                        $summarized = Glimmr_AI_Tool_Summarizer::summarize( $tool_name, $content );
                        $api_msg['content'] = wp_json_encode( $summarized );
                    }
                }
            }

            $formatted[] = $api_msg;
        }

        // Apply sliding window.
        return $this->apply_sliding_window( $formatted, $system_prompt );
    }

    /**
     * Apply sliding window to messages.
     *
     * @param array  $messages      All messages.
     * @param string $system_prompt System prompt.
     * @return array Truncated messages.
     */
    private function apply_sliding_window( $messages, $system_prompt = '' ) {
        // Estimate system prompt tokens.
        $system_tokens = $this->openai->estimate_tokens( $system_prompt );
        $reserve = $this->context_config['reserve_tokens'];
        $available_tokens = $this->max_context_tokens - $system_tokens - $reserve;

        // Get configurable thresholds.
        $sliding_threshold  = $this->context_config['sliding_window_threshold'];
        $minimum_messages   = $this->context_config['minimum_recent_messages'];

        // If we have few messages, return all.
        if ( count( $messages ) <= $sliding_threshold ) {
            return $messages;
        }

        // Start from the end and work backwards.
        $selected = array();
        $current_tokens = 0;

        for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
            $msg = $messages[ $i ];
            $content = is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] );
            $msg_tokens = $this->openai->estimate_tokens( $content ) + 4;

            if ( $current_tokens + $msg_tokens > $available_tokens ) {
                break;
            }

            array_unshift( $selected, $msg );
            $current_tokens += $msg_tokens;
        }

        // Ensure we have at least the minimum recent messages.
        if ( count( $selected ) < $minimum_messages && count( $messages ) >= $minimum_messages ) {
            $selected = array_slice( $messages, -$minimum_messages );
        }

        // If we truncated, add a context note.
        $truncated_count = count( $messages ) - count( $selected );
        if ( $truncated_count > 0 ) {
            array_unshift( $selected, array(
                'role'    => 'system',
                'content' => sprintf(
                    '[Note: %d earlier messages have been summarized for context. Continue the conversation naturally.]',
                    $truncated_count
                ),
            ) );
        }

        return $selected;
    }

    /**
     * Get full conversation history formatted for display.
     *
     * @param string $conversation_id Conversation UUID.
     * @return array History with timestamps.
     */
    public function get_history( $conversation_id ) {
        $messages = $this->get_messages( $conversation_id );
        $history = array();

        foreach ( $messages as $msg ) {
            // Skip tool messages for display (they're internal).
            if ( 'tool' === $msg['role'] ) {
                continue;
            }

            $history[] = array(
                'id'         => $msg['id'],
                'role'       => $msg['role'],
                'content'    => $msg['content'],
                'created_at' => $msg['created_at'],
                'has_tools'  => ! empty( $msg['tool_calls'] ),
            );
        }

        return $history;
    }

    // =========================================================================
    // Flagging System
    // =========================================================================

    /**
     * Flag a conversation or message for review.
     *
     * @param string      $conversation_id Conversation UUID.
     * @param int|null    $message_id      Specific message ID (optional).
     * @param string      $issue_type      Type of issue.
     * @param string|null $user_feedback   User feedback text.
     * @return int|false Flag ID or false.
     */
    public function flag( $conversation_id, $message_id = null, $issue_type = 'general', $user_feedback = null ) {
        global $wpdb;

        $data = array(
            'conversation_id' => $conversation_id,
            'message_id'      => $message_id,
            'issue_type'      => $issue_type,
            'user_feedback'   => $user_feedback,
            'status'          => 'new',
            'created_at'      => current_time( 'mysql' ),
        );

        $result = $wpdb->insert(
            $wpdb->prefix . 'glimmr_ai_flagged_issues',
            $data,
            array( '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        if ( $result ) {
            // Mark conversation as flagged.
            $this->update_status( $conversation_id, 'flagged' );
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Get flagged issues.
     *
     * @param array $args Query arguments.
     * @return array Flagged issues.
     */
    public function get_flagged_issues( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'   => null,
            'limit'    => 50,
            'offset'   => 0,
            'order_by' => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = '';
        if ( $args['status'] ) {
            $where = $wpdb->prepare( ' WHERE status = %s', $args['status'] );
        }

        $order_by = in_array( $args['order_by'], array( 'created_at', 'status', 'issue_type' ), true )
            ? $args['order_by']
            : 'created_at';
        $order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}glimmr_ai_flagged_issues
                 {$where}
                 ORDER BY {$order_by} {$order}
                 LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            ),
            ARRAY_A
        );
    }

    /**
     * Update flagged issue.
     *
     * @param int   $id   Flag ID.
     * @param array $data Data to update.
     * @return bool
     */
    public function update_flag( $id, $data ) {
        global $wpdb;

        $allowed = array( 'status', 'admin_notes', 'reviewed_at', 'reviewed_by' );
        $update = array_intersect_key( $data, array_flip( $allowed ) );

        if ( empty( $update ) ) {
            return false;
        }

        return $wpdb->update(
            $wpdb->prefix . 'glimmr_ai_flagged_issues',
            $update,
            array( 'id' => $id )
        ) !== false;
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Clean up expired conversations.
     *
     * Uses batch deletes for better performance instead of individual deletes.
     *
     * @return int Number of conversations cleaned.
     */
    public function cleanup_expired() {
        global $wpdb;

        // Get expired conversation IDs.
        $expired = $wpdb->get_col(
            "SELECT conversation_id FROM {$wpdb->prefix}glimmr_ai_conversations
             WHERE expires_at < NOW() AND status = 'active'"
        );

        if ( empty( $expired ) ) {
            return 0;
        }

        // NOTE: Analytics records are intentionally preserved after conversation deletion
        // for historical revenue attribution and reporting. Analytics have separate
        // retention managed by the cron cleanup job.

        // Batch delete for performance.
        $conversations_table = $wpdb->prefix . 'glimmr_ai_conversations';
        $messages_table      = $wpdb->prefix . 'glimmr_ai_messages';
        $flags_table         = $wpdb->prefix . 'glimmr_ai_flagged_issues';

        // Prepare placeholders for IN clause.
        $placeholders = implode( ', ', array_fill( 0, count( $expired ), '%s' ) );

        // Delete messages in batch.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$messages_table} WHERE conversation_id IN ({$placeholders})",
                $expired
            )
        );

        // Delete flags in batch.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$flags_table} WHERE conversation_id IN ({$placeholders})",
                $expired
            )
        );

        // Delete conversations in batch.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$conversations_table} WHERE conversation_id IN ({$placeholders})",
                $expired
            )
        );

        return (int) $deleted;
    }

    /**
     * Delete a conversation and all its messages.
     *
     * @param string $conversation_id Conversation UUID.
     * @return bool
     */
    public function delete( $conversation_id ) {
        global $wpdb;

        // Delete messages first (FK constraint).
        $messages_deleted = $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_messages',
            array( 'conversation_id' => $conversation_id )
        );
        if ( false === $messages_deleted ) {
            Glimmr_AI_Logger::warning(
                'Failed to delete conversation messages',
                array( 'conversation_id' => $conversation_id, 'db_error' => $wpdb->last_error ),
                'conversation'
            );
        }

        // Delete flags.
        $flags_deleted = $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_flagged_issues',
            array( 'conversation_id' => $conversation_id )
        );
        if ( false === $flags_deleted ) {
            Glimmr_AI_Logger::warning(
                'Failed to delete conversation flags',
                array( 'conversation_id' => $conversation_id, 'db_error' => $wpdb->last_error ),
                'conversation'
            );
        }

        // Delete conversation.
        $result = $wpdb->delete(
            $wpdb->prefix . 'glimmr_ai_conversations',
            array( 'conversation_id' => $conversation_id )
        );
        if ( false === $result ) {
            Glimmr_AI_Logger::error(
                'Failed to delete conversation',
                array( 'conversation_id' => $conversation_id, 'db_error' => $wpdb->last_error ),
                'conversation'
            );
        }
        return $result !== false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Generate unique conversation ID.
     *
     * @return string UUID v4.
     */
    private function generate_conversation_id() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }

        // Fallback for older WP versions.
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff )
        );
    }

    /**
     * Sanitize metadata for storage.
     *
     * @param array $metadata Raw metadata.
     * @return array Sanitized metadata.
     */
    private function sanitize_metadata( $metadata ) {
        $sanitized = array();

        // Hash IP if provided.
        if ( ! empty( $metadata['ip'] ) ) {
            $sanitized['ip_hash'] = wp_hash( $metadata['ip'] );
        }

        // Keep user agent (truncated).
        if ( ! empty( $metadata['user_agent'] ) ) {
            $sanitized['user_agent'] = substr( sanitize_text_field( $metadata['user_agent'] ), 0, 255 );
        }

        // Keep page URL.
        if ( ! empty( $metadata['page_url'] ) ) {
            $sanitized['page_url'] = esc_url_raw( $metadata['page_url'] );
        }

        // Device type.
        if ( ! empty( $metadata['device_type'] ) ) {
            $sanitized['device_type'] = sanitize_key( $metadata['device_type'] );
        }

        return $sanitized;
    }

    /**
     * Get total tokens used in conversation.
     *
     * @param string $conversation_id Conversation UUID.
     * @return int Total tokens.
     */
    public function get_total_tokens( $conversation_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(tokens_used) FROM {$wpdb->prefix}glimmr_ai_messages WHERE conversation_id = %s",
                $conversation_id
            )
        );
    }

    // =========================================================================
    // Intent Classification (Tool Routing)
    // =========================================================================

    /**
     * Classify user message intent using a lightweight LLM call.
     *
     * Uses GPT-5 Nano for fast, cheap intent classification to determine
     * whether to enable file_search or gate it for product/cart queries.
     *
     * @param string $message      Current user message.
     * @param array  $api_messages Recent conversation messages for context.
     * @return array Intent classification with 'intent', 'confidence', and 'use_file_search'.
     */
    private function classify_intent_with_llm( $message, $api_messages ) {
        // Build context from recent messages (last 2 turns max for efficiency).
        $context_snippet = $this->build_classifier_context( $message, $api_messages );

        // System prompt - compact but handles reference resolution.
        $system_prompt = "Classify the user's CURRENT message into one intent: product|cart|order|policy|general. Use recent messages only to resolve references (e.g., 'those', 'subscription ones'). If user is asking to show/list/find/browse products, intent=product. Output JSON only.";

        // Build input for classifier.
        $classifier_input = array(
            array(
                'role'    => 'user',
                'content' => $context_snippet,
            ),
        );

        // Call GPT-5 Nano with structured output.
        $classifier_response = $this->openai->create_response(
            $classifier_input,
            array(), // No tools for classifier.
            $system_prompt,
            array(
                'model'              => 'gpt-4o-mini', // Fast, cheap model for classification.
                'max_tokens'         => 50,
                'temperature'        => 0,
                'use_file_search'    => false, // Never use file_search for classifier.
            )
        );

        // Parse the classification result.
        if ( is_wp_error( $classifier_response ) ) {
            // Fall back to allowing file_search on error.
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::debug(
                    'Intent classifier error, using fallback',
                    array( 'error' => $classifier_response->get_error_message() ),
                    'conversation'
                );
            }
            return array(
                'intent'          => 'general',
                'confidence'      => 0.0,
                'use_file_search' => true,
            );
        }

        // Extract JSON from response.
        $content = $classifier_response['content'] ?? '';
        $classification = $this->parse_intent_json( $content );

        // Determine file_search gating based on intent.
        $disable_file_search_intents = array( 'product', 'cart', 'order' );
        $use_file_search = ! in_array( $classification['intent'], $disable_file_search_intents, true );

        return array(
            'intent'          => $classification['intent'],
            'confidence'      => $classification['confidence'],
            'use_file_search' => $use_file_search,
        );
    }

    /**
     * Build compact context snippet for intent classifier.
     *
     * Includes only the last 2 turns + current message to minimize tokens.
     *
     * @param string $current_message Current user message.
     * @param array  $api_messages    Full conversation messages.
     * @return string Formatted context snippet.
     */
    private function build_classifier_context( $current_message, $api_messages ) {
        $context_lines = array();

        // Get last few messages (excluding system).
        $recent = array_filter( $api_messages, function( $msg ) {
            return ( $msg['role'] ?? '' ) !== 'system';
        } );
        $recent = array_slice( $recent, -4 ); // Last 4 messages max (2 turns).

        foreach ( $recent as $msg ) {
            $role    = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            // Truncate long messages.
            if ( strlen( $content ) > 200 ) {
                $content = substr( $content, 0, 200 ) . '...';
            }

            $prefix = ( $role === 'user' ) ? 'U' : 'A';
            $context_lines[] = $prefix . ': ' . $content;
        }

        // Add current message.
        $context_lines[] = 'U_now: ' . $current_message;

        return "Context (most recent last):\n" . implode( "\n", $context_lines );
    }

    /**
     * Parse intent classification JSON from model response.
     *
     * @param string $content Model response content.
     * @return array Parsed classification with 'intent' and 'confidence'.
     */
    private function parse_intent_json( $content ) {
        // Default fallback.
        $default = array(
            'intent'     => 'general',
            'confidence' => 0.5,
        );

        if ( empty( $content ) ) {
            return $default;
        }

        // Try to extract JSON from response.
        $content = trim( $content );

        // Remove markdown code blocks if present.
        if ( preg_match( '/```(?:json)?\s*(.*?)\s*```/s', $content, $matches ) ) {
            $content = $matches[1];
        }

        // Parse JSON.
        $data = json_decode( $content, true );

        if ( ! is_array( $data ) || ! isset( $data['intent'] ) ) {
            // Try to find intent in plain text.
            $valid_intents = array( 'product', 'cart', 'order', 'policy', 'general' );
            foreach ( $valid_intents as $intent ) {
                if ( stripos( $content, $intent ) !== false ) {
                    return array(
                        'intent'     => $intent,
                        'confidence' => 0.7,
                    );
                }
            }
            return $default;
        }

        // Validate intent.
        $valid_intents = array( 'product', 'cart', 'order', 'policy', 'general' );
        $intent = strtolower( $data['intent'] ?? 'general' );
        if ( ! in_array( $intent, $valid_intents, true ) ) {
            $intent = 'general';
        }

        return array(
            'intent'     => $intent,
            'confidence' => (float) ( $data['confidence'] ?? 0.8 ),
        );
    }

    // =========================================================================
    // Message Processing (AI Orchestration)
    // =========================================================================

    /**
     * Process a user message using slot-filling agent architecture.
     *
     * This is the main orchestration method that:
     * 1. Stores the user message
     * 2. Maintains workspace state (constraints, candidates, shortlist)
     * 3. Uses structured outputs for controller JSON (clarify/tool/final)
     * 4. Executes tools and loops until a stopping condition
     * 5. Enforces loop prevention (fingerprinting, budgets)
     *
     * @param string $conversation_id Conversation UUID.
     * @param string $message         User message.
     * @param array  $context         Request context (page, cart, etc.).
     * @return array|WP_Error Response array with 'content' and 'artifacts'.
     */
    public function process_message( $conversation_id, $message, $context = array() ) {
        // Log start of processing.
        Glimmr_AI_Logger::info(
            '=== SLOT-FILLING AGENT START ===',
            array(
                'conversation_id' => $conversation_id,
                'user_message'    => $message,
                'user_id'         => get_current_user_id(),
            ),
            'agent'
        );

        // Verify conversation is active.
        if ( ! $this->is_active( $conversation_id ) ) {
            return new WP_Error(
                'conversation_inactive',
                __( 'This conversation has expired or is no longer active.', 'glimmr-ai' )
            );
        }

        // NOTE: User message is stored by the REST API handler before calling this method.
        // Do NOT store it again here to avoid duplicates.

        // Initialize workspace for this conversation.
        $workspace = new Glimmr_AI_Workspace( $conversation_id );

        // Check for persisted workspace state and restore it.
        $workspace->restore_from_session();

        // Get slot-filling limits from settings.
        $max_rounds = (int) $this->settings->get( 'max_agent_rounds', Glimmr_AI_Workspace::MAX_ROUNDS );
        $max_tools_per_turn = (int) $this->settings->get( 'max_tools_per_turn', Glimmr_AI_Workspace::MAX_TOOL_CALLS_PER_TURN );

        // Log configuration.
        Glimmr_AI_Logger::debug(
            'Agent configuration',
            array(
                'max_rounds'         => $max_rounds,
                'max_tools_per_turn' => $max_tools_per_turn,
                'workspace_state'    => $workspace->get_state_summary(),
            ),
            'agent'
        );

        // Build slot-filling system prompt.
        $context_builder = new Glimmr_AI_Context( $this->settings );
        $system_prompt   = $context_builder->get_slot_filling_system_prompt( $context, $workspace );

        // Get tool definitions from registry.
        $glimmr_ai     = Glimmr_AI::get_instance();
        $tool_registry = $glimmr_ai->get_tool_registry();
        $tools         = $tool_registry->get_definitions( true );

        // Log available tools.
        $tool_names = array_map( function( $t ) { return $t['function']['name'] ?? 'unknown'; }, $tools );
        Glimmr_AI_Logger::debug(
            'Available tools',
            array( 'tools' => $tool_names ),
            'agent'
        );

        // Get controller JSON schema.
        $controller_schema = Glimmr_AI_Controller_Schema::get_openai_format();

        // Collect artifacts from tool executions.
        $artifacts = array();

        // Time-based timeout (seconds) to prevent runaway loops.
        $max_execution_time = (int) $this->settings->get( 'agent_timeout_seconds', 60 );
        $start_time = microtime( true );

        // Agent loop.
        while ( $workspace->has_rounds_remaining() ) {
            // Check time-based timeout.
            $elapsed = microtime( true ) - $start_time;
            if ( $elapsed > $max_execution_time ) {
                Glimmr_AI_Logger::warning(
                    'Agent loop timeout exceeded',
                    array(
                        'elapsed_seconds'       => round( $elapsed, 2 ),
                        'max_seconds'           => $max_execution_time,
                        'rounds_completed'      => $workspace->get_round_count(),
                        'conversation_id'       => $conversation_id,
                    ),
                    'agent'
                );
                // Return a graceful response instead of leaving user hanging.
                $timeout_message = $this->settings->get(
                    'fallback_response',
                    __( "I'm taking too long to respond. Let me try to help with something simpler.", 'glimmr-ai' )
                );
                $this->add_message( $conversation_id, 'assistant', $timeout_message );
                return array(
                    'content'   => $timeout_message,
                    'artifacts' => $artifacts,
                );
            }

            $workspace->increment_round();

            // Log round start.
            Glimmr_AI_Logger::info(
                sprintf( '--- ROUND %d/%d ---', $workspace->get_round_count(), $max_rounds ),
                array(
                    'workspace_constraints' => $workspace->get_constraints(),
                    'candidates_count'      => count( $workspace->get_candidates() ),
                    'shortlist_count'       => count( $workspace->get_shortlist() ),
                    'tool_calls_this_turn'  => $workspace->get_tool_calls_this_turn(),
                ),
                'agent'
            );

            // Get messages for context.
            $api_messages = $this->get_context_for_api( $conversation_id, $system_prompt );
            $api_input    = $this->openai->messages_to_input( $api_messages );

            // Inject workspace state as a system context message.
            $workspace_context = array(
                'type'    => 'message',
                'role'    => 'system',
                'content' => sprintf(
                    "Current Workspace State:\n%s\n\nRound: %d/%d",
                    $workspace->get_prompt_context(),
                    $workspace->get_round_count(),
                    $max_rounds
                ),
            );
            $api_input[] = $workspace_context;

            // Inject Resolution Pack if focus frame has entities (v1.8.0).
            $focus_frame = $workspace->get_focus_frame();
            if ( $focus_frame->has_entities() ) {
                $api_input[] = array(
                    'type'    => 'message',
                    'role'    => 'system',
                    'content' => $focus_frame->get_resolution_pack_prompt(),
                );

                Glimmr_AI_Logger::debug(
                    'Injected Resolution Pack',
                    array(
                        'has_primary'     => $focus_frame->get_primary_product() !== null,
                        'product_count'   => count( $focus_frame->get_product_list() ),
                        'has_order'       => $focus_frame->get_last_order() !== null,
                        'cart_item_count' => count( $focus_frame->get_cart_items() ),
                    ),
                    'agent'
                );
            }

            // Log API call.
            Glimmr_AI_Logger::debug(
                'Calling OpenAI with structured output',
                array( 'message_count' => count( $api_input ) ),
                'agent'
            );

            // Call OpenAI with structured output.
            $response = $this->openai->create_response_structured(
                $api_input,
                $tools,
                $system_prompt,
                $controller_schema,
                array( 'use_file_search' => false ) // RAG handled by tools.
            );

            if ( is_wp_error( $response ) ) {
                // Log error and return fallback.
                Glimmr_AI_Logger::error(
                    'Slot-filling agent error',
                    array(
                        'error'           => $response->get_error_message(),
                        'conversation_id' => $conversation_id,
                        'round'           => $workspace->get_round_count(),
                    ),
                    'conversation'
                );

                // Store fallback response.
                $fallback = $this->settings->get( 'fallback_response', __( "I'm sorry, I'm having trouble processing your request. Please try again.", 'glimmr-ai' ) );
                $this->add_message( $conversation_id, 'assistant', $fallback );

                return array(
                    'content'   => $fallback,
                    'artifacts' => $artifacts,
                );
            }

            // Parse and validate controller response.
            $controller = $response['content'];
            if ( is_string( $controller ) ) {
                $parse_result = Glimmr_AI_Controller_Schema::parse( $controller );
                if ( is_wp_error( $parse_result ) ) {
                    Glimmr_AI_Logger::warning(
                        'Failed to parse controller response',
                        array(
                            'error_code'     => $parse_result->get_error_code(),
                            'error'          => $parse_result->get_error_message(),
                            'response_usage' => $response['usage'] ?? null,
                        ),
                        'conversation'
                    );
                    // Use fallback response - check if this was a truncation issue.
                    $is_truncated = 'json_truncated' === $parse_result->get_error_code();
                    $usage = $response['usage'] ?? array();
                    $output_tokens = $usage['output_tokens'] ?? 0;

                    if ( $is_truncated || $output_tokens > 2500 ) {
                        // Response was truncated - suggest simpler question.
                        $controller = Glimmr_AI_Controller_Schema::create_clarify_response(
                            "I had trouble processing that request. Could you try asking about one thing at a time?"
                        );
                    } else {
                        $controller = Glimmr_AI_Controller_Schema::create_clarify_response(
                            "I had trouble understanding your request. Could you try rephrasing it?"
                        );
                    }
                } else {
                    $controller = $parse_result;
                }
            }

            // Validate controller response.
            $validation = Glimmr_AI_Controller_Schema::validate( $controller );
            if ( is_wp_error( $validation ) ) {
                Glimmr_AI_Logger::warning(
                    'Controller validation failed',
                    array( 'error' => $validation->get_error_message(), 'controller' => $controller ),
                    'conversation'
                );
                $controller = Glimmr_AI_Controller_Schema::create_clarify_response(
                    "I need a bit more information. What are you looking for?"
                );
            }

            // Log the controller response.
            Glimmr_AI_Logger::info(
                sprintf( 'Controller action: %s', strtoupper( $controller['action'] ?? 'unknown' ) ),
                array(
                    'action'            => $controller['action'],
                    'thought'           => $controller['thought'] ?? '',
                    'user_message'      => substr( $controller['user_message'] ?? '', 0, 200 ),
                    'tool_call'         => $controller['tool_call'] ?? null,
                    'workspace_updates' => $controller['workspace_updates'] ?? null,
                ),
                'agent'
            );

            // Apply workspace updates if present.
            if ( ! empty( $controller['workspace_updates'] ) ) {
                $workspace->apply_updates( $controller['workspace_updates'] );
                Glimmr_AI_Logger::debug(
                    'Workspace updated',
                    array( 'updates' => $controller['workspace_updates'] ),
                    'agent'
                );
            }

            // Handle based on action.
            switch ( $controller['action'] ) {
                case 'clarify':
                    // STOP - ask user for clarification.
                    $user_message = $controller['user_message'] ?? '';

                    Glimmr_AI_Logger::info(
                        '=== AGENT STOP (clarify) ===',
                        array(
                            'response' => $user_message,
                            'rounds'   => $workspace->get_round_count(),
                        ),
                        'agent'
                    );

                    // Store assistant message.
                    $this->add_message(
                        $conversation_id,
                        'assistant',
                        $user_message,
                        null,
                        null,
                        $response['usage']['total_tokens'] ?? 0
                    );

                    // Persist workspace for next message.
                    $workspace->persist_to_session();

                    // Track analytics.
                    $this->track_message_analytics( $conversation_id, $response );

                    return array(
                        'content'   => $user_message,
                        'artifacts' => $artifacts,
                    );

                case 'tool':
                    // Check loop prevention.
                    $tool_call = $controller['tool_call'] ?? array();
                    $tool_name = $tool_call['name'] ?? '';

                    // Parse arguments from JSON string (schema uses arguments_json for strict mode compatibility).
                    $tool_args = array();
                    if ( ! empty( $tool_call['arguments_json'] ) ) {
                        $parsed = json_decode( $tool_call['arguments_json'], true );
                        if ( null === $parsed && json_last_error() !== JSON_ERROR_NONE ) {
                            Glimmr_AI_Logger::warning(
                                'Failed to parse tool arguments JSON',
                                array(
                                    'tool'  => $tool_name,
                                    'error' => json_last_error_msg(),
                                    'json'  => substr( $tool_call['arguments_json'], 0, 200 ),
                                ),
                                'conversation'
                            );
                            // Continue with empty args - tool will handle missing required params.
                        } elseif ( is_array( $parsed ) ) {
                            $tool_args = $parsed;
                        }
                    } elseif ( ! empty( $tool_call['arguments'] ) && is_array( $tool_call['arguments'] ) ) {
                        // Fallback for legacy format.
                        $tool_args = $tool_call['arguments'];
                    }

                    // Check duplicate.
                    $fingerprint = $workspace->generate_tool_fingerprint( $tool_name, $tool_args );
                    if ( $workspace->is_duplicate_tool_call( $fingerprint ) ) {
                        Glimmr_AI_Logger::debug(
                            'Skipping duplicate tool call',
                            array( 'tool' => $tool_name, 'fingerprint' => $fingerprint ),
                            'conversation'
                        );
                        continue 2; // Skip to next iteration.
                    }

                    // Check tool budget.
                    if ( ! $workspace->can_call_more_tools() ) {
                        Glimmr_AI_Logger::debug(
                            'Tool budget exceeded, forcing final',
                            array( 'tool_count' => $workspace->get_tool_calls_this_turn() ),
                            'conversation'
                        );
                        // Force a final response.
                        break 2;
                    }

                    // Validate entity references before tool execution (v1.8.0).
                    $validator  = new Glimmr_AI_Reference_Validator();
                    $validation = $validator->validate( $tool_name, $tool_args, $focus_frame, $message );

                    if ( ! $validation->is_valid() ) {
                        Glimmr_AI_Logger::info(
                            'Reference validation failed',
                            array(
                                'tool'         => $tool_name,
                                'invalid_refs' => $validation->get_invalid_refs(),
                            ),
                            'agent'
                        );

                        // Return clarification instead of executing the tool.
                        $clarification = $validator->build_clarification( $validation, $focus_frame );
                        $this->add_message( $conversation_id, 'assistant', $clarification['message'] );

                        // Clear workspace for next conversation turn.
                        $workspace->reset();

                        return array(
                            'content'   => $clarification['message'],
                            'artifacts' => $artifacts,
                        );
                    }

                    // Execute the tool.
                    $workspace->increment_tool_calls();
                    $workspace->record_tool_fingerprint( $fingerprint );

                    Glimmr_AI_Logger::info(
                        sprintf( 'Executing tool: %s', $tool_name ),
                        array(
                            'tool'      => $tool_name,
                            'arguments' => $tool_args,
                        ),
                        'agent'
                    );

                    $tool_result = $tool_registry->execute( $tool_name, $tool_args );

                    // Log tool result.
                    $result_summary = is_array( $tool_result )
                        ? array(
                            'success' => $tool_result['success'] ?? false,
                            'data_keys' => isset( $tool_result['data'] ) ? array_keys( $tool_result['data'] ) : null,
                            'error' => $tool_result['error'] ?? null,
                        )
                        : array( 'raw' => substr( (string) $tool_result, 0, 200 ) );

                    Glimmr_AI_Logger::info(
                        sprintf( 'Tool result: %s', $tool_result['success'] ?? false ? 'SUCCESS' : 'FAILED' ),
                        $result_summary,
                        'agent'
                    );

                    // Track tool call analytics.
                    if ( class_exists( 'Glimmr_AI_Analytics' ) ) {
                        $success = is_array( $tool_result ) ? ( $tool_result['success'] ?? true ) : true;
                        Glimmr_AI_Analytics::track_tool_call( $conversation_id, $tool_name, $success, $tool_args );
                    }

                    // Collect artifact from tool result.
                    $artifact = $this->tool_result_to_artifact(
                        array(
                            'tool_name' => $tool_name,
                            'output'    => $tool_result,
                        )
                    );
                    if ( $artifact ) {
                        $artifacts[] = $artifact;
                        Glimmr_AI_Logger::debug(
                            'Artifact created',
                            array( 'type' => $artifact['type'] ),
                            'agent'
                        );
                    }

                    // Generate a unique call_id for this tool call (not provided by controller JSON).
                    $call_id = 'call_' . uniqid();

                    // Store assistant message with tool call info.
                    // NOTE: We store empty content, NOT the internal "thought" which is for logging only.
                    // The thought is internal reasoning and should not be shown to users.
                    $tool_call_with_id = array(
                        'call_id'   => $call_id,
                        'name'      => $tool_name,
                        'arguments' => $tool_args,
                    );
                    $this->add_message(
                        $conversation_id,
                        'assistant',
                        '', // Empty content - thought is internal only.
                        array( $tool_call_with_id ),
                        null,
                        $response['usage']['total_tokens'] ?? 0
                    );

                    // Store tool result.
                    $this->add_message(
                        $conversation_id,
                        'tool',
                        is_string( $tool_result ) ? $tool_result : wp_json_encode( $tool_result ),
                        null,
                        array( 'tool_call_id' => $call_id )
                    );

                    // Update workspace with tool results.
                    $workspace->process_tool_result( $tool_name, $tool_result );

                    // Update focus frame with entities from tool result (v1.8.0).
                    $focus_frame->update_from_tool_result( $tool_name, $tool_result );

                    // Continue to next round.
                    break;

                case 'final':
                    // STOP - provide final answer.
                    $user_message = $controller['user_message'] ?? '';

                    Glimmr_AI_Logger::info(
                        '=== AGENT STOP (final) ===',
                        array(
                            'response'        => substr( $user_message, 0, 300 ),
                            'rounds'          => $workspace->get_round_count(),
                            'artifacts_count' => count( $artifacts ),
                            'artifact_types'  => array_map( function( $a ) { return $a['type']; }, $artifacts ),
                        ),
                        'agent'
                    );

                    // Store assistant message.
                    $this->add_message(
                        $conversation_id,
                        'assistant',
                        $user_message,
                        null,
                        null,
                        $response['usage']['total_tokens'] ?? 0
                    );

                    // Clear workspace for next conversation turn.
                    $workspace->reset();

                    // Track analytics.
                    $this->track_message_analytics( $conversation_id, $response );

                    return array(
                        'content'   => $user_message,
                        'artifacts' => $artifacts,
                    );
            }
        }

        // Fallback if we exit the loop without a final action.
        Glimmr_AI_Logger::warning(
            'Agent loop exhausted without final action',
            array(
                'conversation_id' => $conversation_id,
                'rounds'          => $workspace->get_round_count(),
            ),
            'conversation'
        );

        $fallback_message = __( "I've gathered some information but need to wrap up. Based on what I found, how can I help you further?", 'glimmr-ai' );

        $this->add_message( $conversation_id, 'assistant', $fallback_message );
        $workspace->reset();

        return array(
            'content'   => $fallback_message,
            'artifacts' => $artifacts,
        );
    }

    /**
     * Convert a tool result to an artifact for frontend display.
     *
     * @param array $result Tool result with 'call_id', 'output', and 'tool_name'.
     * @return array|null Artifact with 'type' and 'data', or null if not displayable.
     */
    private function tool_result_to_artifact( $result ) {
        $tool_name = $result['tool_name'] ?? '';
        $output    = $result['output'] ?? '';

        // Parse JSON output.
        if ( is_string( $output ) ) {
            $data = json_decode( $output, true );
            if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
                // Log only for unexpected JSON errors - not for non-JSON tool outputs.
                if ( strlen( $output ) > 0 && '{' === $output[0] ) {
                    Glimmr_AI_Logger::warning(
                        'Failed to parse tool result JSON',
                        array( 'tool' => $tool_name, 'error' => json_last_error_msg() ),
                        'conversation'
                    );
                }
                return null;
            }
        } else {
            $data = $output;
        }

        if ( ! $data || ! isset( $data['success'] ) || ! $data['success'] ) {
            return null;
        }

        // Check for cart_action status - these are handled specially by frontend.
        // Cart-mutating tools (add_to_cart, update_cart, apply_coupon) return cart_action
        // intents that the frontend executes via WooCommerce Store API.
        // format_outcome() uses 'status' field for the outcome type.
        if ( isset( $data['status'] ) && 'cart_action' === $data['status'] ) {
            return array(
                'type' => 'cart_action',
                'data' => $data['data'] ?? array(),
            );
        }

        // Map tool names to artifact types.
        // NOTE: Keep in sync with tool_content_to_artifact() in class-glimmr-ai-rest-api.php
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

        // For product tools, wrap products array if needed.
        if ( in_array( $tool_name, array( 'product_lookup', 'query_products', 'select_products' ), true ) ) {
            if ( is_array( $artifact_data ) && ! isset( $artifact_data['products'] ) ) {
                $artifact_data = array(
                    'products' => $artifact_data,
                    'total'    => count( $artifact_data ),
                );
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
        }

        return array(
            'type' => $artifact_type,
            'data' => $artifact_data,
        );
    }

    /**
     * Build system prompt with context.
     *
     * @param array $context Request context.
     * @return string System prompt.
     */
    private function build_system_prompt( $context = array() ) {
        $context_builder = new Glimmr_AI_Context( $this->settings );
        return $context_builder->build_system_prompt( $context );
    }

    /**
     * Execute function calls and return results.
     *
     * For the Responses API, function calls have this format:
     * - call_id: The ID to reference in function_call_output
     * - name: Function name
     * - arguments: Already decoded array
     *
     * @param array                   $function_calls Function calls from Responses API.
     * @param Glimmr_AI_Tool_Registry $tool_registry  Tool registry instance.
     * @return array Function results formatted for API.
     */
    private function execute_function_calls( $function_calls, $tool_registry, $conversation_id = null ) {
        $results = array();

        // Type validation - ensure $function_calls is an array.
        if ( ! is_array( $function_calls ) ) {
            Glimmr_AI_Logger::warning(
                'execute_function_calls received non-array input',
                array( 'type' => gettype( $function_calls ) ),
                'conversation'
            );
            return $results;
        }

        foreach ( $function_calls as $call ) {
            $function_name = $call['name'] ?? '';
            $arguments     = $call['arguments'] ?? array();
            $call_id       = $call['call_id'] ?? '';

            // Log tool call before execution.
            Glimmr_AI_Logger::info(
                'Executing tool call',
                array(
                    'tool'      => $function_name,
                    'call_id'   => $call_id,
                    'arguments' => $arguments,
                ),
                'tools'
            );

            // Execute via registry.
            $result = $tool_registry->execute( $function_name, $arguments );
            $result_json = is_string( $result ) ? $result : wp_json_encode( $result );

            // Log tool result after execution.
            $result_summary = array( 'tool' => $function_name, 'call_id' => $call_id );
            if ( is_array( $result ) ) {
                $result_summary['success']      = $result['success'] ?? null;
                $result_summary['status']       = $result['status'] ?? null;
                $result_summary['product_count'] = isset( $result['data']['count'] ) ? $result['data']['count'] : null;
                $result_summary['result_length'] = strlen( $result_json );
            } else {
                $result_summary['result_length'] = strlen( $result_json );
            }
            Glimmr_AI_Logger::info(
                'Tool call result',
                array_filter( $result_summary, function( $v ) { return $v !== null; } ),
                'tools'
            );

            // Track tool call analytics.
            if ( $conversation_id && class_exists( 'Glimmr_AI_Analytics' ) ) {
                $success = is_array( $result ) ? ( $result['success'] ?? true ) : true;
                Glimmr_AI_Analytics::track_tool_call( $conversation_id, $function_name, $success, $arguments );
            }

            // Format result for Responses API (function_call_output format).
            // Include tool_name for artifact mapping.
            $results[] = array(
                'call_id'   => $call_id,
                'tool_name' => $function_name,
                'output'    => $result_json,
            );
        }

        return $results;
    }

    /**
     * Track message analytics.
     *
     * @param string $conversation_id Conversation ID.
     * @param array  $response        API response from Responses API.
     */
    private function track_message_analytics( $conversation_id, $response ) {
        if ( ! class_exists( 'Glimmr_AI_Analytics' ) ) {
            return;
        }

        try {
            // Use static track method - Analytics class uses static methods.
            Glimmr_AI_Analytics::track_message_received(
                $conversation_id,
                $response['usage']['total_tokens'] ?? 0,
                0 // Response time tracked elsewhere.
            );

            // Track tool calls if any.
            if ( ! empty( $response['function_calls'] ) && is_array( $response['function_calls'] ) ) {
                foreach ( $response['function_calls'] as $call ) {
                    Glimmr_AI_Analytics::track_tool_call(
                        $conversation_id,
                        $call['name'] ?? 'unknown',
                        true,
                        array()
                    );
                }
            }
        } catch ( Exception $e ) {
            // Log but don't break the chat.
            Glimmr_AI_Logger::debug(
                'Analytics tracking failed',
                array( 'error' => $e->getMessage(), 'conversation_id' => $conversation_id ?? 'unknown' ),
                'analytics'
            );
        }
    }

    // =========================================================================
    // Streaming Message Processing
    // =========================================================================

    /**
     * Process a user message with streaming response.
     *
     * Similar to process_message() but uses streaming for real-time text output.
     * The stream_callback is called with each text chunk as it arrives.
     *
     * Note: Tool calls are not streamed - they execute after text streaming completes.
     * If the AI decides to call tools, the callback receives tool status updates.
     *
     * @param string   $conversation_id  Conversation UUID.
     * @param string   $message          User message.
     * @param array    $context          Request context (page, cart, etc.).
     * @param callable $stream_callback  Callback for streaming: fn(string $chunk) => void.
     * @return string|WP_Error Full AI response text or error.
     */
    public function process_message_streaming( $conversation_id, $message, $context = array(), $stream_callback = null, $status_callback = null, $artifact_callback = null ) {
        // Verify conversation is active.
        if ( ! $this->is_active( $conversation_id ) ) {
            return new WP_Error(
                'conversation_inactive',
                __( 'This conversation has expired or is no longer active.', 'glimmr-ai' )
            );
        }

        // Note: We no longer block on message limit. The sliding window in
        // get_context_for_api() handles keeping context manageable.

        // Send initial status.
        if ( is_callable( $status_callback ) ) {
            try {
                call_user_func( $status_callback, 'thinking', 'Reviewing your request...' );
            } catch ( Exception $e ) {
                Glimmr_AI_Logger::warning( 'Status callback failed', array( 'error' => $e->getMessage() ), 'conversation' );
            }
        }

        // Store the user message.
        $this->add_message( $conversation_id, 'user', $message );

        // Build system prompt (used as instructions in Responses API).
        $system_prompt = $this->build_system_prompt( $context );

        // Get messages and convert to Responses API input format.
        // This applies sliding window - only recent messages are sent to AI.
        $api_messages = $this->get_context_for_api( $conversation_id, $system_prompt );
        $api_input    = $this->openai->messages_to_input( $api_messages );

        // Get tool definitions from registry.
        $glimmr_ai     = Glimmr_AI::get_instance();
        $tool_registry = $glimmr_ai->get_tool_registry();
        $tools         = $tool_registry->get_definitions( true );

        // Track accumulated response for tool call loops.
        $full_response = '';

        // Create streaming callback wrapper that accumulates content.
        $wrapped_callback = function( $chunk ) use ( &$full_response, $stream_callback ) {
            $full_response .= $chunk;
            if ( is_callable( $stream_callback ) ) {
                try {
                    call_user_func( $stream_callback, $chunk );
                } catch ( Exception $e ) {
                    // Log but don't break streaming - connection may have closed.
                    Glimmr_AI_Logger::debug( 'Stream callback failed', array( 'error' => $e->getMessage() ), 'conversation' );
                }
            }
        };

        // Call OpenAI Responses API with streaming.
        $response = $this->openai->create_response_streaming(
            $api_input,
            $tools,
            $system_prompt,
            $wrapped_callback
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Handle function calls if present (multi-turn loop).
        // Note: During tool execution, we don't stream - we wait for results.
        $max_tool_rounds = $this->tool_config['max_tool_rounds'];
        $current_round   = 0;

        // Time-based timeout for tool execution loop.
        $max_execution_time = (int) $this->settings->get( 'agent_timeout_seconds', 60 );
        $loop_start_time = microtime( true );

        while ( ! empty( $response['function_calls'] ) && is_array( $response['function_calls'] ) && $current_round < $max_tool_rounds ) {
            // Check time-based timeout.
            $elapsed = microtime( true ) - $loop_start_time;
            if ( $elapsed > $max_execution_time ) {
                Glimmr_AI_Logger::warning(
                    'Streaming tool loop timeout exceeded',
                    array(
                        'elapsed_seconds'  => round( $elapsed, 2 ),
                        'max_seconds'      => $max_execution_time,
                        'rounds_completed' => $current_round,
                        'conversation_id'  => $conversation_id,
                    ),
                    'agent'
                );
                break; // Exit loop gracefully.
            }

            $current_round++;

            // Send status updates for each tool being executed.
            foreach ( $response['function_calls'] as $function_call ) {
                $tool_name   = $function_call['name'] ?? '';
                $tool_status = $this->get_tool_status_message( $tool_name );
                if ( is_callable( $status_callback ) ) {
                    try {
                        call_user_func( $status_callback, 'tool', $tool_status );
                    } catch ( Exception $e ) {
                        Glimmr_AI_Logger::warning( 'Status callback failed', array( 'error' => $e->getMessage() ), 'conversation' );
                    }
                }
            }

            // Execute function calls and collect artifacts.
            $function_results = $this->execute_function_calls( $response['function_calls'], $tool_registry, $conversation_id );

            // Convert tool results to artifacts and send via callback.
            foreach ( $function_results as $result ) {
                $artifact = $this->tool_result_to_artifact( $result );
                if ( $artifact && is_callable( $artifact_callback ) ) {
                    try {
                        call_user_func( $artifact_callback, $artifact );
                    } catch ( Exception $e ) {
                        Glimmr_AI_Logger::warning( 'Artifact callback failed', array( 'error' => $e->getMessage() ), 'conversation' );
                    }
                }
            }

            // Store assistant message with function calls.
            $this->add_message(
                $conversation_id,
                'assistant',
                $response['content'] ?? '',
                $response['function_calls'],
                null,
                $response['usage']['total_tokens'] ?? 0
            );

            // Store function results.
            foreach ( $function_results as $result ) {
                $this->add_message(
                    $conversation_id,
                    'tool',
                    $result['output'],
                    null,
                    array( 'tool_call_id' => $result['call_id'] )
                );
            }

            // Build input for next API call with function outputs.
            $api_messages = $this->get_context_for_api( $conversation_id, $system_prompt );
            $api_input    = $this->openai->messages_to_input( $api_messages );

            // Reset full response for the new streaming response.
            $full_response = '';

            // Send status update before generating response.
            if ( is_callable( $status_callback ) ) {
                try {
                    call_user_func( $status_callback, 'responding', 'Preparing response...' );
                } catch ( Exception $e ) {
                    Glimmr_AI_Logger::warning( 'Status callback failed', array( 'error' => $e->getMessage() ), 'conversation' );
                }
            }

            // Get follow-up response with streaming.
            $response = $this->openai->create_response_streaming(
                $api_input,
                $tools,
                $system_prompt,
                $wrapped_callback
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }
        }

        // Get final content (either from streaming or response object).
        $assistant_content = ! empty( $full_response ) ? $full_response : ( $response['content'] ?? '' );

        // Store the final assistant response.
        $this->add_message(
            $conversation_id,
            'assistant',
            $assistant_content,
            null,
            null,
            $response['usage']['total_tokens'] ?? 0
        );

        // Track analytics.
        $this->track_message_analytics( $conversation_id, $response );

        return $assistant_content;
    }

    /**
     * Get a human-readable status message for a tool.
     *
     * @param string $tool_name The tool name.
     * @return string Human-readable status message.
     */
    private function get_tool_status_message( $tool_name ) {
        $messages = array(
            // Product tools.
            'query_products'            => 'Searching products...',
            'product_lookup'            => 'Looking up product details...',
            'product_compare'           => 'Comparing products...',
            'stock_check'               => 'Checking availability...',
            'recommendations'           => 'Finding recommendations...',

            // Cart tools.
            'add_to_cart'               => 'Adding to cart...',
            'view_cart'                 => 'Loading your cart...',
            'update_cart'               => 'Updating cart...',
            'apply_coupon'              => 'Applying coupon...',
            'checkout_link'             => 'Preparing checkout...',

            // Coupon tools.
            'coupon_lookup'             => 'Finding available coupons...',

            // Order tools.
            'order_status'              => 'Checking order status...',
            'order_history'             => 'Loading order history...',
            'reorder'                   => 'Processing reorder...',

            // Account tools.
            'account_info'              => 'Loading account info...',

            // Knowledge tools.
            'site_knowledge'            => 'Searching knowledge base...',
            'text_answer'               => 'Thinking...',

            // Review tools (v1.8.0).
            'get_reviews'               => 'Loading reviews...',
            'summarize_reviews'         => 'Analyzing reviews...',

            // Support tools (v1.8.0).
            'contact_request'           => 'Submitting request...',
            'check_gift_card_balance'   => 'Checking gift card...',
            'track_package'             => 'Tracking package...',

            // Navigation tool.
            'navigate_to_page'          => 'Navigating...',

            // Resolver tools.
            'resolve_product'           => 'Finding product...',
            'resolve_variation'         => 'Checking variations...',
            'resolve_order'             => 'Locating order...',
            'resolve_cart_item'         => 'Checking cart...',
            'select_products'           => 'Selecting products...',

            // Query tools.
            'sql_readonly'              => 'Querying data...',
            'catalog_query'             => 'Searching catalog...',
        );

        return $messages[ $tool_name ] ?? 'Processing...';
    }
}
