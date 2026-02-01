/**
 * Glimmr AI Admin JavaScript
 *
 * Legacy fallback interface for when the React bundle is not available.
 * The main implementation is in src/admin/ and is compiled to admin/js/glimmr-ai-admin-bundle.js
 *
 * This file is only loaded if the compiled React bundle does not exist.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Admin module.
     */
    const GlimmrAIAdmin = {
        /**
         * Configuration from WordPress.
         */
        config: window.glimmrAI || {},

        /**
         * Initialize the admin.
         */
        init: function() {
            this.bindEvents();
            this.initPage();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Tab switching.
            $(document).on('click', '.glimmr-ai-tab', this.handleTabClick.bind(this));

            // Form submission.
            $(document).on('submit', '.glimmr-ai-settings-form', this.handleFormSubmit.bind(this));

            // Sync buttons.
            $(document).on('click', '.glimmr-ai-sync-products', this.handleProductSync.bind(this));
            $(document).on('click', '.glimmr-ai-sync-knowledge', this.handleKnowledgeSync.bind(this));

            // Toggle switches.
            $(document).on('change', '.glimmr-ai-toggle input', this.handleToggle.bind(this));
        },

        /**
         * Initialize the current page.
         */
        initPage: function() {
            const page = this.config.currentPage || 'dashboard';

            switch (page) {
                case 'dashboard':
                    this.initDashboard();
                    break;
                case 'settings':
                    this.initSettings();
                    break;
                case 'knowledge':
                    this.initKnowledge();
                    break;
                case 'prompts':
                    this.initPrompts();
                    break;
                case 'conversations':
                    this.initConversations();
                    break;
            }
        },

        /**
         * Initialize dashboard page.
         */
        initDashboard: function() {
            const container = $('#glimmr-ai-dashboard-root');
            if (!container.length) return;

            // Replace loading state with dashboard content.
            container.html(this.renderDashboard());

            // Load analytics data.
            this.loadAnalytics('week');
        },

        /**
         * Render dashboard HTML.
         */
        renderDashboard: function() {
            return `
                <div class="glimmr-ai-grid glimmr-ai-grid-4" style="margin-bottom: 24px;">
                    <div class="glimmr-ai-stat-card blue">
                        <div class="glimmr-ai-stat-value" id="stat-conversations">-</div>
                        <div class="glimmr-ai-stat-label">${this.config.strings?.conversations || 'Conversations'}</div>
                    </div>
                    <div class="glimmr-ai-stat-card green">
                        <div class="glimmr-ai-stat-value" id="stat-messages">-</div>
                        <div class="glimmr-ai-stat-label">${this.config.strings?.messages || 'Messages'}</div>
                    </div>
                    <div class="glimmr-ai-stat-card orange">
                        <div class="glimmr-ai-stat-value" id="stat-tools">-</div>
                        <div class="glimmr-ai-stat-label">${this.config.strings?.toolCalls || 'Tool Calls'}</div>
                    </div>
                    <div class="glimmr-ai-stat-card red">
                        <div class="glimmr-ai-stat-value" id="stat-flagged">-</div>
                        <div class="glimmr-ai-stat-label">${this.config.strings?.flaggedIssues || 'Flagged Issues'}</div>
                    </div>
                </div>

                <div class="glimmr-ai-card">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">Quick Actions</h3>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button class="glimmr-ai-btn glimmr-ai-btn-secondary glimmr-ai-sync-products">
                            <span class="dashicons dashicons-update"></span>
                            Sync Products
                        </button>
                        <button class="glimmr-ai-btn glimmr-ai-btn-secondary glimmr-ai-sync-knowledge">
                            <span class="dashicons dashicons-book"></span>
                            Sync Knowledge
                        </button>
                        <a href="admin.php?page=glimmr-ai-settings" class="glimmr-ai-btn glimmr-ai-btn-secondary">
                            <span class="dashicons dashicons-admin-settings"></span>
                            Settings
                        </a>
                    </div>
                </div>

                <div class="glimmr-ai-card">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">Getting Started</h3>
                    </div>
                    <div style="line-height: 1.8;">
                        <p><strong>1. Configure API Key:</strong> Go to Settings → API Configuration and add your OpenAI API key.</p>
                        <p><strong>2. Set Up Knowledge Base:</strong> Go to Knowledge Base and add your site's content for the AI to reference.</p>
                        <p><strong>3. Customize the Widget:</strong> Go to Settings → Widget Appearance to match your brand.</p>
                        <p><strong>4. Configure Prompts:</strong> Go to Prompts & Tools to customize the AI's behavior.</p>
                    </div>
                </div>
            `;
        },

        /**
         * Load analytics data.
         */
        loadAnalytics: function(period) {
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'glimmr_ai_get_analytics',
                    nonce: this.config.nonce,
                    period: period
                },
                success: (response) => {
                    if (response.success) {
                        $('#stat-conversations').text(response.data.conversationCount || 0);
                        $('#stat-messages').text(response.data.messageCount || 0);
                        $('#stat-flagged').text(response.data.flaggedCount || 0);

                        // Calculate total tool calls.
                        const toolCalls = response.data.toolUsage?.reduce((sum, t) => sum + parseInt(t.usage_count || 0), 0) || 0;
                        $('#stat-tools').text(toolCalls);
                    }
                }
            });
        },

        /**
         * Initialize settings page.
         */
        initSettings: function() {
            const container = $('#glimmr-ai-settings-root');
            if (!container.length) return;

            container.html(this.renderSettings());
        },

        /**
         * Render settings HTML.
         */
        renderSettings: function() {
            const settings = this.config.settings || {};

            return `
                <div class="glimmr-ai-tabs">
                    <button class="glimmr-ai-tab active" data-tab="api">API Configuration</button>
                    <button class="glimmr-ai-tab" data-tab="costs">Cost Controls</button>
                    <button class="glimmr-ai-tab" data-tab="sync">Sync Settings</button>
                    <button class="glimmr-ai-tab" data-tab="widget">Widget Appearance</button>
                    <button class="glimmr-ai-tab" data-tab="behavior">Widget Behavior</button>
                    <button class="glimmr-ai-tab" data-tab="personality">Agent Personality</button>
                    <button class="glimmr-ai-tab" data-tab="gdpr">GDPR & Privacy</button>
                </div>

                <form class="glimmr-ai-settings-form">
                    <!-- API Configuration -->
                    <div class="glimmr-ai-tab-content active" id="tab-api">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label for="openai_api_key">OpenAI API Key</label>
                                <input type="password" id="openai_api_key" name="openai_api_key"
                                    value="${this.escapeHtml(settings.openai_api_key || '')}"
                                    placeholder="sk-...">
                                <p class="glimmr-ai-form-help">Your OpenAI API key. Get one at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>.</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="openai_vector_store_id">Vector Store ID</label>
                                <input type="text" id="openai_vector_store_id" name="openai_vector_store_id"
                                    value="${this.escapeHtml(settings.openai_vector_store_id || '')}"
                                    placeholder="vs_...">
                                <p class="glimmr-ai-form-help">The OpenAI Vector Store ID for knowledge retrieval.</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="openai_model">Model</label>
                                <select id="openai_model" name="openai_model">
                                    <option value="gpt-4o" ${settings.openai_model === 'gpt-4o' ? 'selected' : ''}>GPT-4o</option>
                                    <option value="gpt-4o-mini" ${settings.openai_model === 'gpt-4o-mini' ? 'selected' : ''}>GPT-4o Mini</option>
                                    <option value="gpt-4-turbo" ${settings.openai_model === 'gpt-4-turbo' ? 'selected' : ''}>GPT-4 Turbo</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Cost Controls -->
                    <div class="glimmr-ai-tab-content" id="tab-costs">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label for="max_tokens_per_response">Max Tokens Per Response</label>
                                <input type="number" id="max_tokens_per_response" name="max_tokens_per_response"
                                    value="${settings.max_tokens_per_response || 1000}" min="100" max="4000">
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="rate_limit_authenticated">Rate Limit (Authenticated Users)</label>
                                <input type="number" id="rate_limit_authenticated" name="rate_limit_authenticated"
                                    value="${settings.rate_limit_authenticated || 100}" min="1">
                                <p class="glimmr-ai-form-help">Requests per hour for logged-in users.</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="rate_limit_anonymous">Rate Limit (Anonymous Users)</label>
                                <input type="number" id="rate_limit_anonymous" name="rate_limit_anonymous"
                                    value="${settings.rate_limit_anonymous || 20}" min="1">
                                <p class="glimmr-ai-form-help">Requests per hour for guests.</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="daily_token_limit">Daily Token Limit</label>
                                <input type="number" id="daily_token_limit" name="daily_token_limit"
                                    value="${settings.daily_token_limit || 100000}" min="1000">
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="monthly_token_limit">Monthly Token Limit</label>
                                <input type="number" id="monthly_token_limit" name="monthly_token_limit"
                                    value="${settings.monthly_token_limit || 2000000}" min="10000">
                            </div>
                        </div>
                    </div>

                    <!-- Sync Settings -->
                    <div class="glimmr-ai-tab-content" id="tab-sync">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label>Enable Product Sync</label>
                                <label class="glimmr-ai-toggle">
                                    <input type="checkbox" name="product_sync_enabled"
                                        ${settings.product_sync_enabled !== false ? 'checked' : ''}>
                                    <span class="glimmr-ai-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="product_sync_schedule">Product Sync Time</label>
                                <input type="time" id="product_sync_schedule" name="product_sync_schedule"
                                    value="${settings.product_sync_schedule || '03:00'}">
                                <p class="glimmr-ai-form-help">Daily sync time (server timezone).</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="product_sync_batch_size">Batch Size</label>
                                <input type="number" id="product_sync_batch_size" name="product_sync_batch_size"
                                    value="${settings.product_sync_batch_size || 100}" min="10" max="500">
                            </div>

                            <div style="margin-top: 24px;">
                                <button type="button" class="glimmr-ai-btn glimmr-ai-btn-secondary glimmr-ai-sync-products">
                                    Sync Products Now
                                </button>
                                <button type="button" class="glimmr-ai-btn glimmr-ai-btn-secondary glimmr-ai-sync-knowledge">
                                    Sync Knowledge Now
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Widget Appearance -->
                    <div class="glimmr-ai-tab-content" id="tab-widget">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label>Enable Widget</label>
                                <label class="glimmr-ai-toggle">
                                    <input type="checkbox" name="widget_enabled"
                                        ${settings.widget_enabled !== false ? 'checked' : ''}>
                                    <span class="glimmr-ai-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="widget_name">Assistant Name</label>
                                <input type="text" id="widget_name" name="widget_name"
                                    value="${this.escapeHtml(settings.widget_name || 'Shopping Assistant')}">
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="widget_position">Position</label>
                                <select id="widget_position" name="widget_position">
                                    <option value="bottom-right" ${settings.widget_position === 'bottom-right' ? 'selected' : ''}>Bottom Right</option>
                                    <option value="bottom-left" ${settings.widget_position === 'bottom-left' ? 'selected' : ''}>Bottom Left</option>
                                </select>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label>Primary Color</label>
                                <div class="glimmr-ai-color-picker">
                                    <input type="color" id="widget_primary_color" name="widget_primary_color"
                                        value="${settings.widget_primary_color || '#4F46E5'}">
                                    <input type="text" value="${settings.widget_primary_color || '#4F46E5'}"
                                        data-color-for="widget_primary_color">
                                </div>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label>Secondary Color</label>
                                <div class="glimmr-ai-color-picker">
                                    <input type="color" id="widget_secondary_color" name="widget_secondary_color"
                                        value="${settings.widget_secondary_color || '#818CF8'}">
                                    <input type="text" value="${settings.widget_secondary_color || '#818CF8'}"
                                        data-color-for="widget_secondary_color">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Widget Behavior -->
                    <div class="glimmr-ai-tab-content" id="tab-behavior">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label for="widget_greeting">Greeting Message</label>
                                <textarea id="widget_greeting" name="widget_greeting" rows="4">${this.escapeHtml(settings.widget_greeting || '<p>Hi! How can I help you today?</p>')}</textarea>
                                <p class="glimmr-ai-form-help">HTML is allowed. This is shown when the chat opens.</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="widget_exclude_pages">Exclude Pages</label>
                                <input type="text" id="widget_exclude_pages" name="widget_exclude_pages"
                                    value="${(settings.widget_exclude_pages || []).join(', ')}"
                                    placeholder="/checkout, /cart, /my-account">
                                <p class="glimmr-ai-form-help">Comma-separated list of URL paths where widget should be hidden.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Agent Personality -->
                    <div class="glimmr-ai-tab-content" id="tab-personality">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label for="agent_tone">Tone</label>
                                <select id="agent_tone" name="agent_tone">
                                    <option value="friendly" ${settings.agent_tone === 'friendly' ? 'selected' : ''}>Friendly</option>
                                    <option value="professional" ${settings.agent_tone === 'professional' ? 'selected' : ''}>Professional</option>
                                    <option value="casual" ${settings.agent_tone === 'casual' ? 'selected' : ''}>Casual</option>
                                </select>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="agent_personality">Personality Notes</label>
                                <textarea id="agent_personality" name="agent_personality" rows="3">${this.escapeHtml(settings.agent_personality || '')}</textarea>
                                <p class="glimmr-ai-form-help">Additional personality traits for the AI (e.g., "Expert in outdoor gear, knows hiking trails").</p>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="fallback_response">Fallback Response</label>
                                <textarea id="fallback_response" name="fallback_response" rows="3">${this.escapeHtml(settings.fallback_response || "I'm not sure about that. Would you like to speak with our support team?")}</textarea>
                                <p class="glimmr-ai-form-help">Response when the AI is uncertain.</p>
                            </div>
                        </div>
                    </div>

                    <!-- GDPR & Privacy -->
                    <div class="glimmr-ai-tab-content" id="tab-gdpr">
                        <div class="glimmr-ai-card">
                            <div class="glimmr-ai-form-group">
                                <label>Enable GDPR Consent</label>
                                <label class="glimmr-ai-toggle">
                                    <input type="checkbox" name="gdpr_enabled"
                                        ${settings.gdpr_enabled !== false ? 'checked' : ''}>
                                    <span class="glimmr-ai-toggle-slider"></span>
                                </label>
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="gdpr_consent_text">Consent Text</label>
                                <input type="text" id="gdpr_consent_text" name="gdpr_consent_text"
                                    value="${this.escapeHtml(settings.gdpr_consent_text || 'By chatting, you agree to our privacy policy.')}">
                            </div>

                            <div class="glimmr-ai-form-group">
                                <label for="data_retention_days">Data Retention (Days)</label>
                                <input type="number" id="data_retention_days" name="data_retention_days"
                                    value="${settings.data_retention_days || 365}" min="30">
                                <p class="glimmr-ai-form-help">How long to keep conversation data.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glimmr-ai-card" style="margin-top: 24px;">
                        <button type="submit" class="glimmr-ai-btn glimmr-ai-btn-primary">
                            Save Settings
                        </button>
                    </div>
                </form>
            `;
        },

        /**
         * Initialize knowledge page.
         */
        initKnowledge: function() {
            const container = $('#glimmr-ai-knowledge-root');
            if (!container.length) return;

            container.html(`
                <div class="glimmr-ai-card">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">Knowledge Base</h3>
                        <button class="glimmr-ai-btn glimmr-ai-btn-secondary glimmr-ai-sync-knowledge">
                            Sync to Vector Store
                        </button>
                    </div>
                    <p>Configure your site's knowledge base that the AI will use to answer questions.</p>
                    <p style="color: #666; font-style: italic;">Note: This is a fallback interface. If you see this, try running <code>npm run build</code> in the plugin directory.</p>
                </div>
            `);
        },

        /**
         * Initialize prompts page.
         */
        initPrompts: function() {
            const container = $('#glimmr-ai-prompts-root');
            if (!container.length) return;

            const settings = this.config.settings || {};

            container.html(`
                <div class="glimmr-ai-card">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">System Prompt</h3>
                    </div>
                    <form class="glimmr-ai-settings-form">
                        <div class="glimmr-ai-form-group">
                            <label for="system_prompt">System Prompt</label>
                            <textarea id="system_prompt" name="system_prompt" rows="15">${this.escapeHtml(settings.system_prompt || '')}</textarea>
                            <p class="glimmr-ai-form-help">
                                Available variables: {site_name}, {site_url}, {is_logged_in}, {customer_name}, {cart_summary}
                            </p>
                        </div>
                        <button type="submit" class="glimmr-ai-btn glimmr-ai-btn-primary">Save Prompt</button>
                    </form>
                </div>

                <div class="glimmr-ai-card" style="margin-top: 24px;">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">Enabled Tools</h3>
                    </div>
                    <p style="color: #666; font-style: italic;">Note: This is a fallback interface. If you see this, try running <code>npm run build</code> in the plugin directory.</p>
                </div>
            `);
        },

        /**
         * Initialize conversations page.
         */
        initConversations: function() {
            const container = $('#glimmr-ai-conversations-root');
            if (!container.length) return;

            container.html(`
                <div class="glimmr-ai-card">
                    <div class="glimmr-ai-card-header">
                        <h3 class="glimmr-ai-card-title">Recent Conversations</h3>
                    </div>
                    <div id="conversations-list">
                        <div class="glimmr-ai-empty-state">
                            <div class="glimmr-ai-empty-state-icon">💬</div>
                            <div class="glimmr-ai-empty-state-title">No conversations yet</div>
                            <div class="glimmr-ai-empty-state-text">
                                Conversations will appear here once visitors start chatting with your AI assistant.
                            </div>
                        </div>
                    </div>
                </div>
            `);

            this.loadConversations();
        },

        /**
         * Load conversations list.
         */
        loadConversations: function() {
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'glimmr_ai_get_conversations',
                    nonce: this.config.nonce,
                    page: 1,
                    per_page: 20
                },
                success: (response) => {
                    if (response.success && response.data.conversations.length > 0) {
                        const html = response.data.conversations.map(c => this.renderConversationItem(c)).join('');
                        $('#conversations-list').html(html);
                    }
                }
            });
        },

        /**
         * Render a conversation list item.
         */
        renderConversationItem: function(conversation) {
            const initial = conversation.user_id ? 'U' : 'G';
            const date = new Date(conversation.created_at).toLocaleDateString();

            return `
                <div class="glimmr-ai-conversation-item" data-id="${conversation.conversation_id}">
                    <div class="glimmr-ai-conversation-avatar">${initial}</div>
                    <div class="glimmr-ai-conversation-info">
                        <div class="glimmr-ai-conversation-name">
                            ${conversation.user_id ? 'User #' + conversation.user_id : 'Guest'}
                            <span class="glimmr-ai-badge glimmr-ai-badge-${conversation.status}">${conversation.status}</span>
                        </div>
                        <div class="glimmr-ai-conversation-preview">
                            ${conversation.message_count} messages
                        </div>
                    </div>
                    <div class="glimmr-ai-conversation-meta">
                        ${date}
                    </div>
                </div>
            `;
        },

        /**
         * Handle tab click.
         */
        handleTabClick: function(e) {
            const $tab = $(e.currentTarget);
            const tabId = $tab.data('tab');

            $('.glimmr-ai-tab').removeClass('active');
            $tab.addClass('active');

            $('.glimmr-ai-tab-content').removeClass('active');
            $(`#tab-${tabId}`).addClass('active');
        },

        /**
         * Handle form submission.
         */
        handleFormSubmit: function(e) {
            e.preventDefault();

            const $form = $(e.currentTarget);
            const $button = $form.find('button[type="submit"]');
            const originalText = $button.text();

            $button.prop('disabled', true).text(this.config.strings?.saving || 'Saving...');

            // Collect form data.
            const settings = {};
            $form.find('input, textarea, select').each(function() {
                const $input = $(this);
                const name = $input.attr('name');

                if (!name) return;

                if ($input.attr('type') === 'checkbox') {
                    settings[name] = $input.is(':checked');
                } else {
                    settings[name] = $input.val();
                }
            });

            // Parse comma-separated values.
            if (settings.widget_exclude_pages) {
                settings.widget_exclude_pages = settings.widget_exclude_pages.split(',').map(s => s.trim()).filter(Boolean);
            }

            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'glimmr_ai_save_settings',
                    nonce: this.config.nonce,
                    settings: JSON.stringify(settings)
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                    } else {
                        this.showNotice('error', response.data.message);
                    }
                },
                error: () => {
                    this.showNotice('error', this.config.strings?.error || 'An error occurred.');
                },
                complete: () => {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Handle product sync button.
         */
        handleProductSync: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalHtml = $button.html();

            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Syncing...');

            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'glimmr_ai_sync_products',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                    } else {
                        this.showNotice('error', response.data.message);
                    }
                },
                error: () => {
                    this.showNotice('error', 'Sync failed. Please try again.');
                },
                complete: () => {
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        },

        /**
         * Handle knowledge sync button.
         */
        handleKnowledgeSync: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalHtml = $button.html();

            $button.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Syncing...');

            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'glimmr_ai_sync_knowledge',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                    } else {
                        this.showNotice('error', response.data.message);
                    }
                },
                error: () => {
                    this.showNotice('error', 'Sync failed. Please try again.');
                },
                complete: () => {
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        },

        /**
         * Handle toggle switch changes.
         */
        handleToggle: function(e) {
            // Auto-save could be implemented here if desired.
        },

        /**
         * Show a notice message.
         */
        showNotice: function(type, message) {
            // Remove existing notices.
            $('.glimmr-ai-notice').remove();

            const html = `
                <div class="glimmr-ai-notice glimmr-ai-notice-${type}">
                    ${message}
                </div>
            `;

            $('.glimmr-ai-admin h1').after(html);

            // Auto-remove after 5 seconds.
            setTimeout(() => {
                $('.glimmr-ai-notice').fadeOut(() => {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Escape HTML for safe output.
         */
        escapeHtml: function(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    };

    // Initialize on DOM ready.
    $(document).ready(function() {
        GlimmrAIAdmin.init();
    });

})(jQuery);
