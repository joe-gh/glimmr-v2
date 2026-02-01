# Agent Robustness Analysis & Improvement Proposals

## Executive Summary

This document analyzes failure modes in the Glimmr AI Shopping Assistant's agent architecture and proposes a multi-layered solution combining:
1. A new **`needs_clarification`** bailout mechanism
2. **Pre-flight validation checklists** in the system prompt
3. **Graceful degradation patterns** for edge cases
4. **Capability boundary hardening** to prevent hallucinated actions

---

## Part 1: Problem Taxonomy

### Category A: Missing Context (High Frequency)

These failures occur when the agent attempts actions without required contextual information.

| Problem ID | Scenario | Current Behavior | Impact |
|------------|----------|------------------|--------|
| A1 | "Does it come in medium?" (no prior product) | Agent may search for "medium" or hallucinate | Wrong product shown |
| A2 | "Add that to my cart" (ambiguous reference) | Agent guesses or searches | Wrong item added |
| A3 | "The cheaper one" (no comparison context) | Agent may search or fail | Confusion |
| A4 | "What about the jacket we discussed?" (stale session) | Context lost after 1 hour | User frustration |

**Root Cause:** Workspace transient expires (1 hour TTL), and pronoun resolution depends on `focused_product_ids` which may be empty.

### Category B: Tool Parameter Complexity (Medium Frequency)

These failures occur when the agent mis-structures complex nested parameters.

| Problem ID | Scenario | Current Behavior | Impact |
|------------|----------|------------------|--------|
| B1 | Wrong mode/object mismatch | `query_products(mode: "search", product_ids: [...])` | Tool error |
| B2 | Missing nested wrapper | `query_products(mode: "details", product_id: 123)` | Tool error |
| B3 | Legacy vs modern params | `order_status(order_number: "123")` vs `order_status(lookup: {...})` | May work but inconsistent |

**Root Cause:** 5 different modes with different required nested objects is cognitively complex for the LLM.

### Category C: Verification Flow Errors (Medium Frequency)

These failures occur in guest order verification flows.

| Problem ID | Scenario | Current Behavior | Impact |
|------------|----------|------------------|--------|
| C1 | Guest order lookup without order number | Agent may call tool prematurely | Tool error |
| C2 | Email provided but no zip | Agent forgets second verification field | Verification fails |
| C3 | User provides info across multiple messages | Agent may lose track of gathered info | Repeated questions |

**Root Cause:** Two-field verification (email + zip) requires gathering both before tool call.

### Category D: Capability Hallucination (Low Frequency, High Impact)

These failures occur when the agent promises or attempts actions it cannot perform.

| Problem ID | Scenario | Risk |
|------------|----------|------|
| D1 | "Cancel my order" | Agent might search for cancel tool |
| D2 | "Email me when it's back in stock" | Agent might promise notification |
| D3 | "Change my delivery address" | Agent might attempt account modification |
| D4 | "Talk to a human" | Agent might claim escalation capability |

**Root Cause:** Guardrails exist but are in a separate section that may not be top-of-mind.

### Category E: Multi-Part Request Handling (Medium Frequency)

These failures occur when users ask compound questions.

| Problem ID | Scenario | Current Behavior | Impact |
|------------|----------|------------------|--------|
| E1 | "Tell me about X and your return policy" | May only answer one part | Incomplete response |
| E2 | "Add X and Y to cart" | May only add first item | Missing items |
| E3 | "Compare these and tell me which is best for hiking" | May show comparison without recommendation | User must ask again |

**Root Cause:** Agent may "complete" after first tool result instead of tracking all request parts.

### Category F: Search Mode Selection (Medium Frequency)

These failures occur when the agent chooses the wrong search approach.

| Problem ID | Scenario | Wrong Choice | Correct Choice |
|------------|----------|--------------|----------------|
| F1 | "Blue hoodies under $50" | Semantic search | Structured (has filters) |
| F2 | "Something cozy for winter" | Structured search | Semantic (conceptual) |
| F3 | "Waterproof hiking boots" | Either could work | Semantic preferred |

**Root Cause:** Decision boundary between semantic and structured search is fuzzy.

---

## Part 2: Proposed Solutions

### Solution 1: Needs-Clarification Bailout Mechanism

**Concept:** Add a structured way for the agent to recognize when it MUST clarify before proceeding, with specific categories of missing information.

**Implementation Options:**

**Option 1A: New Action Type**
Add `"needs_info"` as a fourth action type alongside `clarify`, `tool`, `final`:

```json
{
  "action": "needs_info",
  "missing": ["product_reference", "order_number"],
  "context_checked": ["focused_products: empty", "workspace.constraints: empty"],
  "user_message": "Which product would you like me to check? I don't see any products from our recent conversation."
}
```

**Option 1B: Enhanced Clarify Action**
Extend the existing `clarify` action with required metadata:

```json
{
  "action": "clarify",
  "thought": "User said 'it' but focused_products is empty",
  "clarification_type": "missing_product_reference",
  "attempted_resolution": ["checked focused_products", "checked shortlist", "checked recent tool results"],
  "user_message": "I want to make sure I help with the right item. Which product are you asking about?"
}
```

**Option 1C: Pre-Tool Validation Gate (Recommended)**
Add a validation step in the prompt that MUST be performed before certain tools:

```
## Pre-Tool Validation Gates

Before calling these tools, you MUST verify the required context exists:

| Tool | Required Context | If Missing |
|------|------------------|------------|
| `add_to_cart` | Product ID (explicit or from focused_products) | Ask: "Which product would you like to add?" |
| `resolve_variation` | Product ID + attribute values | Ask for missing attributes |
| `order_status` (guest) | Order number + email + zip | Ask for ALL missing in one message |
| `update_cart` | Cart item key or clear product reference | Ask: "Which item in your cart?" |
| `query_products(details)` | Specific product ID | Use search mode instead, or ask |

**VALIDATION CHECKLIST (Run mentally before tool calls):**
1. ✅ Do I have all required parameters with real values (not placeholders)?
2. ✅ If referencing "it/that/this", is there a focused product in context?
3. ✅ If guest order lookup, do I have order_number AND email AND zip?
4. ✅ Is the mode/nested-object pairing correct for query_products?

If ANY check fails → Use action="clarify" to gather missing info FIRST.
```

### Solution 2: Focused Product Fallback Chain

**Concept:** When the agent encounters a pronoun or ambiguous reference, follow a defined resolution chain.

**Prompt Addition:**
```
## Resolving Ambiguous References ("it", "that", "this product")

When a user references a product ambiguously, resolve in this order:

1. **Check Focused Products** → Use first item in "Product IDs in Focus" from workspace
2. **Check Shortlist** → If focused empty but shortlist has 1 item, use it
3. **Check Last Tool Result** → If a single product was just returned, use it
4. **Clarify** → If multiple products or none, ask: "Which product did you mean?"

**NEVER:**
- Guess a product ID
- Search for the pronoun as a query (e.g., searching "it" or "that one")
- Assume the user means the first search result

**Example Resolution:**
User: "Does it come in blue?"
→ Check: focused_products = [596] (CloudSoft Hoodie)
→ Action: Call resolve_variation(product_id=596, attributes={color: "blue"})
✅ Correct

User: "Does it come in blue?"
→ Check: focused_products = [] (empty)
→ Action: clarify → "Which product would you like me to check for blue?"
✅ Correct
```

### Solution 3: Capability Boundary Hardening

**Concept:** Move capability limitations from the guardrails section into a highly visible "STOP" section.

**Prompt Addition:**
```
## 🛑 HARD BOUNDARIES - Actions You CANNOT Take

These requests are IMPOSSIBLE with your current tools. Do not search for tools, do not promise these actions, do not say "let me try."

| User Request | Your Response Pattern |
|--------------|----------------------|
| Cancel/modify placed order | "I can't modify orders after they're placed. Here's how to contact support: [use site_knowledge for contact info]" |
| Send email/notification | "I'm not able to send emails, but I can help you [alternative action]" |
| Update account info | "Account changes need to be made in your account settings. Would you like me to show you the account page?" |
| Process refund/return | "Returns are handled by our support team. I can show you the return policy and contact info." |
| Talk to human/escalate | "I can provide contact information for our support team. Would that help?" |
| Set up alerts/notifications | "I can't set up notifications, but I can check current stock right now if you'd like." |

**Response Pattern:**
1. Acknowledge what they want (empathy)
2. Clearly state you cannot do it (honesty)
3. Offer the closest alternative you CAN do (helpful)
4. Provide path to resolution (actionable)
```

### Solution 4: Multi-Part Request Planning

**Concept:** When a request contains multiple distinct questions/actions, enumerate them before executing.

**Prompt Addition:**
```
## Handling Multi-Part Requests

When a user asks for multiple things in one message, PLAN before executing:

**Step 1: Enumerate**
In your `thought` field, list all distinct requests:
- "User wants: (1) product details for X, (2) return policy info"

**Step 2: Execute All**
Make tool calls for ALL parts, not just the first one:
- Call `query_products` for product details
- Call `site_knowledge` for return policy

**Step 3: Combine Response**
Address ALL parts in your final response. Use formatting to separate:
- "Here's the CloudSoft Hoodie: [details]"
- "Regarding returns: [policy]"

**Common Multi-Part Patterns:**
| Pattern | Tools Needed |
|---------|-------------|
| "Tell me about X and also Y" | 2× query_products OR query + knowledge |
| "Add X and apply coupon Y" | add_to_cart + apply_coupon |
| "Compare these and recommend one" | query_products(compare) + recommendation in text |
| "What's the price and is it in stock?" | 1× query_products (returns both) |

**Completion Check:** Before using action="final", verify ALL parts are addressed.
```

### Solution 5: Search Mode Decision Tree

**Concept:** Replace the fuzzy guidance with a concrete decision tree.

**Prompt Addition:**
```
## Search Mode Decision Tree

Use this flowchart to choose between semantic and structured search:

```
START
  │
  ├─ Does query have SPECIFIC FILTERS (category, price, size, color)?
  │   │
  │   YES ──→ Use semantic: false (structured SQL search)
  │           Examples: "blue hoodies", "jackets under $100", "size large shirts"
  │   │
  │   NO ──→ Continue...
  │
  ├─ Is query CONCEPTUAL or DESCRIPTIVE?
  │   │
  │   YES ──→ Use semantic: true (vector search)
  │           Examples: "cozy", "professional", "workout gear", "gift for mom"
  │   │
  │   NO ──→ Continue...
  │
  ├─ Is query a SUPERLATIVE (best, top, cheapest)?
  │   │
  │   YES ──→ Use semantic: false with sort parameter
  │           "best rated" → sort: "rating"
  │           "cheapest" → sort: "price_asc"
  │   │
  │   NO ──→ Default to semantic: true
```

**Quick Reference:**
| Query Pattern | semantic | Why |
|---------------|----------|-----|
| Has category + any filter | false | Filters need SQL |
| "Something [adjective]" | true | Conceptual matching |
| "[Superlative] [category]" | false | Sort + filter |
| Vague/lifestyle query | true | Needs understanding |
```

### Solution 6: Guest Order Verification Template

**Concept:** Provide a fill-in-the-blank template for guest order verification.

**Prompt Addition:**
```
## Guest Order Verification Flow

When a guest asks about an order, you need THREE pieces of information.
ALWAYS ask for all missing pieces IN ONE MESSAGE.

**Required Info:**
1. Order number (or ID)
2. Email address used for order
3. Billing zip/postal code

**Decision Logic:**
```
Guest asks about order
  │
  ├─ Have order number? NO ──→ Must ask
  ├─ Have email? NO ──→ Must ask
  ├─ Have zip? NO ──→ Must ask
  │
  └─ Have ALL THREE? ──→ Call order_status with verify block

**Template Response (adjust based on what's missing):**

Missing all three:
"I can look that up! Please provide:
• Your order number
• The email address used for the order
• Your billing zip code"

Have order number, missing email + zip:
"To access order #{number}, I'll need to verify your identity. What email address and billing zip code did you use?"

Have order + email, missing zip:
"Almost there! What's the billing zip code for this order?"

**NEVER call order_status for a guest without ALL THREE verified.**
```

### Solution 7: Stale Context Acknowledgment

**Concept:** When workspace state appears empty but user references prior context, acknowledge gracefully.

**Prompt Addition:**
```
## Handling Lost Context

If a user references something from "earlier" or "before" but your workspace shows no prior context (empty focused_products, empty constraints), the session state may have expired.

**Graceful Response:**
"I apologize, but I don't have context from our earlier conversation. Could you remind me which [product/order/item] you're referring to? I'm happy to help once I know what you're looking for."

**Signs of Lost Context:**
- User says "the one we talked about" but focused_products is empty
- User references constraints you don't have recorded
- User expects you to remember something from "earlier"

**DO NOT:**
- Pretend you remember
- Guess what they meant
- Search for vague terms like "the product"

**DO:**
- Acknowledge the gap honestly
- Ask for the specific information needed
- Offer to start fresh
```

---

## Part 3: Implementation Priority Matrix

| Solution | Impact | Effort | Priority |
|----------|--------|--------|----------|
| Solution 1C: Pre-Tool Validation Gates | High | Low | **P0** |
| Solution 3: Capability Boundary Hardening | High | Low | **P0** |
| Solution 2: Focused Product Fallback Chain | High | Low | **P1** |
| Solution 6: Guest Order Verification Template | Medium | Low | **P1** |
| Solution 4: Multi-Part Request Planning | Medium | Low | **P1** |
| Solution 5: Search Mode Decision Tree | Medium | Low | **P2** |
| Solution 7: Stale Context Acknowledgment | Low | Low | **P2** |
| Solution 1A/1B: New Action Type | Medium | High | **P3** (future) |

---

## Part 4: Consolidated Prompt Additions

The following section can be added to the system prompt to address the highest-priority issues:

```markdown
---

## 🛑 CRITICAL: Validation Gates & Boundaries

### Pre-Tool Validation (MUST check before calling tools)

| Tool | Required Context | If Missing → Clarify |
|------|------------------|---------------------|
| `add_to_cart` | Explicit product_id OR focused_products not empty | "Which product would you like to add?" |
| `resolve_variation` | product_id + at least one attribute | "What size/color are you looking for?" |
| `update_cart` | cart_item_key OR unambiguous product reference | "Which item in your cart?" |
| `order_status` (guest) | order_number + email + zip (ALL THREE) | Ask for all missing in ONE message |
| `query_products(mode=details)` | Specific product_id | Use mode=search instead, or clarify |
| `reorder` | User must be logged in + valid order_id | Check login, ask for order number |

**Before ANY tool call, verify:**
1. ✅ All required parameters have REAL values (no placeholders, no guesses)
2. ✅ For "it/that/this" → focused_products must have entries
3. ✅ Mode and nested object match for query_products
4. ✅ Guest verification has ALL required fields

### Ambiguous Reference Resolution

When user says "it", "that", "this product", "the one":
1. Check `focused_products` in workspace → Use first if exists
2. Check if last tool returned exactly 1 product → Use it
3. Otherwise → MUST clarify: "Which product did you mean?"

❌ NEVER search for pronouns ("it", "that one")
❌ NEVER guess product IDs
❌ NEVER assume first search result

### Hard Capability Boundaries

These actions are IMPOSSIBLE. Respond with empathy + alternative:

| Request | Response Pattern |
|---------|-----------------|
| Cancel/modify order | "I can't modify placed orders. Here's how to contact support..." |
| Send email/notification | "I can't send emails, but I can [check stock now / show you the item]..." |
| Update account/address | "Account changes are made in your account settings. Want me to show you that page?" |
| Process refund | "Refunds are handled by support. I can show you the return policy..." |
| Talk to human | "I can provide support contact info. Would that help?" |

### Multi-Part Requests

When user asks multiple things:
1. **Enumerate** in thought: "User wants: (1)..., (2)..."
2. **Execute** tools for ALL parts
3. **Verify** all parts addressed before final response

### Guest Order Verification

ALWAYS collect ALL THREE before calling order_status:
- Order number ✓
- Email address ✓
- Billing zip code ✓

Template: "To look up your order, I'll need: your order number, the email used for the order, and your billing zip code."
```

---

## Part 5: Testing Scenarios

After implementing these changes, test with these scenarios:

### Pronoun Resolution Tests
1. First message: "Does it come in medium?" → Should clarify
2. After showing products: "Does it come in medium?" → Should use focused product
3. After showing 3 products: "Add that one" → Should clarify which one

### Verification Flow Tests
4. Guest: "Where's my order?" → Should ask for order#, email, AND zip together
5. Guest provides order# only → Should ask for email AND zip together
6. Logged-in user: "Where's my order 12345?" → Should proceed without verification

### Capability Boundary Tests
7. "Cancel my order" → Should explain limitation, offer support contact
8. "Email me when back in stock" → Should explain cannot do, offer to check now
9. "Change my shipping address" → Should direct to account settings

### Multi-Part Tests
10. "Tell me about the Alpine Jacket and your return policy" → Should answer BOTH
11. "Add the hoodie and apply code SAVE10" → Should do BOTH actions
12. "Compare jackets and tell me which is warmest" → Should compare AND recommend

### Search Mode Tests
13. "Blue hoodies under $50" → Should use structured search
14. "Something cozy for winter" → Should use semantic search
15. "Best rated jackets" → Should use structured with sort=rating
