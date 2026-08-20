## Context

The production dispatch model already represents fulfillment acknowledgement for both goods and completed work. Standard Sales persists pending dispatch details, approval counts approved quantities toward Sale completion, and `is_inventory_managed` routes only stock-managed details through stock, serial, and inventory-transaction effects. POS creates approved dispatch evidence during atomic checkout finalization and likewise avoids inventory mutation for non-stock content.

The current canonical non-stock and standard-document specifications predate that decision and still say non-stock rows are excluded. No broken production data has been observed. Historical and POS dispatch details may legitimately have a null `is_inventory_managed` value, while current reports and return queries derive their behavior from approved dispatch rows rather than that nullable field.

## Goals / Non-Goals

**Goals:**

- Make canonical requirements describe dispatch acknowledgement accurately.
- Protect the current stock versus non-stock effect boundary with focused tests.
- Prove bundle fulfillment identity remains separated by product, tax, and bundle context where necessary.
- Preserve approved non-stock work in Sale completion and Sales Delivery reporting.
- Keep implementation changes conditional on a focused regression test exposing a concrete defect.

**Non-Goals:**

- No database migration, backfill, historical status recalculation, or repair script.
- No new dispatch composite-key column or line-level dispatch schema.
- No change to pricing, tax allocation, HPP, serial policy, POS idempotency architecture, or return execution.
- No enforcement of the deferred rule that non-stock services are not returnable; that belongs to Sequence 10.
- No full-suite test task.

## Decisions

### 1. Treat every dispatch detail as fulfillment evidence and route effects separately

Approved stock-managed and non-stock details both acknowledge fulfilled quantity. `is_inventory_managed` distinguishes whether approval also performs physical stock and serial effects. This matches the operational meaning that a quantity of two laptop services means two service jobs were completed.

Alternative considered: exclude non-stock details and infer completed work from the Sale alone. Rejected because it removes the auditable completion event and conflicts with established production behavior.

### 2. Preserve the existing fulfillment key

Continue aggregating demand and approved quantities by `product_id + normalized tax_id + normalized bundle_id` within a Sale. This separates standalone versus bundled uses and different bundle/tax contexts while allowing repeated equivalent transaction lines to form fungible fulfillment demand.

Alternative considered: add `sale_detail_id` or `line_group_key` to `dispatch_details`. Rejected because no concrete inventory collision was demonstrated, existing delivery reports intentionally use the composite key, and a schema change would create unnecessary production migration and historical-data concerns.

### 3. Do not backfill nullable routing snapshots

Leave existing null `is_inventory_managed` values unchanged. Standard pending-dispatch approval already has conservative legacy inference and fails closed on ambiguity. Approved historical and POS rows are not re-approved, and current completion/report consumers do not require this field.

Alternative considered: infer and backfill every historical row from current product classification or location fields. Rejected because current classification may not represent historical intent and no consumer requires the backfill.

### 4. Use focused characterization and regression tests

Add tests for mixed stock/non-stock bundle acknowledgement, same SKU identity boundaries, repeated bundles, tax separation, partial/location fulfillment, rejected-demand release, and exactly-once stock effects. Run only directly affected tests and related dispatch/report regressions.

Alternative considered: require the full application suite. Rejected for this narrow change; broad verification is disproportionate and explicitly outside the requested workflow.

## Risks / Trade-offs

- [Canonical return behavior still allows approved non-stock rows to become return candidates] → Record this as an explicit Sequence 10 dependency; do not expand this change.
- [Sequential retry tests do not reproduce every database concurrency interleaving] → Retain the existing locking assertions and add true concurrency coverage only where the supported test database can exercise separate connections reliably.
- [Delivery terminology may sound physical for services] → Preserve report inclusion because the business defines it as completed work; avoid renaming the report in this change.
- [A focused test could reveal a concrete identity defect] → Make only the smallest correction proven by that test and do not introduce schema changes without a demonstrated collision.

