<?php
/**
 * Controller Schema
 *
 * JSON schema for OpenAI Structured Outputs in slot-filling agent architecture.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Controller_Schema
 *
 * Provides the JSON schema for controller responses and validation.
 */
class Glimmr_AI_Controller_Schema {

	/**
	 * Get the JSON schema for controller responses.
	 *
	 * Used with OpenAI Structured Outputs (text.format.type = "json_schema").
	 *
	 * @return array JSON schema.
	 */
	public static function get_schema() {
		// NOTE: OpenAI Structured Outputs strict mode requirements:
		// - additionalProperties must be false for all objects
		// - oneOf/anyOf/allOf are NOT supported
		// - All properties must be listed in required array
		// - maxItems is NOT supported
		return array(
			'name'   => 'agent_controller',
			'strict' => true,
			'schema' => array(
				'type'       => 'object',
				'required'   => array( 'action', 'thought', 'workspace_updates', 'tool_call', 'user_message', 'resolved_references' ),
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'enum'        => array( 'clarify', 'tool', 'final' ),
						'description' => 'The action to take: clarify (ask user), tool (execute tool), or final (respond to user)',
					),
					'thought' => array(
						'type'        => 'string',
						'description' => 'One sentence decision summary (not shown to user). Max 500 chars.',
						'maxLength'   => 500,
					),
					'workspace_updates' => array(
						'type'       => 'object',
						'required'   => array( 'constraints', 'candidates', 'shortlist' ),
						'properties' => array(
							'constraints' => array(
								'type'        => 'string',
								'description' => 'JSON-encoded object of user requirements (category, max_price, size, color, etc.)',
							),
							'candidates' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Product IDs found during search',
							),
							'shortlist' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Narrowed selection of product IDs (max 5 recommended)',
							),
						),
						'additionalProperties' => false,
					),
					'tool_call' => array(
						'type'        => 'object',
						'description' => 'Tool to execute (required when action=tool)',
						'required'    => array( 'name', 'arguments_json', 'purpose' ),
						'properties'  => array(
							'name' => array(
								'type'        => 'string',
								'description' => 'Name of the tool to call',
							),
							'arguments_json' => array(
								'type'        => 'string',
								'description' => 'JSON-encoded arguments to pass to the tool',
							),
							'purpose' => array(
								'type'        => 'string',
								'description' => 'Brief description of why calling this tool',
							),
						),
						'additionalProperties' => false,
					),
					'user_message' => array(
						'type'        => 'string',
						'nullable'    => true,
						'description' => 'Message to show to the user (required when action=clarify or action=final, null when action=tool)',
					),
					'resolved_references' => array(
						'type'        => 'object',
						'nullable'    => true,
						'description' => 'Declare pronoun resolutions from Resolution Pack. Use when referring to entities by pronoun.',
						'properties'  => array(
							'it' => array(
								'type'        => 'object',
								'nullable'    => true,
								'description' => 'Resolution for singular pronouns: it, that, this product',
								'properties'  => array(
									'type'   => array(
										'type' => 'string',
										'enum' => array( 'product', 'order', 'cart_item' ),
										'description' => 'Entity type being referenced',
									),
									'id'     => array(
										'type' => 'integer',
										'description' => 'Entity ID from Resolution Pack',
									),
									'reason' => array(
										'type' => 'string',
										'description' => 'Brief reason for this resolution (e.g., primary_focus, last_mentioned)',
									),
								),
								'required' => array( 'type', 'id', 'reason' ),
								'additionalProperties' => false,
							),
							'these' => array(
								'type'        => 'object',
								'nullable'    => true,
								'description' => 'Resolution for plural pronouns: these, those, the products',
								'properties'  => array(
									'type' => array(
										'type' => 'string',
										'enum' => array( 'products', 'orders', 'cart_items' ),
										'description' => 'Entity type being referenced (plural)',
									),
									'ids'  => array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
										'description' => 'Entity IDs from Resolution Pack',
									),
									'reason' => array(
										'type' => 'string',
										'description' => 'Brief reason for this resolution',
									),
								),
								'required' => array( 'type', 'ids', 'reason' ),
								'additionalProperties' => false,
							),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Validate a controller response.
	 *
	 * @param array $response The parsed controller response.
	 * @return array Validation result with 'valid' boolean and optional 'errors' array.
	 */
	public static function validate( $response ) {
		$errors = array();

		// Check required fields.
		if ( ! isset( $response['action'] ) ) {
			$errors[] = 'Missing required field: action';
		}
		if ( ! isset( $response['thought'] ) ) {
			$errors[] = 'Missing required field: thought';
		}

		// Validate action enum.
		if ( isset( $response['action'] ) ) {
			$valid_actions = array( 'clarify', 'tool', 'final' );
			if ( ! in_array( $response['action'], $valid_actions, true ) ) {
				$errors[] = sprintf( 'Invalid action: %s. Must be one of: %s', $response['action'], implode( ', ', $valid_actions ) );
			}
		}

		// Action-specific validation.
		if ( isset( $response['action'] ) ) {
			switch ( $response['action'] ) {
				case 'tool':
					if ( ! isset( $response['tool_call'] ) ) {
						$errors[] = 'action=tool requires tool_call object';
					} elseif ( ! isset( $response['tool_call']['name'] ) ) {
						$errors[] = 'tool_call.name is required';
					}
					break;

				case 'clarify':
				case 'final':
					if ( ! isset( $response['user_message'] ) || empty( $response['user_message'] ) ) {
						$errors[] = sprintf( 'action=%s requires user_message', $response['action'] );
					}
					break;
			}
		}

		// Validate workspace_updates structure if present.
		if ( isset( $response['workspace_updates'] ) && is_array( $response['workspace_updates'] ) ) {
			$updates = $response['workspace_updates'];

			if ( isset( $updates['candidates'] ) && ! is_array( $updates['candidates'] ) ) {
				$errors[] = 'workspace_updates.candidates must be an array';
			}

			if ( isset( $updates['shortlist'] ) ) {
				if ( ! is_array( $updates['shortlist'] ) ) {
					$errors[] = 'workspace_updates.shortlist must be an array';
				} elseif ( count( $updates['shortlist'] ) > 5 ) {
					$errors[] = 'workspace_updates.shortlist cannot exceed 5 items';
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Parse a JSON response string into validated controller object.
	 *
	 * @param string $json_string The JSON response from OpenAI.
	 * @return array|WP_Error Parsed response or error.
	 */
	public static function parse( $json_string ) {
		$response = json_decode( $json_string, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'json_parse_error',
				sprintf( __( 'Failed to parse controller JSON: %s', 'glimmr-ai' ), json_last_error_msg() )
			);
		}

		$validation = self::validate( $response );

		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'validation_error',
				__( 'Controller response validation failed', 'glimmr-ai' ),
				array( 'errors' => $validation['errors'] )
			);
		}

		return $response;
	}

	/**
	 * Get instructions for the AI about using the controller schema.
	 *
	 * @return string Instructions text.
	 */
	public static function get_instructions() {
		return <<<'INSTRUCTIONS'
## Response Format

**CRITICAL RULES:**
1. Output exactly ONE JSON object per response. Never output multiple JSON objects.
2. **NEVER wrap your JSON in markdown code fences** (no \`\`\`json). Output raw JSON only.
3. All responses—including refusals—must be valid controller JSON (use action="final" for refusals).

You must respond with a single JSON object following the controller schema.

```json
{
  "action": "clarify" | "tool" | "final",
  "thought": "One sentence decision summary",
  "workspace_updates": {
    "constraints": "{\"category\": \"...\", \"size\": \"...\"}",
    "candidates": [product_ids...],
    "shortlist": [product_ids...]
  },
  "tool_call": {
    "name": "tool_name",
    "arguments_json": "{\"param\": \"value\"}",
    "purpose": "Brief description of why calling this tool"
  },
  "user_message": "Message shown to the user (or null when action=tool)",
  "resolved_references": {
    "it": {"type": "product", "id": 596, "reason": "primary_focus"},
    "these": null
  }
}
```

### Entity Reference Resolution (CRITICAL)

When the user uses pronouns or implicit references ("it", "that", "these", "the product"):
1. Check the Resolution Pack (injected as a system message) for available entities
2. ONLY use entity IDs from the Resolution Pack - NEVER invent or guess IDs
3. Declare your resolution in `resolved_references`:
   - `it` for singular: {"type": "product", "id": 596, "reason": "primary_focus"}
   - `these` for plural: {"type": "products", "ids": [596, 598], "reason": "product_list"}
4. If the reference is ambiguous, use action="clarify" and ask which entity they mean
5. If no entities are in the Resolution Pack, ask the user to search or specify

### Field Requirements

**Root-level fields are always required:**
- `action`: Required - "clarify", "tool", or "final"
- `thought`: Required - One sentence summary of your decision (max 500 chars, not shown to user)
- `workspace_updates`: Required object (uses PATCH semantics - see below)
- `tool_call`: Required - Object with `name`, `arguments_json`, `purpose` (use empty strings when not calling a tool)
- `user_message`: Required - String message or null when action=tool
- `resolved_references`: Required - Declare pronoun resolutions when using entity IDs (null if no pronouns resolved)

### Workspace Updates (PATCH Semantics)

The `workspace_updates` object uses **PATCH semantics**: only include fields you want to change.
- `constraints`: JSON-encoded string of user requirements. Merge new constraints with existing ones.
- `candidates`: Product IDs found during search. Replace with new search results, or carry forward previous values.
- `shortlist`: Narrowed selection of product IDs (max 5). Update when you've narrowed down, otherwise keep previous.

**PATCH Example:** If you only learned a new constraint (color=blue), update constraints but keep candidates/shortlist from previous state.

### Actions (Choose ONE per response)

- **clarify**: Ask the user a question. Requires `user_message`.
- **tool**: Execute a tool. Requires `tool_call` with `name`, `arguments_json`, and `purpose`.
- **final**: Provide final response to user. Requires `user_message`.

**IMPORTANT**: Output ONE action per response. See CRITICAL STOPPING RULES below for when you must stop.

### Slot-Filling Process (Action-First!)

1. **CAPTURE CONSTRAINTS**: Record any constraints the user already provided (category, max_price, size, color, use_case).
2. **ACTION-FIRST SEARCH**: If user intent is "browse/search/compare", call `query_products` IMMEDIATELY using Sensible Defaults (even if some constraints are missing).
3. **REFINE AFTER RESULTS**: After showing results, ask ONE refinement question if needed (size, budget, category).
4. **SHORTLIST**: When <10 candidates, select top 3-5 and present.

**Key Principle:** Search first, refine after. Only ask a question BEFORE searching when the request is genuinely ambiguous (e.g., "add that to my cart" with no product context).

### CRITICAL STOPPING RULES

1. **Stop-after-clarify**: When action="clarify", STOP and wait for user response
2. **Max 3 tools per turn**: After 3 tool calls, must output clarify or final
3. **Max 5 rounds**: After 5 rounds total, must output final
4. **No duplicate tool calls**: Skip if same tool+args already called

### Example Responses

**Gathering constraints:**
```json
{
  "action": "clarify",
  "thought": "User wants a product but hasn't specified category or budget",
  "workspace_updates": {
    "constraints": "{}",
    "candidates": [],
    "shortlist": []
  },
  "tool_call": {
    "name": "",
    "arguments_json": "{}",
    "purpose": ""
  },
  "user_message": "I'd be happy to help you find something! What type of product are you looking for?",
  "resolved_references": null
}
```

**Executing search:**
```json
{
  "action": "tool",
  "thought": "Have category=hoodies and max_price=100, ready to search",
  "workspace_updates": {
    "constraints": "{\"category\": \"hoodies\", \"max_price\": 100}",
    "candidates": [],
    "shortlist": []
  },
  "tool_call": {
    "name": "query_products",
    "arguments_json": "{\"mode\":\"search\",\"search\":{\"category\":\"hoodies\",\"max_price\":100,\"limit\":10}}",
    "purpose": "Search for hoodies under $100"
  },
  "user_message": null,
  "resolved_references": null
}
```

**Final response:**
```json
{
  "action": "final",
  "thought": "Found 3 matching hoodies, presenting recommendations",
  "workspace_updates": {
    "constraints": "{\"category\": \"hoodies\", \"max_price\": 100}",
    "candidates": [596, 598, 612],
    "shortlist": [596, 598, 612]
  },
  "tool_call": {
    "name": "",
    "arguments_json": "{}",
    "purpose": ""
  },
  "user_message": "Here are 3 hoodies under $100. The Alpine is highest rated. Add one to cart?",
  "resolved_references": null
}
```

**Adding "it" to cart (using Resolution Pack):**
User says "Add it to cart" after seeing products. Resolution Pack shows PRIMARY product ID: 596.
```json
{
  "action": "tool",
  "thought": "User said 'it' - resolving to primary_product 596 from Resolution Pack",
  "workspace_updates": {
    "constraints": "{}",
    "candidates": [],
    "shortlist": []
  },
  "tool_call": {
    "name": "add_to_cart",
    "arguments_json": "{\"product_id\":596,\"quantity\":1}",
    "purpose": "Add the primary focused product to cart"
  },
  "user_message": null,
  "resolved_references": {
    "it": {"type": "product", "id": 596, "reason": "primary_focus"},
    "these": null
  }
}
```
INSTRUCTIONS;
	}

	/**
	 * Get the OpenAI text format configuration for structured outputs.
	 *
	 * @return array OpenAI text format config.
	 */
	public static function get_openai_text_format() {
		return array(
			'type'        => 'json_schema',
			'json_schema' => self::get_schema(),
		);
	}

	/**
	 * Get the schema in OpenAI format for create_response_structured().
	 *
	 * Returns just the schema definition (name, strict, schema object).
	 * The create_response_structured() method will wrap this in text.format.
	 *
	 * @return array Schema definition with name, strict, and schema.
	 */
	public static function get_openai_format() {
		return self::get_schema();
	}

	/**
	 * Create a clarify action response.
	 *
	 * @param string $thought      Internal reasoning.
	 * @param string $user_message Message for user.
	 * @param array  $updates      Optional workspace updates.
	 * @return array Controller response.
	 */
	public static function create_clarify( $thought, $user_message, $updates = array() ) {
		return array(
			'action'            => 'clarify',
			'thought'           => $thought,
			'workspace_updates' => $updates,
			'user_message'      => $user_message,
		);
	}

	/**
	 * Create a clarify response with just a message.
	 *
	 * Simplified version for error recovery.
	 *
	 * @param string $user_message Message for user.
	 * @return array Controller response.
	 */
	public static function create_clarify_response( $user_message ) {
		return self::create_clarify( 'Error recovery - asking for clarification', $user_message );
	}

	/**
	 * Create a tool action response.
	 *
	 * @param string $thought   Internal reasoning.
	 * @param string $tool_name Tool to call.
	 * @param array  $arguments Tool arguments.
	 * @param array  $updates   Optional workspace updates.
	 * @return array Controller response.
	 */
	public static function create_tool( $thought, $tool_name, $arguments, $updates = array() ) {
		return array(
			'action'            => 'tool',
			'thought'           => $thought,
			'workspace_updates' => $updates,
			'tool_call'         => array(
				'name'      => $tool_name,
				'arguments' => $arguments,
			),
		);
	}

	/**
	 * Create a final action response.
	 *
	 * @param string $thought      Internal reasoning.
	 * @param string $user_message Message for user.
	 * @param array  $updates      Optional workspace updates.
	 * @return array Controller response.
	 */
	public static function create_final( $thought, $user_message, $updates = array() ) {
		return array(
			'action'            => 'final',
			'thought'           => $thought,
			'workspace_updates' => $updates,
			'user_message'      => $user_message,
		);
	}
}
