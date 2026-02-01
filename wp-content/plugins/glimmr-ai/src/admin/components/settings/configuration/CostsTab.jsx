/**
 * Cost Controls Tab
 *
 * Rate limiting and token budget settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const { RangeControl, TextControl } = wp.components;

import SettingsSection from '../SettingsSection';
import { TokenLimitControl, HelpText, InfoBox } from '../SharedControls';

/**
 * CostsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const CostsTab = ({ settings, onChange }) => {
    const dailyTokens = settings.daily_token_limit || 100000;
    const monthlyTokens = settings.monthly_token_limit || 2000000;

    return (
        <div className="glimmr-settings-wide-controls">
            <InfoBox type="info" title="Understanding API Costs">
                OpenAI charges based on <strong>tokens</strong> (roughly 4 characters = 1 token).
                The settings below help you control spending and prevent unexpected charges.
                Monitor your actual usage at <strong>platform.openai.com/usage</strong>.
            </InfoBox>

            <SettingsSection
                title="Spending Limits"
                description="Set dollar-based spending limits to automatically pause the assistant when thresholds are reached."
            >
                <div style={{ display: 'flex', gap: '20px', flexWrap: 'wrap' }}>
                    <div style={{ flex: '1', minWidth: '200px' }}>
                        <TextControl
                            label="Daily Cost Limit ($)"
                            type="number"
                            value={settings.daily_cost_limit || 10}
                            onChange={(value) => onChange('daily_cost_limit', parseFloat(value) || 0)}
                            min={0}
                            step={1}
                            help={
                                <HelpText>
                                    Stops the assistant if daily API costs exceed this amount. Set to 0 to disable.
                                </HelpText>
                            }
                        />
                    </div>
                    <div style={{ flex: '1', minWidth: '200px' }}>
                        <TextControl
                            label="Monthly Cost Limit ($)"
                            type="number"
                            value={settings.monthly_cost_limit || 100}
                            onChange={(value) => onChange('monthly_cost_limit', parseFloat(value) || 0)}
                            min={0}
                            step={5}
                            help={
                                <HelpText>
                                    Stops the assistant if monthly API costs exceed this amount. Set to 0 to disable.
                                </HelpText>
                            }
                        />
                    </div>
                </div>

                <RangeControl
                    label={`Token Cost per Million: $${settings.token_cost_per_million || 5}`}
                    value={settings.token_cost_per_million || 5}
                    onChange={(value) => onChange('token_cost_per_million', value)}
                    min={1}
                    max={30}
                    step={0.5}
                    help={
                        <HelpText type="tip">
                            Used for cost calculations. GPT-4o Mini ≈ $0.15/M, GPT-4o ≈ $5/M, GPT-4 ≈ $30/M. Check OpenAI pricing for current rates.
                        </HelpText>
                    }
                />
            </SettingsSection>

            <SettingsSection
                title="Rate Limiting"
                description="Limit how many messages users can send to prevent abuse and control costs."
            >
                <RangeControl
                    label={`Logged-In Users: ${settings.rate_limit_authenticated || 100} messages/hour`}
                    value={settings.rate_limit_authenticated || 100}
                    onChange={(value) => onChange('rate_limit_authenticated', value)}
                    min={10}
                    max={500}
                    step={10}
                    help={
                        <HelpText>
                            Customers with accounts can send more messages since they're identifiable and accountable.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Guest Users: ${settings.rate_limit_anonymous || 20} messages/hour`}
                    value={settings.rate_limit_anonymous || 20}
                    onChange={(value) => onChange('rate_limit_anonymous', value)}
                    min={5}
                    max={100}
                    step={5}
                    help={
                        <HelpText type="warning">
                            Keep this lower to prevent abuse from anonymous visitors. They're tracked by IP address.
                        </HelpText>
                    }
                />
            </SettingsSection>

            <SettingsSection
                title="Token Budgets"
                description="Set maximum token usage across all conversations. When limits are reached, the assistant pauses until the period resets."
            >
                <TokenLimitControl
                    label="Daily Token Budget"
                    value={dailyTokens}
                    onChange={(value) => onChange('daily_token_limit', value)}
                    min={10000}
                    max={1000000}
                    step={10000}
                    help={
                        <HelpText>
                            Total tokens allowed per day across all conversations. Resets at midnight (server time). 100,000 tokens ≈ $0.50 with GPT-4o Mini.
                        </HelpText>
                    }
                />

                <TokenLimitControl
                    label="Monthly Token Budget"
                    value={monthlyTokens}
                    onChange={(value) => onChange('monthly_token_limit', value)}
                    min={100000}
                    max={20000000}
                    step={100000}
                    help={
                        <HelpText>
                            Total tokens allowed per month. Resets on the 1st of each month.
                        </HelpText>
                    }
                />
            </SettingsSection>

            <SettingsSection
                title="Conversation Limits"
                description="Control how long conversations can last before they're automatically closed."
            >
                <RangeControl
                    label={`Max Messages Per Conversation: ${settings.max_messages_per_conversation || 50}`}
                    value={settings.max_messages_per_conversation || 50}
                    onChange={(value) => onChange('max_messages_per_conversation', value)}
                    min={10}
                    max={200}
                    step={10}
                    help={
                        <HelpText>
                            After this many messages, a new conversation starts. Helps keep conversations focused and prevents runaway token usage.
                        </HelpText>
                    }
                />

                <RangeControl
                    label={`Conversation Timeout: ${settings.conversation_expiry_days || 30} days`}
                    value={settings.conversation_expiry_days || 30}
                    onChange={(value) => onChange('conversation_expiry_days', value)}
                    min={1}
                    max={365}
                    step={1}
                    help={
                        <HelpText>
                            Conversations without activity for this long are considered closed. The customer starts fresh on their next visit.
                        </HelpText>
                    }
                />
            </SettingsSection>

            <SettingsSection
                title="AI Memory (Context Window)"
                description="Control how much conversation history the AI can remember when responding."
            >
                <RangeControl
                    label={`Context Window Size: ${(settings.max_context_tokens || 32000).toLocaleString()} tokens`}
                    value={settings.max_context_tokens || 32000}
                    onChange={(value) => onChange('max_context_tokens', value)}
                    min={4000}
                    max={128000}
                    step={1000}
                    help={
                        <HelpText type="tip">
                            Larger context = AI remembers more of the conversation, but costs more per message. 32,000 is good for most stores. Increase for complex product discussions.
                        </HelpText>
                    }
                />

                <InfoBox type="info" title="What is a Context Window?">
                    The context window is the AI's "working memory" - how much of the conversation it can see when generating a response.
                    It includes the system prompt, conversation history, product data, and more. When the window fills up, older messages are dropped.
                </InfoBox>
            </SettingsSection>
        </div>
    );
};

export default CostsTab;
