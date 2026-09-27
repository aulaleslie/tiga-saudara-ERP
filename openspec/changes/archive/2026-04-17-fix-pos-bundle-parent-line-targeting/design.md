## Context

The POS sell shell already detects bundle-parent products and can open a bundle selection modal before adding a line. The cart service also already keeps selected bundle state in line metadata and merge keys, so `Product A + Bundle A`, `Product A + Bundle B`, and `Product A + no bundle` can exist as distinct rows.

The current problem is the frontend routing order. Some scan flows first look for an existing serial-required line by `product_id`, then append the scanned serial to that row. That bypasses bundle selection when the same bundle-parent product is scanned again. Similar product-only post-add line lookup can target the wrong row when a normal no-bundle add is created after a bundled row already exists.

## Goals / Non-Goals

**Goals:**

- Make bundle intent capture the first decision for any bundle-parent add, independent of serial tracking.
- Ensure target cart rows are resolved by product plus bundle state: selected bundle id or explicit no-bundle.
- Keep serial handoff behavior, but append serials only after the correct bundle-aware row has been selected or created.
- Keep non-serial bundle-parent behavior consistent by incrementing only the matching bundle-aware row.
- Add regression tests that cover serial and non-serial products across same bundle, different bundle, and no-bundle rows.

**Non-Goals:**

- Redesigning bundle pricing or checkout posting.
- Changing product, bundle, or serial database schema.
- Changing scanner resolver backend matching rules.
- Changing the cashier-facing modal layout beyond behavior needed for correct targeting.

## Decisions

### 1. Bundle parent routing wins before existing-line lookup

For any resolved product marked as a bundle parent, scan/add flow must open bundle selection before selecting an existing cart line. This applies to barcode/product scans, serial scans, manual search selection, and camera scans because they share the same add path.

Alternative considered: find an existing line with available quantity/serial capacity first, then ask only if no matching line exists. That preserves the current shortcut but keeps the bug: the cashier cannot choose a different bundle or no-bundle state for the next unit.

### 2. Use bundle-aware line matching after cart mutation

After adding a line, frontend logic should locate the target row with the same product id and the selected bundle state. For selected bundles, match `bundle_id` to the selected bundle id. For "continue without bundle", match an empty bundle id. Product-only lookup is insufficient because cart snapshots can contain several lines for the same parent product.

Alternative considered: rely entirely on the backend to return a mutated line id. That would be cleaner long term, but it expands the API contract. This change can remain frontend-focused because the existing snapshot contains enough metadata to locate the correct row.

### 3. Preserve backend merge-key behavior

The cart service merge key already includes `bundle_id`, so same-product/different-bundle rows are distinct. Implementation should verify this behavior and add tests rather than changing the merge-key model.

Alternative considered: add a separate `bundle_selection_mode` into merge keys. The current nullable bundle id already differentiates selected-bundle rows from no-bundle rows, so a merge-key change is unnecessary unless tests reveal an existing metadata gap.

### 4. Treat serial as payload, not routing identity

Serial numbers should be carried through the bundle modal and appended to the row chosen by bundle state. Serial presence must not decide whether bundle selection is skipped. Non-serial products follow the same bundle decision tree, with quantity increment replacing serial append.

Alternative considered: maintain a separate serial-specific bundle flow. That would duplicate the same intent logic and keep serial/non-serial behavior divergent.

## Risks / Trade-offs

- Rapid scanner submissions while a bundle modal is open could overwrite pending bundle state -> keep pending state single-flight and ensure scan inputs are cleared or blocked while bundle selection is pending.
- Existing product-only helper code may remain in secondary paths -> cover all shared scan/add entry points in tests and avoid product-only fallback when product is a bundle parent.
- Finding the target row from snapshots can be ambiguous if data types differ between numeric ids and string ids -> normalize ids before comparison.
- If backend metadata for no-bundle rows is inconsistent, frontend matching may miss the row -> test no-bundle rows explicitly and normalize empty `bundle_id` as no bundle.
