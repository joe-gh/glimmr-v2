<?php
/**
 * Parameter Validator
 *
 * Recursive validation with path-aware errors for tool parameters.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Parameter_Validator
 *
 * Validates tool parameters against schemas with support for
 * nested objects and path-aware error messages.
 */
class Glimmr_AI_Parameter_Validator {

    /**
     * Validate arguments against a schema.
     *
     * @param array  $arguments The arguments to validate.
     * @param array  $schema    The schema to validate against.
     * @param string $path      Current path for error reporting.
     * @return array|true True if valid, or array with validation error.
     */
    public static function validate( $arguments, $schema, $path = '' ) {
        foreach ( $schema as $field_name => $field_schema ) {
            $field_path = $path ? "{$path}.{$field_name}" : $field_name;
            $value = $arguments[ $field_name ] ?? null;

            // Check required.
            if ( ! empty( $field_schema['required'] ) && null === $value ) {
                return self::error( 'missing_required', $field_path,
                    sprintf( __( 'Required field "%s" is missing.', 'glimmr-ai' ), $field_path )
                );
            }

            // Skip validation if value is null and not required.
            if ( null === $value ) {
                continue;
            }

            // Validate type.
            $type_error = self::validate_type( $value, $field_schema, $field_path );
            if ( is_array( $type_error ) ) {
                return $type_error;
            }

            // Validate enum.
            if ( isset( $field_schema['enum'] ) ) {
                if ( ! in_array( $value, $field_schema['enum'], true ) ) {
                    return self::error( 'invalid_enum', $field_path,
                        sprintf( __( 'Field "%s" must be one of: %s', 'glimmr-ai' ),
                            $field_path,
                            implode( ', ', $field_schema['enum'] )
                        ),
                        array( 'allowed_values' => $field_schema['enum'] )
                    );
                }
            }

            // Validate nested object properties.
            if ( isset( $field_schema['properties'] ) && is_array( $value ) ) {
                $nested_result = self::validate( $value, $field_schema['properties'], $field_path );
                if ( is_array( $nested_result ) ) {
                    return $nested_result;
                }
            }

            // Validate array items.
            if ( isset( $field_schema['items'] ) && is_array( $value ) ) {
                $item_result = self::validate_array_items( $value, $field_schema, $field_path );
                if ( is_array( $item_result ) ) {
                    return $item_result;
                }
            }

            // Validate constraints.
            $constraint_error = self::validate_constraints( $value, $field_schema, $field_path );
            if ( is_array( $constraint_error ) ) {
                return $constraint_error;
            }
        }

        return true;
    }

    /**
     * Validate value type.
     *
     * @param mixed  $value       The value to validate.
     * @param array  $field_schema The field schema.
     * @param string $field_path  Path for error reporting.
     * @return array|true True if valid, or error array.
     */
    private static function validate_type( $value, $field_schema, $field_path ) {
        if ( ! isset( $field_schema['type'] ) ) {
            return true;
        }

        $type = $field_schema['type'];
        $valid = false;

        switch ( $type ) {
            case 'string':
                $valid = is_string( $value );
                break;
            case 'integer':
                $valid = is_int( $value ) || ( is_numeric( $value ) && (int) $value == $value );
                break;
            case 'number':
                $valid = is_numeric( $value );
                break;
            case 'boolean':
                $valid = is_bool( $value );
                break;
            case 'array':
                $valid = is_array( $value ) && ! self::is_assoc( $value );
                break;
            case 'object':
                $valid = is_array( $value ) && ( empty( $value ) || self::is_assoc( $value ) );
                break;
            default:
                $valid = true;
                break;
        }

        if ( ! $valid ) {
            return self::error( 'invalid_type', $field_path,
                sprintf( __( 'Field "%s" must be of type %s.', 'glimmr-ai' ), $field_path, $type )
            );
        }

        return true;
    }

    /**
     * Validate array items against items schema.
     *
     * @param array  $value       The array value.
     * @param array  $field_schema The field schema with 'items'.
     * @param string $field_path  Path for error reporting.
     * @return array|true True if valid, or error array.
     */
    private static function validate_array_items( $value, $field_schema, $field_path ) {
        $items_schema = $field_schema['items'];

        foreach ( $value as $index => $item ) {
            $item_path = "{$field_path}[{$index}]";

            // Validate item type.
            if ( isset( $items_schema['type'] ) ) {
                $type_error = self::validate_type( $item, $items_schema, $item_path );
                if ( is_array( $type_error ) ) {
                    return $type_error;
                }
            }

            // Validate nested properties for object items.
            if ( isset( $items_schema['properties'] ) && is_array( $item ) ) {
                $nested_result = self::validate( $item, $items_schema['properties'], $item_path );
                if ( is_array( $nested_result ) ) {
                    return $nested_result;
                }
            }
        }

        return true;
    }

    /**
     * Validate numeric and string constraints.
     *
     * @param mixed  $value       The value to validate.
     * @param array  $field_schema The field schema.
     * @param string $field_path  Path for error reporting.
     * @return array|true True if valid, or error array.
     */
    private static function validate_constraints( $value, $field_schema, $field_path ) {
        // Minimum for numbers.
        if ( isset( $field_schema['minimum'] ) && is_numeric( $value ) ) {
            if ( $value < $field_schema['minimum'] ) {
                return self::error( 'below_minimum', $field_path,
                    sprintf( __( 'Field "%s" must be at least %s.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['minimum']
                    )
                );
            }
        }

        // Maximum for numbers.
        if ( isset( $field_schema['maximum'] ) && is_numeric( $value ) ) {
            if ( $value > $field_schema['maximum'] ) {
                return self::error( 'above_maximum', $field_path,
                    sprintf( __( 'Field "%s" must be at most %s.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['maximum']
                    )
                );
            }
        }

        // minItems for arrays.
        if ( isset( $field_schema['minItems'] ) && is_array( $value ) ) {
            if ( count( $value ) < $field_schema['minItems'] ) {
                return self::error( 'too_few_items', $field_path,
                    sprintf( __( 'Field "%s" must have at least %d items.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['minItems']
                    )
                );
            }
        }

        // maxItems for arrays.
        if ( isset( $field_schema['maxItems'] ) && is_array( $value ) ) {
            if ( count( $value ) > $field_schema['maxItems'] ) {
                return self::error( 'too_many_items', $field_path,
                    sprintf( __( 'Field "%s" must have at most %d items.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['maxItems']
                    )
                );
            }
        }

        // minLength for strings.
        if ( isset( $field_schema['minLength'] ) && is_string( $value ) ) {
            if ( strlen( $value ) < $field_schema['minLength'] ) {
                return self::error( 'string_too_short', $field_path,
                    sprintf( __( 'Field "%s" must be at least %d characters.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['minLength']
                    )
                );
            }
        }

        // maxLength for strings.
        if ( isset( $field_schema['maxLength'] ) && is_string( $value ) ) {
            if ( strlen( $value ) > $field_schema['maxLength'] ) {
                return self::error( 'string_too_long', $field_path,
                    sprintf( __( 'Field "%s" must be at most %d characters.', 'glimmr-ai' ),
                        $field_path,
                        $field_schema['maxLength']
                    )
                );
            }
        }

        // pattern for strings (regex).
        if ( isset( $field_schema['pattern'] ) && is_string( $value ) ) {
            $result = @preg_match( '/' . $field_schema['pattern'] . '/', $value );
            if ( false === $result ) {
                // Invalid regex pattern in schema - log and fail open for schema errors.
                Glimmr_AI_Logger::warning(
                    'Invalid regex pattern in tool schema',
                    array( 'pattern' => $field_schema['pattern'], 'field' => $field_path ),
                    'validation'
                );
                // Don't reject value for schema errors.
            } elseif ( ! $result ) {
                return self::error( 'pattern_mismatch', $field_path,
                    sprintf( __( 'Field "%s" does not match the required pattern.', 'glimmr-ai' ),
                        $field_path
                    )
                );
            }
        }

        return true;
    }

    /**
     * Check if array is associative.
     *
     * @param array $arr Array to check.
     * @return bool True if associative.
     */
    private static function is_assoc( $arr ) {
        if ( empty( $arr ) ) {
            return false;
        }
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    /**
     * Create a validation error.
     *
     * @param string $code        Error code.
     * @param string $field_path  Field path.
     * @param string $message     Error message.
     * @param array  $suggestions Optional suggestions.
     * @return array Error structure.
     */
    private static function error( $code, $field_path, $message, $suggestions = array() ) {
        return array(
            'success'     => false,
            'error'       => $code,
            'field'       => $field_path,
            'message'     => $message,
            'suggestions' => $suggestions,
        );
    }

    /**
     * Check if a result is a validation error.
     *
     * @param mixed $result The result to check.
     * @return bool True if it's an error.
     */
    public static function is_error( $result ) {
        return is_array( $result ) && isset( $result['success'] ) && false === $result['success'];
    }
}
