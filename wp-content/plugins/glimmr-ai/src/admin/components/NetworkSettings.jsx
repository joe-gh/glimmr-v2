/**
 * Network Settings Component
 *
 * Network-level settings interface for Glimmr AI multisite configuration.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect, useCallback } = wp.element;
const {
    Card,
    CardBody,
    CardHeader,
    TextControl,
    TextareaControl,
    SelectControl,
    ToggleControl,
    RangeControl,
    Button,
    Spinner,
    Notice,
    ColorPicker,
    CheckboxControl,
} = wp.components;

/**
 * Format large numbers with K/M suffix for display.
 */
const formatNumber = (num) => {
    if (num === null || num === undefined) return '0';
    if (num >= 1000000) {
        const millions = num / 1000000;
        return millions % 1 === 0 ? `${millions}M` : `${millions.toFixed(1)}M`;
    }
    if (num >= 1000) {
        const thousands = num / 1000;
        return thousands % 1 === 0 ? `${thousands}K` : `${thousands.toFixed(1)}K`;
    }
    return num.toLocaleString();
};

/**
 * Network settings tabs configuration.
 */
const NETWORK_TABS = [
    { name: 'api', title: 'API Configuration', icon: 'admin-network' },
    { name: 'costs', title: 'Rate Limits', icon: 'chart-line' },
    { name: 'appearance', title: 'Widget Defaults', icon: 'admin-appearance' },
    { name: 'agent', title: 'Agent Config', icon: 'admin-users' },
    { name: 'tools', title: 'Tools', icon: 'admin-tools' },
    { name: 'locks', title: 'Locked Settings', icon: 'lock' },
    { name: 'sites', title: 'Network Sites', icon: 'networking' },
];

/**
 * Token Limit Control - Custom component for token limits
 */
const TokenLimitControl = ({ label, value, onChange, min, max, step, help }) => {
    const displayValue = Math.min(Math.max(value || min, min), max);

    return (
        <div className="glimmr-token-control">
            <div className="glimmr-token-control-header">
                <label className="components-base-control__label">{label}</label>
                <span className="glimmr-token-value">
                    {formatNumber(value || min)} tokens
                </span>
            </div>
            <RangeControl
                value={displayValue}
                onChange={onChange}
                min={min}
                max={max}
                step={step}
                withInputField={false}
                __nextHasNoMarginBottom
            />
            <div className="glimmr-token-range">
                <span>{formatNumber(min)}</span>
                <span>{formatNumber(max)}</span>
            </div>
            {help && <p className="components-base-control__help">{help}</p>}
        </div>
    );
};

/**
 * API Configuration Tab (Network)
 */
const NetworkApiTab = ({ settings, onChange }) => (
    <div className="glimmr-settings-section">
        <h3>Network OpenAI Configuration</h3>
        <p className="description">
            Configure default OpenAI credentials for all sites in the network.
            Sites can override these unless locked.
        </p>

        <TextControl
            label="Network API Key"
            type="password"
            value={settings.openai_api_key || ''}
            onChange={(value) => onChange('openai_api_key', value)}
            help={settings.has_api_key
                ? `Current key ends in: ${settings.openai_api_key_masked || '****'}`
                : 'No API key set. Enter your OpenAI API key.'
            }
            placeholder={settings.has_api_key ? '••••••••••••••••' : 'sk-...'}
        />

        <SelectControl
            label="Default Model"
            value={settings.openai_model || 'gpt-4o-mini'}
            options={[
                { value: '', label: '— GPT-5 Series —', disabled: true },
                { value: 'gpt-5', label: 'GPT-5 (Most Capable)' },
                { value: 'gpt-5-mini', label: 'GPT-5 Mini (Faster, Lower Cost)' },
                { value: 'gpt-5-nano', label: 'GPT-5 Nano (Ultra-Fast)' },
                { value: '', label: '— GPT-4o Series —', disabled: true },
                { value: 'gpt-4o', label: 'GPT-4o (Fast, Multimodal)' },
                { value: 'gpt-4o-mini', label: 'GPT-4o Mini (Fastest & Cheapest)' },
                { value: '', label: '— GPT-4.1 Series —', disabled: true },
                { value: 'gpt-4.1', label: 'GPT-4.1 (Best Overall)' },
                { value: 'gpt-4.1-mini', label: 'GPT-4.1 Mini (Faster, Cheaper)' },
                { value: 'gpt-4.1-nano', label: 'GPT-4.1 Nano (Low Cost)' },
                { value: '', label: '— Reasoning Models —', disabled: true },
                { value: 'o4-mini', label: 'o4-mini (Advanced Reasoning)' },
                { value: 'o3', label: 'o3 (High-End Reasoning)' },
                { value: 'o3-mini', label: 'o3-mini (Lightweight Reasoning)' },
                { value: '', label: '— Legacy —', disabled: true },
                { value: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
                { value: 'gpt-4', label: 'GPT-4' },
            ]}
            onChange={(value) => onChange('openai_model', value)}
            help="Default model for all sites. Can be locked to enforce network-wide."
        />

        <TextControl
            label="Default Vector Store ID"
            value={settings.openai_vector_store_id || ''}
            onChange={(value) => onChange('openai_vector_store_id', value)}
            help="Optional network-wide vector store ID. Sites typically create their own."
        />

        <RangeControl
            label={`Max Tokens Per Response: ${(settings.max_tokens_per_response || 1000).toLocaleString()}`}
            value={settings.max_tokens_per_response || 1000}
            onChange={(value) => onChange('max_tokens_per_response', value)}
            min={100}
            max={4000}
            step={100}
            help="Default maximum tokens the AI can use per response."
        />
    </div>
);

/**
 * Rate Limits Tab (Network)
 */
const NetworkCostsTab = ({ settings, onChange }) => (
    <div className="glimmr-settings-section glimmr-settings-wide-controls">
        <h3>Network Rate Limiting Defaults</h3>
        <p className="description">
            Set default rate limits for all sites. Lock these to enforce network-wide limits.
        </p>

        <RangeControl
            label={`Authenticated User Rate Limit: ${settings.rate_limit_authenticated || 100} requests/hour`}
            value={settings.rate_limit_authenticated || 100}
            onChange={(value) => onChange('rate_limit_authenticated', value)}
            min={10}
            max={500}
            step={10}
            help="Default max requests per hour for logged-in users."
        />

        <RangeControl
            label={`Anonymous User Rate Limit: ${settings.rate_limit_anonymous || 20} requests/hour`}
            value={settings.rate_limit_anonymous || 20}
            onChange={(value) => onChange('rate_limit_anonymous', value)}
            min={5}
            max={100}
            step={5}
            help="Default max requests per hour for guests."
        />

        <h3>Network Token Budgets</h3>
        <p className="description">
            Lock these to enforce network-wide token limits and control costs.
        </p>

        <TokenLimitControl
            label="Daily Token Limit"
            value={settings.daily_token_limit || 100000}
            onChange={(value) => onChange('daily_token_limit', value)}
            min={10000}
            max={1000000}
            step={10000}
            help="Default daily token budget per site."
        />

        <TokenLimitControl
            label="Monthly Token Limit"
            value={settings.monthly_token_limit || 2000000}
            onChange={(value) => onChange('monthly_token_limit', value)}
            min={100000}
            max={20000000}
            step={100000}
            help="Default monthly token budget per site."
        />

        <h3>Conversation Limits</h3>

        <RangeControl
            label={`Max Messages Per Conversation: ${settings.max_messages_per_conversation || 50}`}
            value={settings.max_messages_per_conversation || 50}
            onChange={(value) => onChange('max_messages_per_conversation', value)}
            min={10}
            max={200}
            step={10}
            help="Default max messages before conversation closes."
        />

        <RangeControl
            label={`Conversation Expiry: ${settings.conversation_expiry_days || 30} days`}
            value={settings.conversation_expiry_days || 30}
            onChange={(value) => onChange('conversation_expiry_days', value)}
            min={1}
            max={365}
            step={1}
            help="Default days of inactivity before expiry."
        />
    </div>
);

/**
 * Widget Appearance Tab (Network Defaults)
 */
const NetworkAppearanceTab = ({ settings, onChange }) => (
    <div className="glimmr-settings-section">
        <h3>Widget Position</h3>

        <SelectControl
            label="Default Position"
            value={settings.widget_position || 'bottom-right'}
            options={[
                { value: 'bottom-right', label: 'Bottom Right' },
                { value: 'bottom-left', label: 'Bottom Left' },
            ]}
            onChange={(value) => onChange('widget_position', value)}
        />

        <h3>Default Widget Size</h3>

        <RangeControl
            label={`Width: ${settings.widget_width || 400}px`}
            value={settings.widget_width || 400}
            onChange={(value) => onChange('widget_width', value)}
            min={300}
            max={600}
            step={10}
        />

        <RangeControl
            label={`Height: ${settings.widget_height || 650}px`}
            value={settings.widget_height || 650}
            onChange={(value) => onChange('widget_height', value)}
            min={400}
            max={800}
            step={10}
        />

        <h3>Default Brand Colors</h3>
        <p className="description">
            Sites can override these unless appearance settings are locked.
        </p>

        <div className="glimmr-color-picker-row">
            <div className="glimmr-color-picker-item">
                <label>Primary Color</label>
                <ColorPicker
                    color={settings.widget_primary_color || '#4F46E5'}
                    onChangeComplete={(color) => onChange('widget_primary_color', color.hex)}
                    disableAlpha
                />
            </div>

            <div className="glimmr-color-picker-item">
                <label>Primary Hover</label>
                <ColorPicker
                    color={settings.widget_primary_hover || '#4338CA'}
                    onChangeComplete={(color) => onChange('widget_primary_hover', color.hex)}
                    disableAlpha
                />
            </div>

            <div className="glimmr-color-picker-item">
                <label>Secondary Color</label>
                <ColorPicker
                    color={settings.widget_secondary_color || '#818CF8'}
                    onChangeComplete={(color) => onChange('widget_secondary_color', color.hex)}
                    disableAlpha
                />
            </div>
        </div>

        <h3>Default Branding</h3>

        <TextControl
            label="Default Assistant Name"
            value={settings.widget_name || 'Shopping Assistant'}
            onChange={(value) => onChange('widget_name', value)}
            help="Default name displayed in the widget header."
        />

        <TextareaControl
            label="Default Greeting"
            value={settings.widget_greeting || 'Hi! How can I help you today?'}
            onChange={(value) => onChange('widget_greeting', value)}
            help="Default greeting message. HTML allowed."
            rows={3}
        />
    </div>
);

/**
 * Agent Configuration Tab (Network)
 */
const NetworkAgentTab = ({ settings, onChange, defaultPrompt }) => (
    <div className="glimmr-settings-section">
        <h3>Default Agent Tone</h3>

        <SelectControl
            label="Conversation Tone"
            value={settings.agent_tone || 'friendly'}
            options={[
                { value: 'friendly', label: 'Friendly & Helpful' },
                { value: 'professional', label: 'Professional & Formal' },
                { value: 'casual', label: 'Casual & Conversational' },
            ]}
            onChange={(value) => onChange('agent_tone', value)}
        />

        <TextareaControl
            label="Default Personality Notes"
            value={settings.agent_personality || ''}
            onChange={(value) => onChange('agent_personality', value)}
            help="Personality guidelines applied network-wide."
            rows={4}
        />

        <h3>System Prompt</h3>
        <p className="description">
            The system prompt defines the AI's behavior. Leave empty to use the built-in prompt.
        </p>

        <TextareaControl
            label="Custom System Prompt (Network Default)"
            value={settings.system_prompt || ''}
            onChange={(value) => onChange('system_prompt', value)}
            help="Custom prompt that all sites will use by default. Leave empty for built-in prompt."
            rows={8}
            placeholder={defaultPrompt}
        />

        <h3>Default Fallback Behavior</h3>

        <TextareaControl
            label="Fallback Response"
            value={settings.fallback_response || "I'm not sure about that. Would you like to speak with our support team?"}
            onChange={(value) => onChange('fallback_response', value)}
            help="Default response when the AI cannot answer."
            rows={3}
        />

        <TextControl
            label="Default Support Email"
            type="email"
            value={settings.support_email || ''}
            onChange={(value) => onChange('support_email', value)}
        />

        <TextControl
            label="Default Support Phone"
            value={settings.support_phone || ''}
            onChange={(value) => onChange('support_phone', value)}
        />
    </div>
);

/**
 * Tools Configuration Tab (Network)
 */
const NetworkToolsTab = ({ settings, onChange }) => {
    const allTools = [
        { id: 'text_answer', name: 'Text Answer', description: 'Direct text responses' },
        { id: 'product_lookup', name: 'Product Lookup', description: 'Search products' },
        { id: 'product_compare', name: 'Product Compare', description: 'Compare products' },
        { id: 'stock_check', name: 'Stock Check', description: 'Check availability' },
        { id: 'recommendations', name: 'Recommendations', description: 'Product suggestions' },
        { id: 'add_to_cart', name: 'Add to Cart', description: 'Add items to cart' },
        { id: 'view_cart', name: 'View Cart', description: 'View cart contents' },
        { id: 'update_cart', name: 'Update Cart', description: 'Modify cart quantities' },
        { id: 'apply_coupon', name: 'Apply Coupon', description: 'Apply discount codes' },
        { id: 'coupon_lookup', name: 'Coupon Lookup', description: 'Find coupons' },
        { id: 'order_status', name: 'Order Status', description: 'Track orders' },
        { id: 'order_history', name: 'Order History', description: 'View past orders' },
        { id: 'checkout_link', name: 'Checkout Link', description: 'Generate checkout URL' },
        { id: 'account_info', name: 'Account Info', description: 'Customer account details' },
        { id: 'site_knowledge', name: 'Site Knowledge', description: 'Query knowledge base' },
    ];

    const enabledTools = settings.enabled_tools || {};
    const networkAllowedTools = settings.network_allowed_tools || allTools.map(t => t.id);

    const handleToolToggle = (toolId, checked) => {
        const updated = { ...enabledTools, [toolId]: checked };
        onChange('enabled_tools', updated);
    };

    const handleAllowedToggle = (toolId, allowed) => {
        let updated = [...networkAllowedTools];
        if (allowed && !updated.includes(toolId)) {
            updated.push(toolId);
        } else if (!allowed) {
            updated = updated.filter(id => id !== toolId);
        }
        onChange('network_allowed_tools', updated);
    };

    return (
        <div className="glimmr-settings-section">
            <h3>Network Default Tools</h3>
            <p className="description">
                Configure which tools are enabled by default, and which tools sites are allowed to enable.
            </p>

            <div className="glimmr-network-tools-grid">
                <div className="glimmr-tools-header">
                    <span>Tool</span>
                    <span>Enabled by Default</span>
                    <span>Sites Can Enable</span>
                </div>

                {allTools.map((tool) => (
                    <div key={tool.id} className="glimmr-network-tool-row">
                        <div className="glimmr-tool-info">
                            <strong>{tool.name}</strong>
                            <span className="description">{tool.description}</span>
                        </div>
                        <ToggleControl
                            checked={enabledTools[tool.id] !== false}
                            onChange={(checked) => handleToolToggle(tool.id, checked)}
                        />
                        <ToggleControl
                            checked={networkAllowedTools.includes(tool.id)}
                            onChange={(allowed) => handleAllowedToggle(tool.id, allowed)}
                        />
                    </div>
                ))}
            </div>

            <p className="glimmr-tools-note">
                <strong>Note:</strong> If "Sites Can Enable" is off, sites cannot enable that tool even if they try.
            </p>
        </div>
    );
};

/**
 * Locked Settings Tab (Network)
 */
const NetworkLocksTab = ({ settings, onChange, lockableSettings }) => {
    const lockedSettings = settings.locked_settings || [];

    const lockGroups = {
        'API & Costs': [
            { key: 'openai_api_key_encrypted', label: 'OpenAI API Key', desc: 'Force all sites to use the network API key' },
            { key: 'openai_model', label: 'AI Model', desc: 'Enforce specific model across network' },
            { key: 'daily_token_limit', label: 'Daily Token Limit', desc: 'Prevent sites from exceeding budget' },
            { key: 'monthly_token_limit', label: 'Monthly Token Limit', desc: 'Prevent sites from exceeding budget' },
            { key: 'rate_limit_authenticated', label: 'Authenticated Rate Limit', desc: 'Enforce rate limits' },
            { key: 'rate_limit_anonymous', label: 'Anonymous Rate Limit', desc: 'Enforce rate limits' },
        ],
        'Tools & Behavior': [
            { key: 'enabled_tools', label: 'Enabled Tools', desc: 'Lock which tools are available' },
            { key: 'system_prompt', label: 'System Prompt', desc: 'Enforce network system prompt' },
            { key: 'widget_enabled', label: 'Widget Enabled', desc: 'Control widget visibility network-wide' },
        ],
        'Appearance': [
            { key: 'widget_primary_color', label: 'Primary Color', desc: 'Enforce brand color' },
            { key: 'widget_name', label: 'Assistant Name', desc: 'Enforce assistant name' },
        ],
        'Privacy': [
            { key: 'gdpr_enabled', label: 'GDPR Enabled', desc: 'Enforce consent requirements' },
            { key: 'data_retention_days', label: 'Data Retention', desc: 'Enforce retention policy' },
        ],
    };

    const handleLockToggle = (key, isLocked) => {
        let updated = [...lockedSettings];
        if (isLocked && !updated.includes(key)) {
            updated.push(key);
        } else if (!isLocked) {
            updated = updated.filter(k => k !== key);
        }
        onChange('locked_settings', updated);
    };

    return (
        <div className="glimmr-settings-section">
            <h3>Locked Settings</h3>
            <p className="description">
                Locked settings cannot be overridden by individual sites.
                Use this to enforce network-wide policies.
            </p>

            {Object.entries(lockGroups).map(([groupName, groupSettings]) => (
                <div key={groupName} className="glimmr-lock-group">
                    <h4>{groupName}</h4>
                    {groupSettings.map((setting) => (
                        <div key={setting.key} className="glimmr-lock-item">
                            <CheckboxControl
                                label={setting.label}
                                help={setting.desc}
                                checked={lockedSettings.includes(setting.key)}
                                onChange={(checked) => handleLockToggle(setting.key, checked)}
                            />
                            {lockedSettings.includes(setting.key) && (
                                <span className="glimmr-lock-badge">
                                    <span className="dashicons dashicons-lock"></span>
                                    Locked
                                </span>
                            )}
                        </div>
                    ))}
                </div>
            ))}
        </div>
    );
};

/**
 * Network Sites Tab
 */
const NetworkSitesTab = ({ sites, loading, onRefresh }) => {
    if (loading) {
        return (
            <div className="glimmr-settings-section">
                <Spinner />
                <p>Loading network sites...</p>
            </div>
        );
    }

    return (
        <div className="glimmr-settings-section">
            <h3>Network Sites Overview</h3>
            <p className="description">
                View all sites in the network and their Glimmr AI configuration status.
            </p>

            <Button variant="secondary" onClick={onRefresh} className="glimmr-refresh-sites">
                <span className="dashicons dashicons-update"></span>
                Refresh List
            </Button>

            <div className="glimmr-network-sites-list">
                <div className="glimmr-sites-header">
                    <span>Site</span>
                    <span>Widget</span>
                    <span>Settings</span>
                    <span>API Key</span>
                </div>

                {sites.map((site) => (
                    <div key={site.id} className="glimmr-site-row">
                        <div className="glimmr-site-info">
                            <strong>{site.name}</strong>
                            <a href={site.url} target="_blank" rel="noopener noreferrer">
                                {site.url}
                            </a>
                        </div>
                        <div className="glimmr-site-status">
                            {site.widget_enabled ? (
                                <span className="glimmr-status-enabled">
                                    <span className="dashicons dashicons-yes-alt"></span>
                                    Enabled
                                </span>
                            ) : (
                                <span className="glimmr-status-disabled">
                                    <span className="dashicons dashicons-dismiss"></span>
                                    Disabled
                                </span>
                            )}
                        </div>
                        <div className="glimmr-site-status">
                            {site.inherits_settings ? (
                                <span className="glimmr-status-inherited">
                                    <span className="dashicons dashicons-admin-links"></span>
                                    Inherits Network
                                </span>
                            ) : (
                                <span className="glimmr-status-custom">
                                    <span className="dashicons dashicons-admin-generic"></span>
                                    Custom
                                </span>
                            )}
                        </div>
                        <div className="glimmr-site-status">
                            {site.has_api_key ? (
                                <span className="glimmr-status-enabled">
                                    <span className="dashicons dashicons-yes-alt"></span>
                                    Own Key
                                </span>
                            ) : (
                                <span className="glimmr-status-inherited">
                                    <span className="dashicons dashicons-admin-links"></span>
                                    Network Key
                                </span>
                            )}
                        </div>
                    </div>
                ))}

                {sites.length === 0 && (
                    <p className="glimmr-no-sites">No sites found in the network.</p>
                )}
            </div>

            <p className="description">
                <strong>Total Sites:</strong> {sites.length}
            </p>
        </div>
    );
};

/**
 * Main Network Settings Component
 */
const NetworkSettings = () => {
    const [settings, setSettings] = useState({});
    const [sites, setSites] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadingSites, setLoadingSites] = useState(false);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);
    const [activeTab, setActiveTab] = useState('api');

    const { ajaxUrl, nonce, defaultPrompt, lockableSettings } = window.glimmrAI || {};

    /**
     * Fetch settings on mount.
     */
    useEffect(() => {
        fetchSettings();
        fetchSites();
    }, []);

    /**
     * Fetch network settings.
     */
    const fetchSettings = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_network_settings');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setSettings(result.data || {});
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to load settings.' });
            }
        } catch (err) {
            console.error('Network settings fetch error:', err);
            setNotice({ type: 'error', message: 'Failed to connect to server.' });
        }

        setLoading(false);
    };

    /**
     * Fetch network sites.
     */
    const fetchSites = async () => {
        setLoadingSites(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_network_sites');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setSites(result.data?.sites || []);
            }
        } catch (err) {
            console.error('Network sites fetch error:', err);
        }

        setLoadingSites(false);
    };

    /**
     * Handle setting change.
     */
    const handleChange = useCallback((key, value) => {
        setSettings((prev) => ({
            ...prev,
            [key]: value,
        }));
    }, []);

    /**
     * Save network settings.
     */
    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_save_network_settings');
            formData.append('nonce', nonce);

            // Serialize settings for POST
            Object.entries(settings).forEach(([key, value]) => {
                if (typeof value === 'object') {
                    formData.append(`settings[${key}]`, JSON.stringify(value));
                } else {
                    formData.append(`settings[${key}]`, value);
                }
            });

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Network settings saved successfully.' });
                // Update settings with returned data
                if (result.data?.settings) {
                    setSettings(result.data.settings);
                }
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to save settings.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to connect to server.' });
            console.error('Network settings save error:', err);
        }

        setSaving(false);
    };

    /**
     * Render tab content.
     */
    const renderTabContent = (tabName) => {
        switch (tabName) {
            case 'api':
                return <NetworkApiTab settings={settings} onChange={handleChange} />;
            case 'costs':
                return <NetworkCostsTab settings={settings} onChange={handleChange} />;
            case 'appearance':
                return <NetworkAppearanceTab settings={settings} onChange={handleChange} />;
            case 'agent':
                return <NetworkAgentTab settings={settings} onChange={handleChange} defaultPrompt={defaultPrompt} />;
            case 'tools':
                return <NetworkToolsTab settings={settings} onChange={handleChange} />;
            case 'locks':
                return <NetworkLocksTab settings={settings} onChange={handleChange} lockableSettings={lockableSettings} />;
            case 'sites':
                return <NetworkSitesTab sites={sites} loading={loadingSites} onRefresh={fetchSites} />;
            default:
                return null;
        }
    };

    if (loading) {
        return (
            <div className="glimmr-settings-loading">
                <Spinner />
                <p>Loading network settings...</p>
            </div>
        );
    }

    return (
        <div className="glimmr-settings glimmr-network-settings">
            {notice && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice(null)}
                >
                    {notice.message}
                </Notice>
            )}

            <div className="glimmr-settings-tabs">
                <div className="glimmr-settings-sidebar">
                    {NETWORK_TABS.map((tab) => (
                        <button
                            key={tab.name}
                            className={`glimmr-settings-tab ${activeTab === tab.name ? 'is-active' : ''}`}
                            onClick={() => setActiveTab(tab.name)}
                        >
                            <span className={`dashicons dashicons-${tab.icon}`}></span>
                            {tab.title}
                        </button>
                    ))}
                </div>

                <div className="glimmr-settings-content">
                    <Card>
                        <CardHeader>
                            <h2>{NETWORK_TABS.find((t) => t.name === activeTab)?.title}</h2>
                        </CardHeader>
                        <CardBody>
                            {renderTabContent(activeTab)}
                        </CardBody>
                    </Card>

                    {activeTab !== 'sites' && (
                        <div className="glimmr-settings-actions">
                            <Button
                                variant="primary"
                                onClick={handleSave}
                                disabled={saving}
                            >
                                {saving ? 'Saving...' : 'Save Network Settings'}
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default NetworkSettings;
