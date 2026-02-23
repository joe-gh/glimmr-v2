/**
 * Knowledge Manager Component
 *
 * Manage content indexed for AI knowledge retrieval.
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
    TextControl,
    TextareaControl,
    ToggleControl,
    SelectControl,
    Modal,
    CheckboxControl,
} = wp.components;

/**
 * Content type tabs
 */
const CONTENT_TYPES = [
    { name: 'pages', title: 'Pages', icon: 'admin-page' },
    { name: 'posts', title: 'Posts', icon: 'admin-post' },
    { name: 'products', title: 'Products', icon: 'cart' },
    { name: 'custom', title: 'Custom Content', icon: 'edit' },
];

/**
 * Sync Status Badge
 */
const SyncStatus = ({ status, lastSynced }) => {
    const statusColors = {
        synced: 'green',
        pending: 'orange',
        error: 'red',
    };

    const statusLabels = {
        synced: 'Synced',
        pending: 'Pending Sync',
        error: 'Sync Error',
    };

    return (
        <div className="glimmr-sync-status">
            <span className={`glimmr-sync-badge glimmr-sync-${status}`}>
                {statusLabels[status] || 'Unknown'}
            </span>
            {lastSynced && (
                <span className="glimmr-sync-time">
                    Last synced: {new Date(lastSynced).toLocaleDateString()}
                </span>
            )}
        </div>
    );
};

/**
 * Content Item Row
 */
const ContentItem = ({ item, onToggle, onSync }) => (
    <div className={`glimmr-content-item ${item.included ? 'is-included' : ''}`}>
        <div className="glimmr-content-item-check">
            <CheckboxControl
                checked={item.included}
                onChange={() => onToggle(item.id)}
            />
        </div>
        <div className="glimmr-content-item-info">
            <div className="glimmr-content-item-title">
                {item.title}
                {item.source_type && (
                    <span className="glimmr-content-type-badge">{item.source_type}</span>
                )}
            </div>
            {item.excerpt && (
                <div className="glimmr-content-item-excerpt">{item.excerpt}</div>
            )}
        </div>
        <div className="glimmr-content-item-status">
            {item.included && (
                <SyncStatus status={item.sync_status} lastSynced={item.last_synced_at} />
            )}
        </div>
        <div className="glimmr-content-item-actions">
            {item.edit_url && (
                <a
                    href={item.edit_url}
                    className="button button-small"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Edit
                </a>
            )}
            {item.included && item.sync_status !== 'synced' && (
                <Button
                    variant="secondary"
                    isSmall
                    onClick={() => onSync(item.id)}
                >
                    Sync
                </Button>
            )}
        </div>
    </div>
);

/**
 * Pages Tab Content
 */
const PagesTab = ({ pages, onToggle, onSync, onSelectAll, loading }) => {
    const [search, setSearch] = useState('');

    const filteredPages = pages.filter((page) =>
        page.title.toLowerCase().includes(search.toLowerCase())
    );

    const includedCount = pages.filter((p) => p.included).length;

    if (loading) {
        return (
            <div className="glimmr-loading-center">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="glimmr-content-tab">
            <div className="glimmr-content-toolbar">
                <TextControl
                    placeholder="Search pages..."
                    value={search}
                    onChange={setSearch}
                    className="glimmr-search-input"
                />
                <div className="glimmr-content-stats">
                    {includedCount} of {pages.length} pages included
                </div>
                <Button variant="secondary" onClick={() => onSelectAll('pages', true)}>
                    Select All
                </Button>
                <Button variant="secondary" onClick={() => onSelectAll('pages', false)}>
                    Deselect All
                </Button>
            </div>

            <div className="glimmr-content-list">
                {filteredPages.length === 0 ? (
                    <div className="glimmr-empty-state">
                        <p>No pages found.</p>
                    </div>
                ) : (
                    filteredPages.map((page) => (
                        <ContentItem
                            key={page.id}
                            item={page}
                            onToggle={onToggle}
                            onSync={onSync}
                        />
                    ))
                )}
            </div>
        </div>
    );
};

/**
 * Posts Tab Content
 */
const PostsTab = ({ posts, postTypes, selectedType, onTypeChange, onToggle, onSync, loading }) => {
    const [search, setSearch] = useState('');

    const filteredPosts = posts.filter((post) =>
        post.title.toLowerCase().includes(search.toLowerCase())
    );

    const includedCount = posts.filter((p) => p.included).length;

    if (loading) {
        return (
            <div className="glimmr-loading-center">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="glimmr-content-tab">
            <div className="glimmr-content-toolbar">
                <SelectControl
                    value={selectedType}
                    options={[
                        { value: 'post', label: 'Blog Posts' },
                        ...postTypes.map((pt) => ({ value: pt.name, label: pt.label })),
                    ]}
                    onChange={onTypeChange}
                />
                <TextControl
                    placeholder="Search posts..."
                    value={search}
                    onChange={setSearch}
                    className="glimmr-search-input"
                />
                <div className="glimmr-content-stats">
                    {includedCount} of {posts.length} included
                </div>
            </div>

            <div className="glimmr-content-list">
                {filteredPosts.length === 0 ? (
                    <div className="glimmr-empty-state">
                        <p>No posts found.</p>
                    </div>
                ) : (
                    filteredPosts.map((post) => (
                        <ContentItem
                            key={post.id}
                            item={post}
                            onToggle={onToggle}
                            onSync={onSync}
                        />
                    ))
                )}
            </div>
        </div>
    );
};

/**
 * Custom Content Tab
 */
const CustomContentTab = ({ items, onAdd, onEdit, onDelete, onSync }) => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState(null);
    const [formData, setFormData] = useState({ title: '', content: '' });

    const openAddModal = () => {
        setEditingItem(null);
        setFormData({ title: '', content: '' });
        setIsModalOpen(true);
    };

    const openEditModal = (item) => {
        setEditingItem(item);
        setFormData({ title: item.title, content: item.content });
        setIsModalOpen(true);
    };

    const handleSave = () => {
        if (editingItem) {
            onEdit(editingItem.id, formData);
        } else {
            onAdd(formData);
        }
        setIsModalOpen(false);
    };

    return (
        <div className="glimmr-content-tab">
            <div className="glimmr-content-toolbar">
                <Button variant="primary" onClick={openAddModal}>
                    <span className="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    Add Custom Content
                </Button>
            </div>

            <div className="glimmr-content-list">
                {items.length === 0 ? (
                    <div className="glimmr-empty-state">
                        <span className="dashicons dashicons-edit" aria-hidden="true"></span>
                        <p>No custom content yet.</p>
                        <p className="description">
                            Add custom knowledge entries like FAQs, policies, or store information.
                        </p>
                    </div>
                ) : (
                    items.map((item) => (
                        <div key={item.id} className="glimmr-content-item is-included">
                            <div className="glimmr-content-item-info">
                                <div className="glimmr-content-item-title">{item.title}</div>
                                <div className="glimmr-content-item-excerpt">
                                    {item.content.substring(0, 150)}...
                                </div>
                            </div>
                            <div className="glimmr-content-item-status">
                                <SyncStatus status={item.sync_status} lastSynced={item.last_synced_at} />
                            </div>
                            <div className="glimmr-content-item-actions">
                                <Button variant="secondary" isSmall onClick={() => openEditModal(item)}>
                                    Edit
                                </Button>
                                <Button variant="secondary" isSmall onClick={() => onSync(item.id)}>
                                    Sync
                                </Button>
                                <Button variant="link" isDestructive isSmall onClick={() => onDelete(item.id)}>
                                    Delete
                                </Button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {isModalOpen && (
                <Modal
                    title={editingItem ? 'Edit Custom Content' : 'Add Custom Content'}
                    onRequestClose={() => setIsModalOpen(false)}
                    className="glimmr-custom-content-modal"
                >
                    <TextControl
                        label="Title"
                        value={formData.title}
                        onChange={(value) => setFormData({ ...formData, title: value })}
                        placeholder="e.g., Shipping Policy, Return FAQ"
                    />
                    <TextareaControl
                        label="Content"
                        value={formData.content}
                        onChange={(value) => setFormData({ ...formData, content: value })}
                        rows={10}
                        help="Enter the content the AI should know about. Plain text or markdown."
                    />
                    <div className="glimmr-modal-actions">
                        <Button variant="secondary" onClick={() => setIsModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            onClick={handleSave}
                            disabled={!formData.title || !formData.content}
                        >
                            {editingItem ? 'Update' : 'Add'} Content
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
};

/**
 * Progress Bar Component - Clean, minimal design
 */
const ProgressBar = ({ current = 0, total = 0, label, status }) => {
    const safeTotal = total || 0;
    const safeCurrent = current || 0;
    const percentage = safeTotal > 0 ? Math.round((safeCurrent / safeTotal) * 100) : 0;

    return (
        <div className="glimmr-progress">
            {label && <div className="glimmr-progress__label">{label}</div>}
            <div className="glimmr-progress__track">
                <div
                    className={`glimmr-progress__bar glimmr-progress__bar--${status || 'default'}`}
                    style={{ width: `${percentage}%` }}
                />
            </div>
        </div>
    );
};

/**
 * Products Tab - Sync products to vector store for semantic search
 * Clean, organized layout with clear sections
 */
const ProductsTab = ({ ajaxUrl, nonce, onNotice, settings, onSettingsChange }) => {
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [reindexing, setReindexing] = useState(false);
    const [purging, setPurging] = useState(false);
    const [showPurgeConfirm, setShowPurgeConfirm] = useState(false);
    const [productStats, setProductStats] = useState({
        total_products: 0,
        indexed_products: 0,
        synced_products: 0,
        pending_products: 0,
        failed_products: 0,
        last_sync: null,
        last_sync_status: null,
        last_sync_duration: null,
        vector_store_id: null,
        sync_enabled: false,
    });
    const [syncProgress, setSyncProgress] = useState({
        current: 0,
        total: 0,
        status: 'idle',
        message: '',
        errors: [],
        started_at: null,
    });
    const [showErrors, setShowErrors] = useState(false);
    const [showHelp, setShowHelp] = useState(false);

    const autoSyncEnabled = settings?.product_auto_sync_enabled || false;

    useEffect(() => {
        fetchProductStatus();
    }, []);

    useEffect(() => {
        let pollInterval;
        if (syncing && syncProgress.status === 'syncing') {
            pollInterval = setInterval(() => {
                fetchSyncProgress();
            }, 2000);
        }
        return () => clearInterval(pollInterval);
    }, [syncing, syncProgress.status]);

    const fetchProductStatus = async () => {
        setLoading(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_product_sync_status');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                setProductStats(result.data);
            } else {
                onNotice({ type: 'error', message: result.data?.message || 'Failed to load product status.' });
            }
        } catch (err) {
            console.error('Product status fetch error:', err);
            onNotice({ type: 'error', message: 'Failed to load product status.' });
        }
        setLoading(false);
    };

    const fetchSyncProgress = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_product_sync_progress');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                setSyncProgress(result.data);
                if (result.data.status === 'complete' || result.data.status === 'error') {
                    setSyncing(false);
                    fetchProductStatus();
                }
            }
        } catch (err) {
            console.error('Sync progress fetch error:', err);
        }
    };

    const handleStartSync = async (fullSync = false) => {
        setSyncing(true);
        setSyncProgress({
            current: 0,
            total: productStats.total_products,
            status: 'syncing',
            message: fullSync ? 'Starting full re-sync...' : 'Syncing new & updated products...',
            errors: [],
            started_at: new Date().toISOString(),
        });

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_sync_products_batch');
            formData.append('nonce', nonce);
            formData.append('full_sync', fullSync ? '1' : '0');

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                setSyncProgress(prev => ({ ...prev, message: result.data?.message || 'Syncing products...' }));
            } else {
                setSyncing(false);
                setSyncProgress(prev => ({ ...prev, status: 'error', message: result.data?.message || 'Failed to start sync.' }));
                onNotice({ type: 'error', message: result.data?.message || 'Failed to start sync.' });
            }
        } catch (err) {
            setSyncing(false);
            setSyncProgress(prev => ({ ...prev, status: 'error', message: 'Network error during sync.' }));
            onNotice({ type: 'error', message: 'Network error during sync.' });
        }
    };

    const handleCancelSync = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_cancel_product_sync');
            formData.append('nonce', nonce);
            await fetch(ajaxUrl, { method: 'POST', body: formData });

            setSyncing(false);
            setSyncProgress(prev => ({ ...prev, status: 'cancelled', message: 'Sync cancelled.' }));
            onNotice({ type: 'warning', message: 'Product sync cancelled.' });
        } catch (err) {
            console.error('Cancel sync error:', err);
        }
    };

    const handlePurgeProducts = async () => {
        setShowPurgeConfirm(false);
        setPurging(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_purge_products');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                onNotice({ type: 'success', message: result.data.message || 'Products purged successfully.' });
                fetchProductStatus();
            } else {
                onNotice({ type: 'error', message: result.data?.message || 'Failed to purge products.' });
            }
        } catch (err) {
            console.error('Purge error:', err);
            onNotice({ type: 'error', message: 'Network error during purge.' });
        }
        setPurging(false);
    };

    const handleReindex = async () => {
        setReindexing(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_reindex_products');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                onNotice({
                    type: 'success',
                    message: result.data?.message || `Indexed ${result.data?.indexed || 0} products from WooCommerce.`
                });
                fetchProductStatus();
            } else {
                onNotice({ type: 'error', message: result.data?.message || 'Failed to reindex products.' });
            }
        } catch (err) {
            console.error('Reindex error:', err);
            onNotice({ type: 'error', message: 'Network error during reindex.' });
        }
        setReindexing(false);
    };

    const handleToggleAutoSync = (enabled) => {
        if (onSettingsChange) {
            onSettingsChange({ product_auto_sync_enabled: enabled });
        }
    };

    const formatDuration = (seconds) => {
        if (!seconds) return '-';
        if (seconds < 60) return `${seconds}s`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
        return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
    };

    const formatRelativeTime = (dateString) => {
        if (!dateString) return 'Never';
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;
        return date.toLocaleDateString();
    };

    if (loading) {
        return (
            <div className="glimmr-loading-center">
                <Spinner />
                <p>Loading product data...</p>
            </div>
        );
    }

    const totalProducts = productStats.total_products || 0;
    const indexedProducts = productStats.indexed_products || 0;
    const syncedProducts = productStats.synced_products || 0;
    const pendingProducts = productStats.pending_products || 0;
    const failedProducts = productStats.failed_products || 0;
    const unindexedProducts = Math.max(0, totalProducts - indexedProducts);
    const syncPercentage = totalProducts > 0 ? Math.round((syncedProducts / totalProducts) * 100) : 0;

    return (
        <div className="glimmr-products-tab">
            {/* Index Warning - Products not yet indexed */}
            {totalProducts > 0 && indexedProducts === 0 && (
                <Notice status="warning" isDismissible={false} className="glimmr-products-notice">
                    <strong>Products not indexed.</strong> You have {totalProducts.toLocaleString()} WooCommerce products but none are indexed yet.
                    Click "Reindex Products" below to build the product index before syncing to the vector store.
                </Notice>
            )}

            {/* Partial Index Warning */}
            {indexedProducts > 0 && unindexedProducts > 0 && (
                <Notice status="info" isDismissible={false} className="glimmr-products-notice">
                    <strong>{unindexedProducts.toLocaleString()} products not indexed.</strong> Click "Reindex Products" to include new products in the index.
                </Notice>
            )}

            {/* Vector Store Warning */}
            {!productStats.vector_store_id && (
                <Notice status="warning" isDismissible={false} className="glimmr-products-notice">
                    <strong>Vector store not configured.</strong> A vector store will be created automatically when you start your first sync.
                </Notice>
            )}

            {/* Active Sync Banner */}
            {syncing && (
                <div className="glimmr-sync-banner">
                    <div className="glimmr-sync-banner__content">
                        <Spinner />
                        <div className="glimmr-sync-banner__info">
                            <strong>{syncProgress.message}</strong>
                            <span className="glimmr-sync-banner__progress">
                                {syncProgress.current} / {syncProgress.total} products
                            </span>
                        </div>
                    </div>
                    <Button variant="secondary" isSmall isDestructive onClick={handleCancelSync}>
                        Cancel
                    </Button>
                </div>
            )}

            {/* Active Reindex Banner */}
            {reindexing && (
                <div className="glimmr-sync-banner">
                    <div className="glimmr-sync-banner__content">
                        <Spinner />
                        <div className="glimmr-sync-banner__info">
                            <strong>Indexing products from WooCommerce...</strong>
                            <span className="glimmr-sync-banner__progress">
                                This may take a while for large catalogs
                            </span>
                        </div>
                    </div>
                </div>
            )}

            {/* Success Message */}
            {!syncing && syncProgress.status === 'complete' && (
                <Notice status="success" isDismissible onRemove={() => setSyncProgress(prev => ({ ...prev, status: 'idle' }))}>
                    Sync completed! {syncProgress.current} products synced to vector store.
                </Notice>
            )}

            {/* Main Content Grid */}
            <div className="glimmr-products-grid">
                {/* Left Column - Stats & Status */}
                <div className="glimmr-products-main">
                    {/* Sync Status Card */}
                    <div className="glimmr-card">
                        <div className="glimmr-card__header">
                            <h3 className="glimmr-card__title">Sync Status</h3>
                            <Button
                                variant="tertiary"
                                isSmall
                                onClick={fetchProductStatus}
                                disabled={syncing}
                                className="glimmr-card__action"
                            >
                                <span className="dashicons dashicons-update" aria-hidden="true"></span>
                                Refresh
                            </Button>
                        </div>
                        <div className="glimmr-card__body">
                            {/* Stats Table */}
                            <table className="glimmr-stats-table">
                                <tbody>
                                    <tr>
                                        <td className="glimmr-stats-table__label">WooCommerce Products</td>
                                        <td className="glimmr-stats-table__value">{totalProducts.toLocaleString()}</td>
                                    </tr>
                                    <tr className={indexedProducts < totalProducts ? 'glimmr-stats-table__row--warning' : ''}>
                                        <td className="glimmr-stats-table__label">
                                            {indexedProducts < totalProducts && (
                                                <span className="glimmr-status-dot glimmr-status-dot--warning"></span>
                                            )}
                                            Indexed for Search
                                        </td>
                                        <td className="glimmr-stats-table__value">{indexedProducts.toLocaleString()}</td>
                                    </tr>
                                    <tr className="glimmr-stats-table__row--success">
                                        <td className="glimmr-stats-table__label">
                                            <span className="glimmr-status-dot glimmr-status-dot--success"></span>
                                            Synced to Vector Store
                                        </td>
                                        <td className="glimmr-stats-table__value">{syncedProducts.toLocaleString()}</td>
                                    </tr>
                                    <tr className="glimmr-stats-table__row--warning">
                                        <td className="glimmr-stats-table__label">
                                            <span className="glimmr-status-dot glimmr-status-dot--warning"></span>
                                            Pending Sync
                                        </td>
                                        <td className="glimmr-stats-table__value">{pendingProducts.toLocaleString()}</td>
                                    </tr>
                                    {failedProducts > 0 && (
                                        <tr className="glimmr-stats-table__row--error">
                                            <td className="glimmr-stats-table__label">
                                                <span className="glimmr-status-dot glimmr-status-dot--error"></span>
                                                Failed
                                            </td>
                                            <td className="glimmr-stats-table__value">{failedProducts.toLocaleString()}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>

                            {/* Progress Bar */}
                            <div className="glimmr-sync-progress">
                                <div className="glimmr-sync-progress__header">
                                    <span className="glimmr-sync-progress__label">Coverage</span>
                                    <span className="glimmr-sync-progress__percent">{syncPercentage}%</span>
                                </div>
                                <ProgressBar
                                    current={syncedProducts}
                                    total={totalProducts}
                                    status={syncPercentage === 100 ? 'complete' : 'default'}
                                />
                            </div>

                            {/* Last Sync Info */}
                            <div className="glimmr-sync-meta">
                                <div className="glimmr-sync-meta__item">
                                    <span className="glimmr-sync-meta__label">Last Sync</span>
                                    <span className="glimmr-sync-meta__value">{formatRelativeTime(productStats.last_sync)}</span>
                                </div>
                                {productStats.last_sync_duration && (
                                    <div className="glimmr-sync-meta__item">
                                        <span className="glimmr-sync-meta__label">Duration</span>
                                        <span className="glimmr-sync-meta__value">{formatDuration(productStats.last_sync_duration)}</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Errors Card */}
                    {syncProgress.errors && syncProgress.errors.length > 0 && (
                        <div className="glimmr-card glimmr-card--error">
                            <div className="glimmr-card__header">
                                <h3 className="glimmr-card__title">
                                    <span className="dashicons dashicons-warning" aria-hidden="true"></span>
                                    Sync Errors ({syncProgress.errors.length})
                                </h3>
                                <Button variant="link" isSmall onClick={() => setShowErrors(!showErrors)}>
                                    {showErrors ? 'Hide' : 'Show'}
                                </Button>
                            </div>
                            {showErrors && (
                                <div className="glimmr-card__body">
                                    <div className="glimmr-error-list">
                                        {syncProgress.errors.map((error, index) => (
                                            <div key={index} className="glimmr-error-list__item">
                                                <strong>{error.product_name || `Product #${error.product_id}`}</strong>
                                                <span>{error.message}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Right Column - Actions */}
                <div className="glimmr-products-sidebar">
                    {/* Index Actions Card */}
                    <div className="glimmr-card">
                        <div className="glimmr-card__header">
                            <h3 className="glimmr-card__title">Step 1: Index Products</h3>
                        </div>
                        <div className="glimmr-card__body">
                            <p className="glimmr-card__help">
                                Build the product index from WooCommerce. Required before syncing to vector store.
                            </p>
                            <Button
                                variant={indexedProducts < totalProducts ? 'primary' : 'secondary'}
                                onClick={handleReindex}
                                disabled={reindexing || syncing || purging || totalProducts === 0}
                                className="glimmr-action-stack__btn"
                            >
                                {reindexing ? <Spinner /> : <span className="dashicons dashicons-database" aria-hidden="true"></span>}
                                {reindexing ? 'Indexing...' : (indexedProducts === 0 ? 'Reindex Products' : 'Rebuild Index')}
                            </Button>
                            {indexedProducts > 0 && unindexedProducts > 0 && (
                                <p className="glimmr-card__meta">
                                    {unindexedProducts.toLocaleString()} new products to index
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Sync Actions Card */}
                    <div className="glimmr-card">
                        <div className="glimmr-card__header">
                            <h3 className="glimmr-card__title">Step 2: Sync to Vector Store</h3>
                        </div>
                        <div className="glimmr-card__body">
                            <div className="glimmr-action-stack">
                                <Button
                                    variant="primary"
                                    onClick={() => handleStartSync(false)}
                                    disabled={syncing || reindexing || purging || indexedProducts === 0}
                                    className="glimmr-action-stack__btn"
                                >
                                    <span className="dashicons dashicons-update" aria-hidden="true"></span>
                                    {syncedProducts === 0 ? 'Sync All Products' : 'Sync New & Updated'}
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => handleStartSync(true)}
                                    disabled={syncing || reindexing || purging || indexedProducts === 0}
                                    className="glimmr-action-stack__btn"
                                >
                                    <span className="dashicons dashicons-image-rotate" aria-hidden="true"></span>
                                    Full Re-sync
                                </Button>
                            </div>
                            {indexedProducts === 0 && totalProducts > 0 && (
                                <p className="glimmr-card__meta glimmr-card__meta--warning">
                                    Index products first before syncing
                                </p>
                            )}

                            <div className="glimmr-action-divider"></div>

                            <Button
                                variant="tertiary"
                                isDestructive
                                onClick={() => setShowPurgeConfirm(true)}
                                disabled={syncing || reindexing || purging || syncedProducts === 0}
                                className="glimmr-action-stack__btn glimmr-action-stack__btn--danger"
                            >
                                {purging ? <Spinner /> : <span className="dashicons dashicons-trash" aria-hidden="true"></span>}
                                {purging ? 'Purging...' : 'Purge All Products'}
                            </Button>
                        </div>
                    </div>

                    {/* Settings Card */}
                    <div className="glimmr-card">
                        <div className="glimmr-card__header">
                            <h3 className="glimmr-card__title">Settings</h3>
                        </div>
                        <div className="glimmr-card__body">
                            <ToggleControl
                                label="Automatic Sync"
                                help={autoSyncEnabled
                                    ? 'Products sync on schedule.'
                                    : 'Manual sync only.'}
                                checked={autoSyncEnabled}
                                onChange={handleToggleAutoSync}
                            />
                        </div>
                    </div>

                    {/* Help Card */}
                    <div className="glimmr-card glimmr-card--muted">
                        <div className="glimmr-card__header">
                            <h3 className="glimmr-card__title">
                                <span className="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                Help
                            </h3>
                            <Button variant="link" isSmall onClick={() => setShowHelp(!showHelp)}>
                                {showHelp ? 'Hide' : 'Show'}
                            </Button>
                        </div>
                        {showHelp && (
                            <div className="glimmr-card__body glimmr-help-content">
                                <p>
                                    <strong>Semantic Search:</strong> Synced products can be found by meaning, not just keywords.
                                    "Cozy sweaters" finds relevant products even without exact matches.
                                </p>
                                <p><strong>Two-Step Process:</strong></p>
                                <dl>
                                    <dt>Step 1: Reindex Products</dt>
                                    <dd>Reads products from WooCommerce and builds the local index. Required for new stores or after adding many products.</dd>
                                    <dt>Step 2: Sync to Vector Store</dt>
                                    <dd>Uploads indexed products to OpenAI for semantic search.</dd>
                                </dl>
                                <p><strong>Sync Options:</strong></p>
                                <dl>
                                    <dt>Sync New & Updated</dt>
                                    <dd>Only syncs products changed since last sync.</dd>
                                    <dt>Full Re-sync</dt>
                                    <dd>Clears and rebuilds all vector store data.</dd>
                                    <dt>Purge</dt>
                                    <dd>Removes all products from vector store.</dd>
                                </dl>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Purge Confirmation Modal */}
            {showPurgeConfirm && (
                <Modal
                    title="Purge All Products?"
                    onRequestClose={() => setShowPurgeConfirm(false)}
                    className="glimmr-modal"
                >
                    <p>
                        This will <strong>permanently delete all {syncedProducts.toLocaleString()} product files</strong> from
                        the OpenAI vector store. Semantic search will stop working until you sync again.
                    </p>
                    <div className="glimmr-modal__actions">
                        <Button variant="secondary" onClick={() => setShowPurgeConfirm(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" isDestructive onClick={handlePurgeProducts}>
                            Yes, Purge All
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
};

/**
 * Sync Status Overview
 */
const SyncOverview = ({ stats, onSyncAll, onSyncEverything, onPurgeEverything, onPurgeVectorStoreDirect, syncing, purging, autoSyncEnabled, onToggleAutoSync }) => {
    const [showPurgeConfirm, setShowPurgeConfirm] = useState(false);
    const [showDirectPurgeConfirm, setShowDirectPurgeConfirm] = useState(false);

    return (
        <Card className="glimmr-sync-overview">
            <CardBody>
                {/* Auto-Sync Toggle */}
                <div className="glimmr-knowledge-auto-sync">
                    <ToggleControl
                        label="Automatic Knowledge Sync"
                        help={autoSyncEnabled
                            ? 'Pages and posts will sync automatically on schedule (configured in Settings → Sync tab).'
                            : 'Automatic sync is disabled. Use the buttons below to sync manually.'}
                        checked={autoSyncEnabled}
                        onChange={onToggleAutoSync}
                    />
                </div>

                <div className="glimmr-sync-stats">
                    <div className="glimmr-sync-stat">
                        <span className="glimmr-sync-stat-value">{stats.total || 0}</span>
                        <span className="glimmr-sync-stat-label">Knowledge Items</span>
                    </div>
                    <div className="glimmr-sync-stat">
                        <span className="glimmr-sync-stat-value glimmr-stat-green">{stats.synced || 0}</span>
                        <span className="glimmr-sync-stat-label">Synced</span>
                    </div>
                    <div className="glimmr-sync-stat">
                        <span className="glimmr-sync-stat-value glimmr-stat-orange">{stats.pending || 0}</span>
                        <span className="glimmr-sync-stat-label">Pending</span>
                    </div>
                    <div className="glimmr-sync-stat">
                        <span className="glimmr-sync-stat-value glimmr-stat-red">{stats.error || 0}</span>
                        <span className="glimmr-sync-stat-label">Errors</span>
                    </div>
                </div>

                <div className="glimmr-sync-actions">
                    <div className="glimmr-action-group">
                        <Button
                            variant="primary"
                            onClick={onSyncAll}
                            disabled={syncing || purging || stats.pending === 0}
                        >
                            {syncing ? (
                                <>
                                    <Spinner />
                                    Syncing...
                                </>
                            ) : (
                                <>
                                    <span className="dashicons dashicons-update" aria-hidden="true"></span>
                                    Sync Pending Knowledge
                                </>
                            )}
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={onSyncEverything}
                            disabled={syncing || purging}
                        >
                            <span className="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                            Sync Everything
                        </Button>
                        <Button
                            variant="tertiary"
                            isDestructive
                            onClick={() => setShowPurgeConfirm(true)}
                            disabled={syncing || purging || stats.synced === 0}
                        >
                            {purging ? (
                                <>
                                    <Spinner />
                                    Purging...
                                </>
                            ) : (
                                <>
                                    <span className="dashicons dashicons-trash" aria-hidden="true"></span>
                                    Purge Everything
                                </>
                            )}
                        </Button>
                        <Button
                            variant="tertiary"
                            isDestructive
                            onClick={() => setShowDirectPurgeConfirm(true)}
                            disabled={syncing || purging}
                            title="Purge all files directly from OpenAI vector store (bypasses database)"
                        >
                            {purging ? (
                                <>
                                    <Spinner />
                                    Purging...
                                </>
                            ) : (
                                <>
                                    <span className="dashicons dashicons-warning" aria-hidden="true"></span>
                                    Purge All (Direct)
                                </>
                            )}
                        </Button>
                    </div>

                    {stats.lastSync && (
                        <span className="glimmr-last-sync">
                            Last full sync: {new Date(stats.lastSync).toLocaleString()}
                        </span>
                    )}
                </div>

                {/* Purge Confirmation Modal */}
                {showPurgeConfirm && (
                    <Modal
                        title="Purge All Content?"
                        onRequestClose={() => setShowPurgeConfirm(false)}
                        className="glimmr-purge-modal"
                    >
                        <p>
                            This will <strong>permanently delete ALL files</strong> from the OpenAI vector store,
                            including knowledge items (pages, posts, custom content) and products.
                        </p>
                        <p>
                            Semantic search will stop working until you sync again.
                        </p>
                        <div className="glimmr-modal-actions">
                            <Button variant="secondary" onClick={() => setShowPurgeConfirm(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="primary"
                                isDestructive
                                onClick={() => {
                                    setShowPurgeConfirm(false);
                                    onPurgeEverything();
                                }}
                            >
                                Yes, Purge Everything
                            </Button>
                        </div>
                    </Modal>
                )}

                {/* Direct Purge Confirmation Modal */}
                {showDirectPurgeConfirm && (
                    <Modal
                        title="Direct Purge Vector Store?"
                        onRequestClose={() => setShowDirectPurgeConfirm(false)}
                        className="glimmr-purge-modal"
                    >
                        <p>
                            <strong>⚠️ Advanced Operation:</strong> This will delete ALL files directly from
                            the OpenAI vector store by querying the API, <strong>bypassing the local database</strong>.
                        </p>
                        <p>
                            Use this when the database is out of sync with the vector store (e.g., files exist
                            in OpenAI but not in your database).
                        </p>
                        <p>
                            After purging, the database will be reset to reflect the empty state.
                        </p>
                        <div className="glimmr-modal-actions">
                            <Button variant="secondary" onClick={() => setShowDirectPurgeConfirm(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="primary"
                                isDestructive
                                onClick={() => {
                                    setShowDirectPurgeConfirm(false);
                                    onPurgeVectorStoreDirect();
                                }}
                            >
                                Yes, Purge Directly
                            </Button>
                        </div>
                    </Modal>
                )}
            </CardBody>
        </Card>
    );
};

/**
 * Main Knowledge Manager Component
 */
const KnowledgeManager = () => {
    const [activeTab, setActiveTab] = useState('pages');
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState(false);
    const [purging, setPurging] = useState(false);
    const [notice, setNotice] = useState(null);

    // Content state
    const [pages, setPages] = useState([]);
    const [posts, setPosts] = useState([]);
    const [customContent, setCustomContent] = useState([]);
    const [postTypes, setPostTypes] = useState([]);
    const [selectedPostType, setSelectedPostType] = useState('post');

    // Sync stats
    const [syncStats, setSyncStats] = useState({
        total: 0,
        synced: 0,
        pending: 0,
        error: 0,
        lastSync: null,
    });

    // Settings state for product sync settings
    const [productSettings, setProductSettings] = useState({
        product_auto_sync_enabled: false,
    });

    // Settings state for knowledge auto-sync
    const [knowledgeAutoSyncEnabled, setKnowledgeAutoSyncEnabled] = useState(false);

    const { ajaxUrl, nonce } = window.glimmrAI || {};

    /**
     * Fetch all knowledge data on mount.
     */
    useEffect(() => {
        fetchKnowledgeData();
        fetchSyncSettings();
    }, []);

    /**
     * Fetch sync settings (product and knowledge auto-sync).
     */
    const fetchSyncSettings = async () => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_settings');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setProductSettings({
                    product_auto_sync_enabled: result.data?.product_auto_sync_enabled || false,
                });
                setKnowledgeAutoSyncEnabled(result.data?.knowledge_auto_sync_enabled || false);
            }
        } catch (err) {
            console.error('Settings fetch error:', err);
        }
    };

    /**
     * Save product sync settings.
     */
    const handleProductSettingsChange = async (newSettings) => {
        const updatedSettings = { ...productSettings, ...newSettings };
        setProductSettings(updatedSettings);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_save_settings');
            formData.append('nonce', nonce);
            formData.append('settings', JSON.stringify(newSettings));

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Settings saved.' });
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to save settings.' });
            }
        } catch (err) {
            console.error('Settings save error:', err);
            setNotice({ type: 'error', message: 'Failed to save settings.' });
        }
    };

    /**
     * Toggle knowledge auto-sync setting.
     */
    const handleToggleKnowledgeAutoSync = async (enabled) => {
        // Optimistic update
        setKnowledgeAutoSyncEnabled(enabled);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_save_settings');
            formData.append('nonce', nonce);
            formData.append('settings', JSON.stringify({ knowledge_auto_sync_enabled: enabled }));

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: enabled ? 'Automatic knowledge sync enabled.' : 'Automatic knowledge sync disabled.' });
            } else {
                // Revert on failure
                setKnowledgeAutoSyncEnabled(!enabled);
                setNotice({ type: 'error', message: result.data?.message || 'Failed to save setting.' });
            }
        } catch (err) {
            // Revert on error
            setKnowledgeAutoSyncEnabled(!enabled);
            console.error('Settings save error:', err);
            setNotice({ type: 'error', message: 'Failed to save setting.' });
        }
    };

    /**
     * Sync everything (knowledge + products).
     */
    const handleSyncEverything = async () => {
        setSyncing(true);
        setNotice({ type: 'info', message: 'Syncing all content and products...' });

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_sync_everything');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: result.data.message });
                fetchKnowledgeData();
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to sync.' });
            }
        } catch (err) {
            console.error('Sync everything error:', err);
            setNotice({ type: 'error', message: 'Network error during sync.' });
        }

        setSyncing(false);
    };

    /**
     * Purge everything from vector store.
     */
    const handlePurgeEverything = async () => {
        setPurging(true);
        setNotice({ type: 'info', message: 'Purging all content from vector store...' });

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_purge_everything');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: result.data.message });
                fetchKnowledgeData();
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to purge.' });
            }
        } catch (err) {
            console.error('Purge everything error:', err);
            setNotice({ type: 'error', message: 'Network error during purge.' });
        }

        setPurging(false);
    };

    /**
     * Purge vector store directly via API (bypasses database).
     * Use when database is out of sync with the vector store.
     */
    const handlePurgeVectorStoreDirect = async () => {
        setPurging(true);
        setNotice({ type: 'info', message: 'Purging all files directly from OpenAI vector store...' });

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_purge_vector_store_direct');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: result.data.message });
                fetchKnowledgeData();
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to purge.' });
            }
        } catch (err) {
            console.error('Direct purge error:', err);
            setNotice({ type: 'error', message: 'Network error during direct purge.' });
        }

        setPurging(false);
    };

    /**
     * Fetch posts when post type changes.
     */
    useEffect(() => {
        if (activeTab === 'posts') {
            fetchPosts(selectedPostType);
        }
    }, [selectedPostType]);

    /**
     * Fetch all knowledge data.
     */
    const fetchKnowledgeData = async () => {
        setLoading(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_knowledge');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setPages(result.data.pages || []);
                setPosts(result.data.posts || []);
                setCustomContent(result.data.custom || []);
                setPostTypes(result.data.post_types || []);
                setSyncStats(result.data.stats || {});
            }
        } catch (err) {
            console.error('Knowledge fetch error:', err);
            setNotice({ type: 'error', message: 'Failed to load knowledge data.' });
        }

        setLoading(false);
    };

    /**
     * Fetch posts for a specific post type.
     */
    const fetchPosts = async (postType) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_posts');
            formData.append('nonce', nonce);
            formData.append('post_type', postType);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setPosts(result.data || []);
            }
        } catch (err) {
            console.error('Posts fetch error:', err);
        }
    };

    /**
     * Toggle content inclusion.
     */
    const handleToggle = async (id) => {
        const type = activeTab === 'pages' ? 'page' : 'post';
        const items = activeTab === 'pages' ? pages : posts;
        const setItems = activeTab === 'pages' ? setPages : setPosts;

        const item = items.find((i) => i.id === id);
        const newIncluded = !item.included;

        // Optimistic update
        setItems(items.map((i) => (i.id === id ? { ...i, included: newIncluded } : i)));

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_toggle_knowledge');
            formData.append('nonce', nonce);
            formData.append('type', type);
            formData.append('source_id', id);
            formData.append('included', newIncluded ? '1' : '0');

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (!result.success) {
                // Revert on failure
                setItems(items.map((i) => (i.id === id ? { ...i, included: !newIncluded } : i)));
                setNotice({ type: 'error', message: 'Failed to update.' });
            } else {
                // Update sync stats
                setSyncStats(result.data.stats || syncStats);
            }
        } catch (err) {
            // Revert on error
            setItems(items.map((i) => (i.id === id ? { ...i, included: !newIncluded } : i)));
        }
    };

    /**
     * Select/deselect all items.
     */
    const handleSelectAll = async (type, selected) => {
        const items = type === 'pages' ? pages : posts;
        const setItems = type === 'pages' ? setPages : setPosts;

        // Optimistic update
        setItems(items.map((i) => ({ ...i, included: selected })));

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_bulk_toggle_knowledge');
            formData.append('nonce', nonce);
            formData.append('type', type === 'pages' ? 'page' : 'post');
            formData.append('post_type', selectedPostType);
            formData.append('included', selected ? '1' : '0');

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setSyncStats(result.data.stats || syncStats);
            }
        } catch (err) {
            console.error('Bulk toggle error:', err);
        }
    };

    /**
     * Sync a single item.
     */
    const handleSyncItem = async (id) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_sync_knowledge_item');
            formData.append('nonce', nonce);
            formData.append('id', id);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Item synced successfully.' });
                fetchKnowledgeData(); // Refresh data
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Sync failed.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Sync failed.' });
        }
    };

    /**
     * Sync all pending items.
     */
    const handleSyncAll = async () => {
        setSyncing(true);

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_sync_knowledge');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Sync completed.' });
                fetchKnowledgeData(); // Refresh data
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Sync failed.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Sync failed.' });
        }

        setSyncing(false);
    };

    /**
     * Add custom content.
     */
    const handleAddCustom = async (data) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_add_custom_knowledge');
            formData.append('nonce', nonce);
            formData.append('title', data.title);
            formData.append('content', data.content);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Content added.' });
                fetchKnowledgeData(); // Refresh
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to add.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to add content.' });
        }
    };

    /**
     * Edit custom content.
     */
    const handleEditCustom = async (id, data) => {
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_edit_custom_knowledge');
            formData.append('nonce', nonce);
            formData.append('id', id);
            formData.append('title', data.title);
            formData.append('content', data.content);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Content updated.' });
                fetchKnowledgeData(); // Refresh
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to update.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to update content.' });
        }
    };

    /**
     * Delete custom content.
     */
    const handleDeleteCustom = async (id) => {
        if (!confirm('Are you sure you want to delete this content?')) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_delete_custom_knowledge');
            formData.append('nonce', nonce);
            formData.append('id', id);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                setNotice({ type: 'success', message: 'Content deleted.' });
                setCustomContent(customContent.filter((c) => c.id !== id));
            } else {
                setNotice({ type: 'error', message: result.data?.message || 'Failed to delete.' });
            }
        } catch (err) {
            setNotice({ type: 'error', message: 'Failed to delete content.' });
        }
    };

    /**
     * Render active tab content.
     */
    const renderTabContent = () => {
        switch (activeTab) {
            case 'pages':
                return (
                    <PagesTab
                        pages={pages}
                        onToggle={handleToggle}
                        onSync={handleSyncItem}
                        onSelectAll={handleSelectAll}
                        loading={loading}
                    />
                );
            case 'posts':
                return (
                    <PostsTab
                        posts={posts}
                        postTypes={postTypes}
                        selectedType={selectedPostType}
                        onTypeChange={setSelectedPostType}
                        onToggle={handleToggle}
                        onSync={handleSyncItem}
                        loading={loading}
                    />
                );
            case 'products':
                return (
                    <ProductsTab
                        ajaxUrl={ajaxUrl}
                        nonce={nonce}
                        onNotice={setNotice}
                        settings={productSettings}
                        onSettingsChange={handleProductSettingsChange}
                    />
                );
            case 'custom':
                return (
                    <CustomContentTab
                        items={customContent}
                        onAdd={handleAddCustom}
                        onEdit={handleEditCustom}
                        onDelete={handleDeleteCustom}
                        onSync={handleSyncItem}
                    />
                );
            default:
                return null;
        }
    };

    return (
        <div className="glimmr-knowledge-manager">
            {notice && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice(null)}
                >
                    {notice.message}
                </Notice>
            )}

            {/* Hide sync overview on Products tab - it has its own stats */}
            {activeTab !== 'products' && (
                <SyncOverview
                    stats={syncStats}
                    onSyncAll={handleSyncAll}
                    onSyncEverything={handleSyncEverything}
                    onPurgeEverything={handlePurgeEverything}
                    onPurgeVectorStoreDirect={handlePurgeVectorStoreDirect}
                    syncing={syncing}
                    purging={purging}
                    autoSyncEnabled={knowledgeAutoSyncEnabled}
                    onToggleAutoSync={handleToggleKnowledgeAutoSync}
                />
            )}

            <Card className="glimmr-knowledge-content">
                <CardHeader>
                    <div className="glimmr-knowledge-tabs" role="tablist" aria-label="Content type sections">
                        {CONTENT_TYPES.map((tab) => (
                            <button
                                key={tab.name}
                                role="tab"
                                aria-selected={activeTab === tab.name}
                                aria-controls={`tabpanel-knowledge-${tab.name}`}
                                id={`tab-knowledge-${tab.name}`}
                                className={`glimmr-tab-button ${activeTab === tab.name ? 'is-active' : ''}`}
                                onClick={() => setActiveTab(tab.name)}
                            >
                                <span className={`dashicons dashicons-${tab.icon}`} aria-hidden="true"></span>
                                {tab.title}
                            </button>
                        ))}
                    </div>
                </CardHeader>
                <CardBody role="tabpanel" id={`tabpanel-knowledge-${activeTab}`} aria-labelledby={`tab-knowledge-${activeTab}`}>
                    {renderTabContent()}
                </CardBody>
            </Card>
        </div>
    );
};

export default KnowledgeManager;
