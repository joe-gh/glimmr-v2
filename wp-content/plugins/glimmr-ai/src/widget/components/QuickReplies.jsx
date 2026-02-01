/**
 * QuickReplies - Suggested Questions Component
 *
 * Displays clickable quick reply buttons for common questions.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';

/**
 * QuickReplies Component
 */
const QuickReplies = ({ replies, onSelect }) => {
    if (!replies || replies.length === 0) {
        return null;
    }

    return (
        <div className="glimmr-quick-replies" role="group" aria-label="Suggested questions">
            {replies.map((reply, index) => (
                <button
                    key={index}
                    className="glimmr-quick-reply"
                    onClick={() => onSelect(reply.action || reply.text)}
                    aria-label={reply.text}
                >
                    {reply.text}
                </button>
            ))}
        </div>
    );
};

export default QuickReplies;
