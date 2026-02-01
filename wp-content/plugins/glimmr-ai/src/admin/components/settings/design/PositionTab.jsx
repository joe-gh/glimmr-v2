/**
 * Position & Size Tab
 *
 * Widget positioning and dimension settings.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    SelectControl,
    RangeControl,
    TextControl,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * PositionTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 */
const PositionTab = ({ settings, onChange }) => (
    <>
        <InfoBox type="info" title="Widget Placement">
            The chat widget appears as a floating button on your site. When clicked, it expands into the chat window.
            On mobile devices, the widget automatically goes full-screen for better usability.
        </InfoBox>

        <SettingsSection
            title="Widget Position"
            description="Control where the chat button and window appear on your site."
        >
            <SelectControl
                label="Position"
                value={settings.widget_position || 'bottom-right'}
                options={[
                    { value: 'bottom-right', label: 'Bottom Right' },
                    { value: 'bottom-left', label: 'Bottom Left' },
                ]}
                onChange={(value) => onChange('widget_position', value)}
                help={
                    <HelpText type="tip">
                        Bottom-right is the most common placement and what users expect. Use bottom-left if you have other floating elements on the right.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Horizontal Offset: ${settings.widget_offset_x ?? 20}px`}
                value={settings.widget_offset_x ?? 20}
                onChange={(value) => onChange('widget_offset_x', value)}
                min={0}
                max={500}
                step={5}
                help={
                    <HelpText>
                        Distance from the left or right edge of the screen. Increase if the widget overlaps with other elements.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Vertical Offset: ${settings.widget_offset_y ?? 20}px`}
                value={settings.widget_offset_y ?? 20}
                onChange={(value) => onChange('widget_offset_y', value)}
                min={0}
                max={500}
                step={5}
                help={
                    <HelpText>
                        Distance from the bottom of the screen. Useful for avoiding footer elements or cookie banners.
                    </HelpText>
                }
            />

            <TextControl
                label="Z-Index"
                type="number"
                value={settings.widget_z_index ?? 999999}
                onChange={(value) => onChange('widget_z_index', parseInt(value, 10) || 999999)}
                help={
                    <HelpText type="tip">
                        Controls layering (higher = on top of other elements). Default of 999999 works for most sites. Increase if the widget appears behind other elements like sticky headers or popups.
                    </HelpText>
                }
            />
        </SettingsSection>

        <SettingsSection
            title="Widget Size"
            description="Set the dimensions of the expanded chat window on desktop. Mobile devices always use full-screen."
        >
            <RangeControl
                label={`Width: ${settings.widget_width || 400}px`}
                value={settings.widget_width || 400}
                onChange={(value) => onChange('widget_width', value)}
                min={300}
                max={600}
                step={10}
                help={
                    <HelpText>
                        Width of the chat window on desktop. 380-420px is optimal for most content. Narrower = less intrusive, wider = easier to read product cards.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Height: ${settings.widget_height || 650}px`}
                value={settings.widget_height || 650}
                onChange={(value) => onChange('widget_height', value)}
                min={400}
                max={800}
                step={10}
                help={
                    <HelpText>
                        Height of the chat window on desktop. 600-700px shows a good amount of conversation history. Consider your typical screen sizes.
                    </HelpText>
                }
            />

            <InfoBox type="tip" title="Responsive Behavior">
                <ul style={{ margin: 0, paddingLeft: '20px' }}>
                    <li><strong>Desktop:</strong> Uses the width/height you set above</li>
                    <li><strong>Tablet:</strong> Scales down proportionally</li>
                    <li><strong>Mobile:</strong> Always full-screen for best experience</li>
                </ul>
            </InfoBox>
        </SettingsSection>
    </>
);

export default PositionTab;
