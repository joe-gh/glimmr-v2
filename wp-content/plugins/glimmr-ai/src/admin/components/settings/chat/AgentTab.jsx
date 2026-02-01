/**
 * Agent Tab
 *
 * Agent personality, tone, and fallback settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    SelectControl,
    TextareaControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * AgentTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const AgentTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="info" title="Customizing Your AI Assistant">
            These settings control how your AI assistant communicates with customers.
            For advanced customization like the system prompt and tool configuration, use the <strong>Prompts & Tools</strong> page.
        </InfoBox>

        <SettingsSection
            title="Communication Style"
            description="Set the overall tone and personality for customer interactions."
        >
            <SelectControl
                label="Conversation Tone"
                value={settings.agent_tone || 'friendly'}
                options={[
                    { value: 'friendly', label: 'Friendly & Helpful' },
                    { value: 'professional', label: 'Professional & Formal' },
                    { value: 'casual', label: 'Casual & Conversational' },
                ]}
                onChange={(value) => onChange('agent_tone', value)}
                help={
                    <HelpText>
                        <strong>Friendly:</strong> Warm, approachable, uses contractions (best for most stores)<br />
                        <strong>Professional:</strong> Formal, business-like (good for B2B or luxury brands)<br />
                        <strong>Casual:</strong> Very relaxed, may use slang (good for youth-oriented brands)
                    </HelpText>
                }
            />

            <TextareaControl
                label="Personality Notes"
                value={settings.agent_personality || ''}
                onChange={(value) => onChange('agent_personality', value)}
                help={
                    <HelpText type="tip">
                        Additional instructions for the AI's personality. These are added to the system prompt. Be specific about what you want.
                    </HelpText>
                }
                rows={4}
                placeholder="Examples:
• Always mention our free shipping on orders over $50
• Use emoji sparingly (only 👍 and ✨)
• Refer to customers as 'friend'
• Never use the word 'unfortunately'"
            />
        </SettingsSection>

        <SettingsSection
            title="Fallback Behavior"
            description="Configure what happens when the AI can't answer a question."
        >
            <TextareaControl
                label="Fallback Response"
                value={settings.fallback_response || "I'm not sure about that. Would you like to speak with our support team?"}
                onChange={(value) => onChange('fallback_response', value)}
                help={
                    <HelpText>
                        Message shown when the AI cannot confidently answer a question. Should offer alternative help options.
                    </HelpText>
                }
                rows={3}
            />

            <InfoBox type="tip" title="Good Fallback Responses">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li>Acknowledge the limitation honestly</li>
                    <li>Offer to connect with human support</li>
                    <li>Suggest alternative questions the AI can help with</li>
                    <li>Provide direct contact information</li>
                </ul>
            </InfoBox>

            <InfoBox type="info" title="Support Contact Information">
                <p style={{ margin: 0 }}>
                    <span className="dashicons dashicons-arrow-right-alt" style={{ marginRight: '6px' }}></span>
                    Configure support email and phone in <strong>Configuration → Support</strong> tab.
                    The AI will use these when customers ask for help.
                </p>
            </InfoBox>
        </SettingsSection>
    </>
);

export default AgentTab;
