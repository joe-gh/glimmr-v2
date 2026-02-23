/**
 * CartPreview - Enhanced Cart Display Component
 *
 * Displays cart contents with inline quantity editing,
 * savings display, and coupon input.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState, useCallback } from 'preact/hooks';
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
 * Trash icon.
 */
const TrashIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
    </svg>
);

/**
 * Tag/coupon icon.
 */
const TagIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
        <line x1="7" y1="7" x2="7.01" y2="7" />
    </svg>
);

/**
 * Close/X icon.
 */
const CloseIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

/**
 * Inline quantity editor component.
 */
const QuantityEditor = ({ value, min = 1, max = 99, onChange, disabled }) => {
    const [isUpdating, setIsUpdating] = useState(false);

    const handleChange = useCallback(async (newValue) => {
        if (newValue < min || newValue > max || isUpdating) return;

        setIsUpdating(true);
        try {
            await onChange(newValue);
        } finally {
            setIsUpdating(false);
        }
    }, [min, max, onChange, isUpdating]);

    return (
        <div className={`glimmr-cart-qty-editor ${isUpdating ? 'is-updating' : ''}`}>
            <button
                type="button"
                className="glimmr-cart-qty-btn"
                onClick={() => handleChange(value - 1)}
                disabled={disabled || isUpdating || value <= min}
                aria-label="Decrease quantity"
            >
                -
            </button>
            <span className="glimmr-cart-qty-value">{value}</span>
            <button
                type="button"
                className="glimmr-cart-qty-btn"
                onClick={() => handleChange(value + 1)}
                disabled={disabled || isUpdating || value >= max}
                aria-label="Increase quantity"
            >
                +
            </button>
        </div>
    );
};

/**
 * Enhanced cart item component with inline editing.
 */
const CartItem = ({
    item,
    showInlineQuantity,
    onUpdateQuantity,
    onRemove,
}) => {
    const [isRemoving, setIsRemoving] = useState(false);

    const handleRemove = useCallback(async () => {
        if (isRemoving) return;
        setIsRemoving(true);
        try {
            await onRemove(item.key);
        } catch (error) {
            setIsRemoving(false);
        }
    }, [item.key, onRemove, isRemoving]);

    const handleQuantityChange = useCallback((newQuantity) => {
        return onUpdateQuantity(item.key, newQuantity);
    }, [item.key, onUpdateQuantity]);

    return (
        <div className={`glimmr-cart-item ${isRemoving ? 'is-removing' : ''}`}>
            {item.image && (
                <img src={item.image} alt={item.name} className="glimmr-cart-item-image" />
            )}
            <div className="glimmr-cart-item-info">
                <div className="glimmr-cart-item-name">{item.name}</div>
                {item.variation && (
                    <div className="glimmr-cart-item-variation">{item.variation}</div>
                )}
                <div className="glimmr-cart-item-meta">
                    {showInlineQuantity && onUpdateQuantity ? (
                        <QuantityEditor
                            value={item.quantity}
                            max={item.max_quantity || 99}
                            onChange={handleQuantityChange}
                            disabled={isRemoving}
                        />
                    ) : (
                        <span className="glimmr-cart-item-qty">Qty: {item.quantity}</span>
                    )}
                    <span className="glimmr-cart-item-price">{item.price}</span>
                </div>
            </div>
            {onRemove && (
                <button
                    type="button"
                    className="glimmr-cart-item-remove"
                    onClick={handleRemove}
                    disabled={isRemoving}
                    aria-label={`Remove ${item.name} from cart`}
                >
                    {isRemoving ? (
                        <span className="glimmr-spinner-small" />
                    ) : (
                        <TrashIcon />
                    )}
                </button>
            )}
        </div>
    );
};

/**
 * Coupon input component.
 */
const CouponInput = ({ onApply, isApplying }) => {
    const [couponCode, setCouponCode] = useState('');
    const [error, setError] = useState('');

    const handleSubmit = useCallback(async (e) => {
        e.preventDefault();
        if (!couponCode.trim() || isApplying) return;

        setError('');
        try {
            await onApply(couponCode.trim());
            setCouponCode('');
        } catch (err) {
            setError(err.message || 'Invalid coupon code');
        }
    }, [couponCode, onApply, isApplying]);

    return (
        <form className="glimmr-cart-coupon-form" onSubmit={handleSubmit}>
            <div className="glimmr-cart-coupon-input-wrapper">
                <TagIcon />
                <input
                    type="text"
                    className="glimmr-cart-coupon-input"
                    placeholder="Enter coupon code"
                    value={couponCode}
                    onChange={(e) => setCouponCode(e.target.value)}
                    disabled={isApplying}
                    aria-label="Coupon code"
                    aria-describedby={error ? 'coupon-error' : undefined}
                />
                <button
                    type="submit"
                    className="glimmr-cart-coupon-apply"
                    disabled={!couponCode.trim() || isApplying}
                >
                    {isApplying ? 'Applying...' : 'Apply'}
                </button>
            </div>
            {error && <span className="glimmr-cart-coupon-error" id="coupon-error" role="alert">{error}</span>}
        </form>
    );
};

/**
 * Applied coupon tag with remove option.
 */
const AppliedCoupon = ({ coupon, onRemove }) => {
    const [isRemoving, setIsRemoving] = useState(false);

    const handleRemove = useCallback(async () => {
        if (isRemoving) return;
        setIsRemoving(true);
        try {
            await onRemove(coupon.code);
        } catch (error) {
            setIsRemoving(false);
        }
    }, [coupon.code, onRemove, isRemoving]);

    return (
        <span className={`glimmr-cart-coupon-tag ${isRemoving ? 'is-removing' : ''}`}>
            <TagIcon />
            <span className="glimmr-cart-coupon-code">{coupon.code}</span>
            {coupon.discount && (
                <span className="glimmr-cart-coupon-discount">-{coupon.discount}</span>
            )}
            {onRemove && (
                <button
                    type="button"
                    className="glimmr-cart-coupon-remove"
                    onClick={handleRemove}
                    disabled={isRemoving}
                    aria-label={`Remove coupon ${coupon.code}`}
                >
                    <CloseIcon />
                </button>
            )}
        </span>
    );
};

/**
 * Savings display component.
 */
const SavingsDisplay = ({ cart }) => {
    // Calculate total savings from discounts
    const discountAmount = cart.discount_total
        ? toNumber(cart.discount_total.replace(/[^0-9.-]/g, ''))
        : 0;

    // Check for sale items savings
    const itemSavings = cart.items?.reduce((total, item) => {
        if (item.regular_price && item.sale_price) {
            const regular = toNumber(item.regular_price.replace(/[^0-9.-]/g, ''));
            const sale = toNumber(item.sale_price.replace(/[^0-9.-]/g, ''));
            return total + (regular - sale) * toNumber(item.quantity, 1);
        }
        return total;
    }, 0) || 0;

    const totalSavings = discountAmount + itemSavings;

    if (totalSavings <= 0) return null;

    return (
        <div className="glimmr-cart-savings">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="10" />
                <path d="M8 14s1.5 2 4 2 4-2 4-2" />
                <line x1="9" y1="9" x2="9.01" y2="9" />
                <line x1="15" y1="9" x2="15.01" y2="9" />
            </svg>
            <span>You're saving ${safeToFixed(totalSavings, 2, '0.00')} on this order!</span>
        </div>
    );
};

/**
 * CartPreview Component
 */
const CartPreview = ({
    cart,
    config = {},
    onUpdateQuantity,
    onRemoveItem,
    onApplyCoupon,
    onRemoveCoupon,
}) => {
    const [isApplyingCoupon, setIsApplyingCoupon] = useState(false);
    const [showAllItems, setShowAllItems] = useState(false);

    // Get config values
    const {
        cartInlineQuantity = true,
        cartShowSavings = true,
        cartCouponInput = true,
    } = config.artifacts || config;

    /**
     * Handle coupon application.
     */
    const handleApplyCoupon = useCallback(async (code) => {
        if (!onApplyCoupon) return;
        setIsApplyingCoupon(true);
        try {
            await onApplyCoupon(code);
        } finally {
            setIsApplyingCoupon(false);
        }
    }, [onApplyCoupon]);

    // Empty cart state
    if (!cart || !cart.items || cart.items.length === 0) {
        return (
            <div className="glimmr-cart-preview glimmr-cart-empty">
                <CartIcon />
                <p>Your cart is empty</p>
                <span className="glimmr-cart-empty-hint">
                    Ask me to help you find products!
                </span>
            </div>
        );
    }

    // Determine items to display
    const maxVisibleItems = 3;
    const displayItems = showAllItems ? cart.items : cart.items.slice(0, maxVisibleItems);
    const hasMoreItems = cart.items.length > maxVisibleItems;

    return (
        <div className="glimmr-cart-preview">
            {/* Header */}
            <div className="glimmr-cart-header">
                <div className="glimmr-cart-header-info">
                    <CartIcon />
                    <span>Your Cart ({cart.item_count} {cart.item_count === 1 ? 'item' : 'items'})</span>
                </div>
            </div>

            {/* Savings display */}
            {cartShowSavings && <SavingsDisplay cart={cart} />}

            {/* Items */}
            <div className="glimmr-cart-items">
                {displayItems.map((item) => (
                    <CartItem
                        key={item.key}
                        item={item}
                        showInlineQuantity={cartInlineQuantity}
                        onUpdateQuantity={onUpdateQuantity}
                        onRemove={onRemoveItem}
                    />
                ))}

                {hasMoreItems && !showAllItems && (
                    <button
                        type="button"
                        className="glimmr-cart-show-more"
                        onClick={() => setShowAllItems(true)}
                    >
                        +{cart.items.length - maxVisibleItems} more items
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </button>
                )}

                {hasMoreItems && showAllItems && (
                    <button
                        type="button"
                        className="glimmr-cart-show-less"
                        onClick={() => setShowAllItems(false)}
                    >
                        Show less
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
                            <polyline points="18 15 12 9 6 15" />
                        </svg>
                    </button>
                )}
            </div>

            {/* Applied coupons */}
            {cart.coupons && cart.coupons.length > 0 && (
                <div className="glimmr-cart-applied-coupons">
                    {cart.coupons.map((coupon) => (
                        <AppliedCoupon
                            key={coupon.code}
                            coupon={coupon}
                            onRemove={onRemoveCoupon}
                        />
                    ))}
                </div>
            )}

            {/* Coupon input */}
            {cartCouponInput && onApplyCoupon && (
                <CouponInput
                    onApply={handleApplyCoupon}
                    isApplying={isApplyingCoupon}
                />
            )}

            {/* Totals */}
            <div className="glimmr-cart-totals">
                {cart.subtotal && (
                    <div className="glimmr-cart-row">
                        <span>Subtotal</span>
                        <span>{cart.subtotal}</span>
                    </div>
                )}
                {cart.discount_total && cart.discount_total !== '$0.00' && (
                    <div className="glimmr-cart-row glimmr-cart-discount">
                        <span>Discount</span>
                        <span>-{cart.discount_total}</span>
                    </div>
                )}
                {cart.shipping_total && (
                    <div className="glimmr-cart-row">
                        <span>Shipping</span>
                        <span>{cart.shipping_total === '$0.00' ? 'Free' : cart.shipping_total}</span>
                    </div>
                )}
                {cart.tax_total && cart.tax_total !== '$0.00' && (
                    <div className="glimmr-cart-row">
                        <span>Tax</span>
                        <span>{cart.tax_total}</span>
                    </div>
                )}
                <div className="glimmr-cart-row glimmr-cart-total">
                    <span>Total</span>
                    <span>{cart.total}</span>
                </div>
            </div>

            {/* Actions */}
            <div className="glimmr-cart-actions">
                {cart.cart_url && new URL(cart.cart_url, window.location.origin).pathname !== '/' && (
                    <a
                        href={cart.cart_url}
                        className="glimmr-btn glimmr-btn-secondary"
                        aria-label={`View cart (${cart.item_count || 0} ${cart.item_count === 1 ? 'item' : 'items'})`}
                    >
                        View Cart
                    </a>
                )}
                <a
                    href={cart.checkout_url || '/checkout'}
                    className="glimmr-btn glimmr-btn-primary"
                    aria-label="Proceed to checkout"
                >
                    Checkout
                </a>
            </div>
        </div>
    );
};

export default CartPreview;
