## Context

The sales HPP snapshot importer correctly applies source-ledger HPP to matched historical `sale_details`. It intentionally does not mutate `product_prices`, so a historical import does not provide an opening current average cost for subsequent live POS and standard sales. Those live flows already use `SalesCostSnapshotService`, which reads `product_prices.average_purchase_price` for the sale setting.

The product-price model uses three cost buckets: `CV TIGA NUSA COMPUTER`, `CV TOP IT INTERNUSA`, and REST/global for every other setting. Existing purchase-price normalization applies a special bucket when present and uses REST/global as the special-setting fallback.

## Goals / Non-Goals

**Goals:**

- Provide an operator-controlled post-import command that previews and, with explicit write mode, seeds current average purchase price from imported sales HPP snapshots.
- Select a deterministic latest authoritative snapshot per product and bucket using the source sale date, then stable database identifiers.
- Preserve the existing bucket targeting/fallback behavior so future POS and standard sales resolve the intended setting-specific HPP.
- Make every prospective change visible in dry-run output and cover the behavior with focused feature tests.

**Non-Goals:**

- Change the sales HPP importer, re-import historical sales, or alter historical sale-detail snapshots.
- Recalculate weighted purchase costs, change approved purchase receiving, or update `last_purchase_price`.
- Modify inventory quantities, transactions, sales prices, tier prices, or tax metadata.
- Automatically run the command after an import batch.

## Decisions

### Use an explicit Artisan reconciliation command with dry-run default

The command will be named `product:seed-average-cost-from-sales-hpp`; without `--write` it reports the changes it would make, and with `--write` it applies them.

Rationale: source HPP imports are queued and can have row-level failures. A separate command lets the operator review the complete imported result before mutable catalog prices are seeded.

Alternatives considered:

- Update product prices while processing each imported CSV row: rejected because partial/retried imports could leave an intermediate cost and queue row order is not the source-of-truth ordering.
- Run automatically at HPP-batch completion: rejected because financial catalog-price writes need explicit operator review and the importer may not represent the full historical period.

### Treat only imported authoritative snapshots as candidates

Candidates are stock-managed products whose sale details have `cost_snapshot_source = HPP_SNAPSHOT_IMPORT`, a positive `cost_unit_snapshot`, and a parent sale setting/date. The command will derive the bucket from the parent sale setting. It will select the newest candidate by `sales.date`, then sale ID, then sale-detail ID.

Rationale: imported values are the ledger-authoritative HPP snapshots. Live snapshots and backfill outputs must not silently replace the intended imported baseline.

Alternatives considered:

- Select the latest row by import/queue order: rejected because upload order and retry timing are not transaction chronology.
- Include every non-null sale cost snapshot: rejected because it mixes distinct sources and could seed a cost not authorized by the historical HPP ledger.

### Write average cost by existing product-price bucket targets

The command will calculate one latest candidate for each product in Tiga Nusa, Top IT, and REST/global. It will write a setting's own bucket result when available; a special setting with no special-bucket candidate will receive the REST/global candidate when available. Every non-special setting receives the REST/global candidate. Missing target `product_prices` rows will be created while preserving existing same-product sales/tax metadata when a template exists.

Rationale: this follows the system's established price-bucket resolution and lets future live sales at every setting resolve a seeded average.

Alternatives considered:

- Broadcast one product-wide latest HPP to every setting: rejected because it would leak special-owner cost into other owner buckets.
- Update only the exact setting of the latest sale: rejected because non-special settings share REST/global cost and missing rows would still cause future live-sale zero-cost snapshots.

### Change only `average_purchase_price`

Writes update or create only the required product-price row and set `average_purchase_price`. Existing `last_purchase_price`, selling/tier prices, and tax fields remain unchanged; creation copies those metadata fields from a suitable existing same-product row or uses existing safe defaults.

Rationale: the source HPP is a sale-time moving-average cost, not a new purchase event or selling-price instruction.

## Risks / Trade-offs

- [Risk] Running the command after newer real purchase receipts could replace their current cost with a historical import baseline. → Mitigation: dry-run default, explicit `--write`, and command output that identifies selected source sale dates.
- [Risk] An incomplete HPP upload seeds an old value. → Mitigation: choose only successful imported snapshots and show skipped products/buckets before writing.
- [Risk] Existing price rows lack a special-bucket HPP source. → Mitigation: use the established REST/global fallback; skip only when neither target nor fallback exists.
- [Risk] Price-row creation could discard catalog metadata. → Mitigation: reuse the normalization command's template/default strategy and test it.

## Migration Plan

1. Deploy the command and tests; no database migration or automatic data write occurs.
2. After confirming historical HPP imports, run the command without `--write` and review product, bucket, source-date, old-average, and new-average output.
3. Run again with `--write` to seed catalog averages.
4. Rollback is code rollback for the command. If a write must be reverted, rerun from a corrected imported snapshot dataset or restore the affected `product_prices.average_purchase_price` values from a reviewed backup/report.

## Open Questions

None. The command intentionally requires explicit operator invocation rather than automatic execution after import completion.
