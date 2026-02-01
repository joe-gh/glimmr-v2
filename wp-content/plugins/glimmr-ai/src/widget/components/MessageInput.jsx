/**
 * MessageInput - User Input Component
 *
 * Text input with send button for composing messages.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState, useRef, useCallback } from 'preact/hooks';

/**
 * Send icon
 */
const SendIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
        <path d="M22 2L11 13" />
        <path d="M22 2L15 22L11 13L2 9L22 2Z" />
    </svg>
);

/**
 * MessageInput Component
 */
const MessageInput = ({ onSend, isLoading, placeholder }) => {
    const [value, setValue] = useState('');
    const inputRef = useRef(null);

    /**
     * Handle form submission.
     */
    const handleSubmit = useCallback(
        (e) => {
            e.preventDefault();
            if (value.trim() && !isLoading) {
                onSend(value);
                setValue('');
                // Reset textarea height to default after sending.
                if (inputRef.current) {
                    inputRef.current.style.height = 'auto';
                }
                inputRef.current?.focus();
            }
        },
        [value, isLoading, onSend]
    );

    /**
     * Handle keyboard shortcuts.
     */
    const handleKeyDown = useCallback(
        (e) => {
            // Submit on Enter (without Shift)
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSubmit(e);
            }
        },
        [handleSubmit]
    );

    /**
     * Handle input change with auto-resize for textarea.
     */
    const handleChange = useCallback((e) => {
        setValue(e.target.value);

        // Auto-resize textarea
        if (inputRef.current) {
            inputRef.current.style.height = 'auto';
            inputRef.current.style.height = Math.min(inputRef.current.scrollHeight, 120) + 'px';
        }
    }, []);

    const canSend = value.trim().length > 0 && !isLoading;

    return (
        <form className="glimmr-input-form" onSubmit={handleSubmit}>
            <div className="glimmr-input-wrapper">
                <label htmlFor="glimmr-message-input" className="glimmr-sr-only">
                    Type your message
                </label>
                <textarea
                    ref={inputRef}
                    id="glimmr-message-input"
                    className="glimmr-input"
                    value={value}
                    onInput={handleChange}
                    onKeyDown={handleKeyDown}
                    placeholder={placeholder}
                    disabled={isLoading}
                    rows={1}
                    aria-describedby="glimmr-input-hint"
                />

                <button
                    type="submit"
                    className={`glimmr-send-btn ${canSend ? 'is-active' : ''}`}
                    disabled={!canSend}
                    aria-label={isLoading ? 'Sending message...' : 'Send message'}
                >
                    {isLoading ? (
                        <span className="glimmr-send-loading" aria-hidden="true" />
                    ) : (
                        <SendIcon />
                    )}
                </button>
            </div>

            <div id="glimmr-input-hint" className="glimmr-input-hint">
                Press Enter to send, Shift+Enter for new line
            </div>

            {/* Screen reader announcement for loading state */}
            {isLoading && (
                <div className="glimmr-sr-only" role="status" aria-live="polite">
                    Sending your message, please wait...
                </div>
            )}
        </form>
    );
};

export default MessageInput;
