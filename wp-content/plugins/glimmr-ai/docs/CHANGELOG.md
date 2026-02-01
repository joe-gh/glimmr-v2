# Changelog

All notable changes to Glimmr AI Shopping Assistant.

---

## [1.8.0] - January 2026

### New Tools (5)

| Tool | Purpose | File |
|------|---------|------|
| `check_gift_card_balance` | Check gift card balance via native plugin APIs | `class-tool-check-gift-card-balance.php` |
| `track_package` | Track packages with carrier URL generation | `class-tool-track-package.php` |
| `get_reviews` | Retrieve product reviews with ratings | `class-tool-get-reviews.php` |
| `summarize_reviews` | AI-powered review summarization and Q&A | `class-tool-summarize-reviews.php` |
| `contact_request` | Store customer contact requests with conversation context | `class-tool-contact-request.php` |

**Total tools: 26** (was 22)

### Gift Card Balance (`check_gift_card_balance`)
- Supports 4 major gift card plugins via native APIs
- PW WooCommerce Gift Cards
- YITH WooCommerce Gift Cards
- WooCommerce Gift Cards (Official)
- Smart Coupons
- Masks card numbers for security (shows last 4 chars)
- Uses plugin APIs (not direct DB) to ensure hooks fire

### Package Tracking (`track_package`)
- Auto-detects carrier from tracking number patterns
- Generates direct tracking URLs for USPS, UPS, FedEx, DHL
- Searches order meta for tracking numbers from various plugins
- Supports WooCommerce Shipment Tracking, AfterShip, custom meta

### Product Reviews (`get_reviews`, `summarize_reviews`)
- `get_reviews`: Retrieves reviews with ratings, verified status, rating breakdown
- `summarize_reviews`: Provides review data for LLM to synthesize answers
- Supports filtering by rating (1-5 stars)
- Supports sorting (newest, oldest, highest/lowest rated)
- No nested LLM calls - returns data for main agent

### Contact Support (`contact_request`)
- Stores requests in dedicated database table
- Email validation and field validation
- Generates unique reference numbers (CR-XXXXXXXX)
- Email notification to admin
- Optional conversation context inclusion
- Category, priority, related order/product support
- `glimmr_ai_contact_request_created` action hook

### Database Changes

**Version 1.6.0:**
- Added `contact_requests` table for support requests

**contact_requests Schema:**
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

### Conversion Tracking Fixes

**Critical bug fixes:**

1. **Streaming handler attribution** - Fixed: `handle_chat_stream()` now sets attribution cookie/session. Previously, streaming mode (the default) never called `set_attribution_conversation_id()`, so purchases weren't attributed.

2. **Cookie timing** - Fixed: Conversation creation and attribution cookie are now set BEFORE SSE headers are sent. Previously, `setcookie()` failed silently because headers were already sent.

3. **HPOS compatibility** - Fixed: `track_order_thankyou()` now uses `$order->get_meta()` instead of `get_post_meta()` for HPOS compatibility.

4. **SameSite cookie attribute** - Added: Attribution cookie now includes `samesite: 'Lax'` for modern browser compatibility.

5. **Attribution refresh** - Added: Existing conversations refresh their attribution cookie on each message to ensure persistence through checkout.

### WP-CLI Commands

New command for adding test reviews:
```bash
wp glimmr-ai add-reviews <product_id> [--count=10]
```

---

## [1.7.0] - January 2026

### Security & Moderation Update

#### OpenAI Content Moderation Integration (S13)
- Filters user messages through OpenAI's Moderation API
- Blocks hate speech, harassment, self-harm, sexual content, violence
- Enabled by default (`moderation_enabled` setting)
- Fails open - allows messages if API errors
- Tracks moderation events in analytics
- User-friendly rejection message

**New Files:**
- `includes/class-glimmr-ai-moderation.php`

#### SQL Query Timeout Enforcement
- `sql_readonly` tool now enforces 2-second query timeout
- Uses MySQL `max_execution_time` session variable
- Prevents long-running queries from blocking server

#### Input Validation Improvements
| Tool | Validation Added |
|------|-----------------|
| `text_answer` | Query length limit (max 2000 chars) |
| `apply_coupon` | Coupon code format validation (alphanumeric + `-_`) |
| `apply_coupon` | Coupon code length limit (max 100 chars) |

---

## [1.6.0] - January 2026

### Admin Analytics & Reorder Update

#### One-Click Reorder Tool
- Validates order ownership
- Checks product availability and stock
- Handles partial reorders (skips unavailable items)
- Supports `replace_cart` option
- Returns `cart_action` intent for frontend execution

#### Conversation Export
- CSV and JSON format support
- Period filtering (today, week, month, all)
- Single conversation or bulk export

#### Response Time Analytics
- Average, min, max response times
- Total tokens per period
- Daily breakdown chart

#### Health Monitoring Dashboard
- Health status indicator (healthy/warning/critical)
- API configuration check
- Recent errors display
- Success rate tracking
- Error type breakdown

---

## [1.5.0] - January 2026

### Revenue & Engagement Update

#### Revenue Attribution Dashboard
- Revenue stat cards (Total Revenue, Conversion Rate, AOV, Orders)
- RevenueChart component with dual Y-axis
- TopConversations component showing highest-converting conversations

#### Proactive Engagement Triggers
- Time-on-page trigger (5-120 seconds configurable)
- Exit-intent detection (mouse leave + mobile scroll-up)
- Scroll-depth trigger (25-90% configurable)
- Per-trigger page type targeting
- Session storage to prevent re-triggering

**New Files:**
- `src/widget/hooks/useProactiveTriggers.js`
- `src/admin/components/settings/chat/EngagementTab.jsx`

#### Database Performance Indexes
- `idx_site_event_date` on analytics
- `idx_conv_event` on analytics
- `idx_window_cleanup` on rate_limits

---

## [1.0.0] - Initial Release

### Features

- **OpenAI Responses API Integration**
  - Full integration with OpenAI Responses API
  - Support for GPT-4o, GPT-4-turbo, and newer models
  - Streaming responses via Server-Sent Events (SSE)
  - Automatic retry with exponential backoff

- **AI Tools (Initial 22)**
  - Core: query_products, select_products, sql_readonly
  - Knowledge: text_answer, site_knowledge
  - Cart: add_to_cart, view_cart, update_cart, checkout_link
  - Coupons: coupon_lookup, apply_coupon
  - Orders: order_status, order_history, reorder
  - Account: account_info, recommendations
  - Navigation: navigate_to_page
  - Resolvers: resolve_product, resolve_variation, resolve_cart_item, resolve_order

- **Vector Store RAG**
  - Product catalog sync to OpenAI vector store
  - Knowledge base sync (pages, posts, policies)
  - Incremental sync support
  - Batch file uploads

- **Conversion Tracking**
  - Purchase attribution to conversations
  - Revenue tracking per conversation
  - Admin order UI integration
  - Analytics dashboard

- **Security**
  - API key encryption (AES-256-CBC)
  - Rate limiting with atomic operations
  - PII masking in logs and storage
  - Session fingerprint validation
  - GDPR compliance

- **Admin Dashboard**
  - React-based admin interface
  - Conversation viewer with history
  - Analytics with charts
  - Knowledge base manager
  - Settings configuration

- **Chat Widget**
  - React-based chat widget
  - Shadow DOM isolation
  - Responsive design
  - Customizable appearance

- **Multisite Support**
  - Per-site configuration
  - Network admin settings
  - Site isolation (S8)

---

## Security Remediation - January 2026

### Customer Data Protection

| Fix | File | Annotation |
|-----|------|------------|
| Site ID filtering | `class-glimmr-ai-database.php` | S8 |
| Server-generated conversation IDs | `class-glimmr-ai-rest-api.php` | S9 |
| Zip required for guest orders | `class-tool-order-status.php` | S12 |
| Address masking | `class-tool-order-status.php`, `class-tool-account-info.php` | S11 |
| PII masking utility | `class-glimmr-ai-pii-masker.php` (new) | S10 |
| Admin audit logging | `class-glimmr-ai-audit-log.php` (new) | - |
| Message length validation | `class-glimmr-ai-rest-api.php` | 4000 char limit |
| Conversation history retention | `class-glimmr-ai-rest-api.php` | 30-day default |

### Security Annotations Added

- **S10:** PII Masking (storage) - Masks emails, phones, addresses before storage
- **S11:** Address Privacy - Only exposes city/state/country, never street
- **S12:** Consistent Errors - Generic errors prevent enumeration attacks
- **S13:** Content Moderation - Filters harmful/inappropriate content

---

## December 2025 Fixes

### API Key Retrieval Bug

Fixed 3 files incorrectly calling `$settings->get('openai_api_key')` instead of `Glimmr_AI_Settings::get_api_key()`:

- `class-glimmr-ai-rest-api.php`
- `class-glimmr-ai-openai.php`
- `class-glimmr-ai-cron.php`

### Tool Schema Validation

- Fixed empty parameters serializing to `[]` instead of `{}`
- Added `additionalProperties` for object-type parameters
- All tools now pass OpenAI schema validation

### Widget Appearance Settings

Added configurable CSS variables:
- Primary, hover, success, error colors
- Background, border, text colors
- Button border width and radius

---

## Database Version History

| Version | Changes |
|---------|---------|
| 1.0.0 | Initial schema (8 tables) |
| 1.1.0 | Added `site_id` column to all tables |
| 1.5.0 | Added performance indexes |
| 1.6.0 | Added `contact_requests` table |

---

## Dependencies

| Requirement | Minimum | Tested Up To |
|-------------|---------|--------------|
| WordPress | 6.0 | 6.8.2 |
| WooCommerce | 8.0 | 9.0 |
| PHP | 8.0 | 8.3 |
| HPOS | Compatible | Yes |

---

## Upgrade Notes

### From 1.7.x to 1.8.0

1. Database will auto-migrate to add `contact_requests` table
2. New tools are automatically registered
3. Conversion tracking should now work correctly
4. Clear browser cookies to reset attribution (if testing)

### From Pre-1.0

1. Backup database before upgrading
2. Run database migrations: `Glimmr_AI_Database::maybe_upgrade()`
3. Re-enter API key (encryption changed)
4. Clear rate limits: `Glimmr_AI::get_instance()->get_rate_limiter()->cleanup()`

---

## Known Issues

### Current

- Vector store sync may timeout on large catalogs (>10,000 products)
- Widget may flash on initial load before styles applied

### Resolved in 1.8.0

- Conversion tracking not working with streaming (fixed)
- Attribution cookie not set before SSE headers (fixed)
- HPOS incompatibility in `track_order_thankyou()` (fixed)

---

## Future Plans

### Planned

- Multi-language support
- Voice input
- Image search / visual product finder
- WhatsApp integration

### Under Consideration

- A/B testing framework
- Klaviyo integration
- Mailchimp integration
- Facebook Messenger
