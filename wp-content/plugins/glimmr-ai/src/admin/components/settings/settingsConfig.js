/**
 * Settings Navigation Configuration
 *
 * Defines the two-tier navigation structure for the settings page.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * Main settings categories with sub-tabs.
 */
export const SETTINGS_CATEGORIES = [
    {
        id: 'configuration',
        label: 'Configuration',
        icon: 'admin-settings',
        tabs: [
            { id: 'api', label: 'API' },
            { id: 'costs', label: 'Costs' },
            { id: 'sync', label: 'Sync' },
            { id: 'products', label: 'Products' },
            { id: 'integrations', label: 'Integrations' },
            { id: 'support', label: 'Support' },
            { id: 'advanced', label: 'Advanced' },
        ],
    },
    {
        id: 'design',
        label: 'Design',
        icon: 'admin-appearance',
        tabs: [
            { id: 'position', label: 'Position & Size' },
            { id: 'colors', label: 'Colors' },
            { id: 'branding', label: 'Branding' },
        ],
    },
    {
        id: 'chat',
        label: 'Chat Experience',
        icon: 'format-chat',
        tabs: [
            { id: 'artifacts', label: 'Artifacts' },
            { id: 'behavior', label: 'Behavior' },
            { id: 'engagement', label: 'Engagement' },
            { id: 'agent', label: 'Agent' },
            { id: 'translations', label: 'Translations' },
        ],
    },
    {
        id: 'privacy',
        label: 'Privacy & Debug',
        icon: 'shield',
        tabs: [
            { id: 'gdpr', label: 'GDPR' },
            { id: 'logging', label: 'Logging' },
        ],
    },
];

/**
 * Default attribute translations for WooCommerce attributes.
 */
export const DEFAULT_ATTRIBUTE_TRANSLATIONS = [
    { key: 'pa_color', label: 'Color' },
    { key: 'pa_colour', label: 'Color' },
    { key: 'pa_size', label: 'Size' },
    { key: 'pa_material', label: 'Material' },
    { key: 'pa_style', label: 'Style' },
    { key: 'pa_brand', label: 'Brand' },
    { key: 'pa_weight', label: 'Weight' },
    { key: 'pa_length', label: 'Length' },
    { key: 'pa_width', label: 'Width' },
    { key: 'pa_height', label: 'Height' },
    { key: 'pa_pattern', label: 'Pattern' },
    { key: 'pa_fabric', label: 'Fabric' },
    { key: 'pa_fit', label: 'Fit' },
    { key: 'pa_gender', label: 'Gender' },
    { key: 'pa_age-group', label: 'Age Group' },
    { key: 'pa_capacity', label: 'Capacity' },
    { key: 'pa_voltage', label: 'Voltage' },
    { key: 'pa_wattage', label: 'Wattage' },
    { key: 'pa_finish', label: 'Finish' },
    { key: 'pa_shape', label: 'Shape' },
    { key: 'pa_scent', label: 'Scent' },
    { key: 'pa_flavor', label: 'Flavor' },
    { key: 'pa_flavour', label: 'Flavor' },
    { key: 'pa_model', label: 'Model' },
    { key: 'pa_edition', label: 'Edition' },
    { key: 'pa_type', label: 'Type' },
    { key: 'pa_variant', label: 'Variant' },
    { key: 'pa_pack-size', label: 'Pack Size' },
    { key: 'pa_quantity', label: 'Quantity' },
];

/**
 * Artifact sections for the dropdown selector.
 */
export const ARTIFACT_SECTIONS = [
    { name: 'grid', title: 'Product Grid Display' },
    { name: 'comparison', title: 'Product Comparison Table' },
    { name: 'modal', title: 'Product Detail Modal' },
    { name: 'order', title: 'Order Display' },
    { name: 'cart', title: 'Cart & Checkout' },
    { name: 'coupons', title: 'Coupons' },
    { name: 'carousel', title: 'Recommendations Carousel' },
    { name: 'account', title: 'Account Summary' },
    { name: 'knowledge', title: 'Site Knowledge Responses' },
];

/**
 * Get the default tab for a category.
 *
 * @param {string} categoryId - The category ID
 * @returns {string} The default tab ID
 */
export const getDefaultTab = (categoryId) => {
    const category = SETTINGS_CATEGORIES.find(c => c.id === categoryId);
    return category?.tabs[0]?.id || '';
};

/**
 * Parse URL hash to get category and tab.
 *
 * @returns {{ category: string, tab: string }}
 */
export const parseUrlHash = () => {
    const hash = window.location.hash.slice(1); // Remove #
    if (!hash) {
        return { category: 'configuration', tab: 'api' };
    }

    const parts = hash.split('/');
    const category = parts[0] || 'configuration';
    const tab = parts[1] || getDefaultTab(category);

    // Validate category exists
    const categoryExists = SETTINGS_CATEGORIES.some(c => c.id === category);
    if (!categoryExists) {
        return { category: 'configuration', tab: 'api' };
    }

    // Validate tab exists in category
    const categoryObj = SETTINGS_CATEGORIES.find(c => c.id === category);
    const tabExists = categoryObj?.tabs.some(t => t.id === tab);
    if (!tabExists) {
        return { category, tab: getDefaultTab(category) };
    }

    return { category, tab };
};

/**
 * Build URL hash from category and tab.
 *
 * @param {string} category - Category ID
 * @param {string} tab - Tab ID
 * @returns {string} The hash string (without #)
 */
export const buildUrlHash = (category, tab) => {
    return `${category}/${tab}`;
};
