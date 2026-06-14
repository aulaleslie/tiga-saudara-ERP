## 1. Regression Coverage

- [x] 1.1 Add a focused sales import test for invoice `JL.2026.4530` shape: `Lunas`, `source_total`/`pembayaran` rounding to `550000.00`, `sisa_tagihan_hari_ini` as `1.0e-06`, high-precision `pajak`, and fixed `Diskon`, proving the row imports as `PAID` with `due_amount` `0.00`.
- [x] 1.2 Add a focused sales import test proving an exact one-cent source-total delta is allocated when `Status Hari Ini` makes CSV `Total` authoritative.
- [x] 1.3 Add or update owner-split sales import coverage proving accepted source-total precision adjustment is applied before settlement allocation and each generated owner sale balances independently.
- [x] 1.4 Add a negative sales import test proving material source-total mismatches outside existing precision limits still mark the invoice invalid.

## 2. Canonical Total Reconciliation

- [x] 2.1 Update sales source-invoice reconciliation to allocate accepted source-total adjustments when the rounded delta is exactly `0.01` as well as larger allowed precision drift.
- [x] 2.2 Pass each owner group's canonical adjusted total from `processSourceInvoice()` into `processInvoiceGroup()` or otherwise make `processInvoiceGroup()` persist and validate against the already reconciled canonical total.
- [x] 2.3 Ensure `Sale::total_amount`, `paid_amount`, `due_amount`, `payment_status`, and active sale payment row amounts are all derived from the canonical adjusted total and allocated settlement components.
- [x] 2.4 Preserve line detail subtotal, tax, product, dispatch, stock, and price-sync behavior while changing only header/payment reconciliation totals.

## 3. Guardrails

- [x] 3.1 Confirm existing repeated-field validation for `Diskon`, `Biaya Pengiriman`, `Pembayaran`, `Sisa Tagihan`, `Sisa Tagihan Hari Ini`, `Jumlah Pemotongan`, and `Total` remains unchanged.
- [x] 3.2 Confirm zero-total owner group behavior remains unchanged and no payment row is created for zero-total groups.
- [x] 3.3 Avoid purchase import behavior changes; if shared helpers are modified, add focused purchase regression coverage for existing reconciliation behavior.

## 4. Verification

- [x] 4.1 Run the focused sales import payment ledger/reconciliation tests.
- [x] 4.2 Run focused owner-split sales import tests covering payment allocation.
- [x] 4.3 Run `php artisan test` with the relevant import filters, or `composer test:fresh-sqlite` if the implementation touches shared import helpers or migrations.
