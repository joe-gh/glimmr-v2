/**
 * Prompts & Tools Configuration Component
 *
 * Configure system prompts and AI tool settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect, useCallback } = wp.element;
const {
    Card,
    CardBody,
    CardHeader,
    Button,
    Spinner,
    Notice,
    TextareaControl,
    ToggleControl,
    SelectControl,
} = wp.components;

/**
 * Tool categories for grouping
 */
const TOOL_CATEGORIES = {
    products: {
        title: 'Product Tools',
        description: 'Search, compare, and query products.',
    },
    resolvers: {
        title: 'Disambiguation Tools',
        description: 'Resolve ambiguous references before taking actions.',
    },
    information: {
        title: 'Information Tools',
        description: 'Answer questions from knowledge base.',
    },
    cart: {
        title: 'Cart Tools',
        description: 'Manage shopping cart.',
    },
    coupons: {
        title: 'Coupon Tools',
        description: 'Find and apply coupons.',
    },
    orders: {
        title: 'Order Tools',
        description: 'Order status and history.',
    },
    account: {
        title: 'Account Tools',
        description: 'Customer account management.',
    },
};

/**
 * Tool definitions with metadata
 */
const TOOLS = {
    // Product tools (unified)
    query_products: {
        name: 'Query Products',
        description: 'Search, compare, details, and stock check with modes.',
        category: 'products',
        required: true,
    },
    recommendations: {
        name: 'Recommendations',
        description: 'Suggests products based on cart, history, or popularity.',
        category: 'products',
    },
    catalog_query: {
        name: 'Catalog Query',
        description: 'Advanced SQL-based product queries.',
        category: 'products',
    },

    // Resolver tools
    resolve_product: {
        name: 'Resolve Product',
        description: 'Resolves ambiguous product names to IDs.',
        category: 'resolvers',
    },
    resolve_variation: {
        name: 'Resolve Variation',
        description: 'Helps select product variations (size, color).',
        category: 'resolvers',
    },
    resolve_cart_item: {
        name: 'Resolve Cart Item',
        description: 'Identifies cart items from vague references.',
        category: 'resolvers',
    },
    resolve_order: {
        name: 'Resolve Order',
        description: 'Helps identify orders for guest users.',
        category: 'resolvers',
    },

    // Information tools
    text_answer: {
        name: 'Text Answer (RAG)',
        description: 'Answers non-product questions using knowledge base.',
        category: 'information',
        required: true,
    },
    site_knowledge: {
        name: 'Site Knowledge',
        description: 'Store policies, shipping info, and FAQs.',
        category: 'information',
    },

    // Cart tools
    add_to_cart: {
        name: 'Add to Cart',
        description: 'Add product to cart.',
        category: 'cart',
    },
    view_cart: {
        name: 'View Cart',
        description: 'View cart contents.',
        category: 'cart',
    },
    update_cart: {
        name: 'Update Cart',
        description: 'Update cart quantities.',
        category: 'cart',
    },
    checkout_link: {
        name: 'Checkout Link',
        description: 'Generate checkout URL.',
        category: 'cart',
    },

    // Coupon tools
    coupon_lookup: {
        name: 'Coupon Lookup',
        description: 'Find available coupons.',
        category: 'coupons',
        hasSettings: true,
    },
    apply_coupon: {
        name: 'Apply Coupon',
        description: 'Apply coupon to cart.',
        category: 'coupons',
    },

    // Order tools
    order_status: {
        name: 'Order Status',
        description: 'Check order status.',
        category: 'orders',
    },
    order_history: {
        name: 'Order History',
        description: 'View past orders.',
        category: 'orders',
    },

    // Account tools
    account_info: {
        name: 'Account Info',
        description: 'Customer account details.',
        category: 'account',
    },
};

/**
 * Variable hints for system prompt
 */
const PROMPT_VARIABLES = [
    { name: '{site_name}', description: 'Your store name' },
    { name: '{site_url}', description: 'Your store URL' },
    { name: '{is_logged_in}', description: 'Whether customer is logged in (Yes/No)' },
    { name: '{customer_name}', description: 'Customer name (if logged in)' },
    { name: '{cart_summary}', description: 'Current cart items and total' },
    { name: '{currency}', description: 'Currency code (USD, EUR, etc.)' },
    { name: '{currency_symbol}', description: 'Currency symbol ($, €, etc.)' },
];

/**
 * Get default system prompt from PHP (single source of truth).
 * Falls back to a minimal prompt if not available.
 */
const getDefaultPrompt = () => {
    if (window.glimmrAI && window.glimmrAI.defaultPrompt) {
        return window.glimmrAI.defaultPrompt;
    }
    // Minimal fallback - should never be used if PHP is working correctly.
    return `You are a helpful AI shopping assistant for {site_name}. Use the available tools to help customers find products, manage their cart, and track orders.`;
};

/**
 * Tool Card Component
 */
const ToolCard = ({ toolKey, tool, enabled, onToggle, onConfigure, hasSettings }) => (
    <div className={`glimmr-tool-card ${enabled ? 'is-enabled' : ''} ${tool.required ? 'is-required' : ''}`}>
        <div className="glimmr-tool-header">
            <ToggleControl
                checked={enabled}
                onChange={() => onToggle(toolKey)}
                disabled={tool.required}
            />
            <div className="glimmr-tool-info">
                <div className="glimmr-tool-name">
                    {tool.name}
                    {tool.required && <span className="glimmr-required-badge">Required</span>}
                </div>
                <div className="glimmr-tool-description">{tool.description}</div>
            </div>
        </div>
        {hasSettings && enabled && (
            <div className="glimmr-tool-settings-link">
                <Button variant="link" onClick={() => onConfigure(toolKey)}>
                    Configure
                </Button>
            </div>
        )}
    </div>
);

/**
 * Coupon Settings Modal Content
 */
const CouponSettings = ({ settings, onChange }) => (
    <div className="glimmr-coupon-settings">
        <h4>Coupon Visibility</h4>
        <p className="description">
            Control which coupons the AI can tell customers about.
        </p>

        <SelectControl
            label="Visibility Mode"
            value={settings.coupon_visibility || 'public'}
            options={[
                { value: 'public', label: 'All Public Coupons' },
                { value: 'specific', label: 'Only Specific Coupons' },
                { value: 'none', label: 'No Coupons (Disable Lookup)' },
            ]}
            onChange={(value) => onChange('coupon_visibility', value)}
        />

        {settings.coupon_visibility === 'specific' && (
            <TextareaControl
                label="Allowed Coupon Codes"
                value={(settings.visible_coupons || []).join('\n')}
                onChange={(value) => {
                    const codes = value.split('\n').map((c) => c.trim()).filter(Boolean);
                    onChange('visible_coupons', codes);
                }}
                help="Enter one coupon code per line."
                rows={5}
            />
        )}
    </div>
);

/**
 * Main Prompts & Tools Component
 */
const PromptsTools = () => {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);
    const [activeSection, setActiveSection] = useState('prompt');
    const [configuringTool, setConfiguringTool] = useState(null);

    // Data state
    const [systemPrompt, setSystemPrompt] = useState('');
    const [guardrails, setGuardrails] = useState('');
    const [defaultGuardrails, setDefaultGuardrails] = useState('');
    const [enabledTools, setEnabledTools] = useState({});
    const [toolSettings, setToolSettings] = useState({});

    const { ajaxUrl, nonce, siteName } = window.glimmrAI || {};

    /**
     * Fetch data on mount.
     */
    useEffect(() => {
        fetchData();
    }, []);

    /**
     * Fetch prompts and tools data.
     */
    const fetchData = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_prompts_tools');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setSystemPrompt(result.data.system_prompt || getDefaultPrompt());
                setGuardrails(result.data.agent_guardrails || result.data.default_guardrails || '');
                setDefaultGuardrails(result.data.default_guardrails || '');
                setEnabledTools(result.data.enabled_tools || {});
                setToolSettings(result.data.tool_settings || {});
            }
        } catch (err) {
            console.error('Prompts/tools fetch error:', err);
            setNotice({ type: 'error', message: 'Failed to load configuration.' });
        }

        setLoading(false);
    };

    /**
     * Save configuration.
     */
    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_save_prompts_tools');
            formData.append('nonce', nonce);
            formData.append('system_prompt', systemPrompt);
            formData.append('agent_guardrails', guardrails);
            formData.append('enabled_tools', JSON.stringify(enabledTools));
            formData.append('tool_settings', JSON.stringify(toolSettings));

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Configuration saved successfully.' });
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to save.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to connect to server.' });
        }

        setSaving(false);
    };

    /**
     * Toggle a tool.
     */
    const handleToggleTool = useCallback((toolKey) => {
        setEnabledTools((prev) => ({
            ...prev,
            [toolKey]: !prev[toolKey],
        }));
    }, []);

    /**
     * Update tool settings.
     */
    const handleToolSettingChange = useCallback((key, value) => {
        setToolSettings((prev) => ({
            ...prev,
            [key]: value,
        }));
    }, []);

    /**
     * Reset prompt to default.
     */
    const handleResetPrompt = () => {
        if (confirm('Reset to default prompt? Your customizations will be lost.')) {
            setSystemPrompt(getDefaultPrompt());
        }
    };

    /**
     * Reset guardrails to default.
     */
    const handleResetGuardrails = () => {
        if (confirm('Reset to default guardrails? Your customizations will be lost.')) {
            setGuardrails(defaultGuardrails);
        }
    };

    /**
     * Get tools grouped by category.
     */
    const getToolsByCategory = () => {
        const grouped = {};

        Object.entries(TOOLS).forEach(([key, tool]) => {
            if (!grouped[tool.category]) {
                grouped[tool.category] = [];
            }
            grouped[tool.category].push({ key, ...tool });
        });

        return grouped;
    };

    if (loading) {
        return (
            <div className="glimmr-loading-center">
                <Spinner />
                <p>Loading configuration...</p>
            </div>
        );
    }

    const toolsByCategory = getToolsByCategory();

    return (
        <div className="glimmr-prompts-tools">
            {notice && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice(null)}
                >
                    {notice.message}
                </Notice>
            )}

            {/* Section Tabs */}
            <div className="glimmr-section-tabs" role="tablist" aria-label="Prompt and tools sections">
                <button
                    role="tab"
                    aria-selected={activeSection === 'prompt'}
                    aria-controls="tabpanel-prompt"
                    id="tab-prompt"
                    className={`glimmr-section-tab ${activeSection === 'prompt' ? 'is-active' : ''}`}
                    onClick={() => setActiveSection('prompt')}
                >
                    <span className="dashicons dashicons-editor-quote" aria-hidden="true"></span>
                    System Prompt
                </button>
                <button
                    role="tab"
                    aria-selected={activeSection === 'guardrails'}
                    aria-controls="tabpanel-guardrails"
                    id="tab-guardrails"
                    className={`glimmr-section-tab ${activeSection === 'guardrails' ? 'is-active' : ''}`}
                    onClick={() => setActiveSection('guardrails')}
                >
                    <span className="dashicons dashicons-shield" aria-hidden="true"></span>
                    Guardrails
                </button>
                <button
                    role="tab"
                    aria-selected={activeSection === 'tools'}
                    aria-controls="tabpanel-tools"
                    id="tab-tools"
                    className={`glimmr-section-tab ${activeSection === 'tools' ? 'is-active' : ''}`}
                    onClick={() => setActiveSection('tools')}
                >
                    <span className="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                    Tools Configuration
                </button>
            </div>

            {/* System Prompt Section */}
            {activeSection === 'prompt' && (
                <Card className="glimmr-prompt-card" role="tabpanel" id="tabpanel-prompt" aria-labelledby="tab-prompt">
                    <CardHeader>
                        <h3>System Prompt</h3>
                        <Button variant="link" onClick={handleResetPrompt}>
                            Reset to Default
                        </Button>
                    </CardHeader>
                    <CardBody>
                        <p className="description">
                            Define the AI assistant's personality and behavior. Use variables below for dynamic content.
                        </p>

                        <TextareaControl
                            value={systemPrompt}
                            onChange={setSystemPrompt}
                            rows={15}
                            className="glimmr-prompt-textarea"
                        />

                        <div className="glimmr-prompt-variables">
                            <h4>Available Variables</h4>
                            <div className="glimmr-variables-grid">
                                {PROMPT_VARIABLES.map((v) => (
                                    <div key={v.name} className="glimmr-variable-item">
                                        <code>{v.name}</code>
                                        <span>{v.description}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="glimmr-prompt-preview">
                            <h4>Preview</h4>
                            <div className="glimmr-prompt-preview-content">
                                {systemPrompt
                                    .replace(/{site_name}/g, siteName || 'Your Store')
                                    .replace(/{tone}/g, 'friendly')
                                    .replace(/{user_name}/g, 'Customer')
                                    .replace(/{currency}/g, '$')
                                }
                            </div>
                        </div>
                    </CardBody>
                </Card>
            )}

            {/* Guardrails Section */}
            {activeSection === 'guardrails' && (
                <Card className="glimmr-prompt-card" role="tabpanel" id="tabpanel-guardrails" aria-labelledby="tab-guardrails">
                    <CardHeader>
                        <h3>Agent Guardrails</h3>
                        <Button variant="link" onClick={handleResetGuardrails}>
                            Reset to Default
                        </Button>
                    </CardHeader>
                    <CardBody>
                        <p className="description">
                            Define what the AI assistant can and cannot do. These guardrails prevent the AI from
                            promising actions it cannot perform (like sending emails or modifying orders).
                            This is appended to the system prompt automatically.
                        </p>

                        <TextareaControl
                            value={guardrails}
                            onChange={setGuardrails}
                            rows={20}
                            className="glimmr-prompt-textarea glimmr-guardrails-textarea"
                        />

                        <div className="glimmr-guardrails-help">
                            <h4>How Guardrails Work</h4>
                            <ul>
                                <li><strong>CAN DO:</strong> List specific actions the AI can perform (e.g., "Search products", "Add items to cart")</li>
                                <li><strong>CANNOT DO:</strong> Explicitly state limitations (e.g., "Cannot send emails", "Cannot modify orders")</li>
                                <li><strong>Fallback behavior:</strong> Tell the AI how to respond when asked to do something unsupported</li>
                            </ul>
                            <p className="note">
                                These guardrails are automatically appended after your system prompt when sending requests to OpenAI.
                            </p>
                        </div>
                    </CardBody>
                </Card>
            )}

            {/* Tools Section */}
            {activeSection === 'tools' && (
                <div className="glimmr-tools-section" role="tabpanel" id="tabpanel-tools" aria-labelledby="tab-tools">
                    {Object.entries(TOOL_CATEGORIES).map(([categoryKey, category]) => (
                        <Card key={categoryKey} className="glimmr-tools-category">
                            <CardHeader>
                                <h3>{category.title}</h3>
                                <p className="description">{category.description}</p>
                            </CardHeader>
                            <CardBody>
                                <div className="glimmr-tools-grid">
                                    {(toolsByCategory[categoryKey] || []).map((tool) => (
                                        <ToolCard
                                            key={tool.key}
                                            toolKey={tool.key}
                                            tool={tool}
                                            enabled={enabledTools[tool.key] !== false}
                                            onToggle={handleToggleTool}
                                            onConfigure={setConfiguringTool}
                                            hasSettings={tool.hasSettings}
                                        />
                                    ))}
                                </div>
                            </CardBody>
                        </Card>
                    ))}

                    {/* Coupon Settings (shown when configuring) */}
                    {configuringTool === 'coupon_lookup' && (
                        <Card className="glimmr-tool-settings-panel">
                            <CardHeader>
                                <h3>Coupon Lookup Settings</h3>
                                <Button variant="link" onClick={() => setConfiguringTool(null)}>
                                    Close
                                </Button>
                            </CardHeader>
                            <CardBody>
                                <CouponSettings
                                    settings={toolSettings}
                                    onChange={handleToolSettingChange}
                                />
                            </CardBody>
                        </Card>
                    )}
                </div>
            )}

            {/* Save Button */}
            <div className="glimmr-prompts-actions">
                <Button
                    variant="primary"
                    onClick={handleSave}
                    disabled={saving}
                >
                    {saving ? 'Saving...' : 'Save Configuration'}
                </Button>
            </div>
        </div>
    );
};

export default PromptsTools;
