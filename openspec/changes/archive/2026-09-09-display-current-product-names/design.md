## Context

`purchase_details`, `sale_details`, and sales bundle rows persist product names as transaction snapshots. The purchase and sales detail models also retain `product_id` relationships to the product catalog, but screens, print templates, and export queries inconsistently choose between the snapshot and `products.product_name`. As a result, renaming a product updates some outputs while purchase detail, sales detail, receiving, invoices, and exports can remain stale.

The persisted names remain valuable commercial evidence and allow historical rows to render if a product relationship is unavailable. The change therefore concerns display resolution, not transaction-data synchronization.

## Goals / Non-Goals

**Goals:**

- Give operational purchase and sales line models one consistent display-name rule: current linked product name first, persisted snapshot second.
- Apply that rule across detail, receiving/dispatch, list-preview, applicable bundle/component displays, invoices, printable documents, and exports.
- Eager-load product relationships on affected collection read paths so the display rule does not introduce N+1 queries or strict lazy-loading failures.
- Preserve transaction snapshots as fallbacks without treating them as the primary product label in regenerated output.
- Verify the behavior with focused automated tests and human browser testing.

**Non-Goals:**

- Rewriting or backfilling persisted product names on existing transaction rows.
- Changing product codes, prices, units, tax, or any other snapshot field.
- Changing search matching or report calculations/grouping except where name selection must be adjusted to emit the current display label.
- Running or requiring the complete automated test suite for this focused presentation change.

## Decisions

### Use a reusable model-level display-name value

Affected transaction-line models will expose a presentation-oriented resolved name whose precedence is:

1. the non-empty current `product.product_name`;
2. the non-empty persisted line name (`product_name` or bundle `name`);
3. the existing neutral unknown-product label used by that surface.

Views will consume this resolved value instead of repeating fallback expressions. This keeps precedence consistent and makes focused model/view testing possible.

Alternative considered: write the renamed value into all historical transaction rows. This is rejected because it destroys the snapshot, expands a simple rename into a potentially large multi-table update, and makes regenerated history dependent on a mutable write operation.

Alternative considered: change only the two primary show templates. This is rejected because related receiving, dispatch, and list-preview surfaces would continue showing conflicting names for the same transaction.

### Keep persisted snapshots immutable during product rename

No observer, event listener, queue, migration, or batch update will synchronize transaction-line name columns. Those columns remain creation-time evidence and provide the fallback when a relationship is unavailable.

### Apply one display policy to regenerated documents and exports

Interactive surfaces, invoices, printable documents, and exports will all prefer the current linked product name. Regenerating an existing document after a catalog rename will therefore show the new name by design.

Persisted names remain unchanged and are used only when the product cannot be resolved or its current name is blank. This retains resilience without presenting stale catalog labels to users.

### Load relationships intentionally

Controllers and Livewire queries that render multiple lines will eager-load the product relation, selecting the fields required by the output. Existing model-level eager loading may remain for compatibility, but affected entry points will not rely on accidental lazy loading. Nested sales bundle/component relationships will load their products where their current names are displayed. Query-backed exports will join or otherwise resolve products in bulk and apply the same live-name-first precedence.

### Limit verification to the changed behavior

Focused tests will prove current-name precedence, snapshot fallback, snapshot persistence, invoice/export output, and bounded relationship loading for the principal purchase and sales paths. A human will verify the affected browser pages. Full-suite execution is not part of this change plan.

## Risks / Trade-offs

- [Regenerated historical documents can differ from copies generated before a rename] → Treat this as the intended latest-catalog-name policy while preserving transaction snapshot columns for traceability.
- [A missed view or export can retain stale-name behavior] → Inventory direct line-name rendering across purchase and sales views, print templates, exports, and backing query services and cover each selected surface in the implementation checklist.
- [Resolved model properties can trigger lazy loading] → Eager-load products on all affected collection paths and add focused query/lazy-loading regression coverage where existing test patterns support it.
- [Blank or unavailable product records can produce an empty label] → Treat blank live names as unavailable and fall back to the stored snapshot.
- [Bundle rows use a different persisted column] → Apply the same precedence through a bundle-specific resolved display value rather than assuming every row has `product_name`.

## Migration Plan

1. Introduce the resolved display-name behavior without changing database schema or stored data.
2. Update operational purchase surfaces and their relationship loading.
3. Update operational sales and bundle/component surfaces and their relationship loading.
4. Update purchase and sales print templates and exports to use the same current-name resolution.
5. Run focused automated tests, then perform human browser verification of renamed-product and fallback cases.

Rollback consists of reverting the presentation and loading changes. No data rollback is necessary because persisted transaction snapshots are never modified.

## Open Questions

None.
