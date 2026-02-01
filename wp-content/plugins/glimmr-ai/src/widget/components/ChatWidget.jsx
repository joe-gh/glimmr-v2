/**
 * ChatWidget - Main Widget Container
 *
 * The primary container component that manages widget state
 * and renders either the bubble or expanded window.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useEffect, useCallback, useRef } from 'preact/hooks';
import ChatBubble from './ChatBubble';
import ChatWindow from './ChatWindow';
import { getCart } from '../utils/storeApi';
import { executeCartAction, isCartActionArtifact } from '../utils/cartActionHandler';
import { useProactiveTriggers } from '../hooks/useProactiveTriggers';
import { debug, debugError, debugWarn } from '../utils/debug';
import { trackWidgetOpen, trackWidgetClose, trackMessageSent } from '../utils/ga4';

/**
 * Generate or retrieve conversation ID from localStorage.
 */
const getConversationId = () => {
    const storageKey = 'glimmr_ai_conversation_id';
    let conversationId = localStorage.getItem(storageKey);

    if (!conversationId) {
        conversationId = 'conv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem(storageKey, conversationId);
    }

    return conversationId;
};

/**
 * Fetch fresh cart data via WooCommerce Store API.
 * This ensures we have the browser's actual cart state.
 *
 * @param {string} nonce - WP REST nonce.
 * @returns {Promise<object|null>} Cart data or null on error.
 */
const getFreshCart = async (nonce) => {
    try {
        debug('[ChatWidget] Fetching fresh cart via Store API...');
        const cart = await getCart(nonce);
        debug('[ChatWidget] Fresh cart fetched:', cart);
        return {
            item_count: cart.items_count || 0,
            total: cart.totals?.total_price || '0',
            currency: cart.totals?.currency_code || 'USD',
            items: cart.items?.map(item => ({
                key: item.key,
                product_id: item.id,
                name: item.name,
                quantity: item.quantity,
                price: item.prices?.price || '0',
                variation_id: item.variation?.length > 0 ? item.id : null,
            })) || [],
        };
    } catch (err) {
        debugWarn('[ChatWidget] Failed to fetch fresh cart:', err);
        return null;
    }
};

/**
 * Main ChatWidget Component
 */
const ChatWidget = ({ config }) => {
    // Widget state
    const [isOpen, setIsOpen] = useState(false);
    const [isMinimized, setIsMinimized] = useState(false);
    const [hasNewMessage, setHasNewMessage] = useState(false);
    const [conversationId, setConversationId] = useState(getConversationId);
    const [gdprConsent, setGdprConsent] = useState(() => {
        return localStorage.getItem('glimmr_ai_gdpr_consent') === 'true';
    });

    // Messages state
    const [messages, setMessages] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isTyping, setIsTyping] = useState(false);
    const [loadingStatus, setLoadingStatus] = useState(null);
    const [error, setError] = useState(null);

    // Refs
    const widgetRef = useRef(null);

    /**
     * Load conversation history on mount.
     */
    useEffect(() => {
        if (gdprConsent || !config.gdprEnabled) {
            loadHistory();
        }
    }, [gdprConsent]);

    /**
     * Handle click outside to close on mobile.
     */
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (
                isOpen &&
                widgetRef.current &&
                !widgetRef.current.contains(e.target) &&
                window.innerWidth <= 768
            ) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen]);

    /**
     * Load conversation history from API.
     */
    const loadHistory = async () => {
        if (!conversationId) return;

        try {
            const response = await fetch(
                `${config.historyEndpoint}/${conversationId}`,
                {
                    headers: {
                        'X-WP-Nonce': config.nonce,
                    },
                    credentials: 'include',
                }
            );

            if (response.ok) {
                const data = await response.json();
                if (data.messages && data.messages.length > 0) {
                    // Map API fields to frontend format.
                    const mappedMessages = data.messages.map(msg => ({
                        id: msg.id,
                        role: msg.role,
                        content: msg.content,
                        timestamp: msg.created_at || msg.timestamp,
                        toolCalls: msg.tool_calls,
                        toolResults: msg.tool_results,
                        artifacts: msg.artifacts || [],
                    }));
                    setMessages(mappedMessages);
                } else {
                    // Add greeting message if no history.
                    addGreetingMessage();
                }
            } else if (response.status === 403) {
                // Session mismatch - clear old conversation and start fresh
                debug('[ChatWidget] Session mismatch on history load, starting fresh conversation...');
                const newId = 'conv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('glimmr_ai_conversation_id', newId);
                setConversationId(newId);
                addGreetingMessage();
            } else {
                addGreetingMessage();
            }
        } catch (err) {
            debugError('[ChatWidget] Failed to load history:', err);
            addGreetingMessage();
        }
    };

    /**
     * Add greeting message.
     */
    const addGreetingMessage = () => {
        const greeting = config.greeting || 'Hi! How can I help you today?';
        setMessages([
            {
                id: 'greeting',
                role: 'assistant',
                content: greeting,
                timestamp: new Date().toISOString(),
            },
        ]);
    };

    /**
     * Send a message to the API with streaming support.
     * Uses Server-Sent Events (SSE) for real-time status updates and response streaming.
     */
    const sendMessage = useCallback(
        async (content) => {
            if (!content.trim() || isLoading) return;

            const messageContent = content.trim();

            // Add user message immediately
            const userMessage = {
                id: `user_${Date.now()}`,
                role: 'user',
                content: messageContent,
                timestamp: new Date().toISOString(),
            };

            setMessages((prev) => [...prev, userMessage]);
            setIsLoading(true);
            setIsTyping(true);
            setLoadingStatus('Thinking...');
            setError(null);

            // Track message sent for GA4
            trackMessageSent(messageContent.length);

            const assistantMessageId = `assistant_${Date.now()}`;
            let accumulatedContent = '';
            let collectedArtifacts = [];
            let currentEventType = null;
            let contentUpdateScheduled = false;

            // Fetch fresh cart from Store API before sending
            // This ensures we have the browser's actual cart state
            const freshCart = await getFreshCart(config.nonce);
            debug('[ChatWidget] Including fresh cart in context:', freshCart);

            // Add placeholder assistant message for streaming
            setMessages((prev) => [
                ...prev,
                {
                    id: assistantMessageId,
                    role: 'assistant',
                    content: '',
                    timestamp: new Date().toISOString(),
                    isStreaming: true,
                    artifacts: [],
                },
            ]);

            try {
                const response = await fetch(config.streamEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce,
                        'Accept': 'text/event-stream',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        message: messageContent,
                        context: {
                            page_url: window.location.href,
                            page_title: document.title,
                            cart_count: freshCart?.item_count || config.cartCount || 0,
                            cart: freshCart,  // Include full cart data
                            is_logged_in: config.isLoggedIn,
                        },
                    }),
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        if (line.startsWith('event: ')) {
                            // Store the event type for the next data line
                            currentEventType = line.slice(7).trim();
                            continue;
                        }

                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            if (data === '[DONE]') {
                                currentEventType = null;
                                continue;
                            }

                            try {
                                const event = JSON.parse(data);

                                // Handle based on SSE event type
                                if (currentEventType === 'artifact') {
                                    // Artifact event - complete artifact object with type and data
                                    if (event.type && event.data) {
                                        // Check if this is a cart_action that needs frontend execution
                                        if (isCartActionArtifact(event)) {
                                            debug('[ChatWidget] Detected cart_action artifact, executing via Store API:', event.data);

                                            // Mark as pending initially
                                            const pendingArtifact = {
                                                ...event,
                                                data: {
                                                    ...event.data,
                                                    executed: false,
                                                    message: event.data.message || 'Processing cart action...',
                                                },
                                            };
                                            collectedArtifacts.push(pendingArtifact);

                                            // Update message with pending artifact
                                            setMessages((prev) =>
                                                prev.map((msg) =>
                                                    msg.id === assistantMessageId
                                                        ? { ...msg, artifacts: [...collectedArtifacts] }
                                                        : msg
                                                )
                                            );

                                            // Execute the cart action via Store API (uses storeApiNonce, not wp_rest nonce)
                                            try {
                                                const actionResult = await executeCartAction(config.storeApiNonce, event.data);
                                                debug('[ChatWidget] Cart action result:', actionResult);

                                                // Update the artifact with the result
                                                const artifactIndex = collectedArtifacts.length - 1;
                                                collectedArtifacts[artifactIndex] = {
                                                    ...event,
                                                    data: {
                                                        ...event.data,
                                                        executed: true,
                                                        result: actionResult.success ? actionResult : null,
                                                        error: actionResult.success ? null : actionResult.error,
                                                    },
                                                };

                                                // Update message with result
                                                setMessages((prev) =>
                                                    prev.map((msg) =>
                                                        msg.id === assistantMessageId
                                                            ? { ...msg, artifacts: [...collectedArtifacts] }
                                                            : msg
                                                    )
                                                );

                                                // Handle redirect if requested
                                                if (actionResult.redirect) {
                                                    debug('[ChatWidget] Redirecting to:', actionResult.redirect);
                                                    setTimeout(() => {
                                                        window.location.href = actionResult.redirect;
                                                    }, 1500);
                                                }
                                            } catch (cartError) {
                                                debugError('[ChatWidget] Cart action execution failed:', cartError);
                                                // Update artifact with error
                                                const artifactIndex = collectedArtifacts.length - 1;
                                                collectedArtifacts[artifactIndex] = {
                                                    ...event,
                                                    data: {
                                                        ...event.data,
                                                        executed: true,
                                                        error: cartError.message || 'Cart operation failed',
                                                    },
                                                };
                                                setMessages((prev) =>
                                                    prev.map((msg) =>
                                                        msg.id === assistantMessageId
                                                            ? { ...msg, artifacts: [...collectedArtifacts] }
                                                            : msg
                                                    )
                                                );
                                            }
                                        } else {
                                            // Non-cart artifact - collect for end of stream
                                            // Don't update message state during streaming to avoid double rendering
                                            collectedArtifacts.push(event);
                                        }
                                    }
                                } else if (currentEventType === 'status') {
                                    // Status update event
                                    if (event.type && event.message) {
                                        setLoadingStatus(event.message);
                                    }
                                } else if (currentEventType === 'content') {
                                    // Content chunk - append to message
                                    if (event.text) {
                                        setLoadingStatus(null);
                                        accumulatedContent += event.text;

                                        // Use requestAnimationFrame to ensure browser renders between updates
                                        // This prevents all chunks from being batched into a single render
                                        if (!contentUpdateScheduled) {
                                            contentUpdateScheduled = true;
                                            requestAnimationFrame(() => {
                                                setMessages((prev) =>
                                                    prev.map((msg) =>
                                                        msg.id === assistantMessageId
                                                            ? { ...msg, content: accumulatedContent }
                                                            : msg
                                                    )
                                                );
                                                contentUpdateScheduled = false;
                                            });
                                        }
                                    }
                                } else if (currentEventType === 'error') {
                                    // Error event
                                    throw new Error(event.message || 'An error occurred');
                                } else if (currentEventType === 'init' || currentEventType === 'done') {
                                    // Init or done event with conversation ID
                                    if (event.conversation_id && event.conversation_id !== conversationId) {
                                        localStorage.setItem('glimmr_ai_conversation_id', event.conversation_id);
                                        setConversationId(event.conversation_id);
                                    }
                                } else {
                                    // Fallback for unrecognized events - try to parse based on content
                                    if (event.type && ['thinking', 'tool', 'responding'].includes(event.type) && event.message) {
                                        setLoadingStatus(event.message);
                                    } else if (event.text) {
                                        setLoadingStatus(null);
                                        accumulatedContent += event.text;

                                        // Use requestAnimationFrame to ensure browser renders between updates
                                        if (!contentUpdateScheduled) {
                                            contentUpdateScheduled = true;
                                            requestAnimationFrame(() => {
                                                setMessages((prev) =>
                                                    prev.map((msg) =>
                                                        msg.id === assistantMessageId
                                                            ? { ...msg, content: accumulatedContent }
                                                            : msg
                                                    )
                                                );
                                                contentUpdateScheduled = false;
                                            });
                                        }
                                    } else if (event.conversation_id) {
                                        if (event.conversation_id !== conversationId) {
                                            localStorage.setItem('glimmr_ai_conversation_id', event.conversation_id);
                                            setConversationId(event.conversation_id);
                                        }
                                    }
                                }

                                // Reset event type after processing
                                currentEventType = null;
                            } catch (parseError) {
                                // Skip malformed JSON
                                debugWarn('[ChatWidget] Failed to parse SSE data:', data);
                            }
                        }
                    }
                }

                // Mark streaming as complete with final content and artifacts
                // Include content to ensure any pending RAF updates are captured
                setMessages((prev) =>
                    prev.map((msg) =>
                        msg.id === assistantMessageId
                            ? { ...msg, content: accumulatedContent, isStreaming: false, artifacts: collectedArtifacts }
                            : msg
                    )
                );

                // Show notification if minimized
                if (isMinimized || !isOpen) {
                    setHasNewMessage(true);
                }
            } catch (err) {
                debugError('[ChatWidget] Message error:', err);
                setError(err.message || 'Failed to send message. Please try again.');

                // Update the streaming message to show error
                setMessages((prev) =>
                    prev.map((msg) =>
                        msg.id === assistantMessageId
                            ? {
                                ...msg,
                                content: accumulatedContent || 'Sorry, there was an error. Please try again.',
                                isStreaming: false,
                                isError: !accumulatedContent,
                            }
                            : msg
                    )
                );
            } finally {
                setIsLoading(false);
                setIsTyping(false);
                setLoadingStatus(null);
            }
        },
        [conversationId, config, isLoading, isMinimized, isOpen]
    );

    /**
     * Handle quick reply click.
     */
    const handleQuickReply = useCallback(
        (action) => {
            sendMessage(action);
        },
        [sendMessage]
    );

    /**
     * Handle GDPR consent.
     */
    const handleGdprConsent = useCallback(() => {
        setGdprConsent(true);
        localStorage.setItem('glimmr_ai_gdpr_consent', 'true');
        localStorage.setItem('glimmr_ai_gdpr_consent_date', new Date().toISOString());
        addGreetingMessage();

        // Track consent event via API
        if (config.apiEndpoint) {
            fetch(config.apiEndpoint.replace('/message', '/consent'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'granted',
                    conversation_id: conversationId,
                }),
            }).catch((error) => {
                // Non-critical: consent tracking failed but chat can continue
                debugWarn('[ChatWidget] Failed to track consent event:', error.message);
            });
        }
    }, [config, conversationId]);

    /**
     * Handle GDPR consent revocation.
     */
    const handleRevokeConsent = useCallback(() => {
        // Clear local data
        setGdprConsent(false);
        setMessages([]);
        localStorage.removeItem('glimmr_ai_gdpr_consent');
        localStorage.removeItem('glimmr_ai_gdpr_consent_date');
        localStorage.removeItem('glimmr_ai_conversation_id');

        // Track revocation event via API
        if (config.apiEndpoint) {
            fetch(config.apiEndpoint.replace('/message', '/consent'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'revoked',
                    conversation_id: conversationId,
                }),
            }).catch((error) => {
                // Non-critical: revocation tracking failed but local data is already cleared
                debugWarn('[ChatWidget] Failed to track consent revocation:', error.message);
            });
        }

        // Close widget after revocation
        setIsOpen(false);
    }, [config, conversationId]);

    /**
     * Toggle widget open/closed.
     */
    const toggleWidget = useCallback(() => {
        setIsOpen((prev) => {
            const willBeOpen = !prev;
            // Track widget open/close
            if (willBeOpen) {
                trackWidgetOpen();
            } else {
                trackWidgetClose();
            }
            return willBeOpen;
        });
        setIsMinimized(false);
        setHasNewMessage(false);
    }, []);

    /**
     * Open the widget (for proactive triggers).
     */
    const openWidget = useCallback(() => {
        setIsOpen(true);
        setIsMinimized(false);
        setHasNewMessage(false);
        trackWidgetOpen();
    }, []);

    /**
     * Add a proactive message from the assistant.
     * This is different from greeting - it's triggered by user behavior.
     *
     * @param {string} message - The proactive message text.
     * @param {string} triggerType - The type of trigger (time, exit, scroll).
     */
    const addProactiveMessage = useCallback((message, triggerType) => {
        if (!message) return;

        const proactiveMessage = {
            id: `proactive_${triggerType}_${Date.now()}`,
            role: 'assistant',
            content: message,
            timestamp: new Date().toISOString(),
            isProactive: true,
            triggerType: triggerType,
        };

        setMessages((prev) => {
            // Don't add if we already have this type of proactive message.
            if (prev.some((m) => m.isProactive && m.triggerType === triggerType)) {
                return prev;
            }
            return [...prev, proactiveMessage];
        });

        // Show notification dot if widget is minimized.
        if (!isOpen) {
            setHasNewMessage(true);
        }
    }, [isOpen]);

    /**
     * Proactive engagement triggers.
     * Monitors time-on-page, exit intent, and scroll depth.
     */
    const { triggered: proactiveTriggered } = useProactiveTriggers(
        config,
        {
            onOpen: openWidget,
            onAddSystemMessage: addProactiveMessage,
        },
        isOpen
    );

    /**
     * Flag a message.
     */
    const flagMessage = useCallback(
        async (messageId, issueType, feedback) => {
            try {
                await fetch(config.flagEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce,
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        conversation_id: conversationId,
                        message_id: messageId,
                        issue_type: issueType,
                        feedback: feedback,
                    }),
                });
            } catch (err) {
                debugError('[ChatWidget] Failed to flag message:', err);
            }
        },
        [conversationId, config]
    );

    /**
     * Start new conversation.
     */
    const startNewConversation = useCallback(() => {
        const newId = 'conv_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('glimmr_ai_conversation_id', newId);
        setConversationId(newId);
        setMessages([]);
        setError(null);
        addGreetingMessage();
    }, []);

    // Determine position class
    const positionClass = config.position === 'bottom-left' ? 'position-left' : 'position-right';

    // Build CSS custom properties from config.
    const cssVars = {
        // Brand colors.
        '--glimmr-primary': config.primaryColor || '#4F46E5',
        '--glimmr-primary-hover': config.primaryHover || '#4338CA',
        '--glimmr-secondary': config.secondaryColor || '#818CF8',

        // Background & surface colors.
        '--glimmr-bg': config.bgColor || '#FFFFFF',
        '--glimmr-bg-light': config.bgLight || '#F3F4F6',
        '--glimmr-border': config.borderColor || '#E5E7EB',

        // Text colors.
        '--glimmr-text': config.textColor || '#FFFFFF',
        '--glimmr-text-dark': config.textDark || '#1F2937',
        '--glimmr-text-muted': config.textMuted || '#6B7280',

        // Status colors.
        '--glimmr-success': config.successColor || '#059669',
        '--glimmr-error': config.errorColor || '#DC2626',

        // Button style.
        '--glimmr-button-border': config.buttonBorder || 'transparent',
        '--glimmr-button-border-width': `${config.buttonBorderWidth || 0}px`,
        '--glimmr-radius': `${config.borderRadius || 16}px`,
        '--glimmr-radius-sm': `${Math.max(4, (config.borderRadius || 16) / 2)}px`,

        // Widget dimensions.
        '--glimmr-widget-width': `${config.width || 400}px`,
        '--glimmr-widget-height': `${config.height || 650}px`,

        // Widget positioning.
        '--glimmr-offset-x': `${config.offsetX ?? 20}px`,
        '--glimmr-offset-y': `${config.offsetY ?? 20}px`,
        '--glimmr-z-index': config.zIndex ?? 999999,

        // Typography.
        '--glimmr-font': config.fontFamily || 'inherit',

        // Header logo dimensions.
        '--glimmr-header-logo-max-width': `${config.headerLogoMaxWidth || 120}px`,
        '--glimmr-header-logo-max-height': `${config.headerLogoMaxHeight || 32}px`,

        // Title typography.
        '--glimmr-title-font-size': `${config.titleFontSize || 16}px`,
        '--glimmr-title-font-weight': config.titleFontWeight || '600',
    };

    return (
        <div
            ref={widgetRef}
            className={`glimmr-widget ${positionClass} ${isOpen ? 'is-open' : ''}`}
            style={cssVars}
        >
            {isOpen ? (
                <ChatWindow
                    config={config}
                    messages={messages}
                    isLoading={isLoading}
                    isTyping={isTyping}
                    loadingStatus={loadingStatus}
                    error={error}
                    gdprConsent={gdprConsent || !config.gdprEnabled}
                    onSendMessage={sendMessage}
                    onQuickReply={handleQuickReply}
                    onGdprConsent={handleGdprConsent}
                    onRevokeConsent={handleRevokeConsent}
                    onClose={toggleWidget}
                    onFlagMessage={flagMessage}
                    onNewConversation={startNewConversation}
                />
            ) : (
                <ChatBubble
                    config={config}
                    hasNewMessage={hasNewMessage}
                    onClick={toggleWidget}
                />
            )}
        </div>
    );
};

export default ChatWidget;
