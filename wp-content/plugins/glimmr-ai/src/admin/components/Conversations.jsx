/**
 * Conversations Component
 *
 * View and manage AI chat conversations.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect } = wp.element;
const {
    Card,
    CardBody,
    CardHeader,
    Button,
    Spinner,
    Notice,
    SelectControl,
    TextControl,
    Modal,
} = wp.components;

/**
 * Format date for display
 */
const formatDate = (dateString) => {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
 * Conversation Row Component
 */
const ConversationRow = ({ conversation, onView, onFlag }) => (
    <tr className={`glimmr-conversation-row ${conversation.status === 'flagged' ? 'is-flagged' : ''}`}>
        <td className="glimmr-conv-id">
            <button
                className="glimmr-conv-id-link"
                onClick={() => onView(conversation)}
            >
                #{conversation.conversation_id?.slice(-8) || conversation.id}
            </button>
        </td>
        <td className="glimmr-conv-user">
            {conversation.user_id ? (
                <a href={`user-edit.php?user_id=${conversation.user_id}`} target="_blank" rel="noopener noreferrer">
                    {conversation.user_name || `User #${conversation.user_id}`}
                </a>
            ) : (
                <span className="glimmr-guest">Guest</span>
            )}
        </td>
        <td className="glimmr-conv-messages">
            {conversation.message_count || 0}
        </td>
        <td className="glimmr-conv-status">
            <StatusBadge status={conversation.status} />
        </td>
        <td className="glimmr-conv-date">
            {formatDate(conversation.created_at)}
        </td>
        <td className="glimmr-conv-last">
            {conversation.last_message_at ? formatDate(conversation.last_message_at) : '-'}
        </td>
        <td className="glimmr-conv-actions">
            <Button variant="secondary" isSmall onClick={() => onView(conversation)}>
                View
            </Button>
            {conversation.status !== 'flagged' && (
                <Button variant="link" isSmall onClick={() => onFlag(conversation)}>
                    Flag
                </Button>
            )}
        </td>
    </tr>
);

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
 * Conversation Detail Modal
 */
const ConversationDetail = ({ conversation, messages, onClose, onFlag, loading }) => {
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
                    {conversation.metadata && (
                        <div className="glimmr-meta-item">
                            <strong>Device:</strong> {conversation.metadata.user_agent?.substring(0, 50)}...
                        </div>
                    )}
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
                    {conversation.status !== 'flagged' && (
                        <Button variant="secondary" onClick={() => onFlag(conversation)}>
                            <span className="dashicons dashicons-flag"></span>
                            Flag Issue
                        </Button>
                    )}
                    <Button variant="secondary" onClick={onClose}>
                        Close
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Flag Issue Modal
 */
const FlagIssueModal = ({ conversation, onClose, onSubmit, submitting }) => {
    const [issueType, setIssueType] = useState('');
    const [feedback, setFeedback] = useState('');

    if (!conversation) return null;

    const handleSubmit = () => {
        onSubmit(conversation.conversation_id, issueType, feedback);
    };

    return (
        <Modal
            title="Flag Conversation Issue"
            onRequestClose={onClose}
            className="glimmr-flag-modal"
        >
            <div className="glimmr-flag-form">
                <p>
                    Flag this conversation for review. This helps improve the AI assistant.
                </p>

                <SelectControl
                    label="Issue Type"
                    value={issueType}
                    options={[
                        { value: '', label: 'Select an issue type...' },
                        { value: 'wrong_answer', label: 'Wrong or inaccurate answer' },
                        { value: 'rude', label: 'Inappropriate or rude response' },
                        { value: 'technical', label: 'Technical error or failure' },
                        { value: 'confused', label: 'AI seemed confused' },
                        { value: 'other', label: 'Other' },
                    ]}
                    onChange={setIssueType}
                />

                <TextControl
                    label="Additional Feedback"
                    value={feedback}
                    onChange={setFeedback}
                    placeholder="Describe the issue..."
                />

                <div className="glimmr-modal-actions">
                    <Button variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={handleSubmit}
                        disabled={!issueType || submitting}
                    >
                        {submitting ? 'Submitting...' : 'Submit Flag'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Flagged Issues Tab
 */
const FlaggedIssuesTab = ({ issues, onResolve, loading }) => {
    if (loading) {
        return (
            <div className="glimmr-loading-center">
                <Spinner />
            </div>
        );
    }

    if (issues.length === 0) {
        return (
            <div className="glimmr-empty-state">
                <span className="dashicons dashicons-yes-alt"></span>
                <p>No flagged issues. Great job!</p>
            </div>
        );
    }

    return (
        <div className="glimmr-flagged-list">
            {issues.map((issue) => (
                <Card key={issue.id} className="glimmr-flagged-card">
                    <CardBody>
                        <div className="glimmr-flagged-header">
                            <div>
                                <span className={`glimmr-issue-type glimmr-issue-${issue.issue_type}`}>
                                    {issue.issue_type?.replace('_', ' ')}
                                </span>
                                <span className="glimmr-flagged-date">
                                    {formatDate(issue.created_at)}
                                </span>
                            </div>
                            <StatusBadge status={issue.status} />
                        </div>
                        <div className="glimmr-flagged-content">
                            <p><strong>Conversation:</strong> #{issue.conversation_id?.slice(-8)}</p>
                            {issue.user_feedback && (
                                <p><strong>Feedback:</strong> {issue.user_feedback}</p>
                            )}
                        </div>
                        <div className="glimmr-flagged-actions">
                            <Button
                                variant="secondary"
                                isSmall
                                onClick={() => onResolve(issue.id, 'reviewed')}
                                disabled={issue.status !== 'new'}
                            >
                                Mark Reviewed
                            </Button>
                            <Button
                                variant="primary"
                                isSmall
                                onClick={() => onResolve(issue.id, 'resolved')}
                                disabled={issue.status === 'resolved'}
                            >
                                Resolve
                            </Button>
                        </div>
                    </CardBody>
                </Card>
            ))}
        </div>
    );
};

/**
 * Export Modal Component
 */
const ExportModal = ({ onClose, onExport, exporting }) => {
    const [format, setFormat] = useState('csv');
    const [period, setPeriod] = useState('week');

    return (
        <Modal
            title="Export Conversations"
            onRequestClose={onClose}
            className="glimmr-export-modal"
        >
            <div className="glimmr-export-form">
                <p>Export conversation data for analysis and reporting.</p>

                <SelectControl
                    label="Export Format"
                    value={format}
                    options={[
                        { value: 'csv', label: 'CSV (Spreadsheet)' },
                        { value: 'json', label: 'JSON (Raw Data)' },
                    ]}
                    onChange={setFormat}
                />

                <SelectControl
                    label="Time Period"
                    value={period}
                    options={[
                        { value: 'day', label: 'Today' },
                        { value: 'week', label: 'Last 7 Days' },
                        { value: 'month', label: 'Last 30 Days' },
                        { value: 'all', label: 'All Time' },
                    ]}
                    onChange={setPeriod}
                />

                <div className="glimmr-modal-actions">
                    <Button variant="secondary" onClick={onClose} disabled={exporting}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        onClick={() => onExport(format, period)}
                        disabled={exporting}
                    >
                        {exporting ? 'Exporting...' : 'Export'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Main Conversations Component
 */
const Conversations = () => {
    const [loading, setLoading] = useState(true);
    const [notice, setNotice] = useState(null);
    const [activeTab, setActiveTab] = useState('all');

    // Data state
    const [conversations, setConversations] = useState([]);
    const [flaggedIssues, setFlaggedIssues] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, perPage: 20, total: 0 });

    // Filters
    const [filters, setFilters] = useState({
        status: '',
        search: '',
        dateFrom: '',
        dateTo: '',
    });

    // Modals
    const [viewingConversation, setViewingConversation] = useState(null);
    const [conversationMessages, setConversationMessages] = useState([]);
    const [loadingMessages, setLoadingMessages] = useState(false);
    const [flaggingConversation, setFlaggingConversation] = useState(null);
    const [submittingFlag, setSubmittingFlag] = useState(false);
    const [showExportModal, setShowExportModal] = useState(false);
    const [exporting, setExporting] = useState(false);

    const { ajaxUrl, nonce } = window.glimmrAI || {};

    /**
     * Fetch conversations on mount and filter change.
     */
    useEffect(() => {
        fetchConversations();
    }, [pagination.page, filters]);

    /**
     * Fetch flagged issues.
     */
    useEffect(() => {
        if (activeTab === 'flagged') {
            fetchFlaggedIssues();
        }
    }, [activeTab]);

    /**
     * Fetch conversations list.
     */
    const fetchConversations = async () => {
        setLoading(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_conversations');
            formData.append('nonce', nonce);
            formData.append('page', pagination.page);
            formData.append('per_page', pagination.perPage);

            if (filters.status) formData.append('status', filters.status);
            if (filters.search) formData.append('search', filters.search);
            if (filters.dateFrom) formData.append('date_from', filters.dateFrom);
            if (filters.dateTo) formData.append('date_to', filters.dateTo);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setConversations(result.data.conversations || []);
                setPagination((prev) => ({
                    ...prev,
                    total: result.data.total || 0,
                }));
            }
        } catch (err) {
            console.error('Conversations fetch error:', err);
            setNotice({ type: 'error', message: 'Failed to load conversations.' });
        }

        setLoading(false);
    };

    /**
     * Fetch flagged issues.
     */
    const fetchFlaggedIssues = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_flagged_issues');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setFlaggedIssues(result.data || []);
            }
        } catch (err) {
            console.error('Flagged issues fetch error:', err);
        }
    };

    /**
     * View conversation detail.
     */
    const handleViewConversation = async (conversation) => {
        setViewingConversation(conversation);
        setLoadingMessages(true);

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
     * Flag a conversation.
     */
    const handleFlagConversation = (conversation) => {
        setFlaggingConversation(conversation);
    };

    /**
     * Submit flag.
     */
    const handleSubmitFlag = async (conversationId, issueType, feedback) => {
        setSubmittingFlag(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_flag_conversation');
            formData.append('nonce', nonce);
            formData.append('conversation_id', conversationId);
            formData.append('issue_type', issueType);
            formData.append('feedback', feedback);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Issue flagged successfully.' });
                setFlaggingConversation(null);
                fetchConversations(); // Refresh list
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to flag.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to submit flag.' });
        }

        setSubmittingFlag(false);
    };

    /**
     * Resolve flagged issue.
     */
    const handleResolveIssue = async (issueId, status) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_resolve_issue');
            formData.append('nonce', nonce);
            formData.append('issue_id', issueId);
            formData.append('status', status);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Issue updated.' });
                fetchFlaggedIssues(); // Refresh
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to update issue.' });
        }
    };

    /**
     * Export conversations.
     */
    const handleExport = async (format, period) => {
        setExporting(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_export_conversations');
            formData.append('nonce', nonce);
            formData.append('format', format);
            formData.append('period', period);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                // Create and trigger download
                const data = result.data;
                let blob, filename;

                if (format === 'csv') {
                    blob = new Blob([data.content], { type: 'text/csv;charset=utf-8;' });
                    filename = `glimmr-conversations-${period}-${new Date().toISOString().split('T')[0]}.csv`;
                } else {
                    blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
                    filename = `glimmr-conversations-${period}-${new Date().toISOString().split('T')[0]}.json`;
                }

                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);

                setNotice({ type: 'success', message: `Exported ${data.count || 0} messages successfully.` });
                setShowExportModal(false);
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Export failed.' });
            }
        } catch (err) {
            console.error('Export error:', err);
            setNotice({ type: 'error', message: 'Failed to export conversations.' });
        }

        setExporting(false);
    };

    /**
     * Handle filter change.
     */
    const updateFilter = (key, value) => {
        setFilters((prev) => ({ ...prev, [key]: value }));
        setPagination((prev) => ({ ...prev, page: 1 }));
    };

    const totalPages = Math.ceil(pagination.total / pagination.perPage);

    return (
        <div className="glimmr-conversations">
            {notice && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice(null)}
                >
                    {notice.message}
                </Notice>
            )}

            {/* Tab Navigation */}
            <div className="glimmr-conversations-tabs">
                <button
                    className={`glimmr-tab-button ${activeTab === 'all' ? 'is-active' : ''}`}
                    onClick={() => setActiveTab('all')}
                >
                    <span className="dashicons dashicons-format-chat"></span>
                    All Conversations
                </button>
                <button
                    className={`glimmr-tab-button ${activeTab === 'flagged' ? 'is-active' : ''}`}
                    onClick={() => setActiveTab('flagged')}
                >
                    <span className="dashicons dashicons-flag"></span>
                    Flagged Issues
                    {flaggedIssues.filter((i) => i.status === 'new').length > 0 && (
                        <span className="glimmr-badge">
                            {flaggedIssues.filter((i) => i.status === 'new').length}
                        </span>
                    )}
                </button>
            </div>

            {/* All Conversations Tab */}
            {activeTab === 'all' && (
                <Card className="glimmr-conversations-card">
                    <CardHeader>
                        <div className="glimmr-conversations-filters">
                            <SelectControl
                                value={filters.status}
                                options={[
                                    { value: '', label: 'All Status' },
                                    { value: 'active', label: 'Active' },
                                    { value: 'expired', label: 'Expired' },
                                    { value: 'flagged', label: 'Flagged' },
                                ]}
                                onChange={(value) => updateFilter('status', value)}
                            />
                            <TextControl
                                placeholder="Search..."
                                value={filters.search}
                                onChange={(value) => updateFilter('search', value)}
                            />
                            <TextControl
                                type="date"
                                value={filters.dateFrom}
                                onChange={(value) => updateFilter('dateFrom', value)}
                                placeholder="From date"
                            />
                            <TextControl
                                type="date"
                                value={filters.dateTo}
                                onChange={(value) => updateFilter('dateTo', value)}
                                placeholder="To date"
                            />
                        </div>
                        <Button variant="secondary" onClick={() => setShowExportModal(true)}>
                            <span className="dashicons dashicons-download"></span>
                            Export
                        </Button>
                    </CardHeader>
                    <CardBody>
                        {loading ? (
                            <div className="glimmr-loading-center">
                                <Spinner />
                            </div>
                        ) : conversations.length === 0 ? (
                            <div className="glimmr-empty-state">
                                <span className="dashicons dashicons-format-chat"></span>
                                <p>No conversations found.</p>
                            </div>
                        ) : (
                            <>
                                <table className="glimmr-conversations-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">ID</th>
                                            <th scope="col">Customer</th>
                                            <th scope="col">Messages</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Started</th>
                                            <th scope="col">Last Message</th>
                                            <th scope="col">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {conversations.map((conv) => (
                                            <ConversationRow
                                                key={conv.id}
                                                conversation={conv}
                                                onView={handleViewConversation}
                                                onFlag={handleFlagConversation}
                                            />
                                        ))}
                                    </tbody>
                                </table>

                                {/* Pagination */}
                                {totalPages > 1 && (
                                    <div className="glimmr-pagination">
                                        <Button
                                            variant="secondary"
                                            disabled={pagination.page <= 1}
                                            onClick={() => setPagination((p) => ({ ...p, page: p.page - 1 }))}
                                        >
                                            Previous
                                        </Button>
                                        <span className="glimmr-pagination-info">
                                            Page {pagination.page} of {totalPages}
                                        </span>
                                        <Button
                                            variant="secondary"
                                            disabled={pagination.page >= totalPages}
                                            onClick={() => setPagination((p) => ({ ...p, page: p.page + 1 }))}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </CardBody>
                </Card>
            )}

            {/* Flagged Issues Tab */}
            {activeTab === 'flagged' && (
                <Card className="glimmr-flagged-card">
                    <CardHeader>
                        <h3>Flagged Issues</h3>
                    </CardHeader>
                    <CardBody>
                        <FlaggedIssuesTab
                            issues={flaggedIssues}
                            onResolve={handleResolveIssue}
                            loading={loading}
                        />
                    </CardBody>
                </Card>
            )}

            {/* Conversation Detail Modal */}
            {viewingConversation && (
                <ConversationDetail
                    conversation={viewingConversation}
                    messages={conversationMessages}
                    onClose={() => setViewingConversation(null)}
                    onFlag={handleFlagConversation}
                    loading={loadingMessages}
                />
            )}

            {/* Flag Issue Modal */}
            {flaggingConversation && (
                <FlagIssueModal
                    conversation={flaggingConversation}
                    onClose={() => setFlaggingConversation(null)}
                    onSubmit={handleSubmitFlag}
                    submitting={submittingFlag}
                />
            )}

            {/* Export Modal */}
            {showExportModal && (
                <ExportModal
                    onClose={() => setShowExportModal(false)}
                    onExport={handleExport}
                    exporting={exporting}
                />
            )}
        </div>
    );
};

export default Conversations;
