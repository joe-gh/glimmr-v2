/**
 * Logging Tab
 *
 * Log settings and live log viewer.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { useState, useEffect } = wp.element;
const {
    SelectControl,
    ToggleControl,
    Button,
    Spinner,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * Get level color for log entries.
 */
const getLevelColor = (level) => {
    switch (level) {
        case 'debug': return '#6b7280';
        case 'info': return '#3b82f6';
        case 'warning': return '#f59e0b';
        case 'error': return '#ef4444';
        case 'critical': return '#dc2626';
        default: return '#6b7280';
    }
};

/**
 * Format file size.
 */
const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

/**
 * LoggingTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const LoggingTab = ({ settings, onChange }) => {
    const [logs, setLogs] = useState({ entries: [], file_size: 0, file_name: '', log_level: 'warning' });
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [clearing, setClearing] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(false);
    const [filterLevel, setFilterLevel] = useState('all');

    const { ajaxUrl, nonce } = window.glimmrAI || {};

    // Fetch logs
    const fetchLogs = async () => {
        setLoadingLogs(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_get_logs');
            formData.append('nonce', nonce);
            formData.append('lines', 200);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();
            if (result.success) {
                setLogs(result.data);
            }
        } catch (err) {
            console.error('Failed to fetch logs:', err);
        }
        setLoadingLogs(false);
    };

    // Clear logs
    const clearLogs = async () => {
        if (!confirm('Are you sure you want to clear all logs?')) return;

        setClearing(true);
        try {
            const formData = new FormData();
            formData.append('action', 'glimmr_ai_clear_logs');
            formData.append('nonce', nonce);

            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();
            if (result.success) {
                fetchLogs();
            }
        } catch (err) {
            console.error('Failed to clear logs:', err);
        }
        setClearing(false);
    };

    // Download logs
    const downloadLogs = () => {
        const url = `${ajaxUrl}?action=glimmr_ai_download_logs&nonce=${nonce}`;
        window.open(url, '_blank');
    };

    // Auto-refresh effect
    useEffect(() => {
        fetchLogs();

        let interval;
        if (autoRefresh) {
            interval = setInterval(fetchLogs, 5000);
        }

        return () => {
            if (interval) clearInterval(interval);
        };
    }, [autoRefresh]);

    // Filter entries by level
    const filteredEntries = filterLevel === 'all'
        ? logs.entries
        : logs.entries.filter(e => e.level === filterLevel);

    return (
        <>
            <InfoBox type="info" title="Debugging & Troubleshooting">
                Logs help diagnose issues with the AI assistant. During normal operation, use "Warning" level.
                Switch to "Debug" when troubleshooting specific problems, then switch back to avoid large log files.
            </InfoBox>

            <SettingsSection
                title="Log Settings"
                description="Configure what gets logged for debugging and monitoring."
            >
                <SelectControl
                    label="Log Level"
                    value={settings.log_level || 'warning'}
                    options={[
                        { value: 'debug', label: 'Debug (All messages)' },
                        { value: 'info', label: 'Info (Info and above)' },
                        { value: 'warning', label: 'Warning (Warnings and errors)' },
                        { value: 'error', label: 'Error (Errors only)' },
                        { value: 'critical', label: 'Critical (Critical only)' },
                    ]}
                    onChange={(value) => onChange('log_level', value)}
                    help={
                        <HelpText>
                            <strong>Debug:</strong> Everything, very verbose (use only for troubleshooting)<br />
                            <strong>Info:</strong> General activity + warnings/errors<br />
                            <strong>Warning:</strong> Potential issues + errors (recommended for production)<br />
                            <strong>Error/Critical:</strong> Only serious problems
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Log AI Requests"
                    checked={settings.log_ai_requests === true}
                    onChange={(value) => onChange('log_ai_requests', value)}
                    help={
                        <HelpText type="warning">
                            Logs full OpenAI API request/response data. Very verbose - can create large log files. Enable only when debugging AI behavior issues.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Log Tool Execution"
                    checked={settings.log_tool_execution !== false}
                    onChange={(value) => onChange('log_tool_execution', value)}
                    help={
                        <HelpText>
                            Log when AI tools (product search, add to cart, etc.) are executed. Useful for understanding what actions the AI is taking.
                        </HelpText>
                    }
                />
            </SettingsSection>

            <SettingsSection
                title="Log Viewer"
                description="View recent log entries in real-time. Use filters to find specific issues."
            >
                <div className="glimmr-log-controls">
                    <Button
                        variant="secondary"
                        onClick={fetchLogs}
                        disabled={loadingLogs}
                    >
                        {loadingLogs ? <Spinner /> : 'Refresh'}
                    </Button>

                    <Button
                        variant="secondary"
                        onClick={downloadLogs}
                        disabled={!logs.file_name}
                    >
                        Download Log
                    </Button>

                    <Button
                        variant="secondary"
                        isDestructive
                        onClick={clearLogs}
                        disabled={clearing || !logs.file_name}
                    >
                        {clearing ? 'Clearing...' : 'Clear Logs'}
                    </Button>

                    <ToggleControl
                        label="Auto-refresh (5s)"
                        checked={autoRefresh}
                        onChange={setAutoRefresh}
                        className="glimmr-auto-refresh-toggle"
                    />

                    <SelectControl
                        value={filterLevel}
                        options={[
                            { value: 'all', label: 'All Levels' },
                            { value: 'debug', label: 'Debug' },
                            { value: 'info', label: 'Info' },
                            { value: 'warning', label: 'Warning' },
                            { value: 'error', label: 'Error' },
                            { value: 'critical', label: 'Critical' },
                        ]}
                        onChange={setFilterLevel}
                        __nextHasNoMarginBottom
                    />
                </div>

                {logs.file_name && (
                    <div className="glimmr-log-info">
                        <strong>File:</strong> {logs.file_name} ({formatSize(logs.file_size)}) |
                        <strong> Current Level:</strong> {logs.log_level} |
                        <strong> Showing:</strong> {filteredEntries.length} entries
                    </div>
                )}

                <div className="glimmr-log-viewer">
                    {loadingLogs && !logs.entries.length ? (
                        <div className="glimmr-log-loading">
                            <Spinner />
                            <p>Loading logs...</p>
                        </div>
                    ) : filteredEntries.length === 0 ? (
                        <div className="glimmr-log-empty">
                            No log entries found. Send a message in the chat widget to generate logs.
                        </div>
                    ) : (
                        filteredEntries.map((entry, index) => (
                            <div
                                key={index}
                                className="glimmr-log-entry"
                                style={{ borderLeftColor: getLevelColor(entry.level) }}
                            >
                                <span className="glimmr-log-timestamp">{entry.timestamp}</span>
                                {' '}
                                <span
                                    className="glimmr-log-level"
                                    style={{ color: getLevelColor(entry.level) }}
                                >
                                    [{entry.level}]
                                </span>
                                {entry.context && (
                                    <span className="glimmr-log-context"> [{entry.context}]</span>
                                )}
                                {' '}
                                <span className="glimmr-log-message">{entry.message}</span>
                            </div>
                        ))
                    )}
                </div>

                <InfoBox type="tip" title="Common Log Patterns">
                    <ul style={{ margin: 0, paddingLeft: '20px' }}>
                        <li><strong>Rate limit exceeded:</strong> User sending too many messages</li>
                        <li><strong>API error 401:</strong> Invalid or expired API key</li>
                        <li><strong>API error 429:</strong> OpenAI rate limit - wait and retry</li>
                        <li><strong>Tool execution failed:</strong> Check tool parameters and permissions</li>
                        <li><strong>Vector store error:</strong> Knowledge base sync issue</li>
                    </ul>
                </InfoBox>
            </SettingsSection>
        </>
    );
};

export default LoggingTab;
