## Context

The ERP stores product purchase cost snapshots in `product_prices` per product and setting. Sales prices are intentionally setting-scoped, but purchase cost should be global per product for the current business use case. Existing flows do not keep those purchase cost snapshots uniformly aligned: purchase approval updates one setting row, imports can create received purchase documents without receiving-note stock mutation, product creation/import paths may seed rows differently, and some products may be missing `product_prices` rows for newer settings.

The command must be safe for production use because it rewrites purchase cost snapshots used by purchase defaults and reports. It should not change historical purchase documents, stock records, returns, or setting-specific sales prices.

## Goals / Non-Goals

**Goals:**

- Add a dry-run-first artisan command to normalize `product_prices.last_purchase_price` and `product_prices.average_purchase_price`.
- Recompute historical purchase cost from received purchase documents for stock-managed products.
- Treat purchase cost as global per product and write the same purchase cost snapshots to every setting's `product_prices` row.
- Include received imports and normal system-created purchases under one eligibility rule.
- Create missing setting rows while preserving or deriving sales price fields in a way that keeps existing Sales behavior stable.
- Keep the command bounded and deterministic enough to run against production data.

**Non-Goals:**

- No schema changes.
- No changes to purchase approval, purchase import, product import, Sales, POS, reports, or live pricing workflows.
- No updates to legacy `products.last_purchase_price` or `products.average_purchase_price`.
- No purchase return cost reversal.
- No normalization of `sale_price`, `tier_1_price`, or `tier_2_price` for rows that already exist.
- No command options beyond dry-run default and explicit write mode.

## Decisions

### Decision 1: Use one received-purchase eligibility rule

The command will include purchase details whose parent purchase is not archived and has status `RECEIVED` or `RECEIVED PARTIALLY`.

For each purchase detail:

- If approved received-note details exist for that purchase detail, eligible quantity is the sum of those approved `quantity_received` values.
- If no approved received-note detail exists, eligible quantity is `purchase_details.quantity`.
- Purchase details with no positive eligible quantity are ignored.

Rationale: Normal purchase receiving records the actual accepted quantity in approved received notes. Purchase imports intentionally create received purchase documents without stock mutation or receiving notes, so falling back to purchase detail quantity includes imports without requiring a fragile import detector.

Alternative considered: Only use approved received-note details. Rejected because imported received purchases would be excluded even though their purchase detail costs should participate in normalization.

### Decision 2: Use `purchase_details.price` as the purchase cost field

The command will use `purchase_details.price` as the tax-included unit purchase cost and may only fall back to `unit_price` if `price` is null.

Rationale: The current purchase approval path updates `last_purchase_price` and `average_purchase_price` from `purchaseDetail->price`, and purchase import writes the final tax-included unit price into both `price` and `unit_price`.

Alternative considered: Use `unit_price` as the primary field. Rejected because it does not match the current approval code path.

### Decision 3: Recompute average as historical weighted average

For each eligible stock-managed product, the command will calculate:

```text
average_purchase_price =
  SUM(unit_purchase_cost * eligible_quantity) / SUM(eligible_quantity)
```

The result will be rounded to two decimals before persistence. Products with no eligible positive quantity are skipped.

Rationale: This produces historical truth from accepted purchase cost events rather than copying a potentially stale current snapshot.

Alternative considered: Copy `products.average_purchase_price` into all product price rows. Rejected because legacy product fields can themselves be stale and should not be the normalization source.

### Decision 4: Derive last purchase price from the latest eligible event

For each eligible product, `last_purchase_price` will come from the latest eligible purchase cost event. The ordering should prefer approved receiving time when present, then purchase date, then stable database identifiers to break ties.

Rationale: Approved receiving time best represents when stock entered the business. Imported purchases without receiving notes still need deterministic ordering through purchase date and IDs.

Alternative considered: Use the maximum purchase price or latest purchase ID only. Rejected because those do not reflect business chronology as closely.

### Decision 5: Ensure product price rows for every setting

For every eligible product and every setting, the command will ensure a `product_prices` row exists.

When creating a missing row:

- Prefer a same-product existing row with a non-zero `sale_price` as the template.
- If there are multiple template candidates, choose deterministically by non-zero sale price availability and stable row order.
- Copy the template's `sale_price`, `purchase_tax_id`, and `sale_tax_id`.
- Copy `tier_1_price` and `tier_2_price` when positive; otherwise default each tier to the template `sale_price`.
- If no template exists, initialize sale and tier prices to `0` and tax IDs to null.

Existing rows must preserve their current sale/tier/tax fields.

Rationale: Sales pricing is setting-scoped and should not be normalized by this command. Missing rows still need sane values because Sales falls back from zero tier prices to sale price, and product edit/import conventions tolerate zero pricing.

Alternative considered: Skip missing setting rows. Rejected because the normalization goal includes repairing missing `product_prices` coverage across settings.

### Decision 6: Dry-run by default, write only with `--write`

Running the command without options will report what would change and must not modify data. Running with `--write` will apply the same computed changes.

Rationale: The operator intends to run this in production, and a dry run gives a safe preflight for row counts, skipped products, and representative before/after values.

Alternative considered: Write by default with a confirmation prompt. Rejected because non-interactive production command execution should be explicit and predictable.

## Risks / Trade-offs

- [Risk] Fallback to purchase detail quantity may include a received purchase whose real received quantity differs from detail quantity because its receiving records are missing or incomplete. -> Mitigation: Only apply fallback when no approved received-note details exist for that purchase detail, matching the intended import shape.
- [Risk] Purchase returns are ignored, so average cost can remain based on received historical cost rather than net on-hand cost. -> Mitigation: This is an explicit business decision for this command.
- [Risk] Existing sale/tier prices could be accidentally changed during missing row creation. -> Mitigation: Preserve existing rows, copy sales fields only for newly created rows, and cover this with tests.
- [Risk] Large production datasets could make a naive implementation slow. -> Mitigation: Use grouped aggregate queries, chunk product updates, and avoid loading full Eloquent graphs.
- [Risk] Ties in latest purchase ordering can produce unstable last purchase prices. -> Mitigation: Add deterministic fallback ordering by purchase date and database IDs.

## Migration Plan

Deploy as a code-only change. Before running in production, execute the command in dry-run mode and inspect counts of eligible products, rows to create, rows to update, and skipped products. Apply changes only with `--write`.

Rollback is data-level, not automatic. If a production write must be reversed, restore `product_prices` from database backup or rerun a verified previous-data repair script. Code rollback alone will not revert normalized prices.

## Open Questions

None.
