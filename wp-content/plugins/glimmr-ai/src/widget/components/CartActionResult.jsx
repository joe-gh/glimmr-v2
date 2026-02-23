/**
 * Cart Action Result Component
 *
 * Displays the result of a cart action execution.
 * Shows pending, success, or error states.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

import { h, Fragment } from 'preact';

/**
 * Format cart item count for display.
 * @param {object} cart - Cart data from Store API or backend tool.
 * @returns {string} Formatted item count.
 */
const formatItemCount = (cart) => {
    if (!cart) return '';
    // Handle both Store API (items_count) and backend tool (cart_count) formats
    const count = cart.items_count ?? cart.cart_count ?? cart.item_count ?? 0;
    return count === 1 ? '1 item' : `${count} items`;
};

/**
 * Format price for display (Store API returns price in cents).
 * @param {string|number} price - Price value.
 * @param {string} [currencySymbol='$'] - Currency symbol.
 * @returns {string} Formatted price.
 */
const formatPrice = (price, currencySymbol = '$') => {
    if (!price) return '';
    // Store API returns price as string in minor units (cents)
    const numericPrice = parseInt(price, 10) / 100;
    return `${currencySymbol}${numericPrice.toFixed(2)}`;
};

/**
 * Get human-readable label for cart action type.
 * @param {string} action - Action type.
 * @returns {string} Action label.
 */
const getActionLabel = (action) => {
    switch (action) {
        case 'add':
            return 'Added';
        case 'update':
            return 'Updated';
        case 'remove':
            return 'Removed';
        case 'apply_coupon':
            return 'Applied coupon to';
        case 'remove_coupon':
            return 'Removed coupon from';
        case 'add_then_redirect':
            return 'Added';
        default:
            return 'Updated';
    }
};

/**
 * Cart Action Result Component
 */
const CartActionResult = ({ data }) => {
    // Not yet executed - show pending state
    if (!data.executed) {
        return (
            <div className="glimmr-cart-action glimmr-cart-action--pending">
                <div className="glimmr-cart-action__spinner" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" strokeOpacity="0.3" />
                        <path d="M12 2a10 10 0 0 1 10 10" strokeLinecap="round">
                            <animateTransform
                                attributeName="transform"
                                type="rotate"
                                from="0 12 12"
                                to="360 12 12"
                                dur="1s"
                                repeatCount="indefinite"
                            />
                        </path>
                    </svg>
                </div>
                <span className="glimmr-cart-action__message">{data.message || 'Processing...'}</span>
            </div>
        );
    }

    // Error state
    if (data.error) {
        return (
            <div className="glimmr-cart-action glimmr-cart-action--error" role="alert">
                <span className="glimmr-cart-action__icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                </span>
                <div className="glimmr-cart-action__content">
                    <span className="glimmr-cart-action__error-message">
                        {data.error}
                    </span>
                </div>
            </div>
        );
    }

    // Success state - either from live result or historical data
    const result = data.result;

    // For historical actions (loaded from conversation history), show completed state
    // using the original action data since we don't have the Store API result.
    if (data.historical && !result) {
        const actionLabel = getActionLabel(data.action);
        const message = data.quantity > 1
            ? `${actionLabel} ${data.quantity} x ${data.product_name || 'item'}`
            : `${actionLabel} ${data.product_name || 'item'}`;

        return (
            <div className="glimmr-cart-action glimmr-cart-action--success glimmr-cart-action--historical" role="status">
                <span className="glimmr-cart-action__icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M8 12l2.5 2.5L16 9" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </span>
                <div className="glimmr-cart-action__content">
                    <span className="glimmr-cart-action__message">
                        {message}
                    </span>
                    {data.line_total && (
                        <span className="glimmr-cart-action__summary">
                            {data.line_total}
                        </span>
                    )}
                </div>
            </div>
        );
    }

    // Live result - but no result object means nothing to show
    if (!result) {
        return null;
    }

    return (
        <div className="glimmr-cart-action glimmr-cart-action--success" role="status">
            <span className="glimmr-cart-action__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="12" cy="12" r="10" />
                    <path d="M8 12l2.5 2.5L16 9" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </span>
            <div className="glimmr-cart-action__content">
                <span className="glimmr-cart-action__message">
                    {result.message}
                </span>
                {result.cart && (
                    <span className="glimmr-cart-action__summary">
                        Cart: {formatItemCount(result.cart)}
                        {result.cart.totals?.total_price && (
                            <> &middot; {formatPrice(result.cart.totals.total_price)}</>
                        )}
                    </span>
                )}
            </div>
            {result.redirect && (
                <span className="glimmr-cart-action__redirect">
                    Redirecting...
                </span>
            )}
        </div>
    );
};

export default CartActionResult;
