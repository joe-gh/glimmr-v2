/**
 * Get Started Component
 *
 * Setup wizard and onboarding page for Glimmr AI.
 * Guides users through initial configuration with step-by-step instructions.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

const { useState, useEffect, useCallback } = wp.element;
const { Card, CardBody, Button, Spinner, TextControl, SelectControl, Notice, Modal } = wp.components;

import './get-started/get-started.scss';

/**
 * Progress indicator showing completion status.
 */
const SetupProgress = ({ steps, currentStep }) => {
    const stepNames = ['API Key', 'Vector Store', 'Products', 'Knowledge', 'Widget'];
    const completedCount = Object.values(steps).filter(s => s.complete).length;

    return (
        <div className="glimmr-setup-progress">
            <div className="glimmr-progress-bar">
                {stepNames.map((name, index) => {
                    const stepKey = Object.keys(steps)[index];
                    const step = steps[stepKey];
                    const isComplete = step?.complete;
                    const isCurrent = currentStep === index;

                    return (
                        <div key={name} className={`glimmr-progress-step ${isComplete ? 'complete' : ''} ${isCurrent ? 'current' : ''}`}>
                            <div className="glimmr-progress-dot">
                                {isComplete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">{index + 1}</span>
                                )}
                            </div>
                            <span className="step-label">{name}</span>
                        </div>
                    );
                })}
                <div className="glimmr-progress-line">
                    <div
                        className="glimmr-progress-fill"
                        style={{ width: `${(completedCount / 5) * 100}%` }}
                    ></div>
                </div>
            </div>
            <div className="glimmr-progress-summary">
                <span className="progress-text">
                    {completedCount === 5 ? (
                        <><span className="dashicons dashicons-yes"></span> Setup Complete!</>
                    ) : (
                        <>{completedCount} of 5 steps complete</>
                    )}
                </span>
            </div>
        </div>
    );
};

/**
 * Step 1: API Key Configuration
 */
const ApiKeyStep = ({ step, onSave, onTest }) => {
    const [apiKey, setApiKey] = useState('');
    const [model, setModel] = useState('gpt-4o');
    const [showKey, setShowKey] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    const [isEditing, setIsEditing] = useState(!step.complete);

    const modelOptions = [
        { label: '— GPT-5 Series —', value: '', disabled: true },
        { label: 'GPT-5.2 (Most Capable)', value: 'gpt-5.2' },
        { label: 'GPT-5.1', value: 'gpt-5.1' },
        { label: 'GPT-5', value: 'gpt-5' },
        { label: 'GPT-5 Mini (Faster, Lower Cost)', value: 'gpt-5-mini' },
        { label: 'GPT-5 Nano (Ultra-Fast)', value: 'gpt-5-nano' },
        { label: '— GPT-4o Series —', value: '', disabled: true },
        { label: 'GPT-4o (Fast, Multimodal)', value: 'gpt-4o' },
        { label: 'GPT-4o Mini (Fastest & Cheapest)', value: 'gpt-4o-mini' },
        { label: '— GPT-4.1 Series —', value: '', disabled: true },
        { label: 'GPT-4.1 (Best Overall)', value: 'gpt-4.1' },
        { label: 'GPT-4.1 Mini (Faster, Cheaper)', value: 'gpt-4.1-mini' },
        { label: 'GPT-4.1 Nano (Low Cost)', value: 'gpt-4.1-nano' },
        { label: '— Reasoning Models —', value: '', disabled: true },
        { label: 'o4-mini (Advanced Reasoning)', value: 'o4-mini' },
        { label: 'o3-mini (Lightweight Reasoning)', value: 'o3-mini' },
        { label: '— Legacy —', value: '', disabled: true },
        { label: 'GPT-4 Turbo', value: 'gpt-4-turbo' },
        { label: 'GPT-4', value: 'gpt-4' },
    ];

    const handleSave = async () => {
        if (!apiKey.trim()) {
            setError('Please enter your API key.');
            return;
        }

        setIsLoading(true);
        setError('');

        try {
            const result = await onSave(apiKey, model);
            if (result.success) {
                setIsEditing(false);
                setApiKey('');
            } else {
                setError(result.message || 'Failed to save API key.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsLoading(false);
    };

    if (step.complete && !isEditing) {
        return (
            <div className="glimmr-step-content glimmr-step-complete">
                <div className="glimmr-step-status">
                    <span className="dashicons dashicons-yes-alt"></span>
                    <span>Connected: {step.details?.masked_key}</span>
                </div>
                <div className="glimmr-step-meta">
                    <span>Model: {step.details?.model}</span>
                </div>
                <div className="glimmr-step-actions">
                    <Button variant="link" onClick={() => setIsEditing(true)}>
                        Change API Key
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="glimmr-step-content glimmr-step-form">
            <div className="glimmr-api-instructions">
                <h4>How to get your OpenAI API Key:</h4>
                <ol>
                    <li>Go to <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a></li>
                    <li>Sign in or create an OpenAI account</li>
                    <li>Click "Create new secret key"</li>
                    <li>Copy the key and paste it below</li>
                </ol>
                <p className="glimmr-note">
                    <span className="dashicons dashicons-info"></span>
                    Your API key is encrypted and stored securely. OpenAI usage is billed separately.
                </p>
            </div>

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            <div className="glimmr-api-form">
                <div className="glimmr-api-key-field">
                    <label htmlFor="openai-api-key">OpenAI API Key</label>
                    <div className="glimmr-input-with-toggle">
                        <input
                            id="openai-api-key"
                            type={showKey ? 'text' : 'password'}
                            value={apiKey}
                            onChange={(e) => setApiKey(e.target.value)}
                            placeholder="sk-proj-..."
                            className="glimmr-api-input"
                        />
                        <button
                            type="button"
                            className="glimmr-toggle-visibility"
                            onClick={() => setShowKey(!showKey)}
                            aria-label={showKey ? 'Hide API key' : 'Show API key'}
                        >
                            <span className={`dashicons dashicons-${showKey ? 'hidden' : 'visibility'}`}></span>
                        </button>
                    </div>
                </div>

                <div className="glimmr-model-field">
                    <SelectControl
                        label="AI Model"
                        value={model}
                        options={modelOptions}
                        onChange={setModel}
                        help="GPT-4o offers the best balance of quality and speed."
                    />
                </div>

                <div className="glimmr-form-actions">
                    <Button
                        variant="primary"
                        onClick={handleSave}
                        disabled={isLoading || !apiKey.trim()}
                        isBusy={isLoading}
                    >
                        {isLoading ? 'Connecting...' : 'Save & Connect'}
                    </Button>
                    {step.complete && (
                        <Button variant="link" onClick={() => setIsEditing(false)}>
                            Cancel
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
};

/**
 * Step 2: Vector Store
 */
const VectorStoreStep = ({ step, onAction, prerequisites }) => {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');

    if (!prerequisites.apiKey) {
        return (
            <div className="glimmr-step-content glimmr-step-locked">
                <div className="glimmr-prereq-notice">
                    <span className="dashicons dashicons-lock"></span>
                    <span>Complete Step 1 (API Key) to continue</span>
                </div>
            </div>
        );
    }

    const handleCreate = async () => {
        setIsLoading(true);
        setError('');

        try {
            const result = await onAction('create_vector_store');
            if (!result.success) {
                setError(result.message || 'Failed to create vector store.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsLoading(false);
    };

    if (step.complete) {
        return (
            <div className="glimmr-step-content glimmr-step-complete">
                <div className="glimmr-step-status">
                    <span className="dashicons dashicons-yes-alt"></span>
                    <span>Vector store ready</span>
                </div>
                <div className="glimmr-step-meta">
                    <span>ID: {step.details?.store_id?.substring(0, 20)}...</span>
                </div>
            </div>
        );
    }

    return (
        <div className="glimmr-step-content">
            <p className="glimmr-step-description">
                The vector store enables the AI to intelligently search your products and knowledge base.
                This is required for product recommendations and site knowledge queries.
            </p>

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            <div className="glimmr-step-actions">
                <Button
                    variant="primary"
                    onClick={handleCreate}
                    disabled={isLoading}
                    isBusy={isLoading}
                >
                    {isLoading ? 'Creating...' : 'Create Vector Store'}
                </Button>
            </div>
        </div>
    );
};

/**
 * Step 3: Product Indexing
 */
const ProductsStep = ({ step, onAction, prerequisites }) => {
    const [isIndexing, setIsIndexing] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const [error, setError] = useState('');

    if (!prerequisites.vectorStore) {
        return (
            <div className="glimmr-step-content glimmr-step-locked">
                <div className="glimmr-prereq-notice">
                    <span className="dashicons dashicons-lock"></span>
                    <span>Complete Steps 1 & 2 to continue</span>
                </div>
            </div>
        );
    }

    const handleIndex = async () => {
        setIsIndexing(true);
        setError('');

        try {
            const result = await onAction('reindex_products');
            if (!result.success) {
                setError(result.message || 'Failed to index products.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsIndexing(false);
    };

    const handleSync = async () => {
        setIsSyncing(true);
        setError('');

        try {
            const result = await onAction('sync_products');
            if (!result.success) {
                setError(result.message || 'Failed to sync products.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsSyncing(false);
    };

    const { total, indexed, synced } = step.details || {};

    if (step.complete) {
        return (
            <div className="glimmr-step-content glimmr-step-complete">
                <div className="glimmr-step-status">
                    <span className="dashicons dashicons-yes-alt"></span>
                    <span>{synced} products synced to AI</span>
                </div>
                <div className="glimmr-step-actions">
                    <Button variant="link" onClick={handleSync} disabled={isSyncing}>
                        {isSyncing ? 'Syncing...' : 'Re-sync Products'}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="glimmr-step-content">
            <p className="glimmr-step-description">
                Products are indexed locally then synced to OpenAI for intelligent search.
            </p>

            <div className="glimmr-product-stats">
                <div className="stat-item">
                    <span className="stat-value">{total || 0}</span>
                    <span className="stat-label">WooCommerce Products</span>
                </div>
                <div className="stat-item">
                    <span className="stat-value">{indexed || 0}</span>
                    <span className="stat-label">Indexed</span>
                </div>
                <div className="stat-item">
                    <span className="stat-value">{synced || 0}</span>
                    <span className="stat-label">Synced to AI</span>
                </div>
            </div>

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            <div className="glimmr-step-actions">
                {indexed === 0 || indexed < total ? (
                    <Button
                        variant="primary"
                        onClick={handleIndex}
                        disabled={isIndexing}
                        isBusy={isIndexing}
                    >
                        {isIndexing ? 'Indexing...' : 'Index Products'}
                    </Button>
                ) : (
                    <Button
                        variant="primary"
                        onClick={handleSync}
                        disabled={isSyncing}
                        isBusy={isSyncing}
                    >
                        {isSyncing ? 'Syncing...' : 'Sync to AI'}
                    </Button>
                )}
                <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-settings`} className="glimmr-link">
                    Configure Exclusions
                </a>
            </div>
        </div>
    );
};

/**
 * Step 4: Knowledge Base
 */
const KnowledgeStep = ({ step, onAction, prerequisites }) => {
    const [isSyncing, setIsSyncing] = useState(false);
    const [error, setError] = useState('');

    if (!prerequisites.vectorStore) {
        return (
            <div className="glimmr-step-content glimmr-step-locked">
                <div className="glimmr-prereq-notice">
                    <span className="dashicons dashicons-lock"></span>
                    <span>Complete Steps 1 & 2 to continue</span>
                </div>
            </div>
        );
    }

    const handleSync = async () => {
        setIsSyncing(true);
        setError('');

        try {
            const result = await onAction('sync_knowledge');
            if (!result.success) {
                setError(result.message || 'Failed to sync knowledge.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsSyncing(false);
    };

    const { total, synced } = step.details || {};

    if (step.complete || (total === 0)) {
        return (
            <div className="glimmr-step-content glimmr-step-complete">
                <div className="glimmr-step-status">
                    {total === 0 ? (
                        <>
                            <span className="dashicons dashicons-info"></span>
                            <span>No knowledge items configured (optional)</span>
                        </>
                    ) : (
                        <>
                            <span className="dashicons dashicons-yes-alt"></span>
                            <span>{synced} knowledge items synced</span>
                        </>
                    )}
                </div>
                <div className="glimmr-step-actions">
                    <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-knowledge`} className="glimmr-link">
                        Manage Knowledge Base
                    </a>
                </div>
            </div>
        );
    }

    return (
        <div className="glimmr-step-content">
            <p className="glimmr-step-description">
                Add pages, posts, and custom content to the knowledge base for the AI to reference
                when answering questions about your store (return policies, shipping info, FAQs, etc.).
            </p>

            <div className="glimmr-knowledge-stats">
                <div className="stat-item">
                    <span className="stat-value">{total || 0}</span>
                    <span className="stat-label">Items Added</span>
                </div>
                <div className="stat-item">
                    <span className="stat-value">{synced || 0}</span>
                    <span className="stat-label">Synced</span>
                </div>
            </div>

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            <div className="glimmr-step-actions">
                {total > 0 && synced < total && (
                    <Button
                        variant="primary"
                        onClick={handleSync}
                        disabled={isSyncing}
                        isBusy={isSyncing}
                    >
                        {isSyncing ? 'Syncing...' : 'Sync Knowledge'}
                    </Button>
                )}
                <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-knowledge`} className="glimmr-link">
                    {total === 0 ? 'Add Knowledge Content' : 'Manage Knowledge'}
                </a>
            </div>
        </div>
    );
};

/**
 * Step 5: Widget Enable
 */
const WidgetStep = ({ step, onAction, prerequisites }) => {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');

    // Widget requires products to be synced
    if (!prerequisites.products) {
        return (
            <div className="glimmr-step-content glimmr-step-locked">
                <div className="glimmr-prereq-notice">
                    <span className="dashicons dashicons-lock"></span>
                    <span>Sync at least some products to enable the widget</span>
                </div>
            </div>
        );
    }

    const handleToggle = async () => {
        setIsLoading(true);
        setError('');

        try {
            const result = await onAction('toggle_widget', { enabled: !step.details?.enabled });
            if (!result.success) {
                setError(result.message || 'Failed to toggle widget.');
            }
        } catch (err) {
            setError(err.message || 'An error occurred.');
        }

        setIsLoading(false);
    };

    return (
        <div className="glimmr-step-content">
            <p className="glimmr-step-description">
                Enable the chat widget to display on your storefront. Customers can ask questions,
                browse products, and get help directly from your site.
            </p>

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error}
                </Notice>
            )}

            <div className="glimmr-widget-toggle">
                <label className="glimmr-toggle">
                    <input
                        type="checkbox"
                        checked={step.details?.enabled || false}
                        onChange={handleToggle}
                        disabled={isLoading}
                    />
                    <span className="glimmr-toggle-slider"></span>
                </label>
                <span className="glimmr-toggle-label">
                    {step.details?.enabled ? 'Widget is enabled' : 'Widget is disabled'}
                </span>
            </div>

            {step.details?.enabled && (
                <div className="glimmr-step-actions">
                    <a href={glimmrAI.siteUrl} target="_blank" rel="noopener noreferrer" className="glimmr-link">
                        <span className="dashicons dashicons-external"></span>
                        View Widget on Site
                    </a>
                    <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-settings`} className="glimmr-link">
                        Customize Appearance
                    </a>
                </div>
            )}
        </div>
    );
};

/**
 * How It Works section.
 */
const HowItWorks = () => {
    return (
        <div className="glimmr-how-it-works">
            <h3>How Glimmr AI Works</h3>
            <div className="glimmr-flow-diagram">
                <div className="flow-step">
                    <div className="flow-icon">
                        <span className="dashicons dashicons-format-chat"></span>
                    </div>
                    <div className="flow-content">
                        <h4>1. Customer Asks</h4>
                        <p>Customer types a question in the chat widget</p>
                    </div>
                </div>
                <div className="flow-arrow">
                    <span className="dashicons dashicons-arrow-right-alt"></span>
                </div>
                <div className="flow-step">
                    <div className="flow-icon">
                        <span className="dashicons dashicons-lightbulb"></span>
                    </div>
                    <div className="flow-content">
                        <h4>2. AI Analyzes</h4>
                        <p>OpenAI understands intent and selects relevant tools</p>
                    </div>
                </div>
                <div className="flow-arrow">
                    <span className="dashicons dashicons-arrow-right-alt"></span>
                </div>
                <div className="flow-step">
                    <div className="flow-icon">
                        <span className="dashicons dashicons-admin-tools"></span>
                    </div>
                    <div className="flow-content">
                        <h4>3. Tools Execute</h4>
                        <p>Search products, check orders, manage cart</p>
                    </div>
                </div>
                <div className="flow-arrow">
                    <span className="dashicons dashicons-arrow-right-alt"></span>
                </div>
                <div className="flow-step">
                    <div className="flow-icon">
                        <span className="dashicons dashicons-yes"></span>
                    </div>
                    <div className="flow-content">
                        <h4>4. Rich Response</h4>
                        <p>Customer sees helpful answer with product cards</p>
                    </div>
                </div>
            </div>
        </div>
    );
};

/**
 * Quick Setup Modal
 */
const QuickSetupModal = ({ onClose, onRun, isRunning, results }) => {
    return (
        <Modal
            title="Quick Setup"
            onRequestClose={onClose}
            className="glimmr-quick-setup-modal"
            isDismissible={!isRunning}
        >
            <div className="glimmr-quick-setup-content">
                {!results ? (
                    <>
                        <p>
                            Quick Setup will automatically complete all remaining setup steps:
                        </p>
                        <ul>
                            <li>Create vector store (if needed)</li>
                            <li>Index all WooCommerce products</li>
                            <li>Sync products to OpenAI for AI search</li>
                            <li>Sync knowledge base content</li>
                            <li>Enable the chat widget</li>
                        </ul>
                        <p className="glimmr-note">
                            <span className="dashicons dashicons-info"></span>
                            This may take a few minutes depending on catalog size.
                        </p>
                        <div className="glimmr-modal-actions">
                            <Button
                                variant="primary"
                                onClick={onRun}
                                disabled={isRunning}
                                isBusy={isRunning}
                            >
                                {isRunning ? 'Running Setup...' : 'Start Quick Setup'}
                            </Button>
                            <Button variant="secondary" onClick={onClose} disabled={isRunning}>
                                Cancel
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <div className="glimmr-setup-results">
                            <div className={`results-status ${results.complete ? 'success' : 'warning'}`}>
                                <span className={`dashicons dashicons-${results.complete ? 'yes-alt' : 'warning'}`}></span>
                                <span>{results.message}</span>
                            </div>
                            {results.steps && (
                                <ul className="results-steps">
                                    {Object.entries(results.steps).map(([key, step]) => (
                                        <li key={key} className={step.success ? 'success' : 'error'}>
                                            <span className={`dashicons dashicons-${step.success ? 'yes' : 'no'}`}></span>
                                            {step.message}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {results.errors && results.errors.length > 0 && (
                                <div className="results-errors">
                                    <h4>Issues:</h4>
                                    <ul>
                                        {results.errors.map((err, i) => (
                                            <li key={i}>{err}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                        <div className="glimmr-modal-actions">
                            <Button variant="primary" onClick={onClose}>
                                Done
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </Modal>
    );
};

/**
 * Main Get Started Component
 */
const GetStarted = () => {
    const [status, setStatus] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');
    const [showQuickSetup, setShowQuickSetup] = useState(false);
    const [isQuickSetupRunning, setIsQuickSetupRunning] = useState(false);
    const [quickSetupResults, setQuickSetupResults] = useState(null);

    // Fetch setup status
    const fetchStatus = useCallback(async () => {
        try {
            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'glimmr_ai_get_setup_status',
                    nonce: glimmrAI.nonce,
                }),
            });

            if (!response.ok) throw new Error('Server error');
            const data = await response.json();

            if (data.success) {
                setStatus(data.data);
            } else {
                setError(data.data?.message || 'Failed to load setup status.');
            }
        } catch (err) {
            setError(err.message || 'Failed to connect to server.');
        }

        setIsLoading(false);
    }, []);

    useEffect(() => {
        fetchStatus();
    }, [fetchStatus]);

    // Handle API key save
    const handleSaveApiKey = async (apiKey, model) => {
        const response = await fetch(glimmrAI.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'glimmr_ai_save_api_key_inline',
                nonce: glimmrAI.nonce,
                api_key: apiKey,
                model: model,
            }),
        });

        if (!response.ok) throw new Error('Server error');
        const data = await response.json();

        if (data.success) {
            await fetchStatus();
        }

        return { success: data.success, message: data.data?.message };
    };

    // Handle step actions
    const handleStepAction = async (action, params = {}) => {
        let ajaxAction;

        switch (action) {
            case 'create_vector_store':
                ajaxAction = 'glimmr_ai_create_vector_store';
                break;
            case 'reindex_products':
                ajaxAction = 'glimmr_ai_reindex_products';
                break;
            case 'sync_products':
                ajaxAction = 'glimmr_ai_sync_products';
                break;
            case 'sync_knowledge':
                ajaxAction = 'glimmr_ai_sync_knowledge';
                break;
            case 'toggle_widget':
                ajaxAction = 'glimmr_ai_toggle_widget';
                break;
            default:
                return { success: false, message: 'Unknown action' };
        }

        const response = await fetch(glimmrAI.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: ajaxAction,
                nonce: glimmrAI.nonce,
                ...params,
            }),
        });

        if (!response.ok) throw new Error('Server error');
        const data = await response.json();

        if (data.success) {
            await fetchStatus();
        }

        return { success: data.success, message: data.data?.message };
    };

    // Handle quick setup
    const handleQuickSetup = async () => {
        setIsQuickSetupRunning(true);
        setQuickSetupResults(null);

        try {
            const response = await fetch(glimmrAI.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'glimmr_ai_run_quick_setup',
                    nonce: glimmrAI.nonce,
                }),
            });

            if (!response.ok) throw new Error('Server error');
            const data = await response.json();

            if (data.success) {
                setQuickSetupResults({
                    complete: data.data.complete,
                    message: data.data.message,
                    steps: data.data.results?.steps,
                    errors: data.data.results?.errors,
                });
                await fetchStatus();
            } else {
                setQuickSetupResults({
                    complete: false,
                    message: data.data?.message || 'Quick setup failed.',
                    errors: [data.data?.message],
                });
            }
        } catch (err) {
            setQuickSetupResults({
                complete: false,
                message: 'Failed to run quick setup.',
                errors: [err.message],
            });
        }

        setIsQuickSetupRunning(false);
    };

    // Calculate prerequisites
    const prerequisites = status ? {
        apiKey: status.steps?.api_key?.complete,
        vectorStore: status.steps?.vector_store?.complete,
        products: status.steps?.products?.details?.synced > 0,
    } : {};

    // Loading state
    if (isLoading) {
        return (
            <div className="glimmr-get-started glimmr-loading">
                <Spinner />
                <span>Loading setup status...</span>
            </div>
        );
    }

    // Error state
    if (error) {
        return (
            <div className="glimmr-get-started">
                <Notice status="error" isDismissible={false}>
                    {error}
                    <Button variant="link" onClick={fetchStatus}>
                        Try Again
                    </Button>
                </Notice>
            </div>
        );
    }

    const steps = status?.steps || {};

    return (
        <div className="glimmr-get-started">
            {/* Header */}
            <div className="glimmr-get-started-header">
                <div className="header-content">
                    <h1>Get Started with Glimmr AI</h1>
                    <p>Set up your AI-powered shopping assistant in just a few steps.</p>
                </div>
                {status?.ready ? (
                    <div className="header-status ready">
                        <span className="dashicons dashicons-yes-alt"></span>
                        <span>Your AI assistant is ready!</span>
                    </div>
                ) : (
                    <Button
                        variant="primary"
                        className="quick-setup-btn"
                        onClick={() => setShowQuickSetup(true)}
                        disabled={!prerequisites.apiKey}
                    >
                        Quick Setup
                    </Button>
                )}
            </div>

            {/* Progress */}
            <Card className="glimmr-progress-card">
                <CardBody>
                    <SetupProgress steps={steps} currentStep={status?.overall_progress || 0} />
                </CardBody>
            </Card>

            {/* Step Cards */}
            <div className="glimmr-steps-container">
                {/* Step 1: API Key */}
                <Card className={`glimmr-step-card ${steps.api_key?.complete ? 'complete' : 'pending'}`}>
                    <CardBody>
                        <div className="glimmr-step-header">
                            <div className="step-indicator">
                                {steps.api_key?.complete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">1</span>
                                )}
                            </div>
                            <h3>Connect OpenAI</h3>
                        </div>
                        <ApiKeyStep
                            step={steps.api_key || {}}
                            onSave={handleSaveApiKey}
                        />
                    </CardBody>
                </Card>

                {/* Step 2: Vector Store */}
                <Card className={`glimmr-step-card ${steps.vector_store?.complete ? 'complete' : 'pending'}`}>
                    <CardBody>
                        <div className="glimmr-step-header">
                            <div className="step-indicator">
                                {steps.vector_store?.complete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">2</span>
                                )}
                            </div>
                            <h3>Initialize Vector Store</h3>
                        </div>
                        <VectorStoreStep
                            step={steps.vector_store || {}}
                            onAction={handleStepAction}
                            prerequisites={prerequisites}
                        />
                    </CardBody>
                </Card>

                {/* Step 3: Products */}
                <Card className={`glimmr-step-card ${steps.products?.complete ? 'complete' : 'pending'}`}>
                    <CardBody>
                        <div className="glimmr-step-header">
                            <div className="step-indicator">
                                {steps.products?.complete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">3</span>
                                )}
                            </div>
                            <h3>Index Products</h3>
                        </div>
                        <ProductsStep
                            step={steps.products || {}}
                            onAction={handleStepAction}
                            prerequisites={prerequisites}
                        />
                    </CardBody>
                </Card>

                {/* Step 4: Knowledge */}
                <Card className={`glimmr-step-card ${steps.knowledge?.complete ? 'complete' : 'pending'}`}>
                    <CardBody>
                        <div className="glimmr-step-header">
                            <div className="step-indicator">
                                {steps.knowledge?.complete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">4</span>
                                )}
                            </div>
                            <h3>Sync Knowledge Base</h3>
                            <span className="step-badge optional">Optional</span>
                        </div>
                        <KnowledgeStep
                            step={steps.knowledge || {}}
                            onAction={handleStepAction}
                            prerequisites={prerequisites}
                        />
                    </CardBody>
                </Card>

                {/* Step 5: Widget */}
                <Card className={`glimmr-step-card ${steps.widget?.complete ? 'complete' : 'pending'}`}>
                    <CardBody>
                        <div className="glimmr-step-header">
                            <div className="step-indicator">
                                {steps.widget?.complete ? (
                                    <span className="dashicons dashicons-yes-alt"></span>
                                ) : (
                                    <span className="step-number">5</span>
                                )}
                            </div>
                            <h3>Enable Chat Widget</h3>
                        </div>
                        <WidgetStep
                            step={steps.widget || {}}
                            onAction={handleStepAction}
                            prerequisites={prerequisites}
                        />
                    </CardBody>
                </Card>
            </div>

            {/* How It Works */}
            <Card className="glimmr-how-it-works-card">
                <CardBody>
                    <HowItWorks />
                </CardBody>
            </Card>

            {/* Quick Actions (when setup complete) */}
            {status?.ready && (
                <Card className="glimmr-quick-actions-card">
                    <CardBody>
                        <h3>Next Steps</h3>
                        <div className="glimmr-quick-actions">
                            <a href={glimmrAI.siteUrl} target="_blank" rel="noopener noreferrer" className="quick-action">
                                <span className="dashicons dashicons-visibility"></span>
                                <span>Test Chat Widget</span>
                            </a>
                            <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-dashboard`} className="quick-action">
                                <span className="dashicons dashicons-chart-bar"></span>
                                <span>View Analytics</span>
                            </a>
                            <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-prompts`} className="quick-action">
                                <span className="dashicons dashicons-edit"></span>
                                <span>Customize Prompts</span>
                            </a>
                            <a href={`${glimmrAI.ajaxUrl.replace('admin-ajax.php', 'admin.php')}?page=glimmr-ai-settings`} className="quick-action">
                                <span className="dashicons dashicons-admin-appearance"></span>
                                <span>Widget Appearance</span>
                            </a>
                        </div>
                    </CardBody>
                </Card>
            )}

            {/* Quick Setup Modal */}
            {showQuickSetup && (
                <QuickSetupModal
                    onClose={() => {
                        setShowQuickSetup(false);
                        setQuickSetupResults(null);
                    }}
                    onRun={handleQuickSetup}
                    isRunning={isQuickSetupRunning}
                    results={quickSetupResults}
                />
            )}
        </div>
    );
};

export default GetStarted;
