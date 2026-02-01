/**
 * Artifacts Tab
 *
 * Rich UI artifact display settings with section selector.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState } = wp.element;
const {
    SelectControl,
    ToggleControl,
    RangeControl,
    TextControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { ARTIFACT_SECTIONS } from '../settingsConfig';

/**
 * ArtifactsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const ArtifactsTab = ({ settings, onChange }) => {
    const [activeSection, setActiveSection] = useState('grid');

    const renderSectionContent = () => {
        switch (activeSection) {
            case 'grid':
                return (
                    <>
                        <p className="description">
                            Configure how product search results appear in the chat.
                        </p>

                        <SelectControl
                            label="Grid Columns"
                            value={settings.artifact_grid_columns || '2'}
                            options={[
                                { value: '2', label: '2 Columns' },
                                { value: '3', label: '3 Columns' },
                            ]}
                            onChange={(value) => onChange('artifact_grid_columns', value)}
                            help="Number of product columns in search results."
                        />

                        <SelectControl
                            label="Card Style"
                            value={settings.artifact_grid_card_style || 'detailed'}
                            options={[
                                { value: 'minimal', label: 'Minimal (image, name, price)' },
                                { value: 'detailed', label: 'Detailed (includes rating, stock)' },
                            ]}
                            onChange={(value) => onChange('artifact_grid_card_style', value)}
                        />

                        <ToggleControl
                            label="Show Star Ratings"
                            checked={settings.artifact_grid_show_rating !== false}
                            onChange={(value) => onChange('artifact_grid_show_rating', value)}
                            help="Display product ratings on cards."
                        />

                        <ToggleControl
                            label="Show Stock Status"
                            checked={settings.artifact_grid_show_stock !== false}
                            onChange={(value) => onChange('artifact_grid_show_stock', value)}
                            help="Show in stock / out of stock badges."
                        />

                        <ToggleControl
                            label="Show Add to Cart Button"
                            checked={settings.artifact_grid_show_add_to_cart !== false}
                            onChange={(value) => onChange('artifact_grid_show_add_to_cart', value)}
                            help="Display add to cart button on product cards."
                        />

                        <ToggleControl
                            label="Show Sale Badge"
                            checked={settings.artifact_grid_show_sale_badge !== false}
                            onChange={(value) => onChange('artifact_grid_show_sale_badge', value)}
                            help="Show sale/discount badge on products."
                        />
                    </>
                );

            case 'comparison':
                return (
                    <>
                        <p className="description">
                            Configure the side-by-side product comparison display.
                        </p>

                        <SelectControl
                            label="Comparison Layout"
                            value={settings.artifact_comparison_layout || 'table'}
                            options={[
                                { value: 'table', label: 'Table (side-by-side columns)' },
                                { value: 'cards', label: 'Stacked Cards (mobile-friendly)' },
                            ]}
                            onChange={(value) => onChange('artifact_comparison_layout', value)}
                        />

                        <ToggleControl
                            label="Highlight Best Values"
                            checked={settings.artifact_comparison_highlight_best !== false}
                            onChange={(value) => onChange('artifact_comparison_highlight_best', value)}
                            help="Highlight the best price, highest rating, etc."
                        />

                        <RangeControl
                            label={`Max Products to Compare: ${settings.artifact_comparison_max_products || 4}`}
                            value={settings.artifact_comparison_max_products || 4}
                            onChange={(value) => onChange('artifact_comparison_max_products', value)}
                            min={2}
                            max={6}
                            step={1}
                            help="Maximum products in a comparison (2-6)."
                        />

                        <ToggleControl
                            label="Show SKU"
                            checked={settings.artifact_comparison_show_sku !== false}
                            onChange={(value) => onChange('artifact_comparison_show_sku', value)}
                            help="Display product SKU in comparison."
                        />

                        <ToggleControl
                            label="Show Description"
                            checked={settings.artifact_comparison_show_description !== false}
                            onChange={(value) => onChange('artifact_comparison_show_description', value)}
                            help="Show short description in comparison."
                        />

                        <ToggleControl
                            label="Show Attributes"
                            checked={settings.artifact_comparison_show_attributes !== false}
                            onChange={(value) => onChange('artifact_comparison_show_attributes', value)}
                            help="Display product attributes (color, size, etc.)."
                        />
                    </>
                );

            case 'modal':
                return (
                    <>
                        <p className="description">
                            Settings for the product detail overlay.
                        </p>

                        <SelectControl
                            label="Image Display"
                            value={settings.artifact_modal_image_style || 'gallery'}
                            options={[
                                { value: 'gallery', label: 'Gallery (swipeable thumbnails)' },
                                { value: 'single', label: 'Single Image' },
                            ]}
                            onChange={(value) => onChange('artifact_modal_image_style', value)}
                        />

                        <ToggleControl
                            label="Show Reviews Summary"
                            checked={settings.artifact_modal_show_reviews !== false}
                            onChange={(value) => onChange('artifact_modal_show_reviews', value)}
                            help="Display rating summary and review count."
                        />

                        <ToggleControl
                            label="Show Full Description"
                            checked={settings.artifact_modal_show_description !== false}
                            onChange={(value) => onChange('artifact_modal_show_description', value)}
                            help="Display full product description."
                        />

                        <ToggleControl
                            label="Show Stock Quantity"
                            checked={settings.artifact_modal_show_stock_qty !== false}
                            onChange={(value) => onChange('artifact_modal_show_stock_qty', value)}
                            help="Show available stock quantity."
                        />

                        <ToggleControl
                            label="Enable Quantity Selector"
                            checked={settings.artifact_modal_quantity_selector !== false}
                            onChange={(value) => onChange('artifact_modal_quantity_selector', value)}
                            help="Allow changing quantity before adding to cart."
                        />
                    </>
                );

            case 'order':
                return (
                    <>
                        <p className="description">
                            Configure order status and history presentation.
                        </p>

                        <ToggleControl
                            label="Show Status Timeline"
                            checked={settings.artifact_order_show_timeline !== false}
                            onChange={(value) => onChange('artifact_order_show_timeline', value)}
                            help="Display visual order progress timeline."
                        />

                        <SelectControl
                            label="Timeline Style"
                            value={settings.artifact_order_timeline_style || 'horizontal'}
                            options={[
                                { value: 'horizontal', label: 'Horizontal' },
                                { value: 'vertical', label: 'Vertical' },
                            ]}
                            onChange={(value) => onChange('artifact_order_timeline_style', value)}
                        />

                        <ToggleControl
                            label="Show Order Items"
                            checked={settings.artifact_order_show_items !== false}
                            onChange={(value) => onChange('artifact_order_show_items', value)}
                            help="Show line items in order status card."
                        />

                        <ToggleControl
                            label="Show Tracking Link"
                            checked={settings.artifact_order_show_tracking !== false}
                            onChange={(value) => onChange('artifact_order_show_tracking', value)}
                            help="Display tracking number and link if available."
                        />

                        <div className="glimmr-settings-subsection">
                            <h4>Tracking Configuration</h4>
                            <p className="description">
                                Configure how tracking information is retrieved from orders.
                            </p>

                            <TextControl
                                label="Tracking Number Meta Key(s)"
                                value={settings.tracking_meta_number || ''}
                                onChange={(value) => onChange('tracking_meta_number', value)}
                                help="Comma-separated list of order meta keys to check for tracking numbers."
                                placeholder="e.g., my_tracking_number, _acf_tracking"
                            />

                            <SelectControl
                                label="Default Carrier"
                                value={settings.tracking_default_carrier || ''}
                                options={[
                                    { value: '', label: 'None (detect from meta)' },
                                    { value: 'usps', label: 'USPS' },
                                    { value: 'ups', label: 'UPS' },
                                    { value: 'fedex', label: 'FedEx' },
                                    { value: 'dhl', label: 'DHL' },
                                    { value: 'amazon', label: 'Amazon Logistics' },
                                    { value: 'ontrac', label: 'OnTrac' },
                                    { value: 'lasership', label: 'LaserShip' },
                                    { value: 'custom', label: 'Custom (enter URL template below)' },
                                ]}
                                onChange={(value) => onChange('tracking_default_carrier', value)}
                                help="Used when carrier is not found in order meta."
                            />

                            {settings.tracking_default_carrier === 'custom' && (
                                <TextControl
                                    label="Custom Tracking URL Template"
                                    value={settings.tracking_default_url_template || ''}
                                    onChange={(value) => onChange('tracking_default_url_template', value)}
                                    help="Use {tracking_number} as placeholder."
                                    placeholder="https://carrier.com/track/{tracking_number}"
                                />
                            )}

                            <TextControl
                                label="Carrier Meta Key(s)"
                                value={settings.tracking_meta_carrier || ''}
                                onChange={(value) => onChange('tracking_meta_carrier', value)}
                                help="Optional: Meta keys to check for carrier name."
                                placeholder="e.g., shipping_carrier, _carrier"
                            />

                            <TextControl
                                label="Tracking URL Meta Key(s)"
                                value={settings.tracking_meta_url || ''}
                                onChange={(value) => onChange('tracking_meta_url', value)}
                                help="Optional: Meta keys to check for full tracking URL."
                                placeholder="e.g., tracking_url, _tracking_link"
                            />
                        </div>

                        <RangeControl
                            label={`Order History Max Display: ${settings.artifact_history_max_display || 5}`}
                            value={settings.artifact_history_max_display || 5}
                            onChange={(value) => onChange('artifact_history_max_display', value)}
                            min={3}
                            max={10}
                            step={1}
                            help="Maximum orders to show in history list."
                        />

                        <ToggleControl
                            label="Show Item Thumbnails"
                            checked={settings.artifact_history_show_thumbnails !== false}
                            onChange={(value) => onChange('artifact_history_show_thumbnails', value)}
                            help="Display product images in order history."
                        />

                        <ToggleControl
                            label="Show Reorder Button"
                            checked={settings.artifact_history_show_reorder !== false}
                            onChange={(value) => onChange('artifact_history_show_reorder', value)}
                            help="Allow customers to quickly reorder past orders."
                        />
                    </>
                );

            case 'cart':
                return (
                    <>
                        <p className="description">
                            Cart summary and checkout display options.
                        </p>

                        <ToggleControl
                            label="Inline Quantity Editing"
                            checked={settings.artifact_cart_inline_quantity !== false}
                            onChange={(value) => onChange('artifact_cart_inline_quantity', value)}
                            help="Allow quantity changes directly in cart preview."
                        />

                        <ToggleControl
                            label="Show Savings"
                            checked={settings.artifact_cart_show_savings !== false}
                            onChange={(value) => onChange('artifact_cart_show_savings', value)}
                            help="Display discount amounts and savings."
                        />

                        <ToggleControl
                            label="Show Coupon Input"
                            checked={settings.artifact_cart_coupon_input !== false}
                            onChange={(value) => onChange('artifact_cart_coupon_input', value)}
                            help="Allow applying coupons from cart preview."
                        />

                        <ToggleControl
                            label="Show Item Remove Button"
                            checked={settings.artifact_cart_show_remove !== false}
                            onChange={(value) => onChange('artifact_cart_show_remove', value)}
                            help="Allow removing items from cart preview."
                        />

                        <ToggleControl
                            label="Show Shipping Estimate"
                            checked={settings.artifact_cart_show_shipping !== false}
                            onChange={(value) => onChange('artifact_cart_show_shipping', value)}
                            help="Display estimated shipping cost."
                        />

                        <ToggleControl
                            label="Show Free Shipping Progress"
                            checked={settings.artifact_cart_free_shipping_progress !== false}
                            onChange={(value) => onChange('artifact_cart_free_shipping_progress', value)}
                            help="Show progress bar toward free shipping threshold."
                        />
                    </>
                );

            case 'coupons':
                return (
                    <>
                        <p className="description">
                            Coupon card display settings.
                        </p>

                        <SelectControl
                            label="Coupon Style"
                            value={settings.artifact_coupon_style || 'ticket'}
                            options={[
                                { value: 'ticket', label: 'Ticket (with dashed border)' },
                                { value: 'badge', label: 'Badge (compact)' },
                            ]}
                            onChange={(value) => onChange('artifact_coupon_style', value)}
                        />

                        <ToggleControl
                            label="Show Expiry Date"
                            checked={settings.artifact_coupon_show_expiry !== false}
                            onChange={(value) => onChange('artifact_coupon_show_expiry', value)}
                        />

                        <ToggleControl
                            label="Show Apply Button"
                            checked={settings.artifact_coupon_apply_button !== false}
                            onChange={(value) => onChange('artifact_coupon_apply_button', value)}
                            help="Add quick-apply button to coupon cards."
                        />

                        <ToggleControl
                            label="Show Copy Code Button"
                            checked={settings.artifact_coupon_copy_button !== false}
                            onChange={(value) => onChange('artifact_coupon_copy_button', value)}
                            help="Allow copying coupon code to clipboard."
                        />

                        <ToggleControl
                            label="Show Minimum Spend"
                            checked={settings.artifact_coupon_show_minimum !== false}
                            onChange={(value) => onChange('artifact_coupon_show_minimum', value)}
                            help="Display minimum order amount required."
                        />
                    </>
                );

            case 'carousel':
                return (
                    <>
                        <p className="description">
                            Product recommendation display options.
                        </p>

                        <RangeControl
                            label={`Visible Items: ${settings.artifact_carousel_items_visible || 3}`}
                            value={settings.artifact_carousel_items_visible || 3}
                            onChange={(value) => onChange('artifact_carousel_items_visible', value)}
                            min={2}
                            max={5}
                            step={1}
                            help="Products visible at once in carousel."
                        />

                        <ToggleControl
                            label="Auto-Scroll"
                            checked={settings.artifact_carousel_auto_scroll === true}
                            onChange={(value) => onChange('artifact_carousel_auto_scroll', value)}
                            help="Automatically scroll through recommendations."
                        />

                        <ToggleControl
                            label="Show Recommendation Reason"
                            checked={settings.artifact_carousel_show_reason !== false}
                            onChange={(value) => onChange('artifact_carousel_show_reason', value)}
                            help="Display why product is recommended."
                        />

                        <ToggleControl
                            label="Show Navigation Arrows"
                            checked={settings.artifact_carousel_show_arrows !== false}
                            onChange={(value) => onChange('artifact_carousel_show_arrows', value)}
                            help="Display prev/next navigation arrows."
                        />

                        <ToggleControl
                            label="Show Dot Indicators"
                            checked={settings.artifact_carousel_show_dots !== false}
                            onChange={(value) => onChange('artifact_carousel_show_dots', value)}
                            help="Show page indicator dots below carousel."
                        />

                        <RangeControl
                            label={`Auto-Scroll Interval: ${settings.artifact_carousel_interval || 5} seconds`}
                            value={settings.artifact_carousel_interval || 5}
                            onChange={(value) => onChange('artifact_carousel_interval', value)}
                            min={3}
                            max={10}
                            step={1}
                            help="Seconds between auto-scroll (if enabled)."
                        />
                    </>
                );

            case 'account':
                return (
                    <>
                        <p className="description">
                            Customer account display settings.
                        </p>

                        <ToggleControl
                            label="Show Loyalty Points"
                            checked={settings.artifact_account_show_loyalty !== false}
                            onChange={(value) => onChange('artifact_account_show_loyalty', value)}
                            help="Display loyalty/rewards points if available."
                        />

                        <ToggleControl
                            label="Mask Email Address"
                            checked={settings.artifact_account_mask_email !== false}
                            onChange={(value) => onChange('artifact_account_mask_email', value)}
                            help="Partially hide email for privacy (j***@example.com)."
                        />

                        <ToggleControl
                            label="Show Member Since"
                            checked={settings.artifact_account_show_member_since !== false}
                            onChange={(value) => onChange('artifact_account_show_member_since', value)}
                            help="Display account registration date."
                        />

                        <ToggleControl
                            label="Show Order Stats"
                            checked={settings.artifact_account_show_stats !== false}
                            onChange={(value) => onChange('artifact_account_show_stats', value)}
                            help="Show total orders and spending."
                        />

                        <ToggleControl
                            label="Show Quick Links"
                            checked={settings.artifact_account_show_links !== false}
                            onChange={(value) => onChange('artifact_account_show_links', value)}
                            help="Display links to account pages."
                        />
                    </>
                );

            case 'knowledge':
                return (
                    <>
                        <p className="description">
                            How knowledge base answers are displayed.
                        </p>

                        <ToggleControl
                            label="Show Sources"
                            checked={settings.artifact_knowledge_show_sources !== false}
                            onChange={(value) => onChange('artifact_knowledge_show_sources', value)}
                            help="Display source links for knowledge answers."
                        />

                        <RangeControl
                            label={`Max Sources: ${settings.artifact_knowledge_max_sources || 3}`}
                            value={settings.artifact_knowledge_max_sources || 3}
                            onChange={(value) => onChange('artifact_knowledge_max_sources', value)}
                            min={1}
                            max={5}
                            step={1}
                            help="Maximum source links to display."
                        />

                        <ToggleControl
                            label="Show Confidence Indicator"
                            checked={settings.artifact_knowledge_show_confidence !== false}
                            onChange={(value) => onChange('artifact_knowledge_show_confidence', value)}
                            help="Display confidence level of the answer."
                        />

                        <ToggleControl
                            label="Show Category Badge"
                            checked={settings.artifact_knowledge_show_category !== false}
                            onChange={(value) => onChange('artifact_knowledge_show_category', value)}
                            help="Display knowledge category (e.g., Shipping, Returns)."
                        />

                        <ToggleControl
                            label="Collapsible Sources"
                            checked={settings.artifact_knowledge_collapsible_sources !== false}
                            onChange={(value) => onChange('artifact_knowledge_collapsible_sources', value)}
                            help="Allow expanding/collapsing source list."
                        />
                    </>
                );

            default:
                return null;
        }
    };

    return (
        <>
            <div className="glimmr-artifact-selector">
                <SelectControl
                    label="Select Artifact to Configure"
                    value={activeSection}
                    options={ARTIFACT_SECTIONS.map((section) => ({
                        value: section.name,
                        label: section.title,
                    }))}
                    onChange={setActiveSection}
                    __nextHasNoMarginBottom
                />
            </div>

            <SettingsSection
                title={ARTIFACT_SECTIONS.find((s) => s.name === activeSection)?.title}
                className="glimmr-artifact-section"
            >
                {renderSectionContent()}
            </SettingsSection>
        </>
    );
};

export default ArtifactsTab;
