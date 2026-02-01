/**
 * Behavior Tab
 *
 * Widget display, quick replies, and page targeting settings.
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
import { QuickRepliesEditor, HelpText, InfoBox } from '../SharedControls';

/**
 * BehaviorTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const BehaviorTab = ({ settings, onChange }) => (
    <>
        <SettingsSection
            title="Widget Display"
            description="Control when and how the chat widget appears on your site."
        >
            <ToggleControl
                label="Enable Chat Widget"
                checked={settings.widget_enabled !== false}
                onChange={(value) => onChange('widget_enabled', value)}
                help={
                    <HelpText>
                        Master switch for the chat widget. When disabled, the widget won't appear anywhere on your site.
                    </HelpText>
                }
            />

            <ToggleControl
                label="Content Moderation"
                checked={settings.moderation_enabled !== false}
                onChange={(value) => onChange('moderation_enabled', value)}
                help={
                    <HelpText type="warning">
                        Filters user messages through OpenAI's Moderation API before processing. Blocks hate speech, harassment, and harmful content. Strongly recommended for production sites.
                    </HelpText>
                }
            />

            <ToggleControl
                label="Debug Mode"
                checked={settings.widget_debug_mode === true}
                onChange={(value) => onChange('widget_debug_mode', value)}
                help={
                    <HelpText type="tip">
                        Hides the widget until activated via browser console. Run <code>window.activateGlimmrChat()</code> to show. Perfect for testing on production without customers seeing it.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Max Message Length: ${(settings.max_message_length || 4000).toLocaleString()} characters`}
                value={settings.max_message_length || 4000}
                onChange={(value) => onChange('max_message_length', value)}
                min={500}
                max={10000}
                step={500}
                help={
                    <HelpText>
                        Maximum characters allowed per customer message. Prevents abuse and controls token usage. 4000 characters is about 1000 words.
                    </HelpText>
                }
            />
        </SettingsSection>

        <SettingsSection
            title="Welcome Experience"
            description="Configure the initial greeting and quick reply buttons shown when customers open the chat."
        >
            <TextareaControl
                label="Greeting Message"
                value={settings.widget_greeting || 'Hi! How can I help you today?'}
                onChange={(value) => onChange('widget_greeting', value)}
                help={
                    <HelpText type="tip">
                        First message customers see when opening the chat. Keep it friendly and action-oriented. HTML is allowed for formatting.
                    </HelpText>
                }
                rows={3}
            />
        </SettingsSection>

        <SettingsSection
            title="Quick Replies"
            description="Suggested questions shown as buttons below the greeting. Helps customers get started quickly."
        >
            <QuickRepliesEditor
                replies={settings.widget_quick_replies || []}
                onChange={(replies) => onChange('widget_quick_replies', replies)}
            />

            <InfoBox type="tip" title="Effective Quick Replies">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li><strong>Keep button text short</strong> - 2-4 words work best</li>
                    <li><strong>Use common questions</strong> - "Track my order", "Return policy", "Shipping info"</li>
                    <li><strong>Guide to products</strong> - "Show me new arrivals", "Find a gift"</li>
                    <li><strong>Maximum 5 replies</strong> to avoid overwhelming customers</li>
                </ul>
            </InfoBox>
        </SettingsSection>

        <SettingsSection
            title="Page Targeting"
            description="Control which pages show the chat widget. Leave empty to show on all pages."
        >
            <TextareaControl
                label="Include Only These Pages"
                value={(settings.widget_include_pages || []).join('\n')}
                onChange={(value) => {
                    const pages = value.split('\n').map((p) => p.trim()).filter(Boolean);
                    onChange('widget_include_pages', pages);
                }}
                help={
                    <HelpText>
                        Only show the widget on these URL paths (one per line). Leave empty to show everywhere. Example: <code>/shop</code>, <code>/product/*</code>
                    </HelpText>
                }
                rows={3}
                placeholder="/shop&#10;/product/*&#10;/contact"
            />

            <TextareaControl
                label="Exclude These Pages"
                value={(settings.widget_exclude_pages || []).join('\n')}
                onChange={(value) => {
                    const pages = value.split('\n').map((p) => p.trim()).filter(Boolean);
                    onChange('widget_exclude_pages', pages);
                }}
                help={
                    <HelpText type="warning">
                        Hide the widget on these URL paths (one per line). Takes priority over include rules. Common excludes: <code>/checkout</code>, <code>/cart</code>, <code>/my-account</code>
                    </HelpText>
                }
                rows={3}
                placeholder="/checkout&#10;/cart&#10;/my-account"
            />

            <InfoBox type="info" title="URL Matching">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li><strong>Exact match:</strong> <code>/shop</code> matches only /shop</li>
                    <li><strong>Wildcard:</strong> <code>/product/*</code> matches /product/anything</li>
                    <li><strong>Exclude wins:</strong> If a page matches both include and exclude, it's excluded</li>
                </ul>
            </InfoBox>
        </SettingsSection>
    </>
);

export default BehaviorTab;
