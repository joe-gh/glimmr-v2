/**
 * Settings Section Component
 *
 * Reusable wrapper for settings sections with title and description.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * SettingsSection - Wrapper for grouped settings.
 *
 * @param {Object} props
 * @param {string} props.title - Section title
 * @param {string} [props.description] - Optional description text
 * @param {React.ReactNode} props.children - Section content
 * @param {string} [props.className] - Additional CSS class
 */
const SettingsSection = ({ title, description, children, className = '' }) => {
    return (
        <div className={`glimmr-settings-section ${className}`}>
            {title && <h3>{title}</h3>}
            {description && <p className="description">{description}</p>}
            {children}
        </div>
    );
};

export default SettingsSection;
