## 1. Product catalog maintenance

- [ ] 1.1 Align standard Product Create and Update request validation so purchase, sale, and tier prices are optional numeric values with a minimum of zero.
- [ ] 1.2 Normalize disabled-stock edit submissions so conversion payloads cannot trigger base-unit or conversion validation when `stock_managed` is false.
- [ ] 1.3 Enforce the no-positive-stock-across-settings guard server-side for disabling stock management and keep the edit UI aligned with that guard.
- [ ] 1.4 Update the product edit transaction to remove conversions, setting-scoped conversion prices, and conversion barcode identities atomically when stock management is disabled.
- [ ] 1.5 Add focused Product Create/Edit tests for omitted and zero prices, negative-price rejection, successful zero-stock disabling, positive-stock protection, stale conversion payloads, and conversion cleanup.

## 2. POS service product selling

- [ ] 2.1 Update POS keyword search results to include active-setting priced non-stock-managed products and return `stock_managed` state while retaining inventory-location rules for stock-managed products.
- [ ] 2.2 Update POS search result rendering and exact-match auto-selection so services with zero inventory are selectable while zero-stock inventory products remain disabled.
- [ ] 2.3 Update POS barcode/scan resolution to accept non-stock-managed product barcodes without stock availability checks, while retaining serial scan rules.
- [ ] 2.4 Update POS cart product resolution and quantity mutation guards to skip inventory caps only for non-stock-managed lines and retain stock-managed protections.
- [ ] 2.5 Add focused POS search, scan, cart, and checkout regression tests for sellable services and for unchanged out-of-stock inventory behavior.

## 3. Due-date visibility

- [ ] 3.1 Extend Global Sales Payment history query/data-table output with the related Sale due date and render `Tanggal Jatuh Tempo` safely.
- [ ] 3.2 Render the existing Sale due date in the read-only POS Sale Detail modal invoice information with a null-safe placeholder.
- [ ] 3.3 Add focused feature/view tests confirming due-date visibility in Global Sales Payment history and the POS Sale Detail modal.

## 4. Verification

- [ ] 4.1 Run the focused Product, POS, and Sales test suites affected by this change.
- [ ] 4.2 Run the project’s fresh SQLite test command or an equivalent broader Laravel test pass and resolve regressions.
