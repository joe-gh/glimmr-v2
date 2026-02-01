/**
 * Debug Logging Utility
 *
 * Gates all console.log statements behind the debugMode setting.
 * This allows verbose logging during development while keeping
 * production builds clean.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

/**
 * Global debug flag from widget config.
 */
let debugEnabled = false;

/**
 * Initialize debug mode from widget configuration.
 *
 * @param {Object} config - Widget configuration object
 */
export const initDebug = (config) => {
    debugEnabled = config?.debugMode === true || config?.debug === true;
};

/**
 * Check if debug mode is enabled.
 *
 * @returns {boolean} True if debug mode is enabled
 */
export const isDebugEnabled = () => debugEnabled;

/**
 * Log debug messages (only when debugMode is enabled).
 *
 * @param {...any} args - Arguments to log
 */
export const debug = (...args) => {
    if (debugEnabled) {
        console.log(...args);
    }
};

/**
 * Log error messages (always logged regardless of debug mode).
 *
 * @param {...any} args - Arguments to log
 */
export const debugError = (...args) => {
    // Always log errors - they're important for troubleshooting
    console.error(...args);
};

/**
 * Log warning messages (only when debugMode is enabled).
 *
 * @param {...any} args - Arguments to log
 */
export const debugWarn = (...args) => {
    if (debugEnabled) {
        console.warn(...args);
    }
};

/**
 * Log info messages (only when debugMode is enabled).
 *
 * @param {...any} args - Arguments to log
 */
export const debugInfo = (...args) => {
    if (debugEnabled) {
        console.info(...args);
    }
};

/**
 * Log with a styled label (only when debugMode is enabled).
 *
 * @param {string} label - Label for the log group
 * @param {...any} args - Arguments to log
 */
export const debugLabeled = (label, ...args) => {
    if (debugEnabled) {
        console.log(
            `%c[${label}]`,
            'color: #4F46E5; font-weight: bold;',
            ...args
        );
    }
};

/**
 * Create a scoped debug logger with a consistent prefix.
 *
 * @param {string} scope - The scope/module name for the logger
 * @returns {Object} Object with scoped log, error, warn methods
 */
export const createScopedLogger = (scope) => {
    const prefix = `[${scope}]`;

    return {
        log: (...args) => {
            if (debugEnabled) {
                console.log(prefix, ...args);
            }
        },
        error: (...args) => {
            // Always log errors
            console.error(prefix, ...args);
        },
        warn: (...args) => {
            if (debugEnabled) {
                console.warn(prefix, ...args);
            }
        },
        info: (...args) => {
            if (debugEnabled) {
                console.info(prefix, ...args);
            }
        },
    };
};
