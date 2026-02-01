/**
 * Shared Settings Controls
 *
 * Reusable control components for settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    RangeControl,
    TextControl,
    Button,
    Icon,
} = wp.components;

import { formatNumber, clamp } from './utils';

/**
 * HelpText Component - Displays help text with a visible help icon.
 *
 * @param {Object} props
 * @param {string|React.ReactNode} props.children - Help text content
 * @param {string} [props.type='info'] - Type: 'info', 'warning', 'tip'
 */
export const HelpText = ({ children, type = 'info' }) => {
    const icons = {
        info: 'info-outline',
        warning: 'warning',
        tip: 'lightbulb',
    };

    const colors = {
        info: '#2271b1',
        warning: '#dba617',
        tip: '#4F46E5',
    };

    return (
        <span className="glimmr-help-text" style={{ display: 'flex', alignItems: 'flex-start', gap: '6px' }}>
            <Icon
                icon={icons[type] || icons.info}
                size={16}
                style={{ color: colors[type] || colors.info, flexShrink: 0, marginTop: '1px' }}
            />
            <span>{children}</span>
        </span>
    );
};

/**
 * InfoBox Component - Styled information box for important notes.
 *
 * @param {Object} props
 * @param {string} [props.type='info'] - Type: 'info', 'warning', 'tip', 'success'
 * @param {string} [props.title] - Optional title
 * @param {React.ReactNode} props.children - Content
 */
export const InfoBox = ({ type = 'info', title, children }) => {
    const styles = {
        info: { bg: '#f0f6fc', border: '#c3d9ed', icon: 'info', iconColor: '#2271b1' },
        warning: { bg: '#fef8ee', border: '#f0c33c', icon: 'warning', iconColor: '#b26200' },
        tip: { bg: '#f0fdf4', border: '#86efac', icon: 'lightbulb', iconColor: '#16a34a' },
        success: { bg: '#f0fdf4', border: '#86efac', icon: 'yes-alt', iconColor: '#16a34a' },
    };

    const s = styles[type] || styles.info;

    return (
        <div className="glimmr-info-box" style={{
            background: s.bg,
            border: `1px solid ${s.border}`,
            borderRadius: '4px',
            padding: '12px 16px',
            marginTop: '16px',
            marginBottom: '16px',
        }}>
            {title && (
                <p style={{ margin: '0 0 8px', fontSize: '13px', fontWeight: 600, color: '#1d2327', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <span className={`dashicons dashicons-${s.icon}`} style={{ color: s.iconColor }}></span>
                    {title}
                </p>
            )}
            <div style={{ fontSize: '13px', color: '#50575e', lineHeight: 1.5 }}>
                {children}
            </div>
        </div>
    );
};

/**
 * Token Limit Control - Custom component for token limits with proper formatting.
 *
 * @param {Object} props
 * @param {string} props.label - Control label
 * @param {number} props.value - Current value
 * @param {Function} props.onChange - Change handler
 * @param {number} props.min - Minimum value
 * @param {number} props.max - Maximum value
 * @param {number} props.step - Step increment
 * @param {string} [props.help] - Help text
 */
export const TokenLimitControl = ({ label, value, onChange, min, max, step, help }) => {
    const displayValue = clamp(value || min, min, max);
    const actualValue = value || min;
    const isOutOfRange = actualValue > max || actualValue < min;

    return (
        <div className="glimmr-token-control">
            <div className="glimmr-token-control-header">
                <label className="components-base-control__label">{label}</label>
                <span className="glimmr-token-value">
                    {formatNumber(actualValue)} tokens
                    {isOutOfRange && <span className="glimmr-token-warning"> (out of range, will be adjusted)</span>}
                </span>
            </div>
            <RangeControl
                value={displayValue}
                onChange={onChange}
                min={min}
                max={max}
                step={step}
                withInputField={false}
                __nextHasNoMarginBottom
            />
            <div className="glimmr-token-range">
                <span>{formatNumber(min)}</span>
                <span>{formatNumber(max)}</span>
            </div>
            {help && <p className="components-base-control__help">{help}</p>}
        </div>
    );
};

/**
 * Quick Replies Editor Component
 *
 * @param {Object} props
 * @param {Array} props.replies - Array of reply objects { text, action }
 * @param {Function} props.onChange - Change handler
 */
export const QuickRepliesEditor = ({ replies, onChange }) => {
    const addReply = () => {
        onChange([...replies, { text: '', action: '' }]);
    };

    const updateReply = (index, field, value) => {
        const updated = [...replies];
        updated[index] = { ...updated[index], [field]: value };
        onChange(updated);
    };

    const removeReply = (index) => {
        onChange(replies.filter((_, i) => i !== index));
    };

    return (
        <div className="glimmr-quick-replies-editor">
            {replies.map((reply, index) => (
                <div key={index} className="glimmr-quick-reply-item">
                    <TextControl
                        label="Button Text"
                        value={reply.text}
                        onChange={(value) => updateReply(index, 'text', value)}
                        placeholder="e.g., Track my order"
                    />
                    <TextControl
                        label="Message to Send"
                        value={reply.action}
                        onChange={(value) => updateReply(index, 'action', value)}
                        placeholder="e.g., I want to track my order status"
                    />
                    <Button
                        variant="link"
                        isDestructive
                        onClick={() => removeReply(index)}
                    >
                        Remove
                    </Button>
                </div>
            ))}

            {replies.length < 5 && (
                <Button variant="secondary" onClick={addReply}>
                    Add Quick Reply
                </Button>
            )}
        </div>
    );
};
