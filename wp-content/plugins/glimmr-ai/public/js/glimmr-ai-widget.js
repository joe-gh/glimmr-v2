/**
 * Glimmr AI Chat Widget
 *
 * Legacy fallback widget for when the Preact bundle is not available.
 * The main implementation is in src/widget/ and is compiled to public/js/glimmr-ai-widget-bundle.js
 *
 * This file is only loaded if the compiled Preact bundle does not exist.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

(function() {
    'use strict';

    /**
     * Chat Widget Module
     */
    const GlimmrAIChatWidget = {
        /**
         * Configuration from WordPress.
         */
        config: window.glimmrAIWidget || {},

        /**
         * Widget state.
         */
        state: {
            isOpen: false,
            conversationId: null,
            messages: [],
            isLoading: false,
            hasConsented: false
        },

        /**
         * DOM elements.
         */
        elements: {},

        /**
         * Initialize the widget.
         */
        init: function() {
            if (!this.config.enabled) {
                return;
            }

            // Load conversation ID from localStorage.
            this.state.conversationId = localStorage.getItem('glimmr_ai_conversation_id');
            this.state.hasConsented = localStorage.getItem('glimmr_ai_consent') === 'true';

            // Create and inject widget.
            this.createWidget();
            this.bindEvents();

            // Load existing conversation if we have an ID.
            if (this.state.conversationId && this.state.hasConsented) {
                this.loadHistory();
            }
        },

        /**
         * Create the widget DOM with Shadow DOM.
         */
        createWidget: function() {
            const container = document.getElementById('glimmr-ai-chat-widget');
            if (!container) return;

            // Position container.
            const position = this.config.position || 'bottom-right';
            if (position === 'bottom-right') {
                container.style.right = '20px';
                container.style.bottom = '20px';
            } else {
                container.style.left = '20px';
                container.style.bottom = '20px';
            }

            // Create shadow DOM for style isolation.
            const shadow = container.attachShadow({ mode: 'open' });

            // Inject styles.
            shadow.innerHTML = `
                <style>${this.getWidgetStyles()}</style>
                <div class="glimmr-widget">
                    <!-- Chat Bubble -->
                    <button class="glimmr-bubble" aria-label="Open chat">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>
                        </svg>
                    </button>

                    <!-- Chat Window -->
                    <div class="glimmr-window" style="display: none;">
                        <div class="glimmr-header">
                            <div class="glimmr-header-info">
                                ${this.config.avatarUrl ? `<img src="${this.config.avatarUrl}" class="glimmr-avatar" alt="">` : ''}
                                <span class="glimmr-title">${this.escapeHtml(this.config.name || 'Shopping Assistant')}</span>
                            </div>
                            <button class="glimmr-close" aria-label="Close chat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 6L6 18M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <div class="glimmr-body">
                            <div class="glimmr-messages"></div>
                        </div>

                        <div class="glimmr-footer">
                            <div class="glimmr-input-container">
                                <textarea class="glimmr-input" placeholder="Type a message..." rows="1"></textarea>
                                <button class="glimmr-send" aria-label="Send message">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="glimmr-flag-container" style="display: none;">
                                <button class="glimmr-flag-btn">Report an issue</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Store element references.
            this.elements = {
                container: shadow.querySelector('.glimmr-widget'),
                bubble: shadow.querySelector('.glimmr-bubble'),
                window: shadow.querySelector('.glimmr-window'),
                closeBtn: shadow.querySelector('.glimmr-close'),
                messages: shadow.querySelector('.glimmr-messages'),
                input: shadow.querySelector('.glimmr-input'),
                sendBtn: shadow.querySelector('.glimmr-send'),
                flagBtn: shadow.querySelector('.glimmr-flag-btn'),
                flagContainer: shadow.querySelector('.glimmr-flag-container')
            };
        },

        /**
         * Get widget styles.
         */
        getWidgetStyles: function() {
            const primary = this.config.primaryColor || '#4F46E5';
            const secondary = this.config.secondaryColor || '#818CF8';
            const textColor = this.config.textColor || '#FFFFFF';

            return `
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                .glimmr-widget {
                    font-family: ${this.config.fontFamily || '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'};
                    font-size: 14px;
                    line-height: 1.5;
                }

                /* Chat Bubble */
                .glimmr-bubble {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${primary};
                    color: ${textColor};
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                    transition: transform 0.2s, box-shadow 0.2s;
                }

                .glimmr-bubble:hover {
                    transform: scale(1.05);
                    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
                }

                .glimmr-bubble svg {
                    width: 28px;
                    height: 28px;
                }

                .glimmr-bubble.hidden {
                    display: none;
                }

                /* Chat Window */
                .glimmr-window {
                    position: absolute;
                    bottom: 80px;
                    right: 0;
                    width: 380px;
                    max-width: calc(100vw - 40px);
                    height: 600px;
                    max-height: calc(100vh - 120px);
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                }

                /* Header */
                .glimmr-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 16px 20px;
                    background: ${primary};
                    color: ${textColor};
                }

                .glimmr-header-info {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }

                .glimmr-avatar {
                    width: 36px;
                    height: 36px;
                    border-radius: 50%;
                    object-fit: cover;
                }

                .glimmr-title {
                    font-weight: 600;
                    font-size: 16px;
                }

                .glimmr-close {
                    background: none;
                    border: none;
                    color: ${textColor};
                    cursor: pointer;
                    padding: 4px;
                    opacity: 0.8;
                    transition: opacity 0.2s;
                }

                .glimmr-close:hover {
                    opacity: 1;
                }

                .glimmr-close svg {
                    width: 20px;
                    height: 20px;
                }

                /* Body */
                .glimmr-body {
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    background: #f9fafb;
                }

                .glimmr-messages {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }

                /* Messages */
                .glimmr-message {
                    max-width: 85%;
                    padding: 12px 16px;
                    border-radius: 16px;
                    word-wrap: break-word;
                }

                .glimmr-message-user {
                    align-self: flex-end;
                    background: ${primary};
                    color: ${textColor};
                    border-bottom-right-radius: 4px;
                }

                .glimmr-message-assistant {
                    align-self: flex-start;
                    background: #fff;
                    color: #1f2937;
                    border-bottom-left-radius: 4px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                }

                .glimmr-message-system {
                    align-self: center;
                    background: transparent;
                    color: #6b7280;
                    font-size: 13px;
                    text-align: center;
                }

                /* Quick Replies */
                .glimmr-quick-replies {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-top: 12px;
                }

                .glimmr-quick-reply {
                    padding: 8px 14px;
                    background: #fff;
                    border: 1px solid ${primary};
                    color: ${primary};
                    border-radius: 20px;
                    cursor: pointer;
                    font-size: 13px;
                    transition: background 0.2s, color 0.2s;
                }

                .glimmr-quick-reply:hover {
                    background: ${primary};
                    color: ${textColor};
                }

                /* Typing Indicator */
                .glimmr-typing {
                    display: flex;
                    gap: 4px;
                    padding: 12px 16px;
                }

                .glimmr-typing-dot {
                    width: 8px;
                    height: 8px;
                    background: #9ca3af;
                    border-radius: 50%;
                    animation: glimmr-bounce 1.4s infinite ease-in-out;
                }

                .glimmr-typing-dot:nth-child(1) { animation-delay: -0.32s; }
                .glimmr-typing-dot:nth-child(2) { animation-delay: -0.16s; }

                @keyframes glimmr-bounce {
                    0%, 80%, 100% { transform: translateY(0); }
                    40% { transform: translateY(-6px); }
                }

                /* Footer */
                .glimmr-footer {
                    padding: 12px 16px;
                    background: #fff;
                    border-top: 1px solid #e5e7eb;
                }

                .glimmr-input-container {
                    display: flex;
                    align-items: flex-end;
                    gap: 8px;
                }

                .glimmr-input {
                    flex: 1;
                    padding: 10px 14px;
                    border: 1px solid #d1d5db;
                    border-radius: 20px;
                    font-size: 14px;
                    font-family: inherit;
                    resize: none;
                    max-height: 120px;
                    outline: none;
                    transition: border-color 0.2s;
                }

                .glimmr-input:focus {
                    border-color: ${primary};
                }

                .glimmr-send {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: ${primary};
                    color: ${textColor};
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: background 0.2s;
                }

                .glimmr-send:hover {
                    background: ${secondary};
                }

                .glimmr-send:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }

                .glimmr-send svg {
                    width: 18px;
                    height: 18px;
                }

                /* Flag Button */
                .glimmr-flag-container {
                    text-align: center;
                    margin-top: 8px;
                }

                .glimmr-flag-btn {
                    background: none;
                    border: none;
                    color: #9ca3af;
                    font-size: 12px;
                    cursor: pointer;
                }

                .glimmr-flag-btn:hover {
                    color: #6b7280;
                    text-decoration: underline;
                }

                /* Consent Banner */
                .glimmr-consent {
                    padding: 20px;
                    text-align: center;
                    background: #f9fafb;
                }

                .glimmr-consent-text {
                    color: #6b7280;
                    font-size: 13px;
                    margin-bottom: 16px;
                }

                .glimmr-consent-btn {
                    padding: 10px 24px;
                    background: ${primary};
                    color: ${textColor};
                    border: none;
                    border-radius: 20px;
                    cursor: pointer;
                    font-weight: 500;
                }

                /* Mobile Styles */
                @media (max-width: 480px) {
                    .glimmr-window {
                        width: 100vw;
                        height: 100vh;
                        max-height: 100vh;
                        border-radius: 0;
                        bottom: 0;
                        right: 0;
                        position: fixed;
                    }
                }
            `;
        },

        /**
         * Bind event listeners.
         */
        bindEvents: function() {
            // Open/close chat.
            this.elements.bubble.addEventListener('click', () => this.openChat());
            this.elements.closeBtn.addEventListener('click', () => this.closeChat());

            // Send message.
            this.elements.sendBtn.addEventListener('click', () => this.sendMessage());
            this.elements.input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });

            // Auto-resize input.
            this.elements.input.addEventListener('input', () => {
                this.elements.input.style.height = 'auto';
                this.elements.input.style.height = Math.min(this.elements.input.scrollHeight, 120) + 'px';
            });

            // Flag button.
            this.elements.flagBtn.addEventListener('click', () => this.showFlagDialog());
        },

        /**
         * Open the chat window.
         */
        openChat: function() {
            this.state.isOpen = true;
            this.elements.window.style.display = 'flex';
            this.elements.bubble.classList.add('hidden');

            // Show consent or greeting.
            if (!this.state.hasConsented && this.config.gdprEnabled) {
                this.showConsent();
            } else if (this.state.messages.length === 0) {
                this.showGreeting();
            }

            this.elements.input.focus();
        },

        /**
         * Close the chat window.
         */
        closeChat: function() {
            this.state.isOpen = false;
            this.elements.window.style.display = 'none';
            this.elements.bubble.classList.remove('hidden');
        },

        /**
         * Show GDPR consent.
         */
        showConsent: function() {
            this.elements.messages.innerHTML = `
                <div class="glimmr-consent">
                    <p class="glimmr-consent-text">${this.escapeHtml(this.config.gdprText || 'By chatting, you agree to our privacy policy.')}</p>
                    <button class="glimmr-consent-btn">Start Chat</button>
                </div>
            `;

            this.elements.messages.querySelector('.glimmr-consent-btn').addEventListener('click', () => {
                this.state.hasConsented = true;
                localStorage.setItem('glimmr_ai_consent', 'true');
                this.showGreeting();
            });
        },

        /**
         * Show greeting message.
         */
        showGreeting: function() {
            this.elements.messages.innerHTML = '';

            // Add greeting.
            const greeting = this.config.greeting || '<p>Hi! How can I help you today?</p>';
            this.addMessage('assistant', greeting);

            // Add quick replies.
            const quickReplies = this.config.quickReplies || [];
            if (quickReplies.length > 0) {
                const container = document.createElement('div');
                container.className = 'glimmr-quick-replies';

                quickReplies.forEach(qr => {
                    const btn = document.createElement('button');
                    btn.className = 'glimmr-quick-reply';
                    btn.textContent = qr.text;
                    btn.addEventListener('click', () => {
                        container.remove();
                        this.elements.input.value = qr.action;
                        this.sendMessage();
                    });
                    container.appendChild(btn);
                });

                this.elements.messages.appendChild(container);
            }
        },

        /**
         * Add a message to the chat.
         */
        addMessage: function(role, content) {
            const msg = document.createElement('div');
            msg.className = `glimmr-message glimmr-message-${role}`;

            if (role === 'assistant') {
                msg.innerHTML = content;
            } else {
                msg.textContent = content;
            }

            this.elements.messages.appendChild(msg);
            this.scrollToBottom();

            this.state.messages.push({ role, content });

            // Show flag button after first assistant message.
            if (role === 'assistant' && this.state.messages.length > 1) {
                this.elements.flagContainer.style.display = 'block';
            }
        },

        /**
         * Show typing indicator.
         */
        showTyping: function() {
            const typing = document.createElement('div');
            typing.className = 'glimmr-message glimmr-message-assistant glimmr-typing';
            typing.id = 'glimmr-typing';
            typing.innerHTML = `
                <div class="glimmr-typing-dot"></div>
                <div class="glimmr-typing-dot"></div>
                <div class="glimmr-typing-dot"></div>
            `;
            this.elements.messages.appendChild(typing);
            this.scrollToBottom();
        },

        /**
         * Hide typing indicator.
         */
        hideTyping: function() {
            const typing = this.elements.messages.querySelector('#glimmr-typing');
            if (typing) typing.remove();
        },

        /**
         * Scroll to bottom of messages.
         */
        scrollToBottom: function() {
            this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
        },

        /**
         * Send a message.
         */
        sendMessage: async function() {
            const message = this.elements.input.value.trim();
            if (!message || this.state.isLoading) return;

            // Clear input.
            this.elements.input.value = '';
            this.elements.input.style.height = 'auto';

            // Add user message.
            this.addMessage('user', message);

            // Show typing.
            this.state.isLoading = true;
            this.elements.sendBtn.disabled = true;
            this.showTyping();

            try {
                const response = await fetch(this.config.apiEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce
                    },
                    body: JSON.stringify({
                        conversation_id: this.state.conversationId,
                        message: message
                    })
                });

                const data = await response.json();

                this.hideTyping();

                if (response.ok && data.response) {
                    this.addMessage('assistant', this.escapeHtml(data.response));

                    // Save conversation ID.
                    if (data.conversation_id) {
                        this.state.conversationId = data.conversation_id;
                        localStorage.setItem('glimmr_ai_conversation_id', data.conversation_id);
                    }
                } else {
                    this.addMessage('assistant', this.config.fallbackResponse || "I'm sorry, I couldn't process that. Please try again.");
                }
            } catch (error) {
                this.hideTyping();
                this.addMessage('assistant', "I'm having trouble connecting. Please try again in a moment.");
                console.error('Glimmr AI Error:', error);
            }

            this.state.isLoading = false;
            this.elements.sendBtn.disabled = false;
            this.elements.input.focus();
        },

        /**
         * Load conversation history.
         */
        loadHistory: async function() {
            try {
                const response = await fetch(`${this.config.historyEndpoint}/${this.state.conversationId}`, {
                    headers: {
                        'X-WP-Nonce': this.config.nonce
                    }
                });

                const data = await response.json();

                if (response.ok && data.messages && data.messages.length > 0) {
                    this.elements.messages.innerHTML = '';
                    data.messages.forEach(msg => {
                        this.addMessage(msg.role, msg.content);
                    });
                }
            } catch (error) {
                console.error('Failed to load history:', error);
            }
        },

        /**
         * Show flag dialog.
         */
        showFlagDialog: function() {
            // Simple prompt for legacy fallback. Full modal is in Preact bundle.
            const feedback = prompt('Please describe the issue:');
            if (feedback) {
                this.flagConversation(feedback);
            }
        },

        /**
         * Flag the conversation.
         */
        flagConversation: async function(feedback) {
            try {
                await fetch(this.config.flagEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce
                    },
                    body: JSON.stringify({
                        conversation_id: this.state.conversationId,
                        feedback: feedback
                    })
                });

                this.addMessage('system', 'Thank you for your feedback. We will review this conversation.');
            } catch (error) {
                console.error('Failed to flag:', error);
            }
        },

        /**
         * Escape HTML.
         */
        escapeHtml: function(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => GlimmrAIChatWidget.init());
    } else {
        GlimmrAIChatWidget.init();
    }

})();
