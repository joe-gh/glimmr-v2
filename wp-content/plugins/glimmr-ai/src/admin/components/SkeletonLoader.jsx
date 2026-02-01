/**
 * Skeleton Loader Component
 *
 * Provides animated placeholder UI for loading states in the admin dashboard.
 *
 * @package Glimmr_AI
 * @since 1.9.0
 */

/**
 * SkeletonLoader Component
 *
 * @param {Object} props
 * @param {string} props.type - Type of skeleton (stat-card, table-row, card, text, chart)
 * @param {number} props.count - Number of skeletons to render
 * @param {string} props.className - Additional CSS class
 */
const SkeletonLoader = ({ type = 'card', count = 1, className = '' }) => {
    const items = Array(count).fill(null);

    const baseClass = 'glimmr-skeleton';
    const combinedClass = `${baseClass} ${className}`.trim();

    switch (type) {
        case 'stat-card':
            return items.map((_, i) => (
                <div key={i} className={`${combinedClass} glimmr-skeleton-stat`}>
                    <div className="glimmr-skeleton-line glimmr-skeleton-icon" />
                    <div className="glimmr-skeleton-content">
                        <div className="glimmr-skeleton-line glimmr-skeleton-title" />
                        <div className="glimmr-skeleton-line glimmr-skeleton-value" />
                    </div>
                </div>
            ));

        case 'table-row':
            return items.map((_, i) => (
                <tr key={i} className={`${combinedClass} glimmr-skeleton-row`}>
                    <td><div className="glimmr-skeleton-line" /></td>
                    <td><div className="glimmr-skeleton-line" /></td>
                    <td><div className="glimmr-skeleton-line glimmr-skeleton-short" /></td>
                    <td><div className="glimmr-skeleton-line glimmr-skeleton-short" /></td>
                </tr>
            ));

        case 'chart':
            return (
                <div className={`${combinedClass} glimmr-skeleton-chart`}>
                    <div className="glimmr-skeleton-chart-bars">
                        {items.slice(0, 7).map((_, i) => (
                            <div
                                key={i}
                                className="glimmr-skeleton-bar"
                                style={{ height: `${30 + Math.random() * 50}%` }}
                            />
                        ))}
                    </div>
                    <div className="glimmr-skeleton-line glimmr-skeleton-chart-legend" />
                </div>
            );

        case 'text':
            return items.map((_, i) => (
                <div key={i} className={`${combinedClass} glimmr-skeleton-text`}>
                    <div className="glimmr-skeleton-line" />
                    <div className="glimmr-skeleton-line glimmr-skeleton-medium" />
                    <div className="glimmr-skeleton-line glimmr-skeleton-short" />
                </div>
            ));

        case 'conversation':
            return items.map((_, i) => (
                <div key={i} className={`${combinedClass} glimmr-skeleton-conversation`}>
                    <div className="glimmr-skeleton-avatar" />
                    <div className="glimmr-skeleton-content">
                        <div className="glimmr-skeleton-line glimmr-skeleton-title" />
                        <div className="glimmr-skeleton-line" />
                        <div className="glimmr-skeleton-line glimmr-skeleton-short glimmr-skeleton-muted" />
                    </div>
                </div>
            ));

        case 'card':
        default:
            return items.map((_, i) => (
                <div key={i} className={`${combinedClass} glimmr-skeleton-card`}>
                    <div className="glimmr-skeleton-line glimmr-skeleton-title" />
                    <div className="glimmr-skeleton-line" />
                    <div className="glimmr-skeleton-line glimmr-skeleton-medium" />
                </div>
            ));
    }
};

/**
 * Dashboard Stats Skeleton
 *
 * Full skeleton for the dashboard stats grid.
 */
export const DashboardStatsSkeleton = () => (
    <div className="glimmr-stats-grid glimmr-skeleton-stats-grid">
        <SkeletonLoader type="stat-card" count={4} />
    </div>
);

/**
 * Chart Skeleton
 *
 * Skeleton for chart areas.
 */
export const ChartSkeleton = ({ height = 300 }) => (
    <div className="glimmr-skeleton-chart-container" style={{ height }}>
        <SkeletonLoader type="chart" />
    </div>
);

/**
 * Table Skeleton
 *
 * Skeleton for table content.
 */
export const TableSkeleton = ({ rows = 5 }) => (
    <table className="glimmr-skeleton-table">
        <thead>
            <tr>
                <th><div className="glimmr-skeleton-line glimmr-skeleton-header" /></th>
                <th><div className="glimmr-skeleton-line glimmr-skeleton-header" /></th>
                <th><div className="glimmr-skeleton-line glimmr-skeleton-header" /></th>
                <th><div className="glimmr-skeleton-line glimmr-skeleton-header" /></th>
            </tr>
        </thead>
        <tbody>
            <SkeletonLoader type="table-row" count={rows} />
        </tbody>
    </table>
);

/**
 * Conversations List Skeleton
 *
 * Skeleton for the conversations list.
 */
export const ConversationsListSkeleton = ({ count = 5 }) => (
    <div className="glimmr-skeleton-conversations-list">
        <SkeletonLoader type="conversation" count={count} />
    </div>
);

export default SkeletonLoader;
