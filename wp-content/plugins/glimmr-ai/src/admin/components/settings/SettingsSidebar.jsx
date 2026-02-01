/**
 * Settings Sidebar Component
 *
 * Left sidebar navigation for major settings categories.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * SettingsSidebar - Left navigation for major categories.
 *
 * @param {Object} props
 * @param {Array} props.categories - Array of category objects
 * @param {string} props.activeCategory - Currently active category ID
 * @param {Function} props.onCategoryChange - Callback when category changes
 */
const SettingsSidebar = ({ categories, activeCategory, onCategoryChange }) => {
    return (
        <nav className="glimmr-settings-sidebar" role="navigation" aria-label="Settings categories">
            {categories.map((category) => (
                <button
                    key={category.id}
                    type="button"
                    className={`glimmr-settings-sidebar-item ${activeCategory === category.id ? 'is-active' : ''}`}
                    onClick={() => onCategoryChange(category.id)}
                    aria-current={activeCategory === category.id ? 'page' : undefined}
                >
                    <span className={`dashicons dashicons-${category.icon}`} aria-hidden="true"></span>
                    <span className="glimmr-sidebar-label">{category.label}</span>
                </button>
            ))}
        </nav>
    );
};

export default SettingsSidebar;
