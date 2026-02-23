/**
 * ProductComparisonTable - Side-by-Side Product Comparison
 *
 * Displays products in a comparison table format with highlighted
 * best values. Opens as an overlay with button trigger in chat.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useMemo, useEffect, useRef } from 'preact/hooks';
import { toNumber, safeToFixed, safeRound, getRating } from '../utils/numbers';
import { translateAttributeLabel } from '../utils/attributeLabels';

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
 * Check icon for best value highlight.
 */
const CheckIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <polyline points="20 6 9 17 4 12" />
    </svg>
);

/**
 * Star rating display.
 */
const StarRating = ({ rating }) => {
    const numRating = getRating(rating);
    return (
        <div className="glimmr-comparison-stars" aria-label={`${numRating} out of 5 stars`}>
            {[...Array(5)].map((_, i) => (
                <svg
                    key={i}
                    className={`glimmr-star ${i < safeRound(numRating) ? 'glimmr-star-filled' : 'glimmr-star-empty'}`}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    width="14"
                    height="14"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
            ))}
            <span>{safeToFixed(numRating, 1, '0.0')}</span>
        </div>
    );
};

/**
 * Comparison row component.
 */
const ComparisonRow = ({ label, values, highlightBest, bestType = 'lowest' }) => {
    // Determine which value is "best"
    const bestIndex = useMemo(() => {
        if (!highlightBest || values.every((v) => v === null || v === undefined)) {
            return -1;
        }

        const numericValues = values.map((v) => {
            if (v === null || v === undefined) return null;
            if (typeof v === 'number') return v;
            // Handle rating objects
            if (typeof v === 'object' && v.rating !== undefined) {
                return toNumber(v.rating, null);
            }
            // Parse price strings - strip non-numeric chars
            const cleaned = String(v).replace(/[^0-9.-]/g, '');
            const num = toNumber(cleaned, null);
            return num;
        });

        if (numericValues.every((v) => v === null)) return -1;

        if (bestType === 'lowest') {
            const min = Math.min(...numericValues.filter((v) => v !== null));
            return numericValues.indexOf(min);
        } else {
            const max = Math.max(...numericValues.filter((v) => v !== null));
            return numericValues.indexOf(max);
        }
    }, [values, highlightBest, bestType]);

    return (
        <tr className="glimmr-comparison-row">
            <th className="glimmr-comparison-label">{label}</th>
            {values.map((value, index) => (
                <td
                    key={index}
                    className={`glimmr-comparison-value ${index === bestIndex ? 'is-best' : ''}`}
                >
                    {value !== null && value !== undefined ? (
                        <>
                            {typeof value === 'object' && value.rating !== undefined ? (
                                <StarRating rating={value.rating} />
                            ) : (
                                value
                            )}
                            {index === bestIndex && (
                                <span className="glimmr-best-badge">
                                    <CheckIcon /> Best
                                </span>
                            )}
                        </>
                    ) : (
                        <span className="glimmr-comparison-na">-</span>
                    )}
                </td>
            ))}
        </tr>
    );
};

/**
 * Trigger button shown in chat.
 */
export const ComparisonTrigger = ({ productCount, onClick }) => (
    <button
        type="button"
        className="glimmr-comparison-trigger"
        onClick={onClick}
        aria-label={`Open comparison of ${productCount} products`}
    >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
            <line x1="18" y1="20" x2="18" y2="10" />
            <line x1="12" y1="20" x2="12" y2="4" />
            <line x1="6" y1="20" x2="6" y2="14" />
        </svg>
        Compare {productCount} Products
    </button>
);

/**
 * Main ProductComparisonTable component.
 */
const ProductComparisonTable = ({
    products = [],
    config = {},
    isOpen,
    onClose,
    onProductClick,
}) => {
    const modalRef = useRef(null);
    const previousFocusRef = useRef(null);

    // Get config values
    const {
        comparisonLayout = 'table',
        comparisonHighlightBest = true,
        comparisonMaxProducts = 8,
    } = config.artifacts || config;

    // Limit products to max
    const displayProducts = products.slice(0, comparisonMaxProducts);

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
     * Build comparison attributes from products.
     */
    const comparisonData = useMemo(() => {
        if (displayProducts.length === 0) return [];

        // Comprehensive comparison fields - show all available product data
        const fields = [
            {
                key: 'price',
                label: 'Price',
                getValue: (p) => {
                    const price = p.sale_price || p.price || p.regular_price;
                    if (!price) return null;
                    // Strip existing $ sign to avoid double $$
                    const cleanPrice = String(price).replace(/^\$/, '');
                    return `$${cleanPrice}`;
                },
                bestType: 'lowest',
            },
            {
                key: 'category',
                label: 'Category',
                getValue: (p) => {
                    if (!p.categories || p.categories.length === 0) return null;
                    return Array.isArray(p.categories) ? p.categories.join(', ') : p.categories;
                },
                bestType: null,
            },
            {
                key: 'description',
                label: 'Description',
                getValue: (p) => p.short_description || p.description || null,
                bestType: null,
            },
            {
                key: 'colors',
                label: 'Colors',
                getValue: (p) => {
                    if (!p.available_colors || p.available_colors.length === 0) return null;
                    return p.available_colors.join(', ');
                },
                bestType: null,
            },
            {
                key: 'sizes',
                label: 'Sizes',
                getValue: (p) => {
                    if (!p.available_sizes || p.available_sizes.length === 0) return null;
                    return p.available_sizes.join(', ');
                },
                bestType: null,
            },
            {
                key: 'rating',
                label: 'Rating',
                getValue: (p) => {
                    const numRating = getRating(p.rating);
                    if (numRating === 0) return null;
                    return { rating: numRating };
                },
                bestType: 'highest',
            },
            {
                key: 'reviews',
                label: 'Reviews',
                getValue: (p) => {
                    if (p.review_count === undefined || p.review_count === 0) return null;
                    return `${p.review_count} reviews`;
                },
                bestType: 'highest',
            },
            {
                key: 'stock',
                label: 'Availability',
                getValue: (p) => {
                    if (!p.in_stock || p.stock_status === 'outofstock') return 'Out of Stock';
                    if (p.stock_quantity && p.stock_quantity <= 5) return `${p.stock_quantity} left`;
                    return 'In Stock';
                },
                bestType: null,
            },
            {
                key: 'sku',
                label: 'SKU',
                getValue: (p) => p.sku || null,
                bestType: null,
            },
            {
                key: 'variations',
                label: 'Variations',
                getValue: (p) => {
                    if (!p.variation_count || p.variation_count === 0) return null;
                    return `${p.variation_count} options`;
                },
                bestType: 'highest',
            },
        ];

        // Collect all unique custom attributes from products
        const attributeKeys = new Set();
        displayProducts.forEach((product) => {
            if (product.attributes) {
                Object.keys(product.attributes).forEach((key) => attributeKeys.add(key));
            }
            if (product.custom_attributes && typeof product.custom_attributes === 'object') {
                Object.keys(product.custom_attributes).forEach((key) => attributeKeys.add(key));
            }
        });

        // Add custom attribute fields
        attributeKeys.forEach((key) => {
            fields.push({
                key: `attr_${key}`,
                label: translateAttributeLabel(key),
                getValue: (p) => {
                    const val = p.attributes?.[key] || p.custom_attributes?.[key];
                    if (!val) return null;
                    return Array.isArray(val) ? val.join(', ') : val;
                },
                bestType: null,
            });
        });

        // Filter out fields where ALL products have null values
        return fields.filter((field) => {
            return displayProducts.some((p) => field.getValue(p) !== null);
        });
    }, [displayProducts]);

    if (!isOpen || displayProducts.length === 0) return null;

    // Cards layout for mobile-friendly view
    if (comparisonLayout === 'cards') {
        return (
            <div
                className="glimmr-modal-overlay"
                onClick={(e) => e.target === e.currentTarget && onClose()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="comparison-cards-title"
            >
                <div ref={modalRef} className="glimmr-modal glimmr-comparison-modal glimmr-comparison-cards">
                    <button
                        type="button"
                        className="glimmr-modal-close"
                        onClick={onClose}
                        aria-label="Close comparison"
                    >
                        <CloseIcon />
                    </button>

                    <h2 id="comparison-cards-title" className="glimmr-comparison-title">Compare Products</h2>

                    <div className="glimmr-comparison-cards-container">
                        {displayProducts.map((product) => (
                            <div key={product.id} className="glimmr-comparison-card">
                                <div className="glimmr-comparison-card-header">
                                    {product.image && (
                                        <img
                                            src={product.image}
                                            alt={product.name}
                                            className="glimmr-comparison-card-image"
                                        />
                                    )}
                                    <h3 className="glimmr-comparison-card-name">
                                        <button
                                            type="button"
                                            className="glimmr-link-button"
                                            onClick={() => onProductClick?.(product)}
                                        >
                                            {product.name}
                                        </button>
                                    </h3>
                                    {product.sku && (
                                        <span className="glimmr-comparison-sku">
                                            {product.sku}
                                        </span>
                                    )}
                                </div>

                                <dl className="glimmr-comparison-card-details">
                                    {comparisonData.map((field) => {
                                        const value = field.getValue(product);
                                        if (value === null) return null;

                                        return (
                                            <div key={field.key} className="glimmr-comparison-card-row">
                                                <dt>{field.label}</dt>
                                                <dd>
                                                    {typeof value === 'object' && value.rating !== undefined ? (
                                                        <StarRating rating={value.rating} />
                                                    ) : (
                                                        value
                                                    )}
                                                </dd>
                                            </div>
                                        );
                                    })}
                                </dl>

                                <button
                                    type="button"
                                    className="glimmr-btn glimmr-btn-primary glimmr-comparison-card-btn"
                                    onClick={() => onProductClick?.(product)}
                                    aria-label={`View details for ${product.name}`}
                                >
                                    View Details
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        );
    }

    // Table layout (default)
    return (
        <div
            className="glimmr-modal-overlay"
            onClick={(e) => e.target === e.currentTarget && onClose()}
            role="dialog"
            aria-modal="true"
            aria-labelledby="comparison-title"
        >
            <div ref={modalRef} className="glimmr-modal glimmr-comparison-modal">
                <button
                    type="button"
                    className="glimmr-modal-close"
                    onClick={onClose}
                    aria-label="Close comparison"
                >
                    <CloseIcon />
                </button>

                <h2 id="comparison-title" className="glimmr-comparison-title">
                    Compare Products
                </h2>

                <div className="glimmr-comparison-table-wrapper">
                    <table className="glimmr-comparison-table">
                        <thead>
                            <tr>
                                <th className="glimmr-comparison-corner"></th>
                                {displayProducts.map((product) => (
                                    <th key={product.id} className="glimmr-comparison-header">
                                        <div className="glimmr-comparison-product">
                                            {product.image && (
                                                <img
                                                    src={product.image}
                                                    alt={product.name}
                                                    className="glimmr-comparison-image"
                                                />
                                            )}
                                            <button
                                                type="button"
                                                className="glimmr-link-button glimmr-comparison-name"
                                                onClick={() => onProductClick?.(product)}
                                            >
                                                {product.name}
                                            </button>
                                            {product.sku && (
                                                <span className="glimmr-comparison-sku">
                                                    {product.sku}
                                                </span>
                                            )}
                                        </div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {comparisonData.map((field) => (
                                <ComparisonRow
                                    key={field.key}
                                    label={field.label}
                                    values={displayProducts.map((p) => field.getValue(p))}
                                    highlightBest={comparisonHighlightBest && field.bestType !== null}
                                    bestType={field.bestType}
                                />
                            ))}
                            <tr className="glimmr-comparison-actions-row">
                                <th scope="row">Actions</th>
                                {displayProducts.map((product) => (
                                    <td key={product.id}>
                                        <button
                                            type="button"
                                            className="glimmr-btn glimmr-btn-primary glimmr-comparison-add-btn"
                                            onClick={() => onProductClick?.(product)}
                                            aria-label={`View details for ${product.name}`}
                                        >
                                            View Details
                                        </button>
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
};

export default ProductComparisonTable;
