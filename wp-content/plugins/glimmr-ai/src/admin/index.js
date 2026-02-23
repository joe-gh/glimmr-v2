/**
 * Glimmr AI Admin React App
 *
 * Entry point for the admin React components.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import './styles/admin.scss';

// Import React components
import GetStarted from './components/GetStarted';
import Dashboard from './components/Dashboard';
import Settings from './components/Settings';
import KnowledgeManager from './components/KnowledgeManager';
import PromptsTools from './components/PromptsTools';
import Conversations from './components/Conversations';
import ContactRequests from './components/ContactRequests';
import NetworkSettings from './components/NetworkSettings';

const { render, createElement } = wp.element;

/**
 * Map of container IDs to components.
 * These match the div IDs in the PHP admin class render methods.
 */
const CONTAINER_COMPONENTS = {
    'glimmr-ai-get-started-root': GetStarted,
    'glimmr-ai-dashboard-root': Dashboard,
    'glimmr-ai-settings-root': Settings,
    'glimmr-ai-knowledge-root': KnowledgeManager,
    'glimmr-ai-prompts-root': PromptsTools,
    'glimmr-ai-conversations-root': Conversations,
    'glimmr-ai-contact-requests-root': ContactRequests,
    'glimmr-ai-network-root': NetworkSettings,
};

/**
 * Initialize the admin React app.
 */
const initAdmin = () => {
    // Find and render the appropriate component based on the container that exists
    for (const [containerId, Component] of Object.entries(CONTAINER_COMPONENTS)) {
        const container = document.getElementById(containerId);

        if (container) {
            try {
                render(createElement(Component), container);
            } catch (err) {
                console.error(`Failed to render ${containerId}:`, err);
                container.innerHTML = '<div class="notice notice-error"><p>Failed to load admin interface. Please refresh the page.</p></div>';
            }
            return;
        }
    }
};

/**
 * Wait for DOM ready, then initialize.
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdmin);
} else {
    initAdmin();
}
