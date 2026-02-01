/**
 * Engagement Tab
 *
 * Proactive engagement trigger settings for time-on-page,
 * exit intent, scroll depth, abandoned cart, and idle engagement triggers.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 * @updated 1.9.0 - Added abandoned cart and idle engagement triggers
 */

const {
    ToggleControl,
    TextControl,
    TextareaControl,
    RangeControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * Page type options for targeting.
 */
const PAGE_TYPE_OPTIONS = [
    { label: 'Product Pages', value: 'product' },
    { label: 'Category/Archive Pages', value: 'category' },
    { label: 'Shop Page', value: 'shop' },
    { label: 'Cart Page', value: 'cart' },
    { label: 'Checkout Page', value: 'checkout' },
    { label: 'Home Page', value: 'home' },
    { label: 'Other Pages', value: 'other' },
];

/**
 * Multi-select checkboxes for page types.
 */
const PageTypeSelector = ({ value = [], onChange, label }) => {
    const handleToggle = (pageType) => {
        const newValue = value.includes(pageType)
            ? value.filter((p) => p !== pageType)
            : [...value, pageType];
        onChange(newValue);
    };

    return (
        <div className="glimmr-page-selector">
            <p className="components-base-control__label">{label}</p>
            <div className="glimmr-page-selector-grid">
                {PAGE_TYPE_OPTIONS.map((option) => (
                    <label key={option.value} className="glimmr-page-checkbox">
                        <input
                            type="checkbox"
                            checked={value.includes(option.value)}
                            onChange={() => handleToggle(option.value)}
                        />
                        <span>{option.label}</span>
                    </label>
                ))}
            </div>
        </div>
    );
};

/**
 * EngagementTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const EngagementTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="info" title="What is Proactive Engagement?">
            Instead of waiting for customers to open the chat, proactive engagement automatically opens the widget and starts a conversation based on visitor behavior.
            This can significantly increase chat engagement and conversions - but use it thoughtfully to avoid annoying visitors.
        </InfoBox>

        {/* Time-on-Page Trigger */}
        <SettingsSection
            title="Time-on-Page Trigger"
            description="Engage visitors who have been browsing your page for a while without interaction."
        >
            <ToggleControl
                label="Enable Time Trigger"
                checked={settings.proactive_time_enabled === true}
                onChange={(value) => onChange('proactive_time_enabled', value)}
                help={
                    <HelpText>
                        Automatically open the chat after a visitor spends a set amount of time on a page. Good for engaging browsers who might need help.
                    </HelpText>
                }
            />

            {settings.proactive_time_enabled && (
                <>
                    <RangeControl
                        label={`Delay: ${settings.proactive_time_delay || 30} seconds`}
                        value={settings.proactive_time_delay || 30}
                        onChange={(value) => onChange('proactive_time_delay', value)}
                        min={5}
                        max={120}
                        step={5}
                        help={
                            <HelpText type="tip">
                                How long to wait before triggering. 15-45 seconds works well - too short feels pushy, too long may miss the moment.
                            </HelpText>
                        }
                    />

                    <TextareaControl
                        label="Proactive Message"
                        value={settings.proactive_time_message || 'Hi there! Need help finding anything?'}
                        onChange={(value) => onChange('proactive_time_message', value)}
                        help={
                            <HelpText>
                                Message shown when the trigger fires. Keep it friendly and helpful, not salesy.
                            </HelpText>
                        }
                        rows={2}
                    />

                    <PageTypeSelector
                        label="Trigger on Page Types"
                        value={settings.proactive_time_pages || ['product', 'category', 'shop']}
                        onChange={(value) => onChange('proactive_time_pages', value)}
                    />
                </>
            )}
        </SettingsSection>

        {/* Exit-Intent Trigger */}
        <SettingsSection
            title="Exit-Intent Trigger"
            description="Catch visitors who are about to leave your site with a last-chance engagement."
        >
            <ToggleControl
                label="Enable Exit-Intent Trigger"
                checked={settings.proactive_exit_enabled === true}
                onChange={(value) => onChange('proactive_exit_enabled', value)}
                help={
                    <HelpText type="tip">
                        Detects when a visitor moves their mouse toward the browser's back/close button (desktop) or scrolls up rapidly near the top of the page (mobile).
                    </HelpText>
                }
            />

            {settings.proactive_exit_enabled && (
                <>
                    <TextareaControl
                        label="Exit Message"
                        value={settings.proactive_exit_message || 'Wait! Before you go, is there anything I can help you with?'}
                        onChange={(value) => onChange('proactive_exit_message', value)}
                        help={
                            <HelpText>
                                Message shown when exit intent is detected. This is your last chance to engage!
                            </HelpText>
                        }
                        rows={2}
                    />

                    <ToggleControl
                        label="Once Per Session"
                        checked={settings.proactive_exit_once_per_session !== false}
                        onChange={(value) => onChange('proactive_exit_once_per_session', value)}
                        help={
                            <HelpText type="warning">
                                Strongly recommended. Multiple exit-intent popups in one session are very annoying to visitors.
                            </HelpText>
                        }
                    />

                    <PageTypeSelector
                        label="Trigger on Page Types"
                        value={settings.proactive_exit_pages || ['cart', 'product']}
                        onChange={(value) => onChange('proactive_exit_pages', value)}
                    />
                </>
            )}
        </SettingsSection>

        {/* Scroll-Depth Trigger */}
        <SettingsSection
            title="Scroll-Depth Trigger"
            description="Engage visitors who have scrolled through a significant portion of your content."
        >
            <ToggleControl
                label="Enable Scroll Trigger"
                checked={settings.proactive_scroll_enabled === true}
                onChange={(value) => onChange('proactive_scroll_enabled', value)}
                help={
                    <HelpText>
                        Triggers when a visitor has scrolled past a certain percentage of the page. Indicates they're engaged with your content.
                    </HelpText>
                }
            />

            {settings.proactive_scroll_enabled && (
                <>
                    <RangeControl
                        label={`Scroll Depth: ${settings.proactive_scroll_percent || 50}%`}
                        value={settings.proactive_scroll_percent || 50}
                        onChange={(value) => onChange('proactive_scroll_percent', value)}
                        min={25}
                        max={90}
                        step={5}
                        help={
                            <HelpText>
                                Trigger when visitor has scrolled this percentage of the page. 50% works well for most pages.
                            </HelpText>
                        }
                    />

                    <TextareaControl
                        label="Scroll Message"
                        value={settings.proactive_scroll_message || 'Enjoying what you see? Let me help you find the perfect item!'}
                        onChange={(value) => onChange('proactive_scroll_message', value)}
                        help={
                            <HelpText>
                                Message shown when scroll depth is reached.
                            </HelpText>
                        }
                        rows={2}
                    />

                    <PageTypeSelector
                        label="Trigger on Page Types"
                        value={settings.proactive_scroll_pages || ['product', 'category']}
                        onChange={(value) => onChange('proactive_scroll_pages', value)}
                    />
                </>
            )}
        </SettingsSection>

        {/* Abandoned Cart Trigger */}
        <SettingsSection
            title="Abandoned Cart Recovery"
            description="Re-engage visitors who have items in their cart but seem to be abandoning."
        >
            <ToggleControl
                label="Enable Abandoned Cart Trigger"
                checked={settings.abandoned_cart_enabled === true}
                onChange={(value) => onChange('abandoned_cart_enabled', value)}
                help={
                    <HelpText type="tip">
                        This is one of the highest-value triggers! Catching abandoning customers and offering help or a discount can significantly boost conversions.
                    </HelpText>
                }
            />

            {settings.abandoned_cart_enabled && (
                <>
                    <RangeControl
                        label={`Inactivity Delay: ${settings.abandoned_cart_inactivity_delay || 60} seconds`}
                        value={settings.abandoned_cart_inactivity_delay || 60}
                        onChange={(value) => onChange('abandoned_cart_inactivity_delay', value)}
                        min={30}
                        max={300}
                        step={15}
                        help={
                            <HelpText>
                                How long the user must be inactive (no clicks, scrolls, or typing) before triggering. 45-90 seconds is recommended.
                            </HelpText>
                        }
                    />

                    <div className="glimmr-number-controls-row" style={{ display: 'flex', gap: '16px', marginBottom: '16px' }}>
                        <div style={{ flex: 1 }}>
                            <TextControl
                                label="Minimum Cart Value ($)"
                                type="number"
                                value={settings.abandoned_cart_min_value || 0}
                                onChange={(value) => onChange('abandoned_cart_min_value', parseFloat(value) || 0)}
                                min={0}
                                step={0.01}
                            />
                            <p className="components-base-control__help" style={{ marginTop: '4px' }}>
                                <HelpText>Only trigger if cart is worth at least this amount. Set to 0 for any cart value.</HelpText>
                            </p>
                        </div>
                        <div style={{ flex: 1 }}>
                            <TextControl
                                label="Minimum Cart Items"
                                type="number"
                                value={settings.abandoned_cart_min_items || 1}
                                onChange={(value) => onChange('abandoned_cart_min_items', parseInt(value, 10) || 1)}
                                min={1}
                                max={20}
                            />
                            <p className="components-base-control__help" style={{ marginTop: '4px' }}>
                                <HelpText>Only trigger if cart has at least this many items.</HelpText>
                            </p>
                        </div>
                    </div>

                    <TextareaControl
                        label="Abandoned Cart Message"
                        value={settings.abandoned_cart_message || 'I noticed you have items in your cart. Would you like help completing your order?'}
                        onChange={(value) => onChange('abandoned_cart_message', value)}
                        help={
                            <HelpText>
                                Message shown when abandoned cart is detected. Offer help, not pressure.
                            </HelpText>
                        }
                        rows={2}
                    />

                    <ToggleControl
                        label="Include Cart Items in Message"
                        checked={settings.abandoned_cart_include_items !== false}
                        onChange={(value) => onChange('abandoned_cart_include_items', value)}
                        help={
                            <HelpText>
                                Show a preview of cart items in the proactive message. Reminds customers what they're leaving behind.
                            </HelpText>
                        }
                    />

                    <ToggleControl
                        label="Offer Discount Coupon"
                        checked={settings.abandoned_cart_offer_coupon === true}
                        onChange={(value) => onChange('abandoned_cart_offer_coupon', value)}
                        help={
                            <HelpText type="tip">
                                Including a coupon code can significantly increase conversion. Even 5-10% off can make the difference.
                            </HelpText>
                        }
                    />

                    {settings.abandoned_cart_offer_coupon && (
                        <TextControl
                            label="Coupon Code"
                            value={settings.abandoned_cart_coupon_code || ''}
                            onChange={(value) => onChange('abandoned_cart_coupon_code', value)}
                            placeholder="e.g., SAVE10"
                            help={
                                <HelpText type="warning">
                                    Enter a valid coupon code that exists in WooCommerce → Coupons. The AI will mention this code in the message.
                                </HelpText>
                            }
                        />
                    )}

                    <ToggleControl
                        label="Once Per Session"
                        checked={settings.abandoned_cart_once_per_session !== false}
                        onChange={(value) => onChange('abandoned_cart_once_per_session', value)}
                        help={
                            <HelpText>
                                Only trigger once per browser session. Recommended to avoid annoying returning browsers.
                            </HelpText>
                        }
                    />

                    <PageTypeSelector
                        label="Trigger on Page Types"
                        value={settings.abandoned_cart_pages || ['cart', 'checkout', 'product']}
                        onChange={(value) => onChange('abandoned_cart_pages', value)}
                    />
                </>
            )}
        </SettingsSection>

        {/* Idle Engagement Trigger */}
        <SettingsSection
            title="Idle Engagement"
            description="Engage visitors who are browsing but haven't interacted for a while."
        >
            <ToggleControl
                label="Enable Idle Engagement Trigger"
                checked={settings.idle_engagement_enabled === true}
                onChange={(value) => onChange('idle_engagement_enabled', value)}
                help={
                    <HelpText>
                        Offers help to visitors who are browsing but idle. Great for engaging shoppers who haven't found what they're looking for.
                    </HelpText>
                }
            />

            {settings.idle_engagement_enabled && (
                <>
                    <RangeControl
                        label={`Idle Time: ${settings.idle_engagement_delay || 45} seconds`}
                        value={settings.idle_engagement_delay || 45}
                        onChange={(value) => onChange('idle_engagement_delay', value)}
                        min={20}
                        max={180}
                        step={5}
                        help={
                            <HelpText>
                                How long the user must be inactive before triggering. 30-60 seconds works well.
                            </HelpText>
                        }
                    />

                    <TextareaControl
                        label="Idle Engagement Message"
                        value={settings.idle_engagement_message || 'Is there anything I can help you with today?'}
                        onChange={(value) => onChange('idle_engagement_message', value)}
                        help={
                            <HelpText>
                                Friendly message to offer assistance. Keep it open-ended and helpful.
                            </HelpText>
                        }
                        rows={2}
                    />

                    <ToggleControl
                        label="Only When Cart is Empty"
                        checked={settings.idle_engagement_require_empty_cart === true}
                        onChange={(value) => onChange('idle_engagement_require_empty_cart', value)}
                        help={
                            <HelpText type="tip">
                                Prevents overlap with abandoned cart trigger. Use idle engagement for browsers, abandoned cart for shoppers.
                            </HelpText>
                        }
                    />

                    <ToggleControl
                        label="Once Per Session"
                        checked={settings.idle_engagement_once_per_session !== false}
                        onChange={(value) => onChange('idle_engagement_once_per_session', value)}
                        help={
                            <HelpText>
                                Only trigger once per browser session.
                            </HelpText>
                        }
                    />

                    <PageTypeSelector
                        label="Trigger on Page Types"
                        value={settings.idle_engagement_pages || ['shop', 'product', 'category']}
                        onChange={(value) => onChange('idle_engagement_pages', value)}
                    />
                </>
            )}
        </SettingsSection>

        {/* Tips Section */}
        <InfoBox type="tip" title="Best Practices for Proactive Engagement">
            <ul style={{ margin: 0, paddingLeft: '20px' }}>
                <li><strong>Don't be too aggressive</strong> - Use longer delays (30+ seconds) and limit to specific page types</li>
                <li><strong>Personalize messages</strong> - Reference what the visitor is looking at</li>
                <li><strong>Abandoned cart + discount = high conversion</strong> - Even a small discount can make the difference</li>
                <li><strong>Avoid overlap</strong> - Use "Only When Cart is Empty" for idle engagement to separate browser vs shopper triggers</li>
                <li><strong>Always use "Once Per Session"</strong> - Multiple triggers feel pushy and hurt your brand</li>
                <li><strong>Test and monitor</strong> - Check your analytics to see which triggers drive the most conversions</li>
            </ul>
        </InfoBox>
    </>
);

export default EngagementTab;
