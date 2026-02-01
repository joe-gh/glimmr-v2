/**
 * Translations Tab
 *
 * Attribute label translations for WooCommerce.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    TextControl,
    Button,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';
import { DEFAULT_ATTRIBUTE_TRANSLATIONS } from '../settingsConfig';

/**
 * TranslationsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const TranslationsTab = ({ settings, onChange }) => {
    const translations = settings.attribute_translations || DEFAULT_ATTRIBUTE_TRANSLATIONS;

    const addTranslation = () => {
        const updated = [...translations, { key: '', label: '' }];
        onChange('attribute_translations', updated);
    };

    const updateTranslation = (index, field, value) => {
        const updated = [...translations];
        updated[index] = { ...updated[index], [field]: value };
        onChange('attribute_translations', updated);
    };

    const removeTranslation = (index) => {
        const updated = translations.filter((_, i) => i !== index);
        onChange('attribute_translations', updated);
    };

    const resetToDefaults = () => {
        if (confirm('Reset all translations to defaults? This will remove any custom translations you\'ve added.')) {
            onChange('attribute_translations', DEFAULT_ATTRIBUTE_TRANSLATIONS);
        }
    };

    return (
        <>
            <InfoBox type="info" title="What Are Attribute Translations?">
                WooCommerce uses technical attribute keys like <code>pa_color</code> internally.
                These translations convert those keys into friendly labels like "Color" that customers see in the chat.
                This ensures product attributes display clearly in comparisons and product details.
            </InfoBox>

            <SettingsSection
                title="Attribute Label Translations"
                description="Map WooCommerce attribute keys to human-readable labels shown in the chat widget."
            >
                <InfoBox type="tip" title="How It Works">
                    <ol style={{ margin: 0, paddingLeft: '20px' }}>
                        <li>First, WooCommerce's built-in <code>wc_attribute_label()</code> function is used</li>
                        <li>Then, these translations are applied as overrides</li>
                        <li>Use this for attributes that don't have proper labels in WooCommerce, or to customize the display</li>
                    </ol>
                </InfoBox>

                <div className="glimmr-translations-table">
                    <div className="glimmr-translations-header">
                        <span className="glimmr-translations-col-key">Attribute Key</span>
                        <span className="glimmr-translations-col-label">Display Label</span>
                        <span className="glimmr-translations-col-action"></span>
                    </div>

                    {translations.map((translation, index) => (
                        <div key={index} className="glimmr-translations-row">
                            <TextControl
                                value={translation.key}
                                onChange={(value) => updateTranslation(index, 'key', value.toLowerCase())}
                                placeholder="pa_color"
                                className="glimmr-translations-col-key"
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                value={translation.label}
                                onChange={(value) => updateTranslation(index, 'label', value)}
                                placeholder="Color"
                                className="glimmr-translations-col-label"
                                __nextHasNoMarginBottom
                            />
                            <Button
                                variant="link"
                                isDestructive
                                onClick={() => removeTranslation(index)}
                                className="glimmr-translations-col-action"
                                aria-label="Remove translation"
                            >
                                <span className="dashicons dashicons-trash"></span>
                            </Button>
                        </div>
                    ))}
                </div>

                <div className="glimmr-translations-actions">
                    <Button variant="secondary" onClick={addTranslation}>
                        <span className="dashicons dashicons-plus-alt2"></span>
                        Add Translation
                    </Button>

                    <Button variant="link" onClick={resetToDefaults}>
                        Reset to Defaults
                    </Button>
                </div>

                <InfoBox type="info" title="Common Attribute Keys">
                    <div style={{ marginBottom: '8px' }}>
                        <HelpText>
                            <strong>Global attributes</strong> (created in Products → Attributes) are prefixed with <code>pa_</code>
                        </HelpText>
                    </div>
                    <ul style={{ margin: '8px 0', paddingLeft: '20px' }}>
                        <li><code>pa_color</code> → Color</li>
                        <li><code>pa_size</code> → Size</li>
                        <li><code>pa_material</code> → Material</li>
                        <li><code>pa_brand</code> → Brand</li>
                    </ul>
                    <div style={{ marginTop: '8px' }}>
                        <HelpText>
                            <strong>Custom product attributes</strong> use the attribute name directly (no prefix).
                            Find the exact key in WooCommerce → Products → Attributes.
                        </HelpText>
                    </div>
                </InfoBox>
            </SettingsSection>
        </>
    );
};

export default TranslationsTab;
