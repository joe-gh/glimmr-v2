<?php
/**
 * WP-CLI Commands for Glimmr AI
 *
 * Provides command-line interface for testing and debugging
 * the AI chat functionality.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_CLI
 *
 * WP-CLI commands for Glimmr AI plugin.
 *
 * ## EXAMPLES
 *
 *     # Send a test chat message
 *     wp glimmr-ai chat "Show me subscription products"
 *
 *     # Test intent classification only
 *     wp glimmr-ai classify "Show me subscription products"
 *
 *     # Check plugin status
 *     wp glimmr-ai status
 *
 *     # Dump the full system prompt to a file
 *     wp glimmr-ai dump-prompt
 *
 *     # Add test reviews to a product
 *     wp glimmr-ai add-reviews HOODIE-001 --count=15 --average=4.2
 */
class Glimmr_AI_CLI {

    /**
     * Register all CLI commands.
     */
    public static function register_commands() {
        WP_CLI::add_command( 'glimmr-ai chat', array( __CLASS__, 'chat' ) );
        WP_CLI::add_command( 'glimmr-ai classify', array( __CLASS__, 'classify' ) );
        WP_CLI::add_command( 'glimmr-ai status', array( __CLASS__, 'status' ) );
        WP_CLI::add_command( 'glimmr-ai dump-prompt', array( __CLASS__, 'dump_prompt' ) );
        WP_CLI::add_command( 'glimmr-ai add-reviews', array( __CLASS__, 'add_reviews' ) );
    }

    /**
     * Send a test chat message and display the full response pipeline.
     *
     * ## OPTIONS
     *
     * <message>
     * : The message to send to the AI assistant.
     *
     * [--user=<user_id>]
     * : User ID to simulate (for logged-in user context). Default: 0 (guest).
     *
     * [--conversation=<id>]
     * : Existing conversation ID to continue. Default: creates new.
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp glimmr-ai chat "Show me subscription products"
     *     wp glimmr-ai chat "What's in my cart?" --user=1
     *     wp glimmr-ai chat "Compare product A and B" --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function chat( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide a message to send.' );
            return;
        }

        $message = $args[0];
        $user_id = isset( $assoc_args['user'] ) ? (int) $assoc_args['user'] : 0;
        $format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%B=== Glimmr AI Chat Test ===%n' ) );
        WP_CLI::log( WP_CLI::colorize( '%CMessage:%n ' . $message ) );
        WP_CLI::log( WP_CLI::colorize( '%CUser ID:%n ' . ( $user_id ?: 'Guest' ) ) );
        WP_CLI::log( '' );

        // Get plugin instance and services.
        $plugin = Glimmr_AI::get_instance();

        $settings = new Glimmr_AI_Settings();
        $database = new Glimmr_AI_Database();
        $openai   = new Glimmr_AI_OpenAI( $settings );

        // Check if API is configured.
        if ( ! $openai->is_configured() ) {
            WP_CLI::error( 'OpenAI API key not configured. Please set it in Glimmr AI settings.' );
            return;
        }

        // Create conversation manager.
        $conversation = new Glimmr_AI_Conversation( $database, $settings, $openai );

        // Create or get conversation.
        $conversation_id = isset( $assoc_args['conversation'] )
            ? $assoc_args['conversation']
            : null;

        if ( ! $conversation_id ) {
            $conv_data = $conversation->create( $user_id, null, array( 'source' => 'cli' ) );
            if ( is_wp_error( $conv_data ) ) {
                WP_CLI::error( 'Failed to create conversation: ' . $conv_data->get_error_message() );
                return;
            }
            $conversation_id = $conv_data['conversation_id'];
            WP_CLI::log( WP_CLI::colorize( '%GCreated conversation:%n ' . $conversation_id ) );
        } else {
            WP_CLI::log( WP_CLI::colorize( '%GUsing conversation:%n ' . $conversation_id ) );
        }

        WP_CLI::log( '' );

        // Build context.
        $context = array(
            'page_url'    => 'cli://glimmr-ai/test',
            'page_title'  => 'CLI Test',
            'is_cli'      => true,
        );

        // Set current user if specified.
        if ( $user_id > 0 ) {
            wp_set_current_user( $user_id );
        }

        // Process the message.
        WP_CLI::log( WP_CLI::colorize( '%B=== Processing Message ===%n' ) );
        WP_CLI::log( 'Sending to AI...' );
        WP_CLI::log( '' );

        $start_time = microtime( true );
        $response = $conversation->process_message( $conversation_id, $message, $context );
        $elapsed = round( ( microtime( true ) - $start_time ) * 1000 );

        if ( is_wp_error( $response ) ) {
            WP_CLI::error( 'Error: ' . $response->get_error_message() );
            return;
        }

        // Display results.
        WP_CLI::log( WP_CLI::colorize( '%B=== Response (in ' . $elapsed . 'ms) ===%n' ) );
        WP_CLI::log( '' );

        $content = $response['content'] ?? '';
        $artifacts = $response['artifacts'] ?? array();

        WP_CLI::log( WP_CLI::colorize( '%GContent:%n' ) );
        WP_CLI::log( $content );
        WP_CLI::log( '' );

        if ( ! empty( $artifacts ) ) {
            WP_CLI::log( WP_CLI::colorize( '%B=== Artifacts ===%n' ) );
            foreach ( $artifacts as $i => $artifact ) {
                $type = $artifact['type'] ?? 'unknown';
                $data = $artifact['data'] ?? array();

                WP_CLI::log( WP_CLI::colorize( '%Y[' . ( $i + 1 ) . '] Type:%n ' . $type ) );

                // Show artifact data summary.
                if ( $type === 'product_lookup' && isset( $data['products'] ) ) {
                    $count = count( $data['products'] );
                    WP_CLI::log( '    Products found: ' . $count );
                    foreach ( $data['products'] as $product ) {
                        $name = $product['name'] ?? 'Unknown';
                        $price = $product['price'] ?? 'N/A';
                        WP_CLI::log( '    - ' . $name . ' (' . $price . ')' );
                    }
                } else {
                    WP_CLI::log( '    Data: ' . wp_json_encode( $data, JSON_PRETTY_PRINT ) );
                }
                WP_CLI::log( '' );
            }
        }

        WP_CLI::log( '' );
        WP_CLI::success( 'Chat test complete. Conversation ID: ' . $conversation_id );
    }

    /**
     * Test intent classification for a message.
     *
     * ## OPTIONS
     *
     * <message>
     * : The message to classify.
     *
     * [--context=<json>]
     * : JSON array of previous messages for context.
     *
     * ## EXAMPLES
     *
     *     wp glimmr-ai classify "Show me subscription products"
     *     wp glimmr-ai classify "those ones" --context='[{"role":"user","content":"What products do you have?"}]'
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function classify( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide a message to classify.' );
            return;
        }

        $message = $args[0];
        $context_json = isset( $assoc_args['context'] ) ? $assoc_args['context'] : '[]';
        $context_messages = json_decode( $context_json, true ) ?: array();

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%B=== Intent Classification Test ===%n' ) );
        WP_CLI::log( WP_CLI::colorize( '%CMessage:%n ' . $message ) );

        if ( ! empty( $context_messages ) ) {
            WP_CLI::log( WP_CLI::colorize( '%CContext:%n ' . count( $context_messages ) . ' previous messages' ) );
        }
        WP_CLI::log( '' );

        // Get services.
        $settings = new Glimmr_AI_Settings();
        $database = new Glimmr_AI_Database();
        $openai   = new Glimmr_AI_OpenAI( $settings );

        if ( ! $openai->is_configured() ) {
            WP_CLI::error( 'OpenAI API key not configured.' );
            return;
        }

        // Create conversation manager to access classifier.
        $conversation = new Glimmr_AI_Conversation( $database, $settings, $openai );

        // Build API messages format (add current message to context).
        $api_messages = $context_messages;
        $api_messages[] = array(
            'role'    => 'user',
            'content' => $message,
        );

        // Call the classifier using reflection (it's a private method).
        $start_time = microtime( true );

        try {
            $reflection = new ReflectionMethod( $conversation, 'classify_intent_with_llm' );
            $reflection->setAccessible( true );
            $result = $reflection->invoke( $conversation, $message, $api_messages );
        } catch ( Exception $e ) {
            WP_CLI::error( 'Failed to run classifier: ' . $e->getMessage() );
            return;
        }

        $elapsed = round( ( microtime( true ) - $start_time ) * 1000 );

        // Display results.
        WP_CLI::log( WP_CLI::colorize( '%B=== Classification Result (in ' . $elapsed . 'ms) ===%n' ) );
        WP_CLI::log( '' );

        $intent = $result['intent'] ?? 'unknown';
        $confidence = $result['confidence'] ?? 0;
        $use_file_search = $result['use_file_search'] ?? true;

        // Color-code intent.
        $intent_color = '%G'; // Green default.
        if ( $intent === 'product' || $intent === 'cart' || $intent === 'order' ) {
            $intent_color = '%Y'; // Yellow for function-tool intents.
        } elseif ( $intent === 'policy' ) {
            $intent_color = '%C'; // Cyan for file_search intents.
        }

        WP_CLI::log( WP_CLI::colorize( 'Intent:       ' . $intent_color . $intent . '%n' ) );
        WP_CLI::log( WP_CLI::colorize( 'Confidence:   %W' . number_format( $confidence * 100, 1 ) . '%%n' ) );
        WP_CLI::log( WP_CLI::colorize( 'file_search:  ' . ( $use_file_search ? '%GENABLED%n' : '%RDISABLED%n' ) ) );
        WP_CLI::log( '' );

        // Explain what this means.
        if ( ! $use_file_search ) {
            WP_CLI::log( WP_CLI::colorize( '%YNote:%n file_search is disabled - AI will use function tools only.' ) );
            WP_CLI::log( '      This means product_lookup, stock_check, etc. will be used.' );
        } else {
            WP_CLI::log( WP_CLI::colorize( '%CNote:%n file_search is enabled - AI may use vector store.' ) );
            WP_CLI::log( '      This is appropriate for policy/FAQ questions.' );
        }

        WP_CLI::log( '' );
        WP_CLI::success( 'Classification complete.' );
    }

    /**
     * Display Glimmr AI plugin status and configuration.
     *
     * ## EXAMPLES
     *
     *     wp glimmr-ai status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function status( $args, $assoc_args ) {
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%B=== Glimmr AI Status ===%n' ) );
        WP_CLI::log( '' );

        $settings = new Glimmr_AI_Settings();
        $openai   = new Glimmr_AI_OpenAI( $settings );

        // API Configuration.
        WP_CLI::log( WP_CLI::colorize( '%GOpenAI Configuration:%n' ) );

        $api_configured = $openai->is_configured();
        WP_CLI::log( '  API Key:        ' . ( $api_configured ? WP_CLI::colorize( '%GConfigured%n' ) : WP_CLI::colorize( '%RNot configured%n' ) ) );

        $model = $settings->get( 'openai_model', 'gpt-4o' );
        WP_CLI::log( '  Model:          ' . $model );

        $vector_store = $settings->get( 'openai_vector_store_id' );
        WP_CLI::log( '  Vector Store:   ' . ( $vector_store ? $vector_store : WP_CLI::colorize( '%YNot configured%n' ) ) );

        WP_CLI::log( '' );

        // Database.
        WP_CLI::log( WP_CLI::colorize( '%GDatabase:%n' ) );

        global $wpdb;

        $conv_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_ai_conversations" );
        WP_CLI::log( '  Conversations:  ' . ( $conv_count ?: '0' ) );

        $msg_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_ai_messages" );
        WP_CLI::log( '  Messages:       ' . ( $msg_count ?: '0' ) );

        $product_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}glimmr_ai_product_index" );
        WP_CLI::log( '  Product Index:  ' . ( $product_count ?: '0' ) . ' products' );

        WP_CLI::log( '' );

        // Tools.
        WP_CLI::log( WP_CLI::colorize( '%GEnabled Tools:%n' ) );

        $plugin = Glimmr_AI::get_instance();
        $tool_registry = $plugin->get_tool_registry();
        $tools = $tool_registry->get_definitions( true );

        foreach ( $tools as $tool ) {
            $name = $tool['function']['name'] ?? $tool['name'] ?? 'unknown';
            WP_CLI::log( '  - ' . $name );
        }

        WP_CLI::log( '' );

        // Widget.
        WP_CLI::log( WP_CLI::colorize( '%GWidget Settings:%n' ) );
        WP_CLI::log( '  Enabled:        ' . ( $settings->get( 'widget_enabled', true ) ? 'Yes' : 'No' ) );
        WP_CLI::log( '  Position:       ' . $settings->get( 'widget_position', 'bottom-right' ) );
        WP_CLI::log( '  Greeting:       ' . substr( $settings->get( 'greeting_message', 'Hi! How can I help?' ), 0, 50 ) . '...' );

        WP_CLI::log( '' );
        WP_CLI::success( 'Status check complete.' );
    }

    /**
     * Dump the full assembled system prompt to a file.
     *
     * Outputs the exact prompt that would be sent to the OpenAI Responses API,
     * with all template variables replaced and all components assembled.
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Output file path. Default: FULL_SYSTEM_PROMPT.txt in plugin directory.
     *
     * [--user=<user_id>]
     * : User ID to simulate for context. Default: 0 (guest).
     *
     * [--page=<url>]
     * : Simulated page URL for context. Default: shop page.
     *
     * [--no-tools]
     * : Exclude the tool definitions JSON. Default: tools are included.
     *
     * ## EXAMPLES
     *
     *     wp glimmr-ai dump-prompt
     *     wp glimmr-ai dump-prompt --output=/tmp/prompt.txt
     *     wp glimmr-ai dump-prompt --user=1 --page=/product/hoodie
     *     wp glimmr-ai dump-prompt --no-tools
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function dump_prompt( $args, $assoc_args ) {
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%B=== Glimmr AI System Prompt Dump ===%n' ) );
        WP_CLI::log( '' );

        // Parse arguments.
        $output_file   = isset( $assoc_args['output'] ) ? $assoc_args['output'] : GLIMMR_AI_PLUGIN_DIR . 'FULL_SYSTEM_PROMPT.txt';
        $user_id       = isset( $assoc_args['user'] ) ? (int) $assoc_args['user'] : 0;
        $page_url      = isset( $assoc_args['page'] ) ? $assoc_args['page'] : '';
        $include_tools = ! isset( $assoc_args['no-tools'] ); // Include tools by default

        // Set current user if specified.
        if ( $user_id > 0 ) {
            wp_set_current_user( $user_id );
            $user = get_userdata( $user_id );
            WP_CLI::log( WP_CLI::colorize( '%CSimulating user:%n ' . ( $user ? $user->display_name . ' (ID: ' . $user_id . ')' : 'User not found' ) ) );
        } else {
            WP_CLI::log( WP_CLI::colorize( '%CSimulating user:%n Guest' ) );
        }

        // Get plugin services.
        $settings = new Glimmr_AI_Settings();
        $context  = new Glimmr_AI_Context( $settings );

        // Build request context (simulated).
        $request_context = self::build_sample_request_context( $page_url );
        WP_CLI::log( WP_CLI::colorize( '%CPage context:%n ' . $request_context['page_title'] . ' (' . $request_context['page_url'] . ')' ) );

        // Build sample workspace object.
        $workspace = self::build_sample_workspace();
        WP_CLI::log( WP_CLI::colorize( '%CWorkspace:%n Sample state with constraints and candidates' ) );

        WP_CLI::log( '' );
        WP_CLI::log( 'Assembling full prompt...' );
        WP_CLI::log( '' );

        // === DIAGNOSTIC: Check for corruption at each stage ===
        WP_CLI::log( WP_CLI::colorize( '%Y=== DIAGNOSTIC: Checking for corruption ===%n' ) );

        // Stage 1: Check raw default prompt from source
        $raw_default = $context->get_default_system_prompt();
        $test_strings = array(
            'these from memory'    => 'Tool-First Rule section',
            'Never Guess Data'     => 'Core Principle #3',
            'stock_check'          => 'Tool Usage Decision Guide',
            'Superlative Query'    => 'Superlative section header',
            'add_to_cart'          => 'Cart Action section',
        );

        WP_CLI::log( 'Stage 1 - Raw default prompt from get_default_system_prompt():' );
        foreach ( $test_strings as $needle => $location ) {
            $found = strpos( $raw_default, $needle ) !== false;
            $status = $found ? '%G✓ Found%n' : '%R✗ MISSING%n';
            WP_CLI::log( '  ' . WP_CLI::colorize( $status ) . ' "' . $needle . '" (' . $location . ')' );
        }
        WP_CLI::log( '  Raw default length: ' . strlen( $raw_default ) . ' chars' );
        WP_CLI::log( '  MD5: ' . md5( $raw_default ) );
        WP_CLI::log( '' );

        // Get the full assembled prompt.
        // Pass the workspace object (or null to skip workspace section).
        $full_prompt = $context->get_slot_filling_system_prompt( $request_context, $workspace );

        // Stage 2: Check assembled prompt
        WP_CLI::log( 'Stage 2 - Assembled prompt from get_slot_filling_system_prompt():' );
        foreach ( $test_strings as $needle => $location ) {
            $found = strpos( $full_prompt, $needle ) !== false;
            $status = $found ? '%G✓ Found%n' : '%R✗ MISSING%n';
            WP_CLI::log( '  ' . WP_CLI::colorize( $status ) . ' "' . $needle . '" (' . $location . ')' );
        }
        WP_CLI::log( '  Assembled length: ' . strlen( $full_prompt ) . ' chars' );
        WP_CLI::log( '  MD5: ' . md5( $full_prompt ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%Y=== END DIAGNOSTIC ===%n' ) );
        WP_CLI::log( '' );

        // Build output content.
        $output = self::build_prompt_output( $full_prompt, $request_context, $workspace, $settings, $include_tools );

        // Stage 3: Check output string before writing
        WP_CLI::log( 'Stage 3 - Output string (build_prompt_output result):' );
        foreach ( $test_strings as $needle => $location ) {
            $found = strpos( $output, $needle ) !== false;
            $status = $found ? '%G✓ Found%n' : '%R✗ MISSING%n';
            WP_CLI::log( '  ' . WP_CLI::colorize( $status ) . ' "' . $needle . '" (' . $location . ')' );
        }
        WP_CLI::log( '  Output length: ' . strlen( $output ) . ' chars' );
        WP_CLI::log( '  MD5: ' . md5( $output ) );
        WP_CLI::log( '' );

        // Validate output path — restrict to plugin or uploads directory.
        $real_dir = realpath( dirname( $output_file ) );
        if ( false === $real_dir ) {
            WP_CLI::error( 'Output directory does not exist.' );
            return;
        }
        $output_file   = wp_normalize_path( $real_dir . '/' . basename( $output_file ) );
        $allowed_bases = array(
            wp_normalize_path( GLIMMR_AI_PLUGIN_DIR ),
            wp_normalize_path( wp_upload_dir()['basedir'] ),
        );
        $path_allowed = false;
        foreach ( $allowed_bases as $base ) {
            if ( str_starts_with( $output_file, $base ) ) {
                $path_allowed = true;
                break;
            }
        }
        if ( ! $path_allowed ) {
            WP_CLI::error( 'Output file must be within the plugin or uploads directory.' );
            return;
        }

        // Write to file.
        $result = file_put_contents( $output_file, $output );

        if ( false === $result ) {
            WP_CLI::error( 'Failed to write output file: ' . $output_file );
            return;
        }

        // Stage 4: Read back from file and verify
        $file_content = file_get_contents( $output_file );
        if ( false === $file_content ) {
            WP_CLI::warning( 'Stage 4 - Could not read back file for verification.' );
            $file_content = '';
        }
        WP_CLI::log( 'Stage 4 - File content after writing:' );
        foreach ( $test_strings as $needle => $location ) {
            $found = strpos( $file_content, $needle ) !== false;
            $status = $found ? '%G✓ Found%n' : '%R✗ MISSING%n';
            WP_CLI::log( '  ' . WP_CLI::colorize( $status ) . ' "' . $needle . '" (' . $location . ')' );
        }
        WP_CLI::log( '  File length: ' . strlen( $file_content ) . ' chars' );
        WP_CLI::log( '  MD5: ' . md5( $file_content ) );
        $md5_match = md5( $output ) === md5( $file_content );
        WP_CLI::log( '  MD5 matches output: ' . ( $md5_match ? 'YES' : 'NO - CORRUPTION DETECTED!' ) );
        WP_CLI::log( '' );

        // Display stats.
        $char_count  = strlen( $full_prompt );
        $word_count  = str_word_count( $full_prompt );
        $line_count  = substr_count( $full_prompt, "\n" ) + 1;
        $token_est   = (int) ceil( $char_count / 4 ); // Rough estimate.

        WP_CLI::log( WP_CLI::colorize( '%B=== Prompt Statistics ===%n' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GCharacters:%n  ' . number_format( $char_count ) ) );
        WP_CLI::log( WP_CLI::colorize( '%GWords:%n       ' . number_format( $word_count ) ) );
        WP_CLI::log( WP_CLI::colorize( '%GLines:%n       ' . number_format( $line_count ) ) );
        WP_CLI::log( WP_CLI::colorize( '%GTokens (est):%n ' . number_format( $token_est ) ) );
        WP_CLI::log( '' );

        // Show section breakdown.
        WP_CLI::log( WP_CLI::colorize( '%B=== Prompt Sections ===%n' ) );
        WP_CLI::log( '' );
        self::display_section_breakdown( $full_prompt );

        WP_CLI::log( '' );
        WP_CLI::success( 'Full prompt written to: ' . $output_file );
    }

    /**
     * Add test product reviews to a specific product.
     *
     * Creates realistic fake reviews for testing the get_reviews and summarize_reviews tools.
     * Reviews are distributed around the target average rating with realistic content.
     *
     * ## OPTIONS
     *
     * <sku>
     * : The product SKU to add reviews to.
     *
     * [--count=<number>]
     * : Number of reviews to add. Default: 10.
     *
     * [--average=<rating>]
     * : Target average rating (1.0-5.0). Default: 4.0.
     * : Reviews will be distributed around this average.
     *
     * [--verified=<percent>]
     * : Percentage of reviews to mark as verified purchases. Default: 70.
     *
     * [--dry-run]
     * : Show what would be created without actually creating reviews.
     *
     * ## EXAMPLES
     *
     *     # Add 10 reviews averaging 4 stars to product with SKU "HOODIE-001"
     *     wp glimmr-ai add-reviews HOODIE-001
     *
     *     # Add 25 reviews averaging 3.5 stars
     *     wp glimmr-ai add-reviews HOODIE-001 --count=25 --average=3.5
     *
     *     # Add reviews with 90% verified purchases
     *     wp glimmr-ai add-reviews HOODIE-001 --count=15 --verified=90
     *
     *     # Preview without creating
     *     wp glimmr-ai add-reviews HOODIE-001 --count=5 --dry-run
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public static function add_reviews( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide a product SKU.' );
            return;
        }

        $sku = $args[0];
        $count = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 10;
        $target_average = isset( $assoc_args['average'] ) ? (float) $assoc_args['average'] : 4.0;
        $verified_percent = isset( $assoc_args['verified'] ) ? (int) $assoc_args['verified'] : 70;
        $dry_run = isset( $assoc_args['dry-run'] );

        // Validate inputs.
        if ( $count < 1 || $count > 100 ) {
            WP_CLI::error( 'Count must be between 1 and 100.' );
            return;
        }

        if ( $target_average < 1.0 || $target_average > 5.0 ) {
            WP_CLI::error( 'Average rating must be between 1.0 and 5.0.' );
            return;
        }

        if ( $verified_percent < 0 || $verified_percent > 100 ) {
            WP_CLI::error( 'Verified percent must be between 0 and 100.' );
            return;
        }

        // Find product by SKU.
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            WP_CLI::error( 'Product not found with SKU: ' . $sku );
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            WP_CLI::error( 'Could not load product with ID: ' . $product_id );
            return;
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%B=== Glimmr AI Test Reviews Generator ===%n' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%CProduct:%n ' . $product->get_name() . ' (ID: ' . $product_id . ')' ) );
        WP_CLI::log( WP_CLI::colorize( '%CSKU:%n ' . $sku ) );
        WP_CLI::log( WP_CLI::colorize( '%CReviews to create:%n ' . $count ) );
        WP_CLI::log( WP_CLI::colorize( '%CTarget average:%n ' . number_format( $target_average, 1 ) . ' stars' ) );
        WP_CLI::log( WP_CLI::colorize( '%CVerified purchases:%n ' . $verified_percent . '%' ) );

        if ( $dry_run ) {
            WP_CLI::log( WP_CLI::colorize( '%Y[DRY RUN - No reviews will be created]%n' ) );
        }
        WP_CLI::log( '' );

        // Generate rating distribution.
        $ratings = self::generate_rating_distribution( $count, $target_average );
        $actual_average = array_sum( $ratings ) / count( $ratings );

        WP_CLI::log( WP_CLI::colorize( '%GRating distribution:%n' ) );
        $rating_counts = array_count_values( $ratings );
        krsort( $rating_counts );
        foreach ( $rating_counts as $rating => $cnt ) {
            $rating = (int) $rating;
            $stars = str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
            WP_CLI::log( sprintf( '  %s (%d stars): %d reviews', $stars, $rating, $cnt ) );
        }
        WP_CLI::log( WP_CLI::colorize( '  %CActual average:%n ' . number_format( $actual_average, 2 ) . ' stars' ) );
        WP_CLI::log( '' );

        if ( $dry_run ) {
            WP_CLI::log( WP_CLI::colorize( '%B=== Sample Reviews (Dry Run) ===%n' ) );
            WP_CLI::log( '' );

            // Show 3 sample reviews.
            $sample_count = min( 3, $count );
            for ( $i = 0; $i < $sample_count; $i++ ) {
                $rating = $ratings[ $i ];
                $verified = ( $i * 100 / $count ) < $verified_percent;
                $review = self::generate_review_content( $rating, $product->get_name() );
                $name = self::generate_reviewer_name();

                WP_CLI::log( WP_CLI::colorize( '%YReview ' . ( $i + 1 ) . ':%n' ) );
                WP_CLI::log( '  Rating: ' . str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) );
                WP_CLI::log( '  Verified: ' . ( $verified ? 'Yes' : 'No' ) );
                WP_CLI::log( '  Author: ' . $name );
                WP_CLI::log( '  Content: ' . $review['content'] );
                WP_CLI::log( '' );
            }

            if ( $count > 3 ) {
                WP_CLI::log( '  ... and ' . ( $count - 3 ) . ' more reviews' );
                WP_CLI::log( '' );
            }

            WP_CLI::success( 'Dry run complete. Use without --dry-run to create reviews.' );
            return;
        }

        // Create the reviews.
        WP_CLI::log( 'Creating reviews...' );
        $progress = \WP_CLI\Utils\make_progress_bar( 'Adding reviews', $count );

        $created = 0;
        $errors = 0;

        for ( $i = 0; $i < $count; $i++ ) {
            $rating = $ratings[ $i ];
            $verified = ( $i * 100 / $count ) < $verified_percent;
            $review = self::generate_review_content( $rating, $product->get_name() );
            $name = self::generate_reviewer_name();
            $email = self::generate_reviewer_email( $name );

            // Create a random date within the last 6 months.
            $days_ago = wp_rand( 1, 180 );
            $timestamp = strtotime( "-{$days_ago} days" );
            $date = gmdate( 'Y-m-d H:i:s', false !== $timestamp ? $timestamp : time() );

            $comment_data = array(
                'comment_post_ID'      => $product_id,
                'comment_author'       => $name,
                'comment_author_email' => $email,
                'comment_content'      => $review['content'],
                'comment_type'         => 'review',
                'comment_date'         => $date,
                'comment_date_gmt'     => get_gmt_from_date( $date ),
                'comment_approved'     => 1,
            );

            $comment_id = wp_insert_comment( $comment_data );

            if ( $comment_id ) {
                // Add rating meta.
                update_comment_meta( $comment_id, 'rating', $rating );

                // Add verified purchase meta.
                if ( $verified ) {
                    update_comment_meta( $comment_id, 'verified', 1 );
                }

                $created++;
            } else {
                $errors++;
            }

            $progress->tick();
        }

        $progress->finish();
        WP_CLI::log( '' );

        // Update product rating cache.
        if ( function_exists( 'wc_clear_product_transients' ) ) {
            wc_clear_product_transients( $product_id );
        }

        // Force recalculation of average rating.
        if ( $product instanceof WC_Product && method_exists( $product, 'set_average_rating' ) ) {
            $product->set_average_rating( '' );
            $product->save();
        }

        // Get new average from product.
        $product = wc_get_product( $product_id );
        $new_average = $product->get_average_rating();
        $new_count = $product->get_review_count();

        WP_CLI::log( WP_CLI::colorize( '%B=== Results ===%n' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GReviews created:%n ' . $created ) );
        if ( $errors > 0 ) {
            WP_CLI::log( WP_CLI::colorize( '%RErrors:%n ' . $errors ) );
        }
        WP_CLI::log( WP_CLI::colorize( '%CProduct average rating:%n ' . number_format( (float) $new_average, 2 ) . ' stars' ) );
        WP_CLI::log( WP_CLI::colorize( '%CTotal reviews:%n ' . $new_count ) );
        WP_CLI::log( '' );

        WP_CLI::success( 'Added ' . $created . ' reviews to ' . $product->get_name() );
    }

    /**
     * Generate a distribution of ratings around a target average.
     *
     * @param int   $count  Number of ratings to generate.
     * @param float $target Target average rating.
     * @return array Array of integer ratings (1-5).
     */
    private static function generate_rating_distribution( $count, $target ) {
        $ratings = array();

        // Use weighted random to cluster around target.
        // Higher probability for ratings closer to target.
        for ( $i = 0; $i < $count; $i++ ) {
            // Generate a rating using a simple algorithm:
            // - Start with the target (rounded)
            // - Add some variance
            $base = round( $target );
            $variance = wp_rand( -2, 2 );

            // Weight variance toward 0 for more clustering.
            if ( abs( $variance ) === 2 ) {
                // Only 20% chance to keep extreme variance.
                if ( wp_rand( 1, 100 ) > 20 ) {
                    $variance = $variance > 0 ? 1 : -1;
                }
            }

            $rating = $base + $variance;

            // Clamp to 1-5.
            $rating = max( 1, min( 5, $rating ) );

            $ratings[] = $rating;
        }

        // Adjust to hit target average more precisely.
        $current_sum = array_sum( $ratings );
        $target_sum = round( $target * $count );
        $diff = $target_sum - $current_sum;

        // Adjust ratings to hit target.
        $attempts = 0;
        while ( $diff != 0 && $attempts < $count * 2 ) {
            $idx = wp_rand( 0, $count - 1 );
            $current = $ratings[ $idx ];

            if ( $diff > 0 && $current < 5 ) {
                $ratings[ $idx ]++;
                $diff--;
            } elseif ( $diff < 0 && $current > 1 ) {
                $ratings[ $idx ]--;
                $diff++;
            }

            $attempts++;
        }

        // Shuffle for natural distribution.
        shuffle( $ratings );

        return $ratings;
    }

    /**
     * Generate review content based on rating.
     *
     * @param int    $rating       Star rating (1-5).
     * @param string $product_name Product name for personalization.
     * @return array Array with 'content' key.
     */
    private static function generate_review_content( $rating, $product_name ) {
        // Content pools by rating.
        $positive_phrases = array(
            'Absolutely love this!',
            'Exceeded my expectations.',
            'Best purchase I\'ve made in a while.',
            'Would definitely recommend.',
            'Amazing quality for the price.',
            'So happy with this purchase!',
            'Exactly what I was looking for.',
            'Five stars all the way!',
            'Can\'t say enough good things.',
            'Will be buying more.',
        );

        $neutral_phrases = array(
            'It\'s decent for the price.',
            'Does what it\'s supposed to do.',
            'Good, but not great.',
            'Meets expectations.',
            'Solid product overall.',
            'Nothing special, but works fine.',
            'Average quality.',
            'It\'s okay.',
            'Gets the job done.',
            'Fair value.',
        );

        $negative_phrases = array(
            'Not what I expected.',
            'Disappointed with the quality.',
            'Wouldn\'t buy again.',
            'Had issues from the start.',
            'Could be better.',
            'Not worth the price.',
            'Had to return it.',
            'Underwhelming.',
            'Quality issues.',
            'Expected more.',
        );

        $quality_comments = array(
            'The quality is excellent.',
            'Material feels premium.',
            'Well-made and durable.',
            'Great attention to detail.',
            'Feels cheap unfortunately.',
            'Quality is hit or miss.',
            'Sturdy construction.',
            'Nice finish.',
        );

        $sizing_comments = array(
            'Fits true to size.',
            'Runs a bit small, size up.',
            'Runs large, consider sizing down.',
            'Perfect fit!',
            'Sizing is accurate.',
            'A bit snug but manageable.',
            'Roomy and comfortable.',
        );

        $shipping_comments = array(
            'Arrived quickly.',
            'Fast shipping!',
            'Took a while to arrive.',
            'Packaging was great.',
            'Shipped faster than expected.',
            'Delivery was smooth.',
        );

        // Build review based on rating.
        $parts = array();

        if ( $rating >= 4 ) {
            $parts[] = $positive_phrases[ array_rand( $positive_phrases ) ];

            if ( wp_rand( 1, 100 ) > 40 ) {
                $parts[] = $quality_comments[ array_rand( array_slice( $quality_comments, 0, 4 ) ) ];
            }
        } elseif ( $rating === 3 ) {
            $parts[] = $neutral_phrases[ array_rand( $neutral_phrases ) ];

            if ( wp_rand( 1, 100 ) > 50 ) {
                $parts[] = $quality_comments[ array_rand( $quality_comments ) ];
            }
        } else {
            $parts[] = $negative_phrases[ array_rand( $negative_phrases ) ];

            if ( wp_rand( 1, 100 ) > 60 ) {
                $parts[] = $quality_comments[ array_rand( array_slice( $quality_comments, 4 ) ) ];
            }
        }

        // Sometimes add sizing comment.
        if ( wp_rand( 1, 100 ) > 60 ) {
            $parts[] = $sizing_comments[ array_rand( $sizing_comments ) ];
        }

        // Sometimes add shipping comment.
        if ( wp_rand( 1, 100 ) > 70 ) {
            $parts[] = $shipping_comments[ array_rand( $shipping_comments ) ];
        }

        return array(
            'content' => implode( ' ', $parts ),
        );
    }

    /**
     * Generate a realistic reviewer name.
     *
     * @return string Reviewer name.
     */
    private static function generate_reviewer_name() {
        $first_names = array(
            'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda',
            'William', 'Elizabeth', 'David', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
            'Thomas', 'Sarah', 'Christopher', 'Karen', 'Charles', 'Lisa', 'Daniel', 'Nancy',
            'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Donald', 'Ashley',
            'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle',
            'Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Jamie',
        );

        $last_initials = array(
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M',
            'N', 'O', 'P', 'R', 'S', 'T', 'V', 'W',
        );

        $first = $first_names[ array_rand( $first_names ) ];

        // 60% chance to include last initial.
        if ( wp_rand( 1, 100 ) <= 60 ) {
            $last = $last_initials[ array_rand( $last_initials ) ];
            return $first . ' ' . $last . '.';
        }

        return $first;
    }

    /**
     * Generate a fake email for reviewer.
     *
     * @param string $name Reviewer name.
     * @return string Email address.
     */
    private static function generate_reviewer_email( $name ) {
        $clean_name = strtolower( preg_replace( '/[^a-zA-Z]/', '', $name ) );
        $number = wp_rand( 1, 999 );
        $domains = array( 'example.com', 'test.com', 'email.test', 'sample.org' );

        return $clean_name . $number . '@' . $domains[ array_rand( $domains ) ];
    }

    /**
     * Build sample request context for prompt generation.
     *
     * @param string $page_url Optional page URL override.
     * @return array Request context array.
     */
    private static function build_sample_request_context( $page_url = '' ) {
        // Default to shop page if not specified.
        if ( empty( $page_url ) ) {
            $shop_page_id = wc_get_page_id( 'shop' );
            if ( $shop_page_id > 0 ) {
                $page_url   = get_permalink( $shop_page_id );
                $page_title = get_the_title( $shop_page_id );
            } else {
                $page_url   = home_url( '/shop/' );
                $page_title = 'Shop';
            }
        } else {
            // Try to get title from URL.
            $page_id = url_to_postid( home_url( $page_url ) );
            if ( $page_id > 0 ) {
                $page_title = get_the_title( $page_id );
                $page_url   = get_permalink( $page_id );
            } else {
                $page_title = 'Page';
                $page_url   = home_url( $page_url );
            }
        }

        return array(
            'page_url'     => $page_url,
            'page_title'   => $page_title,
            'page_type'    => 'shop',
            'referrer'     => home_url(),
            'is_cli'       => true,
            'device'       => 'desktop',
            'browser'      => 'CLI',
        );
    }

    /**
     * Build sample workspace object for prompt generation.
     *
     * @return Glimmr_AI_Workspace|null Workspace object or null.
     */
    private static function build_sample_workspace() {
        // Check if the Workspace class exists.
        if ( ! class_exists( 'Glimmr_AI_Workspace' ) ) {
            // Try to load it.
            $workspace_file = GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-workspace.php';
            if ( file_exists( $workspace_file ) ) {
                require_once $workspace_file;
            }
        }

        if ( ! class_exists( 'Glimmr_AI_Workspace' ) ) {
            return null;
        }

        // Create a workspace with a sample conversation ID.
        $sample_conversation_id = 'cli-prompt-dump-' . wp_generate_uuid4();
        $workspace = new Glimmr_AI_Workspace( $sample_conversation_id );

        // Populate with sample data to show what the workspace looks like.
        // Get some real product IDs if available.
        if ( function_exists( 'wc_get_products' ) ) {
            $products = wc_get_products( array(
                'limit'  => 5,
                'status' => 'publish',
                'return' => 'ids',
            ) );

            if ( ! empty( $products ) ) {
                // Use apply_updates to set candidates and shortlist.
                $updates = array(
                    'candidates' => $products,
                    'shortlist'  => array_slice( $products, 0, 2 ),
                );
                $workspace->apply_updates( $updates );

                // Set focused products.
                $workspace->set_focused_products( array( $products[0] ) );
            }
        }

        // Set some sample constraints.
        $workspace->set_constraint( 'category', 'Apparel' );
        $workspace->set_constraint( 'price_range', '$50-$100' );

        return $workspace;
    }

    /**
     * Build the full output content with metadata and formatting.
     *
     * @param string                      $full_prompt     The assembled prompt.
     * @param array                       $request_context Request context used.
     * @param Glimmr_AI_Workspace|null    $workspace       Workspace object used.
     * @param Glimmr_AI_Settings          $settings        Settings instance.
     * @param bool                        $include_tools   Whether to include tool definitions.
     * @return string Formatted output content.
     */
    private static function build_prompt_output( $full_prompt, $request_context, $workspace, Glimmr_AI_Settings $settings, $include_tools ) {
        $output = '';

        // Header.
        $output .= "================================================================================\n";
        $output .= "GLIMMR AI - FULL ASSEMBLED SYSTEM PROMPT\n";
        $output .= "================================================================================\n";
        $output .= "\n";
        $output .= "Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $output .= "Plugin Version: " . GLIMMR_AI_VERSION . "\n";
        $output .= "Model: " . $settings->get( 'openai_model', 'gpt-4o' ) . "\n";
        $output .= "\n";

        // Context used.
        $output .= "================================================================================\n";
        $output .= "CONTEXT USED FOR GENERATION\n";
        $output .= "================================================================================\n";
        $output .= "\n";
        $output .= "User: " . ( is_user_logged_in() ? wp_get_current_user()->display_name . ' (ID: ' . get_current_user_id() . ')' : 'Guest' ) . "\n";
        $output .= "Page URL: " . $request_context['page_url'] . "\n";
        $output .= "Page Title: " . $request_context['page_title'] . "\n";
        $output .= "Site Name: " . get_bloginfo( 'name' ) . "\n";
        $output .= "Site URL: " . home_url() . "\n";
        $output .= "\n";

        // Workspace state.
        $output .= "================================================================================\n";
        $output .= "WORKSPACE STATE (Injected into prompt)\n";
        $output .= "================================================================================\n";
        $output .= "\n";
        if ( $workspace ) {
            $output .= wp_json_encode( $workspace->get_state(), JSON_PRETTY_PRINT ) . "\n";
        } else {
            $output .= "(No workspace state - workspace is null or empty)\n";
        }
        $output .= "\n";

        // The actual prompt.
        $output .= "================================================================================\n";
        $output .= "FULL SYSTEM PROMPT (Sent to OpenAI Responses API)\n";
        $output .= "================================================================================\n";
        $output .= "\n";
        $output .= $full_prompt . "\n";
        $output .= "\n";

        // Statistics.
        $char_count = strlen( $full_prompt );
        $token_est  = (int) ceil( $char_count / 4 );

        $output .= "================================================================================\n";
        $output .= "STATISTICS\n";
        $output .= "================================================================================\n";
        $output .= "\n";
        $output .= "Characters: " . number_format( $char_count ) . "\n";
        $output .= "Words: " . number_format( str_word_count( $full_prompt ) ) . "\n";
        $output .= "Lines: " . number_format( substr_count( $full_prompt, "\n" ) + 1 ) . "\n";
        $output .= "Estimated Tokens: " . number_format( $token_est ) . "\n";
        $output .= "\n";

        // Tool definitions if requested.
        if ( $include_tools ) {
            $output .= "================================================================================\n";
            $output .= "TOOL DEFINITIONS (Sent alongside prompt)\n";
            $output .= "================================================================================\n";
            $output .= "\n";

            $plugin = Glimmr_AI::get_instance();
            $tool_registry = $plugin->get_tool_registry();
            $tools = $tool_registry->get_definitions( true );
            $output .= wp_json_encode( $tools, JSON_PRETTY_PRINT ) . "\n";
            $output .= "\n";
        }

        $output .= "================================================================================\n";
        $output .= "END OF PROMPT DUMP\n";
        $output .= "================================================================================\n";

        return $output;
    }

    /**
     * Display a breakdown of sections in the prompt.
     *
     * @param string $prompt The full prompt.
     */
    private static function display_section_breakdown( $prompt ) {
        // Try to identify sections by common headers.
        $sections = array(
            'Base Prompt'      => '## Your Capabilities',
            'Context Block'    => '## Current Context',
            'Workspace State'  => '## Current Workspace State',
            'Controller Schema' => '## Response Format',
            'Stopping Rules'   => '## CRITICAL STOPPING RULES',
            'Slot-Filling'     => '## Slot-Filling Process',
        );

        foreach ( $sections as $name => $marker ) {
            $pos = strpos( $prompt, $marker );
            if ( false !== $pos ) {
                WP_CLI::log( WP_CLI::colorize( '%G✓%n ' . $name . ' (found at char ' . number_format( $pos ) . ')' ) );
            } else {
                WP_CLI::log( WP_CLI::colorize( '%R✗%n ' . $name . ' (not found)' ) );
            }
        }
    }
}
