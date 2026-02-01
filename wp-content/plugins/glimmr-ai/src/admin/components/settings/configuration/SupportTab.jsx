/**
 * Support Tab
 *
 * Settings for contact form email routing and support configuration.
 *
 * @package Glimmr_AI
 * @since 1.9.0
 */

const {
    TextControl,
    ToggleControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * SupportTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const SupportTab = ({ settings, onChange }) => {
    return (
        <>
            <InfoBox type="info" title="Support Contact Information">
                These settings control where customer support requests are sent and what contact information the AI provides when customers ask for help.
            </InfoBox>

            {/* Contact Form Settings */}
            <SettingsSection
                title="Contact Form Email Routing"
                description="Configure where contact form submissions from the chat widget are delivered."
            >
                <TextControl
                    label="Contact Request Email"
                    type="email"
                    value={settings.contact_request_email || ''}
                    onChange={(value) => onChange('contact_request_email', value)}
                    placeholder="support@yourstore.com"
                    help={
                        <HelpText>
                            Primary email for contact form submissions. This is where support requests from the chat widget will be sent.
                        </HelpText>
                    }
                />

                <TextControl
                    label="Support Email (Fallback)"
                    type="email"
                    value={settings.support_email || ''}
                    onChange={(value) => onChange('support_email', value)}
                    placeholder="help@yourstore.com"
                    help={
                        <HelpText>
                            General support email used as fallback for contact requests. Also shown in AI responses when customers ask "how do I contact you?"
                        </HelpText>
                    }
                />

                <TextControl
                    label="Support Phone"
                    value={settings.support_phone || ''}
                    onChange={(value) => onChange('support_phone', value)}
                    placeholder="+1 (555) 123-4567"
                    help={
                        <HelpText>
                            Phone number the AI will provide when customers ask for support contact options.
                        </HelpText>
                    }
                />

                <InfoBox type="tip" title="Email Routing Priority">
                    Contact requests are sent to (in order of priority):
                    <ol style={{ margin: '8px 0 0', paddingLeft: '20px' }}>
                        <li><strong>Contact Request Email</strong> - if set</li>
                        <li><strong>Support Email</strong> - if set</li>
                        <li><strong>Site Admin Email</strong> - always available as final fallback</li>
                    </ol>
                </InfoBox>
            </SettingsSection>

            {/* Contact Form Behavior */}
            <SettingsSection
                title="Contact Form Behavior"
                description="Configure how the AI-powered contact form works within the chat."
            >
                <ToggleControl
                    label="Include Conversation Context"
                    checked={settings.contact_include_context !== false}
                    onChange={(value) => onChange('contact_include_context', value)}
                    help={
                        <HelpText type="tip">
                            When enabled, the AI includes a summary of the conversation with the contact request. This helps your support team understand what the customer was asking about.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Email Notifications"
                    checked={settings.contact_email_notifications !== false}
                    onChange={(value) => onChange('contact_email_notifications', value)}
                    help={
                        <HelpText>
                            Send email notification when a contact request is submitted. Disable if you're using a separate CRM or helpdesk that pulls from the database.
                        </HelpText>
                    }
                />

                <ToggleControl
                    label="Require Phone Number"
                    checked={settings.contact_require_phone === true}
                    onChange={(value) => onChange('contact_require_phone', value)}
                    help={
                        <HelpText>
                            Make phone number a required field for contact requests. Enable if you prefer to follow up by phone.
                        </HelpText>
                    }
                />
            </SettingsSection>

            {/* Integration Info */}
            <SettingsSection
                title="Helpdesk Integration"
                description="Connect contact requests to external helpdesk systems for advanced ticket management."
            >
                <InfoBox type="info" title="Developer Hook Available">
                    <p style={{ margin: 0 }}>
                        Use the <code style={{ background: '#f0f0f0', padding: '2px 6px', borderRadius: '3px' }}>glimmr_ai_contact_request_created</code> action hook to integrate with
                        helpdesk systems like Zendesk, Freshdesk, or HubSpot.
                    </p>
                    <p style={{ margin: '8px 0 0' }}>
                        Contact requests are stored in the database with unique reference numbers (CR-XXXXXXXX) for tracking.
                    </p>
                </InfoBox>

                <InfoBox type="tip" title="Viewing Contact Requests">
                    Contact requests can be viewed in the <strong>Dashboard → Conversations</strong> page.
                    Look for conversations with the "Contact Request" flag.
                </InfoBox>
            </SettingsSection>
        </>
    );
};

export default SupportTab;
