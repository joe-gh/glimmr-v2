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
    product_lookup: 'Looking up product details...',
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
    coupon_lookup: 'Finding available coupons...',

    // Order tools
    order_status: 'Checking order status...',
    order_history: 'Loading order history...',
    reorder: 'Processing reorder...',

    // Account tools
    account_info: 'Loading account info...',

    // Knowledge tools
    site_knowledge: 'Searching knowledge base...',
    text_answer: 'Thinking...',

    // Review tools (v1.8.0)
    get_reviews: 'Loading reviews...',
    summarize_reviews: 'Analyzing reviews...',

    // Support tools (v1.8.0)
    contact_request: 'Submitting request...',
    check_gift_card_balance: 'Checking gift card...',
    track_package: 'Tracking package...',

    // Navigation tool
    navigate_to_page: 'Navigating...',

    // Resolver tools
    resolve_product: 'Finding product...',
    resolve_variation: 'Checking variations...',
    resolve_order: 'Locating order...',
    resolve_cart_item: 'Checking cart...',
    select_products: 'Selecting products...',

    // Query tools
    sql_readonly: 'Querying data...',
    catalog_query: 'Searching catalog...',

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
