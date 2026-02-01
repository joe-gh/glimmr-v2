# Development Guide

This document covers building, debugging, and testing Glimmr AI Shopping Assistant.

---

## Building Assets

### Prerequisites

- Node.js 18+
- npm 9+

### Install Dependencies

```bash
cd wp-content/plugins/glimmr-ai
npm install
```

### Build Commands

```bash
# Development build with watch
npm run dev

# Production build
npm run build

# Lint JavaScript
npm run lint

# Lint and fix
npm run lint:fix
```

### Build Output

```
build/
├── admin.js           # Admin dashboard bundle
├── admin.css          # Admin styles
├── widget.js          # Chat widget bundle
├── widget.css         # Widget styles
└── vendor.js          # Shared vendor code
```

---

## WP-CLI Commands

### Product Sync

```bash
# Trigger product sync to vector store
wp eval "Glimmr_AI::get_instance()->get_cron()->trigger_product_sync(true);"

# Force full reindex
wp eval "Glimmr_AI::get_instance()->get_product_indexer()->reindex_all();"
```

### Rate Limits

```bash
# Clear all rate limits
wp eval "Glimmr_AI::get_instance()->get_rate_limiter()->cleanup();"

# Check rate limit status
wp eval "print_r(Glimmr_AI::get_instance()->get_rate_limiter()->get_status());"
```

### Conversations

```bash
# Cleanup expired conversations
wp eval "Glimmr_AI::get_instance()->get_conversation()->cleanup_expired();"

# Get conversation count
wp eval "echo Glimmr_AI::get_instance()->get_database()->count_conversations();"
```

### Knowledge Sync

```bash
# Trigger knowledge sync
wp eval "Glimmr_AI::get_instance()->get_cron()->trigger_knowledge_sync(true);"
```

### Health Check

```bash
# Run health check
wp eval "print_r(Glimmr_AI::get_instance()->get_health_check()->run());"
```

### Testing Tools (v1.8.0)

```bash
# Add test reviews to a product
wp glimmr-ai add-reviews <product_id> [--count=10]

# Test a chat message
wp glimmr-ai chat "Show me hoodies"

# Test intent classification
wp glimmr-ai classify "return policy"

# Check plugin status
wp glimmr-ai status
```

---

## Debugging

### Enable Debug Mode

In `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('GLIMMR_AI_DEBUG', true);
```

### Log Locations

```
wp-content/plugins/glimmr-ai/logs/
├── debug.log          # General debug log
├── api.log            # OpenAI API calls
├── tools.log          # Tool execution
└── errors.log         # Error log
```

### Viewing Logs

```bash
# Recent entries
tail -f wp-content/plugins/glimmr-ai/logs/debug.log

# Filter by level
grep "ERROR" wp-content/plugins/glimmr-ai/logs/debug.log

# Filter by date
grep "2026-01-24" wp-content/plugins/glimmr-ai/logs/debug.log
```

### Log Levels

| Level | Constant | Use |
|-------|----------|-----|
| DEBUG | 0 | Detailed debugging |
| INFO | 1 | General information |
| WARNING | 2 | Potential issues |
| ERROR | 3 | Errors |
| CRITICAL | 4 | Fatal errors |

### Programmatic Logging

```php
$logger = Glimmr_AI::get_instance()->get_logger();

$logger->debug('Debug message', ['context' => 'value']);
$logger->info('Info message');
$logger->warning('Warning message');
$logger->error('Error message', ['exception' => $e]);
```

---

## Common Issues & Solutions

### Issue: AI returns generic fallback instead of real OpenAI response

**Symptom:** Instant responses that match keywords (e.g., "return" gives return policy)

**Cause:** API key not being retrieved correctly

**Solution:** Always use `Glimmr_AI_Settings::get_api_key()` which handles decryption

```php
// CORRECT - handles decryption
$api_key = Glimmr_AI_Settings::get_api_key();

// WRONG - returns empty (key is encrypted)
$api_key = $settings->get('openai_api_key');
```

---

### Issue: OpenAI schema error "[] is not of type 'object'"

**Cause:** Empty parameters array serializes to `[]` instead of `{}`

**Solution:** Use `new stdClass()` for empty properties

```php
'parameters' => array(
    'type' => 'object',
    'properties' => new stdClass(), // Serializes to {} not []
)
```

**Note:** Tools with object-type parameters need `additionalProperties`:

```php
'attributes' => array(
    'type' => 'object',
    'additionalProperties' => array('type' => 'string')
)
```

---

### Issue: Widget not appearing

**Check:**
1. Widget enabled in settings
2. WooCommerce active
3. No JavaScript errors in console
4. CSS not being blocked

**Debug:**
```javascript
// Check if widget initialized
console.log(window.glimmrAIWidget);

// Check config
console.log(window.glimmrAiConfig);
```

---

### Issue: Rate limiting not working

**Cause:** Database table missing or corrupted

**Solution:**
```bash
wp eval "Glimmr_AI::get_instance()->get_database()->create_tables();"
```

---

### Issue: Products not syncing

**Check:**
1. OpenAI API key valid
2. Vector store ID configured
3. Cron running

**Debug:**
```bash
# Check sync status
wp eval "print_r(Glimmr_AI::get_instance()->get_vector_store()->get_status());"

# Check last sync log
wp eval "print_r(Glimmr_AI::get_instance()->get_database()->get_last_sync_log('products'));"
```

---

### Issue: Conversation not persisting

**Check:**
1. Session cookie being set
2. Database table exists
3. User ID or session ID available

**Debug:**
```php
// Check session
error_log('Session ID: ' . session_id());
error_log('User ID: ' . get_current_user_id());
```

---

### Issue: Conversions not being tracked (FIXED in v1.8.0)

**Symptoms:** Purchases from chat interactions not appearing in analytics/dashboard

**Cause:** Multiple issues in conversion tracking pipeline:
1. Streaming handler never called `set_attribution_conversation_id()`
2. Attribution cookies set after SSE headers (silently failed)
3. HPOS incompatibility in `track_order_thankyou()`

**Solution:** Fixed in v1.8.0. Restructured `handle_chat_stream()` to create conversation and set attribution cookie BEFORE SSE headers.

**Files Fixed:**
- `class-glimmr-ai-rest-api.php` - Cookie set before SSE headers
- `class-glimmr-ai-analytics.php` - Added SameSite cookie attribute
- `class-glimmr-ai-conversion-tracker.php` - HPOS compatibility

**Debug:**
```bash
# Check attribution cookie in browser
document.cookie.includes('glimmr_ai_conversation')

# Check WC session
wp eval "print_r(WC()->session->get('glimmr_ai_conversation_id'));"

# Check order meta
wp eval "print_r(wc_get_order(123)->get_meta('_glimmr_ai_conversation_id'));"
```

---

## Testing

### Unit Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/test-tools.php

# Run specific test
./vendor/bin/phpunit --filter test_product_lookup
```

### Test Queries

Test these queries to verify tool functionality:

| Query | Expected Behavior |
|-------|-------------------|
| "Show me blue dresses under $50" | query_products executes |
| "What's your return policy?" | site_knowledge or text_answer |
| "Where's my order #12345?" | order_status (prompts for verification if guest) |
| "Add the large size to my cart" | resolve_variation → add_to_cart |
| "What coupons do you have?" | coupon_lookup |
| "Compare Product A and Product B" | resolve_product → query_products |
| "Take me to checkout" | checkout_link or navigate_to_page |
| "Reorder my last purchase" | order_history → reorder |
| "What do people say about this product?" | get_reviews |
| "Is this true to size?" | summarize_reviews |
| "Check my gift card balance ABC123" | check_gift_card_balance |
| "Track my package for order #1234" | track_package |
| "I need to contact support" | contact_request |

### Mock Data

Create test products:
```bash
wp wc product create --name="Test Product" --regular_price=29.99 --user=1
```

Create test order:
```bash
wp wc shop_order create --customer_id=1 --status=completed --user=1
```

---

## Performance Optimization

### Context Token Limits

```php
'max_context_tokens' => 8000,        // Total context budget
'context_reserve_tokens' => 1000,    // Reserve for response
'messages_before_sliding_window' => 10,
'minimum_recent_messages' => 4,
```

### Caching

- Product index cached in database
- Settings cached in transients
- Rate limits use atomic operations

### Database Indexes

Ensure indexes exist:
```sql
-- Check indexes
SHOW INDEX FROM wp_glimmr_ai_conversations;
SHOW INDEX FROM wp_glimmr_ai_messages;
SHOW INDEX FROM wp_glimmr_ai_product_index;
```

---

## Feature Roadmap

### Planned Features

| Feature | Priority | Status |
|---------|----------|--------|
| Multi-language support | High | Planned |
| Voice input | Medium | Planned |
| Image search | Medium | Planned |
| Proactive suggestions | Low | Planned |
| A/B testing | Low | Planned |

### Integration Wishlist

- Klaviyo integration
- Mailchimp integration
- Google Analytics 4
- Facebook Pixel

---

## Contributing

### Code Style

- Follow WordPress Coding Standards
- Use `phpcs` for PHP linting
- Use `eslint` for JavaScript linting

### Pull Request Process

1. Create feature branch
2. Write tests for new functionality
3. Run linters
4. Submit PR with description

### Documentation

Update relevant docs when changing:
- Tool parameters → `docs/TOOLS.md`
- Security → `docs/SECURITY.md`
- API → `docs/API.md`
- Architecture → `docs/ARCHITECTURE.md`

---

## Environment Variables

### Development

```php
define('GLIMMR_AI_DEBUG', true);
define('GLIMMR_AI_LOG_LEVEL', 0); // DEBUG
```

### Production

```php
define('GLIMMR_AI_DEBUG', false);
define('GLIMMR_AI_LOG_LEVEL', 2); // WARNING+
```

### Testing

```php
define('GLIMMR_AI_MOCK_OPENAI', true);
define('GLIMMR_AI_TEST_MODE', true);
```
