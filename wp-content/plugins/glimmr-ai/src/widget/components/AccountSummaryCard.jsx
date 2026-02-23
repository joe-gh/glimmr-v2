/**
 * AccountSummaryCard - Customer Account Summary Component
 *
 * Displays customer account information including name,
 * email, loyalty points, and recent activity.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h } from 'preact';
import { useMemo } from 'preact/hooks';
import { toNumber, safeToFixed, safeToLocaleString } from '../utils/numbers';

/**
 * User icon.
 */
const UserIcon = () => (
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true" focusable="false">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
    </svg>
);

/**
 * Star/badge icon for loyalty.
 */
const StarIcon = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" strokeWidth="0" aria-hidden="true" focusable="false">
        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
    </svg>
);

/**
 * Package icon for orders.
 */
const PackageIcon = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M12.89 1.45l8 4A2 2 0 0 1 22 7.24v9.53a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.79 0l-8-4a2 2 0 0 1-1.1-1.8V7.24a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.78 0z" />
        <polyline points="2.32 6.16 12 11 21.68 6.16" />
        <line x1="12" y1="22.76" x2="12" y2="11" />
    </svg>
);

/**
 * Heart icon for wishlist.
 */
const HeartIcon = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
    </svg>
);

/**
 * Dollar icon for spending.
 */
const DollarIcon = () => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="12" y1="1" x2="12" y2="23" />
        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
    </svg>
);

/**
 * External link icon.
 */
const ExternalLinkIcon = () => (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
        <polyline points="15 3 21 3 21 9" />
        <line x1="10" y1="14" x2="21" y2="3" />
    </svg>
);

/**
 * Mask email for privacy.
 */
const maskEmail = (email) => {
    if (!email) return '';
    const [local, domain] = email.split('@');
    if (!domain) return email;

    const maskedLocal = local.length > 2
        ? local[0] + '***' + local[local.length - 1]
        : local[0] + '***';

    return `${maskedLocal}@${domain}`;
};

/**
 * Format currency.
 */
const formatCurrency = (amount) => {
    if (typeof amount === 'string' && amount.includes('$')) return amount;
    return `$${safeToFixed(amount, 2, '0.00')}`;
};

/**
 * Get loyalty tier color.
 */
const getTierColor = (tier) => {
    const colors = {
        bronze: '#CD7F32',
        silver: '#C0C0C0',
        gold: '#FFD700',
        platinum: '#E5E4E2',
        diamond: '#B9F2FF',
    };
    return colors[tier?.toLowerCase()] || '#9CA3AF';
};

/**
 * Stat card component.
 */
const StatCard = ({ icon: Icon, label, value, sublabel }) => (
    <div className="glimmr-account-stat">
        <div className="glimmr-account-stat-icon">
            <Icon />
        </div>
        <div className="glimmr-account-stat-info">
            <span className="glimmr-account-stat-value">{value}</span>
            <span className="glimmr-account-stat-label">{label}</span>
            {sublabel && (
                <span className="glimmr-account-stat-sublabel">{sublabel}</span>
            )}
        </div>
    </div>
);

/**
 * Main AccountSummaryCard component.
 */
const AccountSummaryCard = ({
    account,
    config = {},
}) => {
    // Get config values
    const {
        accountShowLoyalty = true,
        accountMaskEmail = true,
    } = config.artifacts || config;

    /**
     * Get display name.
     */
    const displayName = useMemo(() => {
        if (!account) return '';
        if (account.display_name) return account.display_name;
        if (account.first_name && account.last_name) {
            return `${account.first_name} ${account.last_name}`;
        }
        if (account.first_name) return account.first_name;
        return account.username || 'Customer';
    }, [account]);

    /**
     * Get displayed email.
     */
    const displayEmail = useMemo(() => {
        if (!account?.email) return '';
        return accountMaskEmail ? maskEmail(account.email) : account.email;
    }, [account, accountMaskEmail]);

    if (!account) {
        return (
            <div className="glimmr-account-card glimmr-account-guest">
                <UserIcon />
                <div className="glimmr-account-guest-info">
                    <p>You're browsing as a guest</p>
                    <a href={account?.login_url || '/my-account'} className="glimmr-btn glimmr-btn-primary">
                        Sign In
                    </a>
                </div>
            </div>
        );
    }

    return (
        <div className="glimmr-account-card">
            {/* Header with avatar and name */}
            <div className="glimmr-account-header">
                <div className="glimmr-account-avatar">
                    {account.avatar_url ? (
                        <img src={account.avatar_url} alt={displayName} />
                    ) : (
                        <UserIcon />
                    )}
                </div>
                <div className="glimmr-account-info">
                    <h3 className="glimmr-account-name">{displayName}</h3>
                    {displayEmail && (
                        <span className="glimmr-account-email">{displayEmail}</span>
                    )}
                    {account.member_since && (
                        <span className="glimmr-account-member">
                            Member since {account.member_since}
                        </span>
                    )}
                </div>
            </div>

            {/* Loyalty tier badge */}
            {accountShowLoyalty && account.loyalty_tier && (
                <div
                    className="glimmr-account-tier"
                    style={{ '--tier-color': getTierColor(account.loyalty_tier) }}
                >
                    <StarIcon />
                    <span>{account.loyalty_tier} Member</span>
                    {account.loyalty_points !== undefined && (
                        <span className="glimmr-account-points">
                            {safeToLocaleString(account.loyalty_points)} points
                        </span>
                    )}
                </div>
            )}

            {/* Stats grid */}
            <div className="glimmr-account-stats">
                {account.total_orders !== undefined && (
                    <StatCard
                        icon={PackageIcon}
                        label="Orders"
                        value={account.total_orders}
                    />
                )}
                {account.total_spent !== undefined && (
                    <StatCard
                        icon={DollarIcon}
                        label="Total Spent"
                        value={formatCurrency(account.total_spent)}
                    />
                )}
                {account.wishlist_count !== undefined && (
                    <StatCard
                        icon={HeartIcon}
                        label="Wishlist"
                        value={account.wishlist_count}
                    />
                )}
            </div>

            {/* Loyalty progress (if applicable) */}
            {accountShowLoyalty && account.next_tier && account.points_to_next_tier && (
                <div className="glimmr-account-progress">
                    <div className="glimmr-account-progress-header">
                        <span>{safeToLocaleString(account.points_to_next_tier)} points to {account.next_tier}</span>
                    </div>
                    <div className="glimmr-account-progress-bar">
                        <div
                            className="glimmr-account-progress-fill"
                            style={{
                                width: `${Math.min((toNumber(account.loyalty_points) / (toNumber(account.loyalty_points) + toNumber(account.points_to_next_tier))) * 100, 100)}%`,
                            }}
                        />
                    </div>
                </div>
            )}

            {/* Quick links */}
            <div className="glimmr-account-links">
                <a
                    href={account.account_url || '/my-account'}
                    className="glimmr-account-link"
                >
                    My Account
                    <ExternalLinkIcon />
                </a>
                <a
                    href={account.orders_url || '/my-account/orders'}
                    className="glimmr-account-link"
                >
                    Order History
                    <ExternalLinkIcon />
                </a>
                {account.wishlist_url && (
                    <a
                        href={account.wishlist_url}
                        className="glimmr-account-link"
                    >
                        Wishlist
                        <ExternalLinkIcon />
                    </a>
                )}
            </div>
        </div>
    );
};

export default AccountSummaryCard;
