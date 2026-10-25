# Design

## Context

See proposal.md for the reported behavior. `PosReceiptReprintProjectionService` already maps authoritative Sale details to customer-facing POS lines, reconciles line charges to settlement, and handles returns across shared completed print/reprint entry points. `buildReprintedLines` currently derives `price` from assigned charge divided by remaining quantity. `recomputePackedBreakdown` then allocates that charge to unit groups, and `formatUnitBreakdown` distributes residual cents into two displayed price groups. The receipt view prints both groups directly.

Read-only database verification found transaction 8611 / checkout 8372, POS line 40240 and Sale detail 426646: original quantity 12, recorded unit price 7083.33, packed box price 85000, conversion factor 12. Completed return 142 / line 206 refunded 7100 for one unit. Sale detail quantity is now 11, unit price remains 7083.33, and subtotal is 77900. Return line lineage is nullable and is resolved through its Sale detail by existing code.

## Goals / Non-Goals

**Goals:** Keep recorded selling-price provenance separate from reconciled line charges, and remove artificial cent-price groups at the shared projection layer.

**Non-Goals:** Change refund rounding policy, reprice sales, merge repeated POS lines, average genuinely different prices, rewrite historical data, or redesign packing and price-adjustment notices.

## Decisions

1. Resolve display selling prices from proven mapped Sale details on the correct product and unit basis, preserving existing authorized later edits. For unchanged packed prices, use the persisted packed snapshot for original box/loose prices; when a whole-box purchase leaves only loose units, use the persisted base-unit selling price (7083.33 in the regression), not the snapshot's catalog loose price (8000). Exclude bundle component allocations from customer selling-price inference. Reuse existing lineage and explanatory-price checks. Dividing remaining charges by quantity is rejected because rounded refunds and header allocations do not establish a selling-price edit.

2. Separate display price/group construction from `assignedLineAmounts`. Keep authoritative charge allocation and sum-to-settlement validation unchanged. Remove remainder distribution from displayed unit groups; retain integer-cent arithmetic wherever financial reconciliation needs it. Preserve fractional quantities and recorded currency precision. Do not round unit prices to whole Rupiah; row totals retain existing formatting rather than acquiring a new rounding policy.

3. Preserve meaningful packing decomposition and genuine differing-price behavior. Render one entry per existing selling-unit group when its recorded price is uniform. Do not turn an entire box-plus-loose line into a single invented price. If separate adjusted packed-unit prices cannot be proven, retain the existing generic adjustment notice and use proven persisted base-unit pricing with packing quantities, without inventing adjusted box/loose prices. Preserve existing refusal behavior where underlying monetary mapping is ambiguous; no catalog lookup or averaging fallback.

4. Make the shared service produce consistent price and unit-breakdown values so all completed endpoints benefit. Change the Blade template only if required for consuming that payload. Keep historical and preview builders unchanged. Genuine distinct linked Sale prices retain existing explanatory behavior and are not forced into an average by this fix.

## Risks / Trade-offs

- Display price times quantity can differ from the row charge → Treat the authoritative charge as final; verify the concrete 8611 case and prohibit any financial mutation.
- Packed prices and component allocation prices have different meanings → Test the original box-to-loose return, mixed packing, and existing bundle/serial behavior using proven unit bases.
- Existing tests may assert cent-split presentation → Update only affected expectations and retain charge/reconciliation assertions.
- Local Laravel bootstrap currently fails on missing `Illuminate\Support\Traits\Conditionable` in installed vendor dependencies → Use a working project test environment if available; otherwise report the focused verification blocker without modifying dependencies or claiming tests passed.

## Verification

Use focused POS receipt regressions in the existing receipt test classes. Cover the verified 12-to-11 packed return, uniform-price owner splits with a non-divisible charge, a legitimate Sale price edit, packing quantities, fractional quantities where affected, and full-return/historical preservation. Reuse existing fixtures rather than relying on live transaction IDs. Assert payload and rendered output, exact totals, unchanged financial records, and shared endpoint parity through existing endpoint tests. Run only relevant test methods/classes; no full suite or fresh-database full-suite run is planned.

## Migration Plan

No database migration or backfill. Deploy the projection change after focused checks. Roll back the code change to restore prior receipt presentation; stored financial facts remain unchanged.
