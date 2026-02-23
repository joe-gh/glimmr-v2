/**
 * ChatWindow - Expanded Chat Panel
 *
 * The main chat window with header, messages, and input.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

import { h, Fragment } from 'preact';
import { useState, useCallback, useEffect, useRef } from 'preact/hooks';
import MessageList from './MessageList';
import MessageInput from './MessageInput';
import QuickReplies from './QuickReplies';
import { debugError } from '../utils/debug';
import { updateCartCount } from '../utils/cartActionHandler';

/**
 * Header icons
 */
const CloseIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

const MoreIcon = () => (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="1" />
        <circle cx="12" cy="5" r="1" />
        <circle cx="12" cy="19" r="1" />
    </svg>
);

/**
 * ChatWindow Component
 */
const ChatWindow = ({
    config,
    messages,
    isLoading,
    isTyping,
    loadingStatus,
    error,
    gdprConsent,
    onSendMessage,
    onQuickReply,
    onGdprConsent,
    onRevokeConsent,
    onClose,
    onFlagMessage,
    onNewConversation,
}) => {
    const [showMenu, setShowMenu] = useState(false);
    const [showAbout, setShowAbout] = useState(false);
    const menuRef = useRef(null);
    const menuButtonRef = useRef(null);
    const aboutPreviousFocusRef = useRef(null);
    const aboutModalRef = useRef(null);

    /**
     * Close menu when clicking outside.
     */
    useEffect(() => {
        if (!showMenu) return;

        const handleClickOutside = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target) &&
                menuButtonRef.current && !menuButtonRef.current.contains(e.target)) {
                setShowMenu(false);
            }
        };

        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                setShowMenu(false);
                menuButtonRef.current?.focus();
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleKeyDown);

        // Focus first menu item when opened
        if (menuRef.current) {
            const firstItem = menuRef.current.querySelector('[role="menuitem"]');
            if (firstItem) firstItem.focus();
        }

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [showMenu]);

    /**
     * Handle keyboard navigation in menu.
     */
    const handleMenuKeyDown = useCallback((e) => {
        if (!menuRef.current) return;

        const items = menuRef.current.querySelectorAll('[role="menuitem"]');
        const currentIndex = Array.from(items).indexOf(document.activeElement);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
            items[nextIndex]?.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
            items[prevIndex]?.focus();
        } else if (e.key === 'Home') {
            e.preventDefault();
            items[0]?.focus();
        } else if (e.key === 'End') {
            e.preventDefault();
            items[items.length - 1]?.focus();
        }
    }, []);

    /**
     * Focus management for About modal: save previous focus and restore on close.
     */
    useEffect(() => {
        if (showAbout) {
            // Save the currently focused element
            aboutPreviousFocusRef.current = document.activeElement;

            // Focus the close button or first focusable element
            if (aboutModalRef.current) {
                const closeBtn = aboutModalRef.current.querySelector('.glimmr-about-close');
                if (closeBtn) {
                    closeBtn.focus();
                }
            }
        } else if (aboutPreviousFocusRef.current) {
            // Restore focus when modal closes
            aboutPreviousFocusRef.current.focus();
            aboutPreviousFocusRef.current = null;
        }
    }, [showAbout]);

    /**
     * Handle keyboard events for About modal: escape to close and focus trapping.
     */
    useEffect(() => {
        if (!showAbout) return;

        const handleKeyDown = (e) => {
            // Close on Escape
            if (e.key === 'Escape') {
                setShowAbout(false);
                return;
            }

            // Focus trap on Tab
            if (e.key === 'Tab' && aboutModalRef.current) {
                const focusableElements = aboutModalRef.current.querySelectorAll(
                    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                );

                if (focusableElements.length === 0) return;

                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];

                if (e.shiftKey) {
                    // Shift+Tab: if on first element, wrap to last
                    if (document.activeElement === firstElement) {
                        e.preventDefault();
                        lastElement.focus();
                    }
                } else {
                    // Tab: if on last element, wrap to first
                    if (document.activeElement === lastElement) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [showAbout]);

    // Show quick replies only if no messages yet (besides greeting)
    const showQuickReplies =
        config.quickReplies &&
        config.quickReplies.length > 0 &&
        messages.length <= 1;

    /**
     * Handle add to cart from product modals.
     */
    const handleAddToCart = useCallback(async ({ productId, variationId, quantity, attributes }) => {
        if (!config.cartAddEndpoint) {
            debugError('[ChatWindow] Cart endpoint not configured');
            throw new Error('Cart endpoint not configured');
        }

        const response = await fetch(config.cartAddEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            credentials: 'include', // Include cookies for WooCommerce cart session
            body: JSON.stringify({
                product_id: productId,
                variation_id: variationId || 0,
                quantity: quantity || 1,
                variation: attributes || {},
            }),
        });

        const data = await response.json();

        if (!response.ok || data.success === false) {
            throw new Error(data.message || 'Failed to add to cart');
        }

        // Update custom cart count elements (e.g., #cart-item-count)
        updateCartCount(data);

        // Refresh WooCommerce minicart fragments.
        if (typeof jQuery !== 'undefined' && jQuery(document.body).trigger) {
            jQuery(document.body).trigger('wc_fragment_refresh');
        }

        // Also trigger added_to_cart event for themes that listen to it.
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, jQuery()]);
        }

        return data;
    }, [config.cartAddEndpoint, config.nonce]);

    return (
        <div className="glimmr-window" role="dialog" aria-label="Chat window">
            {/* Header */}
            <div className="glimmr-window-header">
                <div className="glimmr-window-header-info">
                    {config.headerLogoUrl && (
                        <img
                            src={config.headerLogoUrl}
                            alt=""
                            className="glimmr-header-logo"
                        />
                    )}
                    <div className="glimmr-window-title">
                        <h3>{config.name || 'Shopping Assistant'}</h3>
                    </div>
                </div>

                <div className="glimmr-window-actions">
                    {/* More menu */}
                    <div className="glimmr-menu-wrapper">
                        <button
                            ref={menuButtonRef}
                            className="glimmr-window-btn"
                            onClick={() => setShowMenu(!showMenu)}
                            aria-label="More options"
                            aria-expanded={showMenu}
                            aria-haspopup="menu"
                        >
                            <MoreIcon />
                        </button>

                        {showMenu && (
                            <div
                                ref={menuRef}
                                className="glimmr-menu"
                                role="menu"
                                aria-label="Chat options"
                                onKeyDown={handleMenuKeyDown}
                            >
                                <button
                                    className="glimmr-menu-item"
                                    role="menuitem"
                                    onClick={() => {
                                        onNewConversation();
                                        setShowMenu(false);
                                        menuButtonRef.current?.focus();
                                    }}
                                >
                                    Start new conversation
                                </button>
                                <button
                                    className="glimmr-menu-item"
                                    role="menuitem"
                                    onClick={() => {
                                        window.open(config.supportUrl || '/contact', '_blank');
                                        setShowMenu(false);
                                    }}
                                >
                                    Contact support
                                </button>
                                <button
                                    className="glimmr-menu-item"
                                    role="menuitem"
                                    onClick={() => {
                                        setShowAbout(true);
                                        setShowMenu(false);
                                    }}
                                >
                                    About
                                </button>
                                {config.gdprEnabled && gdprConsent && (
                                    <>
                                        <div className="glimmr-menu-divider" role="separator" />
                                        <button
                                            className="glimmr-menu-item glimmr-menu-item-danger"
                                            role="menuitem"
                                            onClick={() => {
                                                onRevokeConsent();
                                                setShowMenu(false);
                                            }}
                                        >
                                            Revoke data consent
                                        </button>
                                    </>
                                )}
                            </div>
                        )}
                    </div>

                    <button
                        className="glimmr-window-btn"
                        onClick={onClose}
                        aria-label="Close chat"
                    >
                        <CloseIcon />
                    </button>
                </div>
            </div>

            {/* GDPR Consent */}
            {!gdprConsent && config.gdprEnabled && (
                <div className="glimmr-gdpr-banner" role="region" aria-label="Privacy notice">
                    <div className="glimmr-gdpr-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M12 16v-4M12 8h.01" />
                        </svg>
                    </div>
                    <div className="glimmr-gdpr-content">
                        <h4 id="gdpr-title">Privacy Notice</h4>
                        <p>
                            {config.gdprText || 'By chatting with our AI assistant, you agree to our data processing practices.'}
                            {config.privacyPolicyUrl && (
                                <> Read our <a
                                    href={config.privacyPolicyUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="glimmr-gdpr-link"
                                >
                                    Privacy Policy
                                    <span className="glimmr-sr-only"> (opens in new tab)</span>
                                </a>.</>
                            )}
                        </p>
                    </div>
                    <div className="glimmr-gdpr-actions">
                        <button
                            type="button"
                            className="glimmr-gdpr-decline"
                            onClick={onClose}
                            aria-label="Decline and close"
                        >
                            Decline
                        </button>
                        <button
                            type="button"
                            className="glimmr-gdpr-accept"
                            onClick={onGdprConsent}
                            aria-label="Accept privacy policy"
                        >
                            Accept & Continue
                        </button>
                    </div>
                </div>
            )}

            {/* Messages */}
            <div className="glimmr-window-body">
                {gdprConsent || !config.gdprEnabled ? (
                    <>
                        <MessageList
                            messages={messages}
                            isTyping={isTyping}
                            loadingStatus={loadingStatus}
                            config={config}
                            onFlagMessage={onFlagMessage}
                            onAddToCart={handleAddToCart}
                        />

                        {/* Quick replies */}
                        {showQuickReplies && (
                            <QuickReplies
                                replies={config.quickReplies}
                                onSelect={onQuickReply}
                            />
                        )}
                    </>
                ) : (
                    <div className="glimmr-consent-required">
                        <p>Please accept the privacy policy to start chatting.</p>
                    </div>
                )}
            </div>

            {/* Error banner */}
            {error && (
                <div className="glimmr-error-banner" role="alert">
                    {error}
                </div>
            )}

            {/* Input */}
            {(gdprConsent || !config.gdprEnabled) && (
                <MessageInput
                    onSend={onSendMessage}
                    isLoading={isLoading}
                    placeholder={config.inputPlaceholder || 'Type a message...'}
                />
            )}

            {/* About Modal */}
            {showAbout && (
                <div
                    className="glimmr-about-overlay"
                    onClick={() => setShowAbout(false)}
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="glimmr-about-title"
                >
                    <div
                        ref={aboutModalRef}
                        className="glimmr-about-modal"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <h4 id="glimmr-about-title">Glimmr AI</h4>
                        <p>AI Shopping Assistant</p>
                        <p className="glimmr-about-version">Version 1.0</p>
                        <p className="glimmr-about-creator">
                            Created by Joseph DiGiovanna
                        </p>
                        <p className="glimmr-about-email">
                            <a href="mailto:joseph.p.digiovanna@gmail.com">
                                joseph.p.digiovanna@gmail.com
                            </a>
                        </p>
                        <button
                            type="button"
                            className="glimmr-about-close"
                            onClick={() => setShowAbout(false)}
                        >
                            Close
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ChatWindow;
