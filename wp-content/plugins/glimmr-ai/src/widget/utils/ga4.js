/**
 * Google Analytics 4 Integration
 *
 * Tracks chat funnel events: widget open, message sent, product view,
 * add to cart, and checkout start.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

/**
 * GA4 configuration from widget settings.
 */
let ga4Config = null;

/**
 * Initialize GA4 tracking from widget configuration.
 *
 * @param {Object} config - Widget configuration object
 */
export const initGA4 = (config) => {
    ga4Config = {
        enabled: config?.ga4Enabled === true,
        measurementId: config?.ga4MeasurementId || null,
        trackWidgetOpen: config?.ga4TrackWidgetOpen !== false,
        trackMessages: config?.ga4TrackMessages !== false,
        trackProducts: config?.ga4TrackProducts !== false,
        trackCart: config?.ga4TrackCart !== false,
        trackCheckout: config?.ga4TrackCheckout !== false,
    };
};

/**
 * Check if GA4 tracking is enabled and configured.
 *
 * @returns {boolean} True if GA4 is ready to track
 */
export const isGA4Enabled = () => {
    return ga4Config?.enabled === true && !!ga4Config?.measurementId;
};

/**
 * Send an event to GA4.
 *
 * @param {string} eventName - The event name
 * @param {Object} params - Event parameters
 */
const sendEvent = (eventName, params = {}) => {
    if (!ga4Config?.enabled || !ga4Config?.measurementId) {
        return;
    }

    // Add common parameters
    const eventParams = {
        send_to: ga4Config.measurementId,
        event_category: 'glimmr_chat',
        ...params,
    };

    // Use gtag if available (standard GA4 setup)
    if (typeof window.gtag === 'function') {
        window.gtag('event', eventName, eventParams);
        return;
    }

    // Fallback: dataLayer push for Google Tag Manager
    if (typeof window.dataLayer !== 'undefined' && Array.isArray(window.dataLayer)) {
        window.dataLayer.push({
            event: eventName,
            ...eventParams,
        });
    }
};

/**
 * Track widget open event.
 */
export const trackWidgetOpen = () => {
    if (ga4Config?.trackWidgetOpen) {
        sendEvent('glimmr_widget_open');
    }
};

/**
 * Track widget close event.
 */
export const trackWidgetClose = () => {
    if (ga4Config?.trackWidgetOpen) {
        sendEvent('glimmr_widget_close');
    }
};

/**
 * Track message sent event.
 *
 * @param {number} messageLength - Length of the message
 */
export const trackMessageSent = (messageLength) => {
    if (ga4Config?.trackMessages) {
        sendEvent('glimmr_message_sent', {
            message_length: messageLength,
        });
    }
};

/**
 * Track product view event (GA4 standard view_item event).
 *
 * @param {number|string} productId - Product ID
 * @param {string} productName - Product name
 * @param {number|string} price - Product price
 * @param {string} currency - Currency code (default: USD)
 */
export const trackProductView = (productId, productName, price, currency = 'USD') => {
    if (ga4Config?.trackProducts) {
        const numericPrice = parseFloat(price) || 0;

        sendEvent('view_item', {
            currency: currency,
            value: numericPrice,
            items: [{
                item_id: String(productId),
                item_name: productName,
                price: numericPrice,
            }],
        });
    }
};

/**
 * Track product click event.
 *
 * @param {number|string} productId - Product ID
 * @param {string} productName - Product name
 * @param {string} listName - The list/context where product was clicked
 */
export const trackProductClick = (productId, productName, listName = 'chat_results') => {
    if (ga4Config?.trackProducts) {
        sendEvent('select_item', {
            item_list_name: listName,
            items: [{
                item_id: String(productId),
                item_name: productName,
            }],
        });
    }
};

/**
 * Track add to cart event (GA4 standard add_to_cart event).
 *
 * @param {number|string} productId - Product ID
 * @param {string} productName - Product name
 * @param {number|string} price - Product price
 * @param {number} quantity - Quantity added
 * @param {string} currency - Currency code (default: USD)
 */
export const trackAddToCart = (productId, productName, price, quantity = 1, currency = 'USD') => {
    if (ga4Config?.trackCart) {
        const numericPrice = parseFloat(price) || 0;

        sendEvent('add_to_cart', {
            currency: currency,
            value: numericPrice * quantity,
            items: [{
                item_id: String(productId),
                item_name: productName,
                price: numericPrice,
                quantity: quantity,
            }],
        });
    }
};

/**
 * Track remove from cart event.
 *
 * @param {number|string} productId - Product ID
 * @param {string} productName - Product name
 * @param {number|string} price - Product price
 * @param {number} quantity - Quantity removed
 * @param {string} currency - Currency code (default: USD)
 */
export const trackRemoveFromCart = (productId, productName, price, quantity = 1, currency = 'USD') => {
    if (ga4Config?.trackCart) {
        const numericPrice = parseFloat(price) || 0;

        sendEvent('remove_from_cart', {
            currency: currency,
            value: numericPrice * quantity,
            items: [{
                item_id: String(productId),
                item_name: productName,
                price: numericPrice,
                quantity: quantity,
            }],
        });
    }
};

/**
 * Track checkout start event (GA4 standard begin_checkout event).
 *
 * @param {number|string} cartValue - Total cart value
 * @param {Array} items - Array of cart items
 * @param {string} currency - Currency code (default: USD)
 */
export const trackCheckoutStart = (cartValue, items = [], currency = 'USD') => {
    if (ga4Config?.trackCheckout) {
        const numericValue = parseFloat(cartValue) || 0;

        sendEvent('begin_checkout', {
            currency: currency,
            value: numericValue,
            items: items.map((item) => ({
                item_id: String(item.id || item.product_id),
                item_name: item.name || item.product_name || 'Unknown',
                price: parseFloat(item.price) || 0,
                quantity: item.quantity || 1,
            })),
        });
    }
};

/**
 * Track coupon applied event.
 *
 * @param {string} couponCode - The coupon code applied
 */
export const trackCouponApplied = (couponCode) => {
    if (ga4Config?.trackCart) {
        sendEvent('glimmr_coupon_applied', {
            coupon: couponCode,
        });
    }
};
