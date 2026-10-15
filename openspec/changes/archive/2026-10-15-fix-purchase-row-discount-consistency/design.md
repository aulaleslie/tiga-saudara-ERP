# Design

## Context

See proposal.md for motivation. The current Laravel/Livewire flow persists canonical base-unit currency in `product_discount_amount`, while `entered_product_discount_amount` holds either a fixed entered-unit currency amount or an entered percentage. Livewire cart `product_discount` is used as currency per entered unit during calculations. Edit hydration currently loads canonical currency directly into that cart field. `PurchaseNormalizer` checks a top-level entered discount but misses its options equivalent and, for fixed discounts, ignores `product_discount_input`.

Details always format the entered discount as Rupiah; print derives a percentage from that value even when it already is a percentage. Taxed row DPP additionally subtracts a discount already applied to subtotal. Cart quantity arithmetic casts to integer. Earlier focused checks had three failures caused by tests setting product-ID keys instead of current row-ID keys; these need repair before evaluating behavior.

## Goals / Non-Goals

**Goals:** Establish one consistent interpretation at the cart/persistence boundary, share presentation semantics between details and print, and verify downstream HPP using real saved lines.

**Non-Goals:** Schema changes, historical repairs, new pricing authority, changing global discount/shipping allocation, import redesign, sales/POS changes, or a full test suite run.

## Decisions

### 1. Normalize from an explicitly entered discount

Resolve the latest explicit entered input from the supported request/cart shape, including `options.entered_product_discount_amount` and `options.product_discount_input`, respecting zero. Ensure interactions update these fields together so a stale snapshot cannot override a new input. Normalize fixed entered currency by the authoritative factor exactly once; normalize percentages from canonical price and percentage. Maintain existing monetary precision and conversion validation. The overloaded `product_discount` currency field is a compatibility fallback, not a percentage input.

Alternative: changing column meanings or adding schema fields. Rejected because existing fields already represent both domains and consumers rely on canonical discount currency.

### 2. Hydrate the cart in entered units

For fixed discounts, restore the entered snapshot or derive entered currency from canonical discount multiplied by the stored factor when the snapshot is absent. For percentage discounts, restore the percentage snapshot or derive it from canonical discount and canonical price. Calculate the cart's entered-unit currency discount from that input. Apply the same rule to EditForm and applicable controller/duplicate hydration paths; preserve row identity. A zero snapshot is valid and must not automatically be replaced by a nonzero canonical value. Existing contradictory historical data cannot be repaired reliably by an accessor.

Alternative: multiply canonical discounts only during saving. Rejected because cart recalculation and display would remain wrong before save.

### 3. Present discount type and monetary amount separately

Use purchase-specific model/presentation helpers as appropriate to expose percentage input, entered-unit currency discount, and canonical currency discount without changing existing accessor meanings globally. Handle stored discount type case consistently. Details show percentage with `%` and its currency equivalent, or fixed currency per entered unit; print's percentage and amount columns use their corresponding values. Monetary equivalents are per entered unit, with row totals explicitly labeled if shown. Legacy missing snapshots derive display values without database writes. Row DPP is `sub_total - product_tax_amount`.

Alternative: duplicate type checks in each template. Avoid where it creates divergent percentage/fallback calculations.

### 4. Preserve quantities and pricing authority

Remove integer/minimum-one coercion from discounted purchase arithmetic and use the existing supported three-decimal quantity handling and validation. Inspect any reachable Alpine purchase calculator for the same coercion if used by a supported entry path. Keep shared sale/return behavior scoped and keep committed manual totals authoritative. Unchanged reload/save is not a pricing event; genuine price, quantity, discount, unit, or tax changes use the existing recalculation policy.

### 5. Validate HPP without changing its formula

Retain `(sub_total - product_tax_amount) / canonical_quantity`. Correcting its inputs fixes discounted cost. Use focused persisted-flow tests and an existing receiving/approval fixture to verify cost propagation; helper-only tests would miss hydration errors.

Example: one BOX of twelve PCS at Rp12,000 with Rp1,200 fixed discount has canonical discount Rp100 and net entered amount Rp10,800. Without tax its HPP is Rp900; at 11% tax excluded the total is Rp11,988, tax Rp1,188, and HPP Rp900; at 11% included the total is Rp10,800, tax Rp1,070.27, DPP Rp9,729.73, and HPP approximately Rp810.810833.

## Risks / Trade-offs

- [Historical zero or contradictory snapshots] → Preserve explicit zero; only derive when absent and keep data repair separate.
- [Manual totals drift during normalization] → Focused unchanged-save and manual-total tests retain authoritative totals and tax allocation.
- [Mutable cart row IDs invalidate test actions] → Resolve the current row ID after each options mutation and repair stale fixtures.
- [Conversion precision affects monetary totals] → Keep canonical price precision, derive entered discount from snapshots, and test a repeating-factor case with tolerances matching storage precision.
- [Shared cart calculator affects other document types] → Scope quantity changes to purchase or verify the directly affected shared behavior with a focused check.

## Migration Plan

Deploy application/template changes after focused regression checks pass. No migration or historical rewrite is required. Roll back application changes if needed; purchases saved during deployment remain persisted and any historical correction requires a separate audited plan.
