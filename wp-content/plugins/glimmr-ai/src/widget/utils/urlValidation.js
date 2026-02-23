/**
 * URL Validation Utility
 *
 * Validates URLs for safe navigation to prevent open redirect attacks.
 * Blocks javascript:, data:, vbscript:, and external URLs.
 *
 * @package Glimmr_AI
 * @since 1.9.0
 */

/**
 * Validate that a URL is safe for navigation (same-origin or relative).
 * Blocks javascript:, data:, and external URLs.
 *
 * @param {string} url - URL to validate.
 * @returns {boolean} True if the URL is safe for navigation.
 */
export const isSafeUrl = (url) => {
    if (!url || typeof url !== 'string') return false;

    const trimmed = url.trim().toLowerCase();

    // Block dangerous schemes
    if (trimmed.startsWith('javascript:') || trimmed.startsWith('data:') || trimmed.startsWith('vbscript:')) {
        return false;
    }

    // Allow relative URLs
    if (url.startsWith('/') || url.startsWith('./') || url.startsWith('../')) {
        return true;
    }

    // Allow same-origin absolute URLs
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin;
    } catch {
        return false;
    }
};
