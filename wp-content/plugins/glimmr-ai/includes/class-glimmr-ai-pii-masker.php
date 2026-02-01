<?php
/**
 * PII Masker
 *
 * Utility class for masking Personally Identifiable Information (PII)
 * to prevent exposure in AI context, logs, and stored messages.
 *
 * S10: PII masking - mask emails, phones, addresses before storage/exposure.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_PII_Masker
 *
 * Provides static methods for masking various types of PII.
 */
class Glimmr_AI_PII_Masker {

    /**
     * Mask an email address for privacy.
     *
     * Transforms "john.doe@example.com" to "j***@example.com"
     *
     * @param string $email Email address.
     * @return string Masked email.
     */
    public static function mask_email( $email ) {
        if ( empty( $email ) ) {
            return '';
        }

        $parts = explode( '@', $email );
        if ( count( $parts ) !== 2 ) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        // Show first character, mask the rest with up to 3 asterisks.
        $masked_local = substr( $local, 0, 1 ) . str_repeat( '*', min( 3, strlen( $local ) - 1 ) );

        return $masked_local . '@' . $domain;
    }

    /**
     * Mask a phone number for privacy.
     *
     * Transforms "(555) 123-4567" to "***-***-4567"
     *
     * @param string $phone Phone number.
     * @return string Masked phone.
     */
    public static function mask_phone( $phone ) {
        if ( empty( $phone ) ) {
            return '';
        }

        // Extract just the digits.
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        // preg_replace returns null on error.
        if ( null === $digits || strlen( $digits ) < 4 ) {
            return '***';
        }

        // Show only last 4 digits.
        $last4 = substr( $digits, -4 );
        return '***-***-' . $last4;
    }

    /**
     * Mask a street address for privacy.
     *
     * Transforms "123 Main Street" to "*** Main Street"
     *
     * @param string $address Street address.
     * @return string Masked address.
     */
    public static function mask_street_address( $address ) {
        if ( empty( $address ) ) {
            return '';
        }

        // Replace leading numbers/unit numbers with asterisks.
        $result = preg_replace( '/^\d+\s*/', '*** ', $address );
        // preg_replace returns null on error.
        return ( null !== $result ) ? $result : '*** ' . $address;
    }

    /**
     * Mask a credit card number.
     *
     * Transforms "4111111111111111" to "****-****-****-1111"
     *
     * @param string $card_number Credit card number.
     * @return string Masked card number.
     */
    public static function mask_card_number( $card_number ) {
        if ( empty( $card_number ) ) {
            return '';
        }

        // Extract just the digits.
        $digits = preg_replace( '/[^0-9]/', '', $card_number );
        // preg_replace returns null on error.
        if ( null === $digits || strlen( $digits ) < 4 ) {
            return '****';
        }

        // Show only last 4 digits.
        $last4 = substr( $digits, -4 );
        return '****-****-****-' . $last4;
    }

    /**
     * Mask a postal/zip code for privacy.
     *
     * Transforms "12345" to "***45" (shows last 2 digits for matching)
     *
     * @param string $postcode Postal/zip code.
     * @return string Masked postcode.
     */
    public static function mask_postcode( $postcode ) {
        if ( empty( $postcode ) ) {
            return '';
        }

        // Remove non-alphanumeric.
        $clean = preg_replace( '/[^a-zA-Z0-9]/', '', $postcode );
        // preg_replace returns null on error.
        if ( null === $clean || strlen( $clean ) < 3 ) {
            return '***';
        }

        // Show only last 2 characters.
        $last2 = substr( $clean, -2 );
        return str_repeat( '*', strlen( $clean ) - 2 ) . $last2;
    }

    /**
     * Mask PII in a text message.
     *
     * Searches for and masks common PII patterns:
     * - Email addresses
     * - Phone numbers
     * - Credit card numbers
     *
     * @param string $text Text to process.
     * @return string Text with PII masked.
     */
    public static function mask_text( $text ) {
        if ( empty( $text ) ) {
            return '';
        }

        // Store original text in case regex operations fail.
        $original_text = $text;

        // Mask email addresses.
        $result = preg_replace_callback(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            function( $matches ) {
                return self::mask_email( $matches[0] );
            },
            $text
        );
        // Check for regex error - if null, keep previous text.
        $text = ( null === $result ) ? $text : $result;

        // Mask phone numbers (various formats).
        // Matches: (555) 123-4567, 555-123-4567, 555.123.4567, 5551234567, +1 555 123 4567.
        $result = preg_replace_callback(
            '/(?:\+\d{1,3}\s?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}/',
            function( $matches ) {
                return self::mask_phone( $matches[0] );
            },
            $text
        );
        $text = ( null === $result ) ? $text : $result;

        // Mask credit card numbers (13-19 digits with optional separators).
        $result = preg_replace_callback(
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{1,7}\b/',
            function( $matches ) {
                return self::mask_card_number( $matches[0] );
            },
            $text
        );
        $text = ( null === $result ) ? $text : $result;

        // Log if any regex operation failed (text unchanged means possible issue).
        if ( preg_last_error() !== PREG_NO_ERROR ) {
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::warning(
                    'PII masking regex error',
                    array( 'preg_error' => preg_last_error() ),
                    'security'
                );
            }
        }

        return $text;
    }

    /**
     * Build a masked address from components.
     *
     * S11: Address privacy - returns only city, state, country.
     *
     * @param array $address Address array with city, state, country, etc.
     * @return array Masked address with only safe fields.
     */
    public static function mask_address_components( $address ) {
        if ( empty( $address ) || ! is_array( $address ) ) {
            return array();
        }

        // S11: Only include city, state, country - no street addresses or postcode.
        $masked = array();

        if ( ! empty( $address['city'] ) ) {
            $masked['city'] = $address['city'];
        }

        if ( ! empty( $address['state'] ) ) {
            $masked['state'] = $address['state'];
        }

        if ( ! empty( $address['country'] ) ) {
            $masked['country'] = $address['country'];
        }

        // Build formatted string.
        $parts = array_filter( array_values( $masked ) );
        if ( ! empty( $parts ) ) {
            $masked['formatted'] = implode( ', ', $parts );
        }

        return $masked;
    }

    /**
     * Check if text contains potential PII.
     *
     * Useful for logging/analytics to flag messages that may contain sensitive data.
     *
     * @param string $text Text to check.
     * @return bool True if potential PII detected.
     */
    public static function contains_pii( $text ) {
        if ( empty( $text ) ) {
            return false;
        }

        // Check for email pattern.
        if ( preg_match( '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text ) ) {
            return true;
        }

        // Check for phone pattern.
        if ( preg_match( '/(?:\+\d{1,3}\s?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}/', $text ) ) {
            return true;
        }

        // Check for credit card pattern.
        if ( preg_match( '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{1,7}\b/', $text ) ) {
            return true;
        }

        return false;
    }
}
