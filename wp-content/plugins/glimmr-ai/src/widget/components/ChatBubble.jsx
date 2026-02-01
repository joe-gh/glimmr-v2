/**
 * ChatBubble - Floating Chat Button
 *
 * The circular button that floats in the corner of the screen.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';

/**
 * Chat icon SVG
 */
const ChatIcon = () => (
    <svg
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
    >
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
);

/**
 * ChatBubble Component
 */
const ChatBubble = ({ config, hasNewMessage, onClick }) => {
    const avatarUrl = config.avatarUrl;

    return (
        <button
            className={`glimmr-bubble ${hasNewMessage ? 'has-notification' : ''}`}
            onClick={onClick}
            aria-label={`Open chat with ${config.name || 'Shopping Assistant'}`}
            title={config.name || 'Shopping Assistant'}
        >
            {avatarUrl ? (
                <img
                    src={avatarUrl}
                    alt={config.name || 'Chat'}
                    className="glimmr-bubble-avatar"
                />
            ) : (
                <span className="glimmr-bubble-icon">
                    <ChatIcon />
                </span>
            )}

            {hasNewMessage && (
                <span className="glimmr-bubble-badge" aria-label="New message">
                    1
                </span>
            )}

            {/* Pulse animation ring */}
            <span className="glimmr-bubble-pulse" aria-hidden="true" />
        </button>
    );
};

export default ChatBubble;
