## Context

Imported sales/purchases store a document-level discount on the header (`Sale.discount_amount` / `Purchase.discount_amount`). This was verified against the source CSVs: across every 2024–2025 sales and purchase file, the per-row `Diskon` column is identical on every line of an invoice and reconciles only when subtracted **once** (`sum(Jumlah Per Baris) − Diskon = header Jumlah Kena Pajak`). No invoice in the dataset has a genuinely per-line discount. The per-line `SaleDetails.product_discount_amount` is hardcoded to `0` by the importer — correctly, since there is no per-line discount.

Two report families consume this data:

- **Row-expanding** — `SaleByCustomerReportQueryService::mapRows()` / `mapRowsForExport()` and the `PurchaseBySupplierReportQueryService` twin expand each detail line into a product/DPP row plus, when tax exists, a `Pajak` row. The document discount is never emitted, so a discounted invoice's rows no longer sum to its total.
- **Flat columnar** — `SaleReportQueryService` / `PurchaseReportQueryService` produce one row per detail line with discount as columns. The `Diskon` / `Diskon Per Baris %` columns read `product_discount_amount` (always `0`); the real document discount is shown under the misleading label `Jumlah Pemotongan`, with a derived `Diskon %`.

Worked example — invoice `JL.2025.7943`: gross line total `2,657,657.66` − discount `45,045.05` = net DPP `2,612,612.61`; + PPN `287,387.39` = total `2,900,000.00`.

## Goals / Non-Goals

**Goals:**
- Row-expanding reports emit one document `Diskon` row per invoice so discounted invoices read DPP → Diskon → Pajak and the running total reconciles to the document total.
- Flat reports present the real document discount under a clear `Diskon` label and drop the always-zero per-line discount columns; keep the derived `Diskon %`.
- On-screen reports and their CSV/XLSX exports stay in parity for all four report families.

**Non-Goals:**
- No importer changes and no re-import: the discount already exists on the documents.
- No support for genuinely per-line discounts (none exist in the data); `product_discount_amount` stays unused for these documents.
- No database schema or migration changes.
- The Mekari converter/invoice-generator tooling is out of scope; only the four core reports are changed.

## Decisions

### Discount source = document header amount
Use `Sale.discount_amount` / `Purchase.discount_amount` as the single source of truth. Rationale: it is already populated, already reconciles, and is document-scoped — matching the data's true shape. Alternative (read `product_discount_amount` per line) rejected: that field is always `0` and would require a fragile, unnecessary re-import.

### Row-expanding reports: one Diskon row per invoice (not per detail)
The `Diskon` row is emitted **once per invoice**, after the invoice's product/DPP rows and before/with its `Pajak` row. Because `mapRows()` is invoked per detail line, it cannot by itself know when an invoice ends. The change therefore tracks the current invoice in the iterating caller (the Livewire component / snapshot builder) and emits the synthetic discount row at the invoice boundary, with the discount reducing the running `Total nominal tagihan`.

Alternative (pro-rata split across detail lines) rejected per product decision: it adds rows and a re-distribution step for no benefit, since the discount is genuinely document-level.

Ordering within an invoice: product/DPP row(s) → `Diskon` row → `Pajak` row, so the running total walks gross → less discount → plus tax → document total.

### Flat reports: relabel and prune columns, no new data
- Drop/hide `Diskon` (per-line) and `Diskon Per Baris %` — both backed by the always-zero `product_discount_amount`.
- Present the document discount (currently `Jumlah Pemotongan`, = `discount_amount`) as the document `Diskon`.
- Retain the derived `Diskon %`.
Heading arrays (`headingsFor()`), the export column maps, and parity tests are updated together so screen and export remain identical.

### Zero-discount invoices emit no Diskon row / show 0
When `discount_amount` is `0` (the overwhelming majority), no synthetic `Diskon` row is emitted in row-expanding reports and the flat `Diskon` column shows `0`/`-`, preserving current output for undiscounted documents.

## Risks / Trade-offs

- **Running-total / pagination drift when injecting an invoice-boundary row** → The discount row must be folded into the same running-total accumulator used by the existing `Pajak` row and carried across pagination exactly as the current subtotal is; covered by tests asserting reconciliation to the document total across a page boundary.
- **Screen/export divergence** → Both `mapRows()` and `mapRowsForExport()` (and both report families' exports) are changed together and locked by the existing export parity tests, which are extended to cover discounted invoices.
- **Column relabel breaks downstream consumers of the flat CSV/XLSX headers** → Mitigated by updating heading constants and parity tests in lockstep; documented as a header change in the affected exports.
- **Multi-line discounted invoices (~150 purchases) are the only place row placement is observable** → Explicit test fixtures for a multi-line discounted invoice in both row-expanding reports.
