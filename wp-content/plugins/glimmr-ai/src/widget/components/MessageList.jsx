/**
 * MessageList - Message Display Component
 *
 * Displays the list of chat messages with support for
 * products, cart previews, and other rich content artifacts.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useEffect, useRef, useState, useCallback } from 'preact/hooks';
import DOMPurify from 'dompurify';

// Base components
import ProductCard from './ProductCard';
import CartPreview from './CartPreview';
import TypingIndicator from './TypingIndicator';

// Rich artifact components
import ProductSearchGrid from './ProductSearchGrid';
import ProductDetailModal from './ProductDetailModal';

// API utilities
import { getProduct } from '../utils/storeApi';
import { debug, debugError, debugWarn } from '../utils/debug';
import ProductComparisonTable, { ComparisonTrigger } from './ProductComparisonTable';
import OrderStatusCard from './OrderStatusCard';
import OrderHistoryList from './OrderHistoryList';
import CouponCard, { CouponList } from './CouponCard';
import RecommendationCarousel from './RecommendationCarousel';
import StockStatusBadge, { StockCheckResult } from './StockStatusBadge';
import AccountSummaryCard from './AccountSummaryCard';
import SiteKnowledgeResponse from './SiteKnowledgeResponse';
import CheckoutCTA from './CheckoutCTA';
import CartActionResult from './CartActionResult';

/**
 * Flag icon
 */
const FlagIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z" />
        <line x1="4" y1="22" x2="4" y2="15" />
    </svg>
);

/**
 * Format timestamp for display.
 */
const formatTime = (timestamp) => {
    if (!timestamp) return '';
    const date = new Date(timestamp);
    // Check for invalid date
    if (isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

/**
 * Check if content contains HTML tags.
 */
const containsHtml = (content) => {
    return /<[a-z][\s\S]*>/i.test(content);
};

// S14: XSS Prevention - Content sanitized via DOMPurify
const ALLOWED_TAGS = ['strong', 'em', 'code', 'a', 'br', 'p', 'ul', 'ol', 'li', 'span'];
const ALLOWED_ATTR = ['href', 'target', 'rel', 'class'];

/**
 * Parse and render message content.
 * Supports both HTML content and plain text with links.
 * S14: XSS Prevention - All HTML sanitized via DOMPurify before rendering
 */
const renderContent = (content, isHtml = false) => {
    if (!content) return null;

    // If content contains HTML, sanitize and render it
    if (isHtml || containsHtml(content)) {
        const safeContent = DOMPurify.sanitize(content, {
            ALLOWED_TAGS,
            ALLOWED_ATTR,
        });
        return (
            <span dangerouslySetInnerHTML={{ __html: safeContent }} />
        );
    }

    // Plain text: handle links and line breaks
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    const parts = content.split(urlRegex);

    return parts.map((part, i) => {
        if (part.match(urlRegex)) {
            return (
                <a
                    key={i}
                    href={part}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="glimmr-message-link"
                >
                    {part}
                </a>
            );
        }
        // Handle line breaks
        return part.split('\n').map((line, j) => (
            <span key={`${i}-${j}`}>
                {line}
                {j < part.split('\n').length - 1 && <br />}
            </span>
        ));
    });
};

/**
 * Artifact renderer - renders appropriate component based on artifact type.
 */
const ArtifactRenderer = ({
    artifact,
    config,
    isNew = false, // Whether this is from a fresh response (not history)
    onProductClick,
    onAddToCart,
    onUpdateCart,
    onRemoveFromCart,
    onApplyCoupon,
    onRemoveCoupon,
    onViewOrder,
}) => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedProduct, setSelectedProduct] = useState(null);
    const [isLoadingProduct, setIsLoadingProduct] = useState(false);
    // Auto-open comparison overlay only for NEW comparisons (not from history)
    const isComparisonArtifact = artifact?.type === 'product_comparison' || artifact?.type === 'product_compare';
    const [isComparisonOpen, setIsComparisonOpen] = useState(isComparisonArtifact && isNew);

    /**
     * Handle product click to open detail modal.
     * Always fetches fresh product details from Store API to ensure current stock/price.
     */
    const handleProductClick = useCallback(async (product) => {
        // Immediately show modal with cached data while loading fresh details
        setSelectedProduct(product);
        setIsModalOpen(true);

        if (onProductClick) {
            onProductClick(product);
        }

        // Always fetch fresh product details from Store API.
        // This ensures current stock levels, prices, and availability.
        // Cached conversation data may be stale.
        try {
            setIsLoadingProduct(true);
            debug('[ArtifactRenderer] Fetching fresh product details for:', product.id);
            const fullProduct = await getProduct(product.id);

            // Replace with fresh data (preserve original URL as fallback)
            setSelectedProduct(prev => ({
                ...fullProduct,
                // Keep original URL if Store API didn't return one
                url: fullProduct.url || prev?.url,
            }));
            debug('[ArtifactRenderer] Fresh product details loaded:', {
                id: fullProduct.id,
                hasDescription: !!fullProduct.description,
                hasAttributes: !!fullProduct.attributes,
                variationCount: fullProduct.variations?.length || 0,
            });
        } catch (error) {
            debugError('[ArtifactRenderer] Failed to fetch fresh product details:', error);
            // Keep showing the cached product data - modal still works
        } finally {
            setIsLoadingProduct(false);
        }
    }, [onProductClick]);

    // Auto-open modal for details mode when auto_open_modal flag is set
    useEffect(() => {
        if (!isNew || !artifact?.data) return;

        // Check for auto_open_modal flag in details mode response
        const queryResponse = artifact.data?.products || artifact.data || {};
        const shouldAutoOpen = queryResponse.auto_open_modal === true;
        const product = queryResponse.product;

        if (shouldAutoOpen && product && queryResponse.mode === 'details') {
            // Small delay to ensure component is fully mounted
            const timer = setTimeout(() => {
                setSelectedProduct(product);
                setIsModalOpen(true);
            }, 100);
            return () => clearTimeout(timer);
        }
    }, [isNew, artifact]);

    // Auto-open URL for ui_action (e.g., tracking link, page navigation)
    useEffect(() => {
        if (!isNew || !artifact?.data?.ui_action) return;

        const uiAction = artifact.data.ui_action;
        if (uiAction.action === 'open_url' && uiAction.url) {
            // Small delay for user to see the response first
            const timer = setTimeout(() => {
                const target = uiAction.target || '_blank';
                debug('[ArtifactRenderer] Opening URL:', uiAction.url, 'target:', target);

                if (target === '_self') {
                    // Same-tab navigation - use location.href for cleaner UX
                    window.location.href = uiAction.url;
                } else {
                    // New tab - use window.open
                    window.open(uiAction.url, target, 'noopener,noreferrer');
                }
            }, 1000);
            return () => clearTimeout(timer);
        }
    }, [isNew, artifact]);

    if (!artifact || !artifact.type) return null;

    switch (artifact.type) {
        // Product search results (new unified query_products tool + legacy)
        case 'query_products':
        case 'product_search':
        case 'product_lookup': {
            // Handle different modes from the unified query_products tool
            const queryResponse = artifact.data?.products || artifact.data || {};
            const queryMode = queryResponse.mode;

            let productsArray = [];
            let totalCount = artifact.data?.total || artifact.data?.count || 0;

            if (queryMode === 'details' && queryResponse.product) {
                // Single product details mode - wrap in array
                productsArray = [queryResponse.product];
                totalCount = 1;
            } else if (queryMode === 'search' && Array.isArray(queryResponse.products)) {
                // Search mode - products is the array
                productsArray = queryResponse.products;
                totalCount = queryResponse.count || queryResponse.total_found || productsArray.length;
            } else if (queryMode === 'stock_check' && queryResponse.product) {
                // Stock check mode - single product
                productsArray = [queryResponse.product];
                totalCount = 1;
            } else if (Array.isArray(queryResponse)) {
                // Legacy format - products is already an array
                productsArray = queryResponse;
            } else if (Array.isArray(artifact.data?.products)) {
                // Legacy format - data.products is array
                productsArray = artifact.data.products;
            }

            return (
                <Fragment>
                    <ProductSearchGrid
                        products={productsArray}
                        config={config}
                        onProductClick={handleProductClick}
                        resultCount={totalCount}
                        searchQuery={artifact.data?.query}
                        appliedFilters={artifact.data?.filters}
                    />
                    <ProductDetailModal
                        product={selectedProduct}
                        config={config}
                        isOpen={isModalOpen}
                        isLoading={isLoadingProduct}
                        onClose={() => setIsModalOpen(false)}
                        onAddToCart={onAddToCart}
                    />
                </Fragment>
            );
        }

        // Product details (single product view)
        case 'product_details':
            const detailProduct = artifact.data?.product || (artifact.data?.products?.[0]);
            return detailProduct ? (
                <Fragment>
                    <ProductSearchGrid
                        products={[detailProduct]}
                        config={config}
                        onProductClick={handleProductClick}
                        resultCount={1}
                    />
                    <ProductDetailModal
                        product={selectedProduct || detailProduct}
                        config={config}
                        isOpen={isModalOpen || true}
                        isLoading={isLoadingProduct}
                        onClose={() => setIsModalOpen(false)}
                        onAddToCart={onAddToCart}
                    />
                </Fragment>
            ) : null;

        // Product comparison
        case 'product_comparison':
        case 'product_compare': {
            // Handle both new query_products format and legacy format
            const compareResponse = artifact.data?.products || artifact.data || {};
            let compareProducts = [];

            if (compareResponse.mode === 'compare' && Array.isArray(compareResponse.products)) {
                // New format from query_products with mode=compare
                compareProducts = compareResponse.products;
            } else if (Array.isArray(compareResponse)) {
                // Legacy format - products is already an array
                compareProducts = compareResponse;
            } else if (Array.isArray(artifact.data?.products)) {
                // Legacy format - data.products is array
                compareProducts = artifact.data.products;
            }

            // Limit count to what will actually display (comparisonMaxProducts defaults to 4)
            const maxProducts = config?.artifacts?.comparisonMaxProducts || 4;
            const actualProductCount = Math.min(compareProducts.length, maxProducts);
            return (
                <Fragment>
                    <ComparisonTrigger
                        productCount={actualProductCount}
                        onClick={() => setIsComparisonOpen(true)}
                    />
                    <ProductComparisonTable
                        products={compareProducts}
                        config={config}
                        isOpen={isComparisonOpen}
                        onClose={() => setIsComparisonOpen(false)}
                        onProductClick={handleProductClick}
                    />
                    <ProductDetailModal
                        product={selectedProduct}
                        config={config}
                        isOpen={isModalOpen}
                        isLoading={isLoadingProduct}
                        onClose={() => setIsModalOpen(false)}
                        onAddToCart={onAddToCart}
                    />
                </Fragment>
            );
        }

        // Order status
        case 'order_status':
            return (
                <OrderStatusCard
                    order={artifact.data?.order}
                    config={config}
                />
            );

        // Order history
        case 'order_history':
            return (
                <OrderHistoryList
                    orders={artifact.data?.orders || []}
                    config={config}
                    onViewOrder={onViewOrder}
                />
            );

        // Cart display
        case 'cart':
        case 'view_cart':
            return (
                <CartPreview
                    cart={artifact.data?.cart || artifact.data}
                    config={config}
                    onUpdateQuantity={onUpdateCart}
                    onRemoveItem={onRemoveFromCart}
                    onApplyCoupon={onApplyCoupon}
                    onRemoveCoupon={onRemoveCoupon}
                />
            );

        // Checkout CTA
        case 'checkout':
        case 'checkout_link':
            return (
                <CheckoutCTA
                    cart={artifact.data?.cart}
                    checkoutUrl={artifact.data?.checkout_url}
                    config={config}
                />
            );

        // Coupon display
        case 'coupon':
        case 'coupon_lookup':
            if (Array.isArray(artifact.data?.coupons)) {
                return (
                    <CouponList
                        coupons={artifact.data.coupons}
                        config={config}
                        onApply={onApplyCoupon}
                    />
                );
            }
            return (
                <CouponCard
                    coupon={artifact.data?.coupon || artifact.data}
                    config={config}
                    onApply={onApplyCoupon}
                />
            );

        // Product recommendations
        case 'recommendations':
            return (
                <Fragment>
                    <RecommendationCarousel
                        products={artifact.data?.products || []}
                        title={artifact.data?.title || 'Recommended for You'}
                        config={config}
                        onProductClick={handleProductClick}
                        onAddToCart={onAddToCart}
                    />
                    <ProductDetailModal
                        product={selectedProduct}
                        config={config}
                        isOpen={isModalOpen}
                        isLoading={isLoadingProduct}
                        onClose={() => setIsModalOpen(false)}
                        onAddToCart={onAddToCart}
                    />
                </Fragment>
            );

        // Stock check
        case 'stock_check':
            if (Array.isArray(artifact.data?.products)) {
                return (
                    <StockCheckResult
                        products={artifact.data.products}
                        title={artifact.data?.title}
                    />
                );
            }
            return (
                <StockStatusBadge
                    product={artifact.data?.product || artifact.data}
                    variant="detailed"
                />
            );

        // Account info
        case 'account_info':
            return (
                <AccountSummaryCard
                    account={artifact.data?.account || artifact.data}
                    config={config}
                />
            );

        // Product reviews
        case 'product_reviews':
        case 'get_reviews': {
            const reviewsData = artifact.data || {};
            const reviews = reviewsData.reviews || [];
            const breakdown = reviewsData.rating_breakdown?.ratings || {};
            const totalReviews = reviewsData.total_reviews || reviewsData.rating_breakdown?.total || 0;
            const avgRating = parseFloat(reviewsData.average_rating) || 0;

            if (reviews.length === 0) {
                return (
                    <div className="glimmr-reviews-artifact glimmr-reviews-empty">
                        <p>{reviewsData.product_name ? `${reviewsData.product_name} has no reviews yet.` : 'No reviews found.'}</p>
                    </div>
                );
            }

            return (
                <div className="glimmr-reviews-artifact">
                    <div className="glimmr-reviews-header">
                        <h4 className="glimmr-reviews-product-name">{reviewsData.product_name}</h4>
                        <div className="glimmr-reviews-summary">
                            <span className="glimmr-reviews-avg-rating">
                                <span className="glimmr-star filled" aria-hidden="true">★</span>
                                <span className="glimmr-rating-value">{avgRating.toFixed(1)}</span>
                            </span>
                            <span className="glimmr-reviews-count">({totalReviews} review{totalReviews !== 1 ? 's' : ''})</span>
                        </div>
                    </div>

                    {/* Rating breakdown bars */}
                    {totalReviews > 0 && (
                        <div className="glimmr-rating-breakdown">
                            {[5, 4, 3, 2, 1].map((star) => {
                                const ratingData = breakdown[star] || { count: 0, percentage: 0 };
                                return (
                                    <div key={star} className="glimmr-rating-bar-row">
                                        <span className="glimmr-rating-label">{star} <span className="glimmr-star filled" aria-hidden="true">★</span></span>
                                        <div className="glimmr-rating-bar-track">
                                            <div
                                                className="glimmr-rating-bar-fill"
                                                style={{ width: `${ratingData.percentage}%` }}
                                            />
                                        </div>
                                        <span className="glimmr-rating-count">{ratingData.count}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Reviews list */}
                    <div className="glimmr-reviews-list">
                        {reviews.map((review) => (
                            <div key={review.id} className="glimmr-review-item">
                                <div className="glimmr-review-header">
                                    <span className="glimmr-review-author">{review.author}</span>
                                    {review.verified && (
                                        <span className="glimmr-verified-badge">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                                            </svg>
                                            {review.verified_text || 'Verified'}
                                        </span>
                                    )}
                                </div>
                                <div className="glimmr-review-rating">
                                    {[1, 2, 3, 4, 5].map((star) => (
                                        <span
                                            key={star}
                                            className={`glimmr-star ${star <= review.rating ? 'filled' : 'empty'}`}
                                            aria-hidden="true"
                                        >
                                            {star <= review.rating ? '★' : '☆'}
                                        </span>
                                    ))}
                                </div>
                                <p className="glimmr-review-content">{review.content}</p>
                                <span className="glimmr-review-date">{review.date_relative}</span>
                            </div>
                        ))}
                    </div>
                </div>
            );
        }

        // Site knowledge
        case 'site_knowledge':
        case 'knowledge':
            return (
                <SiteKnowledgeResponse
                    content={artifact.data?.content || artifact.data?.answer}
                    sources={artifact.data?.sources || []}
                    config={config}
                    category={artifact.data?.category}
                    confidence={artifact.data?.confidence}
                />
            );

        // Cart action (executed via frontend Store API)
        case 'cart_action':
            return (
                <CartActionResult
                    data={artifact.data || {}}
                />
            );

        // Navigation indicator (from navigate_to_page tool)
        case 'navigating':
        case 'navigate':
            return (
                <div className="glimmr-navigation-indicator">
                    <div className="glimmr-navigation-spinner" aria-hidden="true" />
                    <span>{artifact.data?.suggestion || 'Opening page...'}</span>
                </div>
            );

        // SQL query results (from sql_readonly tool)
        case 'sql_results':
            const rows = artifact.data?.rows || [];
            const rowCount = artifact.data?.row_count || rows.length;
            if (rows.length === 0) {
                return (
                    <div className="glimmr-sql-results glimmr-sql-empty">
                        <p>No results found.</p>
                    </div>
                );
            }
            // Get column headers from first row
            const columns = Object.keys(rows[0] || {});
            return (
                <div className="glimmr-sql-results">
                    <div className="glimmr-sql-header">
                        <span className="glimmr-sql-count">{rowCount} row{rowCount !== 1 ? 's' : ''}</span>
                    </div>
                    <div className="glimmr-sql-table-wrapper">
                        <table className="glimmr-sql-table">
                            <thead>
                                <tr>
                                    {columns.map((col) => (
                                        <th key={col}>{col.replace(/_/g, ' ')}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.slice(0, 20).map((row, idx) => (
                                    <tr key={idx}>
                                        {columns.map((col) => (
                                            <td key={col}>
                                                {row[col] !== null ? String(row[col]) : '-'}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {rows.length > 20 && (
                        <div className="glimmr-sql-footer">
                            Showing first 20 of {rowCount} rows
                        </div>
                    )}
                </div>
            );

        // Legacy product display (backwards compatibility)
        case 'products':
            return (
                <div className="glimmr-message-products">
                    {(artifact.data?.products || []).map((product) => (
                        <ProductCard
                            key={product.id}
                            product={product}
                            onClick={() => handleProductClick(product)}
                        />
                    ))}
                </div>
            );

        default:
            // Unknown artifact type - try to display as JSON for debugging
            if (process.env.NODE_ENV === 'development') {
                debugWarn('[ArtifactRenderer] Unknown artifact type:', artifact.type, artifact);
            }
            return null;
    }
};

/**
 * Single message component.
 */
const Message = ({
    message,
    config,
    onFlagMessage,
    onProductClick,
    onAddToCart,
    onUpdateCart,
    onRemoveFromCart,
    onApplyCoupon,
    onRemoveCoupon,
    onViewOrder,
}) => {
    const [showFlagMenu, setShowFlagMenu] = useState(false);
    const isUser = message.role === 'user';
    const isError = message.isError;

    const handleFlag = (issueType) => {
        onFlagMessage(message.id, issueType, '');
        setShowFlagMenu(false);
    };

    // Check for artifacts in the message
    const hasArtifacts = message.artifacts && message.artifacts.length > 0;

    // Filter out stale artifacts when there's a cart_action in the same message.
    // When cart-modifying tools run (add_to_cart, update_cart, apply_coupon, etc.),
    // the AI may also call view_cart or checkout_link to show current state,
    // but that state would be pre-action (stale). The cart_action result shows
    // the correct post-action state, so we hide stale artifacts.
    const filteredArtifacts = hasArtifacts
        ? (() => {
            const hasCartAction = message.artifacts.some(
                (a) => a.type === 'cart_action' || a.data?.status === 'cart_action'
            );
            if (hasCartAction) {
                // Filter out artifacts that show cart state - they'd be stale after action executes
                const staleArtifactTypes = [
                    'cart',
                    'view_cart',
                    'checkout',
                    'checkout_link',
                ];
                return message.artifacts.filter(
                    (a) => !staleArtifactTypes.includes(a.type)
                );
            }
            return message.artifacts;
        })()
        : [];

    // Only show artifacts after streaming is complete to ensure proper ordering
    // (text first, then artifacts)
    const shouldShowArtifacts = filteredArtifacts.length > 0 && !message.isStreaming;

    // Legacy support: Check for products and cart at message level
    const hasLegacyProducts = message.products && message.products.length > 0;
    const hasLegacyCart = message.cart;

    return (
        <div
            className={`glimmr-message ${isUser ? 'is-user' : 'is-assistant'} ${isError ? 'is-error' : ''} ${message.isStreaming ? 'is-streaming' : ''}`}
        >
            {/* Avatar for assistant */}
            {!isUser && config.avatarUrl && (
                <img src={config.avatarUrl} alt="" className="glimmr-message-avatar" />
            )}

            <div className="glimmr-message-content">
                {/* Message text */}
                {message.content && (
                    <div className="glimmr-message-bubble">
                        {renderContent(message.content)}
                    </div>
                )}

                {/* Render artifacts only after streaming is complete */}
                {shouldShowArtifacts && (
                    <div className="glimmr-message-artifacts">
                        {filteredArtifacts.map((artifact, index) => (
                            <ArtifactRenderer
                                key={`${artifact.type}-${index}`}
                                artifact={artifact}
                                config={config}
                                isNew={message.isNew}
                                onProductClick={onProductClick}
                                onAddToCart={onAddToCart}
                                onUpdateCart={onUpdateCart}
                                onRemoveFromCart={onRemoveFromCart}
                                onApplyCoupon={onApplyCoupon}
                                onRemoveCoupon={onRemoveCoupon}
                                onViewOrder={onViewOrder}
                            />
                        ))}
                    </div>
                )}

                {/* Legacy: Products (if any) */}
                {hasLegacyProducts && !hasArtifacts && (
                    <div className="glimmr-message-products">
                        {message.products.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                )}

                {/* Legacy: Cart preview (if any) */}
                {hasLegacyCart && !hasArtifacts && (
                    <CartPreview
                        cart={message.cart}
                        config={config}
                        onUpdateQuantity={onUpdateCart}
                        onRemoveItem={onRemoveFromCart}
                        onApplyCoupon={onApplyCoupon}
                        onRemoveCoupon={onRemoveCoupon}
                    />
                )}

                {/* Message footer */}
                <div className="glimmr-message-footer">
                    <span className="glimmr-message-time">
                        {formatTime(message.timestamp)}
                    </span>

                    {/* Flag button for assistant messages */}
                    {!isUser && !isError && (
                        <div className="glimmr-flag-wrapper">
                            <button
                                className="glimmr-flag-btn"
                                onClick={() => setShowFlagMenu(!showFlagMenu)}
                                aria-label="Report issue with this response"
                            >
                                <FlagIcon />
                            </button>

                            {showFlagMenu && (
                                <div className="glimmr-flag-menu">
                                    <button onClick={() => handleFlag('wrong_answer')}>
                                        Wrong information
                                    </button>
                                    <button onClick={() => handleFlag('unhelpful')}>
                                        Not helpful
                                    </button>
                                    <button onClick={() => handleFlag('inappropriate')}>
                                        Inappropriate
                                    </button>
                                    <button onClick={() => handleFlag('other')}>
                                        Other issue
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

/**
 * MessageList Component
 */
const MessageList = ({
    messages,
    isTyping,
    loadingStatus,
    config,
    onFlagMessage,
    onProductClick,
    onAddToCart,
    onUpdateCart,
    onRemoveFromCart,
    onApplyCoupon,
    onRemoveCoupon,
    onViewOrder,
}) => {
    const listRef = useRef(null);

    // Auto-scroll to bottom when new messages arrive
    useEffect(() => {
        if (listRef.current) {
            listRef.current.scrollTop = listRef.current.scrollHeight;
        }
    }, [messages, isTyping, loadingStatus]);

    return (
        <div className="glimmr-messages" ref={listRef} role="log" aria-live="polite">
            {messages.map((message) => (
                <Message
                    key={message.id}
                    message={message}
                    config={config}
                    onFlagMessage={onFlagMessage}
                    onProductClick={onProductClick}
                    onAddToCart={onAddToCart}
                    onUpdateCart={onUpdateCart}
                    onRemoveFromCart={onRemoveFromCart}
                    onApplyCoupon={onApplyCoupon}
                    onRemoveCoupon={onRemoveCoupon}
                    onViewOrder={onViewOrder}
                />
            ))}

            {isTyping && <TypingIndicator statusMessage={loadingStatus} />}
        </div>
    );
};

export default MessageList;
