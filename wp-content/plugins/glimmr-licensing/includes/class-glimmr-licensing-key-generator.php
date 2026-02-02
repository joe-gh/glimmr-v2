<?php
/**
 * Cryptographically secure license key generator.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Key_Generator
 *
 * Generates license keys in the format GLMR-XXXX-XXXX-XXXX-XXXX using
 * cryptographically secure randomness. Uses an unambiguous character set
 * (no 0/O, 1/I/L confusion).
 */
class Glimmr_Licensing_Key_Generator {

    /**
     * Unambiguous character set: A-Z and 2-9, excluding 0, O, 1, I, L.
     *
     * 30 characters total: 2-9 (8) + A-H, J-K, M-N, P-T, V-Z (22)
     *
     * @var string
     */
    const CHARSET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Prefix for all license keys.
     *
     * @var string
     */
    const PREFIX = 'GLMR';

    /**
     * Number of segments (after prefix).
     *
     * @var int
     */
    const SEGMENTS = 4;

    /**
     * Characters per segment.
     *
     * @var int
     */
    const SEGMENT_LENGTH = 4;

    /**
     * Generate a unique license key.
     *
     * Format: GLMR-XXXX-XXXX-XXXX-XXXX
     * Uses random_bytes() for cryptographic entropy.
     * ~78 bits of entropy (4 segments x 4 chars x ~4.9 bits/char).
     *
     * @return string Generated license key.
     */
    public static function generate() {
        $charset     = self::CHARSET;
        $charset_len = strlen( $charset );
        $segments    = array( self::PREFIX );

        for ( $s = 0; $s < self::SEGMENTS; $s++ ) {
            $segment = '';
            $bytes   = random_bytes( self::SEGMENT_LENGTH );
            for ( $i = 0; $i < self::SEGMENT_LENGTH; $i++ ) {
                $index    = ord( $bytes[ $i ] ) % $charset_len;
                $segment .= $charset[ $index ];
            }
            $segments[] = $segment;
        }

        return implode( '-', $segments );
    }

    /**
     * Validate key format (does not check database existence).
     *
     * @param string $key License key to validate.
     * @return bool True if format is valid.
     */
    public static function is_valid_format( $key ) {
        // Must match GLMR-XXXX-XXXX-XXXX-XXXX where X is from the charset.
        $pattern = '/^GLMR-[' . preg_quote( self::CHARSET, '/' ) . ']{4}-[' . preg_quote( self::CHARSET, '/' ) . ']{4}-[' . preg_quote( self::CHARSET, '/' ) . ']{4}-[' . preg_quote( self::CHARSET, '/' ) . ']{4}$/';
        return (bool) preg_match( $pattern, strtoupper( $key ) );
    }
}
