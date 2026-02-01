/**
 * Settings Component
 *
 * Two-tier settings interface for Glimmr AI configuration.
 * Left sidebar for major categories, top tabs for sub-categories.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect, useCallback } = wp.element;
const {
    Card,
    CardBody,
    Button,
    Spinner,
    Notice,
} = wp.components;

import { NetworkInheritanceBanner } from './SettingInheritanceIndicator';

// Import layout components
import SettingsSidebar from './settings/SettingsSidebar';
import SettingsSubTabs from './settings/SettingsSubTabs';

// Import configuration
import {
    SETTINGS_CATEGORIES,
    parseUrlHash,
    buildUrlHash,
    getDefaultTab,
} from './settings/settingsConfig';

// Import tab components
import { ApiTab, CostsTab, SyncTab, ProductsTab, IntegrationsTab, SupportTab, AdvancedTab } from './settings/configuration';
import { PositionTab, ColorsTab, BrandingTab } from './settings/design';
import { ArtifactsTab, BehaviorTab, EngagementTab, AgentTab, TranslationsTab } from './settings/chat';
import { GdprTab, LoggingTab } from './settings/privacy';

/**
 * Main Settings Component
 */
const Settings = () => {
    const [settings, setSettings] = useState({});
    const [categories, setCategories] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [notice, setNotice] = useState(null);
    const [lockedSettings, setLockedSettings] = useState([]);

    // Initialize navigation from URL hash
    const initialNav = parseUrlHash();
    const [activeCategory, setActiveCategory] = useState(initialNav.category);
    const [activeTab, setActiveTab] = useState(initialNav.tab);

    const { ajaxUrl, nonce, isMultisite, isNetworkAdminPage } = window.glimmrAI || {};

    /**
     * Update URL hash when navigation changes.
     */
    const updateUrlHash = useCallback((category, tab) => {
        const hash = buildUrlHash(category, tab);
        if (window.location.hash !== `#${hash}`) {
            window.history.replaceState(null, '', `#${hash}`);
        }
    }, []);

    /**
     * Handle category change from sidebar.
     */
    const handleCategoryChange = useCallback((categoryId) => {
        setActiveCategory(categoryId);
        const defaultTab = getDefaultTab(categoryId);
        setActiveTab(defaultTab);
        updateUrlHash(categoryId, defaultTab);
    }, [updateUrlHash]);

    /**
     * Handle tab change from sub-tabs.
     */
    const handleTabChange = useCallback((tabId) => {
        setActiveTab(tabId);
        updateUrlHash(activeCategory, tabId);
    }, [activeCategory, updateUrlHash]);

    /**
     * Listen for browser back/forward navigation.
     */
    useEffect(() => {
        const handleHashChange = () => {
            const nav = parseUrlHash();
            setActiveCategory(nav.category);
            setActiveTab(nav.tab);
        };

        window.addEventListener('hashchange', handleHashChange);
        return () => window.removeEventListener('hashchange', handleHashChange);
    }, []);

    /**
     * Fetch settings on mount.
     */
    useEffect(() => {
        fetchSettings();
        fetchCategories();
    }, []);

    /**
     * Fetch current settings.
     */
    const fetchSettings = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_settings');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                const data = result.data || {};
                // Extract locked settings metadata if present
                if (data._locked_settings) {
                    setLockedSettings(data._locked_settings);
                    delete data._locked_settings;
                }
                setSettings(data);
            }
        } catch (err) {
            console.error('Settings fetch error:', err);
        }

        setLoading(false);
    };

    /**
     * Fetch product categories.
     */
    const fetchCategories = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_categories');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setCategories(result.data || []);
            }
        } catch (err) {
            console.error('Categories fetch error:', err);
        }
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
     * Save settings.
     */
    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_save_settings');
            formData.append('nonce', nonce);
            formData.append('settings', JSON.stringify(settings));

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Settings saved successfully.' });
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to save settings.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to connect to server.' });
            console.error('Settings save error:', err);
        }

        setSaving(false);
    };

    /**
     * Handle sync action.
     */
    const handleSync = async (type) => {
        setSyncing(true);

        try {
            const formData = new FormData();
            formData.append('action', `glimmr_ai_sync_${type}`);
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: result.data?.message || 'Sync started.' });
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Sync failed.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to start sync.' });
        }

        setSyncing(false);
    };

    /**
     * Handle inherit network toggle.
     */
    const handleInheritToggle = (inheritNetwork) => {
        setSettings((prev) => ({
            ...prev,
            inherit_network_settings: inheritNetwork,
        }));
    };

    /**
     * Render tab content based on current category and tab.
     */
    const renderTabContent = () => {
        const tabProps = { settings, onChange: handleChange };

        // Configuration tabs
        if (activeCategory === 'configuration') {
            switch (activeTab) {
                case 'api':
                    return <ApiTab {...tabProps} />;
                case 'costs':
                    return <CostsTab {...tabProps} />;
                case 'sync':
                    return <SyncTab {...tabProps} onSync={handleSync} syncing={syncing} />;
                case 'products':
                    return <ProductsTab {...tabProps} categories={categories} />;
                case 'integrations':
                    return <IntegrationsTab {...tabProps} />;
                case 'support':
                    return <SupportTab {...tabProps} />;
                case 'advanced':
                    return <AdvancedTab {...tabProps} />;
                default:
                    return <ApiTab {...tabProps} />;
            }
        }

        // Design tabs
        if (activeCategory === 'design') {
            switch (activeTab) {
                case 'position':
                    return <PositionTab {...tabProps} />;
                case 'colors':
                    return <ColorsTab {...tabProps} />;
                case 'branding':
                    return <BrandingTab {...tabProps} />;
                default:
                    return <PositionTab {...tabProps} />;
            }
        }

        // Chat Experience tabs
        if (activeCategory === 'chat') {
            switch (activeTab) {
                case 'artifacts':
                    return <ArtifactsTab {...tabProps} />;
                case 'behavior':
                    return <BehaviorTab {...tabProps} />;
                case 'engagement':
                    return <EngagementTab {...tabProps} />;
                case 'agent':
                    return <AgentTab {...tabProps} />;
                case 'translations':
                    return <TranslationsTab {...tabProps} />;
                default:
                    return <ArtifactsTab {...tabProps} />;
            }
        }

        // Privacy & Debug tabs
        if (activeCategory === 'privacy') {
            switch (activeTab) {
                case 'gdpr':
                    return <GdprTab {...tabProps} />;
                case 'logging':
                    return <LoggingTab {...tabProps} />;
                default:
                    return <GdprTab {...tabProps} />;
            }
        }

        return null;
    };

    // Get current category's tabs
    const currentCategory = SETTINGS_CATEGORIES.find(c => c.id === activeCategory);
    const currentTabs = currentCategory?.tabs || [];

    if (loading) {
        return (
            <div className="glimmr-settings-loading">
                <Spinner />
                <p>Loading settings...</p>
            </div>
        );
    }

    return (
        <div className="glimmr-settings">
            {notice && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice(null)}
                >
                    {notice.message}
                </Notice>
            )}

            {isMultisite && !isNetworkAdminPage && (
                <NetworkInheritanceBanner
                    inheritNetwork={settings.inherit_network_settings !== false}
                    onToggle={handleInheritToggle}
                />
            )}

            {lockedSettings.length > 0 && !isNetworkAdminPage && (
                <Notice
                    status="warning"
                    isDismissible={false}
                    className="glimmr-locked-notice"
                >
                    <span className="dashicons dashicons-lock"></span>
                    Some settings are locked by your network administrator and cannot be changed.
                </Notice>
            )}

            <div className="glimmr-settings-layout">
                <SettingsSidebar
                    categories={SETTINGS_CATEGORIES}
                    activeCategory={activeCategory}
                    onCategoryChange={handleCategoryChange}
                />

                <div className="glimmr-settings-main">
                    <SettingsSubTabs
                        tabs={currentTabs}
                        activeTab={activeTab}
                        onTabChange={handleTabChange}
                    />

                    <Card className="glimmr-settings-card">
                        <CardBody>
                            {renderTabContent()}
                        </CardBody>
                    </Card>

                    <div className="glimmr-settings-actions">
                        <Button
                            variant="primary"
                            onClick={handleSave}
                            disabled={saving}
                        >
                            {saving ? 'Saving...' : 'Save Settings'}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default Settings;
