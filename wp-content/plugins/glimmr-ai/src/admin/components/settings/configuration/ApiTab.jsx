/**
 * API Configuration Tab
 *
 * OpenAI API settings including model selection, API key, and response limits.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    TextControl,
    SelectControl,
    RangeControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';
import { formatFullNumber, getReasoningEffortOptions } from '../utils';

/**
 * ApiTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const ApiTab = ({ settings, onChange }) => {
    const modelConfigs = settings._all_model_configs || {};
    const currentModel = settings.openai_model || 'gpt-4o-mini';
    const reasoningOptions = getReasoningEffortOptions(modelConfigs, currentModel);

    return (
        <>
            <SettingsSection
                title="OpenAI Configuration"
                description="Connect your OpenAI account to power the AI shopping assistant. You'll need an API key from platform.openai.com."
            >
                <InfoBox type="info" title="Getting Started">
                    <ol style={{ margin: '0', paddingLeft: '20px' }}>
                        <li>Create an account at <strong>platform.openai.com</strong></li>
                        <li>Go to API Keys and create a new secret key</li>
                        <li>Copy the key and paste it below</li>
                        <li>Add billing information to your OpenAI account</li>
                    </ol>
                </InfoBox>

                <TextControl
                    label="API Key"
                    type="password"
                    value={settings.openai_api_key || ''}
                    onChange={(value) => onChange('openai_api_key', value)}
                    help={
                        <HelpText type="warning">
                            Your API key is encrypted before storage. Never share this key publicly.
                        </HelpText>
                    }
                />

                <SelectControl
                    label="Model"
                    value={currentModel}
                    options={[
                        { value: '', label: '— GPT-5 Series —', disabled: true },
                        { value: 'gpt-5.2', label: 'GPT-5.2 (Most Capable)' },
                        { value: 'gpt-5.1', label: 'GPT-5.1' },
                        { value: 'gpt-5', label: 'GPT-5' },
                        { value: 'gpt-5-mini', label: 'GPT-5 Mini (Faster, Lower Cost)' },
                        { value: 'gpt-5-nano', label: 'GPT-5 Nano (Ultra-Fast)' },
                        { value: '', label: '— GPT-4o Series —', disabled: true },
                        { value: 'gpt-4o', label: 'GPT-4o (Fast, Multimodal)' },
                        { value: 'gpt-4o-mini', label: 'GPT-4o Mini (Fastest & Cheapest)' },
                        { value: '', label: '— GPT-4.1 Series —', disabled: true },
                        { value: 'gpt-4.1', label: 'GPT-4.1 (Best Overall)' },
                        { value: 'gpt-4.1-mini', label: 'GPT-4.1 Mini (Faster, Cheaper)' },
                        { value: 'gpt-4.1-nano', label: 'GPT-4.1 Nano (Low Cost)' },
                        { value: '', label: '— Reasoning Models —', disabled: true },
                        { value: 'o4-mini', label: 'o4-mini (Advanced Reasoning)' },
                        { value: 'o3-mini', label: 'o3-mini (Lightweight Reasoning)' },
                        { value: '', label: '— Legacy —', disabled: true },
                        { value: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
                        { value: 'gpt-4', label: 'GPT-4' },
                    ]}
                    onChange={(value) => {
                        onChange('openai_model', value);
                        // Clear reasoning_effort if new model doesn't support it
                        const newModelConfig = modelConfigs[value];
                        if (!newModelConfig?.reasoning_effort?.supported) {
                            onChange('reasoning_effort', '');
                        } else if (!settings.reasoning_effort) {
                            // Set to model's default if not already set
                            onChange('reasoning_effort', newModelConfig.reasoning_effort.default || 'low');
                        }
                    }}
                    help={
                        <HelpText type="tip">
                            <strong>Recommended:</strong> GPT-4o Mini for best balance of speed and cost. Use GPT-4o or GPT-4.1 for complex product catalogs.
                        </HelpText>
                    }
                />

                {reasoningOptions && (
                    <SelectControl
                        label="Reasoning Effort"
                        value={settings.reasoning_effort || reasoningOptions.default}
                        options={reasoningOptions.available}
                        onChange={(value) => onChange('reasoning_effort', value)}
                        help={
                            <HelpText>
                                Controls how much "thinking" the model does before responding. Lower = faster responses, Higher = more thorough reasoning.
                                {settings.reasoning_effort && reasoningOptions.available.find(o => o.value === settings.reasoning_effort)?.description && (
                                    <><br /><em>{reasoningOptions.available.find(o => o.value === settings.reasoning_effort)?.description}</em></>
                                )}
                            </HelpText>
                        }
                    />
                )}
            </SettingsSection>

            <SettingsSection
                title="Vector Store (Knowledge Base)"
                description="The vector store enables the AI to search your product catalog and knowledge base for relevant information."
            >
                <TextControl
                    label="Vector Store ID"
                    value={settings.openai_vector_store_id || ''}
                    onChange={(value) => onChange('openai_vector_store_id', value)}
                    help={
                        <HelpText>
                            Leave empty to auto-create a new vector store. Only enter a value if migrating from an existing store.
                        </HelpText>
                    }
                />

                <InfoBox type="tip" title="How Vector Search Works">
                    When a customer asks about products, the AI searches your vector store to find relevant items based on meaning (not just keywords).
                    This enables questions like "show me warm jackets for hiking" to find appropriate products even if they don't contain those exact words.
                </InfoBox>
            </SettingsSection>

            <SettingsSection
                title="Response Settings"
                description="Control how the AI generates responses."
            >
                <RangeControl
                    label={`Max Tokens Per Response: ${formatFullNumber(settings.max_tokens_per_response || 1000)}`}
                    value={settings.max_tokens_per_response || 1000}
                    onChange={(value) => onChange('max_tokens_per_response', value)}
                    min={100}
                    max={4000}
                    step={100}
                    help={
                        <HelpText>
                            Limits how long each AI response can be. 1 token ≈ 4 characters. Higher values allow longer responses but cost more. Default: 1000 (about 750 words max).
                        </HelpText>
                    }
                />
            </SettingsSection>
        </>
    );
};

export default ApiTab;
