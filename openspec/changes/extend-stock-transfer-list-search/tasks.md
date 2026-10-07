# Tasks

## 1. Search data paths

- [x] 1.1 Map the persisted product and serial associations for legacy and version 3 transfers, including draft, revision, allocation, and movement stages; verify each planned search source against its schema or fixture.

## 2. List search

- [x] 2.1 Extend the Stock Transfers DataTable global search with grouped, bound substring predicates for document number and current linked product name or primary barcode; verify focused list responses match partial and full terms while conversion-only barcodes do not match.
- [x] 2.2 Add transfer-associated serial predicates covering persisted legacy and version 3 stages without multiplying outer transfer rows; verify focused cases for partial/full serials, historical stages, and unrelated serials.

## 3. Focused verification

- [x] 3.1 Add focused DataTable feature coverage for current product rename/barcode changes, legacy and version 3 serial stages, duplicate matches, visibility scope, sorting/pagination, and existing row actions; run only the relevant filtered test class or classes.
- [x] 3.2 Run `openspec validate extend-stock-transfer-list-search --strict` and the focused transfer-list tests; confirm both pass without running the full suite.
