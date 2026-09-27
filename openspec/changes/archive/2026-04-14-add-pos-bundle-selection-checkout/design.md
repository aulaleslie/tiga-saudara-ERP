## Context

The POS sell flow already exposes `is_bundle_parent` in product search results, but the cashier shell treats every product add as a normal cart line. In contrast, the standard Sales flow already supports choosing a bundle and storing bundle item metadata on the cart row. POS therefore has a partial awareness of bundles without a way to select them, preserve them in the cart snapshot, or post them during checkout.

This change crosses the POS Blade shell, POS cart/session services, cart snapshot contracts, and the checkout posting adapter that creates `SaleDetails`, bundle item rows, dispatch details, and inventory transactions. The business rule is now clear: when a bundle is selected, the parent product and every bundled child product independently participate in stock validation and stock deduction only when that product's `stock_managed` flag is true.

## Goals / Non-Goals

**Goals:**
- Allow cashiers to choose a bundle when adding a bundle-parent product in POS.
- Persist bundle choice and bundled child item metadata in the POS cart snapshot.
- Keep POS cart rendering and mutation flows compatible with existing normal-product behavior.
- Make POS checkout post bundle-aware sale data and apply stock validation/deduction for the parent and each bundled child according to each product's `stock_managed` flag.
- Keep the design aligned with existing Sales bundle semantics where bundle items are stored as part of the sale record instead of being flattened into separate visible cart lines.

**Non-Goals:**
- Refactor the standard Sales create/edit flow in this change.
- Unify all product sale inventory policies across non-POS modules in this change.
- Redesign receipt output to show bundle details unless required to preserve correctness.
- Support advanced post-add bundle editing flows beyond the minimum cart operations needed to select a bundle and checkout successfully.
- Introduce a new Livewire-based POS architecture.

## Decisions

### Decision: Add bundle selection as a POS-native modal flow, not by reusing Sales Livewire components

POS sell is a JavaScript-driven shell built around JSON cart snapshots and imperative cart mutations, while the Sales flow uses Livewire state and modal events. Reusing Sales implementation details would create a mixed state model inside `sell.blade.php` and make the POS cart harder to reason about.

Instead, POS will:
- continue using the existing search result payload with `is_bundle_parent`
- lazily fetch bundle options for the selected parent product
- show a POS-specific bundle selection modal
- post the selected bundle in the existing cart-line creation flow

Alternative considered:
- Reuse Sales Livewire modal and cart logic.
- Rejected because POS does not manage cart state with Livewire and would need brittle cross-framework coordination.

### Decision: Represent a selected bundle as metadata on a single POS cart line

The POS cart snapshot will keep one visible parent line and extend that line with bundle metadata such as:
- `bundle_mode`
- `bundle_id`
- `bundle_name`
- `bundle_price`
- `bundle_items`

This preserves the existing POS snapshot structure and minimizes front-end churn. It also matches current Sales semantics where bundle items are stored as child metadata of the parent sale line rather than as separate visible cart lines.

Alternative considered:
- Flatten bundled child items into separate POS cart lines.
- Rejected because it would change cashier UX, complicate merge behavior, and blur the distinction between commercial composition and cart presentation.

### Decision: Extend cart line identity to include bundle selection state

POS currently merges lines using product/price/tax/conversion identity. Bundle-aware lines must not collapse into plain lines or into different bundle selections for the same parent product. The merge key will therefore include bundle selection state, at minimum:
- `bundle_mode`
- `bundle_id`

Alternative considered:
- Treat bundle information as non-identifying metadata and merge on existing keys.
- Rejected because the wrong bundle could be silently merged into an unrelated parent line.

### Decision: Keep bundle option lookup out of the search payload

Product search already returns enough information to decide whether a product requires a bundle choice. Full bundle definitions will be fetched lazily only when the cashier selects a bundle-parent product. This keeps the existing search response small and avoids loading nested bundle trees for every search result.

Alternative considered:
- Return bundle definitions inline in search results.
- Rejected because it increases payload size and duplicates data for products that are only searched, not added.

### Decision: Checkout posting will persist parent sale detail and bundle child records, while stock movement is evaluated per product

When a bundle is selected:
- the parent line remains the main sale detail
- bundled child items are persisted as bundle child records attached to that sale detail
- stock validation and deduction are evaluated independently for:
  - the parent product
  - each bundled child product

For each product in that set:
- if `stock_managed = true`, stock must be validated and deducted
- if `stock_managed = false`, no stock movement occurs

This keeps the commercial representation and inventory representation separate but consistent.

Alternative considered:
- Deduct stock only for bundled child products.
- Rejected because the confirmed business rule requires the parent product to participate as well when its own `stock_managed` flag is true.

### Decision: Bundle child snapshots will carry product attributes needed for checkout decisions

Each stored bundle child snapshot should include enough normalized data to support checkout behavior and UI rendering without repeated ambiguous lookups, including:
- `bundle_id`
- `bundle_item_id`
- `product_id`
- `name`
- `quantity`
- `quantity_per_bundle`
- `price`
- `sub_total`
- `stock_managed`
- `serial_number_required`

This keeps checkout deterministic and makes it easier to evolve receipt or detail views later.

Alternative considered:
- Store only `bundle_id` and re-hydrate child items during checkout.
- Rejected because it introduces more lookup coupling and makes the cart snapshot less self-describing.

## Risks / Trade-offs

- [Bundle-aware totals diverge from Sales semantics] → Reuse the existing Sales pricing convention where bundle price is additive to the parent line and bundled child prices do not double-count totals.
- [Cart line merging becomes inconsistent] → Include bundle selection state in the merge key and cover it with focused cart service tests.
- [Checkout creates correct sale records but misses inventory movement for parent or children] → Explicitly build the stock-impact set as parent plus bundled child products and apply `stock_managed` checks per product.
- [Bundle child products with serial tracking add more complexity than expected] → Carry `serial_number_required` in bundle item snapshots and keep serial-specific support limited to what current POS checkout can validate safely.
- [Search/add flow becomes slower when bundle-parent products are selected] → Fetch bundle options lazily only for selected bundle-parent products and keep normal product adds on the existing fast path.

## Migration Plan

1. Add the bundle lookup endpoint and cart line contract changes behind the existing POS shell without changing normal product behavior.
2. Extend POS cart snapshots and checkout posting to handle bundle metadata and bundle-aware stock movement.
3. Add feature coverage for bundle-parent add flow, skipped bundle flow, bundle-aware checkout posting, and `stock_managed` stock deduction branches.
4. Roll back by removing the bundle lookup and cart contract usage while preserving the existing normal-product POS path.

## Open Questions

- Should POS receipts explicitly render selected bundle names and bundled child items in this same change, or can receipt formatting remain unchanged while checkout data becomes bundle-aware?
- If a bundled child product requires serial tracking, should POS support serial capture for bundled children immediately or reject such bundle selections until dedicated UX is added?
