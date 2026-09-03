## Context

Products already define a canonical `base_unit_id` and zero or more `product_unit_conversions` whose factors express conversion units in base units. Purchase currently searches products and builds cart rows keyed by product, accepts integer-like base quantities, normalizes row money through `PurchaseNormalizer`, and persists only canonical-looking fields on `purchase_details`. The cart's conversion breakdown is display-only. Receiving likewise accepts integer quantities into an integer `received_note_details.quantity_received` and posts those values directly to base-unit stock and transaction ledgers.

Purchase also shares configurable automatic row-total increment rounding with Sales and POS. The requested behavior is to keep all current Purchase pricing authority—including manual unit-price and line-total overrides—but never apply the configurable increment in Purchase. This change crosses product configuration, Purchase create/edit, normalization, persistence, receiving, serial validation, returns/reporting displays, and migration compatibility.

## Goals / Non-Goals

**Goals:**

- Accept a base unit or valid product conversion unit on each Purchase line and retain the supplier-facing representation through receiving.
- Keep inventory and downstream operational quantities canonical in the product's smallest/base unit.
- Preserve immutable line snapshots sufficient to interpret historical purchases after product conversion configuration changes.
- Support decimal entered and received quantities without truncation, silent precision rounding, or floating-point comparison errors.
- Make serial requirements derive from whole canonical base units.
- Enforce conversion factors greater than one so the base unit remains the smallest counting unit for new activity.
- Remove only configured increment rounding from Purchase while preserving existing manual pricing and all Sales/POS rounding.
- Verify new and plausibly regressed paths with focused tests.

**Non-Goals:**

- Rebase products whose current base unit is not their actual smallest unit.
- Rewrite historical Purchase, receiving, stock, transaction, return, Sales, or POS records.
- Redesign Purchase manual unit-price or manual line-total overrides, discount semantics, taxation, or document-level adjustments.
- Change Sales or POS conversion, packing, pricing, or rounding behavior.
- Require a full automated test-suite run for this scoped change.

## Decisions

### 1. Treat the base unit as the canonical and smallest counting unit

Every conversion uses the direction `1 selected unit = conversion_factor × base unit`, with factor strictly greater than `1`. Base selection uses an implicit factor of `1`. Serialized products additionally require whole-number conversion factors. This produces a single canonical stock quantity and a predictable serial count.

Alternative considered: infer a serial unit from the smallest configured factor. This was rejected because configuration changes could reinterpret open or historical documents and because it permits a base unit that is not canonical.

Legacy factors less than or equal to one are not rewritten. They remain renderable where historical snapshots or existing behavior require them but are unavailable for new Purchase selection. A future audited UOM-rebasing change can correct those products.

### 2. Store canonical operational values plus supplier-facing snapshots

`purchase_details.quantity` remains the canonical base-unit quantity. `purchase_details.unit_price` remains the normalized base-unit price used by existing costing consumers. Nullable snapshot columns record at least the selected unit, conversion row reference, entered quantity, entered-unit price, conversion factor, and unit labels needed for durable display. The factor snapshot, not the current conversion row, is authoritative after persistence.

The schema should use nullable references where deletion semantics require historical tolerance, and scalar snapshots must be sufficient when the related unit or conversion is later inactive or absent. Legacy rows resolve as base-unit entries with factor one at runtime rather than requiring a data backfill.

Alternative considered: store only conversion IDs and recompute from current configuration. This was rejected because changing or deleting a conversion would corrupt document meaning. Storing only entered values was also rejected because stock, costing, reports, and returns already depend on canonical fields.

### 3. Use stable cart line identity for product plus selected unit

Purchase cart rows must no longer assume `product_id` uniquely identifies a line. A stable line key distinguishes at least product and selected unit/conversion, allowing `2 BOX` and `3 PCS` as separate rows. Adding the same product with the same unit increments that row; adding another unit creates another row. Livewire state arrays and cart mutations use row identity rather than product ID wherever collision is possible.

### 4. Centralize decimal-safe conversion

A Purchase-domain conversion value object/service validates identifiers and snapshots, parses decimal strings, multiplies quantities and factors, divides entered-unit prices into normalized base-unit prices, and compares quantities using fixed-scale decimal operations. Entered, canonical, and received quantities use three decimal places to align with current Purchase quantity storage. A result requiring unsupported canonical precision is rejected rather than rounded.

Normalized base-unit prices require additional calculation precision before final currency persistence/allocation so factors such as three do not alter the authoritative supplier line total. Existing currency columns and presentation retain their established precision; the authoritative row monetary result continues to come from existing Purchase pricing/override behavior.

Alternative considered: native PHP floats with final `round()`. This was rejected because conversion and over-receiving boundaries must not vary through binary floating-point drift.

### 5. Make conversion metadata server-authoritative

Clients submit selected unit/conversion identity and entered values, but the server reloads the product, base unit, and conversion relationship. It verifies that the conversion belongs to the product, its base unit matches the product, and its current factor is eligible when creating or changing a line. Persistence snapshots the validated server value; client-provided factors, base quantities, and normalized prices are never trusted.

Inactive or invalid conversions cannot be newly selected. Existing lines retain their snapshots during non-conversion edits and hydration. Before any receiving exists, an ordinary eligible Purchase edit may replace the selection and snapshot. Existing post-receipt edit restrictions remain unchanged.

### 6. Receive in ordered or base units, persist in base units

Receiving defaults each line to its snapshotted ordered unit and displays the base equivalent and remaining quantity. The receiver may use the ordered unit or base unit; unrelated product conversions are not offered. Submission converts to canonical base quantity before creating `received_note_details`. That column becomes decimal-compatible, and approved receiving, stock updates, transactions, completion checks, and over-receiving comparisons continue to consume canonical values.

The approval path rechecks canonical remaining quantity under the existing purchase/receipt lock. Rejected pending receipts do not consume remaining quantity. Existing receiving provenance and reversal behavior use the exact canonical quantity stored on the receipt detail.

### 7. Count serials in base units

For serialized products, the canonical received quantity must be a positive whole number and the submitted serial count must equal that base-unit quantity. Decimal entered conversion quantities are permitted only when multiplication produces a whole base quantity. One serial always represents one base unit.

### 8. Preserve exact Purchase pricing and disable only increment rounding

Purchase code paths stop reading or applying `row_total_rounding_increment`. Automatic Purchase rows retain their calculated currency-precision totals. Existing manual unit-price and manual line-total authority, recalculation flags, taxes, discounts, shipping, imports, and persisted-document stability remain intact. Sales and POS continue using the setting exactly as before.

Existing drafts are not rewritten or recalculated merely by loading them. A later price-affecting Purchase interaction follows the same existing authority rules but produces no configured-increment rounding.

### 9. Keep downstream quantity semantics explicit

Stock, receiving eligibility, Purchase returns, costing, normalization/replay, and inventory-facing reports consume canonical base quantity. Purchase screens, supplier-facing print/export surfaces, and receiving show the entered quantity/unit where available, with base equivalent where operationally useful. Legacy rows fall back to their product base unit and existing canonical values.

## Risks / Trade-offs

- [Existing code keys Livewire state by product ID] → Introduce and test stable row-level keys before enabling mixed-unit duplicate products.
- [Price division can create repeating decimals] → Retain higher internal normalized-price precision and keep the existing authoritative line total so document money reconciles exactly.
- [A conversion changes after a draft is created] → Persist authoritative snapshots and validate only when selection is newly made or changed; never reinterpret persisted lines from current configuration.
- [Decimal receipts expose integer assumptions outside the form] → Audit the directly connected validation, schema, model casts, completion checks, stock mutations, serial handling, returns, and focused report paths.
- [Legacy factors violate the new invariant] → Exclude them from new selection, expose actionable validation, and leave correction to a future audited rebase workflow.
- [Removing Purchase rounding changes expected totals] → Restrict the change to Purchase calculation paths, preserve stored documents on load, and add regression tests proving Sales/POS settings and Purchase manual overrides are unaffected.
- [Nullable conversion references can disappear] → Store complete scalar labels/factors/values required for historical display and calculation.

## Migration Plan

1. Add nullable Purchase-detail snapshot columns and indexes/foreign keys compatible with MySQL/MariaDB and SQLite.
2. Convert `received_note_details.quantity_received` to decimal precision while preserving existing integer values.
3. Deploy code that treats absent snapshots as factor-one base-unit rows; no historical backfill is required.
4. Enable product conversion invariant validation and exclude invalid legacy conversions from new Purchase choices.
5. Enable conversion-aware Purchase and receiving flows, followed by focused migration, model/service, Livewire/controller, receiving/serial, rounding, and downstream regression tests.

Rollback removes new application behavior first. Snapshot columns can remain harmlessly nullable during rollback; destructive column removal or conversion of fractional receipt quantities back to integers must not occur unless data is proven compatible.

## Open Questions

None. Product and pricing decisions were resolved during exploration.
