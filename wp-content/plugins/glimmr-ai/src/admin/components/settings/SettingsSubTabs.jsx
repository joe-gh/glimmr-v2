/**
 * Settings Sub-Tabs Component
 *
 * Horizontal tab navigation for minor categories within a major category.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * SettingsSubTabs - Horizontal sub-tab navigation.
 *
 * @param {Object} props
 * @param {Array} props.tabs - Array of tab objects { id, label }
 * @param {string} props.activeTab - Currently active tab ID
 * @param {Function} props.onTabChange - Callback when tab changes
 */
const SettingsSubTabs = ({ tabs, activeTab, onTabChange }) => {
    return (
        <div className="glimmr-settings-subtabs" role="tablist" aria-label="Settings sections">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    className={`glimmr-settings-subtab ${activeTab === tab.id ? 'is-active' : ''}`}
                    onClick={() => onTabChange(tab.id)}
                    aria-selected={activeTab === tab.id}
                    aria-controls={`tabpanel-${tab.id}`}
                    id={`tab-${tab.id}`}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
};

export default SettingsSubTabs;
