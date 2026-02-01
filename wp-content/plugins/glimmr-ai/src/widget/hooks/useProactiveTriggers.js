/**
 * Proactive Triggers Hook
 *
 * Handles time-on-page, exit-intent, scroll-depth, abandoned cart,
 * and idle engagement triggers to proactively engage customers
 * with the chat widget.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 * @updated 1.9.0 - Added abandoned cart and idle engagement triggers
 */

import { useState, useEffect, useCallback, useRef } from 'preact/hooks';

/**
 * Session storage key prefix for trigger states.
 */
const STORAGE_KEY = 'glimmr_ai_triggers';

/**
 * Get the current page type from glimmrAIConfig.
 * @returns {string} Page type (product, category, shop, cart, etc.)
 */
const getCurrentPageType = () => {
    return window.glimmrAIConfig?.pageContext?.pageType || 'other';
};

/**
 * Check if current page matches any of the allowed pages.
 * @param {Array} allowedPages - Array of page types to check against.
 * @returns {boolean} True if current page is in allowed pages.
 */
const isPageAllowed = (allowedPages) => {
    if (!allowedPages || !Array.isArray(allowedPages) || allowedPages.length === 0) {
        return true; // If no pages specified, allow all.
    }
    const currentPage = getCurrentPageType();
    return allowedPages.includes(currentPage);
};

/**
 * Get session storage state for triggers.
 * @returns {Object} Trigger states from session storage.
 */
const getSessionState = () => {
    try {
        const stored = sessionStorage.getItem(STORAGE_KEY);
        return stored ? JSON.parse(stored) : {};
    } catch (e) {
        return {};
    }
};

/**
 * Save trigger state to session storage.
 * @param {string} triggerType - Type of trigger (time, exit, scroll).
 */
const markTriggered = (triggerType) => {
    try {
        const state = getSessionState();
        state[triggerType] = true;
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {
        // Session storage not available.
    }
};

/**
 * Check if a trigger was already fired this session.
 * @param {string} triggerType - Type of trigger to check.
 * @returns {boolean} True if already triggered.
 */
const wasTriggeredThisSession = (triggerType) => {
    const state = getSessionState();
    return state[triggerType] === true;
};

/**
 * Get cart data from glimmrAIConfig.
 * @returns {Object} Cart data with items array, count, and total.
 */
const getCartData = () => {
    const cart = window.glimmrAIConfig?.pageContext?.cart || {};
    return {
        items: cart.items || [],
        itemCount: cart.item_count || cart.itemCount || 0,
        total: parseFloat(cart.total) || 0,
    };
};

/**
 * Check if cart meets minimum requirements for abandoned cart trigger.
 * @param {Object} config - Abandoned cart trigger config.
 * @returns {boolean} True if cart meets requirements.
 */
const cartMeetsRequirements = (config) => {
    const cart = getCartData();
    const minValue = config.minValue || 0;
    const minItems = config.minItems || 1;

    return cart.itemCount >= minItems && cart.total >= minValue;
};

/**
 * Format cart items for display in abandoned cart message.
 * @returns {string} Formatted cart items string.
 */
const formatCartItemsForMessage = () => {
    const cart = getCartData();
    if (!cart.items.length) return '';

    const itemsList = cart.items.slice(0, 3).map(item => {
        const name = item.name || item.product_name || 'Item';
        const qty = item.quantity || 1;
        return `• ${name}${qty > 1 ? ` (×${qty})` : ''}`;
    }).join('\n');

    const moreCount = cart.items.length - 3;
    const moreText = moreCount > 0 ? `\n• ...and ${moreCount} more item${moreCount > 1 ? 's' : ''}` : '';

    return `\n\n**Your cart:**\n${itemsList}${moreText}`;
};

/**
 * Proactive Triggers Hook
 *
 * @param {Object} config - Trigger configuration from widget config.
 * @param {Object} callbacks - Callback functions for trigger actions.
 * @param {Function} callbacks.onOpen - Called when widget should open.
 * @param {Function} callbacks.onAddSystemMessage - Called to add a proactive message.
 * @param {boolean} isOpen - Whether the chat widget is currently open.
 * @returns {Object} Trigger states and controls.
 */
export function useProactiveTriggers(config, { onOpen, onAddSystemMessage }, isOpen) {
    const [triggered, setTriggered] = useState({
        time: false,
        exit: false,
        scroll: false,
        abandonedCart: false,
        idleEngagement: false,
    });

    // Refs to track state without re-renders.
    const lastScrollY = useRef(0);
    const scrollTriggeredRef = useRef(false);
    const exitTriggeredRef = useRef(false);
    const timeTriggeredRef = useRef(false);
    const abandonedCartTriggeredRef = useRef(false);
    const idleEngagementTriggeredRef = useRef(false);
    const lastActivityRef = useRef(Date.now());

    /**
     * Fire a trigger - opens widget and optionally sends message.
     */
    const fireTrigger = useCallback((type, message) => {
        // Don't fire if widget is already open.
        if (isOpen) return;

        // Mark as triggered.
        setTriggered((prev) => ({ ...prev, [type]: true }));
        markTriggered(type);

        // Open the widget.
        onOpen();

        // Add proactive message if provided.
        if (message && onAddSystemMessage) {
            // Small delay to let widget open first.
            setTimeout(() => {
                onAddSystemMessage(message, type);
            }, 300);
        }

        // Track analytics if available.
        if (window.glimmrAIConfig?.analyticsEnabled) {
            try {
                fetch(`${window.glimmrAIConfig.restUrl}analytics`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        event: 'proactive_trigger',
                        type,
                        page: getCurrentPageType(),
                    }),
                });
            } catch (e) {
                // Analytics tracking failed silently.
            }
        }
    }, [isOpen, onOpen, onAddSystemMessage]);

    /**
     * Time-on-page trigger.
     */
    useEffect(() => {
        const timeConfig = config?.triggers?.time;
        if (!timeConfig?.enabled) return;
        if (timeTriggeredRef.current || wasTriggeredThisSession('time')) return;
        if (isOpen) return;
        if (!isPageAllowed(timeConfig.pages)) return;

        const timer = setTimeout(() => {
            if (!timeTriggeredRef.current && !isOpen) {
                timeTriggeredRef.current = true;
                fireTrigger('time', timeConfig.message);
            }
        }, timeConfig.delay || 30000);

        return () => clearTimeout(timer);
    }, [config, isOpen, fireTrigger]);

    /**
     * Exit-intent trigger (mouse leaves viewport toward top).
     */
    useEffect(() => {
        const exitConfig = config?.triggers?.exit;
        if (!exitConfig?.enabled) return;

        // Check if should only trigger once per session.
        if (exitConfig.oncePerSession && wasTriggeredThisSession('exit')) return;
        if (exitTriggeredRef.current) return;
        if (!isPageAllowed(exitConfig.pages)) return;

        const handleMouseLeave = (e) => {
            // Only trigger when mouse leaves toward top of viewport.
            if (e.clientY <= 0 && !exitTriggeredRef.current && !isOpen) {
                exitTriggeredRef.current = true;
                fireTrigger('exit', exitConfig.message);
            }
        };

        document.addEventListener('mouseleave', handleMouseLeave);
        return () => document.removeEventListener('mouseleave', handleMouseLeave);
    }, [config, isOpen, fireTrigger]);

    /**
     * Mobile exit-intent alternative: rapid scroll-up detection.
     * Triggers when user rapidly scrolls up near the top of the page.
     */
    useEffect(() => {
        const exitConfig = config?.triggers?.exit;
        if (!exitConfig?.enabled) return;

        // Skip on desktop - use mouse leave instead.
        if (!('ontouchstart' in window)) return;

        if (exitConfig.oncePerSession && wasTriggeredThisSession('exit')) return;
        if (exitTriggeredRef.current) return;
        if (!isPageAllowed(exitConfig.pages)) return;

        let lastY = window.scrollY;
        let rapidScrollCount = 0;

        const handleScroll = () => {
            const currentY = window.scrollY;
            const delta = lastY - currentY;

            // Detect rapid upward scroll near top of page.
            if (delta > 50 && currentY < 300) {
                rapidScrollCount++;

                // Trigger after 2-3 rapid upward scrolls.
                if (rapidScrollCount >= 2 && !exitTriggeredRef.current && !isOpen) {
                    exitTriggeredRef.current = true;
                    fireTrigger('exit', exitConfig.message);
                }
            } else {
                rapidScrollCount = 0;
            }

            lastY = currentY;
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        return () => window.removeEventListener('scroll', handleScroll);
    }, [config, isOpen, fireTrigger]);

    /**
     * Scroll-depth trigger.
     */
    useEffect(() => {
        const scrollConfig = config?.triggers?.scroll;
        if (!scrollConfig?.enabled) return;
        if (scrollTriggeredRef.current || wasTriggeredThisSession('scroll')) return;
        if (!isPageAllowed(scrollConfig.pages)) return;

        const handleScroll = () => {
            if (scrollTriggeredRef.current || isOpen) return;

            const scrollPercent = (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100;
            const threshold = scrollConfig.percent || 50;

            if (scrollPercent >= threshold) {
                scrollTriggeredRef.current = true;
                fireTrigger('scroll', scrollConfig.message);
            }
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        return () => window.removeEventListener('scroll', handleScroll);
    }, [config, isOpen, fireTrigger]);

    /**
     * Abandoned cart trigger - fires when user has items in cart but is inactive.
     */
    useEffect(() => {
        const cartConfig = config?.triggers?.abandonedCart;
        if (!cartConfig?.enabled) return;

        // Check if should only trigger once per session.
        if (cartConfig.oncePerSession && wasTriggeredThisSession('abandonedCart')) return;
        if (abandonedCartTriggeredRef.current) return;
        if (!isPageAllowed(cartConfig.pages)) return;

        // Check if cart meets requirements.
        if (!cartMeetsRequirements(cartConfig)) return;

        // Track user activity for inactivity detection.
        const updateActivity = () => {
            lastActivityRef.current = Date.now();
        };

        // Listen for user activity.
        const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
        activityEvents.forEach(event => {
            window.addEventListener(event, updateActivity, { passive: true });
        });

        // Check for inactivity periodically.
        const inactivityDelay = cartConfig.inactivityDelay || 60000;
        const checkInterval = setInterval(() => {
            if (abandonedCartTriggeredRef.current || isOpen) return;

            const timeSinceActivity = Date.now() - lastActivityRef.current;
            if (timeSinceActivity >= inactivityDelay && cartMeetsRequirements(cartConfig)) {
                abandonedCartTriggeredRef.current = true;

                // Build message with optional cart items.
                let message = cartConfig.message;
                if (cartConfig.includeItems) {
                    message += formatCartItemsForMessage();
                }

                // Add coupon offer if enabled.
                if (cartConfig.offerCoupon && cartConfig.couponCode) {
                    message += `\n\nUse code **${cartConfig.couponCode}** for a special discount!`;
                }

                fireTrigger('abandonedCart', message);
            }
        }, 5000); // Check every 5 seconds.

        return () => {
            clearInterval(checkInterval);
            activityEvents.forEach(event => {
                window.removeEventListener(event, updateActivity);
            });
        };
    }, [config, isOpen, fireTrigger]);

    /**
     * Idle engagement trigger - fires when user is browsing but idle (no cart required).
     */
    useEffect(() => {
        const idleConfig = config?.triggers?.idleEngagement;
        if (!idleConfig?.enabled) return;

        // Check if should only trigger once per session.
        if (idleConfig.oncePerSession && wasTriggeredThisSession('idleEngagement')) return;
        if (idleEngagementTriggeredRef.current) return;
        if (!isPageAllowed(idleConfig.pages)) return;

        // Check if should only trigger when cart is empty.
        if (idleConfig.requireEmptyCart) {
            const cart = getCartData();
            if (cart.itemCount > 0) return;
        }

        // Track user activity for inactivity detection.
        const updateActivity = () => {
            lastActivityRef.current = Date.now();
        };

        // Listen for user activity.
        const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
        activityEvents.forEach(event => {
            window.addEventListener(event, updateActivity, { passive: true });
        });

        // Check for inactivity periodically.
        const inactivityDelay = idleConfig.delay || 45000;
        const checkInterval = setInterval(() => {
            if (idleEngagementTriggeredRef.current || isOpen) return;

            // Don't fire if abandoned cart trigger is also enabled and cart has items.
            const abandonedCartConfig = config?.triggers?.abandonedCart;
            if (abandonedCartConfig?.enabled && cartMeetsRequirements(abandonedCartConfig)) {
                // Let abandoned cart trigger handle this instead.
                return;
            }

            // Check if should only trigger when cart is empty (re-check in case cart changed).
            if (idleConfig.requireEmptyCart) {
                const cart = getCartData();
                if (cart.itemCount > 0) return;
            }

            const timeSinceActivity = Date.now() - lastActivityRef.current;
            if (timeSinceActivity >= inactivityDelay) {
                idleEngagementTriggeredRef.current = true;
                fireTrigger('idleEngagement', idleConfig.message);
            }
        }, 5000); // Check every 5 seconds.

        return () => {
            clearInterval(checkInterval);
            activityEvents.forEach(event => {
                window.removeEventListener(event, updateActivity);
            });
        };
    }, [config, isOpen, fireTrigger]);

    /**
     * Reset triggers (useful for testing).
     */
    const resetTriggers = useCallback(() => {
        setTriggered({
            time: false,
            exit: false,
            scroll: false,
            abandonedCart: false,
            idleEngagement: false,
        });
        timeTriggeredRef.current = false;
        exitTriggeredRef.current = false;
        scrollTriggeredRef.current = false;
        abandonedCartTriggeredRef.current = false;
        idleEngagementTriggeredRef.current = false;
        lastActivityRef.current = Date.now();
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // Ignore.
        }
    }, []);

    return {
        triggered,
        resetTriggers,
        // Expose for debugging.
        isTimeTriggerable: config?.triggers?.time?.enabled && !triggered.time && isPageAllowed(config?.triggers?.time?.pages),
        isExitTriggerable: config?.triggers?.exit?.enabled && !triggered.exit && isPageAllowed(config?.triggers?.exit?.pages),
        isScrollTriggerable: config?.triggers?.scroll?.enabled && !triggered.scroll && isPageAllowed(config?.triggers?.scroll?.pages),
        isAbandonedCartTriggerable: config?.triggers?.abandonedCart?.enabled && !triggered.abandonedCart && isPageAllowed(config?.triggers?.abandonedCart?.pages),
        isIdleEngagementTriggerable: config?.triggers?.idleEngagement?.enabled && !triggered.idleEngagement && isPageAllowed(config?.triggers?.idleEngagement?.pages),
    };
}

export default useProactiveTriggers;
