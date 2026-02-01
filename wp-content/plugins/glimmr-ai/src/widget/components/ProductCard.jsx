/**
 * ProductCard - Product Display Component
 *
 * Displays a product within the chat with image, price, and add-to-cart.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useCallback } from 'preact/hooks';
import { getRating, safeRound } from '../utils/numbers';
import { debugError } from '../utils/debug';

/**
 * Cart icon
 */
const CartIcon = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="9" cy="21" r="1" />
        <circle cx="20" cy="21" r="1" />
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
    </svg>
);

/**
 * Star icon for ratings
 */
const StarIcon = ({ filled }) => (
    <svg
        width="12"
        height="12"
        viewBox="0 0 24 24"
        fill={filled ? 'currentColor' : 'none'}
        stroke="currentColor"
        strokeWidth="2"
    >
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
    </svg>
);

/**
 * Render star rating.
 */
const StarRating = ({ rating }) => {
    const stars = [];
    const numRating = getRating(rating);
    const roundedRating = safeRound(numRating * 2) / 2;

    for (let i = 1; i <= 5; i++) {
        stars.push(
            <StarIcon key={i} filled={i <= roundedRating} />
        );
    }

    return <div className="glimmr-product-rating">{stars}</div>;
};

/**
 * ProductCard Component
 */
const ProductCard = ({ product }) => {
    const [isAdding, setIsAdding] = useState(false);
    const [added, setAdded] = useState(false);

    /**
     * Handle add to cart.
     */
    const handleAddToCart = useCallback(async () => {
        if (isAdding || added) return;

        setIsAdding(true);

        try {
            const response = await fetch('/wp-json/glimmr-ai/v1/cart/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.glimmrAIWidget?.nonce,
                },
                body: JSON.stringify({
                    product_id: product.id,
                    quantity: 1,
                    variation_id: product.variation_id || 0,
                }),
            });

            if (response.ok) {
                setAdded(true);
                // Reset after 2 seconds
                setTimeout(() => setAdded(false), 2000);

                // Trigger cart update event
                window.dispatchEvent(new CustomEvent('glimmr-cart-updated'));
            }
        } catch (err) {
            debugError('[ProductCard] Add to cart error:', err);
        } finally {
            setIsAdding(false);
        }
    }, [product, isAdding, added]);

    return (
        <div className="glimmr-product-card">
            {/* Product image */}
            {product.image && (
                <a
                    href={product.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="glimmr-product-image"
                >
                    <img src={product.image} alt={product.name} loading="lazy" />
                    {product.on_sale && (
                        <span className="glimmr-product-badge">Sale</span>
                    )}
                </a>
            )}

            {/* Product info */}
            <div className="glimmr-product-info">
                <a
                    href={product.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="glimmr-product-name"
                >
                    {product.name}
                </a>

                {/* Rating */}
                {product.rating > 0 && (
                    <div className="glimmr-product-rating-wrap">
                        <StarRating rating={product.rating} />
                        {product.review_count > 0 && (
                            <span className="glimmr-product-reviews">
                                ({product.review_count})
                            </span>
                        )}
                    </div>
                )}

                {/* Price */}
                <div className="glimmr-product-price">
                    {product.on_sale && product.regular_price && (
                        <span className="glimmr-product-regular-price">
                            {product.regular_price}
                        </span>
                    )}
                    <span className="glimmr-product-current-price">
                        {product.price}
                    </span>
                </div>

                {/* Stock status */}
                <div className={`glimmr-product-stock ${product.in_stock ? 'is-instock' : 'is-outofstock'}`}>
                    {product.in_stock ? 'In Stock' : 'Out of Stock'}
                </div>

                {/* Add to cart button */}
                {product.in_stock && product.purchasable && (
                    <button
                        className={`glimmr-product-add-btn ${added ? 'is-added' : ''}`}
                        onClick={handleAddToCart}
                        disabled={isAdding}
                    >
                        {isAdding ? (
                            <span className="glimmr-loading-spinner" />
                        ) : added ? (
                            'Added!'
                        ) : (
                            <>
                                <CartIcon /> Add to Cart
                            </>
                        )}
                    </button>
                )}
            </div>
        </div>
    );
};

export default ProductCard;
