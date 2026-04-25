## Context

The Product Bundle module now persists two pricing concepts that were intentionally not wired into Sales in the prior change: `product_bundles.bundle_sale_price` as the final selling price for a selected bundle, and `product_bundle_items.informational_item_price` as reference-only component pricing. Standard Sales create/update still uses the older runtime model: it resolves the parent product price from product/tier/cascading logic, adds legacy `product_bundles.price` as a bundle add-on, and recalculates bundle component totals from component item prices.

Sales create/update has several places that can reprice a cart row after initial selection: customer selection, quantity changes, manual price edits, discount updates, tax toggles, non-PKP reconciliation, PKP tax reconciliation, and edit-page cart hydration. The bundle pricing rule needs to be carried through all of those paths so the selected bundled row remains priced from the parent row value, not from component prices or tier/cascade replacement.

## Goals / Non-Goals

**Goals:**

- Use `product_bundles.bundle_sale_price` as the initial parent row price when a bundle is selected in Sales create/update.
- Keep the bundled parent row price editable after selection.
- Preserve a manually edited bundled parent row price through customer, quantity, tax, discount, and edit hydration recalculations.
- Skip customer tier repricing and cascading quantity repricing for bundled rows.
- Treat bundle component item prices as informational only in Sales; they must not contribute to cart totals, `sale_details` totals, or sale header totals.
- Stop using legacy `product_bundles.price` as a Sales bundle add-on.

**Non-Goals:**

- Do not change Product Bundle CRUD persistence or validation.
- Do not remove or migrate legacy `product_bundles.price` or `product_bundle_items.price`.
- Do not change POS bundle runtime pricing in this proposal.
- Do not introduce customer-tier-specific `bundle_sale_price` values.
- Do not make bundle component prices editable from the Sales cart.

## Decisions

### 1. Bundled parent row owns the billable bundle price

When a Sales user selects a bundle, the cart row price will be initialized from `product_bundles.bundle_sale_price`. That row price is then the billable unit price for the selected parent product + bundle combination. If the user edits the row price, the edited value becomes the row's current billable price.

Rationale: `bundle_sale_price` is configured as the final `Harga Jual Paket`, not an add-on. Preserving manual edits keeps Sales behavior consistent with existing editable product row pricing.

Alternative considered: Keep the parent product price visible and add `bundle_sale_price` as a separate hidden add-on. Rejected because it recreates the legacy add-on ambiguity and makes the displayed row price differ from the billable bundle price.

### 2. Mark bundled cart rows with explicit bundle pricing metadata

Bundled cart rows should carry enough metadata to detect that automatic repricing must be bypassed, such as `bundle_id`, `bundle_name`, `bundle_price_source`, or equivalent information derived from non-empty `bundle_items`. The implementation should avoid relying on component prices to infer billable state.

Rationale: Customer selection, quantity update, and edit hydration code paths need a stable way to know that a row is bundled and must preserve its current parent row price.

Alternative considered: Detect bundled rows only by checking whether `bundle_items` is non-empty. This is acceptable as a fallback, but explicit metadata is clearer and less fragile if bundle component rendering changes later.

### 3. Bundle component prices are persisted as non-billable Sales context

Sales should continue persisting `sale_bundle_items` rows for dispatch, return, and display context, but their `price` and `sub_total` values should be zeroed or otherwise normalized so they cannot be added into Sales totals. Product Bundle `informational_item_price` can be displayed as read-only context where useful, but it must not be used as a billable component price.

Rationale: Downstream flows need bundle composition, not component revenue. Keeping component totals non-billable prevents double counting and aligns with the Product Bundle specification that item prices are informational.

Alternative considered: Persist informational prices into `sale_bundle_items.price` and rely on totals code not to sum them. Rejected because existing show/report/return paths already read bundle item price fields in some contexts, so nonzero persisted component prices create avoidable ambiguity.

### 4. Repricing bypass applies only while the row remains bundled

Customer tier repricing and cascading quantity repricing should still apply to normal product rows. For bundled rows, quantity changes multiply the current parent row unit price; they must not replace it from tier or conversion pricing. If a user removes the row and adds the product without a bundle, normal pricing rules apply again.

Rationale: This limits the behavioral change to selected bundles and preserves existing Sales pricing behavior for standalone products.

Alternative considered: Disable tier/cascade pricing globally when any bundle exists in the cart. Rejected because mixed carts with bundled and standalone products should behave predictably per row.

## Risks / Trade-offs

- [Legacy records with nonzero `sale_bundle_items.price`] → Edit hydration should normalize bundled component prices to non-billable cart state and preserve the parent row's current price.
- [Manual parent row override lost during recalculation] → Centralize bundled-row detection and preserve the row's current unit price in every recalculation path.
- [Display confusion between final bundle price and item information prices] → Hide component price editing in Sales and label component details as informational if displayed.
- [Existing reports or show pages may sum standalone bundle components] → Keep standard linked bundle components non-billable and verify Sales show/print totals use `sale_details`/sale header totals, not component sums.
- [Bundles with null `bundle_sale_price`] → Fall back safely to the parent product's current sale price or zero according to existing cart validation behavior, but do not fall back to legacy `product_bundles.price` as an add-on.

## Migration Plan

No database migration is required. The Product Bundle columns already exist.

Implementation should update Sales runtime behavior only. Rollback is a code rollback: Sales would return to the previous legacy add-on pricing path without schema changes.

## Open Questions

- Should the Sales bundle detail modal display `informational_item_price` as read-only reference, or hide component prices entirely? Either is acceptable as long as component prices are not editable and not billable.
