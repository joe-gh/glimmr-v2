/**
 * Settings Utility Functions
 *
 * Shared helpers for settings components.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * Format large numbers with K/M suffix for display.
 *
 * @param {number} num - The number to format
 * @returns {string} Formatted string like "100K" or "2.5M"
 */
export const formatNumber = (num) => {
    if (num === null || num === undefined) return '0';
    if (num >= 1000000) {
        const millions = num / 1000000;
        return millions % 1 === 0 ? `${millions}M` : `${millions.toFixed(1)}M`;
    }
    if (num >= 1000) {
        const thousands = num / 1000;
        return thousands % 1 === 0 ? `${thousands}K` : `${thousands.toFixed(1)}K`;
    }
    return num.toLocaleString();
};

/**
 * Format number with full locale string (e.g., "1,000,000")
 *
 * @param {number} num - The number to format
 * @returns {string} Formatted string with commas
 */
export const formatFullNumber = (num) => {
    if (num === null || num === undefined) return '0';
    return num.toLocaleString();
};

/**
 * Clamp a value between min and max
 *
 * @param {number} value - Value to clamp
 * @param {number} min - Minimum value
 * @param {number} max - Maximum value
 * @returns {number} Clamped value
 */
export const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

/**
 * Get reasoning effort options for the current model.
 *
 * @param {Object} modelConfigs - Model configuration object
 * @param {string} modelId - Current model ID
 * @returns {Object|null} Reasoning options or null if not supported
 */
export const getReasoningEffortOptions = (modelConfigs, modelId) => {
    const config = modelConfigs?.[modelId];
    if (!config?.reasoning_effort?.supported) {
        return null;
    }

    const available = config.reasoning_effort.available || [];
    const defaultValue = config.reasoning_effort.default || 'low';

    const labels = {
        none: { label: 'None (No Reasoning)', desc: 'Fastest - no extended thinking' },
        low: { label: 'Low', desc: 'Fast responses with basic reasoning' },
        medium: { label: 'Medium', desc: 'Balanced speed and reasoning depth' },
        high: { label: 'High', desc: 'More thorough reasoning, slower' },
        xhigh: { label: 'Extra High', desc: 'Maximum reasoning depth, slowest' },
    };

    return {
        available: available.map(level => ({
            value: level,
            label: labels[level]?.label || level,
            description: labels[level]?.desc || '',
        })),
        default: defaultValue,
    };
};
