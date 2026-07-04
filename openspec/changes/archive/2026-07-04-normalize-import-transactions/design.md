## Context

This ERP is in an initialization phase where sales and purchase CSV imports are used to load historical business documents, then product stock snapshot import is used to harden current `product_stocks` to the external source quantity. Sales and purchase import services currently create documents, details, payments, and for sales dispatch metadata, but they do not mutate `product_stocks`. Product stock snapshot import updates `product_stocks` and creates an `ADJ` transaction using the live pre-import stock value.

The missing piece is a coherent `transactions` ledger between historical imports and the stock snapshot. Stock mutation reports and product transaction lists depend on `transactions`, including `previous_quantity`, `after_quantity`, `previous_quantity_at_location`, and `after_quantity_at_location`. Creating those rows inside import runtime would slow imports and would calculate history in import order rather than business date order.

## Goals / Non-Goals

**Goals:**
- Provide an explicit initialization-only command that truncates `transactions` and rebuilds imported purchase/sale movement history.
- Generate deterministic `BUY` and `SELL` rows from imported purchase and sale details without mutating `product_stocks`.
- Preserve fast, stock-neutral sales and purchase imports.
- Make product stock snapshot import create `ADJ` rows from the latest normalized ledger balance, so the adjustment reflects the difference between historical movement and source stock.
- Keep the implementation compatible with the current initial-phase assumption that each setting has one stock location.

**Non-Goals:**
- Do not support regular production re-normalization after POS/manual/transfer/return transaction history exists.
- Do not rewrite non-import transaction types during initialization normalization.
- Do not change payment, tax, ownership, or document total reconciliation behavior for sales or purchase imports.
- Do not change stock snapshot owner marker resolution beyond the transaction balance source used for `ADJ`.

## Decisions

### Initialization command truncates `transactions`

The command will require explicit destructive flags, for example `inventory:normalize-import-transactions --initialize --write`, before truncating `transactions`. This matches the initialization-only use case where no valuable `INIT`, POS, transfer, return, manual, or stock snapshot transaction history should exist before normalization.

Alternative considered: delete only generated import transactions. That is safer for production re-runs, but it requires reliable source metadata and is unnecessary for the current initialization workflow.

### Normalize from business documents, not import rows

The command will read created `Purchase`/`PurchaseDetail` and `Sale`/`SaleDetails` records that came from imports. Purchases are identified by `supplier_purchase_number`; sales are identified by `imported_sales_reference_number`. This ensures normalization reflects successfully created documents and their resolved product, setting, quantity, and dates.

Alternative considered: read import row JSON directly. That would duplicate tenant/product resolution logic and risks diverging from the actual documents.

### Use existing transaction types

Purchase details create `BUY` transactions with positive quantity. Sale details create `SELL` transactions with negative quantity. These are existing transaction types recognized by stock mutation report labeling and older transaction migrations.

Alternative considered: use `PURCHASE` or `DISPATCH`. `PURCHASE` is not an established transaction type in this codebase. `DISPATCH` is used by POS/dispatch flows and is less direct for historical sales import normalization.

### Ledger balance is calculated per product, setting, and location

The command maintains a running ledger keyed by product, setting, and the setting's single location. For each movement it writes both global setting quantity fields and location quantity fields from the same running balance during this initial phase.

Alternative considered: query `product_stocks` while normalizing. That would mix current stock state with historical movement and defeat the goal of producing a date-ordered ledger.

### Deterministic ordering

Movements are sorted by document date, source priority, document id, and detail id. The source priority should put `BUY` before `SELL` for the same date so same-day purchasing can supply same-day selling in the displayed ledger.

Alternative considered: strict database id ordering. That is deterministic but can reflect import order rather than business event order.

### Stock snapshot `ADJ` uses latest ledger balance

When product stock snapshot import creates its `ADJ`, it should read the latest transaction `after_quantity_at_location` for the target product, owner setting, and location. That value becomes `previous_quantity`; the snapshot total becomes `after_quantity`; the transaction `quantity` is `after - previous`. The import still updates `product_stocks` to the snapshot total.

Alternative considered: continue using pre-import `product_stocks.quantity`. That causes misleading `ADJ` rows when `product_stocks` was zero or stale before the snapshot.

## Risks / Trade-offs

- [Risk] The command is destructive if run outside initialization. → Mitigation: require both `--initialize` and `--write`, print a destructive warning, and document it as initialization-only.
- [Risk] Same-day order can affect intermediate balances. → Mitigation: use explicit deterministic ordering and test same-date purchase/sale tie-breakers.
- [Risk] Missing setting location blocks normalization for a document. → Mitigation: fail with a clear summary of skipped/error documents; current phase assumes one location per setting.
- [Risk] Fractional quantities can be lost if integer casts are used. → Mitigation: preserve decimal quantity parsing and database decimal casts used by current import services.
- [Risk] Existing tests assert no transactions for imports. → Mitigation: keep that runtime assertion, and add separate command tests proving normalization creates transactions.

## Migration Plan

1. Add the initialization normalization command with dry-run summary by default and destructive execution only with explicit flags.
2. Update stock snapshot import `ADJ` calculation to use latest transaction ledger balance instead of live `product_stocks` as the previous quantity.
3. Add focused tests for runtime import neutrality, normalization rebuild, no `product_stocks` mutation during normalization, and stock snapshot `ADJ` ledger alignment.
4. Initialization runbook:
   - Import purchase and sales documents.
   - Run transaction normalization with initialization/write flags.
   - Import product stock snapshot.
5. Rollback during initialization: re-run the command and stock snapshot import from source data, or restore database backup if needed.

## Open Questions

- Should the command include optional `--from` and `--to` filters later for non-initialization diagnostics? This is out of scope for the first implementation.
- Should generated import transactions receive source metadata columns in a later hardening change? This is unnecessary for initialization truncation but useful if future production-safe re-normalization is needed.
