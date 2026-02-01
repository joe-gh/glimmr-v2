# GPT-5 Review Prompt: Agent Robustness Analysis

Copy everything below this line and paste into GPT-5 for review:

---

## Context

I'm building an AI-powered shopping assistant for WooCommerce called **Glimmr AI**. The agent uses OpenAI's Responses API with tool calling to help customers:
- Search and browse products
- Manage their shopping cart
- Track orders
- Apply coupons
- Answer questions about store policies

The agent operates as a **slot-filling agent** with a structured JSON output format:
```json
{
  "action": "clarify" | "tool" | "final",
  "thought": "internal reasoning",
  "workspace_updates": { "constraints": {}, "candidates": [], "shortlist": [] },
  "tool_call": { "name": "...", "arguments_json": "...", "purpose": "..." },
  "user_message": "response to user"
}
```

The agent has access to these tools:
- `query_products` (5 modes: search, compare, details, stock_check, aggregate)
- `add_to_cart`, `view_cart`, `update_cart`
- `apply_coupon`, `coupon_lookup`
- `order_status`, `order_history`, `reorder`
- `checkout_link`, `navigate_to_page`
- `site_knowledge`, `text_answer`
- `recommendations`
- `resolve_product`, `resolve_variation`, `resolve_cart_item`, `resolve_order`

**Key Architecture Details:**
1. **Workspace State**: Persisted via WordPress transients (1-hour TTL) containing `focused_product_ids`, `constraints`, `candidates`, `shortlist`
2. **Loop Prevention**: Max 5 rounds per user message, max 3 tool calls per turn
3. **Guest Order Verification**: Requires email AND zip code for non-logged-in users
4. **Tool Parameters**: Complex nested structures (e.g., `query_products` requires mode-specific nested objects)

---

## Problem Analysis

I've identified these categories of failure modes:

### Category A: Missing Context (High Frequency)
| Problem | Scenario | Impact |
|---------|----------|--------|
| A1 | "Does it come in medium?" with no prior product shown | Wrong product or failed search |
| A2 | "Add that to my cart" with ambiguous reference | Wrong item added |
| A3 | User returns after 1+ hour (transient expired) | Context lost, user frustrated |

### Category B: Tool Parameter Complexity (Medium Frequency)
| Problem | Scenario | Impact |
|---------|----------|--------|
| B1 | Mode/object mismatch in `query_products` | Tool error |
| B2 | Missing nested wrapper (e.g., `product_id` at root instead of `details.product_id`) | Tool error |

### Category C: Guest Order Verification Errors (Medium Frequency)
| Problem | Scenario | Impact |
|---------|----------|--------|
| C1 | Agent calls `order_status` without asking for order number first | Tool error |
| C2 | Agent gets email but forgets to ask for zip code | Verification fails |
| C3 | Info gathered across multiple messages gets lost | User repeats themselves |

### Category D: Capability Hallucination (Low Frequency, High Impact)
| Problem | Scenario | Risk |
|---------|----------|------|
| D1 | User asks to cancel order | Agent might search for nonexistent tool |
| D2 | User asks for email notification | Agent might promise capability |
| D3 | User asks to change shipping address | Agent might attempt modification |

### Category E: Multi-Part Request Handling (Medium Frequency)
| Problem | Scenario | Impact |
|---------|----------|--------|
| E1 | "Tell me about X and your return policy" | Agent only answers one part |
| E2 | "Add X and Y to cart" | Agent only adds first item |

### Category F: Search Mode Selection (Medium Frequency)
| Problem | Scenario | Impact |
|---------|----------|--------|
| F1 | "Blue hoodies under $50" uses semantic search | Filters ignored |
| F2 | "Something cozy for winter" uses structured search | Poor results |

---

## Proposed Solutions

### Solution 1: Pre-Tool Validation Gates (Prompt Addition)

Add a validation checklist that MUST be verified before certain tool calls:

```markdown
## Pre-Tool Validation Gates

Before calling these tools, verify required context exists:

| Tool | Required Context | If Missing → Clarify |
|------|------------------|---------------------|
| `add_to_cart` | Explicit product_id OR focused_products not empty | "Which product would you like to add?" |
| `resolve_variation` | product_id + at least one attribute | "What size/color are you looking for?" |
| `order_status` (guest) | order_number + email + zip (ALL THREE) | Ask for all missing in ONE message |
| `query_products(mode=details)` | Specific product_id | Use mode=search instead |

**Validation Checklist:**
1. ✅ All required parameters have REAL values (no placeholders)
2. ✅ For "it/that/this" → focused_products must have entries
3. ✅ Mode and nested object match for query_products
4. ✅ Guest verification has ALL required fields
```

### Solution 2: Ambiguous Reference Resolution Chain (Prompt Addition)

```markdown
## Resolving Ambiguous References

When user says "it", "that", "this product":
1. Check `focused_products` → Use first if exists
2. Check if last tool returned exactly 1 product → Use it
3. Otherwise → Clarify: "Which product did you mean?"

❌ NEVER search for pronouns ("it", "that one")
❌ NEVER guess product IDs
```

### Solution 3: Capability Boundary Hardening (Prompt Addition)

```markdown
## 🛑 Hard Boundaries - IMPOSSIBLE Actions

| Request | Response Pattern |
|---------|-----------------|
| Cancel/modify order | "I can't modify placed orders. Here's how to contact support..." |
| Send email/notification | "I can't send emails, but I can [alternative]..." |
| Update account info | "Account changes are made in settings. Want me to show you?" |
| Process refund | "Refunds are handled by support. I can show the return policy..." |
```

### Solution 4: Multi-Part Request Planning (Prompt Addition)

```markdown
## Multi-Part Requests

When user asks multiple things:
1. **Enumerate** in thought: "User wants: (1)..., (2)..."
2. **Execute** tools for ALL parts
3. **Verify** all parts addressed before final response
```

### Solution 5: Guest Order Verification Template (Prompt Addition)

```markdown
## Guest Order Verification

ALWAYS collect ALL THREE before calling order_status:
- Order number ✓
- Email address ✓
- Billing zip code ✓

Ask for all missing info IN ONE MESSAGE:
"To look up your order, I'll need your order number, the email used, and billing zip code."
```

### Solution 6: Search Mode Decision Tree (Prompt Addition)

```markdown
## Search Mode Selection

| Query Pattern | semantic | Reason |
|---------------|----------|--------|
| Has category + price/size/color | false | Filters need SQL |
| "Something [adjective]" | true | Conceptual |
| "[Superlative] [category]" | false | Sort + filter |
| Lifestyle/vibe query | true | Understanding needed |
```

### Alternative Considered: New "needs_info" Action Type

Instead of relying on prompt instructions, add a fourth action type:

```json
{
  "action": "needs_info",
  "missing": ["product_reference"],
  "context_checked": ["focused_products: empty"],
  "user_message": "Which product would you like me to check?"
}
```

**Pros:** Explicit, structured, easier to validate
**Cons:** Requires schema change, more complex implementation

---

## Questions for Review

1. **Completeness**: Are there failure modes I'm missing? What other edge cases should I consider?

2. **Solution Effectiveness**: Will the proposed prompt additions actually prevent these failures, or will the LLM ignore verbose instructions?

3. **Prompt Length Concerns**: The current system prompt is ~1800 lines. Adding these sections increases it further. Is there a more concise way to achieve the same goals?

4. **Action Type vs Prompt**: Should I add a new `needs_info` action type, or are prompt-based guardrails sufficient? What are the tradeoffs?

5. **Priority**: Given limited implementation time, which solutions provide the highest impact for lowest effort?

6. **Testing Strategy**: How should I systematically test these improvements? What edge cases should be in my test suite?

7. **Graceful Degradation**: When the agent inevitably fails (wrong tool call, missing context), what's the best recovery pattern? Should the tool layer catch errors and return structured "retry with X" responses?

8. **State Management**: The 1-hour transient TTL seems problematic for long shopping sessions. Should I:
   - Extend TTL to 24 hours?
   - Use database storage instead of transients?
   - Accept the limitation and focus on graceful degradation?

9. **Tool Schema Simplification**: The `query_products` tool has 5 modes with different required nested objects. Would it be better to split into 5 separate tools (`search_products`, `compare_products`, etc.) even though this increases the total tool count?

10. **Confidence Scoring**: Should the agent include a confidence level in its responses? E.g., "I'm 80% sure you mean the CloudSoft Hoodie - is that right?" This could catch ambiguous situations before acting.

---

## Expected Output

Please provide:

1. **Gap Analysis**: Any failure modes or edge cases I haven't considered
2. **Solution Critique**: Strengths and weaknesses of each proposed solution
3. **Recommendations**: Your recommended approach, prioritized
4. **Alternative Approaches**: Any solutions I haven't considered
5. **Implementation Guidance**: Specific wording improvements for the prompt additions
6. **Testing Scenarios**: Additional test cases I should include

---

## Constraints

- The agent uses OpenAI's Responses API (not Assistants API)
- Tool schemas are defined in PHP and passed to OpenAI
- The system prompt is customizable by store owners (can't be too technical)
- Performance matters: each round-trip to OpenAI costs time and tokens
- The agent must work for both logged-in customers and guests
