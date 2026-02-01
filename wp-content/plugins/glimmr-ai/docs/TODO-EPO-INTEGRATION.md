# EPO (Extra Product Options) Integration

**Status:** Planned
**Priority:** Future Enhancement
**Plugin:** ThemeComplete Extra Product Options

---

## Overview

Integrate ThemeComplete's Extra Product Options plugin with the Glimmr AI product modal, allowing customers to configure product options (engravings, customizations, etc.) directly in the chat widget.

**Key Insight:** ThemeComplete EPO isn't designed for headless use. The recommended pattern is using their PHP internals for validation/pricing while rendering our own UI.

---

## Scope

### Supported Types (Full Modal Integration)
- Select/dropdown
- Radio buttons
- Checkboxes
- Text input/textarea
- Any of the above with price modifiers

### Unsupported Types (Redirect to Product Page)
- File upload
- Date/time pickers
- Color picker
- Range slider
- Anything with conditional logic rules

### Out of Scope (Phase 2)
- Conditional logic (show option B only if option A selected)

---

## Implementation Phases

### Phase 1: Backend - EPO Data Endpoint

**New REST endpoint**: `GET /glimmr-ai/v1/epo-options/{product_id}`

Returns structured data:
```json
{
  "productId": 123,
  "hasEpo": true,
  "hasUnsupportedTypes": false,
  "sections": [
    {
      "id": "sec_abc",
      "title": "Personalization",
      "fields": [
        {
          "type": "select",
          "postKey": "tmcp_select_0_1_tc_123",
          "label": "Font Style",
          "required": true,
          "options": [
            { "value": "arial", "label": "Arial", "priceModifier": 0 },
            { "value": "script", "label": "Script", "priceModifier": 5.00 }
          ]
        },
        {
          "type": "textfield",
          "postKey": "tmcp_textfield_1_1_tc_123",
          "label": "Engraving Text",
          "required": true,
          "maxLength": 20,
          "priceModifier": 10.00,
          "priceType": "fixed"
        }
      ]
    }
  ]
}
```

**Key PHP functions to use:**
- `TM_EPO()->get_product_tm_epos($product_id, $form_prefix)` - Returns global, local, and price data
- `TM_EPO_API()->has_options($product_id)` - Check if product has EPO
- `TM_EPO_API()->is_valid_options($has)` - Validate options exist

### Phase 2: Backend - Cart Integration

**Modify existing cart endpoint** to accept EPO selections:

```json
{
  "product_id": 123,
  "quantity": 1,
  "variation_id": 456,
  "epo_selections": {
    "tmcp_select_0_1_tc_123": "script",
    "tmcp_textfield_1_1_tc_123": "Happy Birthday"
  }
}
```

**Key PHP function:**
- `THEMECOMPLETE_EPO_Cart()->tm_add_cart_item_data($cart_item_data, $product_id, $post_data)`

The `$post_data` array needs:
```php
$post_data = [
    'tm-epo-counter' => 1,
    'tcaddtocart'    => $product_id,
    // Plus all tmcp_* selections from the request
];
```

### Phase 3: Frontend - UI Components

1. **EpoFieldRenderer** - Renders appropriate input per field type
2. **EpoSection** - Groups fields with section titles
3. **Integration in ProductDetailModal** - Fetch EPO data, render fields, collect selections

### Phase 4: Fallback Logic

If `hasUnsupportedTypes: true`:
- Hide the in-modal EPO fields
- Show "This product has customization options" message
- "Configure on Product Page" button → opens product URL

---

## Files to Create/Modify

| File | Action |
|------|--------|
| `includes/class-glimmr-ai-rest-api.php` | Add EPO endpoint, modify cart endpoint |
| `includes/class-glimmr-ai-epo.php` | New class for EPO data extraction |
| `src/widget/components/EpoFields.jsx` | New component for rendering EPO inputs |
| `src/widget/components/ProductDetailModal.jsx` | Integrate EPO fields |
| `src/widget/utils/storeApi.js` | Add `getEpoOptions()` function |

---

## ThemeComplete EPO Technical Reference

### Data Storage
- Custom post types: `TM_EPO_LOCAL_POST_TYPE` and `TM_EPO_GLOBAL_POST_TYPE`
- Per-product meta: `tm_meta_cpf`

### Element Properties
- `internal_name`, `section`, `type`, `size`
- `required` flags
- Multi-choice arrays: `multiple_{element}_options_value`, `_title`, `_price`, `_sale_price`
- Conditional logic: `rules`, `rules_type`

### Cart Item Data Keys
- `tmpost_data` - Common key for EPO data in cart items
- Display keys: `tm_label`, `tm_value`, `tm_price`, `tm_quantity`, `tm_total_price`

### Hooks to be aware of
- `woocommerce_add_cart_item_data` - ThemeComplete adds EPO data here
- `woocommerce_add_to_cart_validation` - Validation happens here
- `woocommerce_get_item_data` - Cart display data
- `wc_epo_product_price`, `wc_epo_price` - Pricing filters

### JS Events
- `tm-epo-after-update` - Fired after prices update (if we need to hook into their JS)

---

## Post Key Naming Convention

ThemeComplete uses specific field names like `tmcp_select_0_1_tc_123`. The pattern appears to be:
```
tmcp_{type}_{elementIndex}_{sectionIndex}_{formPrefix}
```

Where `formPrefix` is typically `tc_{product_id}`.

**Recommended approach:** Include the exact `postKey` in our endpoint response so the frontend doesn't need to construct it.

---

## Testing Checklist

- [ ] EPO endpoint returns correct data for products with options
- [ ] EPO endpoint returns `hasEpo: false` for products without options
- [ ] Unsupported types are correctly detected
- [ ] Select/radio/checkbox fields render correctly
- [ ] Text input fields render with character limits
- [ ] Price modifiers display correctly (+$5.00 format)
- [ ] Required field validation works
- [ ] Cart add with EPO selections succeeds
- [ ] Cart displays EPO selections correctly
- [ ] Fallback to product page works for unsupported types
- [ ] Products without EPO still work normally

---

## Resources

- [ThemeComplete EPO Documentation](https://themecomplete.com/documentation/woocommerce-tc-extra-product-options/)
- [EPO Changelog](https://epo.themecomplete.com/changelog/)
- [Integration Gist](https://gist.github.com/79mplus-admin/fb13a2e38271ac4c79d9ee896ce90b1f)
