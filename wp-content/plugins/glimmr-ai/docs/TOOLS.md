# AI Tools Reference

This document covers all 26 AI tools available in Glimmr AI Shopping Assistant.

---

## Tool Categories

| Category | Tools | Purpose |
|----------|-------|---------|
| **Core** | query_products, select_products, sql_readonly | Product search and data retrieval |
| **Knowledge** | text_answer, site_knowledge | RAG and store information |
| **Cart** | add_to_cart, view_cart, update_cart, checkout_link | Shopping cart operations |
| **Coupons** | coupon_lookup, apply_coupon | Discount management |
| **Orders** | order_status, order_history, reorder, track_package | Order information and tracking |
| **Account** | account_info, check_gift_card_balance | Customer data and gift cards |
| **Reviews** | get_reviews, summarize_reviews | Product review access and AI analysis |
| **Support** | contact_request | Customer support contact form |
| **Navigation** | navigate_to_page | UI navigation |
| **Recommendations** | recommendations | Product recommendations |
| **Resolver** | resolve_product, resolve_variation, resolve_cart_item, resolve_order | Slot-filling helpers |

---

## Core Tools

### query_products

**File:** `class-tool-query-products.php`
**Login Required:** No

Search products with powerful filtering, compare products, get details, or check stock.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `mode` | string | Yes | "search", "compare", "details", or "stock" |
| `search` | object | For search mode | Search parameters |
| `compare` | object | For compare mode | Product IDs to compare |
| `details` | object | For details mode | Single product lookup |
| `stock` | object | For stock mode | Availability check |

**Search Object:**
| Field | Type | Description |
|-------|------|-------------|
| `query` | string | Search keywords |
| `category` | string | Category slug or ID |
| `min_price` | number | Minimum price |
| `max_price` | number | Maximum price |
| `in_stock_only` | boolean | Filter to in-stock items |
| `on_sale_only` | boolean | Filter to sale items |
| `sort` | string | "relevance", "price_low", "price_high", "newest", "rating" |
| `limit` | integer | Max results (default 5, max 10) |

---

### select_products

**File:** `class-tool-select-products.php`
**Login Required:** No

Retrieve full product details for products selected from search candidates.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_ids` | array | Yes | Array of product IDs to select |

---

### sql_readonly

**File:** `class-tool-sql-readonly.php`
**Login Required:** No

Execute read-only SQL SELECT queries against product and category tables.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | SQL SELECT statement |

**Restrictions:**
- Only SELECT statements allowed
- Limited to product and category tables
- Maximum 50 rows returned
- Query timeout enforced (2 seconds, v1.7.0)
- Blocked keywords: DROP, DELETE, INSERT, UPDATE, ALTER, CREATE, TRUNCATE, REPLACE, GRANT, REVOKE

---

## Knowledge Tools

### text_answer

**File:** `class-tool-text-answer.php`
**Login Required:** No

Search the knowledge base and product catalog via RAG to answer questions.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | The question to answer (max 2000 chars) |

**Security:** Query length validated (S12)

---

### site_knowledge

**File:** `class-tool-site-knowledge.php`
**Login Required:** No
**Security:** S11 (Address Privacy)

Get store information: shipping, returns, payment, contact, hours, policies.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `topic` | string | Yes | "shipping", "returns", "payment", "contact", "hours", "policies", "all" |

**Note:** Uses configurable support email instead of admin email (S11).

---

## Cart Tools

### add_to_cart

**File:** `class-tool-add-to-cart.php`
**Login Required:** No

Add a product to the shopping cart.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | Yes | Product ID to add |
| `quantity` | integer | No | Quantity (default 1) |
| `selection` | object | For variable products | Variation selection |

**Selection Object:**
| Field | Type | Description |
|-------|------|-------------|
| `variation_id` | integer | Specific variation ID |
| `attributes` | object | Key-value pairs (e.g., `{"size": "large"}`) |

---

### view_cart

**File:** `class-tool-view-cart.php`
**Login Required:** No

View cart contents including items, quantities, prices, totals, and applied coupons.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| (none) | - | - | No parameters required |

---

### update_cart

**File:** `class-tool-update-cart.php`
**Login Required:** No

Update quantity or remove an item from the cart.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `cart_item_key` | string | Yes | Cart item identifier |
| `op` | string | Yes | "remove", "set_qty", "increment", "decrement" |
| `quantity` | integer | For set_qty | New quantity |
| `delta` | integer | For increment/decrement | Amount to change |

---

### checkout_link

**File:** `class-tool-checkout-link.php`
**Login Required:** No

Generate checkout or cart page URLs, optionally adding items first.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | string | No | "checkout" or "cart" (default "checkout") |
| `add_items` | array | No | Products to add before redirect |

---

## Coupon Tools

### coupon_lookup

**File:** `class-tool-coupon-lookup.php`
**Login Required:** No

Find available discount coupons and promotions.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `code` | string | No | Specific coupon code to look up |
| `type` | string | No | Filter by type: "percent", "fixed_cart", "fixed_product" |

**Security:** Respects coupon visibility settings (S6)

---

### apply_coupon

**File:** `class-tool-apply-coupon.php`
**Login Required:** No

Apply or remove a coupon code from the cart.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `code` | string | Yes | Coupon code (alphanumeric, hyphens, underscores, max 100 chars) |
| `action` | string | No | "apply" (default) or "remove" |

**Security:** Coupon code format validated (v1.7.0)

---

## Order Tools

### order_status

**File:** `class-tool-order-status.php`
**Login Required:** Conditional
**Security:** S11 (Address Privacy), S12 (Consistent Errors)

Check order status by order number or ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_number` | string | Yes* | Order number to look up |
| `order_id` | integer | Yes* | Order ID to look up |
| `verify` | object | For guests | Verification credentials |

*One of `order_number` or `order_id` required.

**Verify Object (required for guests):**
| Field | Type | Description |
|-------|------|-------------|
| `email` | string | Email address on order |
| `zip` | string | Billing/shipping zip code |

**Access Rules:**
- Logged-in users: Can access their own orders without verification
- Guests: Must provide BOTH email AND zip code
- Rate limited: 5 attempts per 15 minutes for guest verification

**Address Privacy (S11):** Only city/state/country exposed, never street address.

---

### order_history

**File:** `class-tool-order-history.php`
**Login Required:** Yes

Get order history for the logged-in customer.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | integer | No | Max orders to return (default 10) |
| `status` | string | No | Filter by status |

---

### reorder

**File:** `class-tool-reorder.php`
**Login Required:** Yes
**Security:** S1 (Conversation Ownership), S12 (Consistent Errors)

Reorder all items from a previous order.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_id` | integer | Yes | Order ID to reorder |
| `replace_cart` | boolean | No | Clear cart before adding (default false) |

**Behavior:**
- Adds all purchasable, in-stock items to cart
- Skips unavailable items with notification
- Generic error prevents order enumeration (S12)

---

### track_package

**File:** `class-tool-track-package.php`
**Login Required:** Conditional (for order_id lookup)
**Added:** v1.8.0

Track package shipment with carrier URL generation.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_id` | integer | No* | Order ID to get tracking for |
| `tracking_number` | string | No* | Direct tracking number |
| `carrier` | string | No | Carrier hint (usps, ups, fedex, dhl) |

*At least one of `order_id` or `tracking_number` required.

**Supported Tracking Sources:**
- WooCommerce Shipment Tracking plugin
- AfterShip tracking
- Custom order meta keys containing "tracking"

**Carrier Detection:**
| Pattern | Carrier |
|---------|---------|
| `1Z...` | UPS |
| `94...` (20+ digits) | USPS |
| `7489...`, `6129...` (12+ digits) | FedEx |
| `JD...`, numeric (10 digits) | DHL |

**Returns:**
- Tracking number(s)
- Carrier name
- Direct tracking URL
- Order status

---

## Account Tools

### account_info

**File:** `class-tool-account-info.php`
**Login Required:** Yes
**Security:** S10 (PII Masking), S11 (Address Privacy), S7 (Payment Data Protection)

Get customer account information.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `include` | array | No | Sections: "addresses", "payment_methods", "stats" |

**Privacy Protections:**
- S11: Addresses show only city/state/country (no street, name, phone, postcode)
- S7: Payment methods show only safe, non-sensitive info (last 4 digits)
- S10: PII masked in stored messages

---

### check_gift_card_balance

**File:** `class-tool-check-gift-card-balance.php`
**Login Required:** No
**Added:** v1.8.0

Check gift card balance using native plugin APIs.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `card_number` | string | Yes | Gift card code/number |

**Supported Plugins:**
| Plugin | Detection | API Method |
|--------|-----------|------------|
| PW WooCommerce Gift Cards | `class_exists('PW_Gift_Card')` | `PW_Gift_Card::get_by_card_number()` |
| YITH WooCommerce Gift Cards | `function_exists('YITH_YWGC')` | `YITH_YWGC()->get_gift_card_by_code()` |
| WooCommerce Gift Cards (Official) | `class_exists('WC_GC_Gift_Cards')` | `WC_GC_Gift_Cards::get_by_code()` |
| Smart Coupons | `class_exists('WC_Smart_Coupons')` | WC Coupon API with `smart_coupon` type |

**Returns:**
- Balance (numeric and formatted)
- Currency
- Masked card number (shows last 4 characters)
- Expiration status (for Smart Coupons)

**Note:** Uses native plugin APIs (not direct DB queries) to ensure hooks fire properly.

---

## Review Tools

### get_reviews

**File:** `class-tool-get-reviews.php`
**Login Required:** No
**Added:** v1.8.0

Retrieve product reviews with ratings and verification status.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | Yes | Product ID to get reviews for |
| `limit` | integer | No | Max reviews (default 10, max 50) |
| `rating_filter` | integer | No | Filter by star rating (1-5) |
| `sort` | string | No | "newest", "oldest", "highest_rated", "lowest_rated" |

**Returns:**
- Reviews array with: author, rating, content, date, verified status
- Rating breakdown (count per star level)
- Average rating
- Total review count

---

### summarize_reviews

**File:** `class-tool-summarize-reviews.php`
**Login Required:** No
**Added:** v1.8.0

AI-powered review summarization and Q&A. Provides review data for the main LLM to synthesize.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | Yes | Product ID to analyze |
| `question` | string | No | Specific question to answer from reviews |
| `aspect` | string | No | "quality", "sizing", "durability", "value", "shipping", "overall" |

**Returns:**
- Sample of reviews (up to 30) formatted for LLM analysis
- Rating statistics
- Instruction for LLM to synthesize answer

**Note:** Does not make nested LLM calls - returns data for the main agent to process.

---

## Support Tools

### contact_request

**File:** `class-tool-contact-request.php`
**Login Required:** No
**Added:** v1.8.0

Store customer contact requests with conversation context.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | Customer name |
| `email` | string | Yes | Customer email (validated) |
| `phone` | string | No | Phone number |
| `subject` | string | Yes | Request subject |
| `category` | string | No | "general", "order_issue", "product_question", "return_exchange", "shipping", "billing", "feedback", "other" |
| `message` | string | Yes | Request details |
| `order_id` | integer | No | Related order ID |
| `product_id` | integer | No | Related product ID |
| `priority` | string | No | "low", "normal", "high", "urgent" |
| `include_conversation` | boolean | No | Include recent chat context |

**Behavior:**
- Validates required fields (returns `incomplete` with `missing_fields` if not provided)
- Validates email format
- Generates unique reference number (CR-XXXXXXXX)
- Stores in `contact_requests` database table
- Sends email notification to admin
- Fires `glimmr_ai_contact_request_created` action

**For Logged-In Users:**
The AI can pre-fill name/email from account info and ask for confirmation.

**Returns:**
- Reference number
- Confirmation message
- Status

---

## Navigation Tools

### navigate_to_page

**File:** `class-tool-navigate.php`
**Login Required:** No

Navigate user to a page on the site.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | No | Specific URL from conversation |
| `page_type` | string | No | "home", "shop", "cart", "checkout", "account", "category", "product" |
| `identifier` | string | For category/product | Slug or ID |

**Restriction:** Only internal site pages allowed.

---

## Recommendation Tools

### recommendations

**File:** `class-tool-recommendations.php`
**Login Required:** No
**Security:** S10 (PII Masking)

Get personalized product recommendations.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `source` | string | No | "cart", "history", "browsing", "popular" |
| `product_id` | integer | No | Base product for related items |
| `limit` | integer | No | Max recommendations (default 5) |

---

## Resolver Tools

Resolver tools are slot-filling helpers that convert user references (names, partial names) into specific IDs needed by action tools.

### resolve_product

**File:** `class-tool-resolve-product.php`
**Login Required:** No

Resolve product name to specific product IDs with confidence scores.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | Product name or search phrase |
| `limit` | integer | No | Max candidates (default 5) |

**Returns:** Array of `{product_id, name, confidence}` objects.

---

### resolve_variation

**File:** `class-tool-resolve-variation.php`
**Login Required:** No

Resolve variation attributes to a specific variation ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | Yes | Parent variable product |
| `attributes` | object | Yes | Key-value attribute pairs |

**Example:** `{"product_id": 123, "attributes": {"size": "large", "color": "blue"}}`

---

### resolve_cart_item

**File:** `class-tool-resolve-cart-item.php`
**Login Required:** No

Resolve product/variation reference to cart item key(s).

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | No | Product ID in cart |
| `variation_id` | integer | No | Variation ID in cart |
| `name` | string | No | Product name to search |

**Returns:** Array of matching cart item keys.

---

### resolve_order

**File:** `class-tool-resolve-order.php`
**Login Required:** No

Resolve order number to order ID and check verification requirements.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_number` | string | Yes | Order number to resolve |

**Returns:** `{order_id, requires_verification, verification_type}`

---

## Tool Base Class

**File:** `class-tool-base.php`

All tools extend this base class which provides:

### Response Formatting
- `format_result($data)` - Standardized success response
- `format_error($message, $code)` - Standardized error response
- `format_outcome($status, $data, $message)` - Flexible outcome response
- `format_not_found($entity_type, $identifier, $hints)` - Entity not found response

### Validation Helpers
- `require_login()` - Validates user is logged in
- `require_wc()` - Validates WooCommerce is active

### Input Sanitization
- `get_string_arg($args, $key, $default)` - String parameter
- `get_int_arg($args, $key, $default)` - Integer parameter
- `get_bool_arg($args, $key, $default)` - Boolean parameter
- `get_array_arg($args, $key, $default)` - Array parameter

### Data Formatting
- `format_product($product)` - Standardized product output
- `format_order($order)` - Standardized order output
- `format_price($price)` - Formatted price string

### Schema Handling
Empty parameters use `new stdClass()` to serialize as `{}` instead of `[]` (OpenAI requirement).

---

## Tool Registration

Tools are registered via the `glimmr_ai_register_tools` action:

```php
add_action('glimmr_ai_register_tools', function($registry) {
    $registry->register(new My_Custom_Tool());
});
```

### Tool Execution Hooks

```php
// Before tool execution
do_action('glimmr_ai_before_tool_execute', $tool_name, $args);

// After tool execution
do_action('glimmr_ai_after_tool_execute', $tool_name, $args, $result);
```

---

## Test Queries

Test these queries to verify tool functionality:

| Query | Expected Tools |
|-------|---------------|
| "Show me blue dresses under $50" | query_products |
| "What's your return policy?" | site_knowledge or text_answer |
| "Where's my order #12345?" | order_status (+ resolve_order) |
| "Add the large size to my cart" | resolve_variation → add_to_cart |
| "What coupons do you have?" | coupon_lookup |
| "Compare Product A and Product B" | resolve_product → query_products (compare) |
| "Take me to checkout" | checkout_link or navigate_to_page |
| "Reorder my last purchase" | order_history → reorder |
| "Check my gift card balance" | check_gift_card_balance |
| "Track my package" | track_package |
| "What do people say about this product?" | get_reviews or summarize_reviews |
| "Is this true to size?" | summarize_reviews (with aspect=sizing) |
| "I need to contact support" | contact_request |
