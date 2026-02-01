/**
 * Attribute Label Translation Utility
 *
 * Maps raw WooCommerce attribute keys to human-readable labels.
 * Uses admin-configured translations from settings, with fallback to defaults.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * Default attribute translation table.
 * Maps WooCommerce attribute keys (pa_*) to human-readable labels.
 * Used as fallback when admin hasn't configured custom translations.
 */
const defaultTranslations = {
    // Standard WooCommerce attributes
    'pa_color': 'Color',
    'pa_colour': 'Color',
    'pa_size': 'Size',
    'pa_material': 'Material',
    'pa_style': 'Style',
    'pa_brand': 'Brand',
    'pa_weight': 'Weight',
    'pa_length': 'Length',
    'pa_width': 'Width',
    'pa_height': 'Height',
    'pa_pattern': 'Pattern',
    'pa_fabric': 'Fabric',
    'pa_fit': 'Fit',
    'pa_gender': 'Gender',
    'pa_age-group': 'Age Group',
    'pa_capacity': 'Capacity',
    'pa_voltage': 'Voltage',
    'pa_wattage': 'Wattage',
    'pa_finish': 'Finish',
    'pa_shape': 'Shape',
    'pa_scent': 'Scent',
    'pa_flavor': 'Flavor',
    'pa_flavour': 'Flavor',
    'pa_model': 'Model',
    'pa_edition': 'Edition',
    'pa_type': 'Type',
    'pa_variant': 'Variant',
    'pa_pack-size': 'Pack Size',
    'pa_quantity': 'Quantity',
};

/**
 * Get the attribute translations from the widget config.
 * Falls back to defaults if not configured.
 *
 * @returns {Object} Key-value map of attribute keys to labels.
 */
const getTranslations = () => {
    // Try to get translations from widget config
    if (typeof window !== 'undefined' && window.glimmrAIWidget?.attributeTranslations) {
        return window.glimmrAIWidget.attributeTranslations;
    }
    return defaultTranslations;
};

/**
 * Translate an attribute key to a human-readable label.
 *
 * Priority order:
 * 1. Admin-configured translations (from settings)
 * 2. Default translations
 * 3. Format the key nicely (remove pa_ prefix, convert to title case)
 *
 * @param {string} key - The attribute key (e.g., 'pa_color' or 'Color')
 * @param {Object} [configTranslations] - Optional translations from config prop (for components that receive config)
 * @returns {string} - Human-readable label (e.g., 'Color')
 */
export const translateAttributeLabel = (key, configTranslations = null) => {
    if (!key || typeof key !== 'string') {
        return '';
    }

    const lowerKey = key.toLowerCase().trim();

    // Get translations - prefer passed config, then global config, then defaults
    const translations = configTranslations || getTranslations();

    // Check if we have a direct translation
    if (translations[lowerKey]) {
        return translations[lowerKey];
    }

    // Also check without the pa_ prefix in case the config uses short keys
    const withoutPrefix = lowerKey.replace(/^pa_/, '');
    if (translations[withoutPrefix]) {
        return translations[withoutPrefix];
    }

    // If it doesn't start with 'pa_', it might already be translated
    // Just clean it up and return
    if (!lowerKey.startsWith('pa_')) {
        // It's likely already a clean label, just normalize it
        return key
            .split(/[-_]/)
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ')
            .trim();
    }

    // Fallback: Remove pa_ prefix and format nicely
    let label = withoutPrefix;

    // Convert slug format to title case (some-attribute → Some Attribute)
    label = label
        .split(/[-_]/)
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ');

    return label;
};

export default defaultTranslations;
