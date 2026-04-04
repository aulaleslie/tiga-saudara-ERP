## Context

Sales create and edit flows currently reach the cart through several independent entry points: existing product search, product quick-add, existing customer selection, and customer quick-add. The code already centralizes most line pricing inside `Sale\ProductCart`, but some paths still bypass that consistency by relying on missing follow-up events or legacy product columns during edit-cart hydration.

The investigation found three concrete failure modes. First, creating a customer from the sales quick-add modal dispatches `customerCreated`, but the sales cart repricing flow is keyed off `customerSelected`, so existing cart rows can remain stale. Second, sales edit rebuilds cart options from legacy `products.sale_price` and tier columns rather than the current `product_prices` rows. Third, the product quick-add modal is shared between purchase and sales pages and defaults to purchase-oriented behavior (`is_sold = false`), which can create a product that is immediately inserted into a sales cart without sellable pricing.

## Goals / Non-Goals

**Goals:**
- Make sales cart pricing deterministic across create, edit, and customer repricing flows.
- Ensure a customer created from the sales page triggers the same repricing behavior as selecting an existing customer.
- Ensure sales edit cart hydration uses the same setting-scoped pricing source as newly added cart lines.
- Make sales product quick-add behavior explicitly safe for sales so accidental purchase-only creation does not masquerade as a cart pricing bug.

**Non-Goals:**
- Redesign the entire product creation modal for all contexts.
- Change purchase pricing behavior or purchase cart requirements.
- Introduce new pricing tables or alter the `product_prices` schema.
- Change customer tier definitions or pricing math for WHOLESALER/RESELLER tiers.

## Decisions

### 1. Use `product_prices` as the single pricing source for sales cart pricing
Sales create, sales edit hydration, and customer repricing will all resolve base and tier prices from the active setting's `product_prices` row. Legacy product sale/tier columns will no longer be treated as the authoritative pricing source for sales cart behavior.

Rationale:
- Newly added sales lines already use `product_prices`.
- Edit hydration is the main outlier and is currently inconsistent with modern product persistence.
- A single source of truth makes browser symptoms easier to reason about and test.

Alternatives considered:
- Continue using legacy product columns as an edit fallback. Rejected because those columns are already intentionally de-emphasized in current product create/edit flows.
- Cache price snapshots only in cart options and never re-resolve. Rejected because customer repricing and edit hydration both need canonical setting-scoped prices.

### 2. Unify customer repricing around a cart-visible selection event
Any sales-page action that results in an effective customer selection, including quick-add customer creation, will emit or invoke the same repricing path used by existing customer selection.

Rationale:
- The current gap is not pricing logic but missing event continuity between modal and cart.
- Reusing the existing repricing path reduces behavioral drift between customer sources.

Alternatives considered:
- Teach `ProductCart` to listen directly to `customerCreated` and infer selection state. Rejected because `customerCreated` alone does not necessarily mean "this customer is now the active cart customer" in all contexts.
- Duplicate repricing logic in `CreateForm` or dropdown components. Rejected because pricing belongs in the cart.

### 3. Add a sales-scoped mode for product quick-add behavior
When the shared quick-add modal is opened from sales, it will behave as a sales-scoped entry point: it must not silently create a purchase-only product and immediately push it into a sales cart without explicit sellable pricing.

Rationale:
- The same modal is reused on purchase pages, where the current defaults are reasonable.
- The bug is contextual, so the fix should be contextual rather than globally forcing purchase and sales flows into one default.

Alternatives considered:
- Globally default all quick-add usage to `is_sold = true`. Rejected because purchase flows would inherit a sales-oriented assumption.
- Leave defaults unchanged and add only cart warnings. Rejected because it preserves the misleading "successfully added to cart" behavior.

### 4. Preserve cart-session behavior but remove pricing ambiguity
This change will not redesign session cart persistence. Instead, the implementation will make price derivation explicit and repricing idempotent so stale-session symptoms are easier to distinguish from live pricing errors.

Rationale:
- Session persistence is existing infrastructure shared across flows.
- The confirmed failures are fixable without widening scope into session lifecycle redesign.

Alternatives considered:
- Reset the sales cart on every page entry regardless of old input. Rejected because it would change existing recovery behavior after validation errors.

## Risks / Trade-offs

- [Risk] Repricing on quick-add customer selection could double-fire in some contexts. → Mitigation: standardize around one effective selection event and verify listener ownership on sales pages.
- [Risk] Sales edit hydration may change historical-looking row prices if existing rows previously relied on legacy fallback columns. → Mitigation: resolve canonical prices only for pricing metadata and preserve persisted sale detail amounts unless repricing is explicitly triggered by customer changes.
- [Risk] Sales-scoped quick-add defaults could diverge from purchase behavior and confuse maintainers. → Mitigation: make the mode explicit in the modal API and document the context-specific behavior in specs/tasks.
- [Risk] Hidden stale session carts can still confuse manual testing. → Mitigation: keep the specs focused on observable behavior for fresh create, edit hydration, and modal-driven customer/product flows.
