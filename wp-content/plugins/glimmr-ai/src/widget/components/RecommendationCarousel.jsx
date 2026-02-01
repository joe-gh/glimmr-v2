/**
 * RecommendationCarousel - Product Recommendation Carousel
 *
 * Horizontal scrollable carousel for product recommendations
 * with auto-scroll option and reason display.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useRef, useEffect, useCallback } from 'preact/hooks';
import { getRating, safeRound, calculateDiscountPercent } from '../utils/numbers';
import { debugError } from '../utils/debug';

/**
 * Left arrow icon.
 */
const ChevronLeft = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <polyline points="15 18 9 12 15 6" />
    </svg>
);

/**
 * Right arrow icon.
 */
const ChevronRight = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <polyline points="9 18 15 12 9 6" />
    </svg>
);

/**
 * Star rating display.
 */
const StarRating = ({ rating }) => {
    const numRating = getRating(rating);
    return (
        <div className="glimmr-carousel-rating">
            {[...Array(5)].map((_, i) => (
                <svg
                    key={i}
                    className={`glimmr-star ${i < safeRound(numRating) ? 'glimmr-star-filled' : 'glimmr-star-empty'}`}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    width="12"
                    height="12"
                >
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
            ))}
        </div>
    );
};

/**
 * Cart icon.
 */
const CartIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="9" cy="21" r="1" />
        <circle cx="20" cy="21" r="1" />
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
    </svg>
);

/**
 * Single recommendation card.
 */
const RecommendationCard = ({
    product,
    showReason,
    onClick,
    onAddToCart,
    isAddingToCart,
}) => {
    const discountPercent = calculateDiscountPercent(product.regular_price, product.sale_price);
    const hasDiscount = discountPercent > 0;

    return (
        <div className="glimmr-carousel-card">
            {/* Product image */}
            <button
                type="button"
                className="glimmr-carousel-image-btn"
                onClick={() => onClick(product)}
                aria-label={`View ${product.name}`}
            >
                {product.image ? (
                    <img src={product.image} alt={product.name} loading="lazy" />
                ) : (
                    <div className="glimmr-carousel-placeholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <polyline points="21 15 16 10 5 21" />
                        </svg>
                    </div>
                )}

                {/* Discount badge */}
                {hasDiscount && (
                    <span className="glimmr-carousel-discount">
                        -{discountPercent}%
                    </span>
                )}
            </button>

            {/* Card content */}
            <div className="glimmr-carousel-content">
                {/* Product name */}
                <h4
                    className="glimmr-carousel-name"
                    onClick={() => onClick(product)}
                >
                    {product.name}
                </h4>

                {/* Rating */}
                {product.rating !== undefined && (
                    <StarRating rating={product.rating} />
                )}

                {/* Price */}
                <div className="glimmr-carousel-price">
                    {hasDiscount ? (
                        <>
                            <span className="glimmr-price-sale">${product.sale_price}</span>
                            <span className="glimmr-price-regular">${product.regular_price}</span>
                        </>
                    ) : (
                        <span className="glimmr-price-current">
                            ${product.price || product.regular_price}
                        </span>
                    )}
                </div>

                {/* Recommendation reason */}
                {showReason && product.reason && (
                    <p className="glimmr-carousel-reason">{product.reason}</p>
                )}

                {/* Add to cart button */}
                <button
                    type="button"
                    className="glimmr-btn glimmr-btn-secondary glimmr-carousel-add"
                    onClick={() => onAddToCart(product)}
                    disabled={!product.in_stock || isAddingToCart}
                >
                    {isAddingToCart ? (
                        <span className="glimmr-spinner-small" />
                    ) : (
                        <>
                            <CartIcon />
                            {product.in_stock ? 'Add' : 'Out of Stock'}
                        </>
                    )}
                </button>
            </div>
        </div>
    );
};

/**
 * Main RecommendationCarousel component.
 */
const RecommendationCarousel = ({
    products = [],
    title = 'Recommended for You',
    config = {},
    onProductClick,
    onAddToCart,
}) => {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [addingToCart, setAddingToCart] = useState({});
    const carouselRef = useRef(null);
    const autoScrollRef = useRef(null);

    // Get config values
    const {
        carouselAutoScroll = false,
        carouselItemsVisible = 3,
        carouselShowReason = true,
    } = config.artifacts || config;

    // Calculate visible items based on container width
    const itemsVisible = Math.min(carouselItemsVisible, products.length);

    /**
     * Scroll to a specific index.
     */
    const scrollToIndex = useCallback((index) => {
        if (!carouselRef.current) return;

        const container = carouselRef.current;
        const cardWidth = container.querySelector('.glimmr-carousel-card')?.offsetWidth || 200;
        const gap = 12; // Gap between cards
        const scrollPosition = index * (cardWidth + gap);

        container.scrollTo({
            left: scrollPosition,
            behavior: 'smooth',
        });

        setCurrentIndex(index);
    }, []);

    /**
     * Navigate to next set.
     */
    const handleNext = useCallback(() => {
        const maxIndex = Math.max(0, products.length - itemsVisible);
        const nextIndex = Math.min(currentIndex + 1, maxIndex);
        scrollToIndex(nextIndex);
    }, [currentIndex, products.length, itemsVisible, scrollToIndex]);

    /**
     * Navigate to previous set.
     */
    const handlePrev = useCallback(() => {
        const prevIndex = Math.max(currentIndex - 1, 0);
        scrollToIndex(prevIndex);
    }, [currentIndex, scrollToIndex]);

    /**
     * Handle scroll event to update current index.
     */
    const handleScroll = useCallback(() => {
        if (!carouselRef.current) return;

        const container = carouselRef.current;
        const cardWidth = container.querySelector('.glimmr-carousel-card')?.offsetWidth || 200;
        const gap = 12;
        const scrollPosition = container.scrollLeft;
        const newIndex = Math.round(scrollPosition / (cardWidth + gap));

        if (newIndex !== currentIndex) {
            setCurrentIndex(newIndex);
        }
    }, [currentIndex]);

    /**
     * Handle add to cart.
     */
    const handleAddToCart = useCallback(async (product) => {
        if (addingToCart[product.id] || !onAddToCart) return;

        setAddingToCart((prev) => ({ ...prev, [product.id]: true }));
        try {
            await onAddToCart({ productId: product.id, quantity: 1 });
        } catch (error) {
            debugError('[RecommendationCarousel] Add to cart failed:', error);
        } finally {
            setAddingToCart((prev) => ({ ...prev, [product.id]: false }));
        }
    }, [addingToCart, onAddToCart]);

    /**
     * Auto-scroll functionality.
     */
    useEffect(() => {
        if (carouselAutoScroll && products.length > itemsVisible) {
            autoScrollRef.current = setInterval(() => {
                const maxIndex = products.length - itemsVisible;
                const nextIndex = currentIndex >= maxIndex ? 0 : currentIndex + 1;
                scrollToIndex(nextIndex);
            }, 5000);

            return () => clearInterval(autoScrollRef.current);
        }
    }, [carouselAutoScroll, currentIndex, products.length, itemsVisible, scrollToIndex]);

    /**
     * Pause auto-scroll on hover.
     */
    const handleMouseEnter = useCallback(() => {
        if (autoScrollRef.current) {
            clearInterval(autoScrollRef.current);
        }
    }, []);

    const handleMouseLeave = useCallback(() => {
        if (carouselAutoScroll && products.length > itemsVisible) {
            autoScrollRef.current = setInterval(() => {
                const maxIndex = products.length - itemsVisible;
                const nextIndex = currentIndex >= maxIndex ? 0 : currentIndex + 1;
                scrollToIndex(nextIndex);
            }, 5000);
        }
    }, [carouselAutoScroll, currentIndex, products.length, itemsVisible, scrollToIndex]);

    if (!products || products.length === 0) {
        return null;
    }

    const showNavigation = products.length > itemsVisible;
    const canGoPrev = currentIndex > 0;
    const canGoNext = currentIndex < products.length - itemsVisible;

    return (
        <div
            className="glimmr-carousel-wrapper"
            onMouseEnter={handleMouseEnter}
            onMouseLeave={handleMouseLeave}
        >
            {/* Header */}
            <div className="glimmr-carousel-header">
                <h3 className="glimmr-carousel-title">{title}</h3>
                {showNavigation && (
                    <div className="glimmr-carousel-nav">
                        <button
                            type="button"
                            className="glimmr-carousel-arrow glimmr-carousel-arrow-prev"
                            onClick={handlePrev}
                            disabled={!canGoPrev}
                            aria-label="Previous recommendations"
                        >
                            <ChevronLeft />
                        </button>
                        <button
                            type="button"
                            className="glimmr-carousel-arrow glimmr-carousel-arrow-next"
                            onClick={handleNext}
                            disabled={!canGoNext}
                            aria-label="Next recommendations"
                        >
                            <ChevronRight />
                        </button>
                    </div>
                )}
            </div>

            {/* Carousel container */}
            <div
                ref={carouselRef}
                className="glimmr-carousel"
                onScroll={handleScroll}
                style={{
                    '--carousel-items': itemsVisible,
                }}
            >
                {products.map((product) => (
                    <RecommendationCard
                        key={product.id}
                        product={product}
                        showReason={carouselShowReason}
                        onClick={onProductClick}
                        onAddToCart={handleAddToCart}
                        isAddingToCart={addingToCart[product.id]}
                    />
                ))}
            </div>

            {/* Dot indicators */}
            {showNavigation && (
                <div className="glimmr-carousel-dots">
                    {Array.from({ length: products.length - itemsVisible + 1 }).map((_, index) => (
                        <button
                            key={index}
                            type="button"
                            className={`glimmr-carousel-dot ${index === currentIndex ? 'is-active' : ''}`}
                            onClick={() => scrollToIndex(index)}
                            aria-label={`Go to page ${index + 1}`}
                        />
                    ))}
                </div>
            )}
        </div>
    );
};

export default RecommendationCarousel;
