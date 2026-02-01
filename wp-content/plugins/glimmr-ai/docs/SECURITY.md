# Security Architecture

This document covers all security implementations in Glimmr AI Shopping Assistant.

---

## Security Annotations Reference

The codebase uses security annotations (S1-S13) to mark critical protections.

### S1: Conversation Ownership

**Purpose:** Validates user owns conversation before access
**Locations:** `class-glimmr-ai-rest-api.php:391,494`, `class-tool-reorder.php`

```php
// S1: Validate conversation ownership.
if ($conversation['user_id'] !== get_current_user_id()) {
    return new WP_Error('forbidden', 'Access denied');
}
```

Prevents users from accessing other users' conversation history.

---

### S2: SQL Injection Prevention

**Purpose:** Prepared statements for all database queries
**Locations:** `class-glimmr-ai-rest-api.php:1280,1296`

```php
// S2: Always use prepared statements even when no filters.
$wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id);
```

All user input is parameterized through `$wpdb->prepare()`.

---

### S3: Frontend Settings Whitelist

**Purpose:** Only expose safe settings to frontend
**Location:** `class-glimmr-ai-public.php:96`

```php
// S3: Whitelist of safe settings to expose to frontend.
$safe_settings = ['widget_enabled', 'widget_position', 'widget_primary_color'];
```

Prevents API keys and sensitive configuration from leaking to JavaScript.

---

### S4: Post Type Validation

**Purpose:** Validate post types against registered types
**Location:** `class-glimmr-ai-admin.php:1203`

```php
// S4: Validate post_type against registered post types.
if (!post_type_exists($post_type)) {
    return new WP_Error('invalid_post_type', 'Invalid post type');
}
```

Prevents arbitrary post type injection in knowledge queries.

---

### S5: PII Masking in Logs

**Purpose:** Mask sensitive data before logging
**Locations:** `class-glimmr-ai-logger.php:231,586`

```php
// S5: Mask PII in message and context.
$masked = $this->mask_pii($message);
```

Emails, credit cards, IP addresses, and phone numbers are masked in all log entries.

---

### S6: Coupon Visibility Filtering

**Purpose:** Respect WooCommerce coupon visibility settings
**Location:** `class-glimmr-ai-rest-api.php:1054`

```php
// S6: Check coupon visibility settings before applying.
if (!$coupon->is_valid_for_cart()) {
    return $this->format_error('Coupon not valid');
}
```

Hidden or restricted coupons are not exposed through the AI.

---

### S7: Sensitive Payment Data Protection

**Purpose:** Only expose safe payment method info
**Location:** `class-tool-account-info.php:272`

```php
// S7: Only expose safe, non-sensitive payment info to AI.
$safe_info = [
    'type' => $method->get_type(),
    'last4' => substr($method->get_token(), -4),
    'expiry' => $method->get_expiry_month() . '/' . $method->get_expiry_year()
];
```

Full card numbers, CVV, and full tokens are never exposed.

---

### S8: Site Isolation (Multisite)

**Purpose:** Filter all queries by site_id in multisite
**Location:** `class-glimmr-ai-rest-api.php:1395`, `class-glimmr-ai-database.php`

```php
// S8: Check if request is from a trusted proxy before using forwarded headers.
$site_id = get_current_blog_id();
$wpdb->prepare("WHERE site_id = %d", $site_id);
```

In multisite installations, data is strictly isolated per site.

---

### S9: Server-Generated IDs

**Purpose:** Never accept client-supplied conversation IDs
**Locations:** `class-glimmr-ai-rest-api.php:377,817,856,876`

```php
// S9: Server-generated IDs only - never accept client-supplied conversation IDs.
$conversation_id = wp_generate_uuid4();
```

Prevents conversation ID manipulation attacks.

---

### S10: PII Masking (Storage)

**Purpose:** Mask sensitive data before database storage
**Locations:** `class-glimmr-ai-database.php:833`, `class-glimmr-ai-admin.php:1143`

```php
// S10: PII masking - mask sensitive data in user messages before storage.
$content = Glimmr_AI_PII_Masker::mask($content);
// Result: "j***@domain.com" instead of "john@domain.com"
```

**Tools with S10:** account_info, recommendations

---

### S11: Address Privacy

**Purpose:** Only expose city/state/country, never street address
**Locations:** `class-tool-order-status.php:451`, `class-tool-account-info.php:214,232`, `class-tool-site-knowledge.php:278`, `class-glimmr-ai-pii-masker.php:204`

```php
// S11: Only include city, state, country - no street addresses or postcode.
$safe_address = [
    'city' => $address['city'],
    'state' => $address['state'],
    'country' => $address['country']
];
```

**Tools with S11:** order_status, account_info, site_knowledge

Protects customer shipping/billing addresses from exposure through AI responses.

---

### S12: Consistent Errors

**Purpose:** Generic errors prevent enumeration attacks
**Locations:** `class-glimmr-ai-rest-api.php:35,503`, `class-tool-order-status.php:119,318,347,477`, `class-tool-reorder.php:95`

```php
// S12: Consistent error messages - same message whether order doesn't exist or verification fails.
return $this->format_error('Order not found or verification failed');
```

**Tools with S12:** order_status, reorder, text_answer (query length)

Prevents attackers from determining which orders exist via error differences.

---

### S13: Content Moderation

**Purpose:** Filter harmful/inappropriate content (v1.7.0)
**Locations:** `class-glimmr-ai-rest-api.php:324,1991`

```php
// S13: Content moderation check (v1.7.0).
if ($this->is_content_flagged($message)) {
    return $this->format_error('Message flagged for review');
}
```

Detects and blocks potentially harmful user messages.

---

## API Key Encryption

### Encryption Method

**Primary:** AES-256-CBC with WordPress salt

```php
$cipher = 'aes-256-cbc';
$key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY);
$iv = openssl_random_pseudo_bytes(16);
$encrypted = openssl_encrypt($api_key, $cipher, $key, 0, $iv);
```

**Fallback:** XOR obfuscation (environments without OpenSSL)

**Storage:** `openai_api_key_encrypted` option (not `openai_api_key`)

### Correct Retrieval Pattern

```php
// CORRECT - handles decryption
$api_key = Glimmr_AI_Settings::get_api_key();

// WRONG - returns empty (key is encrypted)
$api_key = $settings->get('openai_api_key');
```

---

## Rate Limiting

### Implementation

Atomic operations using `INSERT...ON DUPLICATE KEY UPDATE`:

```php
$wpdb->query($wpdb->prepare(
    "INSERT INTO {$table} (identifier, request_count, window_start)
     VALUES (%s, 1, %s)
     ON DUPLICATE KEY UPDATE
     request_count = IF(window_start < %s, 1, request_count + 1),
     window_start = IF(window_start < %s, %s, window_start)",
    $identifier, $now, $window_start, $window_start, $now
));
```

### Default Limits

| Limit Type | Value | Window |
|------------|-------|--------|
| Authenticated | 100 requests | 1 hour |
| Anonymous | 20 requests | 1 hour |
| Daily tokens | 100,000 | 24 hours |
| Monthly tokens | 2,000,000 | 30 days |
| Guest order lookup | 5 attempts | 15 minutes |

### Rate Limit Identifier Priority

1. User ID (logged in)
2. Session ID (with cookie)
3. IP address (fallback)

---

## Session Security

### Fingerprint Validation

```php
$fingerprint = hash('sha256', $ip . $user_agent . AUTH_SALT);
```

Sessions are bound to IP + User-Agent combination.

### Cookie Flags

- `HttpOnly` - Not accessible via JavaScript
- `SameSite=Lax` - CSRF protection
- `Secure` - HTTPS only (in production)

### Attribution Cookie (v1.8.0)

The `glimmr_ai_conversation` cookie tracks conversation attribution for purchases:

```php
setcookie(
    'glimmr_ai_conversation',
    $conversation_id,
    array(
        'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
        'path'     => COOKIEPATH,
        'domain'   => COOKIE_DOMAIN,
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    )
);
```

**Important:** Cookie must be set BEFORE any output (including SSE headers) for streaming responses.

### Timing-Safe Comparison

```php
// For email verification
if (!hash_equals($stored_hash, $provided_hash)) {
    return false;
}
```

Prevents timing attacks on verification.

---

## IP Detection (Proxy Support)

Priority order for trusted proxies:

```php
// 1. Sucuri WAF
$ip = $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ?? null;

// 2. CloudFlare
$ip = $ip ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;

// 3. Standard proxy
$ip = $ip ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

// 4. Direct connection
$ip = $ip ?? $_SERVER['REMOTE_ADDR'];
```

**Note:** Forwarded headers only trusted for configured proxy IPs.

---

## GDPR Compliance

### Consent Tracking

- Consent recorded with timestamp
- Audit trail maintained
- Revocation triggers data deletion (configurable)

### IP Anonymization

```php
$anonymized_ip = hash('sha256', $ip . AUTH_SALT);
```

### Data Deletion

```php
// On consent revocation
$this->delete_user_conversations($user_id);
$this->delete_user_analytics($user_id);
```

---

## Input Sanitization

### HTML Whitelist

Allowed tags:
- `<p>`, `<span>`, `<strong>`, `<em>`
- `<a>` (with href validation)
- `<ul>`, `<ol>`, `<li>`

### URL Validation

```php
// Blocked protocols
if (preg_match('/^(javascript|data):/i', $url)) {
    return false;
}
```

### Message Length

```php
// S12: Message length validation (4000 char limit)
if (strlen($message) > 4000) {
    return new WP_Error('message_too_long', 'Message exceeds limit');
}
```

---

## Guest Order Verification

### Requirements

Guests must provide BOTH:
1. Email address on order
2. Billing or shipping zip code

### Rate Limiting

5 failed attempts per 15 minutes blocks further attempts.

### Error Handling

```php
// S12: Same error whether order doesn't exist or verification fails
return $this->format_error('Order not found or verification failed');
```

---

## Admin Audit Logging

### Logged Actions

- Admin viewing conversation list
- Admin viewing analytics
- Admin accessing individual conversations

### Log Format

```php
[
    'action' => 'view_conversations',
    'admin_id' => get_current_user_id(),
    'timestamp' => current_time('mysql'),
    'ip' => $this->get_client_ip()
]
```

---

## File Security

### Log Directory Protection

**Apache (.htaccess):**
```apache
Deny from all
```

**IIS (web.config):**
```xml
<authorization>
    <deny users="*" />
</authorization>
```

**Direct Access (index.php):**
```php
<?php // Silence is golden
```

**Nginx (README):**
```nginx
location ~ ^/wp-content/plugins/glimmr-ai/logs/ {
    deny all;
}
```

---

## Security Checklist

- [x] API key encryption at rest (AES-256-CBC)
- [x] Rate limiting with atomic operations
- [x] Timing-safe email verification
- [x] Session fingerprint validation
- [x] PII masking in logs (S5)
- [x] PII masking in storage (S10)
- [x] SQL injection prevention (S2)
- [x] XSS prevention (HTML whitelist)
- [x] CSRF protection (nonces)
- [x] Proxy IP validation (S8)
- [x] Internal notes filtered from order status
- [x] Address privacy (S11)
- [x] Consistent error messages (S12)
- [x] Content moderation (S13)
- [x] Admin audit logging
- [x] Conversation ownership validation (S1)
- [x] Server-generated IDs (S9)
- [x] Frontend settings whitelist (S3)
- [x] Post type validation (S4)
- [x] Coupon visibility filtering (S6)
- [x] Payment data protection (S7)

---

## PII Masker Class

**File:** `class-glimmr-ai-pii-masker.php`

### Methods

```php
// Mask all PII types
Glimmr_AI_PII_Masker::mask($text);

// Individual masking
Glimmr_AI_PII_Masker::mask_email($email);    // j***@domain.com
Glimmr_AI_PII_Masker::mask_phone($phone);    // ***-***-1234
Glimmr_AI_PII_Masker::mask_card($card);      // ****-****-****-1234
Glimmr_AI_PII_Masker::mask_ip($ip);          // 192.168.***.***
```

### Usage

```php
// In message storage
$content = Glimmr_AI_PII_Masker::mask($user_message);

// In logging
$this->logger->info('Order lookup', [
    'email' => Glimmr_AI_PII_Masker::mask_email($email)
]);
```

---

## Audit Log Class

**File:** `class-glimmr-ai-audit-log.php`

### Methods

```php
// Log admin action
Glimmr_AI_Audit_Log::log($action, $details);

// Get audit history
Glimmr_AI_Audit_Log::get_logs($filters);
```

### Logged Events

| Event | Trigger |
|-------|---------|
| `view_conversations` | Admin opens conversation list |
| `view_analytics` | Admin opens analytics dashboard |
| `view_conversation` | Admin views specific conversation |
| `export_conversations` | Admin exports conversation data |
