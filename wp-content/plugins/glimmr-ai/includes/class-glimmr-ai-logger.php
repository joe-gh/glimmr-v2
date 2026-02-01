<?php
/**
 * Logger
 *
 * Handles error logging, debugging, and audit trails for Glimmr AI.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Logger
 *
 * Provides logging functionality with multiple levels and destinations.
 */
class Glimmr_AI_Logger {

    /**
     * Log levels.
     */
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';
    const LEVEL_CRITICAL = 'critical';

    /**
     * Log level priorities (lower = more important).
     */
    const LEVEL_PRIORITIES = array(
        'critical' => 1,
        'error'    => 2,
        'warning'  => 3,
        'info'     => 4,
        'debug'    => 5,
    );

    /**
     * Log directory.
     *
     * @var string
     */
    private static $log_dir;

    /**
     * Current log level threshold.
     *
     * @var string
     */
    private static $log_level = 'info';

    /**
     * Whether debug mode is enabled.
     *
     * @var bool
     */
    private static $debug_mode = false;

    /**
     * Initialize the logger.
     *
     * @param bool $debug_mode Whether debug mode is enabled.
     */
    public static function init( $debug_mode = false ) {
        self::$debug_mode = $debug_mode;
        self::$log_level = $debug_mode ? self::LEVEL_DEBUG : self::LEVEL_INFO;

        // Set up log directory with randomized name for security.
        $upload_dir = wp_upload_dir();
        $log_dir_name = self::get_secure_log_dir_name();
        self::$log_dir = $upload_dir['basedir'] . '/' . $log_dir_name . '/';

        // Create log directory if it doesn't exist.
        if ( ! file_exists( self::$log_dir ) ) {
            $dir_created = wp_mkdir_p( self::$log_dir );
            if ( ! $dir_created ) {
                // Log directory creation failed - fall back to error_log.
                error_log( '[Glimmr AI] Failed to create log directory: ' . self::$log_dir );
                return;
            }
            self::create_log_protection_files();
        }
    }

    /**
     * Get a secure log directory name.
     *
     * Uses a hash-based name to prevent directory enumeration.
     *
     * @return string The log directory name.
     */
    private static function get_secure_log_dir_name() {
        $stored_name = get_option( 'glimmr_ai_log_dir_name' );

        if ( empty( $stored_name ) ) {
            // Generate a random suffix for the log directory.
            $random_suffix = wp_generate_password( 12, false );
            $stored_name = 'glimmr-ai-logs-' . $random_suffix;
            update_option( 'glimmr_ai_log_dir_name', $stored_name, false );
        }

        return $stored_name;
    }

    /**
     * Create protection files for log directory.
     *
     * Supports Apache, Nginx, IIS, and LiteSpeed.
     * Failures are logged but don't prevent logger operation.
     */
    private static function create_log_protection_files() {
        $failed_files = array();

        // Apache .htaccess.
        $htaccess_content = "# Deny all access to log files\n";
        $htaccess_content .= "<IfModule mod_authz_core.c>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "</IfModule>\n";
        $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "    Order deny,allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</IfModule>\n";
        if ( false === @file_put_contents( self::$log_dir . '.htaccess', $htaccess_content ) ) {
            $failed_files[] = '.htaccess';
        }

        // PHP index file (universal fallback).
        if ( false === @file_put_contents( self::$log_dir . 'index.php', "<?php\n// Silence is golden.\nhttp_response_code(403);\nexit;" ) ) {
            $failed_files[] = 'index.php';
        }

        // HTML index file (backup).
        if ( false === @file_put_contents( self::$log_dir . 'index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Forbidden</h1></body></html>' ) ) {
            $failed_files[] = 'index.html';
        }

        // IIS web.config.
        $webconfig_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $webconfig_content .= '<configuration>' . "\n";
        $webconfig_content .= '    <system.webServer>' . "\n";
        $webconfig_content .= '        <security>' . "\n";
        $webconfig_content .= '            <requestFiltering>' . "\n";
        $webconfig_content .= '                <hiddenSegments>' . "\n";
        $webconfig_content .= '                    <add segment="glimmr-ai-logs" />' . "\n";
        $webconfig_content .= '                </hiddenSegments>' . "\n";
        $webconfig_content .= '            </requestFiltering>' . "\n";
        $webconfig_content .= '        </security>' . "\n";
        $webconfig_content .= '        <httpErrors errorMode="Custom">' . "\n";
        $webconfig_content .= '            <remove statusCode="403" />' . "\n";
        $webconfig_content .= '            <error statusCode="403" path="/" responseMode="Redirect" />' . "\n";
        $webconfig_content .= '        </httpErrors>' . "\n";
        $webconfig_content .= '    </system.webServer>' . "\n";
        $webconfig_content .= '</configuration>' . "\n";
        if ( false === @file_put_contents( self::$log_dir . 'web.config', $webconfig_content ) ) {
            $failed_files[] = 'web.config';
        }

        // Create a README for Nginx users with manual configuration instructions.
        $nginx_readme = "# Nginx Configuration Required\n\n";
        $nginx_readme .= "To protect this log directory on Nginx, add the following to your server block:\n\n";
        $nginx_readme .= "```nginx\n";
        $nginx_readme .= "location ~* /wp-content/uploads/glimmr-ai-logs {" . "\n";
        $nginx_readme .= "    deny all;\n";
        $nginx_readme .= "    return 403;\n";
        $nginx_readme .= "}\n";
        $nginx_readme .= "```\n\n";
        $nginx_readme .= "Or to deny all .log files:\n\n";
        $nginx_readme .= "```nginx\n";
        $nginx_readme .= "location ~* \\.log$ {\n";
        $nginx_readme .= "    deny all;\n";
        $nginx_readme .= "}\n";
        $nginx_readme .= "```\n";
        if ( false === @file_put_contents( self::$log_dir . 'NGINX-README.txt', $nginx_readme ) ) {
            $failed_files[] = 'NGINX-README.txt';
        }

        // Log any failures (using error_log since our logger may not be fully ready).
        if ( ! empty( $failed_files ) ) {
            error_log( '[Glimmr AI] Failed to create log protection files: ' . implode( ', ', $failed_files ) );
        }
    }

    /**
     * S5: PII patterns to mask in logs.
     *
     * @var array
     */
    private static $pii_patterns = array(
        // Email addresses.
        '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
        // Credit card numbers (basic patterns).
        '/\b(?:\d{4}[-\s]?){3}\d{4}\b/' => '[CARD]',
        // Phone numbers (various formats).
        '/\b(?:\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}\b/' => '[PHONE]',
        // SSN.
        '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/' => '[SSN]',
        // IP addresses (IPv4).
        '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/' => '[IP]',
        // ZIP codes (US).
        '/\b\d{5}(?:-\d{4})?\b/' => '[ZIP]',
    );

    /**
     * S5: Context keys that should have their values masked.
     *
     * @var array
     */
    private static $sensitive_keys = array(
        'email',
        'phone',
        'address',
        'address_1',
        'address_2',
        'street',
        'billing_email',
        'billing_phone',
        'billing_address',
        'shipping_address',
        'card_number',
        'cvv',
        'cvc',
        'expiry',
        'ssn',
        'social_security',
        'password',
        'api_key',
        'secret',
        'token',
        'last4',
        'card_type',
        'postcode',
        'postal_code',
        'zip',
    );

    /**
     * Log a message.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context data.
     * @param string $source  Source identifier (e.g., 'openai', 'conversation').
     */
    public static function log( $level, $message, $context = array(), $source = 'general' ) {
        // Check if this level should be logged.
        if ( ! self::should_log( $level ) ) {
            return;
        }

        // S5: Mask PII in message and context.
        $message = self::mask_pii_string( $message );
        $context = self::mask_pii_context( $context );

        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'level'     => strtoupper( $level ),
            'source'    => $source,
            'message'   => $message,
            'context'   => $context,
        );

        // Add request context in debug mode.
        if ( self::$debug_mode ) {
            $log_entry['request'] = array(
                'url'       => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                'method'    => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
                'user_id'   => get_current_user_id(),
                'ip'        => self::get_client_ip_hash(),
            );
        }

        // Write to file.
        self::write_to_file( $log_entry );

        // Also track errors in analytics.
        if ( in_array( $level, array( self::LEVEL_ERROR, self::LEVEL_CRITICAL ), true ) ) {
            Glimmr_AI_Analytics::track_error(
                $source,
                $message,
                isset( $context['conversation_id'] ) ? $context['conversation_id'] : null
            );
        }

        // For critical errors, also use error_log.
        if ( $level === self::LEVEL_CRITICAL ) {
            error_log( sprintf( '[Glimmr AI CRITICAL] %s: %s', $source, $message ) );
        }
    }

    /**
     * Log debug message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     * @param string $source  Source.
     */
    public static function debug( $message, $context = array(), $source = 'general' ) {
        self::log( self::LEVEL_DEBUG, $message, $context, $source );
    }

    /**
     * Log info message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     * @param string $source  Source.
     */
    public static function info( $message, $context = array(), $source = 'general' ) {
        self::log( self::LEVEL_INFO, $message, $context, $source );
    }

    /**
     * Log warning.
     *
     * @param string $message Message.
     * @param array  $context Context.
     * @param string $source  Source.
     */
    public static function warning( $message, $context = array(), $source = 'general' ) {
        self::log( self::LEVEL_WARNING, $message, $context, $source );
    }

    /**
     * Log error.
     *
     * @param string $message Message.
     * @param array  $context Context.
     * @param string $source  Source.
     */
    public static function error( $message, $context = array(), $source = 'general' ) {
        self::log( self::LEVEL_ERROR, $message, $context, $source );
    }

    /**
     * Log critical error.
     *
     * @param string $message Message.
     * @param array  $context Context.
     * @param string $source  Source.
     */
    public static function critical( $message, $context = array(), $source = 'general' ) {
        self::log( self::LEVEL_CRITICAL, $message, $context, $source );
    }

    /**
     * Log an exception.
     *
     * @param Exception $exception The exception.
     * @param string    $source    Source identifier.
     * @param array     $context   Additional context.
     */
    public static function exception( $exception, $source = 'general', $context = array() ) {
        $context = array_merge(
            $context,
            array(
                'exception_class' => get_class( $exception ),
                'code'            => $exception->getCode(),
                'file'            => $exception->getFile(),
                'line'            => $exception->getLine(),
                'trace'           => self::$debug_mode ? $exception->getTraceAsString() : null,
            )
        );

        self::error( $exception->getMessage(), $context, $source );
    }

    /**
     * Log an API request/response.
     *
     * @param string $endpoint   API endpoint.
     * @param array  $request    Request data.
     * @param array  $response   Response data.
     * @param float  $duration   Request duration in seconds.
     * @param bool   $success    Whether request was successful.
     */
    public static function api_call( $endpoint, $request, $response, $duration, $success = true ) {
        $level = $success ? self::LEVEL_DEBUG : self::LEVEL_ERROR;

        // Sanitize sensitive data.
        if ( isset( $request['api_key'] ) ) {
            $request['api_key'] = '***REDACTED***';
        }

        self::log(
            $level,
            sprintf( 'API call to %s (%0.3fs)', $endpoint, $duration ),
            array(
                'endpoint' => $endpoint,
                'success'  => $success,
                'duration' => round( $duration, 3 ),
                'request'  => self::$debug_mode ? $request : null,
                'response' => self::$debug_mode ? self::truncate_response( $response ) : null,
            ),
            'api'
        );
    }

    /**
     * Write log entry to file.
     *
     * @param array $entry Log entry.
     */
    private static function write_to_file( $entry ) {
        // Check if log directory is initialized.
        if ( empty( self::$log_dir ) ) {
            // Fall back to error_log if logger not initialized.
            error_log( sprintf( '[Glimmr AI] [%s] [%s] %s', $entry['level'], $entry['source'], $entry['message'] ) );
            return;
        }

        $log_file = self::$log_dir . 'glimmr-ai-' . gmdate( 'Y-m-d' ) . '.log';

        $line = sprintf(
            "[%s] [%s] [%s] %s",
            $entry['timestamp'],
            $entry['level'],
            $entry['source'],
            $entry['message']
        );

        if ( ! empty( $entry['context'] ) ) {
            $line .= ' ' . wp_json_encode( $entry['context'] );
        }

        $line .= PHP_EOL;

        // Suppress errors and handle failure gracefully.
        $result = @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );

        if ( false === $result ) {
            // File write failed - fall back to error_log for critical/error level messages.
            if ( in_array( $entry['level'], array( 'ERROR', 'CRITICAL' ), true ) ) {
                error_log( sprintf( '[Glimmr AI] [%s] [%s] %s (file write failed)', $entry['level'], $entry['source'], $entry['message'] ) );
            }
        }
    }

    /**
     * Check if a level should be logged.
     *
     * @param string $level Log level.
     * @return bool
     */
    private static function should_log( $level ) {
        $level_priority = self::LEVEL_PRIORITIES[ $level ] ?? 5;
        $threshold_priority = self::LEVEL_PRIORITIES[ self::$log_level ] ?? 4;

        return $level_priority <= $threshold_priority;
    }

    /**
     * Get hashed client IP for logging.
     *
     * Priority: Sucuri > X-Forwarded-For > HTTP_CLIENT_IP > REMOTE_ADDR
     *
     * @return string Hashed IP.
     */
    private static function get_client_ip_hash() {
        $ip = '';

        // Priority 1: Sucuri WAF header.
        if ( ! empty( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // Priority 2: X-Forwarded-For (take leftmost IP).
            $forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip = strpos( $forwarded, ',' ) !== false ? trim( explode( ',', $forwarded )[0] ) : $forwarded;
        } elseif ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        // Return hashed for privacy with consistent salt.
        return $ip ? hash( 'sha256', $ip . wp_salt( 'auth' ) ) : 'unknown';
    }

    /**
     * Truncate large response data for logging.
     *
     * @param mixed $response Response data.
     * @param int   $max_length Maximum length.
     * @return mixed Truncated data.
     */
    private static function truncate_response( $response, $max_length = 1000 ) {
        if ( is_string( $response ) && strlen( $response ) > $max_length ) {
            return substr( $response, 0, $max_length ) . '... (truncated)';
        }

        if ( is_array( $response ) ) {
            $json = wp_json_encode( $response );
            if ( strlen( $json ) > $max_length ) {
                return array( '_truncated' => true, '_length' => strlen( $json ) );
            }
        }

        return $response;
    }

    // =========================================================================
    // Log Management
    // =========================================================================

    /**
     * Get log files.
     *
     * @return array Log file info.
     */
    public static function get_log_files() {
        $files = array();

        if ( ! file_exists( self::$log_dir ) ) {
            return $files;
        }

        $log_files = glob( self::$log_dir . 'glimmr-ai-*.log' );

        foreach ( $log_files as $file ) {
            $files[] = array(
                'name'     => basename( $file ),
                'path'     => $file,
                'size'     => filesize( $file ),
                'modified' => filemtime( $file ),
                'date'     => gmdate( 'Y-m-d', filemtime( $file ) ),
            );
        }

        // Sort by date descending.
        usort( $files, function ( $a, $b ) {
            return $b['modified'] - $a['modified'];
        });

        return $files;
    }

    /**
     * Read log file contents.
     *
     * @param string $filename Log filename.
     * @param int    $lines    Number of lines to read (from end).
     * @return string Log contents.
     */
    public static function read_log( $filename, $lines = 100 ) {
        $filepath = self::$log_dir . basename( $filename );

        if ( ! file_exists( $filepath ) ) {
            return '';
        }

        // Read last N lines.
        $file_lines = file( $filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

        if ( ! $file_lines ) {
            return '';
        }

        $last_lines = array_slice( $file_lines, -$lines );

        return implode( PHP_EOL, $last_lines );
    }

    /**
     * Delete old log files.
     *
     * @param int $days_to_keep Days of logs to keep.
     * @return int Number of files deleted.
     */
    public static function cleanup_logs( $days_to_keep = 30 ) {
        $deleted = 0;
        $cutoff = time() - ( $days_to_keep * DAY_IN_SECONDS );

        $log_files = glob( self::$log_dir . 'glimmr-ai-*.log' );

        foreach ( $log_files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                if ( unlink( $file ) ) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Get total log size.
     *
     * @return int Total size in bytes.
     */
    public static function get_total_log_size() {
        $total = 0;

        $log_files = glob( self::$log_dir . 'glimmr-ai-*.log' );

        foreach ( $log_files as $file ) {
            $total += filesize( $file );
        }

        return $total;
    }

    /**
     * Format bytes to human readable.
     *
     * @param int $bytes Bytes.
     * @return string Formatted string.
     */
    public static function format_bytes( $bytes ) {
        if ( $bytes >= 1073741824 ) {
            return number_format( $bytes / 1073741824, 2 ) . ' GB';
        } elseif ( $bytes >= 1048576 ) {
            return number_format( $bytes / 1048576, 2 ) . ' MB';
        } elseif ( $bytes >= 1024 ) {
            return number_format( $bytes / 1024, 2 ) . ' KB';
        }
        return $bytes . ' bytes';
    }

    // =========================================================================
    // S5: PII Masking Methods
    // =========================================================================

    /**
     * S5: Mask PII in a string using regex patterns.
     *
     * @param string $string The string to mask.
     * @return string The masked string.
     */
    private static function mask_pii_string( $string ) {
        if ( ! is_string( $string ) || empty( $string ) ) {
            return $string;
        }

        foreach ( self::$pii_patterns as $pattern => $replacement ) {
            $string = preg_replace( $pattern, $replacement, $string );
        }

        return $string;
    }

    /**
     * S5: Mask PII in a context array.
     *
     * @param array $context The context array.
     * @return array The masked context array.
     */
    private static function mask_pii_context( $context ) {
        if ( ! is_array( $context ) ) {
            return $context;
        }

        foreach ( $context as $key => $value ) {
            // Check if this key is sensitive.
            $key_lower = strtolower( $key );
            if ( in_array( $key_lower, self::$sensitive_keys, true ) ) {
                $context[ $key ] = '[REDACTED]';
                continue;
            }

            // Recursively mask nested arrays.
            if ( is_array( $value ) ) {
                $context[ $key ] = self::mask_pii_context( $value );
            } elseif ( is_string( $value ) ) {
                $context[ $key ] = self::mask_pii_string( $value );
            }
        }

        return $context;
    }

    /**
     * S5: Set the current log level.
     *
     * @param string $level The log level (debug, info, warning, error, critical).
     */
    public static function set_log_level( $level ) {
        if ( isset( self::LEVEL_PRIORITIES[ $level ] ) ) {
            self::$log_level = $level;
        }
    }

    /**
     * Get the current log level.
     *
     * @return string Current log level.
     */
    public static function get_log_level() {
        return self::$log_level;
    }

    /**
     * Get the log directory path.
     *
     * @return string|null Log directory path or null if not initialized.
     */
    public static function get_log_directory() {
        return self::$log_dir;
    }

    /**
     * Get the current day's log file path.
     *
     * @return string|null Log file path or null if not initialized.
     */
    public static function get_current_log_file() {
        if ( empty( self::$log_dir ) ) {
            return null;
        }

        return self::$log_dir . 'glimmr-ai-' . gmdate( 'Y-m-d' ) . '.log';
    }
}
