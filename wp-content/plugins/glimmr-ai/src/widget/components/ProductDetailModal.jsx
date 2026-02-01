/**
 * ProductDetailModal - Full Product View Overlay
 *
 * Modal overlay for viewing full product details, selecting variations,
 * and adding to cart.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useEffect, useCallback, useRef } from 'preact/hooks';
import ImageGallery from './ImageGallery';
import VariationSelector from './VariationSelector';
import { getRating, safeRound, toNumber, safeToFixed } from '../utils/numbers';
import { debug, debugError } from '../utils/debug';
import { trackProductView, trackAddToCart } from '../utils/ga4';

/**
 * Close icon.
 */
const CloseIcon = () => (
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

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
 * Quantity selector component.
 */
const QuantitySelector = ({ value, min = 1, max = 99, onChange, disabled }) => {
    const handleDecrease = () => {
        if (value > min) onChange(value - 1);
    };

    const handleIncrease = () => {
        if (value < max) onChange(value + 1);
    };

    const handleInputChange = (e) => {
        const newValue = parseInt(e.target.value, 10);
        if (!isNaN(newValue) && newValue >= min && newValue <= max) {
            onChange(newValue);
        }
    };

    return (
        <div className="glimmr-quantity-selector">
            <button
                type="button"
                className="glimmr-quantity-btn"
                onClick={handleDecrease}
                disabled={disabled || value <= min}
                aria-label="Decrease quantity"
            >
                -
            </button>
            <input
                type="number"
                className="glimmr-quantity-input"
                value={value}
                onChange={handleInputChange}
                min={min}
                max={max}
                disabled={disabled}
                aria-label="Quantity"
            />
            <button
                type="button"
                className="glimmr-quantity-btn"
                onClick={handleIncrease}
                disabled={disabled || value >= max}
                aria-label="Increase quantity"
            >
                +
            </button>
        </div>
    );
};

/**
 * Main ProductDetailModal component.
 */
const ProductDetailModal = ({
    product,
    config = {},
    isOpen,
    isLoading = false,
    onClose,
    onAddToCart,
}) => {
    const [quantity, setQuantity] = useState(1);
    const [selectedVariation, setSelectedVariation] = useState(null);
    const [selectedAttributes, setSelectedAttributes] = useState({});
    const [isAdding, setIsAdding] = useState(false);
    const [addedSuccess, setAddedSuccess] = useState(false);
    const modalRef = useRef(null);
    const previousFocusRef = useRef(null);

    // Get config values
    const {
        modalImageStyle = 'gallery',
        modalShowReviews = true,
    } = config.artifacts || config;

    /**
     * Reset state when product changes.
     */
    useEffect(() => {
        if (product) {
            setQuantity(1);
            setSelectedVariation(null);
            setSelectedAttributes({});
            setAddedSuccess(false);

            // Track product view for GA4
            trackProductView(
                product.id,
                product.name,
                toNumber(product.price) || toNumber(product.price_raw) || 0
            );
        }
    }, [product?.id]);

    /**
     * Focus management: save previous focus and restore on close.
     */
    useEffect(() => {
        if (isOpen) {
            // Save the currently focused element
            previousFocusRef.current = document.activeElement;

            // Focus the close button or first focusable element
            if (modalRef.current) {
                const closeBtn = modalRef.current.querySelector('.glimmr-modal-close');
                if (closeBtn) {
                    closeBtn.focus();
                }
            }
        } else if (previousFocusRef.current) {
            // Restore focus when modal closes
            previousFocusRef.current.focus();
            previousFocusRef.current = null;
        }
    }, [isOpen]);

    /**
     * Handle keyboard events: escape to close and focus trapping.
     */
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e) => {
            // Close on Escape
            if (e.key === 'Escape') {
                onClose();
                return;
            }

            // Focus trap on Tab
            if (e.key === 'Tab' && modalRef.current) {
                const focusableElements = modalRef.current.querySelectorAll(
                    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );

                if (focusableElements.length === 0) return;

                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];

                if (e.shiftKey) {
                    // Shift+Tab: if on first element, wrap to last
                    if (document.activeElement === firstElement) {
                        e.preventDefault();
                        lastElement.focus();
                    }
                } else {
                    // Tab: if on last element, wrap to first
                    if (document.activeElement === lastElement) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [isOpen, onClose]);

    /**
     * Handle variation selection change.
     */
    const handleVariationChange = useCallback((attributes, variation) => {
        debug('[ProductDetailModal] Variation selected:', {
            attributes,
            variation,
            variationPrice: variation?.price,
        });
        setSelectedAttributes(attributes);
        setSelectedVariation(variation);
    }, []);

    /**
     * Handle add to cart.
     */
    const handleAddToCart = useCallback(async () => {
        debug('[ProductDetailModal] Add to cart clicked:', {
            product: product?.id,
            productType: product?.type,
            selectedVariation,
            quantity,
            selectedAttributes,
            hasOnAddToCart: typeof onAddToCart === 'function',
            isAdding,
        });

        if (!product || isAdding) {
            debug('[ProductDetailModal] Blocked: no product or already adding');
            return;
        }

        // For variable products, require variation selection
        if (product.type === 'variable' && !selectedVariation) {
            debug('[ProductDetailModal] Blocked: variable product needs variation');
            return;
        }

        setIsAdding(true);

        try {
            const cartData = {
                productId: product.id,
                variationId: selectedVariation?.id || selectedVariation?.variation_id,
                quantity,
                attributes: selectedAttributes,
            };
            debug('[ProductDetailModal] Calling onAddToCart with:', cartData);

            await onAddToCart(cartData);
            debug('[ProductDetailModal] Add to cart succeeded');
            setAddedSuccess(true);
            setTimeout(() => setAddedSuccess(false), 2000);

            // Track add to cart for GA4
            const priceForTracking = selectedVariation?.price || product?.price || product?.price_raw || 0;
            trackAddToCart(
                product.id,
                product.name,
                toNumber(priceForTracking),
                quantity
            );
        } catch (error) {
            debugError('[ProductDetailModal] Add to cart failed:', error);
        } finally {
            setIsAdding(false);
        }
    }, [product, selectedVariation, quantity, selectedAttributes, onAddToCart, isAdding]);

    /**
     * Get current price (variation or product price).
     */
    const getCurrentPrice = () => {
        if (selectedVariation) {
            return {
                price: selectedVariation.price,
                regular: selectedVariation.regular_price,
                sale: selectedVariation.sale_price,
            };
        }
        return {
            price: product?.price,
            regular: product?.regular_price,
            sale: product?.sale_price,
        };
    };

    /**
     * Get stock status.
     */
    const getStockStatus = () => {
        const source = selectedVariation || product;
        if (!source) return { inStock: false, text: 'Unavailable' };

        if (!source.in_stock || source.stock_status === 'outofstock') {
            return { inStock: false, text: 'Out of Stock', className: 'out-of-stock' };
        }
        if (source.stock_status === 'onbackorder') {
            return { inStock: true, text: 'Available on Backorder', className: 'backorder' };
        }
        if (source.stock_quantity && source.stock_quantity <= 5) {
            return { inStock: true, text: `Only ${source.stock_quantity} left`, className: 'low-stock' };
        }
        return { inStock: true, text: 'In Stock', className: 'in-stock' };
    };

    /**
     * Check if can add to cart.
     */
    const canAddToCart = () => {
        if (!product) return false;
        const stock = getStockStatus();
        if (!stock.inStock && stock.className !== 'backorder') return false;
        if (product.type === 'variable' && !selectedVariation) return false;
        return true;
    };

    if (!isOpen || !product) return null;

    const prices = getCurrentPrice();
    const stock = getStockStatus();
    const images = product.gallery || (product.image ? [product.image] : []);

    return (
        <div
            className="glimmr-modal-overlay"
            onClick={(e) => e.target === e.currentTarget && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="product-modal-title"
        >
            <div
                ref={modalRef}
                className="glimmr-modal glimmr-product-modal"
            >
                {/* Close button */}
                <button
                    type="button"
                    className="glimmr-modal-close"
                    onClick={onClose}
                    aria-label="Close"
                >
                    <CloseIcon />
                </button>

                <div className="glimmr-product-modal-content">
                    {/* Image gallery */}
                    <div className="glimmr-product-modal-gallery">
                        <ImageGallery
                            images={images}
                            style={modalImageStyle}
                            showThumbnails={modalImageStyle === 'gallery'}
                        />
                    </div>

                    {/* Product details */}
                    <div className="glimmr-product-modal-details">
                        {/* Title */}
                        <h2 id="product-modal-title" className="glimmr-product-modal-title">
                            {product.name}
                        </h2>

                        {/* Rating */}
                        {modalShowReviews && product.rating !== undefined && (
                            <div className="glimmr-product-modal-rating">
                                <div className="glimmr-stars">
                                    {[...Array(5)].map((_, i) => (
                                        <svg
                                            key={i}
                                            className={`glimmr-star ${i < safeRound(getRating(product.rating)) ? 'glimmr-star-filled' : 'glimmr-star-empty'}`}
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            focusable="false"
                                        >
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    ))}
                                </div>
                                <span className="glimmr-review-count">
                                    {product.review_count} {product.review_count === 1 ? 'review' : 'reviews'}
                                </span>
                            </div>
                        )}

                        {/* Price */}
                        <div className="glimmr-product-modal-price">
                            {prices.sale && toNumber(prices.sale) < toNumber(prices.regular) ? (
                                <>
                                    <span className="glimmr-price-sale">{prices.sale}</span>
                                    <span className="glimmr-price-regular">{prices.regular}</span>
                                    <span className="glimmr-price-savings">
                                        Save ${safeToFixed(toNumber(prices.regular) - toNumber(prices.sale), 2, '0.00')}
                                    </span>
                                </>
                            ) : (
                                <span className="glimmr-price-current">{prices.price || prices.regular}</span>
                            )}
                        </div>

                        {/* Stock status */}
                        <div className={`glimmr-product-modal-stock glimmr-stock-${stock.className}`}>
                            {stock.text}
                        </div>

                        {/* Short description */}
                        {product.short_description && (
                            <div
                                className="glimmr-product-modal-description"
                                dangerouslySetInnerHTML={{ __html: product.short_description }}
                            />
                        )}

                        {/* Full description (scrollable) */}
                        {product.description && (
                            <div className="glimmr-product-modal-full-description">
                                <div
                                    className="glimmr-product-modal-full-description-content"
                                    dangerouslySetInnerHTML={{ __html: product.description }}
                                />
                            </div>
                        )}

                        {/* Reviews section */}
                        {modalShowReviews && product.reviews && product.reviews.length > 0 && (
                            <div className="glimmr-product-modal-reviews">
                                <h4 className="glimmr-reviews-title">
                                    Recent Reviews
                                    {product.review_count > 0 && (
                                        <span className="glimmr-reviews-count">({product.review_count})</span>
                                    )}
                                </h4>
                                <div className="glimmr-reviews-list">
                                    {product.reviews.map((review, idx) => (
                                        <div key={idx} className="glimmr-review">
                                            <div className="glimmr-review-header">
                                                <span className="glimmr-review-author">{review.author}</span>
                                                {review.verified && (
                                                    <span className="glimmr-verified-badge">Verified</span>
                                                )}
                                                <div className="glimmr-review-stars">
                                                    {[...Array(5)].map((_, i) => (
                                                        <svg
                                                            key={i}
                                                            className={`glimmr-star ${i < review.rating ? 'glimmr-star-filled' : 'glimmr-star-empty'}`}
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            width="12"
                                                            height="12"
                                                            aria-hidden="true"
                                                        >
                                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                                        </svg>
                                                    ))}
                                                </div>
                                            </div>
                                            <p className="glimmr-review-text">{review.text}</p>
                                            <span className="glimmr-review-date">
                                                {new Date(review.date).toLocaleDateString()}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Loading indicator for additional details */}
                        {isLoading && !product.description && !product.attributes && (
                            <div className="glimmr-product-modal-loading">
                                <span className="glimmr-spinner" aria-hidden="true" />
                                <span>Loading product details...</span>
                            </div>
                        )}

                        {/* Variation selector */}
                        {product.type === 'variable' && product.attributes && (
                            <div className="glimmr-product-modal-variations">
                                <VariationSelector
                                    attributes={product.attributes}
                                    variations={product.variations}
                                    selectedAttributes={selectedAttributes}
                                    onChange={handleVariationChange}
                                />
                            </div>
                        )}

                        {/* Loading placeholder for variations (variable products without attributes yet) */}
                        {product.type === 'variable' && !product.attributes && isLoading && (
                            <div className="glimmr-product-modal-variations-loading">
                                <div className="glimmr-skeleton glimmr-skeleton-variation" aria-label="Loading size options" />
                                <div className="glimmr-skeleton glimmr-skeleton-variation" aria-label="Loading color options" />
                            </div>
                        )}

                        {/* Quantity and Add to Cart */}
                        <div className="glimmr-product-modal-actions">
                            <QuantitySelector
                                value={quantity}
                                min={1}
                                max={stock.inStock ? (product.stock_quantity || 99) : 0}
                                onChange={setQuantity}
                                disabled={!canAddToCart()}
                            />

                            <button
                                type="button"
                                className={`glimmr-btn glimmr-btn-primary glimmr-add-to-cart ${addedSuccess ? 'is-success' : ''}`}
                                onClick={handleAddToCart}
                                disabled={!canAddToCart() || isAdding}
                            >
                                {isAdding ? (
                                    <span className="glimmr-spinner" />
                                ) : addedSuccess ? (
                                    <>
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                        Added!
                                    </>
                                ) : (
                                    <>
                                        <CartIcon />
                                        Add to Cart
                                    </>
                                )}
                            </button>
                        </div>

                        {/* Variable product hint */}
                        {product.type === 'variable' && !selectedVariation && (
                            <p className="glimmr-product-modal-hint">
                                Please select options above to add to cart
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ProductDetailModal;
