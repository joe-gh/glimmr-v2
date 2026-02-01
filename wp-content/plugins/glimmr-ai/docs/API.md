# API Reference

This document covers REST API endpoints, AJAX handlers, and streaming implementation.

---

## REST API Endpoints

**Namespace:** `glimmr-ai/v1`

### Chat Endpoints (Public)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/chat/message` | Send message, get AI response |
| GET | `/chat/history/{id}` | Retrieve conversation history |
| POST | `/chat/history` | Alternative POST for history |
| POST | `/chat/flag` | Flag message for review |
| POST | `/chat/consent` | GDPR consent tracking |

### Cart Endpoints (Public)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/cart/add` | Add item to cart |
| GET | `/cart/view` | View cart contents |
| POST | `/cart/update` | Update cart quantities |
| POST | `/cart/coupon` | Apply coupon code |

### Admin Endpoints (requires `manage_options`)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET/POST | `/admin/settings` | Get/update settings |
| GET | `/admin/analytics` | Retrieve analytics |
| GET | `/admin/conversations` | List conversations |
| POST | `/admin/knowledge/sync` | Trigger knowledge sync |

---

## Chat Message Endpoint

**POST** `/wp-json/glimmr-ai/v1/chat/message`

### Request

```json
{
    "message": "Show me blue dresses under $50",
    "conversation_id": "uuid-string-or-null",
    "context": {
        "page_url": "https://example.com/shop",
        "page_type": "shop"
    }
}
```

### Response (Non-Streaming)

```json
{
    "success": true,
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
    "response": {
        "content": "I found 5 blue dresses under $50...",
        "artifacts": [
            {
                "type": "product_cards",
                "products": [...]
            }
        ]
    },
    "tokens_used": 450
}
```

### Validation

- Message length: 4000 characters max (S12)
- Content moderation check (S13)
- Rate limiting applied

---

## Streaming Responses (SSE)

### Enabling Streaming

```json
{
    "message": "...",
    "stream": true
}
```

### SSE Event Types

| Event | Data | Purpose |
|-------|------|---------|
| `message` | Text chunk | Incremental response text |
| `tool_start` | Tool name | Tool execution beginning |
| `tool_result` | Tool output | Tool execution complete |
| `artifact` | Rich UI data | Product cards, etc. |
| `done` | Final stats | Stream complete |
| `error` | Error details | Stream error |

### Stream Format

```
event: message
data: {"content": "I found "}

event: message
data: {"content": "5 blue dresses"}

event: artifact
data: {"type": "product_cards", "products": [...]}

event: done
data: {"conversation_id": "...", "tokens_used": 450}
```

### Client Implementation

```javascript
const eventSource = new EventSource(
    `/wp-json/glimmr-ai/v1/chat/message?stream=true&message=${encodeURIComponent(message)}`
);

eventSource.addEventListener('message', (e) => {
    const data = JSON.parse(e.data);
    appendText(data.content);
});

eventSource.addEventListener('artifact', (e) => {
    const data = JSON.parse(e.data);
    renderArtifact(data);
});

eventSource.addEventListener('done', (e) => {
    eventSource.close();
});

eventSource.addEventListener('error', (e) => {
    console.error('Stream error:', e);
    eventSource.close();
});
```

---

## AJAX Handlers

All require `manage_options` capability and nonce verification.

**Nonce:** `glimmr_ai_admin_nonce`

### Settings Management

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_settings` | Retrieve all settings |
| `glimmr_ai_save_settings` | Save settings |

### Product Management

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_categories` | Get WooCommerce categories |
| `glimmr_ai_sync_products` | Trigger product sync |
| `glimmr_ai_reindex_products` | Rebuild product index |
| `glimmr_ai_get_product_sync_status` | Check sync status |
| `glimmr_ai_get_product_sync_progress` | Get sync progress |
| `glimmr_ai_sync_products_batch` | Batch sync products |
| `glimmr_ai_cancel_product_sync` | Cancel running sync |
| `glimmr_ai_clear_product_sync_errors` | Clear sync errors |
| `glimmr_ai_purge_products` | Remove all indexed products |

### Knowledge Management

| Action | Purpose |
|--------|---------|
| `glimmr_ai_sync_knowledge` | Trigger knowledge sync |
| `glimmr_ai_get_knowledge` | List knowledge items |
| `glimmr_ai_get_posts` | Get posts for knowledge |
| `glimmr_ai_toggle_knowledge` | Enable/disable knowledge item |
| `glimmr_ai_bulk_toggle_knowledge` | Bulk enable/disable |
| `glimmr_ai_sync_knowledge_item` | Sync single item |
| `glimmr_ai_add_custom_knowledge` | Create custom entry |
| `glimmr_ai_edit_custom_knowledge` | Edit custom entry |
| `glimmr_ai_delete_custom_knowledge` | Delete custom entry |

### Analytics & Conversations

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_conversations` | List conversations (paginated) |
| `glimmr_ai_get_analytics` | Get analytics by period |
| `glimmr_ai_export_conversations` | Export conversation data |
| `glimmr_ai_get_response_time_analytics` | Response time metrics |
| `glimmr_ai_purge_conversation_history` | Delete old conversations |

### Prompts & Tools

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_prompts_tools` | Get system prompt config |
| `glimmr_ai_save_prompts_tools` | Save prompt/tool config |

### Logs

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_logs` | Retrieve log entries |
| `glimmr_ai_download_logs` | Download log file |
| `glimmr_ai_clear_logs` | Clear all logs |

### System

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_health_status` | System health check |
| `glimmr_ai_purge_everything` | Delete all plugin data |
| `glimmr_ai_purge_vector_store_direct` | Clear OpenAI vector store |
| `glimmr_ai_sync_everything` | Full system sync |

### Network Admin (Multisite)

| Action | Purpose |
|--------|---------|
| `glimmr_ai_get_network_settings` | Get network settings |
| `glimmr_ai_save_network_settings` | Save network settings |
| `glimmr_ai_get_network_sites` | List network sites |

**Total: 33 AJAX handlers** (30 site + 3 network)

---

## AJAX Request Example

```javascript
jQuery.ajax({
    url: ajaxurl,
    method: 'POST',
    data: {
        action: 'glimmr_ai_get_settings',
        nonce: glimmrAiAdmin.nonce
    },
    success: function(response) {
        if (response.success) {
            console.log(response.data);
        }
    }
});
```

---

## Authentication

### Public Endpoints

- Session-based authentication
- Rate limiting by user/session/IP
- GDPR consent check

### Admin Endpoints

- Requires `manage_options` capability
- Nonce verification required
- Audit logging enabled

### REST Authentication

```php
// Permission callback
'permission_callback' => function() {
    return current_user_can('manage_options');
}
```

---

## Rate Limiting

### Headers

Rate limit info included in response headers:

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1706140800
```

### Error Response

```json
{
    "code": "rate_limited",
    "message": "Rate limit exceeded. Try again in 45 minutes.",
    "data": {
        "status": 429,
        "retry_after": 2700
    }
}
```

---

## Tool Schema Requirements

### OpenAI Function Definition

```json
{
    "type": "function",
    "function": {
        "name": "tool_name",
        "description": "Tool description",
        "parameters": {
            "type": "object",
            "properties": {
                "param1": {
                    "type": "string",
                    "description": "Parameter description"
                }
            },
            "required": ["param1"]
        }
    }
}
```

### Empty Parameters

Tools with no parameters must use `stdClass`:

```php
'parameters' => array(
    'type' => 'object',
    'properties' => new stdClass(), // Serializes to {} not []
)
```

### Object Type Parameters

Object-type parameters need `additionalProperties`:

```php
'attributes' => array(
    'type' => 'object',
    'description' => 'Key-value pairs',
    'additionalProperties' => array('type' => 'string')
)
```

---

## Error Responses

### Standard Error Format

```json
{
    "code": "error_code",
    "message": "Human-readable error message",
    "data": {
        "status": 400,
        "details": {}
    }
}
```

### Common Error Codes

| Code | Status | Description |
|------|--------|-------------|
| `invalid_message` | 400 | Message validation failed |
| `rate_limited` | 429 | Rate limit exceeded |
| `unauthorized` | 401 | Authentication required |
| `forbidden` | 403 | Permission denied |
| `not_found` | 404 | Resource not found |
| `openai_error` | 502 | OpenAI API error |
| `internal_error` | 500 | Server error |

---

## WordPress Hooks

### Actions

```php
// Before REST request processed
do_action('glimmr_ai_before_rest_request', $request);

// After REST response
do_action('glimmr_ai_after_rest_response', $request, $response);

// Tool execution
do_action('glimmr_ai_before_tool_execute', $tool_name, $args);
do_action('glimmr_ai_after_tool_execute', $tool_name, $args, $result);
```

### Filters

```php
// Modify rate limits
apply_filters('glimmr_ai_rate_limits', $limits, $user_id);

// Modify context
apply_filters('glimmr_ai_context', $context, $request);

// Modify response
apply_filters('glimmr_ai_response', $response, $conversation_id);
```

---

## Request/Response Examples

### Product Search

**Request:**
```json
{
    "message": "Show me running shoes",
    "conversation_id": null
}
```

**Response:**
```json
{
    "success": true,
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
    "response": {
        "content": "Here are some running shoes I found:",
        "artifacts": [
            {
                "type": "product_cards",
                "products": [
                    {
                        "id": 123,
                        "name": "Nike Air Zoom",
                        "price": "$129.99",
                        "image": "https://...",
                        "url": "https://..."
                    }
                ]
            }
        ]
    }
}
```

### Order Status

**Request:**
```json
{
    "message": "Where's my order #12345?",
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response (needs verification):**
```json
{
    "success": true,
    "response": {
        "content": "To look up your order, I'll need to verify some information. What's the email address on the order?"
    }
}
```

### Flag Message

**POST** `/wp-json/glimmr-ai/v1/chat/flag`

```json
{
    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
    "message_id": 42,
    "issue_type": "incorrect_info",
    "feedback": "The shipping time was wrong"
}
```
