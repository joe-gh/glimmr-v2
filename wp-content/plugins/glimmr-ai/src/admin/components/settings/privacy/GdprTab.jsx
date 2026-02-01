/**
 * GDPR Tab
 *
 * Privacy consent and data retention settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    ToggleControl,
    TextareaControl,
    RangeControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * GdprTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const GdprTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="warning" title="Privacy Compliance">
            These settings help you comply with GDPR and other privacy regulations.
            Consult with a legal professional to ensure your configuration meets your specific requirements.
        </InfoBox>

        <SettingsSection
            title="Privacy Consent"
            description="Require customer consent before storing conversation data."
        >
            <ToggleControl
                label="Enable GDPR Consent"
                checked={settings.gdpr_enabled !== false}
                onChange={(value) => onChange('gdpr_enabled', value)}
                help={
                    <HelpText>
                        When enabled, customers must agree to your privacy policy before their conversation is stored. Required for GDPR compliance in the EU.
                    </HelpText>
                }
            />

            <TextareaControl
                label="Consent Text"
                value={settings.gdpr_consent_text || 'By chatting, you agree to our privacy policy.'}
                onChange={(value) => onChange('gdpr_consent_text', value)}
                help={
                    <HelpText type="tip">
                        Text shown when requesting consent. Include a link to your privacy policy. HTML is allowed, e.g.: <code>&lt;a href="/privacy"&gt;privacy policy&lt;/a&gt;</code>
                    </HelpText>
                }
                rows={2}
            />
        </SettingsSection>

        <SettingsSection
            title="Data Retention"
            description="Control how long conversation data is stored and accessible."
        >
            <RangeControl
                label={`Permanent Data Retention: ${settings.data_retention_days || 365} days`}
                value={settings.data_retention_days || 365}
                onChange={(value) => onChange('data_retention_days', value)}
                min={30}
                max={730}
                step={30}
                help={
                    <HelpText type="warning">
                        Conversation data is <strong>permanently deleted</strong> after this period. GDPR recommends not keeping personal data longer than necessary. Set based on your business needs and legal requirements.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Customer History Access: ${settings.conversation_history_retention_days || 30} days`}
                value={settings.conversation_history_retention_days || 30}
                onChange={(value) => onChange('conversation_history_retention_days', value)}
                min={1}
                max={90}
                step={1}
                help={
                    <HelpText>
                        How long customers can retrieve their conversation history via the chat widget. After this period, history requests return empty (but data may still exist until the retention period above).
                    </HelpText>
                }
            />

            <InfoBox type="info" title="Understanding Retention Settings">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li><strong>Customer History Access ({settings.conversation_history_retention_days || 30} days):</strong> Customers can see their past conversations</li>
                    <li><strong>Data Retention ({settings.data_retention_days || 365} days):</strong> Data exists in database (for analytics, support)</li>
                    <li><strong>After Data Retention:</strong> Data is permanently deleted via automated cleanup</li>
                </ul>
            </InfoBox>
        </SettingsSection>

        <SettingsSection
            title="WordPress Privacy Tools Integration"
            description="Integrate with WordPress's built-in privacy tools for data export and erasure requests."
        >
            <ToggleControl
                label="Include in Privacy Export"
                checked={settings.privacy_export_enabled !== false}
                onChange={(value) => onChange('privacy_export_enabled', value)}
                help={
                    <HelpText>
                        When a user requests their data (Tools → Export Personal Data), include their chat conversation history in the export.
                    </HelpText>
                }
            />

            <ToggleControl
                label="Include in Privacy Erasure"
                checked={settings.privacy_erasure_enabled !== false}
                onChange={(value) => onChange('privacy_erasure_enabled', value)}
                help={
                    <HelpText type="warning">
                        When a user requests data erasure (Tools → Erase Personal Data), delete their chat conversations. Required for GDPR "right to be forgotten" compliance.
                    </HelpText>
                }
            />

            <InfoBox type="tip" title="How Privacy Requests Work">
                <ol style={{ margin: 0, paddingLeft: '20px' }}>
                    <li>Customer submits request via your privacy request form</li>
                    <li>You receive notification in WordPress (Tools → Export/Erase Personal Data)</li>
                    <li>You verify and approve the request</li>
                    <li>WordPress processes the request, including Glimmr AI data if enabled above</li>
                    <li>Customer receives confirmation email</li>
                </ol>
            </InfoBox>
        </SettingsSection>
    </>
);

export default GdprTab;
