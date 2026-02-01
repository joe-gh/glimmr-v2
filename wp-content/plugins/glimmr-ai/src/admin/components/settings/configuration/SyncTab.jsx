/**
 * Sync Settings Tab
 *
 * Product and knowledge sync configuration.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

const {
    TextControl,
    ToggleControl,
    RangeControl,
    Button,
    Spinner,
} = wp.components;

import SettingsSection from '../SettingsSection';
import { HelpText, InfoBox } from '../SharedControls';

/**
 * SyncTab Component
 *
 * @param {Object} props
 * @param {Object} props.settings - Current settings object
 * @param {Function} props.onChange - Settings change handler
 * @param {Function} props.onSync - Sync action handler
 * @param {boolean} props.syncing - Whether sync is in progress
 */
const SyncTab = ({ settings, onChange, onSync, syncing }) => (
    <>
        <InfoBox type="info" title="What is Syncing?">
            Syncing sends your product catalog and knowledge base content to OpenAI's vector store.
            This enables the AI to search and find relevant products/information when customers ask questions.
            <strong> You should sync after adding new products or updating your knowledge base.</strong>
        </InfoBox>

        <SettingsSection
            title="Automatic Sync Schedule"
            description="Set up automatic syncing so your AI always has the latest product and content data."
        >
            <ToggleControl
                label="Enable Automatic Product Sync"
                checked={settings.product_sync_enabled !== false}
                onChange={(value) => onChange('product_sync_enabled', value)}
                help={
                    <HelpText>
                        When enabled, products are automatically synced daily at the scheduled time. Recommended for stores with frequent inventory changes.
                    </HelpText>
                }
            />

            <TextControl
                label="Product Sync Time"
                value={settings.product_sync_schedule || '03:00'}
                onChange={(value) => onChange('product_sync_schedule', value)}
                help={
                    <HelpText type="tip">
                        Use 24-hour format (e.g., "03:00" for 3 AM). Schedule during low-traffic hours to minimize performance impact. Time zone is your server's timezone.
                    </HelpText>
                }
            />

            <TextControl
                label="Knowledge Sync Time"
                value={settings.knowledge_sync_schedule || '03:30'}
                onChange={(value) => onChange('knowledge_sync_schedule', value)}
                help={
                    <HelpText>
                        Schedule 30+ minutes after product sync to avoid overlap. Syncs FAQ, policy pages, and other knowledge base entries.
                    </HelpText>
                }
            />

            <RangeControl
                label={`Sync Batch Size: ${settings.product_sync_batch_size || 100} products`}
                value={settings.product_sync_batch_size || 100}
                onChange={(value) => onChange('product_sync_batch_size', value)}
                min={10}
                max={500}
                step={10}
                help={
                    <HelpText type="warning">
                        Products processed per batch. Lower values (50-100) are more stable but slower. Higher values (200+) are faster but may timeout on shared hosting.
                    </HelpText>
                }
            />
        </SettingsSection>

        <SettingsSection
            title="Manual Sync"
            description="Trigger a sync immediately. Use this after importing products or making bulk changes."
        >
            <div className="glimmr-sync-buttons">
                <Button
                    variant="secondary"
                    onClick={() => onSync('products')}
                    disabled={syncing}
                >
                    {syncing ? <Spinner /> : <span className="dashicons dashicons-update"></span>}
                    Sync Products Now
                </Button>

                <Button
                    variant="secondary"
                    onClick={() => onSync('knowledge')}
                    disabled={syncing}
                >
                    {syncing ? <Spinner /> : <span className="dashicons dashicons-book"></span>}
                    Sync Knowledge Now
                </Button>

                <Button
                    variant="secondary"
                    onClick={() => onSync('full')}
                    disabled={syncing}
                >
                    {syncing ? <Spinner /> : <span className="dashicons dashicons-database"></span>}
                    Full Re-sync
                </Button>
            </div>

            <InfoBox type="tip" title="When to Use Full Re-sync">
                Use "Full Re-sync" when:
                <ul style={{ margin: '8px 0 0', paddingLeft: '20px' }}>
                    <li>You've made major changes to your product catalog</li>
                    <li>The AI isn't finding products it should</li>
                    <li>You've updated many knowledge base entries</li>
                    <li>Troubleshooting search issues</li>
                </ul>
                Note: Full re-sync can take several minutes for large catalogs.
            </InfoBox>
        </SettingsSection>
    </>
);

export default SyncTab;
