# Project Conventions

This document records durable conventions for the application. Feature-specific requirements live in `openspec/specs/`.

## Monetary Storage

**Rule:** All monetary values outside the POS module are stored as `decimal(15,2)` columns representing rupiah directly — no unit conversion or scaling applied. The POS module uses dedicated `*_minor_units` columns (integers in Indonesian Rupiah cents, ×100) converted at module boundaries only.

**Applies to:**
- `sales`, `sale_details`, `sale_payments`
- `sale_returns`, `sale_return_payments`
- `purchases`, `purchase_details`, `purchase_payments`
- `purchase_returns`, `purchase_return_payments`
- `expenses`
- `products` (product_cost, product_price)
- `quotations`, `quotation_details`

**Details:**
- Models must not define `×100`/`÷100` mutators on monetary columns.
- Database values are authoritative; direct reads (via `DB::table()`, raw SQL, `withSum()`, etc.) return unscaled rupiah.
- Import services (`SalesImportService`, `PurchaseImportService`, `ExpenseImportService`) write through Eloquent and automatically store decimal rupiah.
- Report services compute aggregates without unit conversion.
- Percentages (tax_percentage, discount_percentage, etc.) are separate and use percentage math (e.g., `($price * ($taxRate / 100))`).

**History:** Established in change `normalize-currency-storage-to-decimal` (2026-07-26) to eliminate 100× scaling ambiguity and fix quotation read bugs.
