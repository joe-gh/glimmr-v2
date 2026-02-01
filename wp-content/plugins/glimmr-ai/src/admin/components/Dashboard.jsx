/**
 * Dashboard Component
 *
 * Main analytics dashboard for Glimmr AI admin.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect } = wp.element;
const { Card, CardBody, CardHeader, Spinner, SelectControl, Modal, Button } = wp.components;
import {
    ComposedChart,
    Bar,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Area,
    BarChart,
    Cell,
} from 'recharts';
import {
    DashboardStatsSkeleton,
    ChartSkeleton,
    ConversationsListSkeleton,
} from './SkeletonLoader';

/**
 * Format number with commas.
 */
const formatNumber = (num) => {
    return new Intl.NumberFormat().format(num || 0);
};

/**
 * Format currency.
 */
const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount || 0);
};

/**
 * Format percentage.
 */
const formatPercent = (value) => {
    return `${(value || 0).toFixed(1)}%`;
};

/**
 * Format date for display
 */
const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

/**
 * Stat Card Component
 */
const StatCard = ({ title, value, icon, color = 'blue', formatted = false }) => {
    const colorClasses = {
        blue: 'glimmr-stat-blue',
        green: 'glimmr-stat-green',
        orange: 'glimmr-stat-orange',
        red: 'glimmr-stat-red',
        purple: 'glimmr-stat-purple',
        teal: 'glimmr-stat-teal',
    };

    return (
        <div className={`glimmr-stat-card ${colorClasses[color] || ''}`}>
            <div className="glimmr-stat-icon">
                <span className={`dashicons dashicons-${icon}`}></span>
            </div>
            <div className="glimmr-stat-content">
                <div className="glimmr-stat-value">{formatted ? value : formatNumber(value)}</div>
                <div className="glimmr-stat-title">{title}</div>
            </div>
        </div>
    );
};

/**
 * Custom tooltip for the conversation chart
 */
const ConversationTooltip = ({ active, payload, label }) => {
    if (active && payload && payload.length) {
        return (
            <div className="glimmr-chart-tooltip">
                <p className="glimmr-tooltip-label">{label}</p>
                <p className="glimmr-tooltip-value">
                    <strong>{payload[0].value}</strong> conversations
                </p>
            </div>
        );
    }
    return null;
};

/**
 * Enhanced Chart Component using Recharts - Bar + Line combo with activity-based colors
 */
const ConversationChart = ({ data }) => {
    if (!data || data.length === 0) {
        return (
            <div className="glimmr-chart-empty">
                <span className="dashicons dashicons-chart-bar"></span>
                <p>No data available for this period</p>
            </div>
        );
    }

    // Calculate average for activity level coloring
    const totalValue = data.reduce((sum, d) => sum + (d.count || 0), 0);
    const avgValue = totalValue / data.length;

    // Transform data for Recharts
    const chartData = data.map((item) => {
        const date = new Date(item.date);
        const count = item.count || 0;

        // Determine activity level for bar color
        let activityLevel = 'medium';
        if (avgValue > 0) {
            const ratio = count / avgValue;
            if (ratio < 0.5) activityLevel = 'low';
            else if (ratio > 1.5) activityLevel = 'high';
        }

        return {
            date: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
            count,
            activityLevel,
        };
    });

    // Color mapping for activity levels
    const getBarColor = (activityLevel) => {
        switch (activityLevel) {
            case 'low': return '#A5B4FC';
            case 'high': return '#4338CA';
            default: return '#6366F1';
        }
    };

    return (
        <div className="glimmr-recharts-container">
            <ResponsiveContainer width="100%" height={220}>
                <ComposedChart data={chartData} margin={{ top: 20, right: 20, bottom: 20, left: 0 }}>
                    <defs>
                        <linearGradient id="areaGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#6366F1" stopOpacity={0.3} />
                            <stop offset="100%" stopColor="#6366F1" stopOpacity={0.02} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                    <XAxis
                        dataKey="date"
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11, fill: '#666' }}
                        dy={10}
                    />
                    <YAxis
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11, fill: '#666' }}
                        width={30}
                        allowDecimals={false}
                    />
                    <Tooltip content={<ConversationTooltip />} />
                    <Area
                        type="monotone"
                        dataKey="count"
                        fill="url(#areaGradient)"
                        stroke="none"
                    />
                    <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={40}>
                        {chartData.map((entry, index) => (
                            <Cell key={`cell-${index}`} fill={getBarColor(entry.activityLevel)} />
                        ))}
                    </Bar>
                    <Line
                        type="monotone"
                        dataKey="count"
                        stroke="#4F46E5"
                        strokeWidth={2}
                        dot={{ fill: '#4F46E5', strokeWidth: 2, stroke: '#fff', r: 4 }}
                        activeDot={{ r: 6, fill: '#4F46E5', stroke: '#fff', strokeWidth: 2 }}
                    />
                </ComposedChart>
            </ResponsiveContainer>

            {/* Legend */}
            <div className="glimmr-chart-legend">
                <span className="glimmr-legend-item">
                    <span className="glimmr-legend-dot" style={{ background: '#A5B4FC' }}></span>
                    Low
                </span>
                <span className="glimmr-legend-item">
                    <span className="glimmr-legend-dot" style={{ background: '#6366F1' }}></span>
                    Average
                </span>
                <span className="glimmr-legend-item">
                    <span className="glimmr-legend-dot" style={{ background: '#4338CA' }}></span>
                    High
                </span>
            </div>
        </div>
    );
};

/**
 * Tool Usage Component
 */
const ToolUsage = ({ data }) => {
    if (!data || data.length === 0) {
        return (
            <div className="glimmr-tool-usage-empty">
                <p>No tool usage data yet</p>
            </div>
        );
    }

    const total = data.reduce((sum, item) => sum + (parseInt(item.usage_count) || 0), 0);

    return (
        <div className="glimmr-tool-usage">
            {data.slice(0, 8).map((item, index) => {
                const percent = total > 0 ? ((parseInt(item.usage_count) || 0) / total) * 100 : 0;

                return (
                    <div key={index} className="glimmr-tool-usage-item">
                        <div className="glimmr-tool-usage-header">
                            <span className="glimmr-tool-name">
                                {(item.tool_name || 'Unknown').replace(/_/g, ' ')}
                            </span>
                            <span className="glimmr-tool-count">{formatNumber(item.usage_count)}</span>
                        </div>
                        <div className="glimmr-tool-usage-bar-bg">
                            <div
                                className="glimmr-tool-usage-bar"
                                style={{ width: `${percent}%` }}
                            ></div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};

/**
 * Revenue Chart Component using Recharts
 */
const RevenueChart = ({ data }) => {
    if (!data || data.length === 0) {
        return (
            <div className="glimmr-chart-empty">
                <span className="dashicons dashicons-chart-area"></span>
                <p>No revenue data available for this period</p>
            </div>
        );
    }

    // Transform data for Recharts
    const chartData = data.map((item) => {
        const date = new Date(item.date);
        return {
            date: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
            revenue: parseFloat(item.revenue) || 0,
            orders: parseInt(item.orders) || 0,
        };
    });

    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            return (
                <div className="glimmr-chart-tooltip">
                    <p className="glimmr-tooltip-label">{label}</p>
                    <p className="glimmr-tooltip-value" style={{ color: '#10B981' }}>
                        <strong>{formatCurrency(payload[0]?.value)}</strong> revenue
                    </p>
                    {payload[1] && (
                        <p className="glimmr-tooltip-value" style={{ color: '#6366F1' }}>
                            <strong>{payload[1]?.value}</strong> orders
                        </p>
                    )}
                </div>
            );
        }
        return null;
    };

    return (
        <div className="glimmr-recharts-container">
            <ResponsiveContainer width="100%" height={220}>
                <ComposedChart data={chartData} margin={{ top: 20, right: 20, bottom: 20, left: 10 }}>
                    <defs>
                        <linearGradient id="revenueGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#10B981" stopOpacity={0.3} />
                            <stop offset="100%" stopColor="#10B981" stopOpacity={0.02} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                    <XAxis
                        dataKey="date"
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11, fill: '#666' }}
                        dy={10}
                    />
                    <YAxis
                        yAxisId="revenue"
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11, fill: '#666' }}
                        width={50}
                        tickFormatter={(value) => `$${value >= 1000 ? `${(value / 1000).toFixed(0)}k` : value}`}
                    />
                    <YAxis
                        yAxisId="orders"
                        orientation="right"
                        axisLine={false}
                        tickLine={false}
                        tick={{ fontSize: 11, fill: '#666' }}
                        width={30}
                        allowDecimals={false}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Area
                        yAxisId="revenue"
                        type="monotone"
                        dataKey="revenue"
                        fill="url(#revenueGradient)"
                        stroke="#10B981"
                        strokeWidth={2}
                    />
                    <Bar
                        yAxisId="orders"
                        dataKey="orders"
                        fill="#6366F1"
                        opacity={0.6}
                        radius={[4, 4, 0, 0]}
                        maxBarSize={30}
                    />
                </ComposedChart>
            </ResponsiveContainer>

            {/* Legend */}
            <div className="glimmr-chart-legend">
                <span className="glimmr-legend-item">
                    <span className="glimmr-legend-line" style={{ background: '#10B981' }}></span>
                    Revenue
                </span>
                <span className="glimmr-legend-item">
                    <span className="glimmr-legend-dot" style={{ background: '#6366F1' }}></span>
                    Orders
                </span>
            </div>
        </div>
    );
};

/**
 * Top Converting Conversations Component
 */
const TopConversations = ({ conversations, onViewConversation }) => {
    if (!conversations || conversations.length === 0) {
        return (
            <div className="glimmr-recent-empty">
                <span className="dashicons dashicons-awards"></span>
                <p>No converting conversations yet</p>
            </div>
        );
    }

    return (
        <div className="glimmr-top-conversations">
            {conversations.map((conv, index) => (
                <button
                    key={index}
                    type="button"
                    className="glimmr-top-conv-item"
                    onClick={() => onViewConversation(conv)}
                >
                    <div className="glimmr-top-conv-rank">
                        <span className="glimmr-rank-badge">#{index + 1}</span>
                    </div>
                    <div className="glimmr-top-conv-content">
                        <div className="glimmr-top-conv-header">
                            <span className="glimmr-top-conv-id">
                                Conversation #{conv.conversation_id?.slice(-8) || index + 1}
                            </span>
                            <span className="glimmr-top-conv-revenue">
                                {formatCurrency(conv.total_revenue)}
                            </span>
                        </div>
                        <div className="glimmr-top-conv-meta">
                            <span>{conv.order_count} order{conv.order_count !== 1 ? 's' : ''}</span>
                            <span className="glimmr-meta-sep">•</span>
                            <span>{conv.message_count || 0} messages</span>
                            <span className="glimmr-meta-sep">•</span>
                            <span>{new Date(conv.created_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                    <div className="glimmr-top-conv-arrow">
                        <span className="dashicons dashicons-arrow-right-alt2"></span>
                    </div>
                </button>
            ))}
        </div>
    );
};

/**
 * Recent Activity Component
 */
const RecentActivity = ({ conversations, onViewConversation }) => {
    if (!conversations || conversations.length === 0) {
        return (
            <div className="glimmr-recent-empty">
                <span className="dashicons dashicons-format-chat"></span>
                <p>No recent conversations</p>
            </div>
        );
    }

    return (
        <div className="glimmr-recent-activity">
            {conversations.slice(0, 5).map((conv, index) => (
                <button
                    key={index}
                    type="button"
                    className="glimmr-recent-item"
                    onClick={() => onViewConversation(conv)}
                >
                    <div className="glimmr-recent-icon">
                        <span className="dashicons dashicons-format-chat"></span>
                    </div>
                    <div className="glimmr-recent-content">
                        <div className="glimmr-recent-title">
                            Conversation #{conv.conversation_id?.slice(-8) || index + 1}
                        </div>
                        <div className="glimmr-recent-meta">
                            {conv.message_count || 0} messages
                            <span className={`glimmr-status glimmr-status-${conv.status}`}>
                                {conv.status}
                            </span>
                        </div>
                    </div>
                    <div className="glimmr-recent-time">
                        {new Date(conv.created_at).toLocaleDateString()}
                    </div>
                    <div className="glimmr-recent-arrow">
                        <span className="dashicons dashicons-arrow-right-alt2"></span>
                    </div>
                </button>
            ))}
        </div>
    );
};

/**
 * Message Component for conversation detail
 */
const Message = ({ message }) => {
    const roleConfig = {
        user: { label: 'Customer', icon: 'admin-users', color: 'blue' },
        assistant: { label: 'AI', icon: 'format-chat', color: 'green' },
        system: { label: 'System', icon: 'info', color: 'gray' },
        tool: { label: 'Tool', icon: 'admin-tools', color: 'orange' },
    };

    const config = roleConfig[message.role] || { label: message.role, icon: 'editor-help', color: 'gray' };

    return (
        <div className={`glimmr-message glimmr-message-${message.role}`}>
            <div className="glimmr-message-header">
                <span className={`dashicons dashicons-${config.icon}`}></span>
                <span className="glimmr-message-role">{config.label}</span>
                <span className="glimmr-message-time">
                    {formatDate(message.created_at)}
                </span>
            </div>
            <div className="glimmr-message-content">
                {message.content}
            </div>
            {message.tool_calls && (
                <div className="glimmr-message-tools">
                    <strong>Tools called:</strong>
                    <pre>{(() => {
                        try {
                            const toolData = typeof message.tool_calls === 'string'
                                ? JSON.parse(message.tool_calls)
                                : message.tool_calls;
                            return JSON.stringify(toolData, null, 2);
                        } catch {
                            return message.tool_calls;
                        }
                    })()}</pre>
                </div>
            )}
            {message.tokens_used > 0 && (
                <div className="glimmr-message-tokens">
                    {message.tokens_used} tokens
                </div>
            )}
        </div>
    );
};

/**
 * Status Badge Component
 */
const StatusBadge = ({ status }) => {
    const statusConfig = {
        active: { label: 'Active', color: 'green' },
        expired: { label: 'Expired', color: 'gray' },
        flagged: { label: 'Flagged', color: 'red' },
    };

    const config = statusConfig[status] || { label: status, color: 'gray' };

    return (
        <span className={`glimmr-status-badge glimmr-status-${config.color}`}>
            {config.label}
        </span>
    );
};

/**
 * Conversation Detail Modal
 */
const ConversationDetail = ({ conversation, messages, onClose, loading }) => {
    if (!conversation) return null;

    return (
        <Modal
            title={`Conversation #${conversation.conversation_id?.slice(-8) || conversation.id}`}
            onRequestClose={onClose}
            className="glimmr-conversation-modal"
            isFullScreen
        >
            <div className="glimmr-conversation-detail">
                {/* Metadata */}
                <div className="glimmr-conversation-meta">
                    <div className="glimmr-meta-item">
                        <strong>Status:</strong>
                        <StatusBadge status={conversation.status} />
                    </div>
                    <div className="glimmr-meta-item">
                        <strong>Customer:</strong>
                        {conversation.user_id ? (
                            <a href={`user-edit.php?user_id=${conversation.user_id}`} target="_blank">
                                {conversation.user_name || `User #${conversation.user_id}`}
                            </a>
                        ) : (
                            'Guest'
                        )}
                    </div>
                    <div className="glimmr-meta-item">
                        <strong>Started:</strong> {formatDate(conversation.created_at)}
                    </div>
                    <div className="glimmr-meta-item">
                        <strong>Messages:</strong> {conversation.message_count || 0}
                    </div>
                </div>

                {/* Messages */}
                <div className="glimmr-conversation-messages">
                    {loading ? (
                        <div className="glimmr-loading-center">
                            <Spinner />
                        </div>
                    ) : messages.length === 0 ? (
                        <div className="glimmr-empty-state">
                            <p>No messages in this conversation.</p>
                        </div>
                    ) : (
                        messages.map((msg, idx) => (
                            <Message key={idx} message={msg} />
                        ))
                    )}
                </div>

                {/* Actions */}
                <div className="glimmr-conversation-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Close
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Main Dashboard Component
 */
/**
 * Health Status Panel Component
 */
const HealthStatusPanel = ({ status, checks, errorTypes, recentErrors }) => {
    const statusConfig = {
        healthy: { label: 'Healthy', color: 'green', icon: 'yes-alt' },
        warning: { label: 'Warning', color: 'orange', icon: 'warning' },
        critical: { label: 'Critical', color: 'red', icon: 'dismiss' },
    };

    const config = statusConfig[status] || statusConfig.healthy;

    return (
        <div className="glimmr-health-panel">
            <div className={`glimmr-health-status glimmr-health-${config.color}`}>
                <span className={`dashicons dashicons-${config.icon}`}></span>
                <span className="glimmr-health-label">{config.label}</span>
            </div>

            <div className="glimmr-health-checks">
                {checks && Object.entries(checks).map(([key, check]) => (
                    <div key={key} className="glimmr-health-check-item">
                        <span className={`glimmr-check-indicator ${check.passed ? 'passed' : 'failed'}`}>
                            <span className={`dashicons dashicons-${check.passed ? 'yes' : 'no'}`}></span>
                        </span>
                        <span className="glimmr-check-label">{check.label}</span>
                    </div>
                ))}
            </div>

            {errorTypes && errorTypes.length > 0 && (
                <div className="glimmr-health-errors">
                    <h4>Error Breakdown (24h)</h4>
                    <div className="glimmr-error-list">
                        {errorTypes.slice(0, 5).map((item, idx) => (
                            <div key={idx} className="glimmr-error-item">
                                <span className="glimmr-error-type">{item.error_type || 'Unknown'}</span>
                                <span className="glimmr-error-count">{item.count}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {recentErrors && recentErrors.length > 0 && (
                <div className="glimmr-recent-errors">
                    <h4>Recent Errors</h4>
                    {recentErrors.slice(0, 3).map((error, idx) => (
                        <div key={idx} className="glimmr-recent-error">
                            <span className="glimmr-error-message">{error.error_message || 'Unknown error'}</span>
                            <span className="glimmr-error-time">{new Date(error.created_at).toLocaleString()}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

/**
 * Response Time Stats Component
 */
const ResponseTimeStats = ({ stats, daily }) => {
    if (!stats) {
        return (
            <div className="glimmr-response-time-empty">
                <p>No response time data available</p>
            </div>
        );
    }

    const formatMs = (ms) => {
        if (ms >= 1000) {
            return `${(ms / 1000).toFixed(1)}s`;
        }
        return `${Math.round(ms)}ms`;
    };

    return (
        <div className="glimmr-response-time-panel">
            <div className="glimmr-response-time-stats">
                <div className="glimmr-response-stat">
                    <span className="glimmr-stat-label">Average</span>
                    <span className="glimmr-stat-value">{formatMs(stats.avg_response_time)}</span>
                </div>
                <div className="glimmr-response-stat">
                    <span className="glimmr-stat-label">Fastest</span>
                    <span className="glimmr-stat-value glimmr-stat-success">{formatMs(stats.min_response_time)}</span>
                </div>
                <div className="glimmr-response-stat">
                    <span className="glimmr-stat-label">Slowest</span>
                    <span className="glimmr-stat-value glimmr-stat-warning">{formatMs(stats.max_response_time)}</span>
                </div>
                <div className="glimmr-response-stat">
                    <span className="glimmr-stat-label">Total Tokens</span>
                    <span className="glimmr-stat-value">{formatNumber(stats.total_tokens)}</span>
                </div>
            </div>

            {daily && daily.length > 0 && (
                <div className="glimmr-response-time-chart">
                    <ResponsiveContainer width="100%" height={120}>
                        <BarChart data={daily} margin={{ top: 10, right: 10, bottom: 10, left: 0 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                            <XAxis
                                dataKey="date"
                                axisLine={false}
                                tickLine={false}
                                tick={{ fontSize: 10, fill: '#666' }}
                                tickFormatter={(val) => {
                                    const d = new Date(val);
                                    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                                }}
                            />
                            <YAxis
                                axisLine={false}
                                tickLine={false}
                                tick={{ fontSize: 10, fill: '#666' }}
                                width={40}
                                tickFormatter={(val) => val >= 1000 ? `${(val/1000).toFixed(0)}s` : `${val}ms`}
                            />
                            <Tooltip
                                formatter={(value) => [formatMs(value), 'Avg Response Time']}
                                labelFormatter={(label) => new Date(label).toLocaleDateString()}
                            />
                            <Bar dataKey="avg_response_time" fill="#6366F1" radius={[4, 4, 0, 0]} maxBarSize={30} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
};

const Dashboard = () => {
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState('week');
    const [analytics, setAnalytics] = useState({
        conversationCount: 0,
        messageCount: 0,
        flaggedCount: 0,
        toolUsage: [],
        dailyCounts: [],
        // Revenue attribution data
        conversions: {
            revenue: 0,
            conversions: 0,
            conversion_rate: 0,
            avg_order_value: 0,
        },
        dailyRevenue: [],
        topConversations: [],
    });
    const [recentConversations, setRecentConversations] = useState([]);
    const [error, setError] = useState(null);

    // Health and response time state
    const [healthStatus, setHealthStatus] = useState(null);
    const [responseTimeData, setResponseTimeData] = useState(null);

    // Conversation modal state
    const [viewingConversation, setViewingConversation] = useState(null);
    const [conversationMessages, setConversationMessages] = useState([]);
    const [loadingMessages, setLoadingMessages] = useState(false);

    // Purge state
    const [showPurgeConfirm, setShowPurgeConfirm] = useState(false);
    const [purging, setPurging] = useState(false);
    const [purgeResult, setPurgeResult] = useState(null);

    const { ajaxUrl, nonce, strings } = window.glimmrAI || {};

    /**
     * Fetch analytics data.
     */
    const fetchAnalytics = async (selectedPeriod) => {
        setLoading(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_analytics');
            formData.append('nonce', nonce);
            formData.append('period', selectedPeriod);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setAnalytics(result.data);
            } else {
                setError(result.data?.message || 'Failed to load analytics');
            }
        } catch (err) {
            setError('Failed to connect to server');
            console.error('Analytics fetch error:', err);
        }

        setLoading(false);
    };

    /**
     * Fetch recent conversations.
     */
    const fetchRecentConversations = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_conversations');
            formData.append('nonce', nonce);
            formData.append('page', 1);
            formData.append('per_page', 5);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setRecentConversations(result.data.conversations || []);
            }
        } catch (err) {
            console.error('Conversations fetch error:', err);
        }
    };

    /**
     * View conversation detail.
     */
    const handleViewConversation = async (conversation) => {
        setViewingConversation(conversation);
        setLoadingMessages(true);
        setConversationMessages([]);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_conversation_messages');
            formData.append('nonce', nonce);
            formData.append('conversation_id', conversation.conversation_id);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setConversationMessages(result.data || []);
            }
        } catch (err) {
            console.error('Messages fetch error:', err);
        }

        setLoadingMessages(false);
    };

    /**
     * Close conversation modal.
     */
    const handleCloseConversation = () => {
        setViewingConversation(null);
        setConversationMessages([]);
    };

    /**
     * Purge all conversation history.
     */
    const handlePurgeHistory = async () => {
        setPurging(true);
        setPurgeResult(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_purge_conversation_history');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setPurgeResult({ type: 'success', message: result.data.message });
                // Refresh analytics to show empty state
                fetchAnalytics(period);
                fetchRecentConversations();
            } else {
                setPurgeResult({ type: 'error', message: result.data?.message || 'Failed to purge history.' });
            }
        } catch (err) {
            setPurgeResult({ type: 'error', message: 'Failed to connect to server.' });
            console.error('Purge error:', err);
        }

        setPurging(false);
        setShowPurgeConfirm(false);
    };

    /**
     * Fetch health status.
     */
    const fetchHealthStatus = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_health_status');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setHealthStatus(result.data);
            }
        } catch (err) {
            console.error('Health status fetch error:', err);
        }
    };

    /**
     * Fetch response time analytics.
     */
    const fetchResponseTimeAnalytics = async (selectedPeriod) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_response_time_analytics');
            formData.append('nonce', nonce);
            formData.append('period', selectedPeriod);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setResponseTimeData(result.data);
            }
        } catch (err) {
            console.error('Response time fetch error:', err);
        }
    };

    /**
     * Effect: Load data on mount and period change.
     */
    useEffect(() => {
        fetchAnalytics(period);
        fetchRecentConversations();
        fetchHealthStatus();
        fetchResponseTimeAnalytics(period);
    }, [period]);

    /**
     * Handle period change.
     */
    const handlePeriodChange = (newPeriod) => {
        setPeriod(newPeriod);
    };

    /**
     * Get site URL for View Site link.
     */
    const getSiteUrl = () => {
        const restUrl = window.glimmrAI?.restUrl || '';
        return restUrl.replace('/wp-json/glimmr-ai/v1/', '').replace(/\/$/, '');
    };

    // Show skeleton loading state
    if (loading && !analytics.conversationCount) {
        return (
            <div className="glimmr-dashboard">
                {/* Header skeleton */}
                <div className="glimmr-dashboard-header">
                    <div className="glimmr-period-selector">
                        <SelectControl
                            value={period}
                            options={[
                                { value: 'week', label: 'Last 7 Days' },
                            ]}
                            disabled
                        />
                    </div>
                </div>

                {/* Revenue Stats Skeleton */}
                <DashboardStatsSkeleton />

                {/* Conversation Stats Skeleton */}
                <DashboardStatsSkeleton />

                {/* Charts Skeleton */}
                <div className="glimmr-charts-row">
                    <ChartSkeleton height={350} />
                </div>

                {/* Recent Activity Skeleton */}
                <Card className="glimmr-recent-card">
                    <CardHeader>
                        <h3>Recent Conversations</h3>
                    </CardHeader>
                    <CardBody style={{ padding: 0 }}>
                        <ConversationsListSkeleton count={5} />
                    </CardBody>
                </Card>
            </div>
        );
    }

    return (
        <div className="glimmr-dashboard">
            {/* Header */}
            <div className="glimmr-dashboard-header">
                <div className="glimmr-period-selector">
                    <SelectControl
                        value={period}
                        options={[
                            { value: 'day', label: 'Today' },
                            { value: 'week', label: 'Last 7 Days' },
                            { value: 'month', label: 'Last 30 Days' },
                            { value: '6months', label: 'Last 6 Months' },
                            { value: 'year', label: 'Last Year' },
                            { value: '2years', label: 'Last 2 Years' },
                            { value: '5years', label: 'Last 5 Years' },
                            { value: 'all', label: 'All Time' },
                        ]}
                        onChange={handlePeriodChange}
                    />
                </div>
                <a
                    href={getSiteUrl() || '#'}
                    className="glimmr-view-site-link"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <span className="dashicons dashicons-external"></span>
                    View Site
                </a>
            </div>

            {error && (
                <div className="glimmr-error-notice">
                    <span className="dashicons dashicons-warning"></span>
                    {error}
                </div>
            )}

            {/* Revenue Stats Grid */}
            <div className="glimmr-stats-grid glimmr-stats-revenue">
                <StatCard
                    title="AI-Attributed Revenue"
                    value={formatCurrency(analytics.conversions?.revenue || 0)}
                    icon="cart"
                    color="green"
                    formatted
                />
                <StatCard
                    title="Conversion Rate"
                    value={formatPercent(analytics.conversions?.conversion_rate || 0)}
                    icon="performance"
                    color="blue"
                    formatted
                />
                <StatCard
                    title="Average Order Value"
                    value={formatCurrency(analytics.conversions?.avg_order_value || 0)}
                    icon="money-alt"
                    color="purple"
                    formatted
                />
                <StatCard
                    title="AI-Driven Orders"
                    value={analytics.conversions?.conversions || 0}
                    icon="store"
                    color="teal"
                />
            </div>

            {/* Conversation Stats Grid */}
            <div className="glimmr-stats-grid">
                <StatCard
                    title="Conversations"
                    value={analytics.conversationCount}
                    icon="format-chat"
                    color="blue"
                />
                <StatCard
                    title="Messages"
                    value={analytics.messageCount}
                    icon="testimonial"
                    color="green"
                />
                <StatCard
                    title="Flagged Issues"
                    value={analytics.flaggedCount}
                    icon="flag"
                    color={analytics.flaggedCount > 0 ? 'red' : 'green'}
                />
                <StatCard
                    title="Avg Messages/Chat"
                    value={
                        analytics.conversationCount > 0
                            ? Math.round(analytics.messageCount / analytics.conversationCount)
                            : 0
                    }
                    icon="chart-line"
                    color="purple"
                />
            </div>

            {/* Revenue Charts Row */}
            <div className="glimmr-charts-row">
                <Card className="glimmr-chart-card glimmr-chart-wide">
                    <CardHeader>
                        <h3>Revenue Attribution</h3>
                        <span className="glimmr-chart-subtitle">Orders attributed to AI conversations</span>
                    </CardHeader>
                    <CardBody>
                        <RevenueChart data={analytics.dailyRevenue} />
                    </CardBody>
                </Card>

                <Card className="glimmr-chart-card">
                    <CardHeader>
                        <h3>Top Converting Conversations</h3>
                    </CardHeader>
                    <CardBody>
                        <TopConversations
                            conversations={analytics.topConversations}
                            onViewConversation={handleViewConversation}
                        />
                    </CardBody>
                </Card>
            </div>

            {/* Activity Charts Row */}
            <div className="glimmr-charts-row">
                <Card className="glimmr-chart-card">
                    <CardHeader>
                        <h3>Conversation Volume</h3>
                    </CardHeader>
                    <CardBody>
                        <ConversationChart data={analytics.dailyCounts} />
                    </CardBody>
                </Card>

                <Card className="glimmr-chart-card">
                    <CardHeader>
                        <h3>Tool Usage</h3>
                    </CardHeader>
                    <CardBody>
                        <ToolUsage data={analytics.toolUsage} />
                    </CardBody>
                </Card>
            </div>

            {/* System Health & Response Time Row */}
            <div className="glimmr-charts-row">
                <Card className="glimmr-chart-card">
                    <CardHeader>
                        <h3>System Health</h3>
                        <span className="glimmr-chart-subtitle">API status and error monitoring</span>
                    </CardHeader>
                    <CardBody>
                        {healthStatus ? (
                            <HealthStatusPanel
                                status={healthStatus.status}
                                checks={healthStatus.checks}
                                errorTypes={healthStatus.error_types}
                                recentErrors={healthStatus.recent_errors}
                            />
                        ) : (
                            <div className="glimmr-loading-center">
                                <Spinner />
                            </div>
                        )}
                    </CardBody>
                </Card>

                <Card className="glimmr-chart-card">
                    <CardHeader>
                        <h3>Response Time Analytics</h3>
                        <span className="glimmr-chart-subtitle">AI response latency and token usage</span>
                    </CardHeader>
                    <CardBody>
                        <ResponseTimeStats
                            stats={responseTimeData?.stats}
                            daily={responseTimeData?.daily}
                        />
                    </CardBody>
                </Card>
            </div>

            {/* Recent Activity */}
            <Card className="glimmr-recent-card">
                <CardHeader>
                    <h3>Recent Conversations</h3>
                    <a
                        href="admin.php?page=glimmr-ai-conversations"
                        className="glimmr-view-all"
                    >
                        View All
                    </a>
                </CardHeader>
                <CardBody>
                    <RecentActivity
                        conversations={recentConversations}
                        onViewConversation={handleViewConversation}
                    />
                </CardBody>
            </Card>

            {/* Getting Started Notice */}
            {analytics.conversationCount === 0 && (
                <Card className="glimmr-getting-started">
                    <CardBody>
                        <div className="glimmr-getting-started-content">
                            <span className="dashicons dashicons-lightbulb"></span>
                            <div>
                                <h3>Getting Started with Glimmr AI</h3>
                                <p>
                                    Your AI shopping assistant is ready! Here's what to do next:
                                </p>
                                <ol>
                                    <li>
                                        <a href="admin.php?page=glimmr-ai-settings">
                                            Configure your API settings
                                        </a>
                                    </li>
                                    <li>
                                        <a href="admin.php?page=glimmr-ai-knowledge">
                                            Add content to the knowledge base
                                        </a>
                                    </li>
                                    <li>
                                        <a href="admin.php?page=glimmr-ai-prompts">
                                            Customize the system prompt
                                        </a>
                                    </li>
                                    <li>Visit your store to test the chat widget</li>
                                </ol>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            )}

            {/* Developer Tools */}
            <Card className="glimmr-dev-tools-card">
                <CardHeader>
                    <h3>Developer Tools</h3>
                </CardHeader>
                <CardBody>
                    <div className="glimmr-dev-tools">
                        <div className="glimmr-dev-tool-item">
                            <div className="glimmr-dev-tool-info">
                                <strong>Purge Conversation History</strong>
                                <p>Delete all conversations, messages, analytics, and rate limits. Useful for resetting after testing.</p>
                            </div>
                            {!showPurgeConfirm ? (
                                <Button
                                    variant="secondary"
                                    isDestructive
                                    onClick={() => setShowPurgeConfirm(true)}
                                >
                                    Purge All Data
                                </Button>
                            ) : (
                                <div className="glimmr-purge-confirm">
                                    <span className="glimmr-purge-warning">Are you sure? This cannot be undone.</span>
                                    <Button
                                        variant="primary"
                                        isDestructive
                                        onClick={handlePurgeHistory}
                                        disabled={purging}
                                    >
                                        {purging ? 'Purging...' : 'Yes, Purge Everything'}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={() => setShowPurgeConfirm(false)}
                                        disabled={purging}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            )}
                        </div>
                        {purgeResult && (
                            <div className={`glimmr-purge-result glimmr-purge-${purgeResult.type}`}>
                                {purgeResult.message}
                            </div>
                        )}
                    </div>
                </CardBody>
            </Card>

            {/* Conversation Detail Modal */}
            {viewingConversation && (
                <ConversationDetail
                    conversation={viewingConversation}
                    messages={conversationMessages}
                    onClose={handleCloseConversation}
                    loading={loadingMessages}
                />
            )}
        </div>
    );
};

export default Dashboard;
