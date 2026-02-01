/**
 * OrderStatusCard - Order Tracking Display
 *
 * Displays order status with visual timeline, tracking info,
 * and line items.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useState } from 'preact/hooks';

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
 * Status step configuration.
 */
const STATUS_STEPS = [
    { key: 'pending', label: 'Order Placed', icon: 'receipt' },
    { key: 'processing', label: 'Processing', icon: 'cog' },
    { key: 'shipped', label: 'Shipped', icon: 'truck' },
    { key: 'delivered', label: 'Delivered', icon: 'check' },
];

/**
 * Get status index for timeline.
 */
const getStatusIndex = (status) => {
    const statusMap = {
        'pending': 0,
        'on-hold': 0,
        'processing': 1,
        'shipped': 2,
        'completed': 3,
        'delivered': 3,
        'cancelled': -1,
        'refunded': -1,
        'failed': -1,
    };
    return statusMap[status] ?? 0;
};

/**
 * Status icon component.
 */
const StatusIcon = ({ type }) => {
    const icons = {
        receipt: (
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
        ),
        cog: (
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
            </svg>
        ),
        truck: (
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="1" y="3" width="15" height="13" />
                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8" />
                <circle cx="5.5" cy="18.5" r="2.5" />
                <circle cx="18.5" cy="18.5" r="2.5" />
            </svg>
        ),
        check: (
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="20 6 9 17 4 12" />
            </svg>
        ),
    };
    return icons[type] || null;
};

/**
 * Timeline step component.
 */
const TimelineStep = ({ step, isActive, isComplete, date, isHorizontal }) => (
    <div
        className={`glimmr-timeline-step ${isComplete ? 'is-complete' : ''} ${isActive ? 'is-active' : ''}`}
    >
        <div className="glimmr-timeline-marker">
            <div className="glimmr-timeline-icon">
                <StatusIcon type={step.icon} />
            </div>
        </div>
        <div className="glimmr-timeline-content">
            <span className="glimmr-timeline-label">{step.label}</span>
            {date && <span className="glimmr-timeline-date">{formatDate(date)}</span>}
        </div>
    </div>
);

/**
 * Order item row.
 */
const OrderItem = ({ item, showThumbnails }) => (
    <div className="glimmr-order-item">
        {showThumbnails && item.image && (
            <img
                src={item.image}
                alt={item.name}
                className="glimmr-order-item-image"
            />
        )}
        <div className="glimmr-order-item-details">
            <span className="glimmr-order-item-name">{item.name}</span>
            {item.variation && (
                <span className="glimmr-order-item-variation">{item.variation}</span>
            )}
            <span className="glimmr-order-item-qty">Qty: {item.quantity}</span>
        </div>
        <span className="glimmr-order-item-price">${item.total}</span>
    </div>
);

/**
 * Main OrderStatusCard component.
 */
const OrderStatusCard = ({
    order,
    config = {},
}) => {
    const [isExpanded, setIsExpanded] = useState(false);

    // Get config values
    const {
        orderShowTimeline = true,
        orderTimelineStyle = 'horizontal',
        orderShowItems = true,
        historyShowThumbnails = true,
    } = config.artifacts || config;

    if (!order) return null;

    const statusIndex = getStatusIndex(order.status);
    const isCancelled = statusIndex === -1;

    // Build status dates from order data
    const statusDates = {
        pending: order.date_created,
        processing: order.date_processing,
        shipped: order.date_shipped,
        delivered: order.date_completed,
    };

    return (
        <div className="glimmr-order-status-card">
            {/* Order header */}
            <div className="glimmr-order-header">
                <div className="glimmr-order-info">
                    <span className="glimmr-order-number">Order #{order.number || order.id}</span>
                    <span className="glimmr-order-date">{formatDate(order.date_created)}</span>
                </div>
                <span className={`glimmr-status-badge glimmr-status-${order.status}`}>
                    {order.status_label || order.status}
                </span>
            </div>

            {/* Timeline */}
            {orderShowTimeline && !isCancelled && (
                <div className={`glimmr-order-timeline glimmr-timeline-${orderTimelineStyle}`}>
                    <div className="glimmr-timeline-track" />
                    {STATUS_STEPS.map((step, index) => (
                        <TimelineStep
                            key={step.key}
                            step={step}
                            isComplete={index < statusIndex}
                            isActive={index === statusIndex}
                            date={statusDates[step.key]}
                            isHorizontal={orderTimelineStyle === 'horizontal'}
                        />
                    ))}
                </div>
            )}

            {/* Cancelled/refunded notice */}
            {isCancelled && (
                <div className="glimmr-order-cancelled">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="15" y1="9" x2="9" y2="15" />
                        <line x1="9" y1="9" x2="15" y2="15" />
                    </svg>
                    <span>This order has been {order.status}</span>
                </div>
            )}

            {/* Tracking info */}
            {order.tracking_number && (
                <div className="glimmr-order-tracking">
                    <span className="glimmr-tracking-label">Tracking:</span>
                    {order.tracking_url ? (
                        <a
                            href={order.tracking_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="glimmr-tracking-link"
                        >
                            {order.tracking_number}
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                <polyline points="15 3 21 3 21 9" />
                                <line x1="10" y1="14" x2="21" y2="3" />
                            </svg>
                        </a>
                    ) : (
                        <span className="glimmr-tracking-number">{order.tracking_number}</span>
                    )}
                    {order.carrier && (
                        <span className="glimmr-tracking-carrier">via {order.carrier}</span>
                    )}
                </div>
            )}

            {/* Estimated delivery */}
            {order.estimated_delivery && !isCancelled && statusIndex < 3 && (
                <div className="glimmr-order-estimate">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                    </svg>
                    <span>Estimated delivery: {formatDate(order.estimated_delivery)}</span>
                </div>
            )}

            {/* Order items */}
            {orderShowItems && order.items && order.items.length > 0 && (
                <div className="glimmr-order-items">
                    <button
                        type="button"
                        className="glimmr-order-items-toggle"
                        onClick={() => setIsExpanded(!isExpanded)}
                        aria-expanded={isExpanded}
                    >
                        <span>{order.items.length} {order.items.length === 1 ? 'item' : 'items'}</span>
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            className={isExpanded ? 'is-expanded' : ''}
                        >
                            <polyline points="6 9 12 15 18 9" />
                        </svg>
                    </button>

                    {isExpanded && (
                        <div className="glimmr-order-items-list">
                            {order.items.map((item, index) => (
                                <OrderItem
                                    key={index}
                                    item={item}
                                    showThumbnails={historyShowThumbnails}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Order total */}
            <div className="glimmr-order-total">
                <span>Total:</span>
                <span className="glimmr-order-total-amount">${order.total}</span>
            </div>
        </div>
    );
};

export default OrderStatusCard;
