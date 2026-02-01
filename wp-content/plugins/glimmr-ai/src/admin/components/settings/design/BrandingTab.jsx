/**
 * Branding Tab
 *
 * Logo, avatar, name, and typography settings.
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

/**
 * BrandingTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const BrandingTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="tip" title="Brand Your Assistant">
            Give your AI assistant a unique identity that matches your brand. A recognizable name and logo
            help customers understand they're chatting with your store's assistant, not a generic bot.
        </InfoBox>

        <SettingsSection
            title="Header Logo"
            description="Display your company logo in the chat widget header alongside the assistant name."
        >
            <TextControl
                label="Header Logo URL"
                value={settings.widget_header_logo_url || ''}
                onChange={(value) => onChange('widget_header_logo_url', value)}
                placeholder="https://yoursite.com/logo.png"
                help={
                    <HelpText type="tip">
                        Use a transparent PNG or SVG for best results. The logo appears next to the assistant name in the header. Leave empty to show only the name.
                    </HelpText>
                }
            />

            <div className="glimmr-settings-row">
                <RangeControl
                    label={`Logo Max Width: ${settings.widget_header_logo_max_width || 120}px`}
                    value={settings.widget_header_logo_max_width || 120}
                    onChange={(value) => onChange('widget_header_logo_max_width', value)}
                    min={24}
                    max={200}
                    step={4}
                    help={
                        <HelpText>
                            Maximum width. Logo will scale proportionally within this constraint.
                        </HelpText>
                    }
                />
                <RangeControl
                    label={`Logo Max Height: ${settings.widget_header_logo_max_height || 32}px`}
                    value={settings.widget_header_logo_max_height || 32}
                    onChange={(value) => onChange('widget_header_logo_max_height', value)}
                    min={20}
                    max={60}
                    step={2}
                    help={
                        <HelpText>
                            Maximum height. Keep under 40px to fit well in the header.
                        </HelpText>
                    }
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Assistant Identity"
            description="Customize how your AI assistant introduces itself to customers."
        >
            <TextControl
                label="Assistant Name"
                value={settings.widget_name || 'Shopping Assistant'}
                onChange={(value) => onChange('widget_name', value)}
                help={
                    <HelpText type="tip">
                        Displayed in the header and used in responses. Examples: "Maya", "Shop Helper", "Customer Care", or your brand name + "Assistant".
                    </HelpText>
                }
            />

            <div className="glimmr-settings-row">
                <RangeControl
                    label={`Title Font Size: ${settings.widget_title_font_size || 16}px`}
                    value={settings.widget_title_font_size || 16}
                    onChange={(value) => onChange('widget_title_font_size', value)}
                    min={12}
                    max={24}
                    step={1}
                    help={
                        <HelpText>
                            Font size for the assistant name in the header.
                        </HelpText>
                    }
                />
                <SelectControl
                    label="Title Font Weight"
                    value={settings.widget_title_font_weight || '600'}
                    options={[
                        { value: '400', label: 'Normal (400)' },
                        { value: '500', label: 'Medium (500)' },
                        { value: '600', label: 'Semi-Bold (600)' },
                        { value: '700', label: 'Bold (700)' },
                    ]}
                    onChange={(value) => onChange('widget_title_font_weight', value)}
                    help={
                        <HelpText>
                            Font weight for the assistant name. Semi-Bold (600) works well for most fonts.
                        </HelpText>
                    }
                />
            </div>

            <TextControl
                label="Avatar URL"
                value={settings.widget_avatar_url || ''}
                onChange={(value) => onChange('widget_avatar_url', value)}
                placeholder="https://yoursite.com/avatar.png"
                help={
                    <HelpText>
                        Small avatar image shown next to assistant messages. Use a square image (recommended: 64x64px or larger). Leave empty to hide the avatar.
                    </HelpText>
                }
            />

            <InfoBox type="info" title="Avatar Best Practices">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li>Use a friendly, approachable image</li>
                    <li>Can be a mascot, logo mark, or abstract design</li>
                    <li>Square images work best (will be cropped to circle)</li>
                    <li>Keep file size small (under 50KB)</li>
                </ul>
            </InfoBox>
        </SettingsSection>

        <SettingsSection
            title="Typography"
            description="Font settings for the chat widget text."
        >
            <SelectControl
                label="Font Family"
                value={settings.widget_font_family || 'inherit'}
                options={[
                    { value: 'inherit', label: 'Inherit from Theme' },
                    { value: 'system-ui', label: 'System UI (Native)' },
                    { value: 'Inter', label: 'Inter' },
                    { value: 'Roboto', label: 'Roboto' },
                ]}
                onChange={(value) => onChange('widget_font_family', value)}
                help={
                    <HelpText type="tip">
                        <strong>Inherit from Theme</strong> uses your site's default font for consistency.
                        <strong> System UI</strong> uses the device's native font (fast, no loading).
                    </HelpText>
                }
            />
        </SettingsSection>
    </>
);

export default BrandingTab;
