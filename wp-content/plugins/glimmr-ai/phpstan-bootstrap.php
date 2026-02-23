<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants and stubs that PHPStan needs to analyze the plugin
 * without a full WordPress/WooCommerce runtime.
 *
 * @phpstan-ignore-next-line
 */

// Plugin constants (defined in glimmr-ai.php).
define( 'GLIMMR_AI_VERSION', '1.0.2' );
define( 'GLIMMR_AI_PLUGIN_DIR', __DIR__ . '/' );
define( 'GLIMMR_AI_PLUGIN_URL', 'https://example.com/wp-content/plugins/glimmr-ai/' );
define( 'GLIMMR_AI_PLUGIN_BASENAME', 'glimmr-ai/glimmr-ai.php' );
define( 'GLIMMR_AI_TABLE_PREFIX', 'glimmr_ai_' );

// WordPress constants that may not be in stubs.
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'DB_NAME' ) ) {
    define( 'DB_NAME', 'wordpress' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', false );
}
