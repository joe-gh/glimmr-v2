/**
 * Tool Status Messages Utility
 *
 * Provides friendly status messages for AI tools during execution.
 * Used to show users what the assistant is doing while processing.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

/**
 * Mapping of tool names to user-friendly status messages.
 */
const toolStatusMessages = {
    // Product tools
    query_products: 'Searching products...',
    product_lookup: 'Searching products...',
    product_compare: 'Comparing products...',
    stock_check: 'Checking availability...',
    recommendations: 'Finding recommendations...',

    // Cart tools
    add_to_cart: 'Adding to cart...',
    view_cart: 'Loading your cart...',
    update_cart: 'Updating cart...',
    apply_coupon: 'Applying coupon...',
    checkout_link: 'Preparing checkout...',

    // Coupon tools
    coupon_lookup: 'Finding coupons...',

    // Order tools
    order_status: 'Checking order status...',
    order_history: 'Loading order history...',

    // Account tools
    account_info: 'Loading account info...',

    // Knowledge tools
    site_knowledge: 'Searching knowledge base...',
    text_answer: 'Thinking...',

    // Default fallback
    default: 'Processing...',
};

/**
 * Get a user-friendly status message for a tool.
 *
 * @param {string} toolName - The name of the tool being executed
 * @returns {string} - User-friendly status message
 */
export const getToolStatusMessage = (toolName) => {
    if (!toolName || typeof toolName !== 'string') {
        return toolStatusMessages.default;
    }

    return toolStatusMessages[toolName] || toolStatusMessages.default;
};

/**
 * Get all available tool status messages.
 * Useful for testing or debugging.
 *
 * @returns {Object} - All tool status message mappings
 */
export const getAllToolStatusMessages = () => {
    return { ...toolStatusMessages };
};

export default toolStatusMessages;
