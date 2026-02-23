/**
 * CheckoutCTA - Checkout Call-to-Action Component
 *
 * Prominent checkout button with cart summary for driving conversions.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useMemo } from 'preact/hooks';
import { toNumber, safeToFixed } from '../utils/numbers';

/**
 * Cart icon.
 */
const CartIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <circle cx="9" cy="21" r="1" />
        <circle cx="20" cy="21" r="1" />
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
    </svg>
);

/**
 * Arrow right icon.
 */
const ArrowRightIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="5" y1="12" x2="19" y2="12" />
        <polyline points="12 5 19 12 12 19" />
    </svg>
);

/**
 * Lock icon for secure checkout.
 */
const LockIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
    </svg>
);

/**
 * Truck icon for free shipping.
 */
const TruckIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <rect x="1" y="3" width="15" height="13" />
        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8" />
        <circle cx="5.5" cy="18.5" r="2.5" />
        <circle cx="18.5" cy="18.5" r="2.5" />
    </svg>
);

/**
 * CheckoutCTA Component
 */
const CheckoutCTA = ({
    cart,
    checkoutUrl,
    config = {},
}) => {
    /**
     * Determine if free shipping threshold met or available.
     */
    const shippingInfo = useMemo(() => {
        if (!cart) return null;

        // Check if shipping is free
        if (cart.shipping_total === '$0.00' || cart.shipping_total === 'Free') {
            return { type: 'free', message: 'Free Shipping!' };
        }

        // Check if there's a free shipping threshold
        if (cart.free_shipping_threshold) {
            const total = toNumber(cart.subtotal?.replace(/[^0-9.-]/g, ''));
            const threshold = toNumber(cart.free_shipping_threshold);
            const remaining = threshold - total;

            if (remaining > 0) {
                return {
                    type: 'threshold',
                    message: `Add $${safeToFixed(remaining, 2, '0.00')} for free shipping`,
                    progress: threshold > 0 ? (total / threshold) * 100 : 0,
                };
            }
            return { type: 'free', message: 'Free Shipping!' };
        }

        return null;
    }, [cart]);

    /**
     * Get cart summary text.
     */
    const summaryText = useMemo(() => {
        if (!cart) return '';
        const count = cart.item_count || 0;
        return `${count} ${count === 1 ? 'item' : 'items'} in cart`;
    }, [cart]);

    // No cart or empty cart
    if (!cart || !cart.items || cart.items.length === 0) {
        return null;
    }

    const url = checkoutUrl || cart.checkout_url || '/checkout';

    return (
        <div className="glimmr-checkout-cta">
            {/* Free shipping progress */}
            {shippingInfo && shippingInfo.type === 'threshold' && (
                <div className="glimmr-checkout-shipping-progress">
                    <div className="glimmr-checkout-shipping-text">
                        <TruckIcon />
                        <span>{shippingInfo.message}</span>
                    </div>
                    <div className="glimmr-checkout-shipping-bar">
                        <div
                            className="glimmr-checkout-shipping-fill"
                            style={{ width: `${Math.min(shippingInfo.progress, 100)}%` }}
                        />
                    </div>
                </div>
            )}

            {/* Free shipping badge */}
            {shippingInfo && shippingInfo.type === 'free' && (
                <div className="glimmr-checkout-free-shipping">
                    <TruckIcon />
                    <span>{shippingInfo.message}</span>
                </div>
            )}

            {/* Summary row */}
            <div className="glimmr-checkout-summary">
                <div className="glimmr-checkout-items">
                    <CartIcon />
                    <span>{summaryText}</span>
                </div>
                <div className="glimmr-checkout-total">
                    <span className="glimmr-checkout-total-label">Total:</span>
                    <span className="glimmr-checkout-total-amount">{cart.total}</span>
                </div>
            </div>

            {/* Savings callout */}
            {cart.discount_total && cart.discount_total !== '$0.00' && (
                <div className="glimmr-checkout-savings">
                    You're saving {cart.discount_total}!
                </div>
            )}

            {/* Checkout button */}
            <a
                href={url}
                className="glimmr-btn glimmr-btn-primary glimmr-checkout-btn"
                aria-label={`Proceed to checkout with ${cart.item_count || 0} items, total ${cart.total}`}
            >
                <span>Proceed to Checkout</span>
                <ArrowRightIcon />
            </a>

            {/* Security badge */}
            <div className="glimmr-checkout-secure">
                <LockIcon />
                <span>Secure Checkout</span>
            </div>

            {/* Payment icons placeholder */}
            <div className="glimmr-checkout-payments">
                <span className="glimmr-checkout-payment-icon" title="Visa" role="img" aria-label="Visa">
                    <svg width="32" height="20" viewBox="0 0 32 20" fill="none" aria-hidden="true" focusable="false">
                        <rect width="32" height="20" rx="2" fill="#1A1F71"/>
                        <path d="M13.5 13.5L14.5 6.5H16.5L15.5 13.5H13.5Z" fill="#FFFFFF"/>
                        <path d="M21.5 6.7C21 6.5 20.2 6.3 19.2 6.3C17.2 6.3 15.8 7.3 15.8 8.7C15.8 9.8 16.8 10.4 17.6 10.8C18.4 11.2 18.7 11.4 18.7 11.8C18.7 12.3 18.1 12.5 17.5 12.5C16.7 12.5 16.2 12.4 15.4 12L15.1 11.9L14.8 13.5C15.4 13.8 16.4 14 17.5 14C19.6 14 21 13 21 11.5C21 10.6 20.4 9.9 19.2 9.3C18.5 8.9 18.1 8.7 18.1 8.3C18.1 7.9 18.5 7.5 19.4 7.5C20.1 7.5 20.7 7.7 21.1 7.8L21.3 7.9L21.5 6.7Z" fill="#FFFFFF"/>
                        <path d="M24.5 6.5H23C22.5 6.5 22.2 6.6 22 7L19 13.5H21.1L21.5 12.4H24L24.2 13.5H26L24.5 6.5ZM22.1 11C22.3 10.5 23 8.6 23 8.6C23 8.6 23.2 8.1 23.3 7.7L23.4 8.5C23.4 8.5 23.9 10.7 24 11H22.1Z" fill="#FFFFFF"/>
                        <path d="M11.5 6.5L9.5 11.3L9.3 10.3C8.9 9.1 7.8 7.8 6.5 7.2L8.2 13.5H10.4L13.7 6.5H11.5Z" fill="#FFFFFF"/>
                        <path d="M8 6.5H5L5 6.7C7.5 7.3 9.2 8.8 9.8 10.3L9.1 7C9 6.6 8.6 6.5 8 6.5Z" fill="#F9A825"/>
                    </svg>
                </span>
                <span className="glimmr-checkout-payment-icon" title="Mastercard" role="img" aria-label="Mastercard">
                    <svg width="32" height="20" viewBox="0 0 32 20" fill="none" aria-hidden="true" focusable="false">
                        <rect width="32" height="20" rx="2" fill="#F5F5F5"/>
                        <circle cx="12" cy="10" r="6" fill="#EB001B"/>
                        <circle cx="20" cy="10" r="6" fill="#F79E1B"/>
                        <path d="M16 5.5C14.3 6.9 13.2 8.8 13.2 10C13.2 11.2 14.3 13.1 16 14.5C17.7 13.1 18.8 11.2 18.8 10C18.8 8.8 17.7 6.9 16 5.5Z" fill="#FF5F00"/>
                    </svg>
                </span>
                <span className="glimmr-checkout-payment-icon" title="PayPal" role="img" aria-label="PayPal">
                    <svg width="32" height="20" viewBox="0 0 32 20" fill="none" aria-hidden="true" focusable="false">
                        <rect width="32" height="20" rx="2" fill="#F5F5F5"/>
                        <path d="M12.5 15.5H10.5L11.5 8.5H13.5L12.5 15.5Z" fill="#253B80"/>
                        <path d="M20 8.5C19.5 8.3 18.7 8 17.7 8C15.9 8 14.6 9 14.6 10.3C14.6 11.3 15.5 11.8 16.2 12.2C16.9 12.5 17.2 12.8 17.2 13.1C17.2 13.6 16.6 13.8 16.1 13.8C15.3 13.8 14.9 13.7 14.2 13.4L13.9 13.3L13.7 14.7C14.3 14.9 15.2 15.1 16.2 15.1C18.1 15.1 19.4 14.1 19.4 12.7C19.4 11.8 18.9 11.2 17.8 10.6C17.2 10.3 16.8 10.1 16.8 9.7C16.8 9.4 17.2 9 18 9C18.6 9 19.1 9.1 19.5 9.2L19.7 9.3L20 8.5Z" fill="#253B80"/>
                        <path d="M23 8.5H21.6C21.2 8.5 20.9 8.6 20.7 9L18 15.5H19.9L20.3 14.5H22.6L22.8 15.5H24.5L23 8.5ZM20.8 13.1C20.9 12.7 21.5 11 21.5 11C21.5 11 21.7 10.5 21.8 10.2L21.9 10.9C21.9 10.9 22.3 12.8 22.4 13.1H20.8Z" fill="#253B80"/>
                        <path d="M10.5 8.5L8.7 13.1L8.5 12.1C8.1 11 7.2 9.8 6 9.3L7.6 15.5H9.5L12.4 8.5H10.5Z" fill="#253B80"/>
                        <path d="M7.5 8.5H5L5 8.6C7.2 9.1 8.7 10.5 9.2 12L8.6 9C8.5 8.6 8.1 8.5 7.5 8.5Z" fill="#179BD7"/>
                    </svg>
                </span>
            </div>
        </div>
    );
};

export default CheckoutCTA;
