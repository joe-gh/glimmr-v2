/**
 * Colors Tab
 *
 * Widget color and style settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    ColorPicker,
    RangeControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * ColorPickerItem - Individual color picker with label and help text.
 */
const ColorPickerItem = ({ label, value, onChange, help }) => {
    const labelId = `color-label-${label.replace(/\s+/g, '-').toLowerCase()}`;
    return (
        <div className="glimmr-color-item" role="group" aria-labelledby={labelId}>
            <label id={labelId}>{label}</label>
            <ColorPicker
                color={value}
                onChangeComplete={(color) => onChange(color.hex)}
                disableAlpha
            />
            <span className="glimmr-color-help">{help}</span>
        </div>
    );
};

/**
 * ColorsTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const ColorsTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="tip" title="Match Your Brand">
            Use your brand's primary color for the widget header and buttons. For best results, ensure
            sufficient contrast between text and background colors. Test on both light and dark backgrounds.
        </InfoBox>

        <SettingsSection
            title="Brand Colors"
            description="Primary colors used for buttons, header, and interactive elements."
        >
            <div className="glimmr-color-grid">
                <ColorPickerItem
                    label="Primary Color"
                    value={settings.widget_primary_color || '#4F46E5'}
                    onChange={(value) => onChange('widget_primary_color', value)}
                    help="Header background, send button, user message bubbles"
                />

                <ColorPickerItem
                    label="Primary Hover"
                    value={settings.widget_primary_hover || '#4338CA'}
                    onChange={(value) => onChange('widget_primary_hover', value)}
                    help="Button hover states (slightly darker than primary)"
                />

                <ColorPickerItem
                    label="Secondary Color"
                    value={settings.widget_secondary_color || '#818CF8'}
                    onChange={(value) => onChange('widget_secondary_color', value)}
                    help="Focus rings, links, secondary accents"
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Background & Surface Colors"
            description="Colors for the widget container and content areas."
        >
            <div className="glimmr-color-grid">
                <ColorPickerItem
                    label="Background"
                    value={settings.widget_bg_color || '#FFFFFF'}
                    onChange={(value) => onChange('widget_bg_color', value)}
                    help="Main chat window background"
                />

                <ColorPickerItem
                    label="Light Background"
                    value={settings.widget_bg_light || '#F3F4F6'}
                    onChange={(value) => onChange('widget_bg_light', value)}
                    help="Input area, assistant messages, cards"
                />

                <ColorPickerItem
                    label="Border Color"
                    value={settings.widget_border_color || '#E5E7EB'}
                    onChange={(value) => onChange('widget_border_color', value)}
                    help="Divider lines, card borders, separators"
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Text Colors"
            description="Colors for text content throughout the widget."
        >
            <div className="glimmr-color-grid">
                <ColorPickerItem
                    label="Header Text"
                    value={settings.widget_text_color || '#FFFFFF'}
                    onChange={(value) => onChange('widget_text_color', value)}
                    help="Text on primary-colored backgrounds (ensure contrast!)"
                />

                <ColorPickerItem
                    label="Body Text"
                    value={settings.widget_text_dark || '#1F2937'}
                    onChange={(value) => onChange('widget_text_dark', value)}
                    help="Messages, product names, main content"
                />

                <ColorPickerItem
                    label="Muted Text"
                    value={settings.widget_text_muted || '#6B7280'}
                    onChange={(value) => onChange('widget_text_muted', value)}
                    help="Timestamps, helper text, secondary info"
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Status Colors"
            description="Colors for success and error states."
        >
            <div className="glimmr-color-grid">
                <ColorPickerItem
                    label="Success Color"
                    value={settings.widget_success_color || '#059669'}
                    onChange={(value) => onChange('widget_success_color', value)}
                    help="In stock badges, sale prices, confirmations"
                />

                <ColorPickerItem
                    label="Error Color"
                    value={settings.widget_error_color || '#DC2626'}
                    onChange={(value) => onChange('widget_error_color', value)}
                    help="Out of stock, error messages, warnings"
                />
            </div>
        </SettingsSection>

        <SettingsSection
            title="Chat Button Style"
            description="Customize the floating chat button appearance."
        >
            <div className="glimmr-color-grid glimmr-color-grid--single">
                <ColorPickerItem
                    label="Button Border"
                    value={settings.widget_button_border || 'transparent'}
                    onChange={(value) => onChange('widget_button_border', value)}
                    help="Border around the chat bubble (set transparent for no border)"
                />
            </div>

            <RangeControl
                label={`Button Border Width: ${settings.widget_button_border_width || 0}px`}
                value={settings.widget_button_border_width || 0}
                onChange={(value) => onChange('widget_button_border_width', value)}
                min={0}
                max={5}
                step={1}
                help={
                    <HelpText>
                        Thickness of the border around the chat button. Set to 0 for no border.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Border Radius: ${settings.widget_border_radius || 16}px`}
                value={settings.widget_border_radius || 16}
                onChange={(value) => onChange('widget_border_radius', value)}
                min={0}
                max={24}
                step={2}
                help={
                    <HelpText type="tip">
                        Corner roundness for the chat window and UI elements. 12-16px gives a modern look, 0 for sharp corners.
                    </HelpText>
                }
            />
        </SettingsSection>
    </>
);

export default ColorsTab;
