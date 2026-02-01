/**
 * Safe Number Utilities
 *
 * Type-safe number operations for handling API data that may come as
 * strings, numbers, null, or undefined.
 *
 * @package Glimmr_AI
 * @since 1.0.1
 */

/**
 * Safely convert a value to a number.
 * Returns 0 if conversion fails or value is null/undefined.
 *
 * @param {*} value - Value to convert
 * @param {number} fallback - Fallback value if conversion fails (default: 0)
 * @returns {number} - Numeric value
 */
export const toNumber = (value, fallback = 0) => {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }
    if (typeof value === 'number') {
        return isNaN(value) ? fallback : value;
    }
    const parsed = parseFloat(value);
    return isNaN(parsed) ? fallback : parsed;
};

/**
 * Safely format a number with toFixed.
 * Handles string inputs and NaN gracefully.
 *
 * @param {*} value - Value to format
 * @param {number} decimals - Number of decimal places
 * @param {string} fallback - Fallback string if conversion fails
 * @returns {string} - Formatted number string
 */
export const safeToFixed = (value, decimals = 2, fallback = '0.00') => {
    const num = toNumber(value, NaN);
    if (isNaN(num)) {
        return fallback;
    }
    return num.toFixed(decimals);
};

/**
 * Safely format a number with toLocaleString.
 * Handles string inputs and NaN gracefully.
 *
 * @param {*} value - Value to format
 * @param {string} fallback - Fallback string if conversion fails
 * @returns {string} - Formatted number string
 */
export const safeToLocaleString = (value, fallback = '0') => {
    const num = toNumber(value, NaN);
    if (isNaN(num)) {
        return fallback;
    }
    return num.toLocaleString();
};

/**
 * Safely apply Math.round to a value.
 *
 * @param {*} value - Value to round
 * @param {number} fallback - Fallback if conversion fails
 * @returns {number} - Rounded number
 */
export const safeRound = (value, fallback = 0) => {
    const num = toNumber(value, NaN);
    return isNaN(num) ? fallback : Math.round(num);
};

/**
 * Safely apply Math.floor to a value.
 *
 * @param {*} value - Value to floor
 * @param {number} fallback - Fallback if conversion fails
 * @returns {number} - Floored number
 */
export const safeFloor = (value, fallback = 0) => {
    const num = toNumber(value, NaN);
    return isNaN(num) ? fallback : Math.floor(num);
};

/**
 * Safely apply Math.ceil to a value.
 *
 * @param {*} value - Value to ceil
 * @param {number} fallback - Fallback if conversion fails
 * @returns {number} - Ceiled number
 */
export const safeCeil = (value, fallback = 0) => {
    const num = toNumber(value, NaN);
    return isNaN(num) ? fallback : Math.ceil(num);
};

/**
 * Format a price value safely.
 * Handles various price formats from WooCommerce (string "$59.99", number 59.99, etc.)
 *
 * @param {*} value - Price value
 * @param {string} currencySymbol - Currency symbol (default: "$")
 * @param {number} decimals - Decimal places (default: 2)
 * @returns {string} - Formatted price string
 */
export const formatPrice = (value, currencySymbol = '$', decimals = 2) => {
    // If already formatted string with currency symbol, return as-is
    if (typeof value === 'string' && value.includes(currencySymbol)) {
        return value;
    }

    // Strip currency symbols and parse
    const cleanValue = typeof value === 'string'
        ? value.replace(/[^0-9.-]/g, '')
        : value;

    const num = toNumber(cleanValue, NaN);
    if (isNaN(num)) {
        return `${currencySymbol}0.00`;
    }

    return `${currencySymbol}${num.toFixed(decimals)}`;
};

/**
 * Calculate discount percentage safely.
 *
 * @param {*} regularPrice - Original price
 * @param {*} salePrice - Sale price
 * @returns {number} - Discount percentage (0-100)
 */
export const calculateDiscountPercent = (regularPrice, salePrice) => {
    const regular = toNumber(regularPrice);
    const sale = toNumber(salePrice);

    if (regular <= 0 || sale <= 0 || sale >= regular) {
        return 0;
    }

    return Math.round((1 - sale / regular) * 100);
};

/**
 * Get numeric rating value safely.
 * Handles string ratings from API ("0", "4.5", etc.)
 *
 * @param {*} rating - Rating value
 * @param {number} fallback - Fallback if invalid (default: 0)
 * @returns {number} - Numeric rating
 */
export const getRating = (value, fallback = 0) => {
    const num = toNumber(value, fallback);
    // Clamp rating between 0 and 5
    return Math.max(0, Math.min(5, num));
};
