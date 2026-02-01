/**
 * TypingIndicator - Loading State Component
 *
 * Shows animated dots and optional status message when
 * the AI is typing a response or executing tools.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';

/**
 * TypingIndicator Component
 *
 * @param {Object} props Component props.
 * @param {string} [props.statusMessage] Optional status message to display (e.g., "Searching products...").
 */
const TypingIndicator = ({ statusMessage = null }) => (
    <div className="glimmr-typing" aria-label={statusMessage || 'Assistant is typing'}>
        {statusMessage && (
            <span className="glimmr-typing-status">{statusMessage}</span>
        )}
        <div className="glimmr-typing-dots">
            <span className="glimmr-typing-dot" />
            <span className="glimmr-typing-dot" />
            <span className="glimmr-typing-dot" />
        </div>
    </div>
);

export default TypingIndicator;
