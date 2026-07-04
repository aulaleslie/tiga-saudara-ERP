## 1. Command Foundation

- [x] 1.1 Add an initialization-only Laravel console command for import transaction normalization and register it with the application.
- [x] 1.2 Require explicit `--initialize` and `--write` flags before truncating `transactions`; support dry-run output when write flags are absent.
- [x] 1.3 Implement command summary output for truncated state, created `BUY` count, created `SELL` count, skipped count, and warning/error count.

## 2. Movement Source Collection

- [x] 2.1 Query imported purchase details from received imported purchases using `supplier_purchase_number` as the import marker.
- [x] 2.2 Query imported sale details from dispatched imported sales using `imported_sales_reference_number` as the import marker.
- [x] 2.3 Resolve the current initial-phase target location from each document setting's first configured location.
- [x] 2.4 Build normalized movement records with source type, document date, document id, detail id, product id, setting id, location id, signed quantity, and reference text.

## 3. Ledger Rebuild

- [x] 3.1 Sort movement records by document date, source priority with `BUY` before `SELL`, document id, and detail id.
- [x] 3.2 Truncate `transactions` only after command flags pass destructive initialization checks.
- [x] 3.3 Create `BUY` transactions with positive quantities and calculated previous/after quantity fields.
- [x] 3.4 Create `SELL` transactions with negative quantities and calculated previous/after quantity fields.
- [x] 3.5 Ensure normalization never creates, updates, increments, or decrements `product_stocks`.

## 4. Stock Snapshot ADJ Alignment

- [x] 4.1 Update stock snapshot import to read the latest transaction ledger balance for the target product, owner setting, and location.
- [x] 4.2 Create stock snapshot `ADJ` transactions with previous quantity from the latest ledger balance, after quantity from the CSV `Total Quantity`, and quantity as the delta.
- [x] 4.3 Preserve existing stock snapshot behavior that updates `product_stocks`, owner marker routing, row metadata, and row-level transaction references.
- [x] 4.4 Preserve fallback behavior where no prior ledger exists by using previous quantity `0`.

## 5. Verification

- [x] 5.1 Add tests proving sales and purchase import runtime processing does not create transactions and does not mutate `product_stocks`.
- [x] 5.2 Add tests proving the initialization command truncates existing transactions only with explicit write/initialize flags.
- [x] 5.3 Add tests proving the command creates deterministic `BUY`/`SELL` rows with correct previous/after balances.
- [x] 5.4 Add tests proving decimal quantities are preserved in generated transactions.
- [x] 5.5 Add tests proving stock snapshot import creates `ADJ` from latest normalized ledger balance and still hardens `product_stocks`.
- [x] 5.6 Run focused PHP tests for the command, sales import, purchase import, and product stock snapshot import behavior.
