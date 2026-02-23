<?php
/**
 * Check Gift Card Balance Tool
 *
 * Checks gift card balance using native plugin APIs for supported gift card plugins.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Check_Gift_Card_Balance
 *
 * Allows customers to check gift card balance using native plugin APIs.
 * Supports PW WooCommerce Gift Cards, YITH WooCommerce Gift Cards,
 * WooCommerce Gift Cards (Official), and Smart Coupons.
 */
class Glimmr_AI_Tool_Check_Gift_Card_Balance extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'check_gift_card_balance';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Check the balance of a gift card by its code/number. Returns the current balance and currency.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'card_number' => array(
            'type'        => 'string',
            'description' => 'The gift card number/code to check',
            'required'    => true,
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $wc_check = $this->require_wc();
        if ( $wc_check ) {
            return $wc_check;
        }

        $card_number = $this->get_string_arg( $arguments, 'card_number' );

        if ( empty( $card_number ) ) {
            return $this->format_error(
                'missing_card_number',
                __( 'Please provide the gift card number/code to check.', 'glimmr-ai' )
            );
        }

        // Sanitize card number (uppercase, trim).
        $card_number = strtoupper( trim( $card_number ) );

        // Detect which gift card plugin is active and check balance.
        $result = $this->check_balance( $card_number );

        return $result;
    }

    /**
     * Check gift card balance using detected plugin.
     *
     * @param string $card_number The gift card code.
     * @return array Tool result.
     */
    private function check_balance( $card_number ) {
        // 1. PW WooCommerce Gift Cards.
        if ( class_exists( 'PW_Gift_Card' ) ) {
            return $this->check_pw_gift_cards( $card_number );
        }

        // 2. YITH WooCommerce Gift Cards.
        if ( function_exists( 'YITH_YWGC' ) || class_exists( 'YITH_WC_Gift_Card' ) ) {
            return $this->check_yith_gift_cards( $card_number );
        }

        // 3. WooCommerce Gift Cards (Official by Automattic).
        if ( class_exists( 'WC_GC_Gift_Card' ) || class_exists( 'WC_GC_Gift_Cards' ) ) {
            return $this->check_wc_gift_cards( $card_number );
        }

        // 4. Smart Coupons (store credit as coupon type).
        if ( class_exists( 'WC_Smart_Coupons' ) ) {
            return $this->check_smart_coupons( $card_number );
        }

        // No compatible plugin found.
        return $this->format_outcome(
            'no_plugin',
            array(),
            __( 'Gift card balance checking is not available. No compatible gift card plugin is installed.', 'glimmr-ai' )
        );
    }

    /**
     * Check balance using PW WooCommerce Gift Cards.
     *
     * @param string $card_number The gift card code.
     * @return array Tool result.
     */
    private function check_pw_gift_cards( $card_number ) {
        try {
            // PW Gift Cards uses PW_Gift_Card::get_by_card_number().
            /** @phpstan-ignore function.impossibleType */
            if ( method_exists( 'PW_Gift_Card', 'get_by_card_number' ) ) {
                /** @phpstan-ignore class.notFound */
                $gift_card = PW_Gift_Card::get_by_card_number( $card_number );

                if ( $gift_card && $gift_card->get_id() ) {
                    $balance = $gift_card->get_balance();

                    return $this->format_balance_result(
                        $card_number,
                        $balance,
                        'PW WooCommerce Gift Cards'
                    );
                }
            }
        } catch ( Exception $e ) {
            // Fall through to not found.
        }

        return $this->format_card_not_found( $card_number );
    }

    /**
     * Check balance using YITH WooCommerce Gift Cards.
     *
     * @param string $card_number The gift card code.
     * @return array Tool result.
     */
    private function check_yith_gift_cards( $card_number ) {
        try {
            // YITH uses YITH_YWGC()->get_gift_card_by_code() or direct class.
            if ( function_exists( 'YITH_YWGC' ) ) {
                $yith = YITH_YWGC();
                if ( method_exists( $yith, 'get_gift_card_by_code' ) ) {
                    $gift_card = $yith->get_gift_card_by_code( $card_number ); // @phpstan-ignore method.nonObject

                    if ( $gift_card && method_exists( $gift_card, 'get_balance' ) ) {
                        $balance = $gift_card->get_balance(); // @phpstan-ignore method.nonObject

                        return $this->format_balance_result(
                            $card_number,
                            $balance,
                            'YITH WooCommerce Gift Cards'
                        );
                    }
                }
            }

            // Alternative: Direct class lookup.
            if ( class_exists( 'YITH_WC_Gift_Card' ) ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $gift_card_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'gift_card' AND post_title = %s AND post_status = 'publish'",
                        $card_number
                    )
                );

                if ( $gift_card_id ) {
                    $gift_card = new YITH_WC_Gift_Card( array( 'ID' => $gift_card_id ) );
                    if ( method_exists( $gift_card, 'get_balance' ) ) {
                        $balance = $gift_card->get_balance();

                        return $this->format_balance_result(
                            $card_number,
                            $balance,
                            'YITH WooCommerce Gift Cards'
                        );
                    }
                }
            }
        } catch ( Exception $e ) {
            // Fall through to not found.
        }

        return $this->format_card_not_found( $card_number );
    }

    /**
     * Check balance using WooCommerce Gift Cards (Official).
     *
     * @param string $card_number The gift card code.
     * @return array Tool result.
     */
    private function check_wc_gift_cards( $card_number ) {
        try {
            // WC Gift Cards uses WC_GC_Gift_Cards::get_by_code().
            /** @phpstan-ignore function.impossibleType */
            if ( class_exists( 'WC_GC_Gift_Cards' ) && method_exists( 'WC_GC_Gift_Cards', 'get_by_code' ) ) {
                $gift_card = WC_GC_Gift_Cards::get_by_code( $card_number );

                if ( $gift_card && method_exists( $gift_card, 'get_balance' ) ) {
                    $balance = $gift_card->get_balance(); // @phpstan-ignore method.nonObject

                    return $this->format_balance_result(
                        $card_number,
                        $balance,
                        'WooCommerce Gift Cards'
                    );
                }
            }

            // Alternative: Direct class instantiation.
            if ( class_exists( 'WC_GC_Gift_Card' ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'woocommerce_gc_cards';

                // Check if table exists.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $table_exists = $wpdb->get_var(
                    $wpdb->prepare(
                        'SHOW TABLES LIKE %s',
                        $table_name
                    )
                );

                if ( $table_exists ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                    $card_data = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$table_name} WHERE code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $card_number
                        )
                    );

                    if ( $card_data && isset( $card_data->balance ) ) {
                        return $this->format_balance_result(
                            $card_number,
                            floatval( $card_data->balance ),
                            'WooCommerce Gift Cards'
                        );
                    }
                }
            }
        } catch ( Exception $e ) {
            // Fall through to not found.
        }

        return $this->format_card_not_found( $card_number );
    }

    /**
     * Check balance using Smart Coupons (store credit).
     *
     * @param string $card_number The gift card/store credit code.
     * @return array Tool result.
     */
    private function check_smart_coupons( $card_number ) {
        try {
            // Smart Coupons uses WooCommerce coupon system with 'smart_coupon' discount type.
            $coupon = new WC_Coupon( $card_number );

            if ( $coupon->get_id() > 0 ) {
                $discount_type = $coupon->get_discount_type();

                // Smart Coupons uses 'smart_coupon' type for store credit.
                if ( 'smart_coupon' === $discount_type ) {
                    $balance = $coupon->get_amount();

                    // Check if expired.
                    $expiry = $coupon->get_date_expires();
                    if ( $expiry && $expiry->getTimestamp() < time() ) {
                        return $this->format_outcome(
                            'expired',
                            array(
                                'card_number' => $this->mask_card_number( $card_number ),
                                'expired_on'  => $expiry->date_i18n( get_option( 'date_format' ) ),
                            ),
                            __( 'This gift card/store credit has expired.', 'glimmr-ai' )
                        );
                    }

                    return $this->format_balance_result(
                        $card_number,
                        $balance,
                        'Smart Coupons'
                    );
                }
            }
        } catch ( Exception $e ) {
            // Fall through to not found.
        }

        return $this->format_card_not_found( $card_number );
    }

    /**
     * Format a successful balance result.
     *
     * @param string $card_number The gift card code.
     * @param float  $balance     The balance amount.
     * @param string $plugin_name The plugin name for context.
     * @return array Tool result.
     */
    private function format_balance_result( $card_number, $balance, $plugin_name ) {
        $formatted_balance = $this->format_price( $balance );

        return $this->format_outcome(
            'found',
            array(
                'card_number'       => $this->mask_card_number( $card_number ),
                'balance'           => $balance,
                'balance_formatted' => $formatted_balance,
                'currency'          => get_woocommerce_currency(),
            ),
            sprintf(
                /* translators: %s: formatted balance amount */
                __( 'Your gift card balance is %s.', 'glimmr-ai' ),
                $formatted_balance
            )
        );
    }

    /**
     * Format a gift card not found result.
     *
     * @param string $card_number The gift card code.
     * @return array Tool result.
     */
    private function format_card_not_found( $card_number ) {
        return $this->format_outcome(
            'not_found',
            array(
                'card_number' => $this->mask_card_number( $card_number ),
            ),
            __( 'Gift card not found. Please check the code and try again.', 'glimmr-ai' )
        );
    }

    /**
     * Mask gift card number for privacy (show last 4 chars).
     *
     * @param string $card_number The full card number.
     * @return string Masked card number.
     */
    private function mask_card_number( $card_number ) {
        $length = strlen( $card_number );
        if ( $length <= 4 ) {
            return $card_number;
        }

        return str_repeat( '*', $length - 4 ) . substr( $card_number, -4 );
    }
}
