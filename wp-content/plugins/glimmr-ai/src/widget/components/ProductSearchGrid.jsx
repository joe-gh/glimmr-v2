/**
 * ProductSearchGrid - Product Search Results Grid
 *
 * Displays product search results in a configurable tile grid.
 * Clicking a tile opens the ProductDetailModal.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useCallback } from 'preact/hooks';
import { getRating, safeFloor, calculateDiscountPercent } from '../utils/numbers';

/**
 * Star rating component.
 */
const StarRating = ({ rating, reviewCount }) => {
    const numRating = getRating(rating);
    const fullStars = safeFloor(numRating);
    const hasHalfStar = numRating % 1 >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

    return (
        <div className="glimmr-rating" aria-label={`${numRating} out of 5 stars`}>
            <div className="glimmr-stars">
                {[...Array(fullStars)].map((_, i) => (
                    <svg key={`full-${i}`} className="glimmr-star glimmr-star-filled" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                ))}
                {hasHalfStar && (
                    <svg className="glimmr-star glimmr-star-half" viewBox="0 0 20 20">
                        <defs>
                            <linearGradient id="half-star">
                                <stop offset="50%" stopColor="var(--glimmr-star-filled)" />
                                <stop offset="50%" stopColor="var(--glimmr-star-empty)" />
                            </linearGradient>
                        </defs>
                        <path fill="url(#half-star)" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                )}
                {[...Array(emptyStars)].map((_, i) => (
                    <svg key={`empty-${i}`} className="glimmr-star glimmr-star-empty" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                ))}
            </div>
            {reviewCount !== undefined && (
                <span className="glimmr-review-count">({reviewCount})</span>
            )}
        </div>
    );
};

/**
 * Stock status badge.
 */
const StockBadge = ({ inStock, stockQuantity, stockStatus }) => {
    let statusClass = 'in-stock';
    let statusText = 'In Stock';

    if (!inStock || stockStatus === 'outofstock') {
        statusClass = 'out-of-stock';
        statusText = 'Out of Stock';
    } else if (stockStatus === 'onbackorder') {
        statusClass = 'backorder';
        statusText = 'Backorder';
    } else if (stockQuantity && stockQuantity <= 5) {
        statusClass = 'low-stock';
        statusText = `Only ${stockQuantity} left`;
    }

    return (
        <span className={`glimmr-stock-badge glimmr-stock-${statusClass}`}>
            {statusText}
        </span>
    );
};

/**
 * Single product tile.
 */
const ProductTile = ({
    product,
    cardStyle = 'detailed',
    showRating = true,
    showStock = true,
    onClick,
}) => {
    const discountPercent = calculateDiscountPercent(product.regular_price, product.sale_price);
    const hasDiscount = discountPercent > 0;

    return (
        <button
            type="button"
            className={`glimmr-product-tile glimmr-product-tile-${cardStyle}`}
            onClick={() => onClick(product)}
            aria-label={`View ${product.name}`}
        >
            {/* Product image */}
            <div className="glimmr-product-tile-image">
                {product.image ? (
                    <img src={product.image} alt={product.name} loading="lazy" />
                ) : (
                    <div className="glimmr-product-tile-placeholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <polyline points="21 15 16 10 5 21" />
                        </svg>
                    </div>
                )}

                {/* Discount badge */}
                {hasDiscount && (
                    <span className="glimmr-product-tile-discount">
                        -{discountPercent}%
                    </span>
                )}
            </div>

            {/* Product info */}
            <div className="glimmr-product-tile-info">
                <h4 className="glimmr-product-tile-name">{product.name}</h4>

                {/* Rating - only in detailed mode */}
                {cardStyle === 'detailed' && showRating && product.rating !== undefined && (
                    <StarRating rating={product.rating} reviewCount={product.review_count} />
                )}

                {/* Price */}
                <div className="glimmr-product-tile-price">
                    {hasDiscount ? (
                        <>
                            <span className="glimmr-price-sale">{product.price_html || product.sale_price}</span>
                            <span className="glimmr-price-regular">{product.regular_price}</span>
                        </>
                    ) : (
                        <span className="glimmr-price-current">{product.price_html || product.price || product.regular_price}</span>
                    )}
                </div>

                {/* Stock status - only in detailed mode */}
                {cardStyle === 'detailed' && showStock && (
                    <StockBadge
                        inStock={product.in_stock}
                        stockQuantity={product.stock_quantity}
                        stockStatus={product.stock_status}
                    />
                )}
            </div>
        </button>
    );
};

/**
 * Main ProductSearchGrid component.
 */
const ProductSearchGrid = ({
    products = [],
    config = {},
    onProductClick,
    resultCount,
    searchQuery,
}) => {
    // Get config values with defaults
    const {
        gridColumns = '2',
        gridCardStyle = 'detailed',
        gridShowRating = true,
        gridShowStock = true,
    } = config.artifacts || config;

    const handleProductClick = useCallback((product) => {
        if (onProductClick) {
            onProductClick(product);
        }
    }, [onProductClick]);

    if (!products || products.length === 0) {
        return (
            <div className="glimmr-product-grid-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <circle cx="11" cy="11" r="8" />
                    <path d="M21 21l-4.35-4.35" />
                </svg>
                <p>No products found</p>
                {searchQuery && <p className="glimmr-product-grid-query">for "{searchQuery}"</p>}
            </div>
        );
    }

    return (
        <div className="glimmr-product-grid-wrapper">
            {/* Header with result count */}
            {resultCount !== undefined && (
                <div className="glimmr-product-grid-header">
                    <span className="glimmr-product-grid-count">
                        {resultCount} {resultCount === 1 ? 'product' : 'products'} found
                    </span>
                </div>
            )}

            {/* Product grid */}
            <div
                className={`glimmr-product-grid glimmr-product-grid-cols-${gridColumns}`}
                role="list"
            >
                {products.map((product) => (
                    <div key={product.id} role="listitem">
                        <ProductTile
                            product={product}
                            cardStyle={gridCardStyle}
                            showRating={gridShowRating}
                            showStock={gridShowStock}
                            onClick={handleProductClick}
                        />
                    </div>
                ))}
            </div>
        </div>
    );
};

export default ProductSearchGrid;
