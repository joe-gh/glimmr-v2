/**
 * Cart Action Handler
 *
 * Handles cart_action intents from AI tools by executing them
 * via WooCommerce Store API on the frontend.
 *
 * This pattern solves the WooCommerce session sync issue where
 * REST API cart changes don't persist to the browser's session.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

import * as storeApi from './storeApi';
import { debug, debugError, debugWarn } from './debug';
import { trackAddToCart, trackCheckoutStart, trackCouponApplied } from './ga4';
import { isSafeUrl } from './urlValidation';

/**
 * Execute a cart action from AI tool response.
 *
 * @param {string} nonce - WP REST nonce.
 * @param {object} action - Cart action data from tool response.
 * @param {string} action.action - Action type: add, update, remove, apply_coupon, remove_coupon, add_then_redirect.
 * @param {number} [action.product_id] - Product ID (for add).
 * @param {number} [action.variation_id] - Variation ID (for add).
 * @param {number} [action.quantity] - Quantity (for add, update).
 * @param {string} [action.cart_item_key] - Cart item key (for update, remove).
 * @param {string} [action.coupon_code] - Coupon code (for coupon actions).
 * @param {string} [action.product_name] - Product name for display.
 * @returns {Promise<object>} Result with success/error and cart data.
 */
export const executeCartAction = async (nonce, action) => {
    debug('[CartActionHandler] Executing action:', action);

    // Validate action
    if (!action || !action.action) {
        debugError('[CartActionHandler] Invalid action:', action);
        return {
            success: false,
            error: 'Invalid cart action',
        };
    }

    try {
        let result;
        const actionType = action.action;

        switch (actionType) {
            case 'add':
                if (!action.product_id) {
                    throw new Error('Product ID is required for add action');
                }
                result = await storeApi.addToCart(nonce, {
                    productId: action.product_id,
                    variationId: action.variation_id,
                    quantity: action.quantity || 1,
                });

                // Track add to cart for GA4
                trackAddToCart(
                    action.product_id,
                    action.product_name || 'item',
                    action.price || 0,
                    action.quantity || 1
                );

                return {
                    success: true,
                    message: `Added ${action.quantity || 1} x ${action.product_name || 'item'} to cart`,
                    cart: result,
                    action_type: 'add',
                };

            case 'update':
                if (!action.cart_item_key) {
                    throw new Error('Cart item key is required for update action');
                }
                result = await storeApi.updateCartItem(nonce, {
                    cartItemKey: action.cart_item_key,
                    quantity: action.quantity,
                });
                return {
                    success: true,
                    message: `Updated ${action.product_name || 'item'} quantity to ${action.quantity}`,
                    cart: result,
                    action_type: 'update',
                };

            case 'remove':
                if (!action.cart_item_key) {
                    throw new Error('Cart item key is required for remove action');
                }
                result = await storeApi.removeCartItem(nonce, {
                    cartItemKey: action.cart_item_key,
                });
                return {
                    success: true,
                    message: `Removed ${action.product_name || 'item'} from cart`,
                    cart: result,
                    action_type: 'remove',
                };

            case 'apply_coupon':
                if (!action.coupon_code) {
                    throw new Error('Coupon code is required for apply_coupon action');
                }
                result = await storeApi.applyCoupon(nonce, {
                    couponCode: action.coupon_code,
                });

                // Track coupon applied for GA4
                trackCouponApplied(action.coupon_code);

                return {
                    success: true,
                    message: `Applied coupon "${action.coupon_code}"`,
                    cart: result,
                    action_type: 'apply_coupon',
                };

            case 'remove_coupon':
                if (!action.coupon_code) {
                    throw new Error('Coupon code is required for remove_coupon action');
                }
                result = await storeApi.removeCoupon(nonce, {
                    couponCode: action.coupon_code,
                });
                return {
                    success: true,
                    message: `Removed coupon "${action.coupon_code}"`,
                    cart: result,
                    action_type: 'remove_coupon',
                };

            case 'add_then_redirect':
                if (!action.product_id) {
                    throw new Error('Product ID is required for add_then_redirect action');
                }
                result = await storeApi.addToCart(nonce, {
                    productId: action.product_id,
                    variationId: action.variation_id,
                    quantity: action.quantity || 1,
                });

                // Determine redirect URL and validate same-origin
                const redirectUrl = action.redirect_to === 'checkout'
                    ? action.checkout_url
                    : action.cart_url;

                if (redirectUrl && !isSafeUrl(redirectUrl)) {
                    debugWarn('[CartActionHandler] Blocked redirect to unsafe URL:', redirectUrl);
                }

                return {
                    success: true,
                    message: 'Added to cart, redirecting...',
                    cart: result,
                    redirect: redirectUrl && isSafeUrl(redirectUrl) ? redirectUrl : null,
                    action_type: 'add_then_redirect',
                };

            case 'navigate':
                // Pure navigation action - no cart mutation, just redirect.
                // Used when user says "let's checkout" or "take me to cart".
                const navUrl = action.redirect_to === 'checkout'
                    ? action.checkout_url
                    : action.cart_url;

                debug('[CartActionHandler] Navigate action → ', action.redirect_to, navUrl);

                // Validate same-origin before allowing redirect
                if (navUrl && !isSafeUrl(navUrl)) {
                    debugWarn('[CartActionHandler] Blocked navigate to unsafe URL:', navUrl);
                }

                // Track checkout start for GA4 when navigating to checkout
                if (action.redirect_to === 'checkout' && action.cart_summary) {
                    trackCheckoutStart(
                        action.cart_summary.total || 0,
                        action.cart_summary.items || []
                    );
                }

                return {
                    success: true,
                    message: `Redirecting to ${action.redirect_to}...`,
                    redirect: navUrl && isSafeUrl(navUrl) ? navUrl : null,
                    action_type: 'navigate',
                    cart_summary: action.cart_summary || null,
                };

            case 'reorder':
                // Reorder action - add multiple items from a previous order.
                if (!action.items || !action.items.length) {
                    throw new Error('No items to reorder');
                }

                debug('[CartActionHandler] Reorder action with', action.items.length, 'items');

                // Optionally clear cart first
                if (action.replace_cart) {
                    await storeApi.clearCart(nonce);
                }

                // Add items one by one
                const addedItems = [];
                const failedItems = [];

                for (const item of action.items) {
                    try {
                        await storeApi.addToCart(nonce, {
                            productId: item.product_id,
                            variationId: item.variation_id,
                            quantity: item.quantity,
                        });
                        addedItems.push(item);
                    } catch (err) {
                        debugError('[CartActionHandler] Failed to add item:', item.name, err);
                        failedItems.push({
                            ...item,
                            error: err.message,
                        });
                    }
                }

                // Get updated cart state
                const updatedCart = await storeApi.getCart(nonce);

                return {
                    success: addedItems.length > 0,
                    message: addedItems.length === action.items.length
                        ? `Added all ${addedItems.length} items from order #${action.order_number} to cart`
                        : `Added ${addedItems.length} of ${action.items.length} items to cart`,
                    cart: updatedCart,
                    action_type: 'reorder',
                    added_items: addedItems,
                    failed_items: failedItems,
                    unavailable_items: action.unavailable_items || [],
                    order_id: action.order_id,
                    order_number: action.order_number,
                };

            default:
                debugWarn('[CartActionHandler] Unknown action:', actionType);
                return {
                    success: false,
                    error: `Unknown cart action: ${actionType}`,
                };
        }
    } catch (error) {
        debugError('[CartActionHandler] Action failed:', error);
        return {
            success: false,
            error: error.message || 'Cart operation failed',
        };
    }
};

/**
 * Check if an artifact is a cart action that needs frontend execution.
 *
 * @param {object} artifact - Artifact object from AI response.
 * @returns {boolean} True if this is a cart action artifact.
 */
export const isCartActionArtifact = (artifact) => {
    if (!artifact) return false;

    // Check artifact type (primary check - backend sets this)
    if (artifact.type === 'cart_action') {
        return true;
    }

    // Check status in data (format_outcome uses 'status' field)
    if (artifact.data?.status === 'cart_action') {
        return true;
    }

    return false;
};

/**
 * Get cart action data from artifact.
 *
 * @param {object} artifact - Artifact object.
 * @returns {object|null} Cart action data or null.
 */
export const getCartActionData = (artifact) => {
    if (!artifact) return null;

    // Data might be directly on artifact or nested
    return artifact.data || artifact;
};

/**
 * Update custom cart count elements on the page.
 *
 * Many themes use custom cart icons/counters that don't automatically
 * update when cart changes via AJAX. This function updates common
 * selectors used by themes.
 *
 * @param {object} cart - Cart object from Store API or backend tool response.
 */
export const updateCartCount = (cart) => {
    if (!cart) {
        debug('[CartActionHandler] updateCartCount called with null cart');
        return;
    }

    debug('[CartActionHandler] updateCartCount called with:', cart);

    // Get item count from cart - handle multiple response formats:
    // - Store API: items_count
    // - Backend tool: cart_count
    // - Fallback: count items array
    const itemCount = cart.items_count ?? cart.cart_count ?? cart.item_count ?? (cart.items?.length || 0);

    debug('[CartActionHandler] Updating cart count elements:', itemCount);

    // Common selectors used by themes for cart count
    const selectors = [
        '#cart-item-count',                    // Arborwear custom
        '.cart-item-count',                    // Generic class
        '.cart-count',                         // Common class
        '.mini-cart-count',                    // Mini cart
        '.header-cart-count',                  // Header cart
        '.cart-contents-count',                // WooCommerce default
        '.wc-block-mini-cart__badge',          // WooCommerce Blocks
        '[data-cart-count]',                   // Data attribute pattern
    ];

    let totalUpdated = 0;
    selectors.forEach((selector) => {
        const elements = document.querySelectorAll(selector);
        elements.forEach((el) => {
            // Update text content
            el.textContent = itemCount;
            totalUpdated++;

            // Also update data attribute if present
            if (el.hasAttribute('data-cart-count')) {
                el.setAttribute('data-cart-count', itemCount);
            }

            debug('[CartActionHandler] Updated cart element:', selector, '→', itemCount);
        });
    });

    // Dispatch a custom event for themes that listen for cart updates
    window.dispatchEvent(new CustomEvent('glimmr_cart_updated', {
        detail: {
            count: itemCount,
            cart: cart,
        },
    }));

    // Also trigger WooCommerce's native cart fragment refresh if available
    if (typeof jQuery !== 'undefined' && jQuery(document.body).trigger) {
        jQuery(document.body).trigger('wc_fragment_refresh');
    }
};

export default {
    executeCartAction,
    isCartActionArtifact,
    getCartActionData,
    updateCartCount,
};
