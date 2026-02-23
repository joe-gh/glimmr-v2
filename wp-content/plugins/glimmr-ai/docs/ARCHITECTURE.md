# Architecture Reference

This document covers directory structure, database schema, and key design patterns.

---

## Directory Structure

```
glimmr-ai/
├── glimmr-ai.php                    # Main plugin file, constants, lifecycle hooks
├── uninstall.php                    # Complete data removal on uninstall
├── package.json                     # NPM dependencies for React admin
├── webpack.config.js                # Build configuration
│
├── admin/
│   ├── class-glimmr-ai-admin.php    # Admin UI, 30 AJAX handlers, menus
│   ├── class-glimmr-ai-network-admin.php  # Multisite network admin
│   ├── css/glimmr-ai-admin.css      # Admin styles (~2300 lines)
│   └── js/glimmr-ai-admin.js        # Admin JS (jQuery fallback)
│
├── public/
│   ├── class-glimmr-ai-public.php   # Frontend widget rendering
│   ├── css/glimmr-ai-widget.css     # Widget container (Shadow DOM)
│   └── js/glimmr-ai-widget.js       # Chat widget (vanilla JS fallback)
│
├── src/                             # React source files
│   ├── admin/                       # Admin dashboard components
│   └── widget/                      # Chat widget components
│
├── build/                           # Compiled assets (gitignored)
│
├── includes/
│   ├── class-glimmr-ai.php          # Main orchestrator (Singleton)
│   ├── class-glimmr-ai-activator.php      # Activation handler
│   ├── class-glimmr-ai-deactivator.php    # Deactivation handler
│   │
│   ├── class-glimmr-ai-rest-api.php       # REST endpoints
│   ├── class-glimmr-ai-database.php       # Database operations
│   ├── class-glimmr-ai-settings.php       # Settings with encryption
│   │
│   ├── class-glimmr-ai-openai.php         # OpenAI Responses API
│   ├── class-glimmr-ai-conversation.php   # Conversation management
│   ├── class-glimmr-ai-tool-registry.php  # Tool orchestration
│   ├── class-glimmr-ai-vector-store.php   # RAG vector store sync
│   ├── class-glimmr-ai-product-indexer.php # Product SQL index
│   │
│   ├── class-glimmr-ai-conversion-tracker.php # Purchase attribution
│   ├── class-glimmr-ai-analytics.php      # Event tracking
│   ├── class-glimmr-ai-rate-limiter.php   # Rate limiting
│   │
│   ├── class-glimmr-ai-logger.php         # Logging with PII masking
│   ├── class-glimmr-ai-http-client.php    # HTTP with retry logic
│   ├── class-glimmr-ai-context.php        # Context building
│   ├── class-glimmr-ai-cron.php           # Scheduled tasks
│   │
│   ├── class-glimmr-ai-pii-masker.php     # PII masking utility
│   ├── class-glimmr-ai-audit-log.php      # Admin access audit logging
│   │
│   └── tools/                             # AI function tools (26 total)
│       ├── class-tool-base.php            # Base class for all tools
│       ├── class-tool-query-products.php  # Main product search
│       ├── class-tool-select-products.php # Select from candidates
│       ├── class-tool-catalog-query.php   # Advanced catalog queries
│       ├── class-tool-sql-readonly.php    # Raw SQL queries
│       ├── class-tool-text-answer.php     # RAG knowledge search
│       ├── class-tool-site-knowledge.php  # Store policies/info
│       ├── class-tool-order-status.php    # Check order status
│       ├── class-tool-order-history.php   # Customer order history
│       ├── class-tool-account-info.php    # Customer account info
│       ├── class-tool-add-to-cart.php     # Add to cart
│       ├── class-tool-view-cart.php       # View cart
│       ├── class-tool-update-cart.php     # Update cart
│       ├── class-tool-apply-coupon.php    # Apply/remove coupons
│       ├── class-tool-checkout-link.php   # Generate checkout URLs
│       ├── class-tool-coupon-lookup.php   # Find coupons
│       ├── class-tool-recommendations.php # Product recommendations
│       ├── class-tool-navigate.php        # Page navigation
│       ├── class-tool-reorder.php         # Reorder past order
│       ├── class-tool-resolve-product.php # Name → Product ID
│       ├── class-tool-resolve-variation.php # Attributes → Variation ID
│       ├── class-tool-resolve-cart-item.php # Reference → Cart key
│       ├── class-tool-resolve-order.php   # Order number → Order ID
│       │   # v1.8.0 New Tools
│       ├── class-tool-check-gift-card-balance.php  # Gift card balance
│       ├── class-tool-track-package.php   # Package tracking
│       ├── class-tool-get-reviews.php     # Product reviews
│       ├── class-tool-summarize-reviews.php # Review Q&A
│       └── class-tool-contact-request.php # Support requests
│
├── logs/                            # Log files (protected)
│   ├── .htaccess                    # Apache deny all
│   ├── web.config                   # IIS deny all
│   ├── index.php                    # Direct access block
│   └── README.md                    # Nginx instructions
│
└── docs/                            # Documentation
    ├── TOOLS.md                     # Tool reference
    ├── SECURITY.md                  # Security architecture
    ├── API.md                       # API reference
    ├── ARCHITECTURE.md              # This file
    ├── DEVELOPMENT.md               # Developer guide
    └── CHANGELOG.md                 # Version history
```

---

## Database Schema

### Tables Created

**Prefix:** `{wp_prefix}glimmr_ai_`

| Table | Purpose | Added |
|-------|---------|-------|
| `conversations` | Chat session storage | v1.0.0 |
| `messages` | Individual chat messages | v1.0.0 |
| `flagged_issues` | Moderation queue | v1.0.0 |
| `analytics` | Event tracking | v1.0.0 |
| `knowledge` | Knowledge base items | v1.0.0 |
| `rate_limits` | Rate limiting state | v1.0.0 |
| `token_budgets` | Token budget tracking per user/site | v1.0.0 |
| `product_index` | Product search index | v1.0.0 |
| `product_variations` | Product variation data | v1.0.0 |
| `sync_log` | Sync operation history | v1.0.0 |
| `contact_requests` | Customer support requests | v1.6.0 |
| `contact_responses` | Admin responses to contact requests | v1.6.0 |

### conversations

```sql
CREATE TABLE {prefix}glimmr_ai_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL UNIQUE,
    site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    user_id BIGINT UNSIGNED DEFAULT NULL,
    session_id VARCHAR(64) NOT NULL,
    status ENUM('active', 'expired', 'archived') DEFAULT 'active',
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_activity DATETIME NOT NULL,
    metadata JSON,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_status_expires (status, expires_at),
    INDEX idx_site_id (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### messages

```sql
CREATE TABLE {prefix}glimmr_ai_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    role ENUM('user', 'assistant', 'system', 'tool') NOT NULL,
    content LONGTEXT,
    tool_calls JSON,
    tool_results JSON,
    tokens_used INT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (conversation_id)
        REFERENCES {prefix}glimmr_ai_conversations(conversation_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### flagged_issues

```sql
CREATE TABLE {prefix}glimmr_ai_flagged_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    message_id BIGINT UNSIGNED,
    issue_type VARCHAR(50) NOT NULL,
    user_feedback TEXT,
    status ENUM('pending', 'reviewed', 'resolved') DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    reviewed_at DATETIME,
    reviewed_by BIGINT UNSIGNED,
    INDEX idx_status (status),
    INDEX idx_issue_type (issue_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### analytics

```sql
CREATE TABLE {prefix}glimmr_ai_analytics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    event_type VARCHAR(50) NOT NULL,
    conversation_id VARCHAR(36),
    user_id BIGINT UNSIGNED,
    session_id VARCHAR(64),
    properties JSON,
    created_at DATETIME NOT NULL,
    INDEX idx_event_type (event_type),
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_created_at (created_at),
    INDEX idx_site_id (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### knowledge

```sql
CREATE TABLE {prefix}glimmr_ai_knowledge (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    type VARCHAR(50) NOT NULL,
    source_id VARCHAR(100),
    title VARCHAR(255),
    content LONGTEXT,
    vector_file_id VARCHAR(100),
    enabled TINYINT(1) DEFAULT 1,
    sync_status ENUM('pending', 'synced', 'error') DEFAULT 'pending',
    last_synced DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY idx_type_source (type, source_id, site_id),
    INDEX idx_sync_status (sync_status),
    INDEX idx_site_id (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### rate_limits

```sql
CREATE TABLE {prefix}glimmr_ai_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    identifier_type ENUM('user', 'session', 'ip') NOT NULL,
    request_count INT UNSIGNED DEFAULT 1,
    window_start DATETIME NOT NULL,
    UNIQUE KEY idx_identifier (identifier, identifier_type),
    INDEX idx_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### product_index

```sql
CREATE TABLE {prefix}glimmr_ai_product_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    short_description TEXT,
    sku VARCHAR(100),
    price DECIMAL(10,2),
    sale_price DECIMAL(10,2),
    stock_status VARCHAR(20),
    stock_quantity INT,
    categories TEXT,
    tags TEXT,
    attributes JSON,
    image_url VARCHAR(500),
    permalink VARCHAR(500),
    vector_file_id VARCHAR(100),
    last_synced DATETIME,
    UNIQUE KEY idx_product_site (product_id, site_id),
    INDEX idx_stock_status (stock_status),
    INDEX idx_price (price),
    FULLTEXT idx_search (name, description, short_description, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### sync_log

```sql
CREATE TABLE {prefix}glimmr_ai_sync_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    sync_type ENUM('products', 'knowledge', 'full') NOT NULL,
    status ENUM('running', 'completed', 'failed', 'cancelled') NOT NULL,
    items_total INT UNSIGNED DEFAULT 0,
    items_processed INT UNSIGNED DEFAULT 0,
    items_failed INT UNSIGNED DEFAULT 0,
    error_message TEXT,
    started_at DATETIME NOT NULL,
    completed_at DATETIME,
    INDEX idx_sync_type_status (sync_type, status),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### contact_requests (v1.6.0)

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
    resolved_at DATETIME NULL,
    UNIQUE KEY idx_request_id (request_id),
    KEY idx_site_id (site_id),
    KEY idx_status (status),
    KEY idx_category (category),
    KEY idx_user_id (user_id),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Database Version

- **Current:** `1.10.0`
- **Option:** `glimmr_ai_db_version`

---

## Key Design Patterns

### Singleton Pattern

Main orchestrator uses singleton:

```php
class Glimmr_AI {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }
}
```

### Tool Registry Pattern

Tools registered through central registry:

```php
class Glimmr_AI_Tool_Registry {
    private $tools = [];

    public function register(Glimmr_AI_Tool_Base $tool) {
        $this->tools[$tool->get_name()] = $tool;
    }

    public function execute($name, $args) {
        if (!isset($this->tools[$name])) {
            throw new Exception("Unknown tool: $name");
        }
        return $this->tools[$name]->execute($args);
    }
}
```

### Service Locator Pattern

Services accessed through main class:

```php
// Get services
$settings = Glimmr_AI::get_instance()->get_settings();
$database = Glimmr_AI::get_instance()->get_database();
$openai = Glimmr_AI::get_instance()->get_openai();
```

---

## Class Relationships

```
Glimmr_AI (Singleton)
├── Glimmr_AI_Settings
├── Glimmr_AI_Database
├── Glimmr_AI_REST_API
├── Glimmr_AI_Conversation
│   └── Glimmr_AI_OpenAI
│       └── Glimmr_AI_HTTP_Client
├── Glimmr_AI_Tool_Registry
│   └── Glimmr_AI_Tool_Base (26 implementations)
├── Glimmr_AI_Vector_Store
├── Glimmr_AI_Product_Indexer
├── Glimmr_AI_Rate_Limiter
├── Glimmr_AI_Analytics
├── Glimmr_AI_Conversion_Tracker
├── Glimmr_AI_Logger
├── Glimmr_AI_Context
├── Glimmr_AI_Cron
├── Glimmr_AI_PII_Masker (static)
└── Glimmr_AI_Audit_Log (static)
```

---

## Tool Execution Flow

```
1. User message received via REST API
   └── Rate limiting check
   └── Content moderation (S13)

2. Context building
   └── User context (logged in, cart, page)
   └── Conversation history (sliding window)

3. OpenAI Responses API call
   └── System prompt with context
   └── Tool definitions from registry
   └── Previous messages

4. Tool execution loop (max 5 rounds)
   └── Parse tool_calls from response
   └── Execute each tool via registry
   └── Format results
   └── Send back to OpenAI if more tools needed

5. Final response
   └── Extract text content
   └── Extract rich artifacts
   └── Store messages (S10 PII masking)
   └── Return to client
```

---

## Migration System

### Migration Files

Located in `includes/migrations/`:

```php
// Example: 1.1.0_add_site_id.php
return [
    'version' => '1.1.0',
    'description' => 'Add site_id column for multisite',
    'up' => function($wpdb) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}glimmr_ai_conversations
                      ADD COLUMN site_id BIGINT UNSIGNED NOT NULL DEFAULT 1");
    },
    'down' => function($wpdb) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}glimmr_ai_conversations
                      DROP COLUMN site_id");
    }
];
```

### Running Migrations

```php
// Check and run on activation
Glimmr_AI_Database::maybe_upgrade();

// Manual run via WP-CLI
wp eval "Glimmr_AI::get_instance()->get_database()->maybe_upgrade();"
```

---

## Cron Jobs

| Hook | Schedule | Handler |
|------|----------|---------|
| `glimmr_ai_product_sync` | Daily | `Glimmr_AI_Cron::trigger_product_sync()` |
| `glimmr_ai_knowledge_sync` | Daily | `Glimmr_AI_Cron::trigger_knowledge_sync()` |
| `glimmr_ai_cleanup` | Twice daily | `Glimmr_AI_Cron::cleanup()` |

### Cleanup Tasks

- Expire old conversations (30-day default)
- Clean stale rate limit records
- Purge orphaned messages

---

## OpenAI Integration

### Responses API

```php
$response = $this->openai->create_response([
    'model' => $this->settings->get('openai_model'),
    'messages' => $messages,
    'tools' => $this->tool_registry->get_definitions(),
    'max_tokens' => $this->settings->get('max_tokens_per_response'),
]);
```

### Vector Store

```php
// Create store
$store_id = $this->vector_store->create_store('Glimmr Products');

// Upload file
$file_id = $this->vector_store->upload_file($content, 'products.jsonl');

// Attach to store
$this->vector_store->attach_file($store_id, $file_id);
```

### Supported Models

| Category | Models |
|----------|--------|
| GPT-5 Series | gpt-5.2, gpt-5.1, gpt-5, gpt-5-mini, gpt-5-nano |
| GPT-4.1 Series | gpt-4.1, gpt-4.1-mini, gpt-4.1-nano |
| GPT-4o Series | gpt-4o, gpt-4o-mini |
| Reasoning | o4-mini, o3-mini |
| Legacy | gpt-4-turbo, gpt-4 |
