/**
 * OrderHistoryList - Order History Display
 *
 * Displays a compact list of past orders with expandable details.
 * Clicking an order can open full order status view.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useCallback } from 'preact/hooks';

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
 * Format price - handles already-formatted prices to avoid double currency symbols.
 */
const formatPrice = (price) => {
    if (!price && price !== 0) return '';
    // If already formatted with currency symbol, return as-is
    if (typeof price === 'string' && /^[£$€¥₹]/.test(price)) {
        return price;
    }
    // Otherwise, format as USD
    return `$${parseFloat(price).toFixed(2)}`;
};

/**
 * Status badge colors and labels.
 */
const STATUS_CONFIG = {
    'pending': { label: 'Pending', className: 'pending' },
    'on-hold': { label: 'On Hold', className: 'pending' },
    'processing': { label: 'Processing', className: 'processing' },
    'shipped': { label: 'Shipped', className: 'shipped' },
    'completed': { label: 'Completed', className: 'delivered' },
    'delivered': { label: 'Delivered', className: 'delivered' },
    'cancelled': { label: 'Cancelled', className: 'cancelled' },
    'refunded': { label: 'Refunded', className: 'cancelled' },
    'failed': { label: 'Failed', className: 'cancelled' },
};

/**
 * Chevron icon for expand/collapse.
 */
const ChevronIcon = ({ isExpanded }) => (
    <svg
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        className={`glimmr-history-chevron ${isExpanded ? 'is-expanded' : ''}`}
        aria-hidden="true"
        focusable="false"
    >
        <polyline points="6 9 12 15 18 9" />
    </svg>
);

/**
 * Package icon for order.
 */
const PackageIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="16.5" y1="9.4" x2="7.5" y2="4.21" />
        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
        <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
        <line x1="12" y1="22.08" x2="12" y2="12" />
    </svg>
);

/**
 * Single order item in the list.
 */
const OrderItem = ({ item, showThumbnails }) => (
    <div className="glimmr-history-item">
        {showThumbnails && item.image && (
            <img
                src={item.image}
                alt={item.name}
                className="glimmr-history-item-image"
            />
        )}
        <div className="glimmr-history-item-info">
            <span className="glimmr-history-item-name">{item.name}</span>
            {item.variation && (
                <span className="glimmr-history-item-variation">{item.variation}</span>
            )}
        </div>
        <div className="glimmr-history-item-meta">
            <span className="glimmr-history-item-qty">×{item.quantity}</span>
            <span className="glimmr-history-item-price">{formatPrice(item.total)}</span>
        </div>
    </div>
);

/**
 * Single order row in the history list.
 */
const OrderRow = ({
    order,
    showThumbnails,
    isExpanded,
    onToggle,
    onViewDetails,
}) => {
    const statusConfig = STATUS_CONFIG[order.status] || STATUS_CONFIG['pending'];

    return (
        <div className={`glimmr-history-order ${isExpanded ? 'is-expanded' : ''}`}>
            {/* Order header - clickable to expand */}
            <button
                type="button"
                className="glimmr-history-order-header"
                onClick={onToggle}
                aria-expanded={isExpanded}
            >
                <div className="glimmr-history-order-icon">
                    <PackageIcon />
                </div>

                <div className="glimmr-history-order-info">
                    <span className="glimmr-history-order-number">
                        Order #{order.number || order.id}
                    </span>
                    <span className="glimmr-history-order-date">
                        {formatDate(order.date_created)}
                    </span>
                </div>

                <div className="glimmr-history-order-summary">
                    <span className={`glimmr-status-badge glimmr-status-${statusConfig.className}`}>
                        {order.status_label || statusConfig.label}
                    </span>
                    <span className="glimmr-history-order-total">{formatPrice(order.total)}</span>
                </div>

                <ChevronIcon isExpanded={isExpanded} />
            </button>

            {/* Expanded details */}
            {isExpanded && (
                <div className="glimmr-history-order-details">
                    {/* Order items */}
                    {order.items && order.items.length > 0 && (
                        <div className="glimmr-history-items">
                            {order.items.map((item, index) => (
                                <OrderItem
                                    key={index}
                                    item={item}
                                    showThumbnails={showThumbnails}
                                />
                            ))}
                        </div>
                    )}

                    {/* Order meta */}
                    <div className="glimmr-history-order-meta">
                        {order.shipping_method && (
                            <div className="glimmr-history-meta-row">
                                <span>Shipping:</span>
                                <span>{order.shipping_method}</span>
                            </div>
                        )}
                        {order.payment_method && (
                            <div className="glimmr-history-meta-row">
                                <span>Payment:</span>
                                <span>{order.payment_method}</span>
                            </div>
                        )}
                        {order.tracking_number && (
                            <div className="glimmr-history-meta-row">
                                <span>Tracking:</span>
                                {order.tracking_url && /^https?:\/\//i.test(order.tracking_url) ? (
                                    <a
                                        href={order.tracking_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="glimmr-history-tracking-link"
                                    >
                                        {order.tracking_number}
                                    </a>
                                ) : (
                                    <span>{order.tracking_number}</span>
                                )}
                            </div>
                        )}
                    </div>

                    {/* View full details button */}
                    {onViewDetails && (
                        <button
                            type="button"
                            className="glimmr-btn glimmr-btn-secondary glimmr-history-view-btn"
                            onClick={() => onViewDetails(order)}
                        >
                            View Full Details
                        </button>
                    )}
                </div>
            )}
        </div>
    );
};

/**
 * Main OrderHistoryList component.
 */
const OrderHistoryList = ({
    orders = [],
    config = {},
    onViewOrder,
    onReorder,
}) => {
    const [expandedOrderId, setExpandedOrderId] = useState(null);
    const [showAll, setShowAll] = useState(false);

    // Get config values
    const {
        historyMaxDisplay = 5,
        historyShowThumbnails = true,
    } = config.artifacts || config;

    /**
     * Toggle order expansion.
     */
    const handleToggle = useCallback((orderId) => {
        setExpandedOrderId((prev) => (prev === orderId ? null : orderId));
    }, []);

    /**
     * Handle view order details.
     */
    const handleViewDetails = useCallback((order) => {
        if (onViewOrder) {
            onViewOrder(order);
        }
    }, [onViewOrder]);

    if (!orders || orders.length === 0) {
        return (
            <div className="glimmr-history-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                    <line x1="3" y1="9" x2="21" y2="9" />
                    <line x1="9" y1="21" x2="9" y2="9" />
                </svg>
                <p>No orders found</p>
            </div>
        );
    }

    // Determine orders to display
    const displayOrders = showAll ? orders : orders.slice(0, historyMaxDisplay);
    const hasMore = orders.length > historyMaxDisplay;

    return (
        <div className="glimmr-history-list">
            {/* Header */}
            <div className="glimmr-history-header">
                <h3 className="glimmr-history-title">Order History</h3>
                <span className="glimmr-history-count">
                    {orders.length} {orders.length === 1 ? 'order' : 'orders'}
                </span>
            </div>

            {/* Orders list */}
            <div className="glimmr-history-orders">
                {displayOrders.map((order) => (
                    <OrderRow
                        key={order.id}
                        order={order}
                        showThumbnails={historyShowThumbnails}
                        isExpanded={expandedOrderId === order.id}
                        onToggle={() => handleToggle(order.id)}
                        onViewDetails={onViewOrder ? handleViewDetails : null}
                    />
                ))}
            </div>

            {/* Show more/less toggle */}
            {hasMore && (
                <button
                    type="button"
                    className="glimmr-history-toggle"
                    onClick={() => setShowAll(!showAll)}
                >
                    {showAll ? (
                        <>
                            Show Less
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <polyline points="18 15 12 9 6 15" />
                            </svg>
                        </>
                    ) : (
                        <>
                            Show {orders.length - historyMaxDisplay} More
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </>
                    )}
                </button>
            )}

            {/* Quick reorder section */}
            {onReorder && orders.length > 0 && orders[0].status === 'completed' && (
                <div className="glimmr-history-reorder">
                    <button
                        type="button"
                        className="glimmr-btn glimmr-btn-secondary"
                        onClick={() => onReorder(orders[0])}
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <polyline points="23 4 23 10 17 10" />
                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                        </svg>
                        Reorder Last Purchase
                    </button>
                </div>
            )}
        </div>
    );
};

export default OrderHistoryList;
