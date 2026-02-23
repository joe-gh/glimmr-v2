/**
 * WooCommerce Store API helper for cart operations.
 *
 * Uses the official Store API which properly syncs with browser session.
 * This solves the issue where REST API cart changes don't persist to
 * the browser's mini cart / cart page.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

import { debug, debugError, debugWarn } from './debug';
import DOMPurify from 'dompurify';

const STORE_API_BASE = '/wp-json/wc/store/v1';

/**
 * Get Cart-Token from WooCommerce (for guest sessions).
 * @returns {string|null} Cart token or null if not found.
 */
const getCartToken = () => {
    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'wc_cart_token') {
            return value;
        }
    }
    return null;
};

/**
 * Build headers for Store API requests.
 * WooCommerce Store API requires 'Nonce' header (not X-WP-Nonce).
 * @param {string} nonce - WooCommerce Store API nonce (wc_store_api).
 * @returns {object} Headers object.
 */
const buildHeaders = (nonce) => {
    const headers = {
        'Content-Type': 'application/json',
    };

    // WooCommerce Store API uses 'Nonce' header (different from X-WP-Nonce)
    if (nonce) {
        headers['Nonce'] = nonce;
    }

    // Add cart token for guest sessions
    const cartToken = getCartToken();
    if (cartToken) {
        headers['Cart-Token'] = cartToken;
    }

    return headers;
};

/**
 * Add item to cart via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @param {object} params - Parameters.
 * @param {number} params.productId - Product ID.
 * @param {number} [params.variationId] - Variation ID for variable products.
 * @param {number} params.quantity - Quantity to add.
 * @returns {Promise<object>} Cart data.
 */
export const addToCart = async (nonce, { productId, variationId, quantity }) => {
    debug('[Store API] Adding to cart:', { productId, variationId, quantity });

    const body = {
        id: variationId || productId,
        quantity: quantity,
    };

    const response = await fetch(`${STORE_API_BASE}/cart/add-item`, {
        method: 'POST',
        headers: buildHeaders(nonce),
        credentials: 'include',
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to add to cart';
        debugError('[Store API] Add to cart failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Add to cart success:', {
        items_count: cart.items_count,
        total: cart.totals?.total_price,
    });

    // Trigger WooCommerce cart fragment refresh
    triggerCartUpdate();

    return cart;
};

/**
 * Update cart item quantity via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @param {object} params - Parameters.
 * @param {string} params.cartItemKey - Cart item key.
 * @param {number} params.quantity - New quantity.
 * @returns {Promise<object>} Cart data.
 */
export const updateCartItem = async (nonce, { cartItemKey, quantity }) => {
    debug('[Store API] Updating cart item:', { cartItemKey, quantity });

    const response = await fetch(`${STORE_API_BASE}/cart/update-item`, {
        method: 'POST',
        headers: buildHeaders(nonce),
        credentials: 'include',
        body: JSON.stringify({
            key: cartItemKey,
            quantity: quantity,
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to update cart';
        debugError('[Store API] Update cart failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Update cart success:', {
        items_count: cart.items_count,
        total: cart.totals?.total_price,
    });

    triggerCartUpdate();
    return cart;
};

/**
 * Remove item from cart via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @param {object} params - Parameters.
 * @param {string} params.cartItemKey - Cart item key.
 * @returns {Promise<object>} Cart data.
 */
export const removeCartItem = async (nonce, { cartItemKey }) => {
    debug('[Store API] Removing cart item:', { cartItemKey });

    const response = await fetch(`${STORE_API_BASE}/cart/remove-item`, {
        method: 'POST',
        headers: buildHeaders(nonce),
        credentials: 'include',
        body: JSON.stringify({
            key: cartItemKey,
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to remove from cart';
        debugError('[Store API] Remove from cart failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Remove from cart success:', {
        items_count: cart.items_count,
        total: cart.totals?.total_price,
    });

    triggerCartUpdate();
    return cart;
};

/**
 * Apply coupon via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @param {object} params - Parameters.
 * @param {string} params.couponCode - Coupon code to apply.
 * @returns {Promise<object>} Cart data.
 */
export const applyCoupon = async (nonce, { couponCode }) => {
    debug('[Store API] Applying coupon:', couponCode);

    const response = await fetch(`${STORE_API_BASE}/cart/apply-coupon`, {
        method: 'POST',
        headers: buildHeaders(nonce),
        credentials: 'include',
        body: JSON.stringify({
            code: couponCode,
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to apply coupon';
        debugError('[Store API] Apply coupon failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Apply coupon success:', {
        items_count: cart.items_count,
        discount: cart.totals?.total_discount,
    });

    triggerCartUpdate();
    return cart;
};

/**
 * Remove coupon via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @param {object} params - Parameters.
 * @param {string} params.couponCode - Coupon code to remove.
 * @returns {Promise<object>} Cart data.
 */
export const removeCoupon = async (nonce, { couponCode }) => {
    debug('[Store API] Removing coupon:', couponCode);

    const response = await fetch(`${STORE_API_BASE}/cart/remove-coupon`, {
        method: 'POST',
        headers: buildHeaders(nonce),
        credentials: 'include',
        body: JSON.stringify({
            code: couponCode,
        }),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to remove coupon';
        debugError('[Store API] Remove coupon failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Remove coupon success:', {
        items_count: cart.items_count,
        discount: cart.totals?.total_discount,
    });

    triggerCartUpdate();
    return cart;
};

/**
 * Clear all items from cart via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @returns {Promise<object>} Empty cart data.
 */
export const clearCart = async (nonce) => {
    debug('[Store API] Clearing cart...');

    // First get current cart to find all items
    const cart = await getCart(nonce);

    if (!cart.items || cart.items.length === 0) {
        debug('[Store API] Cart already empty');
        return cart;
    }

    // Remove each item
    for (const item of cart.items) {
        try {
            await removeCartItem(nonce, { cartItemKey: item.key });
        } catch (err) {
            debugWarn('[Store API] Failed to remove item during clear:', item.key, err);
        }
    }

    // Return final cart state
    const clearedCart = await getCart(nonce);
    debug('[Store API] Cart cleared:', {
        items_count: clearedCart.items_count,
    });

    return clearedCart;
};

/**
 * Get current cart via Store API.
 * @param {string} nonce - WordPress REST nonce.
 * @returns {Promise<object>} Cart data.
 */
export const getCart = async (nonce) => {
    debug('[Store API] Fetching cart...');

    const response = await fetch(`${STORE_API_BASE}/cart`, {
        method: 'GET',
        headers: buildHeaders(nonce),
        credentials: 'include',
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to get cart';
        debugError('[Store API] Get cart failed:', error);
        throw new Error(errorMessage);
    }

    const cart = await response.json();
    debug('[Store API] Cart fetched:', {
        items_count: cart.items_count,
        total: cart.totals?.total_price,
    });

    return cart;
};

/**
 * Get product details via Store API.
 * Returns full product info including description, variations, and attributes.
 * @param {number} productId - Product ID to fetch.
 * @returns {Promise<object>} Product data formatted for our components.
 */
export const getProduct = async (productId) => {
    debug('[Store API] Fetching product details:', productId);

    const response = await fetch(`${STORE_API_BASE}/products/${productId}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'include',
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        const errorMessage = error.message || error.data?.message || 'Failed to get product';
        debugError('[Store API] Get product failed:', error);
        throw new Error(errorMessage);
    }

    const storeProduct = await response.json();
    debug('[Store API] Product fetched:', {
        id: storeProduct.id,
        name: storeProduct.name,
        type: storeProduct.type,
        has_variations: storeProduct.variations?.length > 0,
        variation_count: storeProduct.variations?.length || 0,
    });

    // Log raw variation data for debugging stock issues
    if (storeProduct.variations?.length > 0) {
        debug('[Store API] Raw variations sample:', storeProduct.variations.slice(0, 2));
    }

    // Transform Store API format to our component format
    return transformStoreProduct(storeProduct);
};

/**
 * Clean up WooCommerce attribute name for display.
 * Converts "pa_color" to "Color", "pa_size" to "Size", etc.
 * @param {string} name - Raw attribute name.
 * @returns {string} Clean display name.
 */
const cleanAttributeName = (name) => {
    if (!name) return name;

    // Remove "pa_" prefix (WooCommerce global attribute taxonomy prefix)
    let clean = name.replace(/^pa_/, '');

    // Replace underscores/hyphens with spaces
    clean = clean.replace(/[-_]/g, ' ');

    // Capitalize first letter of each word
    clean = clean.replace(/\b\w/g, char => char.toUpperCase());

    return clean;
};

/**
 * Transform WooCommerce Store API product format to our component format.
 * @param {object} storeProduct - Product from Store API.
 * @returns {object} Product in our expected format.
 */
const transformStoreProduct = (storeProduct) => {
    // Extract price from Store API format (prices are in minor units)
    const currencyMinorUnit = storeProduct.prices?.currency_minor_unit || 2;
    const divisor = Math.pow(10, currencyMinorUnit);
    const currencyPrefix = storeProduct.prices?.currency_prefix || '$';
    const currencySuffix = storeProduct.prices?.currency_suffix || '';

    const formatPrice = (value) => {
        if (!value) return null;
        const numValue = parseInt(value, 10) / divisor;
        return `${currencyPrefix}${numValue.toFixed(2)}${currencySuffix}`;
    };

    // Build gallery array from images
    const gallery = (storeProduct.images || []).map(img => img.src);

    // Transform attributes for variation selector
    // Store API attributes format: [{name, taxonomy, has_variations, terms: [{name, slug}]}]
    // VariationSelector expects: { "Color": ["Red", "Blue"], "Size": ["S", "M", "L"] }
    const attributes = {};
    const attrNameMap = {}; // Maps taxonomy/raw name to clean display name
    const attrValueMap = {}; // Maps slug to display name for each attribute

    if (storeProduct.attributes) {
        for (const attr of storeProduct.attributes) {
            if (attr.has_variations && attr.terms) {
                // Clean up the attribute name (pa_color -> Color)
                const rawName = attr.taxonomy || attr.name;
                const displayName = cleanAttributeName(rawName);

                attributes[displayName] = attr.terms.map(t => t.name);

                // Store mappings for variation attribute lookup
                attrNameMap[rawName] = displayName;
                if (attr.taxonomy && attr.taxonomy !== rawName) {
                    attrNameMap[attr.taxonomy] = displayName;
                }
                // Also map the cleaned name to itself for direct lookups
                attrNameMap[displayName] = displayName;

                // Build value mapping: slug -> display name
                // This is needed because variation attributes use slugs
                attrValueMap[displayName] = {};
                for (const term of attr.terms) {
                    attrValueMap[displayName][term.slug] = term.name;
                    // Also map the name to itself for direct matches
                    attrValueMap[displayName][term.name] = term.name;
                }
            }
        }
    }

    // Transform variations
    // Store API variations format: [{id, attributes: [{name, value}], ...}]
    // VariationSelector expects variations with: { attributes: {"Color": "Red", "Size": "M"} }
    const variations = (storeProduct.variations || []).map(variation => {
        // Build attributes object for this variation
        // Use the clean display names as keys and convert slug values to display names
        const varAttrs = {};
        if (variation.attributes) {
            for (const attr of variation.attributes) {
                // attr.name could be "Color", "pa_color", etc. - normalize it
                const displayName = attrNameMap[attr.name] || cleanAttributeName(attr.name);

                // Convert slug value to display name (e.g., "heather-blue" -> "Heather Blue")
                // attr.value from Store API is typically a slug for taxonomy attributes
                const valueMap = attrValueMap[displayName] || {};
                const displayValue = valueMap[attr.value] || attr.value;

                varAttrs[displayName] = displayValue;
            }
        }

        // Handle stock status - Store API uses is_in_stock boolean
        // Default to true if not specified (let WooCommerce validate at checkout)
        const isInStock = variation.is_in_stock !== false && variation.is_purchasable !== false;
        const stockStatus = variation.stock_status || (isInStock ? 'instock' : 'outofstock');

        debug('[Store API] Variation stock:', {
            id: variation.id,
            is_in_stock: variation.is_in_stock,
            is_purchasable: variation.is_purchasable,
            stock_status: variation.stock_status,
            computed_in_stock: isInStock,
        });

        return {
            variation_id: variation.id,
            id: variation.id,
            attributes: varAttrs,
            price: formatPrice(variation.prices?.price),
            regular_price: formatPrice(variation.prices?.regular_price),
            sale_price: formatPrice(variation.prices?.sale_price),
            in_stock: isInStock,
            stock_status: stockStatus,
            stock_quantity: variation.stock_quantity,
        };
    });

    return {
        id: storeProduct.id,
        name: storeProduct.name,
        type: storeProduct.type,
        sku: storeProduct.sku,
        price: formatPrice(storeProduct.prices?.price),
        price_raw: storeProduct.prices?.price ? parseInt(storeProduct.prices.price, 10) / divisor : 0,
        regular_price: formatPrice(storeProduct.prices?.regular_price),
        sale_price: formatPrice(storeProduct.prices?.sale_price),
        on_sale: storeProduct.on_sale,
        in_stock: storeProduct.is_in_stock,
        stock_status: storeProduct.is_in_stock ? 'instock' : 'outofstock',
        stock_quantity: storeProduct.stock_quantity,
        description: DOMPurify.sanitize(storeProduct.description || '', { ALLOWED_TAGS: [] }),
        short_description: DOMPurify.sanitize(storeProduct.short_description || '', { ALLOWED_TAGS: [] }),
        url: storeProduct.permalink,
        image: gallery[0] || null,
        gallery: gallery,
        rating: storeProduct.average_rating ? parseFloat(storeProduct.average_rating) : 0,
        average_rating: storeProduct.average_rating ? parseFloat(storeProduct.average_rating) : 0,
        review_count: storeProduct.review_count || 0,
        attributes: Object.keys(attributes).length > 0 ? attributes : null,
        variations: variations.length > 0 ? variations : null,
        categories: (storeProduct.categories || []).map(c => c.name),
        // Pass through reviews data if present (from AI tool response)
        reviews: storeProduct.reviews || [],
        rating_counts: storeProduct.rating_counts || {},
    };
};

/**
 * Trigger WooCommerce mini cart refresh.
 * Works with both classic WooCommerce themes and block-based themes.
 */
const triggerCartUpdate = () => {
    // Method 1: jQuery trigger (classic WooCommerce cart fragments)
    if (typeof jQuery !== 'undefined') {
        try {
            jQuery(document.body).trigger('wc_fragment_refresh');
            jQuery(document.body).trigger('updated_cart_totals');
            jQuery(document.body).trigger('added_to_cart');
            debug('[Store API] Triggered WooCommerce cart refresh events');
        } catch (e) {
            debugWarn('[Store API] jQuery cart refresh failed:', e);
        }
    }

    // Method 2: Dispatch custom event for themes that listen
    try {
        document.body.dispatchEvent(new CustomEvent('wc-cart-updated'));
        document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart'));
    } catch (e) {
        debugWarn('[Store API] Custom event dispatch failed:', e);
    }

    // Method 3: Trigger storage event for cross-tab sync
    try {
        const cartHash = Date.now().toString();
        localStorage.setItem('wc_cart_hash', cartHash);
        localStorage.removeItem('wc_cart_hash');
    } catch (e) {
        // Storage may not be available
    }
};

export default {
    addToCart,
    updateCartItem,
    removeCartItem,
    applyCoupon,
    removeCoupon,
    clearCart,
    getCart,
    getProduct,
};
