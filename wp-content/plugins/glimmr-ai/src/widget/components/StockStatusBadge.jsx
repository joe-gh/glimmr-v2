/**
 * StockStatusBadge - Stock Status Display Component
 *
 * Simple badge for displaying product stock status
 * with quantity indicators and backorder support.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';

/**
 * Check icon for in stock.
 */
const CheckIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <polyline points="20 6 9 17 4 12" />
    </svg>
);

/**
 * X icon for out of stock.
 */
const XIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

/**
 * Clock icon for backorder.
 */
const ClockIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="10" />
        <polyline points="12 6 12 12 16 14" />
    </svg>
);

/**
 * Alert icon for low stock.
 */
const AlertIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        <line x1="12" y1="9" x2="12" y2="13" />
        <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
);

/**
 * Get stock status configuration.
 */
const getStockConfig = (product) => {
    // Out of stock
    if (!product.in_stock || product.stock_status === 'outofstock') {
        return {
            status: 'out-of-stock',
            text: 'Out of Stock',
            icon: XIcon,
            showQuantity: false,
        };
    }

    // On backorder
    if (product.stock_status === 'onbackorder') {
        return {
            status: 'backorder',
            text: product.backorder_date
                ? `Available ${product.backorder_date}`
                : 'Available on Backorder',
            icon: ClockIcon,
            showQuantity: false,
        };
    }

    // Low stock (5 or fewer)
    if (product.stock_quantity !== undefined && product.stock_quantity <= 5) {
        return {
            status: 'low-stock',
            text: `Only ${product.stock_quantity} left`,
            icon: AlertIcon,
            showQuantity: true,
            quantity: product.stock_quantity,
        };
    }

    // In stock with quantity
    if (product.stock_quantity !== undefined && product.manage_stock) {
        return {
            status: 'in-stock',
            text: 'In Stock',
            icon: CheckIcon,
            showQuantity: true,
            quantity: product.stock_quantity,
        };
    }

    // In stock (default)
    return {
        status: 'in-stock',
        text: 'In Stock',
        icon: CheckIcon,
        showQuantity: false,
    };
};

/**
 * Compact stock badge (inline).
 */
const CompactBadge = ({ stockConfig }) => {
    const Icon = stockConfig.icon;

    return (
        <span className={`glimmr-stock-badge glimmr-stock-${stockConfig.status}`}>
            <Icon />
            <span className="glimmr-stock-text">{stockConfig.text}</span>
        </span>
    );
};

/**
 * Detailed stock display (for product detail).
 */
const DetailedStock = ({ stockConfig, product }) => {
    const Icon = stockConfig.icon;

    return (
        <div className={`glimmr-stock-detailed glimmr-stock-${stockConfig.status}`}>
            <div className="glimmr-stock-header">
                <Icon />
                <span className="glimmr-stock-label">{stockConfig.text}</span>
            </div>

            {/* Stock quantity bar for low stock */}
            {stockConfig.status === 'low-stock' && stockConfig.quantity <= 10 && (
                <div className="glimmr-stock-bar-container">
                    <div
                        className="glimmr-stock-bar"
                        style={{ width: `${(stockConfig.quantity / 10) * 100}%` }}
                    />
                </div>
            )}

            {/* Additional info */}
            {stockConfig.status === 'backorder' && product.backorder_info && (
                <p className="glimmr-stock-info">{product.backorder_info}</p>
            )}

            {stockConfig.status === 'out-of-stock' && product.restock_date && (
                <p className="glimmr-stock-info">
                    Expected back in stock: {product.restock_date}
                </p>
            )}
        </div>
    );
};

/**
 * Main StockStatusBadge component.
 */
const StockStatusBadge = ({
    product,
    variant = 'compact', // 'compact' | 'detailed'
    className = '',
}) => {
    if (!product) return null;

    const stockConfig = getStockConfig(product);

    if (variant === 'detailed') {
        return (
            <div className={`glimmr-stock-wrapper ${className}`}>
                <DetailedStock stockConfig={stockConfig} product={product} />
            </div>
        );
    }

    return (
        <div className={`glimmr-stock-wrapper ${className}`}>
            <CompactBadge stockConfig={stockConfig} />
        </div>
    );
};

/**
 * Multi-product stock check result display.
 */
export const StockCheckResult = ({
    products = [],
    title = 'Stock Availability',
}) => {
    if (!products || products.length === 0) {
        return null;
    }

    return (
        <div className="glimmr-stock-check">
            <h4 className="glimmr-stock-check-title">{title}</h4>
            <ul className="glimmr-stock-check-list">
                {products.map((product) => {
                    const stockConfig = getStockConfig(product);
                    const Icon = stockConfig.icon;

                    return (
                        <li
                            key={product.id}
                            className={`glimmr-stock-check-item glimmr-stock-${stockConfig.status}`}
                        >
                            {product.image && (
                                <img
                                    src={product.image}
                                    alt={product.name}
                                    className="glimmr-stock-check-image"
                                />
                            )}
                            <div className="glimmr-stock-check-info">
                                <span className="glimmr-stock-check-name">{product.name}</span>
                                <span className="glimmr-stock-check-status">
                                    <Icon />
                                    {stockConfig.text}
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
};

export default StockStatusBadge;
