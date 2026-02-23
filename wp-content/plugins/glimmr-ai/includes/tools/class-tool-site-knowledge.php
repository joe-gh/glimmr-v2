<?php
/**
 * Site Knowledge Tool
 *
 * Provides information about store policies, shipping, returns,
 * contact information, and other site-specific knowledge.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Site_Knowledge
 *
 * Retrieves site-specific information from WooCommerce settings,
 * WordPress pages, and custom knowledge base entries.
 */
class Glimmr_AI_Tool_Site_Knowledge extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'site_knowledge';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get information about the store including shipping options, return policy, payment methods, contact information, store hours, and other policies. Use this when customers ask about how the store operates.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'topic' => array(
            'type'        => 'string',
            'description' => 'The topic to get information about: shipping, returns, payments, contact, hours, policies, faq, about',
            'required'    => true,
            'enum'        => array( 'shipping', 'returns', 'payments', 'contact', 'hours', 'policies', 'faq', 'about', 'all' ),
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $topic = $this->get_string_arg( $arguments, 'topic', 'all' );

        $info = array();

        switch ( $topic ) {
            case 'shipping':
                $info = $this->get_shipping_info();
                break;

            case 'returns':
                $info = $this->get_returns_info();
                break;

            case 'payments':
                $info = $this->get_payment_info();
                break;

            case 'contact':
                $info = $this->get_contact_info();
                break;

            case 'hours':
                $info = $this->get_store_hours();
                break;

            case 'policies':
                $info = $this->get_policies();
                break;

            case 'faq':
                $info = $this->get_faq();
                break;

            case 'about':
                $info = $this->get_about_info();
                break;

            case 'all':
            default:
                $info = array(
                    'store_name' => get_bloginfo( 'name' ),
                    'shipping'   => $this->get_shipping_info(),
                    'returns'    => $this->get_returns_info(),
                    'payments'   => $this->get_payment_info(),
                    'contact'    => $this->get_contact_info(),
                );
                break;
        }

        return $this->format_result( $info, true );
    }

    /**
     * Get shipping information.
     *
     * @return array Shipping info.
     */
    private function get_shipping_info() {
        $info = array(
            'available_methods' => array(),
            'shipping_zones'    => array(),
            'free_shipping'     => array(),
        );

        if ( ! $this->is_wc_active() ) {
            return $info;
        }

        // Get shipping zones.
        $zones = WC_Shipping_Zones::get_zones();

        foreach ( $zones as $zone ) {
            $zone_data = array(
                'name'    => $zone['zone_name'],
                'regions' => $zone['formatted_zone_location'],
                'methods' => array(),
            );

            foreach ( $zone['shipping_methods'] as $method ) {
                if ( ! is_object( $method ) || ! $method->is_enabled() ) { // @phpstan-ignore method.notFound
                    continue;
                }

                $method_info = array(
                    'name' => $method->get_title(), // @phpstan-ignore method.notFound
                    'type' => $method->id, // @phpstan-ignore property.notFound
                );

                // Get cost if available.
                if ( method_exists( $method, 'get_option' ) ) {
                    $cost = $method->get_option( 'cost' );
                    if ( $cost ) {
                        $method_info['cost'] = $cost;
                    }

                    // Check for free shipping threshold.
                    if ( 'free_shipping' === $method->id ) { // @phpstan-ignore property.notFound
                        $min_amount = $method->get_option( 'min_amount' );
                        if ( $min_amount ) {
                            $method_info['min_order'] = $this->format_price( $min_amount );
                            $info['free_shipping'][] = array(
                                'zone'       => $zone['zone_name'],
                                'min_amount' => $this->format_price( $min_amount ),
                            );
                        }
                    }
                }

                $zone_data['methods'][] = $method_info;
                $info['available_methods'][] = $method_info['name'];
            }

            $info['shipping_zones'][] = $zone_data;
        }

        // Remove duplicates from available methods.
        $info['available_methods'] = array_unique( $info['available_methods'] );

        // Add estimated delivery from custom knowledge.
        $custom_shipping = $this->get_custom_knowledge( 'shipping' );
        if ( $custom_shipping ) {
            $info['additional_info'] = $custom_shipping;
        }

        return $info;
    }

    /**
     * Get returns and refund information.
     *
     * @return array Returns info.
     */
    private function get_returns_info() {
        $info = array(
            'policy'      => '',
            'timeframe'   => '',
            'conditions'  => array(),
            'process'     => '',
            'refund_method' => '',
        );

        // Try to get from WooCommerce refund policy page.
        if ( $this->is_wc_active() ) {
            $refund_page_id = wc_get_page_id( 'refund_returns' );
            if ( $refund_page_id > 0 ) {
                $page = get_post( $refund_page_id );
                if ( $page ) {
                    $info['policy'] = wp_strip_all_tags( $page->post_content );
                    $policy_url = get_permalink( $refund_page_id );
                    if ( $policy_url ) {
                        $info['policy_url'] = $policy_url;
                    }
                }
            }
        }

        // Get from custom knowledge.
        $custom_returns = $this->get_custom_knowledge( 'returns' );
        if ( $custom_returns ) {
            $info['additional_info'] = $custom_returns;
        }

        // Default if nothing found.
        if ( empty( $info['policy'] ) && empty( $info['additional_info'] ) ) {
            $info['message'] = __( 'Please contact customer support for information about our return policy.', 'glimmr-ai' );
        }

        return $info;
    }

    /**
     * Get payment information.
     *
     * @return array Payment info.
     */
    private function get_payment_info() {
        $info = array(
            'accepted_methods' => array(),
            'security'         => __( 'All transactions are secure and encrypted.', 'glimmr-ai' ),
        );

        if ( ! $this->is_wc_active() ) {
            return $info;
        }

        $gateways = WC()->payment_gateways->get_available_payment_gateways();

        foreach ( $gateways as $gateway ) {
            if ( ! $gateway->is_available() ) {
                continue;
            }

            $method = array(
                'name'        => $gateway->get_title(),
                'description' => $gateway->get_description(),
            );

            // Add icons if available.
            $icons = $gateway->get_icon();
            if ( ! empty( $icons ) ) {
                // Extract image URLs from icon HTML.
                preg_match_all( '/src="([^"]+)"/', $icons, $matches );
                if ( ! empty( $matches[1] ) ) {
                    $method['icons'] = $matches[1];
                }
            }

            $info['accepted_methods'][] = $method;
        }

        return $info;
    }

    /**
     * Get contact information.
     *
     * S11: Use configurable support_email instead of exposing admin email.
     * Falls back to admin_email only if support email is not configured.
     *
     * @return array Contact info.
     */
    private function get_contact_info() {
        // S11: Use configurable support email instead of admin email.
        $support_email = Glimmr_AI_Settings::get( 'support_email', '' );
        if ( empty( $support_email ) ) {
            $support_email = get_option( 'admin_email' );
        }

        $info = array(
            'store_name' => get_bloginfo( 'name' ),
            'email'      => $support_email,
        );

        // Get from WooCommerce store settings.
        // S11: Only expose city/state/country, never street addresses.
        if ( $this->is_wc_active() ) {
            $info['address'] = array(
                'city'    => get_option( 'woocommerce_store_city' ),
                'state'   => get_option( 'woocommerce_store_state' ),
                'country' => WC()->countries->get_base_country(),
            );
        }

        // Get from custom knowledge.
        $custom_contact = $this->get_custom_knowledge( 'contact' );
        if ( $custom_contact ) {
            $info['additional_info'] = $custom_contact;
        }

        // Contact form URL.
        $contact_page = get_page_by_path( 'contact' );
        if ( ! $contact_page ) {
            $contact_page = get_page_by_path( 'contact-us' );
        }
        if ( $contact_page ) {
            $contact_url = get_permalink( $contact_page->ID );
            if ( $contact_url ) {
                $info['contact_page'] = $contact_url;
            }
        }

        return $info;
    }

    /**
     * Get store hours.
     *
     * @return array Store hours.
     */
    private function get_store_hours() {
        // Get from custom knowledge.
        $hours = $this->get_custom_knowledge( 'hours' );

        if ( $hours ) {
            return array(
                'hours' => $hours,
            );
        }

        return array(
            'message' => __( 'We\'re an online store available 24/7. Customer support responds during business hours.', 'glimmr-ai' ),
        );
    }

    /**
     * Get store policies.
     *
     * @return array Policies.
     */
    private function get_policies() {
        $policies = array();

        // Privacy policy.
        $privacy_page_id = get_option( 'wp_page_for_privacy_policy' );
        if ( $privacy_page_id ) {
            $privacy_url = get_permalink( $privacy_page_id );
            if ( $privacy_url ) {
                $policies['privacy_policy'] = array(
                    'title' => __( 'Privacy Policy', 'glimmr-ai' ),
                    'url'   => $privacy_url,
                );
            }
        }

        // Terms and conditions.
        if ( $this->is_wc_active() ) {
            $terms_page_id = wc_get_page_id( 'terms' );
            if ( $terms_page_id > 0 ) {
                $terms_url = get_permalink( $terms_page_id );
                if ( $terms_url ) {
                    $policies['terms'] = array(
                        'title' => __( 'Terms and Conditions', 'glimmr-ai' ),
                        'url'   => $terms_url,
                    );
                }
            }
        }

        return $policies;
    }

    /**
     * Get FAQ information.
     *
     * @return array FAQ.
     */
    private function get_faq() {
        // Get from custom knowledge.
        $faq = $this->get_custom_knowledge( 'faq' );

        if ( $faq ) {
            return array(
                'faq' => $faq,
            );
        }

        // Try to find FAQ page.
        $faq_page = get_page_by_path( 'faq' );
        if ( ! $faq_page ) {
            $faq_page = get_page_by_path( 'frequently-asked-questions' );
        }

        if ( $faq_page ) {
            $faq_data = array(
                'content' => wp_strip_all_tags( $faq_page->post_content ),
            );
            $faq_url = get_permalink( $faq_page->ID );
            if ( $faq_url ) {
                $faq_data['url'] = $faq_url;
            }
            return $faq_data;
        }

        return array(
            'message' => __( 'Please contact our support team for any questions.', 'glimmr-ai' ),
        );
    }

    /**
     * Get about information.
     *
     * @return array About info.
     */
    private function get_about_info() {
        $info = array(
            'store_name'  => get_bloginfo( 'name' ),
            'description' => get_bloginfo( 'description' ),
        );

        // Try to find About page.
        $about_page = get_page_by_path( 'about' );
        if ( ! $about_page ) {
            $about_page = get_page_by_path( 'about-us' );
        }

        if ( $about_page ) {
            $info['content'] = wp_trim_words( wp_strip_all_tags( $about_page->post_content ), 200 );
            $about_url = get_permalink( $about_page->ID );
            if ( $about_url ) {
                $info['url'] = $about_url;
            }
        }

        return $info;
    }

    /**
     * Get custom knowledge from database.
     *
     * @param string $topic Knowledge topic.
     * @return string|null Knowledge content.
     */
    private function get_custom_knowledge( $topic ) {
        global $wpdb;

        $table = $wpdb->prefix . 'glimmr_ai_knowledge';

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT content FROM {$table}
                 WHERE site_id = %d
                 AND type = 'custom'
                 AND (title LIKE %s OR source_type = %s)
                 LIMIT 1",
                get_current_blog_id(),
                '%' . $wpdb->esc_like( $topic ) . '%',
                $topic
            )
        );

        return $result ? wp_strip_all_tags( $result ) : null;
    }
}
