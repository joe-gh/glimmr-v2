# Glimmr AI Shopping Assistant

AI-powered shopping assistant for WooCommerce with OpenAI integration, product recommendations, order tracking, and intelligent customer support.

## Description

Glimmr AI Shopping Assistant is a comprehensive AI chat widget that transforms how customers interact with your WooCommerce store. Powered by OpenAI's latest models, it provides intelligent product recommendations, real-time order tracking, cart management, and instant answers to customer questions.

### Key Features

- **AI-Powered Conversations** - Natural language understanding with OpenAI's GPT models
- **Product Discovery** - Intelligent product search, recommendations, and comparisons
- **Order Management** - Real-time order tracking and order history lookup
- **Cart Operations** - Add to cart, view cart, update quantities, apply coupons
- **Knowledge Base** - RAG-powered answers from your site content via vector store
- **Rich UI Artifacts** - Beautiful product cards, order timelines, comparison tables
- **Streaming Responses** - Real-time typing effect for natural conversation flow
- **Multisite Support** - Network-level settings with site inheritance and locking
- **GDPR Compliant** - Consent tracking, data export, and erasure support

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- WooCommerce 8.0 or higher
- OpenAI API account with Responses API access

## Installation

1. Upload the `glimmr-ai` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Glimmr AI** in the admin menu
4. Enter your OpenAI API key in the Settings tab
5. Configure widget appearance and behavior to match your brand

## Configuration

### API Settings

- **OpenAI API Key** - Your secret API key from OpenAI
- **Model** - Choose from GPT-4o, GPT-4.1, GPT-5 series, or reasoning models
- **Max Tokens** - Control response length (100-4000 tokens)
- **Vector Store ID** - Optional, auto-created if not provided

### Rate Limiting

- **Authenticated Users** - Requests per hour for logged-in customers
- **Anonymous Users** - Requests per hour for guests
- **Token Budgets** - Daily and monthly limits to control costs

### Widget Appearance

- Position (bottom-right or bottom-left)
- Custom colors (primary, secondary, success, error, etc.)
- Assistant name and avatar
- Greeting message and quick reply buttons
- Custom CSS variables for complete control

### AI Tools

The assistant has access to 15 specialized tools:

| Tool | Purpose |
|------|---------|
| `product_lookup` | Search and filter products |
| `product_compare` | Side-by-side product comparison |
| `recommendations` | Personalized product suggestions |
| `add_to_cart` | Add items to cart |
| `view_cart` | Display cart contents |
| `update_cart` | Modify quantities |
| `apply_coupon` | Apply discount codes |
| `coupon_lookup` | Find available coupons |
| `order_status` | Track order by number |
| `order_history` | View past orders |
| `checkout_link` | Generate checkout URL |
| `stock_check` | Check product availability |
| `account_info` | Customer account details |
| `site_knowledge` | Query knowledge base |
| `text_answer` | Direct text responses |

## Multisite Support

For WordPress Multisite installations:

- **Network Settings** - Configure defaults at `/wp-admin/network/`
- **Setting Inheritance** - Sites inherit network defaults automatically
- **Lockable Settings** - Prevent sites from overriding critical settings
- **Site Isolation** - Conversations and analytics are isolated per site
- **Global View** - Super admins can view data across all sites

## REST API

The plugin provides REST endpoints under the `glimmr-ai/v1` namespace:

### Public Endpoints

- `POST /chat/message` - Send message, get AI response
- `POST /chat/stream` - Streaming response via SSE
- `GET /chat/history/{id}` - Retrieve conversation
- `POST /chat/flag` - Flag message for review
- `POST /cart/add` - Add product to cart
- `GET /cart/view` - View cart contents
- `POST /cart/update` - Update cart
- `POST /cart/coupon` - Apply coupon

### Admin Endpoints (requires `manage_options`)

- `GET/POST /admin/settings` - Get/update settings
- `GET /admin/conversations` - List conversations
- `GET /admin/analytics` - Retrieve analytics

## Development

### Building Assets

```bash
# Install dependencies
npm install

# Development build with watch
npm run dev

# Production build
npm run build
```

### Directory Structure

```
glimmr-ai/
├── glimmr-ai.php              # Main plugin file
├── admin/                     # Admin classes
├── includes/                  # Core classes and tools
├── public/                    # Frontend classes
├── src/
│   ├── admin/                 # Admin React components
│   └── widget/                # Widget React components
└── languages/                 # Translations
```

## Hooks & Filters

### Actions

```php
// Register custom tools
do_action('glimmr_ai_register_tools', $registry);

// Before/after tool execution
do_action('glimmr_ai_before_tool_execute', $name, $args);
do_action('glimmr_ai_after_tool_execute', $name, $args, $result);
```

### Filters

```php
// Preserve data on uninstall (default: true = delete)
apply_filters('glimmr_ai_delete_data_on_uninstall', true);
```

## Security

- API keys encrypted at rest (AES-256-CBC)
- Rate limiting with atomic database operations
- Session fingerprint validation
- Timing-safe email verification for guest orders
- PII masking in logs
- SQL injection prevention (prepared statements)
- XSS prevention (HTML whitelist)
- CSRF protection (nonces)

## Changelog

### 1.0.0
- Initial release
- OpenAI Responses API integration
- 15 AI tools for WooCommerce
- Vector store RAG support
- Streaming responses
- Rich UI artifacts
- Conversion tracking
- GDPR compliance
- Multisite support with network settings

## Author

**Joseph DiGiovanna**
- Email: joseph.p.digiovanna@gmail.com
- Company: Vimpact Consulting LLC

## License

GPL-2.0+

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or any later version.
