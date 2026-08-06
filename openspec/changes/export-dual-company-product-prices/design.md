## Context

`product:export-tiga-nusa-prices` currently resolves only CV TIGA NUSA COMPUTER, queries every product with that setting's `product_prices` row, and writes one worksheet. `product_prices` already contains company-scoped selling, tier, last-purchase, and average-purchase prices; `products.purchase_price` supplies the legacy product-level fallback. The command uses PhpSpreadsheet directly and existing tests inspect the generated workbook.

## Goals / Non-Goals

**Goals:**

- Produce one XLSX workbook with CV TIGA NUSA COMPUTER as sheet 1 and CV TOP IT INTERNUSA as sheet 2.
- Keep every per-company value sourced only from that sheet's `product_prices` row.
- Include numeric last and average purchase-cost columns with the agreed fallback chain.
- Preserve the command name, destination options, confirmation behavior, ordering, and simple Excel layout.

**Non-Goals:**

- Changing stored purchase prices, recalculating average costs, or creating missing `product_prices` rows.
- Adding settings selection, additional companies, CSV output, or a web export UI.
- Changing the existing workbook path or command-line interface.

## Decisions

### Resolve both settings by exact name before touching the destination

The service will resolve exactly one setting each for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA` before the command creates or overwrites a workbook. This preserves the existing strict tenancy check and prevents a partially valid export.

Alternative considered: silently omit a missing company sheet. Rejected because the requested workbook has two known companies and omission could be mistaken for complete pricing data.

### Reuse one parameterized product-price query for each worksheet

The export service will accept a resolved setting when it builds the product query. Each query will left join `product_prices` constrained by both product and that setting ID, returning all products in alphabetical order. The command will create two worksheets from the same layout routine, explicitly creating Tiga Nusa first and Top IT second.

Alternative considered: one join retrieving both price rows per product. Rejected because it duplicates product rows and makes sheet-level isolation and fallback handling harder to verify.

### Treat null and zero purchase-cost values as unavailable

For each company worksheet, the effective last purchase price is the company row's positive `last_purchase_price`, falling back to positive `products.purchase_price`. The effective average purchase price is the company row's positive `average_purchase_price`, falling back to the effective last purchase price. A resolved value is written as a numeric Excel cell; if the whole chain is unavailable, the cell remains blank.

This makes the requested fallback work with the application's established zero-default price fields. Selling and tier prices have no new fallback behavior.

Alternative considered: use SQL `COALESCE`. Rejected because `COALESCE` does not treat zero as unavailable and would duplicate the average-to-last-to-product rule across queries.

### Preserve the existing worksheet conventions

Both sheets will retain title, subtitle, timestamp, header styling, frozen header row, filter, numeric formatting, and widths. The new columns will be titled `Harga Beli Terakhir` and `Harga Beli Rata-rata`; the filter and merged title ranges will expand to cover them.

## Risks / Trade-offs

- [A product has no company price row] → The left join retains it; selling/tier cells remain blank while the product-level purchase-price fallback can still populate purchase-cost columns.
- [A legitimate cost of zero is intended] → This export treats zero as unavailable by the explicitly agreed rule; correcting that distinction would require a future data-state decision.
- [A required setting is renamed or absent] → Exact-name validation fails before any output is written with an actionable message.
- [Workbook layout regression] → Focused tests will assert sheet order, company-specific values, fallback values, headers, and no cross-company leakage.

## Migration Plan

No migration or data backfill is required. Deploy the code change, then run the existing command as usual; its output becomes the two-sheet workbook. Rollback consists of restoring the prior command/service code; no persisted state is affected.

## Open Questions

None.
