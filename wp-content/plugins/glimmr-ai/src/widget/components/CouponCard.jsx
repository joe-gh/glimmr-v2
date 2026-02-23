/**
 * CouponCard - Coupon Display Component
 *
 * Displays coupon codes in an attractive ticket-style or badge format
 * with copy/apply actions.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState, useCallback } from 'preact/hooks';
import { debugError } from '../utils/debug';

/**
 * Tag icon.
 */
const TagIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
        <line x1="7" y1="7" x2="7.01" y2="7" />
    </svg>
);

/**
 * Copy icon.
 */
const CopyIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
    </svg>
);

/**
 * Check icon.
 */
const CheckIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <polyline points="20 6 9 17 4 12" />
    </svg>
);

/**
 * Clock icon for expiry.
 */
const ClockIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="10" />
        <polyline points="12 6 12 12 16 14" />
    </svg>
);

/**
 * Format date for display.
 */
const formatDate = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

/**
 * Check if coupon is expiring soon (within 7 days).
 */
const isExpiringSoon = (expiryDate) => {
    if (!expiryDate) return false;
    const expiry = new Date(expiryDate);
    const now = new Date();
    const daysUntilExpiry = Math.ceil((expiry - now) / (1000 * 60 * 60 * 24));
    return daysUntilExpiry > 0 && daysUntilExpiry <= 7;
};

/**
 * Ticket-style coupon card.
 */
const TicketCouponCard = ({
    coupon,
    showExpiry,
    showApplyButton,
    onApply,
    isApplying,
}) => {
    const [copied, setCopied] = useState(false);

    const handleCopy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(coupon.code);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            debugError('[CouponCard] Failed to copy:', err);
        }
    }, [coupon.code]);

    const expiringSoon = isExpiringSoon(coupon.expiry_date);

    return (
        <div className={`glimmr-coupon-card glimmr-coupon-ticket ${expiringSoon ? 'is-expiring-soon' : ''}`}>
            {/* Left section with decoration */}
            <div className="glimmr-coupon-ticket-left">
                <div className="glimmr-coupon-ticket-icon">
                    <TagIcon />
                </div>
                <div className="glimmr-coupon-ticket-notch glimmr-coupon-ticket-notch-top" />
                <div className="glimmr-coupon-ticket-notch glimmr-coupon-ticket-notch-bottom" />
            </div>

            {/* Right section with content */}
            <div className="glimmr-coupon-ticket-right">
                <div className="glimmr-coupon-ticket-content">
                    {/* Discount amount */}
                    <div className="glimmr-coupon-discount">
                        {coupon.discount_type === 'percent' ? (
                            <span className="glimmr-coupon-discount-value">{coupon.amount}%</span>
                        ) : (
                            <span className="glimmr-coupon-discount-value">${coupon.amount}</span>
                        )}
                        <span className="glimmr-coupon-discount-label">OFF</span>
                    </div>

                    {/* Coupon code */}
                    <div className="glimmr-coupon-code-wrapper">
                        <code className="glimmr-coupon-code">{coupon.code}</code>
                        <button
                            type="button"
                            className="glimmr-coupon-copy"
                            onClick={handleCopy}
                            aria-label={copied ? 'Copied!' : 'Copy coupon code'}
                        >
                            {copied ? <CheckIcon /> : <CopyIcon />}
                        </button>
                    </div>

                    {/* Description */}
                    {coupon.description && (
                        <p className="glimmr-coupon-description">{coupon.description}</p>
                    )}

                    {/* Minimum spend */}
                    {coupon.minimum_amount && parseFloat(coupon.minimum_amount) > 0 && (
                        <span className="glimmr-coupon-minimum">
                            Min. spend: ${coupon.minimum_amount}
                        </span>
                    )}

                    {/* Expiry */}
                    {showExpiry && coupon.expiry_date && (
                        <div className={`glimmr-coupon-expiry ${expiringSoon ? 'is-urgent' : ''}`}>
                            <ClockIcon />
                            <span>
                                {expiringSoon ? 'Expires soon: ' : 'Valid until: '}
                                {formatDate(coupon.expiry_date)}
                            </span>
                        </div>
                    )}
                </div>

                {/* Apply button */}
                {showApplyButton && onApply && (
                    <button
                        type="button"
                        className="glimmr-btn glimmr-btn-primary glimmr-coupon-apply"
                        onClick={() => onApply(coupon.code)}
                        disabled={isApplying}
                    >
                        {isApplying ? 'Applying...' : 'Apply to Cart'}
                    </button>
                )}
            </div>
        </div>
    );
};

/**
 * Badge-style coupon (compact).
 */
const BadgeCouponCard = ({
    coupon,
    showExpiry,
    showApplyButton,
    onApply,
    isApplying,
}) => {
    const [copied, setCopied] = useState(false);

    const handleCopy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(coupon.code);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            debugError('[CouponCard] Failed to copy:', err);
        }
    }, [coupon.code]);

    const expiringSoon = isExpiringSoon(coupon.expiry_date);

    return (
        <div className={`glimmr-coupon-card glimmr-coupon-badge ${expiringSoon ? 'is-expiring-soon' : ''}`}>
            <div className="glimmr-coupon-badge-header">
                <TagIcon />
                <span className="glimmr-coupon-badge-discount">
                    {coupon.discount_type === 'percent' ? `${coupon.amount}% OFF` : `$${coupon.amount} OFF`}
                </span>
            </div>

            <div className="glimmr-coupon-badge-body">
                <div className="glimmr-coupon-code-wrapper">
                    <code className="glimmr-coupon-code">{coupon.code}</code>
                    <button
                        type="button"
                        className="glimmr-coupon-copy"
                        onClick={handleCopy}
                        aria-label={copied ? 'Copied!' : 'Copy coupon code'}
                    >
                        {copied ? <CheckIcon /> : <CopyIcon />}
                    </button>
                </div>

                {coupon.description && (
                    <p className="glimmr-coupon-description">{coupon.description}</p>
                )}

                <div className="glimmr-coupon-badge-footer">
                    {coupon.minimum_amount && parseFloat(coupon.minimum_amount) > 0 && (
                        <span className="glimmr-coupon-minimum">
                            Min: ${coupon.minimum_amount}
                        </span>
                    )}
                    {showExpiry && coupon.expiry_date && (
                        <span className={`glimmr-coupon-expiry ${expiringSoon ? 'is-urgent' : ''}`}>
                            <ClockIcon />
                            {formatDate(coupon.expiry_date)}
                        </span>
                    )}
                </div>
            </div>

            {showApplyButton && onApply && (
                <button
                    type="button"
                    className="glimmr-btn glimmr-btn-primary glimmr-coupon-apply"
                    onClick={() => onApply(coupon.code)}
                    disabled={isApplying}
                >
                    {isApplying ? 'Applying...' : 'Apply'}
                </button>
            )}
        </div>
    );
};

/**
 * Coupon list container for multiple coupons.
 */
export const CouponList = ({
    coupons = [],
    config = {},
    onApply,
}) => {
    const [applyingCode, setApplyingCode] = useState(null);

    // Get config values
    const {
        couponStyle = 'ticket',
        couponShowExpiry = true,
        couponApplyButton = true,
    } = config.artifacts || config;

    /**
     * Handle apply coupon.
     */
    const handleApply = useCallback(async (code) => {
        if (!onApply) return;
        setApplyingCode(code);
        try {
            await onApply(code);
        } finally {
            setApplyingCode(null);
        }
    }, [onApply]);

    if (!coupons || coupons.length === 0) {
        return (
            <div className="glimmr-coupon-empty">
                <TagIcon />
                <p>No coupons available</p>
            </div>
        );
    }

    const CouponComponent = couponStyle === 'badge' ? BadgeCouponCard : TicketCouponCard;

    return (
        <div className={`glimmr-coupon-list glimmr-coupon-list-${couponStyle}`}>
            {coupons.map((coupon) => (
                <CouponComponent
                    key={coupon.code}
                    coupon={coupon}
                    showExpiry={couponShowExpiry}
                    showApplyButton={couponApplyButton}
                    onApply={handleApply}
                    isApplying={applyingCode === coupon.code}
                />
            ))}
        </div>
    );
};

/**
 * Main CouponCard component (single coupon display).
 */
const CouponCard = ({
    coupon,
    config = {},
    onApply,
}) => {
    const [isApplying, setIsApplying] = useState(false);

    // Get config values
    const {
        couponStyle = 'ticket',
        couponShowExpiry = true,
        couponApplyButton = true,
    } = config.artifacts || config;

    /**
     * Handle apply coupon.
     */
    const handleApply = useCallback(async (code) => {
        if (!onApply) return;
        setIsApplying(true);
        try {
            await onApply(code);
        } finally {
            setIsApplying(false);
        }
    }, [onApply]);

    if (!coupon) return null;

    const CouponComponent = couponStyle === 'badge' ? BadgeCouponCard : TicketCouponCard;

    return (
        <CouponComponent
            coupon={coupon}
            showExpiry={couponShowExpiry}
            showApplyButton={couponApplyButton}
            onApply={handleApply}
            isApplying={isApplying}
        />
    );
};

export default CouponCard;
