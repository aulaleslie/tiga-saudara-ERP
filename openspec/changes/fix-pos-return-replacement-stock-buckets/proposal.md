# Proposal

## Why

POS Return product-replacement dispatch currently decrements aggregate stock without decrementing the replacement owner's tax or non-tax bucket. Subsequent return reception can rebuild aggregate stock from the stale buckets, causing recorded stock to diverge from physical serialized inventory.

## What Changes

- Make same-owner and cross-owner POS Return replacement dispatch decrement the replacement location owner's correct good-stock bucket: `quantity_tax` for PKP owners and `quantity_non_tax` for non-PKP owners.
- Recompute aggregate stock fields after the bucket mutation and keep global product quantity and mutation-ledger balances consistent.
- Preserve ownership-aware replacement behavior independently of POS terminal configuration; continue allowing valid cross-owner replacements and do not use deprecated `products.setting_id` as stock ownership authority.
- Add a dry-run-first, auditable, idempotent repair command/service that repairs only rows conclusively linked to the known POS Return replacement-dispatch defect.
- Repair the two currently confirmed affected stock rows through evidence-based discovery and validation: product 182/location 6 and product 4391/location 2.
- Leave unrelated stock/serial discrepancies, legacy stock behavior, completed return documents, dispatches, and serial histories unchanged.
- Add focused regression verification only; do not require or plan a full test-suite run.

## Capabilities

### New Capabilities

- `pos-return-replacement-stock-repair`: Dry-run, evidence classification, guarded correction, audit recording, concurrency protection, and idempotency for historical stock-bucket drift caused by POS Return replacement dispatch.

### Modified Capabilities

- `pos-return-approval-execution`: Require replacement receipt and dispatch to mutate the original and replacement owners' PKP/non-PKP stock buckets consistently for same-owner and cross-owner execution.

## Impact

- Affects `Modules/Pos/Services/PosReturnLifecycleService.php`, POS Return replacement stock mutation behavior, transaction-ledger records, and focused POS Return feature tests.
- Adds a dedicated Artisan command and repair service using existing POS Return, Sale Return, dispatch, transaction, setting/location, product-stock, and serial lineage. No schema change: the corrective `transactions` row carries the repair identity.
- No database-wide reconciliation, POS configuration redesign, product ownership model change, `products.setting_id` cleanup, cross-owner eligibility change, or generic serialized-stock repair is included.
- User-visible impact is limited to corrected stock totals and serial reconciliation in inventory views; normal POS Return workflows and historical documents remain unchanged.
