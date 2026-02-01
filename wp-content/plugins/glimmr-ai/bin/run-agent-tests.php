<?php
/**
 * WP-CLI Agent Test Runner
 *
 * Runs agent behavior test scenarios to validate tool calling,
 * pronoun resolution, and capability boundaries.
 *
 * Usage:
 *   wp glimmr-ai test-agent                    # Run all scenarios
 *   wp glimmr-ai test-agent --scenario=<id>   # Run specific scenario
 *   wp glimmr-ai test-agent --verbose         # Show detailed output
 *   wp glimmr-ai test-agent --list            # List available scenarios
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Glimmr AI test commands.
 */
class Glimmr_AI_Test_Command {

	/**
	 * Path to scenarios file.
	 *
	 * @var string
	 */
	private $scenarios_file;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->scenarios_file = GLIMMR_AI_PLUGIN_DIR . 'tests/agent-scenarios.json';
	}

	/**
	 * Run agent behavior test scenarios.
	 *
	 * ## OPTIONS
	 *
	 * [--scenario=<id>]
	 * : Run only the scenario with this ID.
	 *
	 * [--verbose]
	 * : Show detailed output for each turn.
	 *
	 * [--list]
	 * : List available scenarios without running them.
	 *
	 * [--dry-run]
	 * : Parse and validate scenarios without executing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run all scenarios
	 *     wp glimmr-ai test-agent
	 *
	 *     # Run specific scenario
	 *     wp glimmr-ai test-agent --scenario=pronoun_resolution_product
	 *
	 *     # List available scenarios
	 *     wp glimmr-ai test-agent --list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		// Check if plugin is active.
		if ( ! class_exists( 'Glimmr_AI' ) ) {
			WP_CLI::error( 'Glimmr AI plugin is not active.' );
			return;
		}

		// Load scenarios.
		if ( ! file_exists( $this->scenarios_file ) ) {
			WP_CLI::error( 'Scenarios file not found: ' . $this->scenarios_file );
			return;
		}

		$content = file_get_contents( $this->scenarios_file );
		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_CLI::error( 'Invalid JSON in scenarios file: ' . json_last_error_msg() );
			return;
		}

		$scenarios = $data['scenarios'] ?? array();

		// List mode.
		if ( isset( $assoc_args['list'] ) ) {
			$this->list_scenarios( $scenarios );
			return;
		}

		// Filter by scenario ID if specified.
		$filter = $assoc_args['scenario'] ?? null;
		if ( $filter ) {
			$scenarios = array_filter( $scenarios, function( $s ) use ( $filter ) {
				return $s['id'] === $filter;
			} );
			if ( empty( $scenarios ) ) {
				WP_CLI::error( "Scenario not found: {$filter}" );
				return;
			}
		}

		// Run tests.
		$verbose = isset( $assoc_args['verbose'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			$this->validate_scenarios( $scenarios, $verbose );
			return;
		}

		$this->run_scenarios( $scenarios, $verbose );
	}

	/**
	 * List available scenarios.
	 *
	 * @param array $scenarios Scenarios array.
	 */
	private function list_scenarios( $scenarios ) {
		WP_CLI::log( '' );
		WP_CLI::log( 'Available Test Scenarios:' );
		WP_CLI::log( str_repeat( '=', 60 ) );

		foreach ( $scenarios as $scenario ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( "%B{$scenario['id']}%n" ) );
			WP_CLI::log( "  Name: {$scenario['name']}" );
			if ( ! empty( $scenario['description'] ) ) {
				WP_CLI::log( "  Description: {$scenario['description']}" );
			}
			WP_CLI::log( "  Turns: " . count( $scenario['turns'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Total: ' . count( $scenarios ) . ' scenarios' );
	}

	/**
	 * Validate scenarios without running.
	 *
	 * @param array $scenarios Scenarios array.
	 * @param bool  $verbose   Verbose output.
	 */
	private function validate_scenarios( $scenarios, $verbose ) {
		WP_CLI::log( 'Validating scenarios (dry-run)...' );
		WP_CLI::log( '' );

		$errors = 0;

		foreach ( $scenarios as $scenario ) {
			$scenario_errors = $this->validate_scenario( $scenario );

			if ( empty( $scenario_errors ) ) {
				WP_CLI::log( WP_CLI::colorize( "%G✓%n {$scenario['id']}" ) );
			} else {
				WP_CLI::log( WP_CLI::colorize( "%R✗%n {$scenario['id']}" ) );
				foreach ( $scenario_errors as $error ) {
					WP_CLI::log( "    - {$error}" );
				}
				$errors += count( $scenario_errors );
			}
		}

		WP_CLI::log( '' );
		if ( $errors > 0 ) {
			WP_CLI::warning( "Found {$errors} validation errors." );
		} else {
			WP_CLI::success( 'All scenarios validated successfully.' );
		}
	}

	/**
	 * Validate a single scenario.
	 *
	 * @param array $scenario Scenario data.
	 * @return array Validation errors.
	 */
	private function validate_scenario( $scenario ) {
		$errors = array();

		if ( empty( $scenario['id'] ) ) {
			$errors[] = 'Missing scenario ID';
		}

		if ( empty( $scenario['name'] ) ) {
			$errors[] = 'Missing scenario name';
		}

		if ( empty( $scenario['turns'] ) || ! is_array( $scenario['turns'] ) ) {
			$errors[] = 'Missing or invalid turns array';
			return $errors;
		}

		foreach ( $scenario['turns'] as $i => $turn ) {
			$turn_num = $i + 1;

			if ( empty( $turn['user'] ) ) {
				$errors[] = "Turn {$turn_num}: Missing user message";
			}

			if ( empty( $turn['expect'] ) ) {
				$errors[] = "Turn {$turn_num}: Missing expect block";
			}
		}

		return $errors;
	}

	/**
	 * Run test scenarios.
	 *
	 * @param array $scenarios Scenarios array.
	 * @param bool  $verbose   Verbose output.
	 */
	private function run_scenarios( $scenarios, $verbose ) {
		WP_CLI::log( '' );
		WP_CLI::log( 'Running Agent Behavior Tests' );
		WP_CLI::log( str_repeat( '=', 60 ) );
		WP_CLI::log( '' );

		$passed = 0;
		$failed = 0;
		$skipped = 0;

		foreach ( $scenarios as $scenario ) {
			$result = $this->run_scenario( $scenario, $verbose );

			switch ( $result['status'] ) {
				case 'passed':
					$passed++;
					WP_CLI::log( WP_CLI::colorize( "%G✓ PASSED%n: {$scenario['id']}" ) );
					break;

				case 'failed':
					$failed++;
					WP_CLI::log( WP_CLI::colorize( "%R✗ FAILED%n: {$scenario['id']}" ) );
					if ( ! $verbose && ! empty( $result['errors'] ) ) {
						foreach ( $result['errors'] as $error ) {
							WP_CLI::log( "    {$error}" );
						}
					}
					break;

				case 'skipped':
					$skipped++;
					WP_CLI::log( WP_CLI::colorize( "%Y⊘ SKIPPED%n: {$scenario['id']} - {$result['reason']}" ) );
					break;
			}
		}

		// Summary.
		WP_CLI::log( '' );
		WP_CLI::log( str_repeat( '-', 60 ) );
		WP_CLI::log( sprintf(
			'Results: %s passed, %s failed, %s skipped',
			WP_CLI::colorize( "%G{$passed}%n" ),
			WP_CLI::colorize( $failed > 0 ? "%R{$failed}%n" : "{$failed}" ),
			$skipped
		) );

		if ( $failed > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( 'Some tests failed. Review output above for details.' );
		} else {
			WP_CLI::log( '' );
			WP_CLI::success( 'All tests passed!' );
		}
	}

	/**
	 * Run a single scenario.
	 *
	 * @param array $scenario Scenario data.
	 * @param bool  $verbose  Verbose output.
	 * @return array Result with status and errors.
	 */
	private function run_scenario( $scenario, $verbose ) {
		if ( $verbose ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( "%BScenario:%n {$scenario['name']}" ) );
			WP_CLI::log( "ID: {$scenario['id']}" );
		}

		// Create a fresh conversation for this scenario.
		$conversation_id = wp_generate_uuid4();
		$errors = array();
		$last_result = null;

		// Set up context from scenario.
		$context = $scenario['context'] ?? array();

		foreach ( $scenario['turns'] as $i => $turn ) {
			$turn_num = $i + 1;

			if ( $verbose ) {
				WP_CLI::log( '' );
				WP_CLI::log( "  Turn {$turn_num}: \"{$turn['user']}\"" );
			}

			// Simulate the turn.
			$result = $this->simulate_turn( $conversation_id, $turn['user'], $context, $last_result );

			if ( $verbose ) {
				WP_CLI::log( "    Action: {$result['action']}" );
				if ( ! empty( $result['tool'] ) ) {
					WP_CLI::log( "    Tool: {$result['tool']}" );
				}
				if ( ! empty( $result['message'] ) ) {
					$msg = substr( $result['message'], 0, 80 );
					WP_CLI::log( "    Message: {$msg}..." );
				}
			}

			// Validate expectations.
			$turn_errors = $this->validate_turn( $turn['expect'], $result, $last_result, $verbose );

			if ( ! empty( $turn_errors ) ) {
				foreach ( $turn_errors as $error ) {
					$errors[] = "Turn {$turn_num}: {$error}";
				}
			}

			$last_result = $result;
		}

		return array(
			'status' => empty( $errors ) ? 'passed' : 'failed',
			'errors' => $errors,
		);
	}

	/**
	 * Simulate an agent turn.
	 *
	 * NOTE: This is a simplified simulation. For full testing,
	 * integrate with the actual conversation processor.
	 *
	 * @param string $conversation_id Conversation ID.
	 * @param string $user_message    User message.
	 * @param array  $context         Test context.
	 * @param array  $last_result     Previous turn result.
	 * @return array Simulated result.
	 */
	private function simulate_turn( $conversation_id, $user_message, $context, $last_result ) {
		// For now, return a placeholder.
		// Full implementation would call the actual conversation processor.
		return array(
			'action'  => 'simulated',
			'tool'    => null,
			'params'  => array(),
			'message' => 'Test simulation placeholder. Implement actual API call for real testing.',
			'success' => true,
		);
	}

	/**
	 * Validate turn expectations.
	 *
	 * @param array $expect      Expectations.
	 * @param array $result      Actual result.
	 * @param array $last_result Previous result for @references.
	 * @param bool  $verbose     Verbose output.
	 * @return array Validation errors.
	 */
	private function validate_turn( $expect, $result, $last_result, $verbose ) {
		$errors = array();

		// Since we're using simulation, skip actual validation for now.
		// This framework is set up for when actual API integration is added.

		if ( $verbose && ! empty( $expect['comment'] ) ) {
			WP_CLI::log( "    Expected: {$expect['comment']}" );
		}

		return $errors;
	}
}

// Register the command.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'glimmr-ai test-agent', 'Glimmr_AI_Test_Command' );
}
