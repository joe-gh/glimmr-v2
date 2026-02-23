<?php
/**
 * Plugin activator for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Activator
 *
 * Creates database tables, sets default options, and seeds subscription
 * products on activation.
 */
class Glimmr_Licensing_Activator {

    /**
     * Option key tracking whether seed products have been created.
     *
     * @var string
     */
    const PRODUCTS_SEEDED_OPTION = 'glimmr_licensing_products_seeded';

    /**
     * Subscription product definitions.
     *
     * @var array
     */
    const PRODUCT_DEFINITIONS = array(
        array(
            'name'              => 'Glimmr AI - Single',
            'slug'              => 'glimmr-ai-single',
            'short_description' => 'Glimmr AI Shopping Assistant for 1 site.',
            'price'             => '19',
            'plan'              => 'plan_1',
        ),
        array(
            'name'              => 'Glimmr AI - Growth',
            'slug'              => 'glimmr-ai-growth',
            'short_description' => 'Glimmr AI Shopping Assistant for up to 10 sites.',
            'price'             => '49',
            'plan'              => 'plan_10',
        ),
        array(
            'name'              => 'Glimmr AI - Agency',
            'slug'              => 'glimmr-ai-agency',
            'short_description' => 'Glimmr AI Shopping Assistant for up to 100 sites.',
            'price'             => '149',
            'plan'              => 'plan_100',
        ),
    );

    /**
     * Run activation tasks.
     *
     * @return void
     */
    public static function activate() {
        require_once GLIMMR_LICENSING_PLUGIN_DIR . 'includes/class-glimmr-licensing-database.php';
        Glimmr_Licensing_Database::create_tables();

        // Set default options.
        if ( false === get_option( 'glimmr_licensing_settings' ) ) {
            update_option( 'glimmr_licensing_settings', array(
                'rate_limit_per_minute' => 60,
                'auto_email_license'    => true,
            ) );
        }

        // Schedule daily cron.
        if ( ! wp_next_scheduled( 'glimmr_licensing_daily_check' ) ) {
            wp_schedule_event( time(), 'daily', 'glimmr_licensing_daily_check' );
        }

        // Register the My Account endpoint and flush rewrite rules so it works immediately.
        add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();

        // Flag that products need seeding. The actual creation happens on
        // admin_init when WooCommerce Subscriptions classes are available.
        if ( ! get_option( self::PRODUCTS_SEEDED_OPTION ) ) {
            update_option( 'glimmr_licensing_needs_product_seed', '1' );
        }
    }

    /**
     * Create the default WooCommerce Subscription products.
     *
     * Called from admin_init so all plugin classes are guaranteed to be loaded.
     * Runs once and sets a flag so it never runs again.
     *
     * @return void
     */
    public static function maybe_seed_products() {
        // Already seeded.
        if ( get_option( self::PRODUCTS_SEEDED_OPTION ) ) {
            return;
        }

        // Not flagged for seeding (fresh install hasn't activated yet).
        if ( ! get_option( 'glimmr_licensing_needs_product_seed' ) ) {
            return;
        }

        // WooCommerce Subscriptions must be active.
        if ( ! class_exists( 'WC_Product_Subscription' ) ) {
            set_transient( 'glimmr_licensing_wcs_missing_notice', true, 300 );
            return;
        }

        $product_ids = array();

        foreach ( self::PRODUCT_DEFINITIONS as $def ) {
            $product_id = self::create_subscription_product( $def );
            if ( $product_id ) {
                $product_ids[ $def['plan'] ] = $product_id;
            }
        }

        // Store the created product IDs for reference.
        update_option( self::PRODUCTS_SEEDED_OPTION, $product_ids );
        delete_option( 'glimmr_licensing_needs_product_seed' );
    }

    /**
     * Create a single WooCommerce Subscription product.
     *
     * @param array $def Product definition.
     * @return int|false Product ID on success, false on failure.
     */
    private static function create_subscription_product( $def ) {
        // Check if a product with this slug already exists.
        $existing = get_page_by_path( $def['slug'], OBJECT, 'product' );
        if ( $existing ) {
            return $existing->ID;
        }

        $product = new WC_Product_Subscription();

        $product->set_name( $def['name'] );
        $product->set_slug( $def['slug'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_short_description( $def['short_description'] );
        $product->set_regular_price( $def['price'] );
        $product->set_virtual( true );
        $product->set_sold_individually( true );

        // Subscription meta.
        $product->update_meta_data( '_subscription_price', $def['price'] );
        $product->update_meta_data( '_subscription_period', 'month' );
        $product->update_meta_data( '_subscription_period_interval', '1' );
        $product->update_meta_data( '_subscription_length', '0' );
        $product->update_meta_data( '_subscription_sign_up_fee', '' );
        $product->update_meta_data( '_subscription_trial_period', '' );
        $product->update_meta_data( '_subscription_trial_length', '0' );

        // Glimmr licensing meta.
        $product->update_meta_data( '_glimmr_licensing_enabled', 'yes' );
        $product->update_meta_data( '_glimmr_licensing_plan', $def['plan'] );

        $product_id = $product->save();

        return $product_id ?: false;
    }
}
