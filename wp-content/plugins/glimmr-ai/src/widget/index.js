/**
 * Glimmr AI Chat Widget (Preact)
 *
 * Entry point for the Preact-based chat widget.
 * Renders into the #glimmr-ai-chat-widget container.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, render } from 'preact';
import ChatWidget from './components/ChatWidget';
import { initDebug, debug, debugWarn } from './utils/debug';
import { initGA4 } from './utils/ga4';
import './styles/widget.scss';

/**
 * Track if widget has been activated (for debug mode).
 */
let widgetActivated = false;

/**
 * Render the widget to the container.
 */
const renderWidget = (config) => {
    const container = document.getElementById('glimmr-ai-chat-widget');
    if (!container) {
        debugWarn('Glimmr AI: Widget container not found');
        return false;
    }

    // Initialize utilities before rendering
    initDebug(config);
    initGA4(config);

    render(h(ChatWidget, { config }), container);
    widgetActivated = true;

    debug('Glimmr AI Widget activated', config);

    return true;
};

/**
 * Initialize the chat widget.
 */
const initWidget = () => {
    // Get configuration from WordPress
    const config = window.glimmrAIWidget || {};

    // Initialize debug mode early so we can use it
    initDebug(config);

    // Find the container
    const container = document.getElementById('glimmr-ai-chat-widget');

    if (!container) {
        debugWarn('Glimmr AI: Widget container not found');
        return;
    }

    // Check if widget should be displayed (page include/exclude handled by PHP)
    if (config.disabled) {
        return;
    }

    // Debug mode: don't render automatically, wait for console activation
    if (config.debugMode) {
        // Always show debug mode notice in console (styled)
        console.log(
            '%c🔧 Glimmr AI Debug Mode Active',
            'background: #4F46E5; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold;'
        );
        console.log(
            '%cWidget hidden. To activate, run: %cwindow.activateGlimmrChat()',
            'color: #666;',
            'color: #4F46E5; font-weight: bold;'
        );
        return;
    }

    // Normal mode: render immediately
    renderWidget(config);
};

/**
 * Wait for DOM ready, then initialize.
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWidget);
} else {
    initWidget();
}

/**
 * Expose API for external access.
 */
window.GlimmrAI = {
    /**
     * Activate the widget (for debug mode).
     * This renders the widget if it hasn't been rendered yet.
     *
     * @returns {boolean} True if widget was activated, false if already active.
     */
    activate: () => {
        if (widgetActivated) {
            debug('Glimmr AI: Widget already active');
            return false;
        }

        const config = window.glimmrAIWidget || {};
        const success = renderWidget(config);

        if (success) {
            // Always show activation notice (styled)
            console.log(
                '%c✅ Glimmr AI Widget Activated',
                'background: #10B981; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold;'
            );
        }

        return success;
    },

    /**
     * Check if widget is currently active.
     *
     * @returns {boolean} True if widget is active.
     */
    isActive: () => widgetActivated,

    /**
     * Open the chat widget.
     */
    open: () => {
        const event = new CustomEvent('glimmr-widget-open');
        window.dispatchEvent(event);
    },

    /**
     * Close the chat widget.
     */
    close: () => {
        const event = new CustomEvent('glimmr-widget-close');
        window.dispatchEvent(event);
    },

    /**
     * Send a message programmatically.
     */
    sendMessage: (message) => {
        const event = new CustomEvent('glimmr-widget-send', { detail: { message } });
        window.dispatchEvent(event);
    },

    /**
     * Get current conversation ID.
     */
    getConversationId: () => {
        return localStorage.getItem('glimmr_ai_conversation_id');
    },

    /**
     * Start a new conversation.
     */
    newConversation: () => {
        const event = new CustomEvent('glimmr-widget-new-conversation');
        window.dispatchEvent(event);
    },
};

/**
 * Shorthand for activating the widget from console.
 * Usage: window.activateGlimmrChat()
 */
window.activateGlimmrChat = () => window.GlimmrAI.activate();
