/**
 * Contact Requests Admin Page
 *
 * Displays and manages customer contact requests submitted via the chat widget.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

const { useState, useEffect, useCallback } = wp.element;
const { Button, SelectControl, TextControl, Modal, Spinner, Notice } = wp.components;

/**
 * Status badge component.
 */
const StatusBadge = ({ status, statusInfo }) => {
    const styles = {
        display: 'inline-flex',
        alignItems: 'center',
        gap: '6px',
        padding: '4px 10px',
        borderRadius: '12px',
        fontSize: '12px',
        fontWeight: '500',
        backgroundColor: statusInfo?.color ? `${statusInfo.color}20` : '#e9ecef',
        color: statusInfo?.color || '#6c757d',
    };

    return (
        <span style={styles}>
            <span style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                backgroundColor: statusInfo?.color || '#6c757d',
            }} />
            {statusInfo?.name || status}
        </span>
    );
};

/**
 * Priority badge component.
 */
const PriorityBadge = ({ priority, priorityInfo }) => {
    const styles = {
        display: 'inline-block',
        padding: '3px 8px',
        borderRadius: '4px',
        fontSize: '11px',
        fontWeight: '600',
        backgroundColor: priorityInfo?.color || '#17a2b8',
        color: '#fff',
        textTransform: 'uppercase',
    };

    return (
        <span style={styles}>
            {priorityInfo?.name || priority}
        </span>
    );
};

/**
 * Category badge component.
 */
const CategoryBadge = ({ category }) => {
    const categoryNames = {
        general: 'General',
        order_issue: 'Order Issue',
        product_question: 'Product',
        return_exchange: 'Return',
        shipping: 'Shipping',
        billing: 'Billing',
        feedback: 'Feedback',
        other: 'Other',
    };

    return (
        <span style={{
            display: 'inline-block',
            padding: '2px 8px',
            borderRadius: '4px',
            fontSize: '11px',
            backgroundColor: '#f0f0f0',
            color: '#555',
        }}>
            {categoryNames[category] || category}
        </span>
    );
};

/**
 * Request detail modal component.
 */
const RequestDetailModal = ({ request, onClose, onUpdate, onReply }) => {
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [showReplyModal, setShowReplyModal] = useState(false);
    const [updating, setUpdating] = useState(false);

    useEffect(() => {
        if (request) {
            fetchDetail();
        }
    }, [request?.request_id]);

    const fetchDetail = async () => {
        setLoading(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_contact_request_detail');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('request_id', request.request_id);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                setDetail(data.data);
            } else {
                setError(data.data?.message || 'Failed to load request details.');
            }
        } catch (err) {
            setError('Failed to load request details.');
        } finally {
            setLoading(false);
        }
    };

    const handleStatusChange = async (newStatus) => {
        setUpdating(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_update_contact_request');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('request_id', request.request_id);
            formData.append('status', newStatus);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                fetchDetail();
                onUpdate?.();
            }
        } finally {
            setUpdating(false);
        }
    };

    const handlePriorityChange = async (newPriority) => {
        setUpdating(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_update_contact_request');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('request_id', request.request_id);
            formData.append('priority', newPriority);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                fetchDetail();
                onUpdate?.();
            }
        } finally {
            setUpdating(false);
        }
    };

    const handleAssignmentChange = async (newAssignee) => {
        setUpdating(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_update_contact_request');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('request_id', request.request_id);
            formData.append('assigned_to', newAssignee);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                fetchDetail();
                onUpdate?.();
            }
        } finally {
            setUpdating(false);
        }
    };

    if (!request) return null;

    return (
        <Modal
            title={`Contact Request ${request.request_id}`}
            onRequestClose={onClose}
            className="glimmr-ai-contact-detail-modal"
            style={{ maxWidth: '800px', width: '90%' }}
        >
            {loading ? (
                <div style={{ padding: '40px', textAlign: 'center' }}>
                    <Spinner />
                    <p>Loading request details...</p>
                </div>
            ) : error ? (
                <Notice status="error" isDismissible={false}>{error}</Notice>
            ) : detail ? (
                <div className="contact-detail-content">
                    {/* Status Bar */}
                    <div style={{
                        display: 'flex',
                        gap: '16px',
                        padding: '16px',
                        backgroundColor: '#f8f9fa',
                        borderRadius: '6px',
                        marginBottom: '20px',
                        flexWrap: 'wrap',
                    }}>
                        <SelectControl
                            label="Status"
                            value={detail.request.status}
                            options={[
                                { label: 'New', value: 'new' },
                                { label: 'In Progress', value: 'in_progress' },
                                { label: 'Resolved', value: 'resolved' },
                            ]}
                            onChange={handleStatusChange}
                            disabled={updating}
                            __nextHasNoMarginBottom
                        />
                        <SelectControl
                            label="Priority"
                            value={detail.request.priority}
                            options={[
                                { label: 'Low', value: 'low' },
                                { label: 'Normal', value: 'normal' },
                                { label: 'High', value: 'high' },
                                { label: 'Urgent', value: 'urgent' },
                            ]}
                            onChange={handlePriorityChange}
                            disabled={updating}
                            __nextHasNoMarginBottom
                        />
                        <SelectControl
                            label="Assigned To"
                            value={detail.request.assigned_to || ''}
                            options={[
                                { label: 'Unassigned', value: '' },
                                ...(detail.admins || []).map(admin => ({
                                    label: admin.display_name,
                                    value: admin.ID.toString(),
                                })),
                            ]}
                            onChange={handleAssignmentChange}
                            disabled={updating}
                            __nextHasNoMarginBottom
                        />
                    </div>

                    {/* Customer Info */}
                    <div style={{ marginBottom: '20px' }}>
                        <h3 style={{ fontSize: '14px', color: '#6c757d', margin: '0 0 10px 0', textTransform: 'uppercase' }}>
                            Customer
                        </h3>
                        <p style={{ margin: 0, fontSize: '15px' }}>
                            <strong>{detail.request.name}</strong>
                            {' '}&bull;{' '}
                            <a href={`mailto:${detail.request.email}`}>{detail.request.email}</a>
                            {detail.request.phone && (
                                <>{' '}&bull;{' '}{detail.request.phone}</>
                            )}
                        </p>
                    </div>

                    {/* Request Details */}
                    <div style={{
                        backgroundColor: '#fff',
                        border: '1px solid #e0e0e0',
                        borderRadius: '6px',
                        padding: '20px',
                        marginBottom: '20px',
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }}>
                            <h3 style={{ margin: 0, fontSize: '14px', color: '#6c757d', textTransform: 'uppercase' }}>
                                Request Details
                            </h3>
                            <span style={{ color: '#6c757d', fontSize: '13px' }}>
                                {new Date(detail.request.created_at).toLocaleString()}
                            </span>
                        </div>
                        <div style={{ marginBottom: '12px' }}>
                            <CategoryBadge category={detail.request.category} />
                        </div>
                        <p style={{ fontWeight: '600', marginBottom: '10px' }}>
                            {detail.request.subject}
                        </p>
                        <div style={{
                            backgroundColor: '#f8f9fa',
                            padding: '15px',
                            borderRadius: '4px',
                            whiteSpace: 'pre-wrap',
                        }}>
                            {detail.request.message}
                        </div>
                    </div>

                    {/* Related Info */}
                    {(detail.order_info || detail.product_info) && (
                        <div style={{
                            display: 'flex',
                            gap: '16px',
                            marginBottom: '20px',
                            flexWrap: 'wrap',
                        }}>
                            {detail.order_info && (
                                <div style={{
                                    flex: '1',
                                    minWidth: '200px',
                                    padding: '12px',
                                    backgroundColor: '#f8f9fa',
                                    borderRadius: '6px',
                                }}>
                                    <h4 style={{ margin: '0 0 8px 0', fontSize: '12px', color: '#6c757d' }}>
                                        Related Order
                                    </h4>
                                    <a href={detail.order_info.url} target="_blank" rel="noopener noreferrer">
                                        #{detail.order_info.number}
                                    </a>
                                    {' '}&bull;{' '}{detail.order_info.status}
                                    {' '}&bull;{' '}<span dangerouslySetInnerHTML={{ __html: detail.order_info.total }} />
                                </div>
                            )}
                            {detail.product_info && (
                                <div style={{
                                    flex: '1',
                                    minWidth: '200px',
                                    padding: '12px',
                                    backgroundColor: '#f8f9fa',
                                    borderRadius: '6px',
                                }}>
                                    <h4 style={{ margin: '0 0 8px 0', fontSize: '12px', color: '#6c757d' }}>
                                        Related Product
                                    </h4>
                                    <a href={detail.product_info.url} target="_blank" rel="noopener noreferrer">
                                        {detail.product_info.name}
                                    </a>
                                    {detail.product_info.sku && (
                                        <span style={{ color: '#6c757d' }}> (SKU: {detail.product_info.sku})</span>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Conversation Context */}
                    {detail.conversation_messages && detail.conversation_messages.length > 0 && (
                        <details style={{ marginBottom: '20px' }}>
                            <summary style={{
                                cursor: 'pointer',
                                padding: '12px',
                                backgroundColor: '#f8f9fa',
                                borderRadius: '6px',
                                fontWeight: '500',
                            }}>
                                Conversation Context ({detail.conversation_messages.length} messages)
                            </summary>
                            <div style={{
                                maxHeight: '300px',
                                overflowY: 'auto',
                                border: '1px solid #e0e0e0',
                                borderRadius: '0 0 6px 6px',
                                padding: '12px',
                            }}>
                                {detail.conversation_messages.map((msg, idx) => (
                                    <div key={idx} style={{
                                        padding: '8px 12px',
                                        marginBottom: '8px',
                                        backgroundColor: msg.role === 'user' ? '#e7f3ff' : '#f0f0f0',
                                        borderRadius: '8px',
                                        fontSize: '13px',
                                    }}>
                                        <strong>{msg.role === 'user' ? 'Customer' : 'Assistant'}:</strong>
                                        <div style={{ marginTop: '4px' }}>{msg.content}</div>
                                    </div>
                                ))}
                            </div>
                        </details>
                    )}

                    {/* Previous Responses */}
                    {detail.responses && detail.responses.length > 0 && (
                        <div style={{ marginBottom: '20px' }}>
                            <h3 style={{ fontSize: '14px', color: '#6c757d', margin: '0 0 12px 0', textTransform: 'uppercase' }}>
                                Response History
                            </h3>
                            {detail.responses.map((response, idx) => (
                                <div key={idx} style={{
                                    padding: '16px',
                                    backgroundColor: '#e8f5e9',
                                    borderLeft: '4px solid #4caf50',
                                    borderRadius: '4px',
                                    marginBottom: '12px',
                                }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px', fontSize: '12px', color: '#6c757d' }}>
                                        <span>{response.admin_name}</span>
                                        <span>{new Date(response.created_at).toLocaleString()}</span>
                                    </div>
                                    <div style={{ whiteSpace: 'pre-wrap' }}>{response.response_text}</div>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Reply Button */}
                    <div style={{ textAlign: 'center', paddingTop: '10px' }}>
                        <Button
                            variant="primary"
                            onClick={() => setShowReplyModal(true)}
                            style={{ minWidth: '200px' }}
                        >
                            Reply to Customer
                        </Button>
                    </div>
                </div>
            ) : null}

            {/* Reply Modal */}
            {showReplyModal && (
                <ResponseModal
                    request={detail?.request}
                    onClose={() => setShowReplyModal(false)}
                    onSuccess={() => {
                        setShowReplyModal(false);
                        fetchDetail();
                        onUpdate?.();
                    }}
                />
            )}
        </Modal>
    );
};

/**
 * Response modal component.
 */
const ResponseModal = ({ request, onClose, onSuccess }) => {
    const [responseText, setResponseText] = useState('');
    const [updateStatus, setUpdateStatus] = useState('in_progress');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState(null);

    const handleSend = async () => {
        if (!responseText.trim()) {
            setError('Please enter a response message.');
            return;
        }

        setSending(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_send_contact_response');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('request_id', request.request_id);
            formData.append('response_text', responseText);
            formData.append('update_status', updateStatus);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                onSuccess?.();
            } else {
                setError(data.data?.message || 'Failed to send response.');
            }
        } catch (err) {
            setError('Failed to send response. Please try again.');
        } finally {
            setSending(false);
        }
    };

    return (
        <Modal
            title={`Reply to ${request.name}`}
            onRequestClose={onClose}
            className="glimmr-ai-response-modal"
            style={{ maxWidth: '600px', width: '90%' }}
        >
            <div style={{ padding: '10px 0' }}>
                {error && (
                    <Notice status="error" isDismissible={false} style={{ marginBottom: '16px' }}>
                        {error}
                    </Notice>
                )}

                <textarea
                    value={responseText}
                    onChange={(e) => setResponseText(e.target.value)}
                    placeholder="Type your response to the customer..."
                    style={{
                        width: '100%',
                        minHeight: '200px',
                        padding: '12px',
                        border: '1px solid #ddd',
                        borderRadius: '4px',
                        resize: 'vertical',
                        fontSize: '14px',
                    }}
                    disabled={sending}
                />

                <div style={{ marginTop: '16px' }}>
                    <SelectControl
                        label="Update status to"
                        value={updateStatus}
                        options={[
                            { label: 'In Progress', value: 'in_progress' },
                            { label: 'Resolved', value: 'resolved' },
                        ]}
                        onChange={setUpdateStatus}
                        disabled={sending}
                        __nextHasNoMarginBottom
                    />
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '12px', marginTop: '20px' }}>
                    <Button variant="tertiary" onClick={onClose} disabled={sending}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={handleSend} isBusy={sending} disabled={sending}>
                        {sending ? 'Sending...' : 'Send Email Response'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Export modal component.
 */
const ExportModal = ({ onClose }) => {
    const [format, setFormat] = useState('csv');
    const [period, setPeriod] = useState('all');
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState(null);

    const handleExport = async () => {
        setExporting(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_export_contact_requests');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('format', format);
            formData.append('period', period);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                // Trigger download.
                const blob = new Blob([data.data.data], { type: data.data.mime_type });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = data.data.filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                a.remove();
                onClose();
            } else {
                setError(data.data?.message || 'Export failed.');
            }
        } catch (err) {
            setError('Export failed. Please try again.');
        } finally {
            setExporting(false);
        }
    };

    return (
        <Modal
            title="Export Contact Requests"
            onRequestClose={onClose}
            style={{ maxWidth: '400px' }}
        >
            <div style={{ padding: '10px 0' }}>
                {error && (
                    <Notice status="error" isDismissible={false} style={{ marginBottom: '16px' }}>
                        {error}
                    </Notice>
                )}

                <SelectControl
                    label="Format"
                    value={format}
                    options={[
                        { label: 'CSV', value: 'csv' },
                        { label: 'JSON', value: 'json' },
                    ]}
                    onChange={setFormat}
                    disabled={exporting}
                    __nextHasNoMarginBottom
                />

                <SelectControl
                    label="Period"
                    value={period}
                    options={[
                        { label: 'Today', value: 'day' },
                        { label: 'Last 7 Days', value: 'week' },
                        { label: 'Last 30 Days', value: 'month' },
                        { label: 'All Time', value: 'all' },
                    ]}
                    onChange={setPeriod}
                    disabled={exporting}
                    style={{ marginTop: '16px' }}
                    __nextHasNoMarginBottom
                />

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '12px', marginTop: '24px' }}>
                    <Button variant="tertiary" onClick={onClose} disabled={exporting}>
                        Cancel
                    </Button>
                    <Button variant="primary" onClick={handleExport} isBusy={exporting} disabled={exporting}>
                        {exporting ? 'Exporting...' : 'Export'}
                    </Button>
                </div>
            </div>
        </Modal>
    );
};

/**
 * Main ContactRequests component.
 */
const ContactRequests = () => {
    const [requests, setRequests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [stats, setStats] = useState(null);

    // Pagination.
    const [page, setPage] = useState(1);
    const [perPage] = useState(20);
    const [totalPages, setTotalPages] = useState(1);
    const [total, setTotal] = useState(0);

    // Filters.
    const [status, setStatus] = useState('');
    const [category, setCategory] = useState('');
    const [priority, setPriority] = useState('');
    const [search, setSearch] = useState('');

    // Modals.
    const [selectedRequest, setSelectedRequest] = useState(null);
    const [showExportModal, setShowExportModal] = useState(false);

    const fetchRequests = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_contact_requests');
            formData.append('nonce', glimmrAI.nonce);
            formData.append('page', page);
            formData.append('per_page', perPage);
            formData.append('status', status);
            formData.append('category', category);
            formData.append('priority', priority);
            formData.append('search', search);

            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                setRequests(data.data.requests);
                setTotal(data.data.total);
                setTotalPages(data.data.total_pages);
                setStats(data.data.stats);
            } else {
                setError(data.data?.message || 'Failed to load contact requests.');
            }
        } catch (err) {
            setError('Failed to load contact requests.');
        } finally {
            setLoading(false);
        }
    }, [page, perPage, status, category, priority, search]);

    useEffect(() => {
        fetchRequests();
    }, [fetchRequests]);

    // Reset page when filters change.
    useEffect(() => {
        setPage(1);
    }, [status, category, priority, search]);

    const statusInfo = {
        new: { name: 'New', color: '#dc3545' },
        in_progress: { name: 'In Progress', color: '#ffc107' },
        resolved: { name: 'Resolved', color: '#28a745' },
    };

    const priorityInfo = {
        low: { name: 'Low', color: '#28a745' },
        normal: { name: 'Normal', color: '#17a2b8' },
        high: { name: 'High', color: '#ffc107' },
        urgent: { name: 'Urgent', color: '#dc3545' },
    };

    return (
        <div className="glimmr-ai-contact-requests">
            {/* Stats Cards */}
            {stats && (
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
                    gap: '16px',
                    marginBottom: '24px',
                }}>
                    <div style={{
                        padding: '20px',
                        backgroundColor: '#fff',
                        borderRadius: '8px',
                        border: '1px solid #e0e0e0',
                        textAlign: 'center',
                    }}>
                        <div style={{ fontSize: '32px', fontWeight: '700', color: '#333' }}>{stats.total}</div>
                        <div style={{ fontSize: '13px', color: '#6c757d' }}>Total Requests</div>
                    </div>
                    <div style={{
                        padding: '20px',
                        backgroundColor: '#fff',
                        borderRadius: '8px',
                        border: '1px solid #e0e0e0',
                        textAlign: 'center',
                    }}>
                        <div style={{ fontSize: '32px', fontWeight: '700', color: '#dc3545' }}>{stats.new}</div>
                        <div style={{ fontSize: '13px', color: '#6c757d' }}>New</div>
                    </div>
                    <div style={{
                        padding: '20px',
                        backgroundColor: '#fff',
                        borderRadius: '8px',
                        border: '1px solid #e0e0e0',
                        textAlign: 'center',
                    }}>
                        <div style={{ fontSize: '32px', fontWeight: '700', color: '#ffc107' }}>{stats.in_progress}</div>
                        <div style={{ fontSize: '13px', color: '#6c757d' }}>In Progress</div>
                    </div>
                    <div style={{
                        padding: '20px',
                        backgroundColor: '#fff',
                        borderRadius: '8px',
                        border: '1px solid #e0e0e0',
                        textAlign: 'center',
                    }}>
                        <div style={{ fontSize: '32px', fontWeight: '700', color: '#28a745' }}>{stats.resolved}</div>
                        <div style={{ fontSize: '13px', color: '#6c757d' }}>Resolved</div>
                    </div>
                </div>
            )}

            {/* Filters */}
            <div style={{
                display: 'flex',
                flexWrap: 'wrap',
                gap: '12px',
                alignItems: 'flex-end',
                marginBottom: '20px',
                padding: '16px',
                backgroundColor: '#fff',
                borderRadius: '8px',
                border: '1px solid #e0e0e0',
            }}>
                <SelectControl
                    label="Status"
                    value={status}
                    options={[
                        { label: 'All Statuses', value: '' },
                        { label: 'New', value: 'new' },
                        { label: 'In Progress', value: 'in_progress' },
                        { label: 'Resolved', value: 'resolved' },
                    ]}
                    onChange={setStatus}
                    __nextHasNoMarginBottom
                />
                <SelectControl
                    label="Category"
                    value={category}
                    options={[
                        { label: 'All Categories', value: '' },
                        { label: 'General', value: 'general' },
                        { label: 'Order Issue', value: 'order_issue' },
                        { label: 'Product Question', value: 'product_question' },
                        { label: 'Return/Exchange', value: 'return_exchange' },
                        { label: 'Shipping', value: 'shipping' },
                        { label: 'Billing', value: 'billing' },
                        { label: 'Feedback', value: 'feedback' },
                        { label: 'Other', value: 'other' },
                    ]}
                    onChange={setCategory}
                    __nextHasNoMarginBottom
                />
                <SelectControl
                    label="Priority"
                    value={priority}
                    options={[
                        { label: 'All Priorities', value: '' },
                        { label: 'Low', value: 'low' },
                        { label: 'Normal', value: 'normal' },
                        { label: 'High', value: 'high' },
                        { label: 'Urgent', value: 'urgent' },
                    ]}
                    onChange={setPriority}
                    __nextHasNoMarginBottom
                />
                <TextControl
                    label="Search"
                    value={search}
                    onChange={setSearch}
                    placeholder="Search by name, email, subject..."
                    __nextHasNoMarginBottom
                />
                <div style={{ marginLeft: 'auto' }}>
                    <Button variant="secondary" onClick={() => setShowExportModal(true)}>
                        Export
                    </Button>
                </div>
            </div>

            {/* Error Message */}
            {error && (
                <Notice status="error" isDismissible={false} style={{ marginBottom: '20px' }}>
                    {error}
                </Notice>
            )}

            {/* Loading State */}
            {loading ? (
                <div style={{ padding: '60px', textAlign: 'center' }}>
                    <Spinner />
                    <p>Loading contact requests...</p>
                </div>
            ) : requests.length === 0 ? (
                <div style={{
                    padding: '60px',
                    textAlign: 'center',
                    backgroundColor: '#fff',
                    borderRadius: '8px',
                    border: '1px solid #e0e0e0',
                }}>
                    <p style={{ color: '#6c757d', fontSize: '16px' }}>
                        No contact requests found.
                    </p>
                </div>
            ) : (
                <>
                    {/* Requests Table */}
                    <div style={{
                        backgroundColor: '#fff',
                        borderRadius: '8px',
                        border: '1px solid #e0e0e0',
                        overflow: 'hidden',
                    }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                            <thead>
                                <tr style={{ backgroundColor: '#f8f9fa', borderBottom: '2px solid #e0e0e0' }}>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Reference</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Customer</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Subject</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Category</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Priority</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Status</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'left', fontWeight: '600', fontSize: '13px' }}>Created</th>
                                    <th style={{ padding: '12px 16px', textAlign: 'center', fontWeight: '600', fontSize: '13px' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((request) => (
                                    <tr
                                        key={request.id}
                                        style={{
                                            borderBottom: '1px solid #e0e0e0',
                                            cursor: 'pointer',
                                            transition: 'background-color 0.15s',
                                        }}
                                        onMouseEnter={(e) => e.currentTarget.style.backgroundColor = '#f8f9fa'}
                                        onMouseLeave={(e) => e.currentTarget.style.backgroundColor = 'transparent'}
                                        onClick={() => setSelectedRequest(request)}
                                    >
                                        <td style={{ padding: '12px 16px', fontFamily: 'monospace', fontSize: '13px' }}>
                                            {request.request_id}
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <div style={{ fontWeight: '500' }}>{request.name}</div>
                                            <div style={{ fontSize: '12px', color: '#6c757d' }}>{request.email}</div>
                                        </td>
                                        <td style={{ padding: '12px 16px', maxWidth: '200px' }}>
                                            <div style={{
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}>
                                                {request.subject}
                                            </div>
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <CategoryBadge category={request.category} />
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <PriorityBadge
                                                priority={request.priority}
                                                priorityInfo={priorityInfo[request.priority]}
                                            />
                                        </td>
                                        <td style={{ padding: '12px 16px' }}>
                                            <StatusBadge
                                                status={request.status}
                                                statusInfo={statusInfo[request.status]}
                                            />
                                        </td>
                                        <td style={{ padding: '12px 16px', fontSize: '13px', color: '#6c757d' }}>
                                            {request.created_at_formatted}
                                        </td>
                                        <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                                            <Button
                                                variant="secondary"
                                                isSmall
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setSelectedRequest(request);
                                                }}
                                            >
                                                View
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {totalPages > 1 && (
                        <div style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            marginTop: '20px',
                            padding: '16px',
                            backgroundColor: '#fff',
                            borderRadius: '8px',
                            border: '1px solid #e0e0e0',
                        }}>
                            <span style={{ color: '#6c757d', fontSize: '14px' }}>
                                Showing {((page - 1) * perPage) + 1} to {Math.min(page * perPage, total)} of {total} requests
                            </span>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <Button
                                    variant="secondary"
                                    disabled={page === 1}
                                    onClick={() => setPage(p => p - 1)}
                                >
                                    Previous
                                </Button>
                                <span style={{ padding: '8px 16px', color: '#6c757d' }}>
                                    Page {page} of {totalPages}
                                </span>
                                <Button
                                    variant="secondary"
                                    disabled={page === totalPages}
                                    onClick={() => setPage(p => p + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* Request Detail Modal */}
            {selectedRequest && (
                <RequestDetailModal
                    request={selectedRequest}
                    onClose={() => setSelectedRequest(null)}
                    onUpdate={fetchRequests}
                />
            )}

            {/* Export Modal */}
            {showExportModal && (
                <ExportModal onClose={() => setShowExportModal(false)} />
            )}
        </div>
    );
};

export default ContactRequests;
