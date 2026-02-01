/**
 * Integrations Tab
 *
 * Settings for GA4 tracking, WooCommerce reviews, and SEO plugin integration.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

const {
    TextControl,
    ToggleControl,
    RangeControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * IntegrationsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const IntegrationsTab = ({ settings, onChange }) => {
    return (
        <>
            {/* Google Analytics 4 Section */}
            <SettingsSection
                title="Google Analytics 4"
                description="Track chat interactions and conversions in Google Analytics 4 to measure your AI assistant's impact."
            >
                <ToggleControl
                    label="Enable GA4 Tracking"
                    checked={settings.ga4_enabled === true}
                    onChange={(value) => onChange('ga4_enabled', value)}
                    help={
                        <HelpText>
                            Send chat widget events to Google Analytics 4. Requires GA4 to already be installed on your site.
                        </HelpText>
                    }
                />

                {settings.ga4_enabled && (
                    <>
                        <TextControl
                            label="Measurement ID"
                            value={settings.ga4_measurement_id || ''}
                            onChange={(value) => onChange('ga4_measurement_id', value)}
                            placeholder="G-XXXXXXXXXX"
                            help={
                                <HelpText type="tip">
                                    Find this in GA4 → Admin → Data Streams → Your Stream. Starts with "G-".
                                </HelpText>
                            }
                        />

                        <div className="glimmr-settings-subgroup">
                            <p className="glimmr-settings-subgroup-title" style={{ fontWeight: 600, marginBottom: '12px' }}>Events to Track</p>

                            <ToggleControl
                                label="Widget Open/Close"
                                checked={settings.ga4_track_widget_open !== false}
                                onChange={(value) => onChange('ga4_track_widget_open', value)}
                                help={
                                    <HelpText>
                                        Track when users open and close the chat widget. Events: <code>glimmr_widget_open</code>, <code>glimmr_widget_close</code>
                                    </HelpText>
                                }
                            />

                            <ToggleControl
                                label="Messages Sent"
                                checked={settings.ga4_track_messages !== false}
                                onChange={(value) => onChange('ga4_track_messages', value)}
                                help={
                                    <HelpText>
                                        Track when users send messages. Event: <code>glimmr_message_sent</code>
                                    </HelpText>
                                }
                            />

                            <ToggleControl
                                label="Product Views"
                                checked={settings.ga4_track_products !== false}
                                onChange={(value) => onChange('ga4_track_products', value)}
                                help={
                                    <HelpText>
                                        Track when users view product details in the chat. Event: <code>glimmr_product_view</code>
                                    </HelpText>
                                }
                            />

                            <ToggleControl
                                label="Add to Cart"
                                checked={settings.ga4_track_cart !== false}
                                onChange={(value) => onChange('ga4_track_cart', value)}
                                help={
                                    <HelpText>
                                        Track when users add items to cart via the chat. Event: <code>glimmr_add_to_cart</code>
                                    </HelpText>
                                }
                            />

                            <ToggleControl
                                label="Checkout Start"
                                checked={settings.ga4_track_checkout !== false}
                                onChange={(value) => onChange('ga4_track_checkout', value)}
                                help={
                                    <HelpText>
                                        Track when users start checkout from the chat. Event: <code>glimmr_checkout_start</code>
                                    </HelpText>
                                }
                            />
                        </div>
                    </>
                )}
            </SettingsSection>

            {/* Product Reviews Section */}
            <SettingsSection
                title="Product Reviews"
                description="Let the AI access and discuss product reviews with customers."
            >
                <ToggleControl
                    label="Show Reviews in Product Modal"
                    checked={settings.reviews_enabled !== false}
                    onChange={(value) => onChange('reviews_enabled', value)}
                    help={
                        <HelpText>
                            Display recent customer reviews when viewing product details in the chat. Great for social proof!
                        </HelpText>
                    }
                />

                {settings.reviews_enabled !== false && (
                    <>
                        <RangeControl
                            label={`Reviews to Display: ${settings.reviews_count || 3}`}
                            value={settings.reviews_count || 3}
                            onChange={(value) => onChange('reviews_count', value)}
                            min={1}
                            max={10}
                            step={1}
                            help={
                                <HelpText>
                                    Number of recent reviews to show in product detail modal. More reviews = more social proof but longer loading.
                                </HelpText>
                            }
                        />

                        <RangeControl
                            label={`Minimum Rating Filter: ${settings.reviews_min_rating || 0} stars`}
                            value={settings.reviews_min_rating || 0}
                            onChange={(value) => onChange('reviews_min_rating', value)}
                            min={0}
                            max={5}
                            step={1}
                            help={
                                <HelpText type="tip">
                                    Only show reviews with this rating or higher. Set to 0 to show all reviews. Set to 4+ to only show positive reviews.
                                </HelpText>
                            }
                        />
                    </>
                )}
            </SettingsSection>

            {/* SEO Integration Section */}
            <SettingsSection
                title="SEO Integration"
                description="Enhance search engine visibility by integrating with popular SEO plugins."
            >
                <ToggleControl
                    label="Enable SEO Integration"
                    checked={settings.seo_integration_enabled === true}
                    onChange={(value) => onChange('seo_integration_enabled', value)}
                    help={
                        <HelpText>
                            Enable integration with Yoast SEO and Rank Math plugins for structured data and sitemap integration.
                        </HelpText>
                    }
                />

                {settings.seo_integration_enabled && (
                    <>
                        <ToggleControl
                            label="Add FAQ Schema to Product Pages"
                            checked={settings.seo_faq_schema !== false}
                            onChange={(value) => onChange('seo_faq_schema', value)}
                            help={
                                <HelpText type="tip">
                                    Adds FAQ structured data to product pages using knowledge base entries. Can improve search appearance with FAQ snippets in Google results.
                                </HelpText>
                            }
                        />

                        <ToggleControl
                            label="Include Knowledge Base in Sitemap"
                            checked={settings.seo_index_knowledge === true}
                            onChange={(value) => onChange('seo_index_knowledge', value)}
                            help={
                                <HelpText>
                                    Makes knowledge base entries discoverable by search engines. Only enable if your knowledge content is unique and valuable for SEO.
                                </HelpText>
                            }
                        />
                    </>
                )}
            </SettingsSection>
        </>
    );
};

export default IntegrationsTab;
