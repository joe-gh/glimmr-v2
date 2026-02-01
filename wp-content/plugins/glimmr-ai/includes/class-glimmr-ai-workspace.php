<?php
/**
 * Workspace State Manager
 *
 * Server-side state management for slot-filling agent architecture.
 * Tracks constraints, candidates, shortlist, and loop prevention.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Workspace
 *
 * Manages state between agent rounds for a conversation.
 * Persists via WordPress transients for cross-request continuity.
 */
class Glimmr_AI_Workspace {

	/**
	 * Default maximum rounds per user message.
	 */
	const MAX_ROUNDS = 5;

	/**
	 * Default maximum tool calls per turn.
	 */
	const MAX_TOOL_CALLS_PER_TURN = 3;

	/**
	 * Conversation ID.
	 *
	 * @var string
	 */
	private $conversation_id;

	/**
	 * User-specified constraints (category, price, size, color, etc.).
	 *
	 * @var array
	 */
	private $constraints = array();

	/**
	 * Product IDs found during search.
	 *
	 * @var array
	 */
	private $candidates = array();

	/**
	 * Narrowed selection of product IDs (max 5).
	 *
	 * @var array
	 */
	private $shortlist = array();

	/**
	 * Focused product IDs for contextual follow-ups.
	 *
	 * Persists across conversation turns so queries like
	 * "Does it come in medium?" can reference previously shown products.
	 *
	 * @var array
	 */
	private $focused_product_ids = array();

	/**
	 * Entity focus stack for pronoun resolution.
	 *
	 * Tracks different entity types separately for precise resolution
	 * of "it", "that", "those" references.
	 *
	 * @var array
	 */
	private $entity_focus = array(
		'products'         => array(), // Stack of product IDs (most recent first, max 5).
		'orders'           => array(), // Stack of order IDs.
		'cart_items'       => array(), // Stack of cart item keys.
		'last_entity_type' => null,    // 'product', 'order', 'cart_item'.
		'last_updated'     => null,    // Timestamp of last focus update.
	);

	/**
	 * Focus Frame for LLM-based reference resolution.
	 *
	 * Provides typed entity tracking with Resolution Pack prompt generation
	 * for accurate pronoun resolution in a single LLM call.
	 *
	 * @var Glimmr_AI_Focus_Frame|null
	 * @since 1.8.0
	 */
	private $focus_frame = null;

	/**
	 * Last tool that was executed (for routing decisions).
	 *
	 * @var string
	 */
	private $last_tool_name = '';

	/**
	 * Tool call fingerprints for deduplication.
	 *
	 * @var array
	 */
	private $tool_fingerprints = array();

	/**
	 * Current round count.
	 *
	 * @var int
	 */
	private $round_count = 0;

	/**
	 * Tool calls made in current turn.
	 *
	 * @var int
	 */
	private $tool_calls_this_turn = 0;

	/**
	 * Maximum rounds allowed per user message.
	 *
	 * @var int
	 */
	private $max_rounds = 5;

	/**
	 * Maximum tool calls per turn.
	 *
	 * @var int
	 */
	private $max_tools_per_turn = 3;

	/**
	 * Transient expiration time in seconds.
	 *
	 * @var int
	 */
	private $transient_expiration = 3600; // 1 hour

	/**
	 * Constructor.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param array  $options         Optional configuration overrides.
	 */
	public function __construct( $conversation_id, $options = array() ) {
		$this->conversation_id = $conversation_id;

		// Apply configuration overrides.
		if ( isset( $options['max_rounds'] ) ) {
			$this->max_rounds = absint( $options['max_rounds'] );
		}
		if ( isset( $options['max_tools_per_turn'] ) ) {
			$this->max_tools_per_turn = absint( $options['max_tools_per_turn'] );
		}
		if ( isset( $options['transient_expiration'] ) ) {
			$this->transient_expiration = absint( $options['transient_expiration'] );
		}

		// Load existing state from transient.
		$this->load_state();
	}

	/**
	 * Load state from transient.
	 */
	private function load_state() {
		$transient_key = $this->get_transient_key();
		$state = get_transient( $transient_key );

		if ( is_array( $state ) ) {
			$this->constraints          = $state['constraints'] ?? array();
			$this->candidates           = $state['candidates'] ?? array();
			$this->shortlist            = $state['shortlist'] ?? array();
			$this->focused_product_ids  = $state['focused_product_ids'] ?? array();
			$this->last_tool_name       = $state['last_tool_name'] ?? '';
			$this->tool_fingerprints    = $state['tool_fingerprints'] ?? array();
			$this->round_count          = $state['round_count'] ?? 0;
			$this->tool_calls_this_turn = $state['tool_calls_this_turn'] ?? 0;

			// Load entity focus stack.
			if ( isset( $state['entity_focus'] ) && is_array( $state['entity_focus'] ) ) {
				$this->entity_focus = array_merge( $this->entity_focus, $state['entity_focus'] );
			}

			// Load focus frame for LLM-based reference resolution.
			if ( isset( $state['focus_frame'] ) && is_array( $state['focus_frame'] ) ) {
				$this->focus_frame = Glimmr_AI_Focus_Frame::from_array( $state['focus_frame'] );
			} else {
				$this->focus_frame = new Glimmr_AI_Focus_Frame();
			}
		} elseif ( false === $state ) {
			// Transient not found - either first load or expired.
			// Log at debug level since this is expected for new conversations.
			Glimmr_AI_Logger::debug(
				'Workspace transient not found (new or expired)',
				array(
					'conversation_id' => $this->conversation_id,
					'transient_key'   => $transient_key,
				),
				'workspace'
			);
		}
	}

	/**
	 * Save state to transient.
	 */
	public function save_state() {
		$transient_key = $this->get_transient_key();
		$state = array(
			'constraints'          => $this->constraints,
			'candidates'           => $this->candidates,
			'shortlist'            => $this->shortlist,
			'focused_product_ids'  => $this->focused_product_ids,
			'last_tool_name'       => $this->last_tool_name,
			'tool_fingerprints'    => $this->tool_fingerprints,
			'round_count'          => $this->round_count,
			'tool_calls_this_turn' => $this->tool_calls_this_turn,
			'entity_focus'         => $this->entity_focus,
			'focus_frame'          => $this->focus_frame ? $this->focus_frame->to_array() : null,
		);

		$saved = set_transient( $transient_key, $state, $this->transient_expiration );
		if ( ! $saved ) {
			Glimmr_AI_Logger::warning(
				'Failed to save workspace transient',
				array(
					'conversation_id' => $this->conversation_id,
					'transient_key'   => $transient_key,
					'state_size'      => strlen( maybe_serialize( $state ) ),
				),
				'workspace'
			);
		}
	}

	/**
	 * Get transient key for this workspace.
	 *
	 * @return string Transient key.
	 */
	private function get_transient_key() {
		return 'glimmr_ai_workspace_' . md5( $this->conversation_id );
	}

	/**
	 * Apply updates from controller response.
	 *
	 * @param array $updates Workspace updates from AI.
	 */
	public function apply_updates( $updates ) {
		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return;
		}

		// Merge constraints - handle both JSON string and array formats.
		if ( isset( $updates['constraints'] ) ) {
			$new_constraints = $updates['constraints'];

			// If constraints is a JSON string, decode it.
			if ( is_string( $new_constraints ) ) {
				$decoded = json_decode( $new_constraints, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					Glimmr_AI_Logger::warning(
						'Workspace constraints JSON decode failed',
						array(
							'json_error'   => json_last_error_msg(),
							'string_length' => strlen( $new_constraints ),
						),
						'workspace'
					);
					$new_constraints = array();
				} elseif ( is_array( $decoded ) ) {
					$new_constraints = $decoded;
				} else {
					$new_constraints = array();
				}
			}

			// Merge if we have valid constraints.
			if ( is_array( $new_constraints ) && ! empty( $new_constraints ) ) {
				$this->constraints = array_merge( $this->constraints, $new_constraints );
			}
		}

		// Replace candidates if provided.
		if ( isset( $updates['candidates'] ) && is_array( $updates['candidates'] ) ) {
			$this->candidates = array_map( 'absint', $updates['candidates'] );
		}

		// Replace shortlist if provided (max 5).
		if ( isset( $updates['shortlist'] ) && is_array( $updates['shortlist'] ) ) {
			$this->shortlist = array_slice( array_map( 'absint', $updates['shortlist'] ), 0, 5 );
		}

		$this->save_state();
	}

	/**
	 * Generate a fingerprint for a tool call.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return string MD5 fingerprint.
	 */
	public function generate_tool_fingerprint( $tool_name, $arguments ) {
		// Sort arguments for consistent hashing.
		$sorted_args = $this->sort_recursive( $arguments );
		$payload = $tool_name . ':' . wp_json_encode( $sorted_args );
		return md5( $payload );
	}

	/**
	 * Recursively sort array keys for consistent hashing.
	 *
	 * @param mixed $data Data to sort.
	 * @return mixed Sorted data.
	 */
	private function sort_recursive( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Check if associative array.
		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
			ksort( $data );
		}

		foreach ( $data as $key => $value ) {
			$data[ $key ] = $this->sort_recursive( $value );
		}

		return $data;
	}

	/**
	 * Check if a tool call is a duplicate.
	 *
	 * @param string $fingerprint Tool call fingerprint.
	 * @return bool True if duplicate.
	 */
	public function is_duplicate_tool_call( $fingerprint ) {
		return in_array( $fingerprint, $this->tool_fingerprints, true );
	}

	/**
	 * Record a tool call fingerprint.
	 *
	 * @param string $fingerprint Tool call fingerprint.
	 */
	public function record_tool_call( $fingerprint ) {
		if ( ! $this->is_duplicate_tool_call( $fingerprint ) ) {
			$this->tool_fingerprints[] = $fingerprint;
			$this->tool_calls_this_turn++;
			$this->save_state();
		}
	}

	/**
	 * Check if more tool calls are allowed this turn.
	 *
	 * @return bool True if can call more tools.
	 */
	public function can_call_more_tools() {
		return $this->tool_calls_this_turn < $this->max_tools_per_turn;
	}

	/**
	 * Check if more rounds are available.
	 *
	 * @return bool True if rounds remaining.
	 */
	public function has_rounds_remaining() {
		return $this->round_count < $this->max_rounds;
	}

	/**
	 * Increment round count.
	 */
	public function increment_round() {
		$this->round_count++;
		$this->save_state();
	}

	/**
	 * Reset tool calls for new turn.
	 */
	public function reset_turn() {
		$this->tool_calls_this_turn = 0;
		$this->save_state();
	}

	/**
	 * Get current state as array.
	 *
	 * @return array Current workspace state.
	 */
	public function get_state() {
		return array(
			'conversation_id'      => $this->conversation_id,
			'constraints'          => $this->constraints,
			'candidates'           => $this->candidates,
			'shortlist'            => $this->shortlist,
			'round_count'          => $this->round_count,
			'tool_calls_this_turn' => $this->tool_calls_this_turn,
			'max_rounds'           => $this->max_rounds,
			'max_tools_per_turn'   => $this->max_tools_per_turn,
			'rounds_remaining'     => $this->max_rounds - $this->round_count,
			'tools_remaining'      => $this->max_tools_per_turn - $this->tool_calls_this_turn,
		);
	}

	/**
	 * Get state formatted for system prompt injection.
	 *
	 * @return string Formatted workspace state.
	 */
	public function get_prompt_context() {
		$context_parts = array();

		// Focused products (for contextual follow-ups).
		if ( ! empty( $this->focused_product_ids ) ) {
			$focused_info = array();
			if ( function_exists( 'wc_get_product' ) ) {
				foreach ( array_slice( $this->focused_product_ids, 0, 3 ) as $pid ) {
					$product = wc_get_product( $pid );
					if ( $product ) {
						$focused_info[] = sprintf( '%s (ID: %d, type: %s)', $product->get_name(), $pid, $product->get_type() );
					}
				}
			}
			if ( ! empty( $focused_info ) ) {
				$context_parts[] = "CURRENTLY DISCUSSING: " . implode( '; ', $focused_info );
				$context_parts[] = "(Follow-up queries like 'Does it come in medium?' refer to these products)";
			}
		}

		// Constraints.
		if ( ! empty( $this->constraints ) ) {
			$constraint_strs = array();
			foreach ( $this->constraints as $key => $value ) {
				if ( is_array( $value ) ) {
					$constraint_strs[] = "{$key}: " . implode( ', ', $value );
				} else {
					$constraint_strs[] = "{$key}: {$value}";
				}
			}
			$context_parts[] = "User Constraints: " . implode( '; ', $constraint_strs );
		}

		// Candidates count.
		if ( ! empty( $this->candidates ) ) {
			$context_parts[] = "Found " . count( $this->candidates ) . " matching products";
		}

		// Shortlist.
		if ( ! empty( $this->shortlist ) ) {
			$context_parts[] = "Shortlist: " . implode( ', ', $this->shortlist );
		}

		// Budgets.
		$context_parts[] = sprintf(
			"Rounds: %d/%d remaining | Tools: %d/%d remaining this turn",
			$this->max_rounds - $this->round_count,
			$this->max_rounds,
			$this->max_tools_per_turn - $this->tool_calls_this_turn,
			$this->max_tools_per_turn
		);

		return implode( "\n", $context_parts );
	}

	/**
	 * Set a specific constraint.
	 *
	 * @param string $key   Constraint key.
	 * @param mixed  $value Constraint value.
	 */
	public function set_constraint( $key, $value ) {
		$this->constraints[ $key ] = $value;
		$this->save_state();
	}

	/**
	 * Get a specific constraint.
	 *
	 * @param string $key     Constraint key.
	 * @param mixed  $default Default value.
	 * @return mixed Constraint value or default.
	 */
	public function get_constraint( $key, $default = null ) {
		return $this->constraints[ $key ] ?? $default;
	}

	/**
	 * Get all constraints.
	 *
	 * @return array All constraints.
	 */
	public function get_constraints() {
		return $this->constraints;
	}

	/**
	 * Get candidates.
	 *
	 * @return array Product IDs.
	 */
	public function get_candidates() {
		return $this->candidates;
	}

	/**
	 * Get shortlist.
	 *
	 * @return array Product IDs.
	 */
	public function get_shortlist() {
		return $this->shortlist;
	}

	/**
	 * Clear workspace state.
	 *
	 * Note: Does NOT clear focused_product_ids as they persist across turns.
	 * Use clear_all() to completely reset including focus.
	 */
	public function clear() {
		$this->constraints          = array();
		$this->candidates           = array();
		$this->shortlist            = array();
		$this->tool_fingerprints    = array();
		$this->round_count          = 0;
		$this->tool_calls_this_turn = 0;
		// Intentionally preserve: focused_product_ids, last_tool_name

		$this->save_state();
	}

	/**
	 * Completely clear all workspace state including focus.
	 *
	 * Use this when starting a completely new topic.
	 */
	public function clear_all() {
		$this->constraints          = array();
		$this->candidates           = array();
		$this->shortlist            = array();
		$this->focused_product_ids  = array();
		$this->last_tool_name       = '';
		$this->tool_fingerprints    = array();
		$this->round_count          = 0;
		$this->tool_calls_this_turn = 0;

		delete_transient( $this->get_transient_key() );
	}

	/**
	 * Check if workspace has any gathered constraints.
	 *
	 * @return bool True if has constraints.
	 */
	public function has_constraints() {
		return ! empty( $this->constraints );
	}

	/**
	 * Check if workspace is ready for search.
	 *
	 * Ready when we have category + at least one other constraint.
	 *
	 * @return bool True if ready for search.
	 */
	public function is_ready_for_search() {
		if ( empty( $this->constraints ) ) {
			return false;
		}

		$has_category = isset( $this->constraints['category'] );
		$constraint_count = count( $this->constraints );

		// Ready if we have category + at least one other constraint.
		return $has_category && $constraint_count >= 2;
	}

	/**
	 * Get missing constraints that should be gathered.
	 *
	 * @return array List of missing constraint names.
	 */
	public function get_missing_constraints() {
		$recommended = array( 'category', 'price_range', 'use_case' );
		$missing = array();

		foreach ( $recommended as $constraint ) {
			if ( ! isset( $this->constraints[ $constraint ] ) ) {
				$missing[] = $constraint;
			}
		}

		return $missing;
	}

	/**
	 * Restore workspace state from session/transient.
	 *
	 * Note: This is called automatically in the constructor via load_state().
	 * This method exists for explicit restoration if needed.
	 */
	public function restore_from_session() {
		// State is already loaded in constructor via load_state().
		// This method can be used for explicit re-loading if needed.
		$this->load_state();
	}

	/**
	 * Get a summary of the current workspace state.
	 *
	 * @return array State summary.
	 */
	public function get_state_summary() {
		return array(
			'constraints_count'    => count( $this->constraints ),
			'constraints'          => array_keys( $this->constraints ),
			'candidates_count'     => count( $this->candidates ),
			'shortlist_count'      => count( $this->shortlist ),
			'round_count'          => $this->round_count,
			'tool_calls_this_turn' => $this->tool_calls_this_turn,
			'fingerprints_count'   => count( $this->tool_fingerprints ),
		);
	}

	/**
	 * Get current round count.
	 *
	 * @return int Round count.
	 */
	public function get_round_count() {
		return $this->round_count;
	}

	/**
	 * Get maximum rounds setting.
	 *
	 * @return int Max rounds.
	 */
	public function get_max_rounds() {
		return $this->max_rounds;
	}

	/**
	 * Get maximum tools per turn setting.
	 *
	 * @return int Max tools per turn.
	 */
	public function get_max_tools_per_turn() {
		return $this->max_tools_per_turn;
	}

	/**
	 * Get tool calls count for this turn.
	 *
	 * @return int Tool calls this turn.
	 */
	public function get_tool_calls_this_turn() {
		return $this->tool_calls_this_turn;
	}

	/**
	 * Increment tool calls counter.
	 */
	public function increment_tool_calls() {
		$this->tool_calls_this_turn++;
		$this->save_state();
	}

	/**
	 * Record a tool fingerprint.
	 *
	 * Alias for record_tool_call() for compatibility.
	 *
	 * @param string $fingerprint Tool call fingerprint.
	 */
	public function record_tool_fingerprint( $fingerprint ) {
		$this->record_tool_call( $fingerprint );
	}

	/**
	 * Persist workspace state to session/transient.
	 *
	 * Alias for save_state() for compatibility.
	 */
	public function persist_to_session() {
		$this->save_state();
	}

	/**
	 * Reset workspace for new conversation turn.
	 *
	 * Clears all state including constraints.
	 * Alias for clear() for compatibility.
	 */
	public function reset() {
		$this->clear();
	}

	/**
	 * Process a tool result and update workspace accordingly.
	 *
	 * Extracts product IDs from tool results and updates candidates.
	 * Also updates focused_product_ids for contextual follow-ups.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $result    Tool result.
	 */
	public function process_tool_result( $tool_name, $result ) {
		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return;
		}

		// Track the last tool called.
		$this->last_tool_name = $tool_name;

		// Extract product IDs from various tool results.
		$product_ids = array();

		switch ( $tool_name ) {
			case 'query_products':
			case 'product_lookup':
			case 'recommendations':
			case 'select_products':
				// These tools return products in data.
				$data = $result['data'] ?? array();

				// Handle nested products array.
				$products = isset( $data['products'] ) ? $data['products'] : $data;

				if ( is_array( $products ) ) {
					foreach ( $products as $product ) {
						if ( isset( $product['id'] ) ) {
							$product_ids[] = (int) $product['id'];
						} elseif ( isset( $product['product_id'] ) ) {
							$product_ids[] = (int) $product['product_id'];
						}
					}
				}
				break;

			case 'resolve_product':
				// Resolver returns candidates.
				if ( isset( $result['candidates'] ) && is_array( $result['candidates'] ) ) {
					foreach ( $result['candidates'] as $candidate ) {
						if ( isset( $candidate['product_id'] ) ) {
							$product_ids[] = (int) $candidate['product_id'];
						}
					}
				}
				break;
		}

		// Update candidates if we found products.
		if ( ! empty( $product_ids ) ) {
			// Merge with existing candidates (no duplicates).
			$this->candidates = array_unique( array_merge( $this->candidates, $product_ids ) );

			// Update focused products for contextual follow-ups.
			// Only tools that display products to users should update focus.
			$display_tools = array( 'query_products', 'select_products', 'recommendations' );
			if ( in_array( $tool_name, $display_tools, true ) ) {
				$this->set_focused_products( $product_ids );
			}

			$this->save_state();
		}
	}

	// =========================================================================
	// Focused Products (Contextual Follow-up Support)
	// =========================================================================

	/**
	 * Set the focused product IDs.
	 *
	 * These are the products currently being discussed that follow-up
	 * queries like "Does it come in medium?" should reference.
	 *
	 * @param array $product_ids Array of product IDs.
	 */
	public function set_focused_products( $product_ids ) {
		$this->focused_product_ids = array_map( 'absint', array_slice( (array) $product_ids, 0, 10 ) );
		$this->save_state();
	}

	/**
	 * Get the focused product IDs.
	 *
	 * @return array Product IDs currently in focus.
	 */
	public function get_focused_products() {
		return $this->focused_product_ids;
	}

	/**
	 * Get the primary focused product ID.
	 *
	 * Returns the first focused product, useful for single-product follow-ups.
	 *
	 * @return int|null Primary product ID or null if none.
	 */
	public function get_primary_focused_product() {
		return ! empty( $this->focused_product_ids ) ? $this->focused_product_ids[0] : null;
	}

	/**
	 * Check if there are focused products.
	 *
	 * @return bool True if products are in focus.
	 */
	public function has_focused_products() {
		return ! empty( $this->focused_product_ids );
	}

	/**
	 * Clear the focused products.
	 *
	 * Call this when the conversation topic changes.
	 */
	public function clear_focused_products() {
		$this->focused_product_ids = array();
		$this->save_state();
	}

	/**
	 * Get the last tool name that was executed.
	 *
	 * @return string Last tool name or empty string.
	 */
	public function get_last_tool_name() {
		return $this->last_tool_name;
	}

	/**
	 * Get the Focus Frame for LLM-based reference resolution.
	 *
	 * The Focus Frame provides typed entity tracking with Resolution Pack
	 * prompt generation for accurate pronoun resolution.
	 *
	 * @return Glimmr_AI_Focus_Frame Focus frame instance.
	 * @since 1.8.0
	 */
	public function get_focus_frame() {
		if ( ! $this->focus_frame ) {
			$this->focus_frame = new Glimmr_AI_Focus_Frame();
		}
		return $this->focus_frame;
	}

	/**
	 * Get a context snapshot for the system prompt.
	 *
	 * Returns a structured summary of the current conversation context
	 * that helps the LLM understand what products are being discussed.
	 *
	 * @return array Context snapshot with focused products and metadata.
	 */
	public function get_context_snapshot() {
		$snapshot = array(
			'has_focus'            => $this->has_focused_products(),
			'focused_product_ids'  => $this->focused_product_ids,
			'focused_count'        => count( $this->focused_product_ids ),
			'last_tool'            => $this->last_tool_name,
			'last_entity_type'     => $this->entity_focus['last_entity_type'] ?? null,
		);

		// If we have focused products, get basic info for context.
		if ( $this->has_focused_products() && function_exists( 'wc_get_product' ) ) {
			$focused_products = array();
			foreach ( array_slice( $this->focused_product_ids, 0, 3 ) as $pid ) {
				$product = wc_get_product( $pid );
				if ( $product ) {
					$focused_products[] = array(
						'id'    => $pid,
						'name'  => $product->get_name(),
						'type'  => $product->get_type(),
					);
				}
			}
			$snapshot['focused_products'] = $focused_products;
		}

		return $snapshot;
	}

	// =========================================================================
	// Entity Focus Stack (Multi-Entity Type Support)
	// =========================================================================

	/**
	 * Push an entity to the focus stack.
	 *
	 * Adds entity to front of stack, removes duplicates, maintains max size.
	 * Also updates last_entity_type for pronoun resolution.
	 *
	 * @param string $type Entity type: 'product', 'order', or 'cart_item'.
	 * @param mixed  $id   Entity identifier (product_id, order_id, or cart_item_key).
	 */
	public function push_focus( $type, $id ) {
		$key_map = array(
			'product'   => 'products',
			'order'     => 'orders',
			'cart_item' => 'cart_items',
		);

		$key = $key_map[ $type ] ?? null;
		if ( ! $key ) {
			return;
		}

		// Initialize if needed.
		if ( ! isset( $this->entity_focus[ $key ] ) ) {
			$this->entity_focus[ $key ] = array();
		}

		// Add to front, remove duplicates.
		array_unshift( $this->entity_focus[ $key ], $id );
		$this->entity_focus[ $key ] = array_unique( $this->entity_focus[ $key ] );

		// Limit size based on type.
		$max_size = 'products' === $key ? 5 : 3;
		$this->entity_focus[ $key ] = array_slice( $this->entity_focus[ $key ], 0, $max_size );

		// Update metadata.
		$this->entity_focus['last_entity_type'] = $type;
		$this->entity_focus['last_updated'] = time();

		// Also update legacy focused_product_ids for backwards compatibility.
		if ( 'product' === $type ) {
			$this->focused_product_ids = $this->entity_focus['products'];
		}

		$this->save_state();
	}

	/**
	 * Set focus for multiple entities at once.
	 *
	 * @param string $type Entity type: 'product', 'order', or 'cart_item'.
	 * @param array  $ids  Array of entity identifiers.
	 */
	public function set_focus( $type, $ids ) {
		$key_map = array(
			'product'   => 'products',
			'order'     => 'orders',
			'cart_item' => 'cart_items',
		);

		$key = $key_map[ $type ] ?? null;
		if ( ! $key ) {
			return;
		}

		// Limit size based on type.
		$max_size = 'products' === $key ? 5 : 3;
		$this->entity_focus[ $key ] = array_slice( (array) $ids, 0, $max_size );

		// Update metadata.
		$this->entity_focus['last_entity_type'] = $type;
		$this->entity_focus['last_updated'] = time();

		// Also update legacy focused_product_ids for backwards compatibility.
		if ( 'product' === $type ) {
			$this->focused_product_ids = $this->entity_focus['products'];
		}

		$this->save_state();
	}

	/**
	 * Resolve a pronoun reference based on context.
	 *
	 * Returns the most recent entity ID for the specified type,
	 * or uses last_entity_type if no type specified.
	 *
	 * @param string|null $type Entity type, or null to use last type.
	 * @return mixed Entity ID/key or null if unresolvable.
	 */
	public function resolve_reference( $type = null ) {
		// If no type specified, use the last entity type.
		if ( null === $type ) {
			$type = $this->entity_focus['last_entity_type'] ?? 'product';
		}

		$key_map = array(
			'product'   => 'products',
			'order'     => 'orders',
			'cart_item' => 'cart_items',
		);

		$key = $key_map[ $type ] ?? null;
		if ( ! $key ) {
			return null;
		}

		$stack = $this->entity_focus[ $key ] ?? array();
		return ! empty( $stack ) ? $stack[0] : null;
	}

	/**
	 * Get the focus stack for a specific entity type.
	 *
	 * @param string $type Entity type: 'product', 'order', or 'cart_item'.
	 * @return array Entity IDs/keys in focus (most recent first).
	 */
	public function get_focus_stack( $type ) {
		$key_map = array(
			'product'   => 'products',
			'order'     => 'orders',
			'cart_item' => 'cart_items',
		);

		$key = $key_map[ $type ] ?? null;
		if ( ! $key ) {
			return array();
		}

		return $this->entity_focus[ $key ] ?? array();
	}

	/**
	 * Check if an entity is in focus.
	 *
	 * @param string $type       Entity type.
	 * @param mixed  $identifier Entity ID/key to check.
	 * @return bool True if entity is in focus.
	 */
	public function is_in_focus( $type, $identifier ) {
		$stack = $this->get_focus_stack( $type );
		return in_array( $identifier, $stack, true );
	}

	/**
	 * Get the last entity type that was focused.
	 *
	 * @return string|null Entity type or null.
	 */
	public function get_last_entity_type() {
		return $this->entity_focus['last_entity_type'] ?? null;
	}

	/**
	 * Clear focus for a specific entity type.
	 *
	 * @param string $type Entity type to clear.
	 */
	public function clear_focus( $type ) {
		$key_map = array(
			'product'   => 'products',
			'order'     => 'orders',
			'cart_item' => 'cart_items',
		);

		$key = $key_map[ $type ] ?? null;
		if ( ! $key ) {
			return;
		}

		$this->entity_focus[ $key ] = array();

		// Also clear legacy focused_product_ids for backwards compatibility.
		if ( 'product' === $type ) {
			$this->focused_product_ids = array();
		}

		$this->save_state();
	}

	/**
	 * Clear all entity focus stacks.
	 */
	public function clear_all_focus() {
		$this->entity_focus = array(
			'products'         => array(),
			'orders'           => array(),
			'cart_items'       => array(),
			'last_entity_type' => null,
			'last_updated'     => null,
		);
		$this->focused_product_ids = array();
		$this->save_state();
	}
}
