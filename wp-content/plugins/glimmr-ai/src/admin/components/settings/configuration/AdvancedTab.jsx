/**
 * Advanced Settings Tab
 *
 * Advanced configuration options for power users.
 *
 * @package Glimmr_AI
 * @since 1.9.0
 */

const { useState } = wp.element;
const {
    RangeControl,
    ToggleControl,
    Button,
    Icon,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * Collapsible section component for organizing advanced settings.
 */
const CollapsibleSection = ({ title, description, children, defaultOpen = false }) => {
    const [isOpen, setIsOpen] = useState(defaultOpen);

    return (
        <div className="glimmr-collapsible-section" style={{
            border: '1px solid #e0e0e0',
            borderRadius: '4px',
            marginBottom: '16px',
            background: '#fff',
        }}>
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    width: '100%',
                    padding: '12px 16px',
                    border: 'none',
                    background: isOpen ? '#f6f7f7' : 'transparent',
                    cursor: 'pointer',
                    textAlign: 'left',
                }}
            >
                <div>
                    <strong style={{ fontSize: '14px', color: '#1d2327' }}>{title}</strong>
                    {description && (
                        <p style={{ margin: '4px 0 0', fontSize: '12px', color: '#757575' }}>
                            {description}
                        </p>
                    )}
                </div>
                <Icon icon={isOpen ? 'arrow-up-alt2' : 'arrow-down-alt2'} />
            </button>
            {isOpen && (
                <div style={{ padding: '16px', borderTop: '1px solid #e0e0e0' }}>
                    {children}
                </div>
            )}
        </div>
    );
};

/**
 * AdvancedTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const AdvancedTab = ({ settings, onChange }) => {
    return (
        <div className="glimmr-settings-wide-controls">
            <InfoBox type="warning" title="Advanced Settings">
                These settings are intended for power users and developers.
                Changing these values may affect AI performance, response quality, and API costs.
                If you're unsure about a setting, leave it at the default value.
            </InfoBox>

            {/* Context Building Settings */}
            <CollapsibleSection
                title="Context Building"
                description="Control what customer information is included when the AI processes messages"
                defaultOpen={true}
            >
                <InfoBox type="info" title="What is Context?">
                    Context is the background information sent to the AI with each message.
                    More context allows for more personalized responses but increases token usage (and cost).
                    The AI uses this information to provide relevant answers without customers having to repeat themselves.
                </InfoBox>

                <ToggleControl
                    label="Include User Context"
                    checked={settings.include_user_context !== false}
                    onChange={(value) => onChange('include_user_context', value)}
                    help={
                        <HelpText type="tip">
                            When enabled, logged-in users' names and email addresses are shared with the AI.
                            This allows personalized greetings like "Hi Sarah!" and awareness of their account.
                            Disable if you prefer completely anonymous interactions.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Include Order History"
                    checked={settings.include_order_history !== false}
                    onChange={(value) => onChange('include_order_history', value)}
                    help={
                        <HelpText>
                            Share recent order information with the AI for logged-in customers.
                            Enables the AI to reference past purchases in recommendations and answer questions like "What did I order last time?"
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Max Orders in Context: ${settings.max_orders_in_context || 1}`}
                    value={settings.max_orders_in_context || 1}
                    onChange={(value) => onChange('max_orders_in_context', value)}
                    min={1}
                    max={5}
                    step={1}
                    help={
                        <HelpText>
                            How many recent orders to include. More orders = better context for repeat customers, but higher token usage.
                            1-2 is usually sufficient for most use cases.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Include Cart Context"
                    checked={settings.include_cart_context !== false}
                    onChange={(value) => onChange('include_cart_context', value)}
                    help={
                        <HelpText type="tip">
                            Share current cart contents with the AI.
                            Essential for questions like "Do you have a discount for what's in my cart?" or cart-aware recommendations.
                            Highly recommended to keep enabled.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Anonymize Customer Data"
                    checked={settings.anonymize_customer_data === true}
                    onChange={(value) => onChange('anonymize_customer_data', value)}
                    help={
                        <HelpText type="warning">
                            Strip personally identifiable information (names, emails, addresses) before sending to OpenAI.
                            Maximizes privacy but significantly reduces personalization - the AI won't know the customer's name or location.
                        </HelpText>
                    }
                />
            </CollapsibleSection>

            {/* API/HTTP Settings */}
            <CollapsibleSection
                title="API & HTTP Settings"
                description="Configure timeouts and retry behavior for OpenAI API requests"
            >
                <InfoBox type="info" title="When to Adjust These Settings">
                    Increase timeouts if you see frequent timeout errors, especially with complex queries.
                    Reduce retry attempts if you want faster failure responses.
                    These settings affect response latency and reliability.
                </InfoBox>

                <RangeControl
                    label={`Base API Timeout: ${settings.api_request_timeout_base || 90} seconds`}
                    value={settings.api_request_timeout_base || 90}
                    onChange={(value) => onChange('api_request_timeout_base', value)}
                    min={30}
                    max={180}
                    step={10}
                    help={
                        <HelpText>
                            How long to wait for an initial API response before giving up.
                            Complex queries (product comparisons, long conversations) may need more time.
                            Default of 90 seconds handles most cases well.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Max API Timeout: ${settings.api_request_timeout_max || 180} seconds`}
                    value={settings.api_request_timeout_max || 180}
                    onChange={(value) => onChange('api_request_timeout_max', value)}
                    min={60}
                    max={300}
                    step={10}
                    help={
                        <HelpText>
                            Absolute maximum wait time including all retries.
                            After this time, the request fails and the user sees an error message.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Retry Attempts: ${settings.retry_max_attempts || 3}`}
                    value={settings.retry_max_attempts || 3}
                    onChange={(value) => onChange('retry_max_attempts', value)}
                    min={1}
                    max={5}
                    step={1}
                    help={
                        <HelpText>
                            Number of times to retry a failed API request before giving up.
                            More retries = higher success rate but potentially longer wait times for users.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Retry Backoff Multiplier: ${settings.retry_backoff_multiplier || 2}x`}
                    value={settings.retry_backoff_multiplier || 2}
                    onChange={(value) => onChange('retry_backoff_multiplier', value)}
                    min={1}
                    max={4}
                    step={0.5}
                    help={
                        <HelpText>
                            Wait time increases exponentially between retries (1s, 2s, 4s with 2x multiplier).
                            Higher values give the API more recovery time but increase total wait.
                        </HelpText>
                    }
                />
            </CollapsibleSection>

            {/* Tool Execution Settings */}
            <CollapsibleSection
                title="Tool Execution"
                description="Configure how the AI uses tools like product search and cart operations"
            >
                <InfoBox type="info" title="What Are Tools?">
                    Tools are actions the AI can take - searching products, checking orders, adding to cart, etc.
                    Each "tool call" costs tokens and takes time. These settings control tool behavior and limits.
                </InfoBox>

                <RangeControl
                    label={`Max Tool Execution Rounds: ${settings.max_tool_execution_rounds || 5}`}
                    value={settings.max_tool_execution_rounds || 5}
                    onChange={(value) => onChange('max_tool_execution_rounds', value)}
                    min={1}
                    max={10}
                    step={1}
                    help={
                        <HelpText type="warning">
                            Maximum times the AI can call tools per message. Higher = more thorough answers but significantly higher costs.
                            For example, comparing 5 products might need 2-3 rounds. Default of 5 handles complex queries well.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Product Search Default Limit: ${settings.product_search_default_limit || 5}`}
                    value={settings.product_search_default_limit || 5}
                    onChange={(value) => onChange('product_search_default_limit', value)}
                    min={3}
                    max={10}
                    step={1}
                    help={
                        <HelpText>
                            Default number of products returned in search results when the user doesn't specify.
                            "Show me hoodies" would return this many products.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Product Search Max Limit: ${settings.product_search_max_limit || 10}`}
                    value={settings.product_search_max_limit || 10}
                    onChange={(value) => onChange('product_search_max_limit', value)}
                    min={5}
                    max={20}
                    step={1}
                    help={
                        <HelpText>
                            Maximum products that can be returned in a single search, even if the user asks for more.
                            Prevents excessively large responses that slow down the chat.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Semantic Search Min Score: ${(settings.semantic_min_score || 0.80).toFixed(2)}`}
                    value={settings.semantic_min_score || 0.80}
                    onChange={(value) => onChange('semantic_min_score', value)}
                    min={0.50}
                    max={1.00}
                    step={0.05}
                    help={
                        <HelpText type="tip">
                            Minimum similarity score (0-1) for vector search results. Higher = more relevant but fewer results.
                            Lower this if searches return too few products. Raise it if results seem off-topic.
                            0.80 is a good balance for most catalogs.
                        </HelpText>
                    }
                />
            </CollapsibleSection>

            {/* Token/Context Window Settings */}
            <CollapsibleSection
                title="Token & Context Window"
                description="Fine-tune how conversation history is managed for long chats"
            >
                <InfoBox type="info" title="Understanding Tokens & Context">
                    AI models have a limited "context window" - the amount of text they can process at once.
                    Long conversations need to be trimmed to fit. These settings control how that trimming works.
                    <strong> 1 token ≈ 4 characters</strong> (for English text).
                </InfoBox>

                <RangeControl
                    label={`Context Reserve Tokens: ${settings.context_reserve_tokens || 1000}`}
                    value={settings.context_reserve_tokens || 1000}
                    onChange={(value) => onChange('context_reserve_tokens', value)}
                    min={500}
                    max={2000}
                    step={100}
                    help={
                        <HelpText>
                            Tokens reserved for the AI's response. Higher values allow longer, more detailed responses.
                            If responses seem cut off, increase this. Default of 1000 allows ~750 words.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Sliding Window Threshold: ${settings.messages_before_sliding_window || 10} messages`}
                    value={settings.messages_before_sliding_window || 10}
                    onChange={(value) => onChange('messages_before_sliding_window', value)}
                    min={5}
                    max={20}
                    step={1}
                    help={
                        <HelpText>
                            Start trimming old messages after this many exchanges.
                            Lower = aggressive trimming (saves tokens), Higher = more context preserved.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Minimum Recent Messages: ${settings.minimum_recent_messages || 4}`}
                    value={settings.minimum_recent_messages || 4}
                    onChange={(value) => onChange('minimum_recent_messages', value)}
                    min={2}
                    max={10}
                    step={1}
                    help={
                        <HelpText>
                            Always keep this many recent messages, even when trimming.
                            Ensures the AI remembers the immediate conversation flow.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Token Estimation Ratio: ${settings.token_estimation_chars_per_token || 4} chars/token`}
                    value={settings.token_estimation_chars_per_token || 4}
                    onChange={(value) => onChange('token_estimation_chars_per_token', value)}
                    min={3}
                    max={6}
                    step={0.5}
                    help={
                        <HelpText>
                            Characters per token for cost estimation. Default of 4 is accurate for English.
                            Non-Latin scripts (Chinese, Japanese, Korean, Arabic) use more tokens per character - try 2-3.
                        </HelpText>
                    }
                />
            </CollapsibleSection>

            {/* Reset to Defaults */}
            <div style={{ marginTop: '24px', textAlign: 'right' }}>
                <Button
                    variant="secondary"
                    onClick={() => {
                        if (!confirm('Reset all advanced settings to their default values?')) return;

                        const defaults = {
                            include_user_context: true,
                            include_order_history: true,
                            max_orders_in_context: 1,
                            include_cart_context: true,
                            anonymize_customer_data: false,
                            api_request_timeout_base: 90,
                            api_request_timeout_max: 180,
                            retry_max_attempts: 3,
                            retry_backoff_multiplier: 2,
                            max_tool_execution_rounds: 5,
                            product_search_default_limit: 5,
                            product_search_max_limit: 10,
                            semantic_min_score: 0.80,
                            context_reserve_tokens: 1000,
                            messages_before_sliding_window: 10,
                            minimum_recent_messages: 4,
                            token_estimation_chars_per_token: 4,
                        };
                        Object.entries(defaults).forEach(([key, value]) => {
                            onChange(key, value);
                        });
                    }}
                >
                    Reset to Defaults
                </Button>
            </div>
        </div>
    );
};

export default AdvancedTab;
