/**
 * Setting Inheritance Indicator Component
 *
 * Shows whether a setting is inherited from the network or locked.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { Tooltip } = wp.components;

/**
 * Indicator for locked settings (cannot be changed by site).
 */
export const LockedIndicator = ({ settingName }) => (
    <span className="glimmr-setting-locked" title={`${settingName} is locked by the network administrator`}>
        <span className="dashicons dashicons-lock"></span>
        <span className="glimmr-indicator-text">Locked by Network</span>
    </span>
);

/**
 * Indicator for inherited settings (using network value).
 */
export const InheritedIndicator = ({ settingName }) => (
    <span className="glimmr-setting-inherited" title={`${settingName} is using the network default value`}>
        <span className="dashicons dashicons-admin-links"></span>
        <span className="glimmr-indicator-text">Network Default</span>
    </span>
);

/**
 * Indicator for overridden settings (site has custom value).
 */
export const OverriddenIndicator = ({ settingName }) => (
    <span className="glimmr-setting-overridden" title={`${settingName} has been customized for this site`}>
        <span className="dashicons dashicons-admin-generic"></span>
        <span className="glimmr-indicator-text">Site Override</span>
    </span>
);

/**
 * Combined indicator that shows the appropriate state.
 *
 * @param {Object} props Component props
 * @param {string} props.settingKey The setting key to check
 * @param {boolean} props.isLocked Whether the setting is locked by network
 * @param {boolean} props.isInherited Whether the setting is using inherited value
 * @param {boolean} props.showLabel Whether to show the text label (default: true)
 */
const SettingInheritanceIndicator = ({
    settingKey,
    isLocked = false,
    isInherited = false,
    showLabel = true,
}) => {
    // If not in multisite or no inheritance info, don't render anything
    const { isMultisite, isNetworkAdminPage } = window.glimmrAI || {};

    if (!isMultisite || isNetworkAdminPage) {
        return null;
    }

    const className = showLabel ? 'glimmr-inheritance-indicator' : 'glimmr-inheritance-indicator compact';

    if (isLocked) {
        return (
            <span className={`${className} locked`}>
                <span className="dashicons dashicons-lock"></span>
                {showLabel && <span className="glimmr-indicator-text">Locked</span>}
            </span>
        );
    }

    if (isInherited) {
        return (
            <span className={`${className} inherited`}>
                <span className="dashicons dashicons-admin-links"></span>
                {showLabel && <span className="glimmr-indicator-text">Network</span>}
            </span>
        );
    }

    return null;
};

/**
 * Wrapper component that applies inheritance indicators to form controls.
 *
 * @param {Object} props Component props
 * @param {string} props.settingKey The setting key
 * @param {boolean} props.isLocked Whether locked by network
 * @param {boolean} props.isInherited Whether using inherited value
 * @param {React.ReactNode} props.children The form control to wrap
 */
export const InheritanceWrapper = ({
    settingKey,
    isLocked = false,
    isInherited = false,
    children,
}) => {
    const { isMultisite, isNetworkAdminPage } = window.glimmrAI || {};

    if (!isMultisite || isNetworkAdminPage) {
        return children;
    }

    const wrapperClass = [
        'glimmr-setting-wrapper',
        isLocked ? 'is-locked' : '',
        isInherited ? 'is-inherited' : '',
    ].filter(Boolean).join(' ');

    return (
        <div className={wrapperClass}>
            <SettingInheritanceIndicator
                settingKey={settingKey}
                isLocked={isLocked}
                isInherited={isInherited}
            />
            <div className={`glimmr-setting-control ${isLocked ? 'disabled' : ''}`}>
                {children}
            </div>
            {isLocked && (
                <p className="glimmr-locked-notice">
                    This setting is controlled by your network administrator and cannot be changed.
                </p>
            )}
        </div>
    );
};

/**
 * Network settings banner for site admin pages.
 * Shows when the site is using inherited settings.
 */
export const NetworkInheritanceBanner = ({ inheritNetwork, onToggle }) => {
    const { isMultisite, isNetworkAdminPage } = window.glimmrAI || {};

    if (!isMultisite || isNetworkAdminPage) {
        return null;
    }

    return (
        <div className="glimmr-network-inheritance-banner">
            <div className="glimmr-banner-content">
                <span className="dashicons dashicons-networking"></span>
                <div className="glimmr-banner-text">
                    <strong>Network Configuration</strong>
                    <p>
                        {inheritNetwork
                            ? 'This site is using network default settings. You can override specific settings below.'
                            : 'This site has custom settings. Toggle on to use network defaults.'
                        }
                    </p>
                </div>
            </div>
            <label className="glimmr-inheritance-toggle">
                <input
                    type="checkbox"
                    checked={inheritNetwork}
                    onChange={(e) => onToggle(e.target.checked)}
                />
                <span className="glimmr-toggle-slider"></span>
                <span className="glimmr-toggle-label">
                    {inheritNetwork ? 'Using Network Defaults' : 'Custom Settings'}
                </span>
            </label>
        </div>
    );
};

export default SettingInheritanceIndicator;
