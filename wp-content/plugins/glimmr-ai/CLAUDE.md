# CLAUDE.md - Glimmr AI Shopping Assistant

This file provides guidance to Claude Code when working with the Glimmr AI plugin.

## Plugin Overview

**Glimmr AI Shopping Assistant** is an AI-powered chat widget for WooCommerce that provides:
- Product recommendations and search
- Order tracking and history
- Cart management (add, update, view)
- Coupon lookup and application
- Site knowledge Q&A via vector store
- Customer account information

**Version:** 1.0.2
**Author:** Joseph DiGiovanna - joseph.p.digiovanna@gmail.com - Vimpact Consulting LLC
**Requires:** WordPress 6.0+, PHP 8.0+, WooCommerce 8.0+
**OpenAI Integration:** Uses the Responses API with tool calling
**Database Version:** 1.10.0
**Total Lines of Code:** ~30,000+ PHP, ~14,000+ JS/JSX

## Architecture

### OpenAI Integration
- Uses **OpenAI Responses API** (not Chat Completions or Assistants)
- Tool calling for structured actions (product lookup, cart operations, etc.)
- Vector store integration via `file_search` for site knowledge
- Conversation context management with sliding window

### Key Design Patterns
- **Singleton pattern** for main plugin class (`Glimmr_AI::get_instance()`)
- **Tool registry** for dynamic tool loading and execution
- **Settings encryption** for API keys (`Glimmr_AI_Settings::get_api_key()`)
- **Rate limiting** per user/IP with configurable windows
- **HTTP client** with retry logic and exponential backoff
- **PII masking** for stored messages and tool responses
- **Admin audit logging** for compliance tracking
- **Slot-filling agent** for multi-turn constraint gathering (v1.1.0)
- **Parameter validation** with type-safe schema enforcement (v1.1.0)
- **Content moderation** via OpenAI Moderation API (v1.7.0)

### Feature Maturity Assessment

| Component | Maturity | Notes |
|-----------|----------|-------|
| Core Plugin | 95% | Production ready |
| Database | 96% | 12 tables with indexes (v1.10.0) |
| REST API | 90% | Streaming attribution fixed (v1.8.0) |
| OpenAI Integration | 85% | Retry-After header not parsed |
| Rate Limiting | 90% | Atomic operations, token budgets |
| Analytics | 94% | Revenue attribution + conversion tracking fixes (v1.8.0) |
| Tools (26 total) | 95% | 5 new tools in v1.8.0 (reviews, support, gift cards, tracking) |
| Security | 95% | S10-S13 protections, content moderation (v1.7.0) |
| Widget Frontend | 96% | Proactive engagement triggers (v1.5.0) |
| Admin Frontend | 96% | Health monitoring, response time analytics, export (v1.6.0) |
| Streaming | 92% | Attribution cookie fixed before SSE headers (v1.8.0) |
| Vector Store | 80% | Batch size hardcoded at 50 |
| Conversion Tracking | 95% | HPOS compatible, streaming support (v1.8.0) |

## Directory Structure

```
glimmr-ai/
├── glimmr-ai.php              # Main plugin file, constants, activation hooks
├── uninstall.php              # Cleanup on uninstall
├── webpack.config.js          # Build configuration
├── admin/
│   ├── class-glimmr-ai-admin.php         # Admin pages, settings UI
│   └── class-glimmr-ai-network-admin.php # Network admin (multisite)
├── includes/
│   ├── class-glimmr-ai.php               # Main orchestrator class
│   ├── class-glimmr-ai-activator.php     # Activation, default settings
│   ├── class-glimmr-ai-deactivator.php   # Deactivation cleanup
│   ├── class-glimmr-ai-settings.php      # Settings management, encryption
│   ├── class-glimmr-ai-database.php      # Database tables, queries (with S8 site isolation)
│   ├── class-glimmr-ai-rest-api.php      # REST endpoints (with S9 server-side IDs)
│   ├── class-glimmr-ai-openai.php        # OpenAI Responses API client
│   ├── class-glimmr-ai-conversation.php  # Conversation/message management
│   ├── class-glimmr-ai-context.php       # Context building for AI
│   ├── class-glimmr-ai-http-client.php   # HTTP with retry logic
│   ├── class-glimmr-ai-moderation.php    # Content moderation (S13) (v1.7.0)
│   ├── class-glimmr-ai-rate-limiter.php  # Rate limiting
│   ├── class-glimmr-ai-pii-masker.php    # PII masking utility (S10)
│   ├── class-glimmr-ai-audit-log.php     # Admin access audit logging
│   ├── class-glimmr-ai-vector-store.php  # Vector store sync
│   ├── class-glimmr-ai-product-indexer.php  # Product indexing
│   ├── class-glimmr-ai-tool-registry.php    # Tool management
│   ├── class-glimmr-ai-analytics.php     # Event tracking
│   ├── class-glimmr-ai-conversion-tracker.php # Conversion tracking
│   ├── class-glimmr-ai-logger.php        # Logging system
│   ├── class-glimmr-ai-cron.php          # Scheduled tasks
│   ├── class-glimmr-ai-workspace.php     # Slot-filling state manager (v1.1.0)
│   ├── class-glimmr-ai-controller-schema.php # Agent controller schema (v1.1.0)
│   ├── class-glimmr-ai-parameter-validator.php # Parameter validation (v1.1.0)
│   ├── class-glimmr-ai-cli.php           # WP-CLI commands
│   ├── class-glimmr-ai-license.php       # License activation/validation
│   ├── class-glimmr-ai-continuity.php    # Entity focus tracking across turns
│   ├── class-glimmr-ai-entity-card.php   # Product/order entity data cards
│   ├── class-glimmr-ai-focus-frame.php   # Pronoun resolution focus tracking
│   ├── class-glimmr-ai-reference-validator.php # Entity reference validation
│   ├── class-glimmr-ai-contact-response.php # Admin reply to contact requests
│   ├── class-glimmr-ai-seo.php           # SEO integration
│   ├── class-glimmr-ai-tool-summarizer.php # Summarize tool results for context
│   └── tools/                            # AI tool implementations (26 tools)
│       ├── class-tool-base.php           # Abstract base class
│       ├── class-tool-account-info.php   # With S10 PII masking
│       ├── class-tool-add-to-cart.php
│       ├── class-tool-apply-coupon.php
│       ├── class-tool-check-gift-card-balance.php  # Multi-plugin gift card support
│       ├── class-tool-checkout-link.php
│       ├── class-tool-contact-request.php          # Support request storage
│       ├── class-tool-coupon-lookup.php
│       ├── class-tool-get-reviews.php              # Product review retrieval
│       ├── class-tool-navigate.php       # Page navigation tool
│       ├── class-tool-order-history.php
│       ├── class-tool-order-status.php   # With S11/S12 address masking
│       ├── class-tool-query-products.php # Unified search, compare, details, stock
│       ├── class-tool-recommendations.php
│       ├── class-tool-reorder.php        # Reorder from previous order
│       ├── class-tool-resolve-cart-item.php # Cart item resolution
│       ├── class-tool-resolve-order.php  # Order reference resolution
│       ├── class-tool-resolve-product.php # Product name to ID resolution
│       ├── class-tool-resolve-variation.php # Variation attribute resolution
│       ├── class-tool-select-products.php # Multi-product hydration
│       ├── class-tool-site-knowledge.php # With S11 configurable contact
│       ├── class-tool-sql-readonly.php   # Read-only SQL queries
│       ├── class-tool-summarize-reviews.php        # AI review Q&A
│       ├── class-tool-text-answer.php    # RAG-based text answers
│       ├── class-tool-track-package.php            # Carrier URL generation
│       ├── class-tool-update-cart.php
│       └── class-tool-view-cart.php
├── public/
│   ├── class-glimmr-ai-public.php   # Frontend, widget enqueue
│   └── js/                          # Compiled widget bundles
├── src/
│   ├── admin/
│   │   ├── index.js                 # Admin entry point
│   │   ├── styles/admin.scss        # Admin styles (including network settings)
│   │   └── components/
│   │       ├── Dashboard.jsx        # Admin dashboard with analytics
│   │       ├── GetStarted.jsx       # Setup wizard and onboarding
│   │       ├── Settings.jsx         # Settings page (with multisite inheritance)
│   │       ├── NetworkSettings.jsx  # Network admin settings (multisite)
│   │       ├── SettingInheritanceIndicator.jsx # Inheritance/lock indicators
│   │       ├── Conversations.jsx    # Conversation viewer with export
│   │       ├── ContactRequests.jsx  # Customer support request management
│   │       ├── KnowledgeManager.jsx # Knowledge base management
│   │       ├── PromptsTools.jsx     # System prompt & tools config
│   │       ├── SkeletonLoader.jsx   # Loading skeleton components
│   │       └── settings/            # Settings tab components (21 tabs)
│   └── widget/
│       ├── index.js                 # Widget entry point
│       ├── styles/widget.scss       # Widget styles (~4400 lines)
│       ├── hooks/
│       │   └── useProactiveTriggers.js  # Proactive engagement triggers (v1.5.0)
│       └── components/
│           ├── ChatWidget.jsx       # Main widget container (with streaming)
│           ├── ChatWindow.jsx       # Chat window
│           ├── ChatBubble.jsx       # Floating button
│           ├── MessageList.jsx      # Message display with artifact rendering
│           ├── MessageInput.jsx     # User input
│           ├── ProductCard.jsx      # Base product display
│           ├── CartPreview.jsx      # Enhanced cart display (~460 lines)
│           ├── QuickReplies.jsx     # Quick reply buttons
│           ├── TypingIndicator.jsx  # Loading indicator
│           │
│           │   # Rich UI Artifact Components
│           ├── ProductSearchGrid.jsx       # Product search results grid
│           ├── ProductDetailModal.jsx      # Full product overlay modal
│           ├── ProductComparisonTable.jsx  # Side-by-side comparison
│           ├── OrderStatusCard.jsx         # Order tracking with timeline
│           ├── OrderHistoryList.jsx        # Past orders list
│           ├── CouponCard.jsx              # Ticket-style coupon display
│           ├── RecommendationCarousel.jsx  # Product recommendations
│           ├── StockStatusBadge.jsx        # Stock availability display
│           ├── AccountSummaryCard.jsx      # Customer account info
│           ├── SiteKnowledgeResponse.jsx   # Knowledge base answers
│           ├── CheckoutCTA.jsx             # Checkout call-to-action
│           ├── CartActionResult.jsx        # Cart operation feedback
│           │
│           │   # Shared Components
│           ├── ImageGallery.jsx     # Product image gallery
│           └── VariationSelector.jsx # Product variation selector
│       └── utils/                   # Utility functions
│           ├── attributeLabels.js   # Attribute name translation
│           ├── cartActionHandler.js # Cart operation execution
│           ├── debug.js             # Debug logging utilities
│           ├── ga4.js               # Google Analytics 4 integration
│           ├── numbers.js           # Safe number operations
│           ├── storeApi.js          # WooCommerce Store API wrapper
│           ├── toolStatusMessages.js # Tool execution status messages
│           └── urlValidation.js     # URL safety validation
```

## Database Tables

All tables prefixed with `wp_glimmr_ai_`:

| Table | Purpose | Added |
|-------|---------|-------|
| `conversations` | Chat sessions with user/session tracking (site_id filtered) | v1.0.0 |
| `messages` | Individual messages with tool calls/results (PII masked) | v1.0.0 |
| `flagged_issues` | User-reported problems for review | v1.0.0 |
| `analytics` | Event tracking data (includes admin_audit events) | v1.0.0 |
| `knowledge` | Synced knowledge base entries | v1.0.0 |
| `rate_limits` | Rate limiting records | v1.0.0 |
| `token_budgets` | Token budget tracking per user/site | v1.0.0 |
| `product_index` | Indexed product data for search | v1.0.0 |
| `product_variations` | Product variation data for search | v1.0.0 |
| `sync_log` | Vector store sync history | v1.0.0 |
| `contact_requests` | Customer support request storage | v1.6.0 |
| `contact_responses` | Admin responses to contact requests | v1.6.0 |

### contact_requests Schema (v1.6.0)

```sql
CREATE TABLE {prefix}glimmr_ai_contact_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED DEFAULT 1,
    request_id VARCHAR(64) NOT NULL UNIQUE,
    conversation_id VARCHAR(64) NULL,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    subject VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'general',
    message LONGTEXT NOT NULL,
    conversation_context LONGTEXT NULL,
    order_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) DEFAULT 'new',
    priority VARCHAR(20) DEFAULT 'normal',
    assigned_to BIGINT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL
);
```

## REST API Endpoints

Base: `/wp-json/glimmr-ai/v1/`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/chat/start` | POST | Start new conversation |
| `/chat/message` | POST | Send message, get AI response (S9: server-generated IDs) |
| `/chat/stream` | POST | Send message with SSE streaming response |
| `/chat/history` | GET | Get conversation messages (S12: retention limited) |
| `/chat/flag` | POST | Flag message for review |
| `/consent` | POST | GDPR consent tracking |
| `/cart/add` | POST | Add product to cart |
| `/cart/view` | GET | View current cart |
| `/cart/update` | POST | Update cart quantities |
| `/cart/coupon` | POST | Apply coupon code |
| `/settings` | GET/POST | Admin settings (admin only) |
| `/conversations` | GET | List conversations (admin only, audit logged) |

## Available Tools

Tools are registered in `class-glimmr-ai-tool-registry.php` and can be enabled/disabled in settings. **26 tools total.**

### Core Tools

| Tool | Purpose | Security Notes |
|------|---------|----------------|
| `text_answer` | Direct text responses | Query length limit (S12) |
| `query_products` | Unified product search with modes (v1.1.0) | Replaces product_lookup, compare, stock_check |
| `catalog_query` | Type-safe query builder (v1.1.0) | SQL-like structured queries |
| `sql_readonly` | Read-only SQL escape hatch (v1.1.0) | Security-hardened, 2s timeout |
| `recommendations` | Get product recommendations | Category history hidden (S10) |
| `add_to_cart` | Add items to cart | Variation support |
| `view_cart` | View cart contents | - |
| `update_cart` | Modify cart quantities | - |
| `apply_coupon` | Apply discount codes | Format validation (v1.7.0) |
| `coupon_lookup` | Find available coupons | - |
| `order_status` | Track order by number | Email+Zip required (S12), address masked (S11) |
| `order_history` | View past orders | Login required |
| `reorder` | Reorder all items from previous order (v1.6.0) | Login required, validates ownership |
| `checkout_link` | Generate checkout URL | - |
| `account_info` | Get customer account details | PII masked (S10), address masked (S11) |
| `site_knowledge` | Query vector store for site info | Uses configurable support_email (S11) |
| `navigate_to_page` | Navigate user to internal pages | Internal URLs only, validates page access |

### Review Tools (v1.8.0)

| Tool | Purpose | Notes |
|------|---------|-------|
| `get_reviews` | Retrieve product reviews with ratings | Filter by rating, sort options |
| `summarize_reviews` | AI-powered review Q&A | Returns data for LLM synthesis |

### Support Tools (v1.8.0)

| Tool | Purpose | Notes |
|------|---------|-------|
| `contact_request` | Store customer contact requests | Email notification, reference numbers |
| `check_gift_card_balance` | Check gift card balance | Multi-plugin support (PW, YITH, WC, Smart Coupons) |
| `track_package` | Package tracking with carrier URLs | Auto-detects USPS, UPS, FedEx, DHL |

### Resolver Tools (v1.1.0)

These helper tools support the slot-filling agent architecture:

| Tool | Purpose |
|------|---------|
| `resolve_product` | Product lookup by ID or query |
| `resolve_variation` | Variation attribute resolution |
| `resolve_order` | Order reference resolution |
| `resolve_cart_item` | Cart item reference resolution |
| `select_products` | Multi-product selection |

### Legacy Tools (Deprecated)

| Tool | Replacement |
|------|-------------|
| `product_lookup` | `query_products` mode=search |
| `product_compare` | `query_products` mode=compare |
| `stock_check` | `query_products` mode=stock_check |

---

## Security Architecture

### Security Annotation Reference

The codebase uses security annotations (S-prefixed comments) to mark critical security implementations:

| Code | Name | Purpose |
|------|------|---------|
| **S1** | Conversation Ownership | Validates user owns conversation before access |
| **S8** | Site Isolation | Filters all queries by site_id in multisite |
| **S9** | Server-Generated IDs | Never accepts client-supplied conversation IDs |
| **S10** | PII Masking | Masks emails, phones, addresses before storage/exposure |
| **S11** | Address Privacy | Only exposes city/state/country, never street addresses |
| **S12** | Consistent Errors | Generic error messages prevent enumeration attacks |
| **S13** | Content Moderation | Filters harmful content via OpenAI Moderation API (v1.7.0) |

### PII Masking (`class-glimmr-ai-pii-masker.php`)

Centralized utility for masking Personally Identifiable Information:

```php
// Mask email: "john.doe@example.com" → "j***@example.com"
Glimmr_AI_PII_Masker::mask_email( $email );

// Mask phone: "(555) 123-4567" → "***-***-4567"
Glimmr_AI_PII_Masker::mask_phone( $phone );

// Mask street address: "123 Main Street" → "*** Main Street"
Glimmr_AI_PII_Masker::mask_street_address( $address );

// Mask credit card: "4111111111111111" → "****-****-****-1111"
Glimmr_AI_PII_Masker::mask_card_number( $card );

// Mask all PII in text (emails, phones, cards)
Glimmr_AI_PII_Masker::mask_text( $message );

// Get only city/state/country from address array
Glimmr_AI_PII_Masker::mask_address_components( $address_array );

// Check if text contains potential PII
Glimmr_AI_PII_Masker::contains_pii( $text );
```

**Integration Points:**
- `class-glimmr-ai-database.php:insert_message()` - Masks user messages before storage
- `class-tool-account-info.php` - Masks profile email/phone
- `class-tool-order-status.php` - Masks shipping addresses

### Admin Audit Logging (`class-glimmr-ai-audit-log.php`)

Tracks admin access to customer conversations for compliance:

```php
// Log admin viewing a conversation
Glimmr_AI_Audit_Log::log_conversation_view( $conversation_id );

// Log admin accessing conversation history
Glimmr_AI_Audit_Log::log_history_view( $conversation_id );

// Log admin accessing analytics
Glimmr_AI_Audit_Log::log_analytics_access( $period, $site_id );

// Log admin listing conversations
Glimmr_AI_Audit_Log::log_conversations_list( $filters );

// Get audit log for specific admin
Glimmr_AI_Audit_Log::get_admin_activity( $admin_id, $limit );

// Get recent audit entries
Glimmr_AI_Audit_Log::get_recent_activity( $limit );
```

**Logged Data:**
- `action` - Type of action performed
- `admin_id` / `admin_login` - Who performed the action
- `context` - Additional context (conversation_id, filters, etc.)
- `ip_hash` - Hashed client IP for security
- `user_agent` - Hashed user agent
- `timestamp` - When the action occurred
- `site_id` - Which site (multisite)

### Guest Order Verification

Order lookups for guests require both email AND zip code:

```php
// Required parameters for guest order access
$verify = array(
    'email' => 'customer@example.com',  // Required
    'zip'   => '12345',                  // Required (was optional, now required)
);
```

**Security Features:**
- Rate limited: 5 attempts per 15 minutes per IP
- Timing-safe comparison using `hash_equals()`
- Generic error message: "Order not found or verification failed"
- All verification attempts recorded (success and failure)

### Message Validation

Messages are validated before processing:

```php
// Settings
'max_message_length' => 4000,  // Maximum characters per message

// Validation in REST API
if ( strlen( $message ) > $max_length ) {
    return new WP_Error( 'message_too_long', '...' );
}
```

### Conversation History Retention

Old conversations automatically expire from history retrieval:

```php
// Settings
'conversation_history_retention_days' => 30,  // Default 30 days

// Expired conversations return empty with message
array(
    'messages' => array(),
    'expired'  => true,
    'message'  => 'This conversation history has expired.'
)
```

---

## Configuration

### API Key Storage
API keys are stored encrypted. Always use:
```php
// CORRECT - handles decryption
$api_key = Glimmr_AI_Settings::get_api_key();

// WRONG - returns empty (key is encrypted)
$api_key = $settings->get('openai_api_key');
```

### Key Settings
Settings are stored in `glimmr_ai_settings` option:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `openai_api_key_encrypted` | string | - | Encrypted OpenAI API key |
| `openai_vector_store_id` | string | - | Vector store ID for file_search |
| `openai_model` | string | gpt-4o | Model to use |
| `max_tokens_per_response` | int | 1000 | Token limit per response |
| `rate_limit_authenticated` | int | 100 | Messages/hour for logged-in users |
| `rate_limit_anonymous` | int | 20 | Messages/hour for guests |
| `fallback_response` | string | - | Message shown when AI fails |
| `support_email` | string | '' | Public contact email (S11) |
| `max_message_length` | int | 4000 | Maximum message length (S12) |
| `conversation_history_retention_days` | int | 30 | Days to retain history (S12) |
| `moderation_enabled` | bool | true | Filter messages via OpenAI Moderation API (S13) |
| `widget_*` | various | - | Widget appearance settings |
| `enabled_tools` | array | - | Array of enabled tool names |

### Widget Appearance (CSS Variables)
The widget uses CSS custom properties for theming:
- `--glimmr-primary` - Primary brand color
- `--glimmr-primary-hover` - Primary hover state
- `--glimmr-secondary` - Secondary color
- `--glimmr-bg` - Background color
- `--glimmr-bg-light` - Light background
- `--glimmr-border` - Border color
- `--glimmr-text` - Button text color
- `--glimmr-text-dark` - Body text color
- `--glimmr-text-muted` - Muted text color
- `--glimmr-success` - Success state color
- `--glimmr-error` - Error state color
- `--glimmr-radius` - Border radius

## Rich UI Artifacts

The widget displays tool results as rich, interactive UI components. Each artifact type maps to a specific tool and renders a specialized component.

### Artifact to Component Mapping

| Tool Result Type | Component | Description |
|------------------|-----------|-------------|
| `product_lookup` / `product_search` | `ProductSearchGrid` | Tile grid of products |
| `product_compare` / `product_comparison` | `ProductComparisonTable` | Side-by-side comparison overlay |
| `order_status` | `OrderStatusCard` | Order tracking with timeline |
| `order_history` | `OrderHistoryList` | List of past orders |
| `cart` / `view_cart` | `CartPreview` | Cart contents with quantities |
| `checkout` / `checkout_link` | `CheckoutCTA` | Checkout button with cart summary |
| `coupon` / `coupon_lookup` | `CouponCard` / `CouponList` | Ticket-style coupon display |
| `recommendations` | `RecommendationCarousel` | Horizontal product carousel |
| `stock_check` | `StockStatusBadge` / `StockCheckResult` | Stock availability indicator |
| `account_info` | `AccountSummaryCard` | Customer account summary (PII masked) |
| `site_knowledge` / `knowledge` | `SiteKnowledgeResponse` | Answer with sources |
| `navigating` | Navigation Indicator | Shows page navigation in progress |

### Artifact Data Flow

```
1. User asks question
2. AI calls tool (e.g., product_compare)
3. Tool returns structured data with type
4. REST API includes artifacts in response
5. ChatWidget adds message with artifacts
6. MessageList detects artifact types
7. ArtifactRenderer selects appropriate component
8. Component renders with CSS variables from config
```

### Shared Components

| Component | Used By | Purpose |
|-----------|---------|---------|
| `ImageGallery` | ProductDetailModal | Swipeable image gallery |
| `VariationSelector` | ProductDetailModal | Color/size/variant selection |
| `ProductDetailModal` | ProductSearchGrid, RecommendationCarousel | Full product overlay on click |

### Accessibility Features

All artifact components include:
- ARIA labels and roles (`role="dialog"`, `aria-modal`, `aria-labelledby`)
- Focus trapping in modals
- Keyboard navigation (Escape to close)
- Semantic HTML (tables, lists, buttons with labels)
- Screen reader support

## Streaming Responses

The widget supports real-time streaming of AI responses using Server-Sent Events (SSE).

### Streaming Architecture

```
Frontend (ChatWidget.jsx)
    │
    │ POST /chat/stream
    ▼
REST API (handle_chat_stream)
    │
    │ SSE headers + disable buffering
    ▼
Conversation Manager (process_message_streaming)
    │
    │ Callback for each chunk
    ▼
OpenAI Client (create_response_streaming)
    │
    │ stream: true
    ▼
OpenAI Responses API (SSE)
```

### SSE Event Types

| Event | Data | Description |
|-------|------|-------------|
| `init` | `{ conversation_id }` | New conversation created |
| `content` | `{ text }` | Text chunk to append |
| `error` | `{ message }` | Error occurred |
| `done` | `{ conversation_id }` | Stream complete |

### Enabling/Disabling Streaming

```php
// In settings
'streaming_enabled' => true  // default

// Fallback to regular POST if streaming disabled
$config['streamingEnabled'] = $settings->get('streaming_enabled', true);
```

### Frontend Streaming Handler

The `ChatWidget.jsx` component handles streaming:
1. Creates placeholder message with `isStreaming: true`
2. Uses `fetch()` with ReadableStream
3. Parses SSE lines and extracts data
4. Updates message content progressively
5. Marks `isStreaming: false` when complete

### Tool Calls During Streaming

When the AI decides to call tools during streaming:
1. Text content streams until tool call is detected
2. Callback receives `[Executing tools...]` notification
3. Tools execute synchronously on server
4. New streaming response starts for follow-up

## Tool Schema Requirements

When creating tools, follow OpenAI function schema requirements:

```php
// Parameters must be a valid JSON Schema object
protected $parameters = array(
    'product_id' => array(
        'type'        => 'integer',
        'description' => 'The WooCommerce product ID',
        'required'    => true,
    ),
);

// For object-type parameters, include additionalProperties
'variation' => array(
    'type'                 => 'object',
    'description'          => 'Variation attributes',
    'additionalProperties' => array( 'type' => 'string' ),
),

// Empty parameters MUST serialize to {} not []
// This is handled automatically in class-tool-base.php
```

---

## Test Queries

Use these queries to test all major functionality:

### Product Discovery
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Show me your jackets" | `product_lookup` | ProductSearchGrid with jacket products |
| "Compare your top 3 hoodies" | `product_compare` | ProductComparisonTable overlay |
| "Tell me more about the CloudSoft Premium Hoodie" | `product_lookup` | Single product detail or ProductDetailModal |
| "Is the Alpine Merino Crewneck in stock?" | `stock_check` | StockStatusBadge with availability |
| "What would you recommend for hiking?" | `recommendations` | RecommendationCarousel with outdoor products |

### Cart Operations
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "What's in my cart?" | `view_cart` | CartPreview with current items |
| "Add the TrailBlazer Hiking Pants to my cart" | `add_to_cart` | Success message + updated CartPreview |
| "I'm ready to checkout" | `checkout_link` | CheckoutCTA with cart summary |

### Coupons
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Do you have any discount codes?" | `coupon_lookup` | CouponCard/CouponList with available coupons |

### Order Tracking (Login Required or Email+Zip)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Show my past orders" | `order_history` | OrderHistoryList (requires login) |
| "Where is order #1234?" | `order_status` | OrderStatusCard (guest: needs email+zip) |

### Account Info (Login Required)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "What's my account info?" | `account_info` | AccountSummaryCard with masked PII (j***@domain.com) |

### Site Knowledge
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "What's your return policy?" | `site_knowledge` | SiteKnowledgeResponse with policy info |

### Navigation
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Take me to my order page" | `navigate_to_page` | Navigates to order details page |
| "Go to checkout" | `navigate_to_page` | Navigates to checkout page |
| "Open the return policy" | `navigate_to_page` | Navigates to return policy page |
| "Take me to the shop" | `navigate_to_page` | Navigates to shop page |

### General
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Hello, how are you?" | `text_answer` | Friendly greeting response |

### Reviews (v1.8.0)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "What do people say about this product?" | `get_reviews` | Review list with ratings |
| "Show me 5-star reviews" | `get_reviews` | Filtered to 5-star reviews only |
| "Is this true to size?" | `summarize_reviews` | LLM synthesizes answer from reviews |
| "What do customers think about quality?" | `summarize_reviews` | Aspect-focused review summary |

### Support & Contact (v1.8.0)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "I need to contact support" | `contact_request` | Collects name, email, subject, message |
| "I want to report a problem with my order" | `contact_request` | Creates request with order context |

### Gift Cards (v1.8.0)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "What's the balance on my gift card ABC123?" | `check_gift_card_balance` | Returns balance (requires plugin) |
| "Check gift card XYZ789" | `check_gift_card_balance` | Balance or "not found" |

### Package Tracking (v1.8.0)
| Query | Tool Triggered | Expected Behavior |
|-------|---------------|-------------------|
| "Where's my package for order #1234?" | `track_package` | Tracking URL and carrier info |
| "Track 1Z999AA10123456784" | `track_package` | UPS tracking link |

### Security Test Cases
| Test | Expected Behavior |
|------|-------------------|
| Guest order lookup without zip | Returns "needs_verification" requiring email AND zip |
| Guest order lookup with wrong email | Generic error "Order not found or verification failed" |
| Very long message (>4000 chars) | Rejected with "message_too_long" error |
| Access expired conversation history | Returns empty with "expired" flag |
| Account info response | Email/phone masked (j***@domain.com, ***-***-1234) |
| Order status response | Only city/state/country shown, no street address |

---

## Common Issues & Solutions

### Issue: AI returns generic fallback instead of real response
**Cause:** API key not being retrieved correctly
**Solution:** Use `Glimmr_AI_Settings::get_api_key()` not `$settings->get('openai_api_key')`
**Files:** `class-glimmr-ai-rest-api.php`, `class-glimmr-ai-openai.php`, `class-glimmr-ai-cron.php`

### Issue: OpenAI schema error "[] is not of type 'object'"
**Cause:** Empty parameters array serializes to `[]` instead of `{}`
**Solution:** Fixed in `class-tool-base.php` using `new stdClass()` for empty properties
**Related:** Tools with no parameters (like `view_cart`) need special handling

### Issue: Widget not appearing
**Check:**
1. Widget enabled in settings
2. Not on excluded page (checkout, cart by default)
3. JavaScript console for errors
4. API key configured

### Issue: Vector store not syncing
**Check:**
1. Vector store ID configured
2. API key valid
3. Cron jobs running (`wp cron event list`)
4. Check sync_log table for errors

### Issue: Cross-site data access in multisite
**Solution:** All database queries now include `site_id` filtering (S8)
**Files:** `class-glimmr-ai-database.php:get_conversation()`, `get_messages()`

### Issue: Guest can access orders without verification
**Solution:** Both email AND zip are now required (S12)
**File:** `class-tool-order-status.php:verify_guest_access()`

### Issue: Conversions not being tracked (FIXED in v1.8.0)
**Cause:** Streaming handler never called `set_attribution_conversation_id()` and cookies were set after SSE headers
**Solution:** Restructured `handle_chat_stream()` to create conversation and set attribution cookie BEFORE SSE headers
**Files:** `class-glimmr-ai-rest-api.php`, `class-glimmr-ai-analytics.php`, `class-glimmr-ai-conversion-tracker.php`

### Issue: HPOS incompatibility in conversion tracking (FIXED in v1.8.0)
**Cause:** `track_order_thankyou()` used `get_post_meta()` instead of `$order->get_meta()`
**Solution:** Updated to use HPOS-compatible order meta retrieval
**File:** `class-glimmr-ai-conversion-tracker.php`

---

## Building Assets

```bash
# Install dependencies
npm install

# Development build with watch
npm run dev

# Production build
npm run build
```

Outputs:
- `admin/js/glimmr-ai-admin-bundle.js`
- `public/js/glimmr-ai-widget-bundle.js`
- `public/js/glimmr-ai-widget-bundle.css`

## Deployment

This development environment is separate from the live testing site. After making changes, deploy to the live site using:

```bash
bash /Users/josephdigiovanna/Local\ Sites/glimmr/app/public/wp-content/plugins/deploy-glimmr-ai.sh
```

**Paths:**
- **Source (development):** `/Users/josephdigiovanna/Local Sites/glimmr/app/public/wp-content/plugins/glimmr-ai`
- **Destination (live testing):** `/Users/josephdigiovanna/Projects/arborwear/WP2/wp-content/plugins/glimmr-ai`

**Important:** Changes made in the development directory are NOT automatically reflected on the live site. Always run the deploy script after making changes to test them.

## Debugging

### Enable Debug Logging
Set `log_level` to `debug` in settings, then check:
- PHP error log
- `Glimmr_AI_Logger::debug()` calls
- Browser console for frontend issues

### Useful Queries
```sql
-- Recent conversations (filtered by site)
SELECT * FROM wp_glimmr_ai_conversations
WHERE site_id = 1 ORDER BY created_at DESC LIMIT 10;

-- Messages for a conversation
SELECT * FROM wp_glimmr_ai_messages
WHERE conversation_id = 'xxx' ORDER BY created_at;

-- Rate limit status
SELECT * FROM wp_glimmr_ai_rate_limits WHERE identifier = 'user_123';

-- Sync status
SELECT * FROM wp_glimmr_ai_sync_log ORDER BY synced_at DESC LIMIT 10;

-- Admin audit log
SELECT properties, created_at FROM wp_glimmr_ai_analytics
WHERE event_type = 'admin_audit' ORDER BY created_at DESC LIMIT 20;
```

---

## Features Ready to Enable (v1.1.0)

### 1. Slot-Filling Agent Architecture ✅ ALREADY ACTIVE

The slot-filling agent is the **primary processing path** - it's always active. The `process_message()` method in `class-glimmr-ai-conversation.php` IS the slot-filling agent.

**Files:**
- `class-glimmr-ai-workspace.php` - Server-side state manager
- `class-glimmr-ai-controller-schema.php` - JSON schema for structured outputs
- `class-glimmr-ai-context.php:get_slot_filling_system_prompt()` - Agent prompt
- `class-glimmr-ai-conversation.php:process_message()` - The agent loop

**Active Features:**
- Constraint tracking (category, price_range, size, color, etc.)
- Candidate product management
- Shortlist (max 5 products for focused conversations)
- Focused product persistence for contextual follow-ups ("Does it come in medium?")
- Tool call deduplication via fingerprints
- Loop prevention (max 5 rounds, 3 tools per turn)
- Workspace state injection into API context

**Note:** The settings `use_slot_filling_agent` and `should_use_slot_filling()` exist but are not wired up - the agent is always used. These may be for future A/B testing.

### 2. Auto-Sync Schedules

Automatic product and knowledge synchronization to vector stores.

**Settings to Enable:**
```php
'product_auto_sync'   => true,   // Auto-sync products daily (default: false)
'knowledge_auto_sync' => true,   // Auto-sync knowledge daily (default: false)
```

### 3. WP-CLI Commands

Debug and test commands available when WP-CLI is active.

**File:** `class-glimmr-ai-cli.php`

**Commands:**
```bash
wp glimmr-ai chat "Show me hoodies"    # Test a message
wp glimmr-ai classify "return policy"  # Test intent classification
wp glimmr-ai status                     # Check plugin status
```

---

## Missing High-Value Features

Based on competitor analysis (Tidio, LiveChat, Drift, Intercom), these features would add significant value:

### High Priority (Revenue Impact)

| Feature | Description | Status |
|---------|-------------|--------|
| **Revenue Attribution** | Dashboard showing order totals linked to conversations | ✅ Complete (v1.5.0) |
| **Proactive Engagement** | Time-on-page, scroll-depth, exit-intent triggers | ✅ Complete (v1.5.0) |
| **Exit-Intent Triggers** | Mouse-leave detection + mobile scroll-up fallback | ✅ Complete (v1.5.0) |
| **Abandoned Cart Recovery** | Automated follow-up for abandoned carts via chat | Planned |

### Medium Priority (Customer Experience)

| Feature | Description | Status |
|---------|-------------|--------|
| **One-Click Reorder** | Allow direct reorder from chat for returning customers | ✅ Complete (v1.6.0) |
| **Conversation Export** | CSV/JSON export for compliance and analysis | ✅ Complete (v1.6.0) |
| **Health Monitoring** | System health dashboard with error tracking | ✅ Complete (v1.6.0) |
| **Response Time Analytics** | AI response latency and token usage tracking | ✅ Complete (v1.6.0) |
| **Visual/Image Search** | Let customers upload photos to find similar products | Planned |
| **Human Agent Escalation** | Route complex issues to human agents seamlessly | Planned |
| **Product Reviews Integration** | Show reviews in product cards, answer review questions | Planned |

### Lower Priority (Multichannel)

| Feature | Description | Complexity |
|---------|-------------|------------|
| **WhatsApp Integration** | Reach customers on WhatsApp | High |
| **Email Integration** | Connect to customer emails for conversation history | Medium |
| **SMS Support** | Text-based conversations | High |
| **Facebook Messenger** | Seamless Messenger chat | High |

### Technical Improvements Needed

| Improvement | File | Status |
|-------------|------|--------|
| Parse Retry-After header in HTTP client | `class-glimmr-ai-http-client.php` | Pending |
| Add compound index on rate_limits table | `class-glimmr-ai-database.php` | ✅ Complete (v1.5.0) |
| Add event_type index on analytics table | `class-glimmr-ai-database.php` | ✅ Complete (v1.5.0) |
| Add conv+event index for revenue queries | `class-glimmr-ai-database.php` | ✅ Complete (v1.5.0) |
| Remove console.log from production builds | `src/widget/*.jsx` | Pending |
| Move hardcoded strings to i18n | `src/widget/components/*.jsx` | Pending |
| Add skeleton loaders for admin | `src/admin/components/*.jsx` | Pending |

---

## Recent Updates

### v1.8.0 New Tools & Conversion Tracking Fixes (January 2026)

#### New Tools (5)

| Tool | Purpose | File |
|------|---------|------|
| `check_gift_card_balance` | Check gift card balance via native plugin APIs | `class-tool-check-gift-card-balance.php` |
| `track_package` | Track packages with carrier URL generation | `class-tool-track-package.php` |
| `get_reviews` | Retrieve product reviews with ratings | `class-tool-get-reviews.php` |
| `summarize_reviews` | AI-powered review summarization and Q&A | `class-tool-summarize-reviews.php` |
| `contact_request` | Store customer contact requests with conversation context | `class-tool-contact-request.php` |

**Total tools: 26** (was 22)

#### Gift Card Balance (`check_gift_card_balance`)
- Supports 4 major gift card plugins via native APIs (not direct DB)
- PW WooCommerce Gift Cards, YITH, WooCommerce Gift Cards (Official), Smart Coupons
- Masks card numbers for security (shows last 4 chars)

#### Package Tracking (`track_package`)
- Auto-detects carrier from tracking number patterns
- Generates direct tracking URLs for USPS, UPS, FedEx, DHL
- Searches order meta for tracking numbers from various plugins

#### Product Reviews (`get_reviews`, `summarize_reviews`)
- `get_reviews`: Retrieves reviews with ratings, verified status, rating breakdown
- `summarize_reviews`: Provides review data for LLM to synthesize answers
- Supports filtering by rating (1-5 stars) and sorting options

#### Contact Support (`contact_request`)
- Stores requests in dedicated `contact_requests` database table
- Email validation and generates unique reference numbers (CR-XXXXXXXX)
- Email notification to admin with conversation context
- `glimmr_ai_contact_request_created` action hook for integrations

#### Conversion Tracking Fixes (Critical)

**Bug 1: Streaming handler attribution**
- Fixed: `handle_chat_stream()` now sets attribution cookie/session BEFORE SSE headers
- Previously, streaming mode (the default) never called `set_attribution_conversation_id()`

**Bug 2: Cookie timing**
- Fixed: Conversation creation and attribution cookie are now set BEFORE SSE headers
- Previously, `setcookie()` failed silently because headers were already sent

**Bug 3: HPOS compatibility**
- Fixed: `track_order_thankyou()` now uses `$order->get_meta()` instead of `get_post_meta()`

**Bug 4: SameSite cookie attribute**
- Added: Attribution cookie now includes `samesite: 'Lax'` for modern browser compatibility

**Modified Files:**
- `includes/class-glimmr-ai-rest-api.php` - Restructured streaming handler
- `includes/class-glimmr-ai-analytics.php` - Added SameSite cookie attribute
- `includes/class-glimmr-ai-conversion-tracker.php` - HPOS compatibility fix

#### Database Changes
- Added `contact_requests` table (DB version 1.6.0)

#### WP-CLI Commands
New command for adding test reviews:
```bash
wp glimmr-ai add-reviews <product_id> [--count=10]
```

---

### v1.7.0 Security & Moderation Update (January 2026)

#### OpenAI Content Moderation Integration (S13)
New content moderation feature that filters user messages through OpenAI's Moderation API before processing:
- Blocks hate speech, harassment, self-harm, sexual content, and violence
- Enabled by default (`moderation_enabled` setting)
- Fails open - if API errors occur, messages are allowed through
- Tracks moderation events via analytics (without storing flagged content)
- User-friendly rejection message: "I can't help with that request. Please ask about our products or services."

**New Files:**
- `includes/class-glimmr-ai-moderation.php` - Moderation API wrapper

**Modified Files:**
- `includes/class-glimmr-ai-rest-api.php` - Added moderation check in `handle_chat_message()` and `handle_chat_stream()`
- `includes/class-glimmr-ai-activator.php` - Added `moderation_enabled` default setting
- `src/admin/components/settings/chat/BehaviorTab.jsx` - Added moderation toggle in admin UI

#### SQL Query Timeout Enforcement
The `sql_readonly` tool now enforces query timeouts:
- Uses MySQL `max_execution_time` session variable
- Default timeout: 2 seconds
- Prevents long-running queries from blocking the server
- Timeout is reset after each query

**Modified Files:**
- `includes/tools/class-tool-sql-readonly.php` - Added timeout enforcement, updated documentation

#### Input Validation Improvements
Enhanced input validation across tools:

| Tool | Validation Added |
|------|-----------------|
| `text_answer` | Query length limit (max 2000 chars) |
| `apply_coupon` | Coupon code format validation (alphanumeric + `-_`) |
| `apply_coupon` | Coupon code length limit (max 100 chars) |

**New Settings:**
```php
'moderation_enabled' => true,  // Filter messages via OpenAI Moderation API
```

### v1.6.0 Admin Analytics & Reorder Update (January 2026)

#### One-Click Reorder Tool
New AI tool that allows customers to quickly reorder all items from a previous order:
- Validates order ownership (customer must own the order)
- Checks product availability and stock for each item
- Handles partial reorders (skips unavailable items with clear messaging)
- Supports `replace_cart` option to clear cart before adding
- Returns `cart_action` intent for frontend execution via WooCommerce Store API

**New Files:**
- `includes/tools/class-tool-reorder.php` - Reorder tool implementation
- Added reorder case to `src/widget/utils/cartActionHandler.js`
- Added `clearCart()` to `src/widget/utils/storeApi.js`

#### Conversation Export
Export conversations for compliance, analysis, and reporting:
- Supports CSV and JSON formats
- Period filtering (today, week, month, all time)
- Single conversation or bulk export
- Download via browser blob

**New AJAX Handler:** `ajax_export_conversations()`

#### Response Time Analytics
Track AI response latency and token usage:
- Average, minimum, maximum response times
- Total tokens used per period
- Daily breakdown chart
- Extracts data from `message_received` analytics events

**New Components:**
- `ResponseTimeStats` component in Dashboard.jsx
- `ajax_get_response_time_analytics()` AJAX handler

#### Health Monitoring Dashboard
System health monitoring with error tracking:
- Health status indicator (healthy/warning/critical)
- Check list: API configured, recent errors, success rate, token budget
- Error type breakdown (24h window)
- Recent error messages display
- Thresholds: >10 errors = critical, >3 = warning, <90% success = warning

**New Components:**
- `HealthStatusPanel` component in Dashboard.jsx
- `ajax_get_health_status()` AJAX handler

#### Export Modal
Enhanced export UI in Conversations page:
- Format selection (CSV/JSON)
- Period selection
- Modal-based workflow with download

**New Component:** `ExportModal` in Conversations.jsx

### v1.5.0 Revenue & Engagement Update (January 2026)

#### Revenue Attribution Dashboard
- Added revenue stat cards (Total Revenue, Conversion Rate, AOV, Orders)
- Added RevenueChart component with dual Y-axis (revenue line + orders bars)
- Added TopConversations component showing highest-converting conversations
- Enhanced AJAX handler to return conversion stats, daily revenue, top conversations

#### Proactive Engagement Triggers
- Time-on-page trigger with configurable delay (5-120 seconds)
- Exit-intent detection (mouse leave to top for desktop, rapid scroll-up for mobile)
- Scroll-depth trigger (25-90% configurable)
- Per-trigger page type targeting (product, category, shop, cart, checkout, home, other)
- Session storage to prevent re-triggering after user interaction
- Analytics tracking for trigger events

**New Settings:**
```php
'proactive_time_enabled'     => false,
'proactive_time_delay'       => 30,  // seconds
'proactive_time_message'     => 'Hi there! Need help finding anything?',
'proactive_time_pages'       => array( 'product', 'category', 'shop' ),
'proactive_exit_enabled'     => false,
'proactive_exit_message'     => 'Wait! Before you go, is there anything I can help you with?',
'proactive_exit_pages'       => array( 'cart', 'product' ),
'proactive_exit_once_per_session' => true,
'proactive_scroll_enabled'   => false,
'proactive_scroll_percent'   => 50,
'proactive_scroll_message'   => 'Enjoying what you see? Let me help you find the perfect item!',
'proactive_scroll_pages'     => array( 'product', 'category' ),
```

**New Files:**
- `src/widget/hooks/useProactiveTriggers.js` - Trigger detection hook
- `src/admin/components/settings/chat/EngagementTab.jsx` - Admin settings UI

#### Database Performance Indexes
Migration 1.5.0 adds three compound indexes:
- `idx_site_event_date` on analytics (site_id, event_type, created_at)
- `idx_conv_event` on analytics (conversation_id, event_type)
- `idx_window_cleanup` on rate_limits (window_start)

### Customer Data Protection Security Remediation (January 2026)

Comprehensive security fixes to prevent customer data leakage and cross-contamination:

#### Priority 1: Critical Fixes
| Fix | File | Annotation |
|-----|------|------------|
| Site ID filtering | `class-glimmr-ai-database.php` | S8 |
| Server-generated conversation IDs | `class-glimmr-ai-rest-api.php` | S9 |
| Zip required for guest orders | `class-tool-order-status.php` | S12 |
| Address masking in order status | `class-tool-order-status.php` | S11 |
| Consistent error messages | `class-tool-order-status.php` | S12 |

#### Priority 2: High Priority Fixes
| Fix | File | Annotation |
|-----|------|------------|
| Account info PII masking | `class-tool-account-info.php` | S10 |
| PII masker utility class | `class-glimmr-ai-pii-masker.php` (new) | S10/S11 |
| Message storage masking | `class-glimmr-ai-database.php` | S10 |
| Configurable support email | `class-tool-site-knowledge.php` | S11 |
| Admin audit logging | `class-glimmr-ai-audit-log.php` (new) | - |

#### Priority 3: Medium Priority Fixes
| Fix | File | Details |
|-----|------|---------|
| Message length validation | `class-glimmr-ai-rest-api.php` | 4000 char limit |
| WC customer notes only | `class-tool-order-status.php` | Removed keyword filtering |
| Conversation history retention | `class-glimmr-ai-rest-api.php` | 30-day default |

#### New Settings
```php
'support_email'                        => '',     // Public contact email
'max_message_length'                   => 4000,   // Message character limit
'conversation_history_retention_days'  => 30,     // History retention
```

### Database Fixes (January 2026)
1. **relevance_score ORDER BY Bug:** Fixed "Unknown column 'relevance_score' in ORDER clause" error when doing category-only searches (no text query).
2. **FULLTEXT Index Creation:** Fixed FULLTEXT index not being created on `product_index` table.

### Security Fixes (December 2025)
1. **SQL Injection Prevention in Product Search:** Fixed SQL injection vulnerability in ORDER BY clause using whitelist approach.

### Multisite Enhancements (December 2025)
- Site isolation via `site_id` column filtering
- Network admin global view for super admins
- Settings inheritance from network to sites
- Lockable settings that sites cannot override

### Rich UI Artifacts (December 2025)
Added 14 artifact components for displaying tool results as interactive UI.

### Streaming Responses (December 2025)
Implemented real-time streaming using Server-Sent Events.

---

## Dependencies

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- OpenAI API account with Responses API access
- Node.js (for building assets)

---

*Last Updated: January 2026 (v1.8.0 New Tools & Conversion Tracking Fixes)*
