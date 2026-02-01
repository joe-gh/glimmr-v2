/**
 * Product Index Tab
 *
 * Product indexing configuration and category selection.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    Button,
    SelectControl,
    TextControl,
    ToggleControl,
    TextareaControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * Reserved attribute keys that cannot be used for custom mappings.
 */
const RESERVED_KEYS = [
    'price', 'max_price', 'stock_status', 'on_sale', 'featured',
    'product_type', 'rating', 'review_count', 'total_sales',
    'date_created', 'regular_price',
];

/**
 * Maximum number of custom attribute mappings.
 */
const MAX_CUSTOM_ATTRIBUTES = 5;

/**
 * CustomAttributeMappings Component
 *
 * Manages vector store custom attribute mappings for metadata filtering.
 *
 * @param {Object} props
 * @param {Array} props.mappings - Current attribute mappings array
 * @param {Function} props.onUpdate - Callback to update mappings
 */
const CustomAttributeMappings = ({ mappings = [], onUpdate }) => {
    const addMapping = () => {
        if (mappings.length >= MAX_CUSTOM_ATTRIBUTES) return;
        onUpdate([...mappings, { meta_key: '', attribute_key: '', type: 'string' }]);
    };

    const removeMapping = (index) => {
        const updated = mappings.filter((_, i) => i !== index);
        onUpdate(updated);
    };

    const updateMapping = (index, field, value) => {
        const updated = mappings.map((m, i) => {
            if (i !== index) return m;
            const newMapping = { ...m, [field]: value };
            // Auto-generate attribute_key from meta_key if attribute_key is empty.
            if (field === 'meta_key' && !m.attribute_key) {
                newMapping.attribute_key = value
                    .toLowerCase()
                    .replace(/[^a-z0-9_]/g, '_')
                    .replace(/^_+|_+$/g, '');
            }
            return newMapping;
        });
        onUpdate(updated);
    };

    const isReservedKey = (key) => {
        const normalized = key.toLowerCase().replace(/[^a-z0-9_]/g, '_');
        return RESERVED_KEYS.includes(normalized);
    };

    return (
        <div className="glimmr-custom-attributes">
            {mappings.length === 0 && (
                <p className="description" style={{ marginBottom: '12px' }}>
                    No custom attributes configured. Click "Add Mapping" to map a product meta field to a vector store filter attribute.
                </p>
            )}

            {mappings.map((mapping, index) => {
                const keyIsReserved = isReservedKey(mapping.attribute_key);
                return (
                    <div
                        key={index}
                        className="glimmr-attribute-row"
                        style={{
                            display: 'flex',
                            gap: '8px',
                            alignItems: 'flex-start',
                            marginBottom: '12px',
                            padding: '12px',
                            background: '#f9f9f9',
                            borderRadius: '4px',
                            border: '1px solid #ddd',
                        }}
                    >
                        <div style={{ flex: 1 }}>
                            <TextControl
                                label="Meta Key"
                                value={mapping.meta_key}
                                onChange={(value) => updateMapping(index, 'meta_key', value)}
                                placeholder="e.g., _brand, brand, _custom_field"
                                help="The product meta key (post meta or custom field name)."
                            />
                        </div>
                        <div style={{ flex: 1 }}>
                            <TextControl
                                label="Attribute Key"
                                value={mapping.attribute_key}
                                onChange={(value) => updateMapping(index, 'attribute_key', value)}
                                placeholder="e.g., brand"
                                help={keyIsReserved ? 'This key is reserved and cannot be used.' : 'The filter key name in the vector store (lowercase, alphanumeric + underscore).'}
                                className={keyIsReserved ? 'glimmr-field-error' : ''}
                            />
                            {keyIsReserved && (
                                <p style={{ color: '#d63638', fontSize: '12px', margin: '-8px 0 0' }}>
                                    Reserved key. Choose a different name.
                                </p>
                            )}
                        </div>
                        <div style={{ width: '120px' }}>
                            <SelectControl
                                label="Type"
                                value={mapping.type || 'string'}
                                options={[
                                    { value: 'string', label: 'String' },
                                    { value: 'number', label: 'Number' },
                                ]}
                                onChange={(value) => updateMapping(index, 'type', value)}
                            />
                        </div>
                        <div style={{ paddingTop: '24px' }}>
                            <Button
                                isDestructive
                                isSmall
                                onClick={() => removeMapping(index)}
                                aria-label={`Remove mapping ${index + 1}`}
                            >
                                Remove
                            </Button>
                        </div>
                    </div>
                );
            })}

            {mappings.length < MAX_CUSTOM_ATTRIBUTES && (
                <Button
                    isSecondary
                    isSmall
                    onClick={addMapping}
                    style={{ marginTop: '4px' }}
                >
                    Add Mapping
                </Button>
            )}

            {mappings.length >= MAX_CUSTOM_ATTRIBUTES && (
                <p className="description" style={{ marginTop: '4px', color: '#996800' }}>
                    Maximum of {MAX_CUSTOM_ATTRIBUTES} custom attributes reached.
                </p>
            )}
        </div>
    );
};

/**
 * ProductsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 * @param {Array} props.categories - WooCommerce categories
 */
const ProductsTab = ({ settings, onChange, categories }) => (
    <>
        <InfoBox type="info" title="How Product Indexing Works">
            The AI can only recommend and discuss products that are indexed. By default, all published products are indexed.
            Use these settings to include or exclude specific categories or products.
        </InfoBox>

        <SettingsSection
            title="Product Vectorization"
            description="Control whether products are indexed for AI-powered semantic search."
        >
            <ToggleControl
                label="Enable Product Vectorization"
                checked={settings.vectorize_products !== false}
                onChange={(value) => onChange('vectorize_products', value)}
                help={
                    <HelpText type="warning">
                        When enabled, products are stored in the vector database for semantic search (finding products by meaning, not just keywords).
                        Disable only if you want to use basic keyword search instead.
                    </HelpText>
                }
            />
        </SettingsSection>

        <SettingsSection
            title="Custom Filter Attributes"
            description="Map custom product meta fields to vector store filter attributes for metadata-based search filtering."
        >
            <HelpText>
                Map custom product meta fields (e.g., ACF fields, custom post meta) to vector store filter attributes.
                These attributes are stored alongside each product in the vector store and can be used
                for filtering during searches. Max {MAX_CUSTOM_ATTRIBUTES} custom attributes.
                Values must be strings or numbers (OpenAI limitation).
            </HelpText>

            <CustomAttributeMappings
                mappings={settings.vector_store_custom_attributes || []}
                onUpdate={(mappings) => onChange('vector_store_custom_attributes', mappings)}
            />

            <InfoBox type="warning" title="Re-sync Required">
                After adding or changing custom attribute mappings, you must run a <strong>Product Sync</strong> (in the Sync tab) to populate the new attributes on all products in the vector store.
            </InfoBox>
        </SettingsSection>

        <SettingsSection
            title="Category Filtering"
            description="Choose which product categories the AI can access and recommend."
        >
            <SelectControl
                label="Index Mode"
                value={settings.product_index_mode || 'all'}
                options={[
                    { value: 'all', label: 'All Products' },
                    { value: 'include', label: 'Only Selected Categories' },
                    { value: 'exclude', label: 'Exclude Selected Categories' },
                ]}
                onChange={(value) => onChange('product_index_mode', value)}
                help={
                    <HelpText>
                        <strong>All Products:</strong> Index everything (recommended for most stores)<br />
                        <strong>Only Selected:</strong> Only index products in chosen categories<br />
                        <strong>Exclude Selected:</strong> Index everything except chosen categories
                    </HelpText>
                }
            />

            {settings.product_index_mode === 'include' && (
                <div className="glimmr-category-select">
                    <label><strong>Categories to Include</strong></label>
                    <p className="description" style={{ marginTop: '4px', marginBottom: '12px' }}>
                        Only products in these categories will be available to the AI. Products not in any selected category will be hidden from the assistant.
                    </p>
                    {categories.length === 0 ? (
                        <InfoBox type="warning">
                            No categories found. Make sure you have WooCommerce product categories set up.
                        </InfoBox>
                    ) : (
                        categories.map((cat) => (
                            <ToggleControl
                                key={cat.id}
                                label={cat.name}
                                checked={(settings.product_include_categories || []).includes(cat.id)}
                                onChange={(checked) => {
                                    const current = settings.product_include_categories || [];
                                    const updated = checked
                                        ? [...current, cat.id]
                                        : current.filter((id) => id !== cat.id);
                                    onChange('product_include_categories', updated);
                                }}
                            />
                        ))
                    )}
                </div>
            )}

            {settings.product_index_mode === 'exclude' && (
                <div className="glimmr-category-select">
                    <label><strong>Categories to Exclude</strong></label>
                    <p className="description" style={{ marginTop: '4px', marginBottom: '12px' }}>
                        Products in these categories will NOT be available to the AI. Useful for hiding wholesale-only, discontinued, or internal products.
                    </p>
                    {categories.length === 0 ? (
                        <InfoBox type="warning">
                            No categories found. Make sure you have WooCommerce product categories set up.
                        </InfoBox>
                    ) : (
                        categories.map((cat) => (
                            <ToggleControl
                                key={cat.id}
                                label={cat.name}
                                checked={(settings.product_exclude_categories || []).includes(cat.id)}
                                onChange={(checked) => {
                                    const current = settings.product_exclude_categories || [];
                                    const updated = checked
                                        ? [...current, cat.id]
                                        : current.filter((id) => id !== cat.id);
                                    onChange('product_exclude_categories', updated);
                                }}
                            />
                        ))
                    )}
                </div>
            )}
        </SettingsSection>

        <SettingsSection
            title="Individual Product Overrides"
            description="Override category settings for specific products by entering their IDs."
        >
            <TextareaControl
                label="Always Include (Product IDs)"
                value={(settings.product_include_ids || []).join(', ')}
                onChange={(value) => {
                    const ids = value.split(',').map((id) => parseInt(id.trim())).filter((id) => id > 0);
                    onChange('product_include_ids', ids);
                }}
                help={
                    <HelpText type="tip">
                        Products listed here will always be indexed, even if their category is excluded. Find product IDs in WooCommerce → Products (hover over a product to see its ID).
                    </HelpText>
                }
                placeholder="e.g., 123, 456, 789"
            />

            <TextareaControl
                label="Always Exclude (Product IDs)"
                value={(settings.product_exclude_ids || []).join(', ')}
                onChange={(value) => {
                    const ids = value.split(',').map((id) => parseInt(id.trim())).filter((id) => id > 0);
                    onChange('product_exclude_ids', ids);
                }}
                help={
                    <HelpText>
                        Products listed here will never be indexed, even if their category is included. Useful for hiding specific items like test products.
                    </HelpText>
                }
                placeholder="e.g., 123, 456, 789"
            />

            <InfoBox type="info" title="After Changing Settings">
                After modifying these settings, run a <strong>Product Sync</strong> (in the Sync tab) to update the AI's product database.
            </InfoBox>
        </SettingsSection>
    </>
);

export default ProductsTab;
