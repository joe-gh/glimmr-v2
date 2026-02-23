<?php
/**
 * SQL Readonly Tool - Constrained SQL Escape Hatch
 *
 * Provides a controlled way for the AI to execute read-only SQL queries
 * against a limited set of allowed tables. This tool is used for complex
 * product queries that cannot be expressed through the query_products tool,
 * such as "Does this product come in blue?" or "Which products have the
 * highest ratings in this category?"
 *
 * The user sends natural language queries to the AI, which then decides
 * whether to use this tool. Users never directly control the SQL - security
 * is enforced server-side through table allowlists and keyword blocking.
 *
 * Security constraints (enforced server-side):
 * - READ-ONLY: Only SELECT statements allowed
 * - ALLOWLISTED TABLES: Only product/category related tables (posts, postmeta, terms, etc.)
 * - BLOCKED TABLES: User data, orders, customers, options, secrets
 * - LIMIT ENFORCED: Max 50 rows returned
 * - BLOCKED KEYWORDS: UNION, INTO OUTFILE, LOAD_FILE, multi-statement, etc.
 * - TIMEOUT ENFORCED: Max 2 second query execution (v1.7.0)
 *
 * @package Glimmr_AI
 * @subpackage Tools
 * @since 1.1.0
 * @updated 1.7.0 Added query timeout enforcement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_SQL_Readonly
 *
 * Security-hardened SQL tool for complex product queries. The AI uses this
 * tool to answer questions like "Does the CloudSoft Hoodie come in medium?"
 * or "Which jackets have the best ratings?" that require joining postmeta
 * tables or complex filtering.
 *
 * All security is enforced server-side - the AI generates SQL, but table
 * allowlists, keyword blocking, row limits, and query timeouts ensure
 * only safe read-only operations are executed.
 */
class Glimmr_AI_Tool_SQL_Readonly extends Glimmr_AI_Tool_Base {

	/**
	 * Maximum number of rows to return.
	 */
	const MAX_ROWS = 50;

	/**
	 * Query timeout in seconds (WordPress doesn't support ms timeouts).
	 */
	const QUERY_TIMEOUT = 2;

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'sql_readonly';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Execute read-only SQL for complex product queries requiring joins that query_products cannot express (e.g., multi-attribute variation checks, cross-table meta queries). Only SELECT allowed, product-related tables only, max 50 rows.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'query' => array(
			'type'        => 'string',
			'description' => 'SQL SELECT query to execute. Must be read-only, targeting allowed tables only.',
			'required'    => true,
		),
	);

	/**
	 * Allowed table names (without prefix).
	 * These are the only tables the AI can query.
	 *
	 * @var array
	 */
	private $allowed_tables = array(
		'glimmr_ai_product_index',
		'glimmr_ai_product_variations',
		'wc_product_meta_lookup',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'posts',
		'postmeta',
	);

	/**
	 * Blocked table patterns.
	 * Queries referencing these patterns will be rejected.
	 *
	 * @var array
	 */
	private $blocked_table_patterns = array(
		'users',
		'usermeta',
		'options',
		'information_schema',
		'mysql',
		'performance_schema',
		'sys',
		'comments',
		'commentmeta',
		'links',
		'sessions',
		'tokens',
		'password',
		'secret',
		'api_key',
		'wc_customer',
		'wc_order',
		'woocommerce_order',
		'woocommerce_payment',
	);

	/**
	 * Blocked SQL keywords.
	 * Queries containing these will be rejected.
	 *
	 * @var array
	 */
	private $blocked_keywords = array(
		'UNION',
		'INTO OUTFILE',
		'INTO DUMPFILE',
		'LOAD_FILE',
		'LOAD DATA',
		'INSERT',
		'UPDATE',
		'DELETE',
		'DROP',
		'CREATE',
		'ALTER',
		'TRUNCATE',
		'REPLACE',
		'GRANT',
		'REVOKE',
		'CALL',
		'EXEC',
		'EXECUTE',
		'SET',
		'SHOW',
		'DESCRIBE',
		'EXPLAIN',
		'HANDLER',
		'BENCHMARK',
		'SLEEP',
		'WAITFOR',
		'DELAY',
		'PG_SLEEP',
		'VERSION()',
		'DATABASE()',
		'USER()',
		'CURRENT_USER',
		'SESSION_USER',
		'SYSTEM_USER',
	);

	/**
	 * Execute the SQL query.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Tool result.
	 */
	public function execute( $arguments ) {
		// Require WooCommerce.
		$wc_check = $this->require_wc();
		if ( $wc_check ) {
			return $wc_check;
		}

		$query = $this->get_string_arg( $arguments, 'query', '' );

		if ( empty( $query ) ) {
			return $this->format_error( 'Query is required.' );
		}

		// Normalize query for validation.
		$normalized_query = $this->normalize_query( $query );

		// Security validations.
		$validation_error = $this->validate_query( $normalized_query );
		if ( is_wp_error( $validation_error ) ) {
			return $this->format_error( $validation_error->get_error_message() );
		}

		// Ensure LIMIT clause.
		$limited_query = $this->ensure_limit( $normalized_query );

		// Replace table placeholders with actual prefixed table names.
		$prefixed_query = $this->apply_table_prefix( $limited_query );

		// Execute the query.
		$result = $this->execute_query( $prefixed_query );

		if ( is_wp_error( $result ) ) {
			return $this->format_error( $result->get_error_message() );
		}

		return $this->format_result(
			array(
				'success'      => true,
				'row_count'    => count( $result ),
				'rows'         => $result,
				'query_info'   => array(
					'original_query' => $query,
					'limit_applied'  => self::MAX_ROWS,
				),
			)
		);
	}

	/**
	 * Normalize query for validation.
	 *
	 * @param string $query Raw query.
	 * @return string Normalized query.
	 */
	private function normalize_query( $query ) {
		// Remove extra whitespace.
		$normalized = preg_replace( '/\s+/', ' ', $query );
		// preg_replace returns null on error.
		$query = ( null !== $normalized ) ? $normalized : $query;

		// Trim.
		$query = trim( $query );

		// Remove trailing semicolon.
		$query = rtrim( $query, ';' );

		return $query;
	}

	/**
	 * Validate query against security constraints.
	 *
	 * @param string $query Normalized query.
	 * @return true|WP_Error True if valid, WP_Error if invalid.
	 */
	private function validate_query( $query ) {
		// Must start with SELECT.
		if ( ! preg_match( '/^\s*SELECT\s/i', $query ) ) {
			return new WP_Error( 'not_select', 'Only SELECT queries are allowed. This query does not start with SELECT.' );
		}

		// Check for blocked keywords using word boundaries to avoid false positives
		// (e.g., OFFSET contains SET, but should not be blocked).
		foreach ( $this->blocked_keywords as $keyword ) {
			if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $query ) ) {
				return new WP_Error(
					'blocked_keyword',
					sprintf( 'Query contains blocked keyword: %s', $keyword )
				);
			}
		}

		// Check for multiple statements (semicolon in middle).
		if ( preg_match( '/;[^\'\"]*\S/', $query ) ) {
			return new WP_Error( 'multi_statement', 'Multiple statements (semicolons) are not allowed.' );
		}

		// Block subqueries to prevent access to sensitive meta via nested SELECTs.
		if ( preg_match( '/\(\s*SELECT\b/i', $query ) ) {
			return new WP_Error( 'subquery_blocked', 'Subqueries are not allowed.' );
		}

		// Check for blocked table patterns.
		$query_lower = strtolower( $query );
		foreach ( $this->blocked_table_patterns as $pattern ) {
			if ( preg_match( '/\b' . preg_quote( $pattern, '/' ) . '\b/i', $query_lower ) ) {
				return new WP_Error(
					'blocked_table',
					sprintf( 'Query references blocked table pattern: %s', $pattern )
				);
			}
		}

		// Validate that query references at least one allowed table.
		$found_allowed_table = false;
		foreach ( $this->allowed_tables as $table ) {
			if ( preg_match( '/\b' . preg_quote( $table, '/' ) . '\b/i', $query ) ) {
				$found_allowed_table = true;
				break;
			}
		}

		if ( ! $found_allowed_table ) {
			return new WP_Error(
				'no_allowed_tables',
				sprintf(
					'Query must reference at least one allowed table: %s',
					implode( ', ', $this->allowed_tables )
				)
			);
		}

		// Check for common SQL injection patterns.
		if ( preg_match( '/--\s*$/m', $query ) || preg_match( '/\/\*/', $query ) ) {
			return new WP_Error( 'sql_comment', 'SQL comments (-- or /*) are not allowed.' );
		}

		// Check for hex encoding that might bypass keyword checks.
		if ( preg_match( '/0x[0-9a-f]+/i', $query ) ) {
			return new WP_Error( 'hex_encoding', 'Hexadecimal encoding is not allowed.' );
		}

		// Block MySQL system variable access (@@version, @@datadir, etc.).
		if ( preg_match( '/@@/', $query ) ) {
			return new WP_Error( 'system_variable', 'System variable access (@@) is not allowed.' );
		}

		// Check for char() function that might bypass keyword checks.
		if ( preg_match( '/\bCHAR\s*\(/i', $query ) ) {
			return new WP_Error( 'char_function', 'CHAR() function is not allowed.' );
		}

		return true;
	}

	/**
	 * Ensure query has a LIMIT clause, add one if missing.
	 *
	 * @param string $query Query to check.
	 * @return string Query with LIMIT ensured.
	 */
	private function ensure_limit( $query ) {
		// Check if LIMIT already exists.
		if ( preg_match( '/\bLIMIT\s+(\d+)/i', $query, $matches ) ) {
			$existing_limit = intval( $matches[1] );

			// If existing limit is higher than max, replace it.
			if ( $existing_limit > self::MAX_ROWS ) {
				$replaced = preg_replace(
					'/\bLIMIT\s+\d+/i',
					'LIMIT ' . self::MAX_ROWS,
					$query
				);
				// preg_replace returns null on error.
				$query = ( null !== $replaced ) ? $replaced : $query;
			}

			return $query;
		}

		// No LIMIT found, append one.
		return $query . ' LIMIT ' . self::MAX_ROWS;
	}

	/**
	 * Apply WordPress table prefix to table names.
	 *
	 * @param string $query Query with unprefixed table names.
	 * @return string Query with prefixed table names.
	 */
	private function apply_table_prefix( $query ) {
		global $wpdb;

		// Replace allowed table names with prefixed versions.
		foreach ( $this->allowed_tables as $table ) {
			// Handle wp_ prefixed tables differently.
			if ( strpos( $table, 'glimmr_ai_' ) === 0 ) {
				// Custom plugin tables - use full prefix.
				$prefixed = $wpdb->prefix . $table;
			} else {
				// WordPress core tables - use wpdb property if available.
				if ( isset( $wpdb->$table ) ) {
					$prefixed = $wpdb->$table;
				} else {
					$prefixed = $wpdb->prefix . $table;
				}
			}

			// Replace table name (word boundary match).
			$replaced = preg_replace(
				'/\b' . preg_quote( $table, '/' ) . '\b/i',
				$prefixed,
				$query
			);
			// preg_replace returns null on error.
			if ( null !== $replaced ) {
				$query = $replaced;
			}
		}

		return $query;
	}

	/**
	 * Execute the query safely with timeout enforcement.
	 *
	 * @param string $query Prepared query.
	 * @return array|WP_Error Query results or error.
	 */
	private function execute_query( $query ) {
		global $wpdb;

		// Log the query for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Glimmr_AI_Logger::debug(
				'sql_readonly executing query',
				array(
					'query' => $query,
				),
				'tools'
			);
		}

		// Suppress errors temporarily to handle them gracefully.
		$suppress = $wpdb->suppress_errors();

		// Set query timeout (in milliseconds) to prevent long-running queries.
		// This uses MySQL's max_execution_time optimizer hint for SELECT queries.
		// Note: This requires MySQL 5.7.8+ or MariaDB 10.1.1+.
		$timeout_ms = self::QUERY_TIMEOUT * 1000;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $timeout_ms ) );

		// Execute query.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query validated above.
		$results = $wpdb->get_results( $query, ARRAY_A );

		// Reset timeout to default (0 = no limit) for subsequent queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET SESSION max_execution_time = 0' );

		// Restore error handling.
		$wpdb->suppress_errors( $suppress );

		// Check for errors.
		if ( $wpdb->last_error ) {
			Glimmr_AI_Logger::warning(
				'sql_readonly query error',
				array(
					'error' => $wpdb->last_error,
					'query' => $query,
				),
				'tools'
			);

			return new WP_Error(
				'query_error',
				'Query execution failed. Please check the query syntax.'
			);
		}

		if ( null === $results ) {
			return array();
		}

		// Sanitize results to remove any sensitive data that might have leaked.
		$results = $this->sanitize_results( $results );

		return $results;
	}

	/**
	 * Blocked meta key patterns for postmeta PII redaction.
	 *
	 * When a postmeta row has a meta_key matching any of these patterns,
	 * the meta_value is redacted. Uses fnmatch-style wildcards.
	 *
	 * @var array
	 */
	private $blocked_meta_keys = array(
		'_billing_*',
		'_shipping_*',
		'_payment_*',
		'_customer_ip_address',
		'_customer_user',
		'_customer_user_agent',
		'_transaction_id',
	);

	/**
	 * Sanitize query results to remove sensitive data.
	 *
	 * @param array $results Query results.
	 * @return array Sanitized results.
	 */
	private function sanitize_results( $results ) {
		if ( empty( $results ) ) {
			return $results;
		}

		$sensitive_columns = array(
			'user_pass',
			'user_email',
			'password',
			'secret',
			'api_key',
			'private_key',
			'token',
			'credit_card',
			'billing_email',
			'billing_phone',
			'shipping_email',
			'shipping_phone',
		);

		foreach ( $results as &$row ) {
			// Redact postmeta rows with sensitive meta_key values.
			if ( isset( $row['meta_key'] ) ) {
				$meta_key_lower = strtolower( $row['meta_key'] );
				foreach ( $this->blocked_meta_keys as $pattern ) {
					if ( fnmatch( $pattern, $meta_key_lower ) ) {
						if ( isset( $row['meta_value'] ) ) {
							$row['meta_value'] = '[REDACTED]';
						}
						break;
					}
				}
			}

			foreach ( $row as $key => $value ) {
				// Check if column name contains sensitive words.
				$key_lower = strtolower( $key );
				foreach ( $sensitive_columns as $sensitive ) {
					if ( false !== strpos( $key_lower, $sensitive ) ) {
						$row[ $key ] = '[REDACTED]';
						break;
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Get tool definition for OpenAI.
	 *
	 * @return array Tool definition.
	 */
	public function get_definition() {
		$allowed_tables_str = implode( ', ', $this->allowed_tables );

		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $this->name,
				'description' => sprintf(
					'%s Allowed tables: %s',
					$this->description,
					$allowed_tables_str
				),
				'strict'      => true,
				'parameters'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'query' ),
					'properties'           => array(
						'query' => array(
							'type'        => 'string',
							'description' => sprintf(
								'SQL SELECT query. Allowed tables: %s. Max %d rows returned. Example: SELECT * FROM glimmr_ai_product_index WHERE average_rating > 4 ORDER BY average_rating DESC LIMIT 5',
								$allowed_tables_str,
								self::MAX_ROWS
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get help text for this tool.
	 *
	 * @return string Help text.
	 */
	public function get_help() {
		return sprintf(
			"Execute read-only SQL queries against product tables.\n\n" .
			"Allowed tables:\n- %s\n\n" .
			"Example queries:\n" .
			"- Top rated products: SELECT * FROM glimmr_ai_product_index WHERE average_rating > 4 ORDER BY average_rating DESC LIMIT 5\n" .
			"- Products with many reviews: SELECT * FROM glimmr_ai_product_index WHERE review_count > 10 ORDER BY review_count DESC\n" .
			"- Products in price range: SELECT * FROM glimmr_ai_product_index WHERE price BETWEEN 20 AND 50\n\n" .
			"Security restrictions:\n" .
			"- Only SELECT queries allowed\n" .
			"- Maximum %d rows returned\n" .
			"- Blocked: UNION, subqueries, system tables",
			implode( "\n- ", $this->allowed_tables ),
			self::MAX_ROWS
		);
	}
}
